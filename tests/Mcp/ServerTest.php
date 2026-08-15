<?php

declare(strict_types=1);

namespace Promises\Tests\Mcp;

use BleedingDeacons\WpMocks\TestCase;
use Promises\Mcp\JsonRpc;
use Promises\Mcp\Server;
use Promises\Mcp\Tool;
use Promises\Mcp\ToolException;
use Promises\Mcp\ToolRegistry;

/**
 * MCP dispatch.
 *
 * The protocol layer is where a mistake is least visible from the outside: a
 * client that gets a subtly wrong envelope tends to fail with something
 * unhelpful several steps later, so these assert the shapes directly.
 */
final class ServerTest extends TestCase
{
    private function server(Tool ...$tools): Server
    {
        $registry = new ToolRegistry();

        foreach ($tools as $tool) {
            $registry->add($tool);
        }

        return new Server($registry);
    }

    /**
     * @param array<string, mixed> $extra
     * @return array<string, mixed>
     */
    private function request(string $method, array $extra = []): array
    {
        return array_merge(['jsonrpc' => '2.0', 'id' => 1, 'method' => $method], $extra);
    }

    public function test_it_rejects_a_payload_that_is_not_a_jsonrpc_request(): void
    {
        $response = $this->server()->handle(['method' => 'ping']);

        $this->assertSame(JsonRpc::INVALID_REQUEST, $response['error']['code']);
    }

    public function test_it_rejects_a_non_array_payload(): void
    {
        $response = $this->server()->handle('not json-rpc at all');

        $this->assertSame(JsonRpc::INVALID_REQUEST, $response['error']['code']);
        $this->assertNull($response['id']);
    }

    public function test_it_reports_an_unknown_method(): void
    {
        $response = $this->server()->handle($this->request('resources/list'));

        $this->assertSame(JsonRpc::METHOD_NOT_FOUND, $response['error']['code']);
        // The message names what *is* available, so a client can recover
        // without a second round trip.
        $this->assertStringContainsString('tools/list', $response['error']['message']);
    }

    /**
     * A notification has no id and must produce no response at all. Answering
     * one breaks a down-level client that is not listening for a reply.
     */
    public function test_it_returns_nothing_for_a_notification(): void
    {
        $response = $this->server()->handle([
            'jsonrpc' => '2.0',
            'method' => 'notifications/initialized',
        ]);

        $this->assertNull($response);
    }

    public function test_ping_returns_an_empty_object(): void
    {
        $response = $this->server()->handle($this->request('ping'));

        // Must encode as {} and not [] — an empty PHP array would become the
        // latter, which is not a valid JSON-RPC result object.
        $this->assertInstanceOf(\stdClass::class, $response['result']);
        $this->assertSame('{}', json_encode($response['result']));
    }

    public function test_initialize_echoes_a_protocol_version_it_supports(): void
    {
        $response = $this->server()->handle(
            $this->request('initialize', ['params' => ['protocolVersion' => '2025-06-18']])
        );

        $this->assertSame('2025-06-18', $response['result']['protocolVersion']);
    }

    /**
     * An unknown revision gets our own version back rather than an error, so a
     * client speaking something we have never heard of still gets a usable
     * answer instead of aborting.
     */
    public function test_initialize_falls_back_to_its_own_version_for_an_unknown_one(): void
    {
        $response = $this->server()->handle(
            $this->request('initialize', ['params' => ['protocolVersion' => '1999-01-01']])
        );

        $this->assertSame(PROMISES_MCP_PROTOCOL_VERSION, $response['result']['protocolVersion']);
    }

    public function test_initialize_advertises_tools_only(): void
    {
        $response = $this->server()->handle($this->request('initialize'));

        $this->assertSame(['tools' => ['listChanged' => false]], $response['result']['capabilities']);
        $this->assertSame('promises', $response['result']['serverInfo']['name']);
        $this->assertNotEmpty($response['result']['instructions']);
    }

