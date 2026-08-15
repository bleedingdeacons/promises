<?php

declare(strict_types=1);

namespace Promises\Mcp;

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

use Promises\Logger\HasLogger;

/**
 * The MCP server: turns one decoded JSON-RPC request into one response.
 *
 * Implements MCP revision 2026-07-28, which is stateless at the protocol
 * layer — there is no session to establish, every request carries its own
 * protocol version and client capabilities in _meta, and any request may be
 * the first one this process has ever seen. That suits WordPress exactly:
 * PHP has no process to hold a session in between requests, and the older
 * initialize/initialized handshake only worked here by being ignored.
 *
 * Down-level clients are still served. A client speaking 2025-11-25 or
 * earlier opens with initialize and then sends notifications/initialized;
 * both are answered, the first with that client's own protocol version echoed
 * back so it does not abort on a revision it has never heard of. Nothing is
 * remembered afterwards — the handshake is accepted and discarded, which is
 * indistinguishable from honouring it given every subsequent request stands
 * alone anyway.
 *
 * Not implemented, because Promises has nothing to put behind them: resources,
 * prompts, sampling, completions, roots, and the Tasks and Apps extensions.
 * The capabilities object advertises tools only, so a client knows this
 * without probing.
 */
class Server
{
    use HasLogger;

    /**
     * Revisions this server will echo back to a client that opens with
     * initialize. Anything outside the list is answered with our own version
     * instead, which is the spec's instruction for an unsupported request.
     */
    private const SUPPORTED_PROTOCOL_VERSIONS = [
        '2026-07-28',
        '2025-11-25',
        '2025-06-18',
        '2025-03-26',
    ];

    public function __construct(private ToolRegistry $tools)
    {
    }

