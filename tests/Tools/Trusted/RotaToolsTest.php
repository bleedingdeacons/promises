<?php

declare(strict_types=1);

namespace Promises\Tests\Tools\Trusted;

use BleedingDeacons\WpMocks\TestCase;
use Promises\Mcp\ToolException;
use Promises\Settings\Settings;
use Promises\Support\RotaPresenter;
use Promises\Tools\Trusted\AssignMemberTool;
use Promises\Tools\Trusted\GetDayTool;
use Promises\Tools\Trusted\GetWeekTool;
use Promises\Tools\Trusted\UnassignTool;
use Trusted\Domain\Assignment;
use Trusted\Domain\Member as TrustedMember;
use Trusted\Domain\Rota;
use Trusted\Testing\Doubles\InMemoryAssignmentRepository;
use Trusted\Testing\Doubles\InMemoryRotaRepository;
use Unity\Testing\Doubles\InMemoryMemberRepository;
use Unity\Testing\Doubles\MemberStub;

/**
 * The Trusted rota tools.
 *
 * Skipped wholesale when Trusted is not checked out alongside, which mirrors
 * how the plugin itself behaves — and exercises the same "is Trusted here at
 * all" question the service provider asks before registering any of this.
 */
final class RotaToolsTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        if (!PROMISES_TESTS_HAVE_TRUSTED) {
            $this->markTestSkipped('Trusted is not checked out alongside this plugin.');
        }
    }

    private function presenter(): RotaPresenter
    {
        return new RotaPresenter(new Settings());
    }

    /**
     * Wednesday 12 August 2026 and Thursday the 13th; the Wednesday shift is
     * covered, the Thursday one is not.
     */
    private function rotaRepository(): InMemoryRotaRepository
    {
        $ann = new TrustedMember('1', 'Ann B', 'ann@example.com', '07700900123');

        // Keyed by id — Trusted's double indexes on the array key, not on
        // Rota::id(), so a plain list would make find(10) miss.
        return new InMemoryRotaRepository([
            10 => new Rota(10, '2026-08-12', '18:00', '22:00', 'Evening', null, [
                new Assignment(100, 10, '1', 'regular', '2026-08-01 09:00:00', $ann),
            ]),
            11 => new Rota(11, '2026-08-13', '18:00', '22:00', 'Evening'),
        ]);
    }

    public function test_it_snaps_a_midweek_date_back_to_that_weeks_monday(): void
    {
        $tool = new GetWeekTool($this->rotaRepository(), $this->presenter());

        // The 12th is a Wednesday.
        $result = $tool->call(['date' => '2026-08-12']);

        $this->assertSame('2026-08-10', $result['week_start']);
        $this->assertSame('2026-08-16', $result['week_end']);
        // Echoed back, because it differs from week_start whenever the date
        // was mid-week and a model cannot see the snap happen.
        $this->assertSame('2026-08-12', $result['requested_date']);
    }

    public function test_a_monday_snaps_to_itself(): void
    {
        $tool = new GetWeekTool($this->rotaRepository(), $this->presenter());

        $this->assertSame('2026-08-10', $tool->call(['date' => '2026-08-10'])['week_start']);
    }

    public function test_a_sunday_snaps_back_six_days_not_forward_one(): void
    {
        $tool = new GetWeekTool($this->rotaRepository(), $this->presenter());

        // 16 August 2026 is a Sunday; ISO weeks end on it rather than start.
        $this->assertSame('2026-08-10', $tool->call(['date' => '2026-08-16'])['week_start']);
    }

    public function test_it_counts_covered_and_uncovered_slots(): void
    {
        $tool = new GetWeekTool($this->rotaRepository(), $this->presenter());

        $result = $tool->call(['date' => '2026-08-12']);

        $this->assertSame(2, $result['slot_count']);
        $this->assertSame(1, $result['uncovered_count']);
        $this->assertTrue($result['slots'][0]['is_covered']);
        $this->assertFalse($result['slots'][1]['is_covered']);
    }

    public function test_uncovered_only_returns_just_the_gaps(): void
    {
        $tool = new GetWeekTool($this->rotaRepository(), $this->presenter());

        $result = $tool->call(['date' => '2026-08-12', 'uncovered_only' => true]);

        $this->assertSame(1, $result['slot_count']);
        $this->assertSame('2026-08-13', $result['slots'][0]['date']);
    }

    /**
     * Trusted's own Member value object serialises email and telephone in
     * plain text, so this is the assertion that stops a week's rota becoming a
     * contact-details export.
     */
    public function test_it_masks_the_responders_contact_details(): void
    {
        $tool = new GetDayTool($this->rotaRepository(), $this->presenter());

        $member = $tool->call(['date' => '2026-08-12'])['slots'][0]['assignments'][0]['member'];

        $this->assertSame('a__@e______.com', $member['email']);
        $this->assertStringNotContainsString('07700900', $member['telephone']);
        $this->assertTrue($member['contact_details_masked']);
    }

    public function test_it_rejects_a_date_that_is_not_a_real_day(): void
    {
        $tool = new GetDayTool($this->rotaRepository(), $this->presenter());

        $this->expectException(ToolException::class);
        // Matching the shape is not enough: left unchecked this reaches the
        // repository, matches nothing, and reads as a quiet day rather than a
        // typo.
        $this->expectExceptionMessage('not a real date');

        $tool->call(['date' => '2026-02-30']);
    }

    public function test_it_rejects_a_date_in_the_wrong_format(): void
    {
        $tool = new GetDayTool($this->rotaRepository(), $this->presenter());

        $this->expectException(ToolException::class);

        $tool->call(['date' => '12/08/2026']);
    }

    // ── Writes ────────────────────────────────────────────────────────

    private function assignTool(InMemoryAssignmentRepository $assignments): AssignMemberTool
    {
        $members = new InMemoryMemberRepository([
            new MemberStub(id: 1, anonymousName: 'Ann B', telephoneResponder: true),
            new MemberStub(id: 2, anonymousName: 'Bob C', telephoneResponder: false),
        ]);

        return new AssignMemberTool($this->rotaRepository(), $assignments, $members, $this->presenter());
    }

    public function test_it_assigns_a_responder_to_an_open_shift(): void
    {
        $assignments = new InMemoryAssignmentRepository();

        $result = $this->assignTool($assignments)->call(['rota_id' => 11, 'member_id' => 1]);

        $this->assertTrue($result['assigned']);
        $this->assertSame('1', $result['assignment']['member_id']);
        $this->assertSame(11, $result['assignment']['rota_id']);
    }

    /**
     * Trusted's admin calendar will not offer a non-responder and the
     * repository has no opinion, so without this check the MCP surface would
     * be the one way into the system that can roster someone untrained.
     */
    public function test_it_refuses_to_assign_someone_who_is_not_a_telephone_responder(): void
    {
        $this->expectException(ToolException::class);
        $this->expectExceptionMessage('not flagged as a telephone responder');

        $this->assignTool(new InMemoryAssignmentRepository())->call(['rota_id' => 11, 'member_id' => 2]);
    }

    public function test_it_refuses_a_shift_that_is_already_covered(): void
    {
        // Trusted's double models the UNIQUE(rota_id) constraint for real:
        // seeding an assignment against slot 10 is what makes assignIfOpen()
        // return null, rather than a flag saying so.
        $assignments = new InMemoryAssignmentRepository([
            100 => new Assignment(100, 10, '1', '', '2026-08-01 09:00:00'),
        ]);

        $this->expectException(ToolException::class);
        $this->expectExceptionMessage('already covered');

        $this->assignTool($assignments)->call(['rota_id' => 10, 'member_id' => 1]);
    }

    public function test_it_reports_an_unknown_slot(): void
    {
        $this->expectException(ToolException::class);
        $this->expectExceptionMessage('No rota slot with id 999.');

        $this->assignTool(new InMemoryAssignmentRepository())->call(['rota_id' => 999, 'member_id' => 1]);
    }

    public function test_it_reports_an_unknown_member(): void
    {
        $this->expectException(ToolException::class);
        $this->expectExceptionMessage('No member with id 42.');

        $this->assignTool(new InMemoryAssignmentRepository())->call(['rota_id' => 11, 'member_id' => 42]);
    }

    public function test_unassign_removes_the_assignment_and_says_what_went(): void
    {
        $ann = new TrustedMember('1', 'Ann B', 'ann@example.com', '07700900123');
        $assignments = new InMemoryAssignmentRepository([
            100 => new Assignment(100, 10, '1', 'regular', '2026-08-01 09:00:00', $ann),
        ]);

        $result = (new UnassignTool($assignments, $this->presenter()))->call(['assignment_id' => 100]);

        $this->assertTrue($result['unassigned']);
        // Captured before the delete — afterwards there is nothing left to
        // describe, and naming the person beats "removed assignment 100".
        $this->assertSame('Ann B', $result['removed']['member']['name']);
        $this->assertNull($assignments->find(100));
    }

    public function test_unassign_reports_an_unknown_assignment_and_says_which_id_it_wants(): void
    {
        $this->expectException(ToolException::class);
        $this->expectExceptionMessage('not a rota slot id');

        (new UnassignTool(new InMemoryAssignmentRepository(), $this->presenter()))->call(['assignment_id' => 55]);
    }

    public function test_the_write_tools_declare_themselves_as_not_read_only(): void
    {
        // Surfaced to clients as readOnlyHint, which is what lets a host
        // decide a call needs confirming.
        $this->assertFalse($this->assignTool(new InMemoryAssignmentRepository())->isReadOnly());
        $this->assertFalse((new UnassignTool(new InMemoryAssignmentRepository(), $this->presenter()))->isReadOnly());
    }
}
