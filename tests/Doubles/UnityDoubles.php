<?php

declare(strict_types=1);

namespace Promises\Tests\Doubles;

use Unity\Groups\Interfaces\Group;
use Unity\Groups\Interfaces\GroupRepository;
use Unity\Locations\Interfaces\Location;
use Unity\Meetings\Interfaces\Meeting;
use Unity\Meetings\Interfaces\MeetingRepository;
use Unity\Positions\Interfaces\Position;
use Unity\Positions\Interfaces\PositionRepository;

/**
 * Stand-ins for the Unity entities and repositories that Unity does not ship
 * doubles for.
 *
 * Unity provides MemberStub, InMemoryMemberRepository and FakeContainer under
 * Unity\Testing\Doubles, and the member tests use those. It provides nothing
 * for groups, meetings, locations or positions, so these fill the gap.
 *
 * They implement the real interfaces rather than being hand-shaped arrays,
 * which is the point: if Unity adds a method to Group, this file stops
 * satisfying the contract and the suite fails immediately, instead of quietly
 * asserting against a shape that no longer exists. That is the failure mode
 * Trusted's bootstrap records having been bitten by.
 */
final class GroupStub implements Group
{
    /**
     * @param Meeting[] $meetings
     * @param array<int, mixed> $contacts
     */
    public function __construct(
        private int $id = 0,
        private string $title = '',
        private string $email = '',
        private array $meetings = [],
        private string $phone = '',
        private string $website = '',
        private ?int $districtId = null,
        private string $notes = '',
        private array $contacts = []
    ) {
    }

    public function getId(): int
    {
        return $this->id;
    }

    public function getTitle(): string
    {
        return $this->title;
    }

    public function getEmail(): string
    {
        return $this->email;
    }

    public function getMeetings(): array
    {
        return $this->meetings;
    }

    public function getLink(): string
    {
        return 'https://example.test/group/' . $this->id;
    }

    public function isValid(): bool
    {
        return true;
    }

    public function getGroupNotes(): string
    {
        return $this->notes;
    }

    public function getWebsite(): string
    {
        return $this->website;
    }

    public function getPhone(): string
    {
        return $this->phone;
    }

    public function getVenmo(): string
    {
        return '';
    }

    public function getPaypal(): string
    {
        return '';
    }

    public function getSquare(): string
    {
        return '';
    }

    public function getDistrictId(): ?int
    {
        return $this->districtId;
    }

    public function getLastContact(): ?string
    {
        return null;
    }

    public function getContacts(): array
    {
        return $this->contacts;
    }

    public function hasContributionOptions(): bool
    {
        return false;
    }

    public function getUpdated(): string
    {
        return '2026-08-01 10:00:00';
    }
}

final class LocationStub implements Location
{
    public function __construct(
        private int $id = 0,
        private string $name = '',
        private string $city = '',
        private string $region = '',
        private string $postalCode = ''
    ) {
    }

    public function getId(): int
    {
        return $this->id;
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function getAddress(): string
    {
        return '1 Example Street';
    }

    public function getCity(): string
    {
        return $this->city;
    }

    public function getState(): string
    {
        return '';
    }

    public function getPostalCode(): string
    {
        return $this->postalCode;
    }

    public function getCountry(): string
    {
        return 'GB';
    }

    public function getRegion(): string
    {
        return $this->region;
    }

    public function getNotes(): string
    {
        return '';
    }

    public function getLink(): string
    {
        return 'https://example.test/location/' . $this->id;
    }

    public function getLatitude(): ?float
    {
        return null;
    }

    public function getLongitude(): ?float
    {
        return null;
    }

    public function getTimezone(): string
    {
        return 'Europe/London';
    }

    public function getMeetingIds(): array
    {
        return [];
    }

    public function isValid(): bool
    {
        return true;
    }

    public function getFormattedAddress(): string
    {
        return '1 Example Street, ' . $this->city . ' ' . $this->postalCode;
    }

    public function hasCoordinates(): bool
    {
        return false;
    }

    public function getUpdated(): string
    {
        return '2026-08-01 10:00:00';
    }
}

final class MeetingStub implements Meeting
{
    /**
     * @param array<int, string> $types
     */
    public function __construct(
        private int $id = 0,
        private string $name = '',
        private int $day = 0,
        private string $time = '19:00',
        private bool $online = false,
        private ?Location $location = null,
        private array $types = []
    ) {
    }

    public function getId(): int
    {
        return $this->id;
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function getSlug(): string
    {
        return 'meeting-' . $this->id;
    }

    public function getLocation(): ?Location
    {
        return $this->location;
    }

    public function getUrl(): string
    {
        return 'https://example.test/meeting/' . $this->id;
    }

    public function getDay(): int
    {
        return $this->day;
    }

    public function getDayOfWeek(): string
    {
        return ['Sunday', 'Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday'][$this->day] ?? '';
    }

    public function getTime(): string
    {
        return $this->time;
    }

    public function getEndTime(): string
    {
        return '20:30';
    }

    public function getTypes(): array
    {
        return $this->types;
    }

    public function getState(): string
    {
        return 'publish';
    }

    public function isOnline(): bool
    {
        return $this->online;
    }

    public function getContacts(): array
    {
        return [];
    }

    public function getMeta(): array
    {
        return [];
    }

    public function getOnlineLink(): string
    {
        return $this->online ? 'https://zoom.example/' . $this->id : '';
    }

    public function getOnlineNotes(): string
    {
        return '';
    }

    public function getUpdated(): string
    {
        return '2026-08-01 10:00:00';
    }
}

final class PositionStub implements Position
{
    public function __construct(
        private int $id = 0,
        private string $longName = '',
        private int $minimumSobriety = 2,
        private int $termYears = 3,
        private string $email = ''
    ) {
    }

