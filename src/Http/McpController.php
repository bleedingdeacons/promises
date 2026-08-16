<?php

declare(strict_types=1);

namespace Promises\Http;

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

use Promises\Auth\ApiKeyManager;
use Promises\Logger\HasLogger;
use Promises\Mcp\JsonRpc;
use Promises\Mcp\Server;
use Promises\Mcp\ToolRegistry;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server as WpRest;

/**
 * The transport: one REST route carrying MCP's Streamable HTTP.
 *
 * MCP 2026-07-28 is stateless, so this is a plain request/response endpoint —
 * no session id to issue or validate, no SSE stream to hold open, nothing
 * kept between calls. That is the whole reason an MCP server can live inside
 * WordPress at all: under the previous revisions a server had to remember an
 * initialize handshake across requests, and PHP has nowhere to put that
 * without inventing a session store.
 *
 * GET on the endpoint is not implemented. Under Streamable HTTP a GET opens
 * the server-to-client notification stream, and this server never initiates
 * anything — there are no subscriptions, no progress notifications and no
 * tool-list changes to push. Clients are told so with 405 rather than left
 * holding a connection that will never carry a message.
 */
class McpController
{
    use HasLogger;

    public const NAMESPACE = 'promises/v1';

    public function __construct(
        private Server $server,
        private ApiKeyManager $keys,
        private ToolRegistry $tools
    ) {
    }

    public function registerRoutes(): void
    {
        register_rest_route(self::NAMESPACE, '/mcp', [
            [
                'methods' => WpRest::CREATABLE,
                'callback' => [$this, 'handle'],
                'permission_callback' => [$this, 'authenticate'],
            ],
            [
                // Declared rather than left to WordPress's own 404, so the
                // failure names the reason instead of implying the endpoint is
                // missing and sending someone to check their URL.
                'methods' => WpRest::READABLE,
                'callback' => [$this, 'streamNotSupported'],
                'permission_callback' => [$this, 'authenticate'],
            ],
        ]);

        register_rest_route(self::NAMESPACE, '/health', [
            'methods' => WpRest::READABLE,
            'callback' => [$this, 'health'],
            'permission_callback' => [$this, 'authenticate'],
        ]);

        // Corrects the Allow header on /mcp; see correctAllowHeader(). Runs
        // after WordPress's own rest_send_allow_header(), which sits on this
        // filter at the default priority 10.
        add_filter('rest_post_dispatch', [$this, 'correctAllowHeader'], 20, 3);
    }

    /**
     * Force `Allow: POST` on /mcp.
     *
     * WordPress builds the Allow header itself, in rest_send_allow_header(),
     * from every method registered against the matched route — and it does so
     * on rest_post_dispatch, after the callback has returned. So the
     * `Allow: POST` set while building the 405 response never survives: the
     * live endpoint answered `Allow: POST, GET`, telling a client doing method
     * discovery that GET was available and then refusing it with 405.
     *
     * GET stays registered deliberately. MCP says a server that does not
     * support the notification stream should answer 405, and dropping the
     * route would make WordPress answer 404 instead — which reads as "wrong
     * URL" and sends someone off checking their configuration. So the handler
     * stays and the header is corrected here instead.
     *
     * Only /mcp is touched. /health is a genuine GET and its header is right.
     *
     * @param mixed $response
     * @param mixed $server
     * @return mixed
     */
    public function correctAllowHeader($response, $server, WP_REST_Request $request)
    {
        // WP_REST_Response, not its WP_HTTP_Response parent. Every route here
        // returns the former — including a rejected one, since WordPress turns
        // the WP_Error from the permission callback into a WP_REST_Response
        // before this filter runs — so nothing is lost by the narrower check.
        //
        // It also has to be the narrower one to be testable at all:
        // bleedingdeacons/wp-mocks declares WP_REST_Response standalone, where
        // real WordPress has it extend WP_HTTP_Response. A guard naming the
        // parent passes in production and silently returns early under test,
        // which is exactly how the first attempt at this fix looked broken.
        if (!$response instanceof WP_REST_Response) {
            return $response;
        }

        if ($request->get_route() === '/' . self::NAMESPACE . '/mcp') {
            // Third argument defaults to replacing, which is what is wanted:
            // WordPress has already written its own value by this point.
            $response->header('Allow', 'POST');
        }

        return $response;
    }

