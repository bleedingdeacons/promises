<?php

declare(strict_types=1);

namespace Promises\Support;

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

use Promises\Settings\Settings;
use Promises\Utils\Mask;
use Unity\Groups\Interfaces\Group;
use Unity\Meetings\Interfaces\Meeting;
use Unity\Members\Interfaces\Member;
use Unity\Positions\Interfaces\Position;

/**
 * Turns Unity's domain objects into the arrays tools return.
 *
 * One place, so a field can only be exposed once and masking cannot be
 * forgotten on a code path: every tool that emits a member goes through
 * member() here, and member() is the only thing that reads
 * getPersonalEmail() or getMobileNumber().
 *
 * Keys are snake_case rather than Unity's camelCase getters. Tool output is
 * read by a model, and snake_case matches the JSON conventions it will have
 * seen in the schemas alongside it.
 */
class Presenter
{
    public function __construct(private Settings $settings)
    {
    }

    /**
     * @return array<string, mixed>
     */
    public function member(Member $member): array
    {
        $mask = $this->settings->maskPii();

        $email = $member->getPersonalEmail();
        $mobile = $member->getMobileNumber();

        return [
            'id' => $member->getId(),
            'anonymous_name' => $member->getAnonymousName(),
            'anonymous_profile' => $member->getAnonymousProfile(),
            'area' => $member->getArea(),
            'home_group_id' => $member->getHomeGroup(),
            'intergroup_position_id' => $member->getIntergroupPosition(),
            'intergroup_position_rotation' => $member->getIntergroupPositionRotation(),
            'is_gsr' => $member->isGSR(),
            'is_twelfth_stepper' => $member->isTwelfthStepper(),
            'is_telephone_responder' => $member->isTelephoneResponder(),
            'responder_certification' => $member->getResponderCertification()->value,
            'accepts' => array_values($member->getAccepts()),
            'personal_email' => $mask ? Mask::email($email) : $email,
            'mobile_number' => $mask ? Mask::phone($mobile) : $mobile,
            // Stated rather than implied. Without it a model reading
            // "j___@e______.com" has to infer that the underscores are masking
            // and not the address, and it will sometimes infer wrong and offer
            // to email it.
            'contact_details_masked' => $mask,
            'gdpr_accepted' => $member->isGdprAccepted(),
            'gdpr_accepted_at' => $member->getGdprAcceptedAt(),
            'updated' => $member->getUpdated(),
        ];
    }

    /**
     * A member reduced to what a rota or a list needs.
     *
     * Used where the member is context rather than the subject — the person
     * filling a shift, say. Carries no contact details at all, masked or
     * otherwise, so listing a week's rota cannot become a way to sweep the
     * membership's phone numbers a slot at a time.
     *
     * @return array<string, mixed>
     */
    public function memberSummary(Member $member): array
    {
        return [
            'id' => $member->getId(),
            'anonymous_name' => $member->getAnonymousName(),
            'is_telephone_responder' => $member->isTelephoneResponder(),
            'responder_certification' => $member->getResponderCertification()->value,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function group(Group $group): array
    {
        return [
            'id' => $group->getId(),
            'title' => $group->getTitle(),
            'email' => $group->getEmail(),
            'phone' => $group->getPhone(),
            'website' => $group->getWebsite(),
            'link' => $group->getLink(),
            'district_id' => $group->getDistrictId(),
            'notes' => $group->getGroupNotes(),
            // getMeetings() returns hydrated Meeting objects, not ids. Only
            // the ids go out: a group listing that inlined every meeting in
            // full would be most of the site's meeting table delivered one
            // group at a time. Call unity_get_meeting for the detail.
            'meeting_ids' => array_values(array_map(
                static fn (Meeting $meeting): int => $meeting->getId(),
                $group->getMeetings()
            )),
            'has_contribution_options' => $group->hasContributionOptions(),
            'last_contact' => $group->getLastContact(),
            'is_valid' => $group->isValid(),
            'updated' => $group->getUpdated(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function meeting(Meeting $meeting): array
    {
        $location = $meeting->getLocation();

        return [
            'id' => $meeting->getId(),
            'name' => $meeting->getName(),
            'slug' => $meeting->getSlug(),
            'day' => $meeting->getDay(),
            'day_of_week' => $meeting->getDayOfWeek(),
            'time' => $meeting->getTime(),
            'end_time' => $meeting->getEndTime(),
            'types' => array_values($meeting->getTypes()),
            'is_online' => $meeting->isOnline(),
            'online_link' => $meeting->getOnlineLink(),
            'online_notes' => $meeting->getOnlineNotes(),
            'url' => $meeting->getUrl(),
            'state' => $meeting->getState(),
            // Location is nullable on the interface — an online-only meeting
            // legitimately has none — so this must not be dereferenced blind.
            'location' => $location === null ? null : [
                'id' => $location->getId(),
                'name' => $location->getName(),
                'formatted_address' => $location->getFormattedAddress(),
                'city' => $location->getCity(),
                'region' => $location->getRegion(),
                'postal_code' => $location->getPostalCode(),
                'timezone' => $location->getTimezone(),
            ],
            'updated' => $meeting->getUpdated(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function position(Position $position): array
    {
        return [
            'id' => $position->getId(),
            'long_name' => $position->getLongName(),
            'short_description' => $position->getShortDescription(),
            'summary' => $position->getSummary(),
            'email' => $position->getEmail(),
            'minimum_sobriety_years' => $position->getMinimumSobriety(),
            'term_years' => $position->getTermYears(),
            'link' => $position->getLink(),
            'is_valid' => $position->isValid(),
            'updated' => $position->getUpdated(),
        ];
    }
}
