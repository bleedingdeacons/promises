<?php

declare(strict_types=1);

namespace Promises\Tools\Unity;

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

use Promises\Support\Presenter;
use Promises\Tools\BaseTool;
use Unity\Members\Interfaces\Member;
use Unity\Members\Interfaces\MemberRepository;

/**
 * unity_list_members — find members, with the filters the helpline actually
 * asks for.
 *
 * Filtering and searching happen in PHP over the repository's result rather
 * than in the query. That is a deliberate trade: the alternative is a
 * meta_query naming ACF field keys, and those keys belong to tsml-for-unity,
 * not to Unity's interface — Promises would break the next time that plugin
 * renamed one, silently, by returning nothing. An intergroup has hundreds of
 * members, not millions, so the cost is a page of objects and the correctness
 * is worth more than the microseconds.
 *
 * The one exception is telephone_responders_only, which maps to
 * findTelephoneResponders() — a method on Unity's own interface, so using it
 * costs no coupling and does the selection in the database.
 */
class ListMembersTool extends BaseTool
{
    public function __construct(
        private MemberRepository $members,
        private Presenter $presenter
    ) {
    }

    public function name(): string
    {
        return 'unity_list_members';
    }

    public function title(): string
    {
        return 'List members';
    }

    public function description(): string
    {
        return 'Search and list Unity members. Use this to find who covers an area, who is available for 12th-step calls, or who is a certified telephone responder. Prefer unity_get_member when you already know the id. Personal contact details are masked unless an administrator has turned masking off.';
    }

    public function inputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => array_merge(
                [
                    'search' => [
                        'type' => 'string',
                        'description' => 'Case-insensitive substring match against anonymous name, profile and area. Omit to list everyone.',
                    ],
                    'area' => [
                        'type' => 'string',
                        'description' => 'Exact (case-insensitive) area match, for when you want one area rather than a fuzzy search.',
                    ],
                    'telephone_responders_only' => [
                        'type' => 'boolean',
                        'default' => false,
                        'description' => 'Only members flagged as telephone responders — the people who staff the helpline.',
                    ],
                    'twelfth_steppers_only' => [
                        'type' => 'boolean',
                        'default' => false,
                        'description' => 'Only members available for 12th-step calls. Distinct from telephone responders; a member may be either, both or neither.',
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
        $respondersOnly = $this->optionalBool($arguments, 'telephone_responders_only');
        $twelfthSteppersOnly = $this->optionalBool($arguments, 'twelfth_steppers_only');
        $search = strtolower($this->optionalString($arguments, 'search'));
        $area = strtolower($this->optionalString($arguments, 'area'));
        $limit = $this->limit($arguments);
        $offset = $this->offset($arguments);

        $members = $respondersOnly
            ? $this->members->findTelephoneResponders()
            : $this->members->findAll();

        $matched = array_values(array_filter(
            $members,
            function (Member $member) use ($twelfthSteppersOnly, $search, $area): bool {
                if ($twelfthSteppersOnly && !$member->isTwelfthStepper()) {
                    return false;
                }

                if ($area !== '' && strtolower($member->getArea()) !== $area) {
                    return false;
                }

                if ($search === '') {
                    return true;
                }

                $haystack = strtolower(implode(' ', [
                    $member->getAnonymousName(),
                    $member->getAnonymousProfile(),
                    $member->getArea(),
                ]));

                return str_contains($haystack, $search);
            }
        ));

        $page = array_slice($matched, $offset, $limit);

        return $this->page(
            array_map(
                fn (Member $member): array => $this->presenter->member($member),
                $page
            ),
            count($matched),
            $limit,
            $offset
        );
    }
}
