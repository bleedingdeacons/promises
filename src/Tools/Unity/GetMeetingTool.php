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
use Unity\Meetings\Interfaces\MeetingRepository;

/**
 * unity_get_meeting — one meeting by id, with its location.
 */
class GetMeetingTool extends BaseTool
{
    public function __construct(
        private MeetingRepository $meetings,
        private Presenter $presenter
    ) {
    }

    public function name(): string
    {
        return 'unity_get_meeting';
    }

    public function title(): string
    {
        return 'Get a meeting';
    }

    public function description(): string
    {
        return 'Fetch one meeting by id, including its day, time, types and full location. Location is null for meetings that are online only.';
    }

    public function inputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'id' => [
                    'type' => 'integer',
                    'minimum' => 1,
                    'description' => 'The meeting id.',
                ],
            ],
            'required' => ['id'],
            'additionalProperties' => false,
        ];
    }

    public function call(array $arguments): array
    {
        $id = $this->requiredId($arguments, 'id');
        $meeting = $this->meetings->findById($id);

        if ($meeting === null) {
            throw new ToolException(sprintf('No meeting with id %d.', $id));
        }

        return $this->presenter->meeting($meeting);
    }
}
