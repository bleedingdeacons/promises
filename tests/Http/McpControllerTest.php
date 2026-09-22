<?php

declare(strict_types=1);

namespace Promises\Tests\Http;

use BleedingDeacons\WpMocks\WpState;
use Promises\Auth\ApiKeyManager;
use Promises\Http\McpController;
use Promises\Mcp\JsonRpc;
use Promises\Mcp\Server;
use Promises\Mcp\ToolRegistry;
use Promises\Settings\Settings;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;

/*
 * The transport: routing, authentication and the HTTP-level decisions.
 *
 * One generated key is shared across the file — Argon2id costs about a tenth
 * of a second per call, and every test here needs a valid credential.
 */

function mcpController(): McpController
{
    $registry = new ToolRegistry();

    return new McpController(
        new Server($registry),
        new ApiKeyManager(new Settings()),
        $registry
    );
}

/**
 * A request carrying a valid key, and the settings row that makes it one.
 */
function authorisedMcpRequest(string $body = '', string $header = 'authorization'): WP_REST_Request
{
    static $key = '';

    $manager = new ApiKeyManager(new Settings());

    if ($key === '') {
        $key = $manager->generate();
    } else {
        // Re-seed the option row WpState::reset() just cleared, without
        // paying for another hash.
        (new Settings())->save([
            'api_key_hash' => $manager->hash($key),
            'api_key_prefix' => substr($key, 0, 12),
            'api_key_created_at' => '2026-08-15 00:00:00',
        ]);
    }

    $value = $header === 'authorization' ? 'Bearer ' . $key : $key;

    return new WP_REST_Request([], '/promises/v1/mcp', [$header => $value], $body);
}

it('registers the mcp and health routes', function () {
    mcpController()->registerRoutes();

    $routes = array_column(WpState::$restRoutes, 'route');

    expect($routes)->toContain('/mcp', '/health')
        ->and(WpState::$restRoutes[0]['namespace'])->toBe('promises/v1');
});

describe('authentication', function () {
    it('authenticates a bearer token', function () {
        expect(mcpController()->authenticate(authorisedMcpRequest()))->toBeTrue();
    });

    // Some hosts strip Authorization before PHP sees it, which is the whole
    // reason the second header exists.
    it('also authenticates the X-API-Key header', function () {
        expect(mcpController()->authenticate(authorisedMcpRequest('', 'x-api-key')))->toBeTrue();
    });

    it('rejects a request with no credential', function () {
        $request = new WP_REST_Request([], '/promises/v1/mcp');

        $error = mcpController()->authenticate($request);

        expect($error)->toBeInstanceOf(WP_Error::class)
            ->and($error->get_error_code())->toBe('promises_unauthorized')
            ->and($error->get_error_data())->toBe(['status' => 401]);
    });

    it('rejects a wrong key', function () {
        authorisedMcpRequest();

        $request = new WP_REST_Request([], '/promises/v1/mcp', ['authorization' => 'Bearer prm_wrong']);

        expect(mcpController()->authenticate($request))->toBeInstanceOf(WP_Error::class);
    });

    // Absent, malformed and wrong keys must be indistinguishable to the
    // caller — which of the three it was goes to the log, not the response.
    it('makes every rejection read the same to the caller', function () {
        authorisedMcpRequest();

        $controller = mcpController();

        $absent = $controller->authenticate(new WP_REST_Request([], '/mcp'));
        $malformed = $controller->authenticate(new WP_REST_Request([], '/mcp', ['authorization' => 'Basic nope']));
        $wrong = $controller->authenticate(new WP_REST_Request([], '/mcp', ['authorization' => 'Bearer prm_wrong']));

        expect($absent)->toBeInstanceOf(WP_Error::class)
            ->and($malformed)->toBeInstanceOf(WP_Error::class)
            ->and($wrong)->toBeInstanceOf(WP_Error::class)
            ->and($malformed->get_error_message())->toBe($absent->get_error_message())
            ->and($wrong->get_error_message())->toBe($absent->get_error_message());
    });
});

