<?php

declare(strict_types=1);

namespace Promises\Tests\Tools\Unity;

use Promises\Mcp\ToolException;
use Promises\Settings\Settings;
use Promises\Support\Presenter;
use Unity\Testing\Doubles\GroupStub;
use Unity\Testing\Doubles\InMemoryGroupRepository;
use Unity\Testing\Doubles\InMemoryMeetingRepository;
use Unity\Testing\Doubles\InMemoryPositionRepository;
use Unity\Testing\Doubles\LocationStub;
use Unity\Testing\Doubles\MeetingStub;
use Unity\Testing\Doubles\PositionStub;
use Promises\Tools\Unity\GetGroupTool;
use Promises\Tools\Unity\GetMeetingTool;
use Promises\Tools\Unity\GetPositionTool;
use Promises\Tools\Unity\ListGroupsTool;
use Promises\Tools\Unity\ListMeetingsTool;
use Promises\Tools\Unity\ListPositionsTool;

/*
 * The group, meeting and position tools.
 *
 * These carry no personal data, so the interesting behaviour is elsewhere:
 * which repository method the meeting tool picks for a given combination of
 * filters, and whether the presenters flatten Unity's objects into the shapes
 * the schemas promise.
 */

function directoryPresenter(): Presenter
{
    return new Presenter(new Settings());
}

function directoryGroups(): InMemoryGroupRepository
{
    return new InMemoryGroupRepository([
        new GroupStub(
            id: 1,
            title: 'Monday Steps',
            email: 'monday@example.com',
            meetings: [new MeetingStub(id: 10), new MeetingStub(id: 12)],
            phone: '07700900123',
            districtId: 4
        ),
        new GroupStub(id: 2, title: 'Harbourside Group', email: 'harbour@example.com'),
    ]);
}

function directoryMeetings(): InMemoryMeetingRepository
{
    $hall = new LocationStub(
        id: 100,
        name: 'Church Hall',
        address: '1 Example Street',
        city: 'Bristol',
        postalCode: 'BS1 1AA',
        region: 'North',
        timezone: 'Europe/London'
    );

    return new InMemoryMeetingRepository(
        [
            new MeetingStub(id: 10, name: 'Monday Steps', location: $hall, day: 1, types: ['O', 'ST']),
            new MeetingStub(
                id: 11,
                name: 'Tuesday Online Big Book',
                day: 2,
                online: true,
                onlineLink: 'https://zoom.example/11'
            ),
            new MeetingStub(id: 12, name: 'Monday Beginners', location: $hall, day: 1),
        ],
        // Meeting id => group id. The interface exposes no group, so the
        // double cannot derive this relation and takes it explicitly.
        [10 => 1, 12 => 1]
    );
}

function directoryPositions(): InMemoryPositionRepository
{
    return new InMemoryPositionRepository([
        new PositionStub(id: 1, longName: 'Telephone Coordinator', minimumSobriety: 2, termYears: 3),
        new PositionStub(id: 2, longName: 'Treasurer', minimumSobriety: 5, termYears: 2),
    ]);
}

// ── Groups ────────────────────────────────────────────────────────
describe('groups', function () {
    it('lists groups', function () {
        $result = (new ListGroupsTool(directoryGroups(), directoryPresenter()))->call([]);

        expect($result['total'])->toBe(2);
    });

    it('filters groups by name case-insensitively', function () {
        $result = (new ListGroupsTool(directoryGroups(), directoryPresenter()))->call(['search' => 'harbour']);

        expect($result['total'])->toBe(1)
            ->and($result['records'][0]['title'])->toBe('Harbourside Group');
    });

    // getMeetings() hands back hydrated Meeting objects. Only their ids go
    // out — inlining each meeting in full would make a group listing most of
    // the site's meeting table, delivered one group at a time.
    it('carries meeting ids on a group, not whole meetings', function () {
        $result = (new GetGroupTool(directoryGroups(), directoryPresenter()))->call(['id' => 1]);

        expect($result['meeting_ids'])->toBe([10, 12])
            ->and($result['district_id'])->toBe(4);
    });

    it('reports a missing id from get group', function () {
        (new GetGroupTool(directoryGroups(), directoryPresenter()))->call(['id' => 99]);
    })->throws(ToolException::class, 'No group with id 99.');
});

