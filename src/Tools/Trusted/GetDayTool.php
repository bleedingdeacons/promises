<?php

declare(strict_types=1);

namespace Promises\Tools\Trusted;

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

use Promises\Support\RotaPresenter;
use Promises\Tools\BaseTool;
use Trusted\Contracts\RotaRepositoryInterface;

/**
 * trusted_get_day — one day of the rota.
 */
class GetDayTool extends BaseTool
{
    public function __construct(
        private RotaRepositoryInterface $rota,
        private RotaPresenter $presenter
    ) {
    }

    public function name(): string
    {
        return 'trusted_get_day';
    }

    public function title(): string
    {
        return 'Get a rota day';
    }

    public function description(): string
    {
        return 'Fetch a single day of the telephone-responder rota, ordered by start time, with the responder assigned to each shift. Prefer this over trusted_get_week when the question is about one day.';
    }

    public function inputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'date' => [
                    'type' => 'string',
                    'pattern' => '^\d{4}-\d{2}-\d{2}$',
                    'description' => 'The ISO date (YYYY-MM-DD) to fetch.',
                ],
                'uncovered_only' => [
                    'type' => 'boolean',
                    'default' => false,
                    'description' => 'Return only the slots with nobody assigned.',
                ],
            ],
            'required' => ['date'],
            'additionalProperties' => false,
        ];
    }

    public function call(array $arguments): array
    {
        $date = $this->requiredDate($arguments, 'date');

        $slots = $this->presenter->slots($this->rota->findForDate($date));

        if ($this->optionalBool($arguments, 'uncovered_only')) {
            $slots = array_values(array_filter(
                $slots,
                static fn (array $slot): bool => $slot['is_covered'] === false
            ));
        }

        return [
            'date' => $date,
            'slots' => $slots,
            'slot_count' => count($slots),
            'uncovered_count' => count(array_filter(
                $slots,
                static fn (array $slot): bool => $slot['is_covered'] === false
            )),
        ];
    }
}
