<?php

declare(strict_types=1);

namespace Promises\Mcp;

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

/**
 * JSON-RPC 2.0 envelopes.
 *
 * MCP is JSON-RPC over a transport; this class knows only the envelope, and
 * nothing about tools or MCP methods. Kept separate so Server can be read as
 * protocol logic without the shape of every reply obscuring it.
 */
class JsonRpc
{
    // The subset of the standard error codes this server can actually emit.
    public const PARSE_ERROR = -32700;
    public const INVALID_REQUEST = -32600;
    public const METHOD_NOT_FOUND = -32601;
    public const INVALID_PARAMS = -32602;
    public const INTERNAL_ERROR = -32603;

    /**
     * @param string|int|null $id
     * @param array<string, mixed> $result
     * @return array<string, mixed>
     */
    public static function result(string|int|null $id, array $result): array
    {
        return [
            'jsonrpc' => '2.0',
            'id' => $id,
            // An empty result must still serialise as {} and not [], which is
            // what an empty PHP array would become. ping's reply is exactly
            // this case.
            'result' => $result === [] ? new \stdClass() : $result,
        ];
    }

    /**
     * @param string|int|null $id
     * @param array<string, mixed>|null $data
     * @return array<string, mixed>
     */
    public static function error(string|int|null $id, int $code, string $message, ?array $data = null): array
    {
        $error = [
            'code' => $code,
            'message' => $message,
        ];

        if ($data !== null) {
            $error['data'] = $data;
        }

        return [
            'jsonrpc' => '2.0',
            'id' => $id,
            'error' => $error,
        ];
    }

    /**
     * Whether a decoded payload is a structurally valid JSON-RPC 2.0 request.
     *
     * Checks the envelope only — that jsonrpc says "2.0" and there is a string
     * method. Whether that method exists, and whether its params make sense,
     * are Server's business.
     *
     * @param mixed $payload
     */
    public static function isRequest(mixed $payload): bool
    {
        return is_array($payload)
            && ($payload['jsonrpc'] ?? null) === '2.0'
            && isset($payload['method'])
            && is_string($payload['method']);
    }

    /**
     * The request id, normalised.
     *
     * JSON-RPC allows a string, a number, or null. A request with no id at all
     * is a notification, which is also represented here as null — Server
     * distinguishes the two with isNotification() before deciding whether to
     * reply.
     *
     * @param array<string, mixed> $payload
     */
    public static function id(array $payload): string|int|null
    {
        $id = $payload['id'] ?? null;

        if (is_string($id) || is_int($id)) {
            return $id;
        }

        return null;
    }

    /**
     * A notification is a request with no id, and gets no response at all.
     *
     * MCP relies on this for notifications/initialized: a down-level client
     * sends it after the handshake and would hang if it were answered, or
     * error if it were answered with a result it has no id to match.
     *
     * @param array<string, mixed> $payload
     */
    public static function isNotification(array $payload): bool
    {
        return !array_key_exists('id', $payload);
    }

    /**
     * The params object, or an empty array when absent or malformed.
     *
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    public static function params(array $payload): array
    {
        $params = $payload['params'] ?? [];

        return is_array($params) ? $params : [];
    }
}
