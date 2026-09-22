<?php

declare(strict_types=1);

namespace Promises\Tests\Tools\Unity;

use Promises\Mcp\ToolException;
use Promises\Settings\Settings;
use Promises\Support\Presenter;
use Promises\Tools\Unity\GetMemberTool;
use Promises\Tools\Unity\ListMembersTool;
use Unity\Members\ResponderCertification;
use Unity\Testing\Doubles\InMemoryMemberRepository;
use Unity\Testing\Doubles\MemberStub;

/*
 * The member tools — filtering, paging, and the masking that matters most
 * here, since members are the one entity carrying personal contact details.
 *
 * Built on Unity's own MemberStub and InMemoryMemberRepository rather than
 * hand-rolled doubles, so a change to Unity's interface breaks this suite
 * loudly instead of leaving it asserting against a stale contract.
 */

function memberDirectory(): InMemoryMemberRepository
{
    return new InMemoryMemberRepository([
        new MemberStub(
            id: 1,
            anonymousName: 'Ann B',
            personalEmail: 'ann@example.com',
            mobileNumber: '07700900123',
            twelfthStepper: true,
            telephoneResponder: true,
            responderCertification: ResponderCertification::Certified,
            area: 'North'
        ),
        new MemberStub(
            id: 2,
            anonymousName: 'Bob C',
            personalEmail: 'bob@example.org',
            mobileNumber: '07700900456',
            twelfthStepper: false,
            telephoneResponder: false,
            area: 'South'
        ),
        new MemberStub(
            id: 3,
            anonymousName: 'Cara D',
            personalEmail: 'cara@example.net',
            twelfthStepper: true,
            telephoneResponder: false,
            area: 'North'
        ),
    ]);
}

function memberListTool(): ListMembersTool
{
    return new ListMembersTool(memberDirectory(), new Presenter(new Settings()));
}

function memberGetTool(): GetMemberTool
{
    return new GetMemberTool(memberDirectory(), new Presenter(new Settings()));
}

describe('list members', function () {
    it('lists every member by default', function () {
        $result = memberListTool()->call([]);

        expect($result['total'])->toBe(3)
            ->and($result['returned'])->toBe(3)
            ->and($result['has_more'])->toBeFalse();
    });

    it('masks contact details by default', function () {
        $result = memberListTool()->call(['search' => 'Ann']);

        $member = $result['records'][0];

        expect($member['personal_email'])->toBe('a__@e______.com')
            ->and($member['mobile_number'])->toEndWith('0123')
            ->and($member['mobile_number'])->not->toContain('07700900')
            // Stated explicitly so a model cannot mistake a masked value for a
            // real address.
            ->and($member['contact_details_masked'])->toBeTrue();
    });

    it('returns real contact details when masking is off', function () {
        (new Settings())->save(['mask_pii' => false]);

        $result = memberListTool()->call(['search' => 'Ann']);

        expect($result['records'][0]['personal_email'])->toBe('ann@example.com')
            ->and($result['records'][0]['contact_details_masked'])->toBeFalse();
    });

    it('filters to telephone responders', function () {
        $result = memberListTool()->call(['telephone_responders_only' => true]);

        expect($result['total'])->toBe(1)
            ->and($result['records'][0]['anonymous_name'])->toBe('Ann B')
            ->and($result['records'][0]['responder_certification'])->toBe('Certified');
    });

    it('filters to twelfth steppers', function () {
        $result = memberListTool()->call(['twelfth_steppers_only' => true]);

        expect($result['total'])->toBe(2);
    });

    it('filters by area case-insensitively', function () {
        $result = memberListTool()->call(['area' => 'north']);

        expect($result['total'])->toBe(2);
    });

    it('matches name and area on search', function () {
        expect(memberListTool()->call(['search' => 'cara'])['total'])->toBe(1)
            ->and(memberListTool()->call(['search' => 'South'])['total'])->toBe(1);
    });

    it('pages and reports that more remain', function () {
        $result = memberListTool()->call(['limit' => 2, 'offset' => 0]);

        expect($result['returned'])->toBe(2)
            ->and($result['total'])->toBe(3)
            ->and($result['has_more'])->toBeTrue();

        $second = memberListTool()->call(['limit' => 2, 'offset' => 2]);

        expect($second['returned'])->toBe(1)
            // has_more is computed from the total, so a page landing exactly on
            // the end does not invite a pointless extra call.
            ->and($second['has_more'])->toBeFalse();
    });

    // An over-large limit is clamped rather than rejected: the model has
    // guessed, not erred, and a first page plus an honest has_more is more
    // useful than an error it has to recover from.
    it('clamps an absurd limit silently', function () {
        $result = memberListTool()->call(['limit' => 100000]);

        expect($result['limit'])->toBe(200);
    });
});

describe('get member', function () {
    it('returns one member by id', function () {
        $result = memberGetTool()->call(['id' => 2]);

        expect($result['anonymous_name'])->toBe('Bob C');
    });

    it('accepts a numeric string id', function () {
        // A model that writes "id": "2" has expressed the same intent as one
        // that writes 2.
        expect(memberGetTool()->call(['id' => '2'])['anonymous_name'])->toBe('Bob C');
    });

    it('finds by exact email', function () {
        $result = memberGetTool()->call(['email' => 'cara@example.net']);

        expect($result['id'])->toBe(3);
    });

    it('reports a missing id as a tool error', function () {
        memberGetTool()->call(['id' => 99]);
    })->throws(ToolException::class, 'No member with id 99.');

    it('rejects a call with neither id nor email', function () {
        memberGetTool()->call([]);
    })->throws(ToolException::class);

    it('rejects a negative id', function () {
        memberGetTool()->call(['id' => -1]);
    })->throws(ToolException::class);
});
