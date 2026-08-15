<?php

declare(strict_types=1);

namespace Promises\Tests\Http;

use BleedingDeacons\WpMocks\TestCase;
use BleedingDeacons\WpMocks\WpState;
use Promises\Auth\ApiKeyManager;
use Promises\Http\McpController;
use Promises\Mcp\JsonRpc;
use Promises\Mcp\Server;
use Promises\Mcp\ToolRegistry;
use Promises\Settings\Settings;
use WP_Error;
use WP_REST_Request;

/**
 * The transport: routing, authentication and the HTTP-level decisions.
 *
 * One generated key is shared across the class — Argon2id costs about a tenth
 * of a second per call, and every test here needs a valid credential.
 */
final class McpControllerTest extends TestCase
{
    private static string $key = '';

    private function controller(): McpController
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
    private function authorisedRequest(string $body = '', string $header = 'authorization'): WP_REST_Request
    {
        $manager = new ApiKeyManager(new Settings());

        if (self::$key === '') {
            self::$key = $manager->generate();
        } else {
            // Re-seed the option row WpState::reset() just cleared, without
            // paying for another hash.
            (new Settings())->save([
                'api_key_hash' => $manager->hash(self::$key),
                'api_key_prefix' => substr(self::$key, 0, 12),
                'api_key_created_at' => '2026-08-15 00:00:00',
            ]);
        }

        $value = $header === 'authorization' ? 'Bearer ' . self::$key : self::$key;

        return new WP_REST_Request([], '/promises/v1/mcp', [$header => $value], $body);
    }

    public function test_it_registers_the_mcp_and_health_routes(): void
    {
        $this->controller()->registerRoutes();

        $routes = array_column(WpState::$restRoutes, 'route');

        $this->assertContains('/mcp', $routes);
        $this->assertContains('/health', $routes);
        $this->assertSame('promises/v1', WpState::$restRoutes[0]['namespace']);
    }

    public function test_a_bearer_token_authenticates(): void
    {
        $this->assertTrue($this->controller()->authenticate($this->authorisedRequest()));
    }

    /**
     * Some hosts strip Authorization before PHP sees it, which is the whole
     * reason the second header exists.
     */
    public function test_the_x_api_key_header_also_authenticates(): void
    {
        $this->assertTrue($this->controller()->authenticate($this->authorisedRequest('', 'x-api-key')));
    }

    public function test_a_request_with_no_credential_is_rejected(): void
    {
        $request = new WP_REST_Request([], '/promises/v1/mcp');

        $error = $this->controller()->authenticate($request);

        $this->assertInstanceOf(WP_Error::class, $error);
        $this->assertSame('promises_unauthorized', $error->get_error_code());
        $this->assertSame(['status' => 401], $error->get_error_data());
    }

    public function test_a_wrong_key_is_rejected(): void
    {
        $this->authorisedRequest();

        $request = new WP_REST_Request([], '/promises/v1/mcp', ['authorization' => 'Bearer prm_wrong']);

        $this->assertInstanceOf(WP_Error::class, $this->controller()->authenticate($request));
    }

    /**
     * Absent, malformed and wrong keys must be indistinguishable to the
     * caller — which of the three it was goes to the log, not the response.
     */
    public function test_every_rejection_reads_the_same_to_the_caller(): void
    {
        $this->authorisedRequest();

        $controller = $this->controller();

        $absent = $controller->authenticate(new WP_REST_Request([], '/mcp'));
        $malformed = $controller->authenticate(new WP_REST_Request([], '/mcp', ['authorization' => 'Basic nope']));
        $wrong = $controller->authenticate(new WP_REST_Request([], '/mcp', ['authorization' => 'Bearer prm_wrong']));

        $this->assertInstanceOf(WP_Error::class, $absent);
        $this->assertInstanceOf(WP_Error::class, $malformed);
        $this->assertInstanceOf(WP_Error::class, $wrong);
        $this->assertSame($absent->get_error_message(), $malformed->get_error_message());
        $this->assertSame($absent->get_error_message(), $wrong->get_error_message());
    }

    public function test_it_dispatches_a_request_and_answers_with_http_200(): void
    {
        $body = json_encode(['jsonrpc' => '2.0', 'id' => 7, 'method' => 'ping']);

        $response = $this->controller()->handle($this->authorisedRequest($body));

        $this->assertSame(200, $response->get_status());
        $this->assertSame(7, $response->get_data()['id']);
    }

    /**
     * A JSON-RPC error still leaves with HTTP 200: the transport delivered the
     * message, and the failure is described inside it.
     */
    public function test_a_protocol_error_still_returns_http_200(): void
    {
        $body = json_encode(['jsonrpc' => '2.0', 'id' => 1, 'method' => 'nope']);

        $response = $this->controller()->handle($this->authorisedRequest($body));

        $this->assertSame(200, $response->get_status());
        $this->assertSame(JsonRpc::METHOD_NOT_FOUND, $response->get_data()['error']['code']);
    }

    public function test_a_malformed_body_becomes_a_parse_error(): void
    {
        $response = $this->controller()->handle($this->authorisedRequest('{not json'));

        $this->assertSame(JsonRpc::PARSE_ERROR, $response->get_data()['error']['code']);
    }

    /**
     * A notification gets 202 and no body — there is nothing to return and the
     * client is not waiting.
     */
    public function test_a_notification_is_acknowledged_with_202_and_no_body(): void
    {
        $body = json_encode(['jsonrpc' => '2.0', 'method' => 'notifications/initialized']);

        $response = $this->controller()->handle($this->authorisedRequest($body));

        $this->assertSame(202, $response->get_status());
        $this->assertNull($response->get_data());
    }

    /**
     * MCP removed JSON-RPC batching in 2025-06-18 and has not restored it, so
     * an array body is refused explicitly rather than guessed at.
     */
    public function test_a_batched_request_is_refused_explicitly(): void
    {
        $body = json_encode([
            ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'ping'],
            ['jsonrpc' => '2.0', 'id' => 2, 'method' => 'ping'],
        ]);

        $response = $this->controller()->handle($this->authorisedRequest($body));

        $this->assertSame(JsonRpc::INVALID_REQUEST, $response->get_data()['error']['code']);
        $this->assertStringContainsString('Batched', $response->get_data()['error']['message']);
    }

    public function test_get_on_the_endpoint_is_405_with_an_allow_header(): void
    {
        $response = $this->controller()->streamNotSupported();

        $this->assertSame(405, $response->get_status());
        $this->assertSame('POST', $response->get_headers()['Allow']);
    }

    public function test_health_reports_the_protocol_version_and_tool_names(): void
    {
        $data = $this->controller()->health()->get_data();

        $this->assertSame('ok', $data['status']);
        $this->assertSame(PROMISES_MCP_PROTOCOL_VERSION, $data['protocolVersion']);
        $this->assertSame([], $data['tools']);
    }
}
