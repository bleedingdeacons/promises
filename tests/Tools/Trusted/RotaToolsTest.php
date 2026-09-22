<?php

declare(strict_types=1);

namespace Promises\Tests\Tools\Trusted;

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

/*
 * The Trusted rota tools.
 *
 * Skipped wholesale when Trusted is not checked out alongside, which mirrors
 * how the plugin itself behaves — and exercises the same "is Trusted here at
 * all" question the service provider asks before registering any of this.
 */

function rotaToolsPresenter(): RotaPresenter
{
    return new RotaPresenter(new Settings());
}

/**
 * Wednesday 12 August 2026 and Thursday the 13th; the Wednesday shift is
 * covered, the Thursday one is not.
 */
function rotaToolsRepository(): InMemoryRotaRepository
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

function rotaAssignTool(InMemoryAssignmentRepository $assignments): AssignMemberTool
{
    $members = new InMemoryMemberRepository([
        new MemberStub(id: 1, anonymousName: 'Ann B', telephoneResponder: true),
        new MemberStub(id: 2, anonymousName: 'Bob C', telephoneResponder: false),
    ]);

    return new AssignMemberTool(rotaToolsRepository(), $assignments, $members, rotaToolsPresenter());
}

beforeEach(function () {
    if (!PROMISES_TESTS_HAVE_TRUSTED) {
        $this->markTestSkipped('Trusted is not checked out alongside this plugin.');
    }
});

describe('reads', function () {
    it('snaps a midweek date back to that week\'s Monday', function () {
        $tool = new GetWeekTool(rotaToolsRepository(), rotaToolsPresenter());

        // The 12th is a Wednesday.
        $result = $tool->call(['date' => '2026-08-12']);

        expect($result['week_start'])->toBe('2026-08-10')
            ->and($result['week_end'])->toBe('2026-08-16')
            // Echoed back, because it differs from week_start whenever the date
            // was mid-week and a model cannot see the snap happen.
            ->and($result['requested_date'])->toBe('2026-08-12');
    });

    it('snaps a Monday to itself', function () {
        $tool = new GetWeekTool(rotaToolsRepository(), rotaToolsPresenter());

        expect($tool->call(['date' => '2026-08-10'])['week_start'])->toBe('2026-08-10');
    });

    it('snaps a Sunday back six days, not forward one', function () {
        $tool = new GetWeekTool(rotaToolsRepository(), rotaToolsPresenter());

        // 16 August 2026 is a Sunday; ISO weeks end on it rather than start.
        expect($tool->call(['date' => '2026-08-16'])['week_start'])->toBe('2026-08-10');
    });

    it('counts covered and uncovered slots', function () {
        $tool = new GetWeekTool(rotaToolsRepository(), rotaToolsPresenter());

        $result = $tool->call(['date' => '2026-08-12']);

        expect($result['slot_count'])->toBe(2)
            ->and($result['uncovered_count'])->toBe(1)
            ->and($result['slots'][0]['is_covered'])->toBeTrue()
            ->and($result['slots'][1]['is_covered'])->toBeFalse();
    });

    it('returns just the gaps when asked for uncovered only', function () {
        $tool = new GetWeekTool(rotaToolsRepository(), rotaToolsPresenter());

        $result = $tool->call(['date' => '2026-08-12', 'uncovered_only' => true]);

        expect($result['slot_count'])->toBe(1)
            ->and($result['slots'][0]['date'])->toBe('2026-08-13');
    });

    // Trusted's own Member value object serialises email and telephone in
    // plain text, so this is the assertion that stops a week's rota becoming a
    // contact-details export.
    it('masks the responder\'s contact details', function () {
        $tool = new GetDayTool(rotaToolsRepository(), rotaToolsPresenter());

        $member = $tool->call(['date' => '2026-08-12'])['slots'][0]['assignments'][0]['member'];

        expect($member['email'])->toBe('a__@e______.com')
            ->and($member['telephone'])->not->toContain('07700900')
            ->and($member['contact_details_masked'])->toBeTrue();
    });

    // Matching the shape is not enough: left unchecked this reaches the
    // repository, matches nothing, and reads as a quiet day rather than a
    // typo.
    it('rejects a date that is not a real day', function () {
        $tool = new GetDayTool(rotaToolsRepository(), rotaToolsPresenter());

        $tool->call(['date' => '2026-02-30']);
    })->throws(ToolException::class, 'not a real date');

    it('rejects a date in the wrong format', function () {
        $tool = new GetDayTool(rotaToolsRepository(), rotaToolsPresenter());

        $tool->call(['date' => '12/08/2026']);
    })->throws(ToolException::class);
});

