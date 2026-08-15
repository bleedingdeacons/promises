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
use Unity\Positions\Interfaces\PositionRepository;

/**
 * unity_get_position — one service position by id.
 */
class GetPositionTool extends BaseTool
{
    public function __construct(
        private PositionRepository $positions,
        private Presenter $presenter
    ) {
    }

    public function name(): string
    {
        return 'unity_get_position';
    }

    public function title(): string
    {
        return 'Get a service position';
    }

    public function description(): string
    {
        return 'Fetch one intergroup service position by id, including its summary, minimum sobriety and term length.';
    }

    public function inputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'id' => [
                    'type' => 'integer',
                    'minimum' => 1,
                    'description' => 'The position id.',
                ],
            ],
            'required' => ['id'],
            'additionalProperties' => false,
        ];
    }

    public function call(array $arguments): array
    {
        $id = $this->requiredId($arguments, 'id');
        $position = $this->positions->findById($id);

        if ($position === null) {
            throw new ToolException(sprintf('No service position with id %d.', $id));
        }

        return $this->presenter->position($position);
    }
}