// ── Meetings ──────────────────────────────────────────────────────
describe('meetings', function () {
    it('lists every meeting by default', function () {
        $result = (new ListMeetingsTool(directoryMeetings(), directoryPresenter()))->call([]);

        expect($result['total'])->toBe(3);
    });

    it('filters meetings by day', function () {
        $result = (new ListMeetingsTool(directoryMeetings(), directoryPresenter()))->call(['day' => 1]);

        expect($result['total'])->toBe(2);
    });

    // Sunday is day 0, which is meaningful — so the tool must not treat it as
    // "no day given" the way an ordinary falsy check would.
    it('treats day zero as Sunday and not as absent', function () {
        $result = (new ListMeetingsTool(directoryMeetings(), directoryPresenter()))->call(['day' => 0]);

        expect($result['total'])->toBe(0);
    });

    it('filters meetings to online only', function () {
        $result = (new ListMeetingsTool(directoryMeetings(), directoryPresenter()))->call(['mode' => 'online']);

        expect($result['total'])->toBe(1)
            ->and($result['records'][0]['is_online'])->toBeTrue();
    });

    it('filters meetings to in person only', function () {
        $result = (new ListMeetingsTool(directoryMeetings(), directoryPresenter()))->call(['mode' => 'in_person']);

        expect($result['total'])->toBe(2);
    });

    it('filters meetings by group', function () {
        $result = (new ListMeetingsTool(directoryMeetings(), directoryPresenter()))->call(['group_id' => 1]);

        // Group 1 holds meetings 10 and 12; meeting 11 belongs to no group.
        expect($result['total'])->toBe(2)
            ->and(array_column($result['records'], 'id'))->toBe([10, 12]);
    });

    // group_id is the most selective filter, so it reaches the repository and
    // day is applied afterwards — the reverse of the day-plus-mode case above.
    it('narrows further in PHP when combining group and day', function () {
        $tool = new ListMeetingsTool(directoryMeetings(), directoryPresenter());

        expect($tool->call(['group_id' => 1, 'day' => 1])['total'])->toBe(2)
            ->and($tool->call(['group_id' => 1, 'day' => 2])['total'])->toBe(0);
    });

    it('searches meetings by keyword', function () {
        $result = (new ListMeetingsTool(directoryMeetings(), directoryPresenter()))->call(['search' => 'beginners']);

        expect($result['total'])->toBe(1)
            ->and($result['records'][0]['name'])->toBe('Monday Beginners');
    });

    // Only one filter can reach the repository, so the rest are applied in
    // PHP afterwards. This is the case that proves the second pass runs.
    it('narrows further in PHP when combining day and mode', function () {
        $tool = new ListMeetingsTool(directoryMeetings(), directoryPresenter());

        // Day 1 has two meetings, both in person, so asking for online as
        // well must come back empty rather than returning the day's two.
        expect($tool->call(['day' => 1, 'mode' => 'online'])['total'])->toBe(0)
            ->and($tool->call(['day' => 1, 'mode' => 'in_person'])['total'])->toBe(2);
    });

    it('narrows further in PHP when combining day and search', function () {
        $result = (new ListMeetingsTool(directoryMeetings(), directoryPresenter()))
            ->call(['day' => 1, 'search' => 'beginners']);

        expect($result['total'])->toBe(1);
    });

    it('presents a meeting\'s location', function () {
        $result = (new GetMeetingTool(directoryMeetings(), directoryPresenter()))->call(['id' => 10]);

        expect($result['location']['name'])->toBe('Church Hall')
            ->and($result['location']['city'])->toBe('Bristol')
            ->and($result['location']['timezone'])->toBe('Europe/London')
            ->and($result['types'])->toBe(['O', 'ST'])
            ->and($result['day_of_week'])->toBe('Monday');
    });

    // Location is nullable on the interface — an online-only meeting
    // legitimately has none — so it must not be dereferenced blind.
    it('gives an online meeting a null location', function () {
        $result = (new GetMeetingTool(directoryMeetings(), directoryPresenter()))->call(['id' => 11]);

        expect($result['location'])->toBeNull()
            ->and($result['online_link'])->toBe('https://zoom.example/11');
    });

    it('reports a missing id from get meeting', function () {
        (new GetMeetingTool(directoryMeetings(), directoryPresenter()))->call(['id' => 99]);
    })->throws(ToolException::class, 'No meeting with id 99.');
});

// ── Positions ─────────────────────────────────────────────────────
describe('positions', function () {
    it('lists positions', function () {
        $result = (new ListPositionsTool(directoryPositions(), directoryPresenter()))->call([]);

        expect($result['total'])->toBe(2)
            // Positions are few and slow-changing, so the default page is larger.
            ->and($result['limit'])->toBe(50);
    });

    it('filters positions by name', function () {
        $result = (new ListPositionsTool(directoryPositions(), directoryPresenter()))->call(['search' => 'treasurer']);

        expect($result['total'])->toBe(1);
    });

    it('presents a position\'s requirements', function () {
        $result = (new GetPositionTool(directoryPositions(), directoryPresenter()))->call(['id' => 1]);

        expect($result['long_name'])->toBe('Telephone Coordinator')
            ->and($result['minimum_sobriety_years'])->toBe(2)
            ->and($result['term_years'])->toBe(3);
    });

    it('reports a missing id from get position', function () {
        (new GetPositionTool(directoryPositions(), directoryPresenter()))->call(['id' => 99]);
    })->throws(ToolException::class, 'No service position with id 99.');
});
