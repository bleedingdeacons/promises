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
 * trusted_get_week — a week of the telephone-responder rota.
 *
 * Trusted's repository requires the Monday that starts the week. A model
 * given "the week of the 14th" will not reliably work that out, and getting
 * it wrong returns an empty rota that reads exactly like a quiet week — so
 * this tool takes any date in the week and snaps it, rather than making
 * correctness depend on the caller's calendar arithmetic.
 */
class GetWeekTool extends BaseTool
{
    public function __construct(
        private RotaRepositoryInterface $rota,
        private RotaPresenter $presenter
    ) {
    }

    public function name(): string
    {
        return 'trusted_get_week';
    }

    public function title(): string
    {
        return 'Get a rota week';
    }

    public function description(): string
    {
        return 'Fetch one week of the telephone-responder rota: every shift slot with its assigned responder, if any. Give any date within the week — the tool snaps back to that week\'s Monday. Use trusted_get_day when you only care about a single day.';
    }

    public function inputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'date' => [
                    'type' => 'string',
                    'pattern' => '^\d{4}-\d{2}-\d{2}$',
                    'description' => 'Any ISO date (YYYY-MM-DD) falling in the week you want. The week returned always runs Monday to Sunday.',
                ],
                'uncovered_only' => [
                    'type' => 'boolean',
                    'default' => false,
                    'description' => 'Return only the slots with nobody assigned — the gaps that need filling.',
                ],
            ],
            'required' => ['date'],
            'additionalProperties' => false,
        ];
    }

    public function call(array $arguments): array
    {
        $date = $this->requiredDate($arguments, 'date');
        $weekStart = $this->mondayOnOrBefore($date);

        $slots = $this->presenter->slots($this->rota->findForWeek($weekStart));

        $uncoveredOnly = $this->optionalBool($arguments, 'uncovered_only');

        if ($uncoveredOnly) {
            $slots = array_values(array_filter(
                $slots,
                static fn (array $slot): bool => $slot['is_covered'] === false
            ));
        }

        $uncovered = count(array_filter(
            $slots,
            static fn (array $slot): bool => $slot['is_covered'] === false
        ));

        return [
            'week_start' => $weekStart,
            'week_end' => $this->addDays($weekStart, 6),
            // Echoed back because it will differ from what was asked for
            // whenever the date was mid-week, and a model that cannot see the
            // snap happen may otherwise report the wrong week to the user.
            'requested_date' => $date,
            'slots' => $slots,
            'slot_count' => count($slots),
            'uncovered_count' => $uncovered,
        ];
    }

    /**
     * The Monday on or before the given ISO date.
     */
    private function mondayOnOrBefore(string $date): string
    {
        $day = new \DateTimeImmutable($date, new \DateTimeZone('UTC'));

        // 'N' is 1 for Monday through 7 for Sunday, so this is 0 on a Monday
        // and never negative. UTC throughout: these are calendar dates, not
        // instants, and running the arithmetic in a zone with a DST shift can
        // move a midnight across a day boundary.
        $daysSinceMonday = (int) $day->format('N') - 1;

        return $day->modify(sprintf('-%d days', $daysSinceMonday))->format('Y-m-d');
    }

    private function addDays(string $date, int $days): string
    {
        return (new \DateTimeImmutable($date, new \DateTimeZone('UTC')))
            ->modify(sprintf('+%d days', $days))
            ->format('Y-m-d');
    }
}
