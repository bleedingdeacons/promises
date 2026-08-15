<?php

declare(strict_types=1);

namespace Promises\Tools\Unity;

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

use Promises\Support\Presenter;
use Promises\Tools\BaseTool;
use Unity\Groups\Interfaces\Group;
use Unity\Groups\Interfaces\GroupRepository;

/**
 * unity_list_groups — the intergroup's groups.
 */
class ListGroupsTool extends BaseTool
{
    public function __construct(
        private GroupRepository $groups,
        private Presenter $presenter
    ) {
    }

    public function name(): string
    {
        return 'unity_list_groups';
    }

    public function title(): string
    {
        return 'List groups';
    }

    public function description(): string
    {
        return 'List the AA groups registered with this intergroup, optionally filtered by a substring of the group name. Each record carries the ids of the group\'s meetings; call unity_get_meeting for the detail of one.';
    }

    public function inputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => array_merge(
                [
                    'search' => [
                        'type' => 'string',
                        'description' => 'Case-insensitive substring match against the group title. Omit to list every group.',
                    ],
                ],
                $this->paginationSchema()
            ),
            'required' => [],
            'additionalProperties' => false,
        ];
    }

    public function call(array $arguments): array
    {
        $search = strtolower($this->optionalString($arguments, 'search'));
        $limit = $this->limit($arguments);
        $offset = $this->offset($arguments);

        $groups = $this->groups->findAll();

        if ($search !== '') {
            $groups = array_values(array_filter(
                $groups,
                static fn (Group $group): bool => str_contains(strtolower($group->getTitle()), $search)
            ));
        }

        $page = array_slice($groups, $offset, $limit);

        return $this->page(
            array_map(
                fn (Group $group): array => $this->presenter->group($group),
                $page
            ),
            count($groups),
            $limit,
            $offset
        );
    }
}