describe('handle', function () {
    it('dispatches a request and answers with HTTP 200', function () {
        $body = json_encode(['jsonrpc' => '2.0', 'id' => 7, 'method' => 'ping']);

        $response = mcpController()->handle(authorisedMcpRequest($body));

        expect($response->get_status())->toBe(200)
            ->and($response->get_data()['id'])->toBe(7);
    });

    // A JSON-RPC error still leaves with HTTP 200: the transport delivered the
    // message, and the failure is described inside it.
    it('still returns HTTP 200 for a protocol error', function () {
        $body = json_encode(['jsonrpc' => '2.0', 'id' => 1, 'method' => 'nope']);

        $response = mcpController()->handle(authorisedMcpRequest($body));

        expect($response->get_status())->toBe(200)
            ->and($response->get_data()['error']['code'])->toBe(JsonRpc::METHOD_NOT_FOUND);
    });

    it('turns a malformed body into a parse error', function () {
        $response = mcpController()->handle(authorisedMcpRequest('{not json'));

        expect($response->get_data()['error']['code'])->toBe(JsonRpc::PARSE_ERROR);
    });

    // A notification gets 202 and no body — there is nothing to return and the
    // client is not waiting.
    it('acknowledges a notification with 202 and no body', function () {
        $body = json_encode(['jsonrpc' => '2.0', 'method' => 'notifications/initialized']);

        $response = mcpController()->handle(authorisedMcpRequest($body));

        expect($response->get_status())->toBe(202)
            ->and($response->get_data())->toBeNull();
    });

    // MCP removed JSON-RPC batching in 2025-06-18 and has not restored it, so
    // an array body is refused explicitly rather than guessed at.
    it('refuses a batched request explicitly', function () {
        $body = json_encode([
            ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'ping'],
            ['jsonrpc' => '2.0', 'id' => 2, 'method' => 'ping'],
        ]);

        $response = mcpController()->handle(authorisedMcpRequest($body));

        expect($response->get_data()['error']['code'])->toBe(JsonRpc::INVALID_REQUEST)
            ->and($response->get_data()['error']['message'])->toContain('Batched');
    });
});

describe('GET and the Allow header', function () {
    it('answers GET on the endpoint with 405', function () {
        $response = mcpController()->streamNotSupported();

        expect($response->get_status())->toBe(405);
    });

    // The Allow header is corrected on rest_post_dispatch, not in the handler.
    //
    // This is the assertion that was missing when the live endpoint answered
    // `Allow: POST, GET`: the old test called streamNotSupported() directly and
    // saw the header it had just set, never reaching the point where
    // WordPress's own rest_send_allow_header() rebuilds it from the route's
    // registered methods and hands GET back to the client.
    it('corrects the Allow header on mcp to POST only', function () {
        $response = new WP_REST_Response(null, 405);
        // What WordPress will have written by the time the filter runs.
        $response->header('Allow', 'POST, GET');

        $corrected = mcpController()->correctAllowHeader(
            $response,
            null,
            new WP_REST_Request([], '/promises/v1/mcp')
        );

        expect($corrected->get_headers()['Allow'])->toBe('POST');
    });

    // /health is a genuine GET, so its header must be left alone.
    it('leaves the Allow header on other routes alone', function () {
        $response = new WP_REST_Response(null, 200);
        $response->header('Allow', 'GET');

        $corrected = mcpController()->correctAllowHeader(
            $response,
            null,
            new WP_REST_Request([], '/promises/v1/health')
        );

        expect($corrected->get_headers()['Allow'])->toBe('GET');
    });

    // rest_post_dispatch can carry a WP_Error rather than a response — a
    // rejected request, for instance — and a filter that assumed otherwise
    // would fatal on the auth-failure path.
    it('passes through anything that is not a response', function () {
        $error = new WP_Error('nope', 'nope');

        expect(mcpController()->correctAllowHeader($error, null, new WP_REST_Request([], '/promises/v1/mcp')))
            ->toBe($error);
    });
});

it('reports the protocol version and tool names on health', function () {
    $data = mcpController()->health()->get_data();

    expect($data['status'])->toBe('ok')
        ->and($data['protocolVersion'])->toBe(PROMISES_MCP_PROTOCOL_VERSION)
        ->and($data['tools'])->toBe([]);
});
