<?php

declare(strict_types=1);

namespace Promises\Mcp;

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

/**
 * The set of tools this server exposes.
 *
 * Populated at boot by Core\PromisesServiceProvider, which decides what goes
 * in: the Unity tools always, the Trusted rota tools only when Trusted's
 * repositories are in the container, and the rota *write* tools only when the
 * admin has also switched them on.
 *
 * A tool that is not registered does not appear in tools/list and cannot be
 * called — there is no second permission check at call time, because the
 * registry is the permission check.
 */
class ToolRegistry
{
    /** @var array<string, Tool> Keyed by tool name. */
    private array $tools = [];

    public function add(Tool $tool): void
    {
        $this->tools[$tool->name()] = $tool;
    }

    public function has(string $name): bool
    {
        return isset($this->tools[$name]);
    }

    public function get(string $name): ?Tool
    {
        return $this->tools[$name] ?? null;
    }

    /**
     * @return array<string, Tool>
     */
    public function all(): array
    {
        return $this->tools;
    }

    /**
     * Every tool in MCP's tools/list shape, ordered by name so the listing is
     * stable across requests (clients cache it; 2026-07-28 makes list results
     * explicitly cacheable, and a set that reshuffles defeats that).
     *
     * @return list<array<string, mixed>>
     */
    public function describe(): array
    {
        $tools = $this->tools;
        ksort($tools);

        $described = [];

        foreach ($tools as $tool) {
            $described[] = [
                'name' => $tool->name(),
                'title' => $tool->title(),
                'description' => $tool->description(),
                'inputSchema' => $tool->inputSchema(),
                'annotations' => [
                    'title' => $tool->title(),
                    'readOnlyHint' => $tool->isReadOnly(),
                    // Nothing here deletes a member or a group. The rota write
                    // tools do remove assignments, but an assignment is
                    // re-creatable from the same arguments, so "destructive"
                    // would overstate it and push hosts into confirming reads.
                    'destructiveHint' => false,
                    // Assigning the same member to the same slot twice is a
                    // no-op rather than a second assignment, and every read is
                    // naturally idempotent.
                    'idempotentHint' => true,
                    'openWorldHint' => false,
                ],
            ];
        }

        return $described;
    }
}
