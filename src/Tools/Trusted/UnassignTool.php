<?php

declare(strict_types=1);

namespace Promises\Tools\Trusted;

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

use Promises\Logger\HasLogger;
use Promises\Mcp\ToolException;
use Promises\Support\RotaPresenter;
use Promises\Tools\BaseTool;
use Trusted\Contracts\AssignmentRepositoryInterface;

/**
 * trusted_unassign — take a responder off a shift.
 *
 * Removes one assignment and leaves the slot itself in place, so the shift
 * stays on the rota as an uncovered gap rather than disappearing. That is
 * what a coordinator means by "take them off Saturday": the shift still needs
 * staffing.
 *
 * The assignment is read before it is deleted so the response can say who was
 * removed from what. Without that the model has nothing to report back beyond
 * "done", and a mistaken call becomes hard to notice.
 *
 * Registered only when the administrator has switched rota writes on.
 */
class UnassignTool extends BaseTool
{
    use HasLogger;

    public function __construct(
        private AssignmentRepositoryInterface $assignments,
        private RotaPresenter $presenter
    ) {
    }

    public function name(): string
    {
        return 'trusted_unassign';
    }

    public function title(): string
    {
        return 'Remove a responder from a shift';
    }

    public function description(): string
    {
        return 'Remove one rota assignment, leaving the shift on the rota with nobody covering it. Takes the assignment id — not the shift id — which trusted_get_day and trusted_get_week return against each covered slot.';
    }

    public function isReadOnly(): bool
    {
        return false;
    }

    public function inputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'assignment_id' => [
                    'type' => 'integer',
                    'minimum' => 1,
                    'description' => 'The id of the assignment to remove, from the assignments array of a rota slot.',
                ],
            ],
            'required' => ['assignment_id'],
            'additionalProperties' => false,
        ];
    }

    public function call(array $arguments): array
    {
        $assignmentId = $this->requiredId($arguments, 'assignment_id');

        $assignment = $this->assignments->find($assignmentId);

        if ($assignment === null) {
            throw new ToolException(sprintf(
                'No assignment with id %d. Note this takes an assignment id, not a rota slot id.',
                $assignmentId
            ));
        }

        // Captured before the delete: afterwards there is nothing left to
        // describe, and "removed assignment 41" tells a coordinator far less
        // than naming the person and the shift.
        $removed = $this->presenter->assignment($assignment);

        if (!$this->assignments->delete($assignmentId)) {
            throw new ToolException(sprintf('Assignment %d could not be removed.', $assignmentId));
        }

        self::logInfo('Rota assignment removed over MCP', [
            'assignment_id' => $assignmentId,
            'rota_id' => $assignment->rotaId(),
            'member_id' => $assignment->memberId(),
        ]);

        return [
            'unassigned' => true,
            'removed' => $removed,
        ];
    }
}