    /**
     * Bearer token or X-API-Key, checked against the stored hash.
     *
     * @return true|WP_Error
     */
    public function authenticate(WP_REST_Request $request): bool|WP_Error
    {
        $presented = $this->presentedKey($request);

        if ($this->keys->verify($presented)) {
            return true;
        }

        // One message for every failure — absent, malformed and wrong keys
        // are indistinguishable to the caller. Which of the three it was goes
        // to the log, where it is useful for an admin debugging their own
        // client and useless to anyone else.
        self::logWarning('MCP request rejected', [
            'reason' => $presented === '' ? 'no credential presented' : 'key did not match',
            'route' => $request->get_route(),
            'ip' => isset($_SERVER['REMOTE_ADDR']) ? sanitize_text_field(wp_unslash((string) $_SERVER['REMOTE_ADDR'])) : 'unknown',
        ]);

        return new WP_Error(
            'promises_unauthorized',
            'A valid API key is required. Send it as "Authorization: Bearer <key>" or in the X-API-Key header.',
            ['status' => 401]
        );
    }

    /**
     * Handle one MCP request.
     */
    public function handle(WP_REST_Request $request): WP_REST_Response
    {
        $body = $request->get_body();

        $payload = json_decode($body, true);

        if (json_last_error() !== JSON_ERROR_NONE) {
            return $this->respond(
                JsonRpc::error(null, JsonRpc::PARSE_ERROR, 'Request body is not valid JSON: ' . json_last_error_msg())
            );
        }

        // A JSON array here is a JSON-RPC batch. MCP removed batching in
        // 2025-06-18 and has not brought it back, so rather than invent
        // semantics for it, say plainly that it is not supported — a client
        // that gets this can fall back to sending the requests one at a time.
        if (is_array($payload) && array_is_list($payload)) {
            return $this->respond(
                JsonRpc::error(null, JsonRpc::INVALID_REQUEST, 'Batched requests are not supported. Send one request per call.')
            );
        }

        $response = $this->server->handle($payload);

        // A notification. There is nothing to return and the client is not
        // waiting for anything, so acknowledge receipt and send no body.
        if ($response === null) {
            return new WP_REST_Response(null, 202);
        }

        return $this->respond($response);
    }

    /**
     * GET on /mcp — the server-to-client stream, which this server has no use
     * for.
     */
    public function streamNotSupported(): WP_REST_Response
    {
        $response = new WP_REST_Response([
            'code' => 'promises_stream_not_supported',
            'message' => 'This server does not open a notification stream: it sends nothing a client has not asked for. POST JSON-RPC requests to this same URL instead.',
            'data' => ['status' => 405],
        ], 405);

        // The Allow header is not set here. WordPress overwrites it on
        // rest_post_dispatch regardless of what this returns, so setting it
        // would only look like it worked — see correctAllowHeader().
        return $response;
    }

    /**
     * A liveness check that also answers the two questions an admin actually
     * has when a client will not connect: is the key working, and is the tool
     * set what I expected?
     *
     * Behind the same auth as everything else — the tool names describe the
     * shape of the intergroup's data and are not for anonymous callers.
     */
    public function health(): WP_REST_Response
    {
        return new WP_REST_Response([
            'status' => 'ok',
            'server' => 'promises',
            'version' => PROMISES_VERSION,
            'protocolVersion' => PROMISES_MCP_PROTOCOL_VERSION,
            'tools' => array_keys($this->tools->all()),
        ], 200);
    }

    /**
     * The key presented on this request, or an empty string.
     *
     * Authorization is checked first and X-API-Key second. Some hosts strip
     * Authorization before PHP sees it (CGI setups that do not pass it
     * through), which is exactly why the second header exists.
     */
    private function presentedKey(WP_REST_Request $request): string
    {
        $authorization = $request->get_header('Authorization');

        if (is_string($authorization) && preg_match('/^Bearer\s+(.+)$/i', trim($authorization), $matches) === 1) {
            return trim($matches[1]);
        }

        $apiKey = $request->get_header('X-API-Key');

        return is_string($apiKey) ? trim($apiKey) : '';
    }

    /**
     * A JSON-RPC response always leaves with HTTP 200.
     *
     * Even the error envelopes: the transport delivered the message
     * successfully, and the failure is described inside it. Mapping JSON-RPC
     * error codes onto HTTP status codes would make clients guess which layer
     * a failure came from.
     *
     * @param array<string, mixed> $payload
     */
    private function respond(array $payload): WP_REST_Response
    {
        return new WP_REST_Response($payload, 200);
    }
}
