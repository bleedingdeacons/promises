<?php

declare(strict_types=1);

namespace Promises\Tools\Unity;

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

use Promises\Support\Presenter;
use Promises\Tools\BaseTool;
use Unity\Meetings\Interfaces\Meeting;
use Unity\Meetings\Interfaces\MeetingRepository;

/**
 * unity_list_meetings — the meeting schedule.
 *
 * This is the tool a caller-facing question most often lands on: "is there a
 * meeting tonight near me". Unity's MeetingRepository has real methods for
 * the three axes that matter — day, online/in-person, and group — so unlike
 * the member and group tools this one pushes the filtering down rather than
 * doing it in PHP.
 */
class ListMeetingsTool extends BaseTool
{
    public function __construct(
        private MeetingRepository $meetings,
        private Presenter $presenter
    ) {
    }

    public function name(): string
    {
        return 'unity_list_meetings';
    }

    public function title(): string
    {
        return 'List meetings';
    }

    public function description(): string
    {
        return 'List AA meetings, optionally filtered by day of the week, by whether they meet online or in person, by group, or by a keyword. Use this to answer "what meetings are there on a given day" or "what is on near a place".';
    }

    public function inputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => array_merge(
                [
                    'day' => [
                        'type' => 'integer',
                        'minimum' => 0,
                        'maximum' => 6,
                        'description' => 'Day of the week, 0 = Sunday through 6 = Saturday. Omit for every day.',
                    ],
                    'mode' => [
                        'type' => 'string',
                        'enum' => ['any', 'online', 'in_person'],
                        'default' => 'any',
                        'description' => 'Restrict to online-only or in-person-only meetings.',
                    ],
                    'group_id' => [
                        'type' => 'integer',
                        'minimum' => 1,
                        'description' => 'Only meetings belonging to this group.',
                    ],
                    'search' => [
                        'type' => 'string',
                        'description' => 'Keyword search across meeting names and details.',
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
        $limit = $this->limit($arguments);
        $offset = $this->offset($arguments);
        $meetings = $this->select($arguments);

        $page = array_slice($meetings, $offset, $limit);

        return $this->page(
            array_map(
                fn (Meeting $meeting): array => $this->presenter->meeting($meeting),
                $page
            ),
            count($meetings),
            $limit,
            $offset
        );
    }

    /**
     * Pick the narrowest repository method the arguments justify, then narrow
     * further in PHP for whatever it could not express.
     *
     * The repository has no combined query, so exactly one filter reaches the
     * database and the rest are applied afterwards. Order matters for cost:
     * group and day are the most selective, so they go first when present.
     *
     * @param array<string, mixed> $arguments
     * @return list<Meeting>
     */
    private function select(array $arguments): array
    {
        $search = $this->optionalString($arguments, 'search');
        $mode = $this->optionalString($arguments, 'mode', 'any');
        $groupId = isset($arguments['group_id']) ? $this->requiredId($arguments, 'group_id') : 0;
        $day = $this->day($arguments);

        if ($groupId > 0) {
            $meetings = $this->meetings->findByGroupId($groupId);
        } elseif ($day !== null) {
            $meetings = $this->meetings->findByDay($day);
        } elseif ($search !== '') {
            $meetings = $this->meetings->search($search);
        } elseif ($mode === 'online') {
            $meetings = $this->meetings->findOnline();
        } elseif ($mode === 'in_person') {
            $meetings = $this->meetings->findInPerson();
        } else {
            $meetings = $this->meetings->findAll();
        }

        $meetings = array_values($meetings);

        // Whatever the chosen query could not express, applied here.
        if ($day !== null && $groupId > 0) {
            $meetings = array_values(array_filter(
                $meetings,
                static fn (Meeting $meeting): bool => $meeting->getDay() === $day
            ));
        }

        if ($mode === 'online' && ($groupId > 0 || $day !== null || $search !== '')) {
            $meetings = array_values(array_filter(
                $meetings,
                static fn (Meeting $meeting): bool => $meeting->isOnline()
            ));
        }

        if ($mode === 'in_person' && ($groupId > 0 || $day !== null || $search !== '')) {
            $meetings = array_values(array_filter(
                $meetings,
                static fn (Meeting $meeting): bool => !$meeting->isOnline()
            ));
        }

        if ($search !== '' && ($groupId > 0 || $day !== null)) {
            $needle = strtolower($search);
            $meetings = array_values(array_filter(
                $meetings,
                static fn (Meeting $meeting): bool => str_contains(strtolower($meeting->getName()), $needle)
            ));
        }

        return $meetings;
    }

    /**
     * The day argument as 0–6, or null when absent.
     *
     * Zero is Sunday and therefore meaningful, so this cannot use the usual
     * "0 means unset" shortcut — hence the explicit null.
     *
     * @param array<string, mixed> $arguments
     */
    private function day(array $arguments): ?int
    {
        $value = $arguments['day'] ?? null;

        if (is_string($value) && ctype_digit($value)) {
            $value = (int) $value;
        }

        if (!is_int($value) || $value < 0 || $value > 6) {
            return null;
        }

        return $value;
    }
}
