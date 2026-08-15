<?php

declare(strict_types=1);

namespace Promises\Tools;

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

use Promises\Mcp\Tool;
use Promises\Mcp\ToolException;

/**
 * Argument reading, shared by every tool.
 *
 * Nothing validates the arguments object against the tool's own inputSchema
 * before call() runs — the schema is advisory, published so a model can build
 * a well-formed call, and a model that ignores it still reaches the tool. So
 * each accessor here re-checks the type it needs and throws a ToolException
 * naming the argument when it does not get it. The model reads that and
 * retries, which is the outcome we want; silently coercing '' to 0 and
 * looking up member zero is not.
 */
abstract class BaseTool implements Tool
{
    /**
     * Most tools only read. The two rota-write tools override this.
     */
    public function isReadOnly(): bool
    {
        return true;
    }

    /**
     * A required positive integer — an entity id, in practice.
     *
     * Accepts a numeric string as well as an int: JSON gives us whatever the
     * client sent, and a model that writes "id": "42" has expressed the same
     * intent as one that writes 42.
     *
     * @param array<string, mixed> $arguments
     * @throws ToolException
     */
    protected function requiredId(array $arguments, string $key): int
    {
        $value = $arguments[$key] ?? null;

        if (is_int($value) && $value > 0) {
            return $value;
        }

        if (is_string($value) && ctype_digit($value) && (int) $value > 0) {
            return (int) $value;
        }

        throw new ToolException(sprintf('"%s" is required and must be a positive integer id.', $key));
    }

    /**
     * @param array<string, mixed> $arguments
     * @throws ToolException
     */
    protected function requiredString(array $arguments, string $key): string
    {
        $value = $arguments[$key] ?? null;

        if (!is_string($value) || trim($value) === '') {
            throw new ToolException(sprintf('"%s" is required and must be a non-empty string.', $key));
        }

        return trim($value);
    }

    /**
     * @param array<string, mixed> $arguments
     */
    protected function optionalString(array $arguments, string $key, string $default = ''): string
    {
        $value = $arguments[$key] ?? null;

        return is_string($value) ? trim($value) : $default;
    }

    /**
     * @param array<string, mixed> $arguments
     */
    protected function optionalBool(array $arguments, string $key, bool $default = false): bool
    {
        $value = $arguments[$key] ?? null;

        return is_bool($value) ? $value : $default;
    }

    /**
     * A page size, clamped to something a context window can survive.
     *
     * The clamp is silent and deliberately so. A model asking for 5,000
     * members has not made an error worth failing the call over — it has
     * guessed at a limit — and returning the first page plus an honest
     * has_more is more useful than an error it has to recover from.
     *
     * @param array<string, mixed> $arguments
     */
    protected function limit(array $arguments, int $default = 25, int $max = 200): int
    {
        $value = $arguments['limit'] ?? null;

        if (is_string($value) && ctype_digit($value)) {
            $value = (int) $value;
        }

        if (!is_int($value) || $value < 1) {
            return $default;
        }

        return min($value, $max);
    }

    /**
     * @param array<string, mixed> $arguments
     */
    protected function offset(array $arguments): int
    {
        $value = $arguments['offset'] ?? null;

        if (is_string($value) && ctype_digit($value)) {
            $value = (int) $value;
        }

        return is_int($value) && $value > 0 ? $value : 0;
    }

    /**
     * A required ISO date (YYYY-MM-DD), validated as a real calendar date.
     *
     * checkdate() as well as the pattern: "2026-02-30" matches the shape and
     * is not a day. Left unchecked it reaches Trusted's repository, matches no
     * rows, and reports as an empty rota — which reads as "no shifts that day"
     * rather than as the typo it is.
     *
     * @param array<string, mixed> $arguments
     * @throws ToolException
     */
    protected function requiredDate(array $arguments, string $key): string
    {
        $value = $this->requiredString($arguments, $key);

        if (preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $value, $m) !== 1) {
            throw new ToolException(sprintf('"%s" must be an ISO date in YYYY-MM-DD form; got "%s".', $key, $value));
        }

        if (!checkdate((int) $m[2], (int) $m[3], (int) $m[1])) {
            throw new ToolException(sprintf('"%s" is not a real date: "%s".', $key, $value));
        }

        return $value;
    }

    /**
     * The standard limit/offset properties, for splicing into an inputSchema.
     *
     * @return array<string, mixed>
     */
    protected function paginationSchema(int $default = 25, int $max = 200): array
    {
        return [
            'limit' => [
                'type' => 'integer',
                'minimum' => 1,
                'maximum' => $max,
                'default' => $default,
                'description' => sprintf('How many records to return. Defaults to %d, capped at %d.', $default, $max),
            ],
            'offset' => [
                'type' => 'integer',
                'minimum' => 0,
                'default' => 0,
                'description' => 'How many records to skip. Use with limit to page through a large result set.',
            ],
        ];
    }

    /**
     * Wrap a page of records with the counts a model needs to decide whether
     * to ask for more.
     *
     * has_more is computed from the total rather than from whether the page
     * came back full, so a page that happens to land exactly on the end does
     * not invite a pointless extra call.
     *
     * @param list<array<string, mixed>> $records
     * @return array<string, mixed>
     */
    protected function page(array $records, int $total, int $limit, int $offset): array
    {
        return [
            'records' => $records,
            'returned' => count($records),
            'total' => $total,
            'limit' => $limit,
            'offset' => $offset,
            'has_more' => ($offset + count($records)) < $total,
        ];
    }
}