    /**
     * Dispatch one request.
     *
     * @param mixed $payload The decoded request body.
     * @return array<string, mixed>|null The response, or null for a
     *                                   notification, which gets no reply.
     */
    public function handle(mixed $payload): ?array
    {
        if (!JsonRpc::isRequest($payload)) {
            return JsonRpc::error(
                is_array($payload) ? JsonRpc::id($payload) : null,
                JsonRpc::INVALID_REQUEST,
                'Not a JSON-RPC 2.0 request: expected an object with "jsonrpc": "2.0" and a string "method".'
            );
        }

        /** @var array<string, mixed> $payload */
        $method = (string) $payload['method'];
        $id = JsonRpc::id($payload);
        $params = JsonRpc::params($payload);
        $isNotification = JsonRpc::isNotification($payload);

        // Notifications are fire-and-forget. Every notifications/* method this
        // server might receive — initialized, cancelled, progress — is
        // something it has nothing to do about, so they are all accepted
        // silently rather than enumerated. Answering one, even with an error,
        // breaks a down-level client that is not listening for a reply.
        if ($isNotification) {
            return null;
        }

        try {
            return match ($method) {
                'initialize' => JsonRpc::result($id, $this->initialize($params)),
                'ping' => JsonRpc::result($id, []),
                'tools/list' => JsonRpc::result($id, ['tools' => $this->tools->describe()]),
                'tools/call' => JsonRpc::result($id, $this->callTool($params)),
                default => JsonRpc::error(
                    $id,
                    JsonRpc::METHOD_NOT_FOUND,
                    sprintf('Unknown method "%s". This server implements initialize, ping, tools/list and tools/call.', $method)
                ),
            };
        } catch (InvalidParamsException $e) {
            return JsonRpc::error($id, JsonRpc::INVALID_PARAMS, $e->getMessage());
        } catch (\Throwable $e) {
            // Genuinely unexpected — a tool throwing is handled inside
            // callTool() and never reaches here. Log the detail and return a
            // flat message: the caller is remote and the exception text may
            // name tables, paths or values.
            self::logError('MCP dispatch failed: ' . $e->getMessage(), [
                'method' => $method,
                'exception' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return JsonRpc::error($id, JsonRpc::INTERNAL_ERROR, 'Internal server error.');
        }
    }

    /**
     * The initialize result, for down-level clients that still open with one.
     *
     * @param array<string, mixed> $params
     * @return array<string, mixed>
     */
    private function initialize(array $params): array
    {
        $requested = $params['protocolVersion'] ?? null;

        $version = is_string($requested) && in_array($requested, self::SUPPORTED_PROTOCOL_VERSIONS, true)
            ? $requested
            : PROMISES_MCP_PROTOCOL_VERSION;

        return [
            'protocolVersion' => $version,
            'capabilities' => [
                // listChanged is false and must stay false: the tool set is
                // fixed at boot from the container and the settings row, and
                // there is no connection to push a change down even if it
                // could vary mid-request.
                'tools' => ['listChanged' => false],
            ],
            'serverInfo' => [
                'name' => 'promises',
                'title' => 'Promises — Unity MCP server',
                'version' => PROMISES_VERSION,
            ],
            'instructions' => $this->instructions(),
        ];
    }

    /**
     * Guidance handed to the model alongside the tool list.
     *
     * Worth spending tokens on: without it a model will happily call
     * unity_list_members with no filter and try to reason over the whole
     * intergroup, and will guess at date formats for the rota.
     */
    private function instructions(): string
    {
        return implode("\n", [
            'This server exposes one AA intergroup\'s records through the Unity plugin, and — when the Trusted plugin is active — its telephone-responder rota.',
            '',
            'Members are real people. Personal email addresses and mobile numbers are masked unless an administrator has turned masking off; do not present a masked value as though it were a real contact detail, and do not try to reconstruct one.',
            '',
            'Prefer the narrowest tool: unity_get_member over listing every member, trusted_get_day over a whole week. List tools are paginated and default to a small page — raise the limit deliberately rather than to be safe.',
            '',
            'All dates are ISO (YYYY-MM-DD) and all times are 24-hour (HH:MM), in the site\'s own timezone. A rota week always starts on a Monday; trusted_get_week snaps to the Monday on or before the date you give it.',
        ]);
    }

    /**
     * tools/call.
     *
     * @param array<string, mixed> $params
     * @return array<string, mixed>
     * @throws InvalidParamsException When name is missing or unknown.
     */
    private function callTool(array $params): array
    {
        $name = $params['name'] ?? null;

        if (!is_string($name) || $name === '') {
            throw new InvalidParamsException('tools/call requires a "name" string naming the tool to run.');
        }

        $tool = $this->tools->get($name);

        if ($tool === null) {
            // Invalid params rather than method-not-found: the *method*
            // (tools/call) exists and was reached; it is the argument naming a
            // tool that is wrong. Listing what is available saves the model a
            // tools/list round trip to recover.
            throw new InvalidParamsException(sprintf(
                'Unknown tool "%s". Available tools: %s.',
                $name,
                implode(', ', array_keys($this->tools->all()))
            ));
        }

        $arguments = $params['arguments'] ?? [];

        if (!is_array($arguments)) {
            throw new InvalidParamsException('"arguments" must be an object.');
        }

        try {
            /** @var array<string, mixed> $arguments */
            $structured = $tool->call($arguments);
        } catch (ToolException $e) {
            return $this->toolError($e->getMessage());
        } catch (\Throwable $e) {
            // A tool blew up in a way it did not anticipate. The model still
            // gets a usable failure rather than a dead turn, but the detail
            // goes to the log rather than over the wire.
            self::logError('Tool "' . $name . '" threw: ' . $e->getMessage(), [
                'tool' => $name,
                'exception' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return $this->toolError(sprintf('The "%s" tool failed unexpectedly. The error has been logged.', $name));
        }

        $json = wp_json_encode($structured, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        return [
            // Both forms, deliberately. structuredContent is what a client
            // should read; the text block is the fallback for clients that
            // predate it, and dropping it would make this server unusable to
            // them for the sake of a few hundred bytes.
            'content' => [
                [
                    'type' => 'text',
                    'text' => $json === false ? '{}' : $json,
                ],
            ],
            'structuredContent' => $structured === [] ? new \stdClass() : $structured,
            'isError' => false,
        ];
    }

    /**
     * A failed tool call: a successful JSON-RPC response carrying isError.
     *
     * @return array<string, mixed>
     */
    private function toolError(string $message): array
    {
        return [
            'content' => [
                [
                    'type' => 'text',
                    'text' => $message,
                ],
            ],
            'isError' => true,
        ];
    }
}
