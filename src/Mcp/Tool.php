<?php

declare(strict_types=1);

namespace Promises\Mcp;

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

/**
 * One MCP tool.
 *
 * Implementations describe themselves well enough for a model to choose them
 * unaided — name, a description that says *when* to reach for the tool and not
 * only what it does, and a JSON Schema for the arguments.
 *
 * call() returns structured data. Wrapping it into MCP's content blocks is
 * Server's job, so tools never build protocol envelopes and can be tested by
 * asserting on a plain array.
 */
interface Tool
{
    /**
     * Unique machine name, e.g. "unity_get_member".
     *
     * Prefixed by the plugin the data comes from rather than by "promises",
     * so a model reading a mixed tool list can tell Unity's data from
     * Trusted's.
     */
    public function name(): string;

    /**
     * Short human-readable title for client UIs.
     */
    public function title(): string;

    /**
     * What the tool does and when to call it.
     */
    public function description(): string;

    /**
     * JSON Schema for the arguments object.
     *
     * @return array<string, mixed>
     */
    public function inputSchema(): array;

    /**
     * Whether the tool only reads.
     *
     * Surfaced to clients as the readOnlyHint annotation, which is what lets
     * a host decide a call needs confirming. Every Unity tool here is true;
     * the rota assignment tools are false.
     */
    public function isReadOnly(): bool;

    /**
     * Execute the tool.
     *
     * @param array<string, mixed> $arguments Validated against inputSchema by
     *                                        the caller only as far as required
     *                                        keys — implementations still check
     *                                        their own types.
     * @return array<string, mixed> Structured result, JSON-encodable.
     * @throws ToolException When the arguments are unusable or the underlying
     *                       repository refuses the operation. Server turns this
     *                       into an isError tool result, not a protocol error.
     */
    public function call(array $arguments): array;
}