// ── Writes ────────────────────────────────────────────────────────
describe('writes', function () {
    it('assigns a responder to an open shift', function () {
        $assignments = new InMemoryAssignmentRepository();

        $result = rotaAssignTool($assignments)->call(['rota_id' => 11, 'member_id' => 1]);

        expect($result['assigned'])->toBeTrue()
            ->and($result['assignment']['member_id'])->toBe('1')
            ->and($result['assignment']['rota_id'])->toBe(11);
    });

    // Trusted's admin calendar will not offer a non-responder and the
    // repository has no opinion, so without this check the MCP surface would
    // be the one way into the system that can roster someone untrained.
    it('refuses to assign someone who is not a telephone responder', function () {
        rotaAssignTool(new InMemoryAssignmentRepository())->call(['rota_id' => 11, 'member_id' => 2]);
    })->throws(ToolException::class, 'not flagged as a telephone responder');

    it('refuses a shift that is already covered', function () {
        // Trusted's double models the UNIQUE(rota_id) constraint for real:
        // seeding an assignment against slot 10 is what makes assignIfOpen()
        // return null, rather than a flag saying so.
        $assignments = new InMemoryAssignmentRepository([
            100 => new Assignment(100, 10, '1', '', '2026-08-01 09:00:00'),
        ]);

        rotaAssignTool($assignments)->call(['rota_id' => 10, 'member_id' => 1]);
    })->throws(ToolException::class, 'already covered');

    it('reports an unknown slot', function () {
        rotaAssignTool(new InMemoryAssignmentRepository())->call(['rota_id' => 999, 'member_id' => 1]);
    })->throws(ToolException::class, 'No rota slot with id 999.');

    it('reports an unknown member', function () {
        rotaAssignTool(new InMemoryAssignmentRepository())->call(['rota_id' => 11, 'member_id' => 42]);
    })->throws(ToolException::class, 'No member with id 42.');

    it('removes the assignment on unassign and says what went', function () {
        $ann = new TrustedMember('1', 'Ann B', 'ann@example.com', '07700900123');
        $assignments = new InMemoryAssignmentRepository([
            100 => new Assignment(100, 10, '1', 'regular', '2026-08-01 09:00:00', $ann),
        ]);

        $result = (new UnassignTool($assignments, rotaToolsPresenter()))->call(['assignment_id' => 100]);

        expect($result['unassigned'])->toBeTrue()
            // Captured before the delete — afterwards there is nothing left to
            // describe, and naming the person beats "removed assignment 100".
            ->and($result['removed']['member']['name'])->toBe('Ann B')
            ->and($assignments->find(100))->toBeNull();
    });

    it('reports an unknown assignment on unassign and says which id it wants', function () {
        (new UnassignTool(new InMemoryAssignmentRepository(), rotaToolsPresenter()))->call(['assignment_id' => 55]);
    })->throws(ToolException::class, 'not a rota slot id');

    it('has the write tools declare themselves as not read-only', function () {
        // Surfaced to clients as readOnlyHint, which is what lets a host
        // decide a call needs confirming.
        expect(rotaAssignTool(new InMemoryAssignmentRepository())->isReadOnly())->toBeFalse()
            ->and((new UnassignTool(new InMemoryAssignmentRepository(), rotaToolsPresenter()))->isReadOnly())->toBeFalse();
    });
});
