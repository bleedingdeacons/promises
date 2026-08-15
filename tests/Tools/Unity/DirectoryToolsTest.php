<?php

declare(strict_types=1);

namespace Promises\Tests\Tools\Unity;

use BleedingDeacons\WpMocks\TestCase;
use Promises\Mcp\ToolException;
use Promises\Settings\Settings;
use Promises\Support\Presenter;
use Promises\Tests\Doubles\GroupStub;
use Promises\Tests\Doubles\InMemoryGroupRepository;
use Promises\Tests\Doubles\InMemoryMeetingRepository;
use Promises\Tests\Doubles\InMemoryPositionRepository;
use Promises\Tests\Doubles\LocationStub;
use Promises\Tests\Doubles\MeetingStub;
use Promises\Tests\Doubles\PositionStub;
use Promises\Tools\Unity\GetGroupTool;
use Promises\Tools\Unity\GetMeetingTool;
use Promises\Tools\Unity\GetPositionTool;
use Promises\Tools\Unity\ListGroupsTool;
use Promises\Tools\Unity\ListMeetingsTool;
use Promises\Tools\Unity\ListPositionsTool;

/**
 * The group, meeting and position tools.
 *
 * These carry no personal data, so the interesting behaviour is elsewhere:
 * which repository method the meeting tool picks for a given combination of
 * filters, and whether the presenters flatten Unity's objects into the shapes
 * the schemas promise.
 */
final class DirectoryToolsTest extends TestCase
{
    private function presenter(): Presenter
    {
        return new Presenter(new Settings());
    }

    private function groupRepository(): InMemoryGroupRepository
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

    private function meetingRepository(): InMemoryMeetingRepository
    {
        $hall = new LocationStub(id: 100, name: 'Church Hall', city: 'Bristol', region: 'North', postalCode: 'BS1 1AA');

        return new InMemoryMeetingRepository([
            new MeetingStub(id: 10, name: 'Monday Steps', day: 1, online: false, location: $hall, types: ['O', 'ST']),
            new MeetingStub(id: 11, name: 'Tuesday Online Big Book', day: 2, online: true),
            new MeetingStub(id: 12, name: 'Monday Beginners', day: 1, online: false, location: $hall),
        ]);
    }

    private function positionRepository(): InMemoryPositionRepository
    {
        return new InMemoryPositionRepository([
            new PositionStub(id: 1, longName: 'Telephone Coordinator', minimumSobriety: 2, termYears: 3),
            new PositionStub(id: 2, longName: 'Treasurer', minimumSobriety: 5, termYears: 2),
        ]);
    }

    // ── Groups ────────────────────────────────────────────────────────

    public function test_it_lists_groups(): void
    {
        $result = (new ListGroupsTool($this->groupRepository(), $this->presenter()))->call([]);

        $this->assertSame(2, $result['total']);
    }

    public function test_it_filters_groups_by_name_case_insensitively(): void
    {
        $result = (new ListGroupsTool($this->groupRepository(), $this->presenter()))->call(['search' => 'harbour']);

        $this->assertSame(1, $result['total']);
        $this->assertSame('Harbourside Group', $result['records'][0]['title']);
    }

    /**
     * getMeetings() hands back hydrated Meeting objects. Only their ids go
     * out — inlining each meeting in full would make a group listing most of
     * the site's meeting table, delivered one group at a time.
     */
    public function test_a_group_carries_meeting_ids_not_whole_meetings(): void
    {
        $result = (new GetGroupTool($this->groupRepository(), $this->presenter()))->call(['id' => 1]);

        $this->assertSame([10, 12], $result['meeting_ids']);
        $this->assertSame(4, $result['district_id']);
    }

    public function test_get_group_reports_a_missing_id(): void
    {
        $this->expectException(ToolException::class);
        $this->expectExceptionMessage('No group with id 99.');

        (new GetGroupTool($this->groupRepository(), $this->presenter()))->call(['id' => 99]);
    }

    // ── Meetings ──────────────────────────────────────────────────────

    public function test_it_lists_every_meeting_by_default(): void
    {
        $result = (new ListMeetingsTool($this->meetingRepository(), $this->presenter()))->call([]);

        $this->assertSame(3, $result['total']);
    }

    public function test_it_filters_meetings_by_day(): void
    {
        $result = (new ListMeetingsTool($this->meetingRepository(), $this->presenter()))->call(['day' => 1]);

        $this->assertSame(2, $result['total']);
    }

