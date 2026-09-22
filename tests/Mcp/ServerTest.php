<?php

declare(strict_types=1);

namespace Promises\Tests\Mcp;

use Promises\Mcp\JsonRpc;
use Promises\Mcp\Server;
use Promises\Mcp\Tool;
use Promises\Mcp\ToolException;
use Promises\Mcp\ToolRegistry;

/*
 * MCP dispatch.
 *
 * The protocol layer is where a mistake is least visible from the outside: a
 * client that gets a subtly wrong envelope tends to fail with something
 * unhelpful several steps later, so these assert the shapes directly.
 */

function mcpServer(Tool ...$tools): Server
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
function rpcRequest(string $method, array $extra = []): array
{
    return array_merge(['jsonrpc' => '2.0', 'id' => 1, 'method' => $method], $extra);
}

describe('the envelope', function () {
    it('rejects a payload that is not a JSON-RPC request', function () {
        $response = mcpServer()->handle(['method' => 'ping']);

        expect($response['error']['code'])->toBe(JsonRpc::INVALID_REQUEST);
    });

    it('rejects a non-array payload', function () {
        $response = mcpServer()->handle('not json-rpc at all');

        expect($response['error']['code'])->toBe(JsonRpc::INVALID_REQUEST)
            ->and($response['id'])->toBeNull();
    });

    it('reports an unknown method', function () {
        $response = mcpServer()->handle(rpcRequest('resources/list'));

        expect($response['error']['code'])->toBe(JsonRpc::METHOD_NOT_FOUND)
            // The message names what *is* available, so a client can recover
            // without a second round trip.
            ->and($response['error']['message'])->toContain('tools/list');
    });

    // A notification has no id and must produce no response at all. Answering
    // one breaks a down-level client that is not listening for a reply.
    it('returns nothing for a notification', function () {
        $response = mcpServer()->handle([
            'jsonrpc' => '2.0',
            'method' => 'notifications/initialized',
        ]);

        expect($response)->toBeNull();
    });

    it('answers ping with an empty object', function () {
        $response = mcpServer()->handle(rpcRequest('ping'));

        // Must encode as {} and not [] — an empty PHP array would become the
        // latter, which is not a valid JSON-RPC result object.
        expect($response['result'])->toBeInstanceOf(\stdClass::class)
            ->and(json_encode($response['result']))->toBe('{}');
    });
});

describe('initialize', function () {
    it('echoes a protocol version it supports', function () {
        $response = mcpServer()->handle(
            rpcRequest('initialize', ['params' => ['protocolVersion' => '2025-06-18']])
        );

        expect($response['result']['protocolVersion'])->toBe('2025-06-18');
    });

    // An unknown revision gets our own version back rather than an error, so a
    // client speaking something we have never heard of still gets a usable
    // answer instead of aborting.
    it('falls back to its own version for an unknown one', function () {
        $response = mcpServer()->handle(
            rpcRequest('initialize', ['params' => ['protocolVersion' => '1999-01-01']])
        );

        expect($response['result']['protocolVersion'])->toBe(PROMISES_MCP_PROTOCOL_VERSION);
    });

    it('advertises tools only', function () {
        $response = mcpServer()->handle(rpcRequest('initialize'));

        expect($response['result']['capabilities'])->toBe(['tools' => ['listChanged' => false]])
            ->and($response['result']['serverInfo']['name'])->toBe('promises')
            ->and($response['result']['instructions'])->not->toBeEmpty();
    });
});

describe('tools/list', function () {
    it('describes registered tools in name order', function () {
        $response = mcpServer(
            new FakeTool('zebra_tool'),
            new FakeTool('alpha_tool')
        )->handle(rpcRequest('tools/list'));

        $names = array_column($response['result']['tools'], 'name');

        // Sorted, because clients cache the listing and 2026-07-28 makes list
        // results explicitly cacheable — a set that reshuffles defeats that.
        expect($names)->toBe(['alpha_tool', 'zebra_tool'])
            ->and($response['result']['tools'][0]['annotations']['readOnlyHint'])->toBeTrue();
    });
});

describe('tools/call', function () {
    it('returns both structured and text content', function () {
        $response = mcpServer(new FakeTool('alpha_tool'))->handle(
            rpcRequest('tools/call', ['params' => ['name' => 'alpha_tool', 'arguments' => []]])
        );

        $result = $response['result'];

        expect($result['isError'])->toBeFalse()
            ->and($result['structuredContent'])->toBe(['ok' => true])
            // The text block is the fallback for clients predating
            // structuredContent, so it must carry the same payload.
            ->and($result['content'][0]['type'])->toBe('text')
            ->and(json_decode($result['content'][0]['text'], true))->toBe(['ok' => true]);
    });

    it('reports an unknown tool as invalid params', function () {
        $response = mcpServer(new FakeTool('alpha_tool'))->handle(
            rpcRequest('tools/call', ['params' => ['name' => 'nope']])
        );

        // Invalid params, not method-not-found: tools/call exists and was
        // reached; it is the argument naming a tool that is wrong.
        expect($response['error']['code'])->toBe(JsonRpc::INVALID_PARAMS)
            ->and($response['error']['message'])->toContain('alpha_tool');
    });

    it('requires a name', function () {
        $response = mcpServer()->handle(rpcRequest('tools/call', ['params' => []]));

        expect($response['error']['code'])->toBe(JsonRpc::INVALID_PARAMS);
    });

    // A tool declining work is a *successful* response carrying isError, not a
    // protocol error — the model is meant to read it and try something else.
    it('turns a tool exception into an isError result, not a protocol error', function () {
        $response = mcpServer(new FakeTool('alpha_tool', new ToolException('No member with id 9.')))->handle(
            rpcRequest('tools/call', ['params' => ['name' => 'alpha_tool']])
        );

        expect($response)->not->toHaveKey('error')
            ->and($response['result']['isError'])->toBeTrue()
            ->and($response['result']['content'][0]['text'])->toBe('No member with id 9.');
    });

    // An unexpected throwable is contained the same way, but its text must not
    // reach the caller — it may name tables, paths or values.
    it('contains an unexpected throwable without leaking detail', function () {
        $response = mcpServer(
            new FakeTool('alpha_tool', new \RuntimeException('SQLSTATE[42S02]: wp_trusted_rota missing'))
        )->handle(rpcRequest('tools/call', ['params' => ['name' => 'alpha_tool']]));

        expect($response['result']['isError'])->toBeTrue()
            ->and($response['result']['content'][0]['text'])->not->toContain('SQLSTATE')
            ->and($response['result']['content'][0]['text'])->toContain('alpha_tool');
    });
});

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
