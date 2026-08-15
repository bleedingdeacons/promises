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
use Trusted\Contracts\RotaRepositoryInterface;
use Unity\Members\Interfaces\MemberRepository;

/**
 * trusted_assign_member — put a responder on a shift.
 *
 * The one tool here that changes who answers the helpline, so it checks its
 * ground before writing:
 *
 *  - the slot exists, so a stale id from an earlier listing fails loudly
 *    rather than writing an orphan row;
 *  - the member exists in Unity;
 *  - the member is flagged as a telephone responder. Trusted's own admin
 *    calendar will not offer a non-responder, and the repository has no
 *    opinion, so without this check the MCP surface would be the one way into
 *    the system that can roster someone untrained.
 *
 * The write itself goes through assignIfOpen(), which resolves the
 * already-taken case in the database rather than by reading first and writing
 * second. Two clients racing for the last open Saturday night therefore
 * produce one assignment and one clear refusal, not two assignments.
 *
 * Registered only when the administrator has switched rota writes on; see
 * Core\PromisesServiceProvider.
 */
class AssignMemberTool extends BaseTool
{
    use HasLogger;

    public function __construct(
        private RotaRepositoryInterface $rota,
        private AssignmentRepositoryInterface $assignments,
        private MemberRepository $members,
        private RotaPresenter $presenter
    ) {
    }

    public function name(): string
    {
        return 'trusted_assign_member';
    }

    public function title(): string
    {
        return 'Assign a responder to a shift';
    }

    public function description(): string
    {
        return 'Assign a telephone responder to an open rota shift. The member must already be flagged as a telephone responder in Unity. Fails if the shift is already covered — use trusted_unassign first if you mean to replace whoever holds it. Find open shifts with trusted_get_day or trusted_get_week and uncovered_only.';
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
                'rota_id' => [
                    'type' => 'integer',
                    'minimum' => 1,
                    'description' => 'The id of the shift slot, as returned by trusted_get_day or trusted_get_week.',
                ],
                'member_id' => [
                    'type' => 'integer',
                    'minimum' => 1,
                    'description' => 'The Unity member id of the responder to assign.',
                ],
                'notes' => [
                    'type' => 'string',
                    'description' => 'Optional note against the assignment, e.g. "covering for Ann".',
                ],
            ],
            'required' => ['rota_id', 'member_id'],
            'additionalProperties' => false,
        ];
    }

    public function call(array $arguments): array
    {
        $rotaId = $this->requiredId($arguments, 'rota_id');
        $memberId = $this->requiredId($arguments, 'member_id');
        $notes = $this->optionalString($arguments, 'notes');

        $slot = $this->rota->find($rotaId);

        if ($slot === null) {
            throw new ToolException(sprintf('No rota slot with id %d.', $rotaId));
        }

        $member = $this->members->findById($memberId);

        if ($member === null) {
            throw new ToolException(sprintf('No member with id %d.', $memberId));
        }

        if (!$member->isTelephoneResponder()) {
            throw new ToolException(sprintf(
                '%s (member %d) is not flagged as a telephone responder, so cannot be put on the rota. Flag them in Unity first.',
                $member->getAnonymousName(),
                $memberId
            ));
        }

        // memberId is a string on Trusted's side — its assignments table
        // stores it that way — so the cast is required, not incidental.
        $assignment = $this->assignments->assignIfOpen($rotaId, (string) $memberId, $notes);

        if ($assignment === null) {
            throw new ToolException(sprintf(
                'The shift on %s at %s is already covered. Remove the existing assignment with trusted_unassign before assigning someone else.',
                $slot->slotDate(),
                $slot->startTime()
            ));
        }

        self::logInfo('Rota assignment created over MCP', [
            'rota_id' => $rotaId,
            'member_id' => $memberId,
            'assignment_id' => $assignment->id(),
        ]);

        return [
            'assigned' => true,
            'assignment' => $this->presenter->assignment($assignment),
            'slot' => $this->presenter->slot($slot),
        ];
    }
}