    public function test_tools_list_describes_registered_tools_in_name_order(): void
    {
        $response = $this->server(
            new FakeTool('zebra_tool'),
            new FakeTool('alpha_tool')
        )->handle($this->request('tools/list'));

        $names = array_column($response['result']['tools'], 'name');

        // Sorted, because clients cache the listing and 2026-07-28 makes list
        // results explicitly cacheable — a set that reshuffles defeats that.
        $this->assertSame(['alpha_tool', 'zebra_tool'], $names);
        $this->assertTrue($response['result']['tools'][0]['annotations']['readOnlyHint']);
    }

    public function test_tools_call_returns_both_structured_and_text_content(): void
    {
        $response = $this->server(new FakeTool('alpha_tool'))->handle(
            $this->request('tools/call', ['params' => ['name' => 'alpha_tool', 'arguments' => []]])
        );

        $result = $response['result'];

        $this->assertFalse($result['isError']);
        $this->assertSame(['ok' => true], $result['structuredContent']);
        // The text block is the fallback for clients predating
        // structuredContent, so it must carry the same payload.
        $this->assertSame('text', $result['content'][0]['type']);
        $this->assertSame(['ok' => true], json_decode($result['content'][0]['text'], true));
    }

    public function test_tools_call_reports_an_unknown_tool_as_invalid_params(): void
    {
        $response = $this->server(new FakeTool('alpha_tool'))->handle(
            $this->request('tools/call', ['params' => ['name' => 'nope']])
        );

        // Invalid params, not method-not-found: tools/call exists and was
        // reached; it is the argument naming a tool that is wrong.
        $this->assertSame(JsonRpc::INVALID_PARAMS, $response['error']['code']);
        $this->assertStringContainsString('alpha_tool', $response['error']['message']);
    }

    public function test_tools_call_requires_a_name(): void
    {
        $response = $this->server()->handle($this->request('tools/call', ['params' => []]));

        $this->assertSame(JsonRpc::INVALID_PARAMS, $response['error']['code']);
    }

    /**
     * A tool declining work is a *successful* response carrying isError, not a
     * protocol error — the model is meant to read it and try something else.
     */
    public function test_a_tool_exception_becomes_an_iserror_result_not_a_protocol_error(): void
    {
        $response = $this->server(new FakeTool('alpha_tool', new ToolException('No member with id 9.')))->handle(
            $this->request('tools/call', ['params' => ['name' => 'alpha_tool']])
        );

        $this->assertArrayNotHasKey('error', $response);
        $this->assertTrue($response['result']['isError']);
        $this->assertSame('No member with id 9.', $response['result']['content'][0]['text']);
    }

    /**
     * An unexpected throwable is contained the same way, but its text must not
     * reach the caller — it may name tables, paths or values.
     */
    public function test_an_unexpected_throwable_is_contained_without_leaking_detail(): void
    {
        $response = $this->server(
            new FakeTool('alpha_tool', new \RuntimeException('SQLSTATE[42S02]: wp_trusted_rota missing'))
        )->handle($this->request('tools/call', ['params' => ['name' => 'alpha_tool']]));

        $this->assertTrue($response['result']['isError']);
        $this->assertStringNotContainsString('SQLSTATE', $response['result']['content'][0]['text']);
        $this->assertStringContainsString('alpha_tool', $response['result']['content'][0]['text']);
    }
}

/**
 * A tool that returns a fixed payload, or throws whatever it was given.
 */
final class FakeTool implements Tool
{
    public function __construct(private string $name, private ?\Throwable $throws = null)
    {
    }

    public function name(): string
    {
        return $this->name;
    }

    public function title(): string
    {
        return 'Fake ' . $this->name;
    }

    public function description(): string
    {
        return 'A tool used only by the test suite.';
    }

    public function inputSchema(): array
    {
        return ['type' => 'object', 'properties' => [], 'additionalProperties' => false];
    }

    public function isReadOnly(): bool
    {
        return true;
    }

    public function call(array $arguments): array
    {
        if ($this->throws !== null) {
            throw $this->throws;
        }

        return ['ok' => true];
    }
}