    /**
     * Sunday is day 0, which is meaningful — so the tool must not treat it as
     * "no day given" the way an ordinary falsy check would.
     */
    public function test_day_zero_is_sunday_and_not_absent(): void
    {
        $result = (new ListMeetingsTool($this->meetingRepository(), $this->presenter()))->call(['day' => 0]);

        $this->assertSame(0, $result['total']);
    }

    public function test_it_filters_meetings_to_online_only(): void
    {
        $result = (new ListMeetingsTool($this->meetingRepository(), $this->presenter()))->call(['mode' => 'online']);

        $this->assertSame(1, $result['total']);
        $this->assertTrue($result['records'][0]['is_online']);
    }

    public function test_it_filters_meetings_to_in_person_only(): void
    {
        $result = (new ListMeetingsTool($this->meetingRepository(), $this->presenter()))->call(['mode' => 'in_person']);

        $this->assertSame(2, $result['total']);
    }

    public function test_it_searches_meetings_by_keyword(): void
    {
        $result = (new ListMeetingsTool($this->meetingRepository(), $this->presenter()))->call(['search' => 'beginners']);

        $this->assertSame(1, $result['total']);
        $this->assertSame('Monday Beginners', $result['records'][0]['name']);
    }

    /**
     * Only one filter can reach the repository, so the rest are applied in
     * PHP afterwards. This is the case that proves the second pass runs.
     */
    public function test_combining_day_and_mode_narrows_further_in_php(): void
    {
        $tool = new ListMeetingsTool($this->meetingRepository(), $this->presenter());

        // Day 1 has two meetings, both in person, so asking for online as
        // well must come back empty rather than returning the day's two.
        $this->assertSame(0, $tool->call(['day' => 1, 'mode' => 'online'])['total']);
        $this->assertSame(2, $tool->call(['day' => 1, 'mode' => 'in_person'])['total']);
    }

    public function test_combining_day_and_search_narrows_further_in_php(): void
    {
        $result = (new ListMeetingsTool($this->meetingRepository(), $this->presenter()))
            ->call(['day' => 1, 'search' => 'beginners']);

        $this->assertSame(1, $result['total']);
    }

    public function test_it_presents_a_meetings_location(): void
    {
        $result = (new GetMeetingTool($this->meetingRepository(), $this->presenter()))->call(['id' => 10]);

        $this->assertSame('Church Hall', $result['location']['name']);
        $this->assertSame('Bristol', $result['location']['city']);
        $this->assertSame('Europe/London', $result['location']['timezone']);
        $this->assertSame(['O', 'ST'], $result['types']);
        $this->assertSame('Monday', $result['day_of_week']);
    }

    /**
     * Location is nullable on the interface — an online-only meeting
     * legitimately has none — so it must not be dereferenced blind.
     */
    public function test_an_online_meeting_has_a_null_location(): void
    {
        $result = (new GetMeetingTool($this->meetingRepository(), $this->presenter()))->call(['id' => 11]);

        $this->assertNull($result['location']);
        $this->assertSame('https://zoom.example/11', $result['online_link']);
    }

    public function test_get_meeting_reports_a_missing_id(): void
    {
        $this->expectException(ToolException::class);
        $this->expectExceptionMessage('No meeting with id 99.');

        (new GetMeetingTool($this->meetingRepository(), $this->presenter()))->call(['id' => 99]);
    }

    // ── Positions ─────────────────────────────────────────────────────

    public function test_it_lists_positions(): void
    {
        $result = (new ListPositionsTool($this->positionRepository(), $this->presenter()))->call([]);

        $this->assertSame(2, $result['total']);
        // Positions are few and slow-changing, so the default page is larger.
        $this->assertSame(50, $result['limit']);
    }

    public function test_it_filters_positions_by_name(): void
    {
        $result = (new ListPositionsTool($this->positionRepository(), $this->presenter()))->call(['search' => 'treasurer']);

        $this->assertSame(1, $result['total']);
    }

    public function test_it_presents_a_positions_requirements(): void
    {
        $result = (new GetPositionTool($this->positionRepository(), $this->presenter()))->call(['id' => 1]);

        $this->assertSame('Telephone Coordinator', $result['long_name']);
        $this->assertSame(2, $result['minimum_sobriety_years']);
        $this->assertSame(3, $result['term_years']);
    }

    public function test_get_position_reports_a_missing_id(): void
    {
        $this->expectException(ToolException::class);
        $this->expectExceptionMessage('No service position with id 99.');

        (new GetPositionTool($this->positionRepository(), $this->presenter()))->call(['id' => 99]);
    }
}
