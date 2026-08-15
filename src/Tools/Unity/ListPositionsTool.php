<?php

declare(strict_types=1);

namespace Promises\Tools\Unity;

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

use Promises\Support\Presenter;
use Promises\Tools\BaseTool;
use Unity\Positions\Interfaces\Position;
use Unity\Positions\Interfaces\PositionRepository;

/**
 * unity_list_positions — the intergroup's service positions.
 *
 * Small and slow-changing, so this one lists everything by default and pages
 * only because the shared helper makes it free to do so.
 */
class ListPositionsTool extends BaseTool
{
    public function __construct(
        private PositionRepository $positions,
        private Presenter $presenter
    ) {
    }

    public function name(): string
    {
        return 'unity_list_positions';
    }

    public function title(): string
    {
        return 'List service positions';
    }

    public function description(): string
    {
        return 'List the intergroup service positions — secretary, treasurer, telephone coordinator and so on — with their sobriety requirements and term lengths. A member\'s intergroup_position_id refers to one of these.';
    }

    public function inputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => array_merge(
                [
                    'search' => [
                        'type' => 'string',
                        'description' => 'Case-insensitive substring match against the position name.',
                    ],
                ],
                $this->paginationSchema(50)
            ),
            'required' => [],
            'additionalProperties' => false,
        ];
    }

    public function call(array $arguments): array
    {
        $search = strtolower($this->optionalString($arguments, 'search'));
        $limit = $this->limit($arguments, 50);
        $offset = $this->offset($arguments);

        $positions = $this->positions->findAll();

        if ($search !== '') {
            $positions = array_values(array_filter(
                $positions,
                static fn (Position $position): bool => str_contains(strtolower($position->getLongName()), $search)
            ));
        }

        $page = array_slice($positions, $offset, $limit);

        return $this->page(
            array_map(
                fn (Position $position): array => $this->presenter->position($position),
                $page
            ),
            count($positions),
            $limit,
            $offset
        );
    }
}