    public function getId(): int
    {
        return $this->id;
    }

    public function getMinimumSobriety(): int
    {
        return $this->minimumSobriety;
    }

    public function getTermYears(): int
    {
        return $this->termYears;
    }

    public function getEmail(): string
    {
        return $this->email;
    }

    public function getLongName(): string
    {
        return $this->longName;
    }

    public function getShortDescription(): string
    {
        return 'Short description of ' . $this->longName;
    }

    public function getSummary(): string
    {
        return 'Summary of ' . $this->longName;
    }

    public function getLink(): string
    {
        return 'https://example.test/position/' . $this->id;
    }

    public function isValid(): bool
    {
        return true;
    }

    public function getUpdated(): string
    {
        return '2026-08-01 10:00:00';
    }
}

/**
 * Groups held in memory, keyed by id.
 */
final class InMemoryGroupRepository implements GroupRepository
{
    /** @var array<int, Group> */
    private array $groups = [];

    /** @param list<Group> $groups */
    public function __construct(array $groups = [])
    {
        foreach ($groups as $group) {
            $this->groups[$group->getId()] = $group;
        }
    }

    public function findById(int $id): ?Group
    {
        return $this->groups[$id] ?? null;
    }

    public function findAll(array $args = []): array
    {
        return array_values($this->groups);
    }

    public function count(array $args = []): int
    {
        return count($this->groups);
    }

    public function save(Group $group): bool
    {
        return true;
    }

    public function update(Group $group): bool
    {
        return true;
    }

    public function delete(int $id): bool
    {
        unset($this->groups[$id]);

        return true;
    }
}

/**
 * Meetings held in memory.
 *
 * The finder methods are real rather than stubbed to constants, because
 * ListMeetingsTool's whole job is choosing between them — a double that
 * returned the same set from each would make that logic untestable.
 */
final class InMemoryMeetingRepository implements MeetingRepository
{
    /** @var array<int, Meeting> */
    private array $meetings = [];

    /** @param list<Meeting> $meetings */
    public function __construct(array $meetings = [])
    {
        foreach ($meetings as $meeting) {
            $this->meetings[$meeting->getId()] = $meeting;
        }
    }

    public function findById(int $id): ?Meeting
    {
        return $this->meetings[$id] ?? null;
    }

    public function findAll(array $args = []): array
    {
        return array_values($this->meetings);
    }

    public function findByDay(int $day, array $args = []): array
    {
        return array_values(array_filter(
            $this->meetings,
            static fn (Meeting $meeting): bool => $meeting->getDay() === $day
        ));
    }

    public function findOnline(array $args = []): array
    {
        return array_values(array_filter(
            $this->meetings,
            static fn (Meeting $meeting): bool => $meeting->isOnline()
        ));
    }

    public function findInPerson(array $args = []): array
    {
        return array_values(array_filter(
            $this->meetings,
            static fn (Meeting $meeting): bool => !$meeting->isOnline()
        ));
    }

    public function findByGroupId(int $groupId, array $args = []): array
    {
        // The double keys "belongs to group N" off the meeting id being a
        // multiple of it, which is enough to tell "the tool asked by group"
        // from "the tool asked for everything".
        return array_values(array_filter(
            $this->meetings,
            static fn (Meeting $meeting): bool => $meeting->getId() % $groupId === 0
        ));
    }

    public function findByLocationId(int $locationId, array $args = []): array
    {
        return [];
    }

    public function search(string $keyword, array $args = []): array
    {
        $needle = strtolower($keyword);

        return array_values(array_filter(
            $this->meetings,
            static fn (Meeting $meeting): bool => str_contains(strtolower($meeting->getName()), $needle)
        ));
    }

    public function count(array $args = []): int
    {
        return count($this->meetings);
    }
}

/**
 * Positions held in memory.
 */
final class InMemoryPositionRepository implements PositionRepository
{
    /** @var array<int, Position> */
    private array $positions = [];

    /** @param list<Position> $positions */
    public function __construct(array $positions = [])
    {
        foreach ($positions as $position) {
            $this->positions[$position->getId()] = $position;
        }
    }

    public function findById(int $id): ?Position
    {
        return $this->positions[$id] ?? null;
    }

    public function findAll(array $args = []): array
    {
        return array_values($this->positions);
    }

    public function count(array $args = []): int
    {
        return count($this->positions);
    }

    public function save(Position $position): bool
    {
        return true;
    }

    public function update(Position $position): bool
    {
        return true;
    }

    public function delete(int $id): bool
    {
        unset($this->positions[$id]);

        return true;
    }
}
