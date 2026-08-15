<?php

declare(strict_types=1);

namespace Promises\Support;

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

use Promises\Settings\Settings;
use Promises\Utils\Mask;
use Trusted\Domain\Assignment;
use Trusted\Domain\Rota;

/**
 * Turns Trusted's rota objects into tool output.
 *
 * Rota and Assignment both implement JsonSerializable, so it would be one
 * line to hand them straight to the encoder — and that is exactly the bug
 * this class exists to avoid. Trusted's own Member value object carries
 * `email` and `telephone` as plain strings, and its toArray() emits both, so
 * serialising a week's rota directly would publish the personal contact
 * details of every responder on shift regardless of the masking setting. This
 * builds the arrays by hand instead, and the two contact fields are the only
 * ones it reads.
 *
 * Kept apart from Presenter because it names Trusted's classes: Trusted is an
 * optional dependency, and nothing that references it may sit on a path
 * reachable when it is absent. RotaPresenter is only ever constructed inside
 * the branch that has already confirmed Trusted's repositories are bound.
 */
class RotaPresenter
{
    public function __construct(private Settings $settings)
    {
    }

    /**
     * @param Rota[] $slots
     * @return list<array<string, mixed>>
     */
    public function slots(array $slots): array
    {
        return array_values(array_map(
            fn (Rota $slot): array => $this->slot($slot),
            $slots
        ));
    }

    /**
     * @return array<string, mixed>
     */
    public function slot(Rota $slot): array
    {
        $assignments = array_values(array_map(
            fn (Assignment $assignment): array => $this->assignment($assignment),
            $slot->assignments()
        ));

        return [
            'id' => $slot->id(),
            'date' => $slot->slotDate(),
            'start' => $slot->startTime(),
            'end' => $slot->endTime(),
            'label' => $slot->label(),
            'template_id' => $slot->templateId(),
            'assignments' => $assignments,
            // The question a model is nearly always about to ask next, and
            // cheaper to answer here than to have it infer from an empty
            // array it might read as "not loaded".
            'is_covered' => $assignments !== [],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function assignment(Assignment $assignment): array
    {
        $member = $assignment->member();
        $mask = $this->settings->maskPii();

        return [
            'id' => $assignment->id(),
            'rota_id' => $assignment->rotaId(),
            'member_id' => $assignment->memberId(),
            'notes' => $assignment->notes(),
            'assigned_at' => $assignment->assignedAt(),
            // Null when the responder record could not be resolved — a member
            // deleted after being rostered, most often. Left as null rather
            // than faked, because "this shift is assigned to someone who no
            // longer exists" is a real state an admin needs to see.
            'member' => $member === null ? null : [
                'id' => $member->id(),
                'name' => $member->name(),
                'email' => $mask ? Mask::email($member->email()) : $member->email(),
                'telephone' => $mask ? Mask::phone($member->telephone()) : $member->telephone(),
                'contact_details_masked' => $mask,
            ],
        ];
    }
}
