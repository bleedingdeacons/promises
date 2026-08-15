<?php

declare(strict_types=1);

namespace Promises\Tools\Unity;

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

use Promises\Mcp\ToolException;
use Promises\Support\Presenter;
use Promises\Tools\BaseTool;
use Unity\Groups\Interfaces\GroupRepository;

/**
 * unity_get_group — one group by id.
 */
class GetGroupTool extends BaseTool
{
    public function __construct(
        private GroupRepository $groups,
        private Presenter $presenter
    ) {
    }

    public function name(): string
    {
        return 'unity_get_group';
    }

    public function title(): string
    {
        return 'Get a group';
    }

    public function description(): string
    {
        return 'Fetch one AA group by id, including its contact details and the ids of its meetings.';
    }

    public function inputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'id' => [
                    'type' => 'integer',
                    'minimum' => 1,
                    'description' => 'The group id.',
                ],
            ],
            'required' => ['id'],
            'additionalProperties' => false,
        ];
    }

    public function call(array $arguments): array
    {
        $id = $this->requiredId($arguments, 'id');
        $group = $this->groups->findById($id);

        if ($group === null) {
            throw new ToolException(sprintf('No group with id %d.', $id));
        }

        return $this->presenter->group($group);
    }
}
