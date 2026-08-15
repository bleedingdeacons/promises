<?php

declare(strict_types=1);

namespace Promises\Tests\Tools\Unity;

use BleedingDeacons\WpMocks\TestCase;
use Promises\Mcp\ToolException;
use Promises\Settings\Settings;
use Promises\Support\Presenter;
use Promises\Tools\Unity\GetMemberTool;
use Promises\Tools\Unity\ListMembersTool;
use Unity\Members\ResponderCertification;
use Unity\Testing\Doubles\InMemoryMemberRepository;
use Unity\Testing\Doubles\MemberStub;

/**
 * The member tools — filtering, paging, and the masking that matters most
 * here, since members are the one entity carrying personal contact details.
 *
 * Built on Unity's own MemberStub and InMemoryMemberRepository rather than
 * hand-rolled doubles, so a change to Unity's interface breaks this suite
 * loudly instead of leaving it asserting against a stale contract.
 */
final class MemberToolsTest extends TestCase
{
    private function repository(): InMemoryMemberRepository
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

    private function listTool(): ListMembersTool
    {
        return new ListMembersTool($this->repository(), new Presenter(new Settings()));
    }

    private function getTool(): GetMemberTool
    {
        return new GetMemberTool($this->repository(), new Presenter(new Settings()));
    }

    public function test_it_lists_every_member_by_default(): void
    {
        $result = $this->listTool()->call([]);

        $this->assertSame(3, $result['total']);
        $this->assertSame(3, $result['returned']);
        $this->assertFalse($result['has_more']);
    }

    public function test_it_masks_contact_details_by_default(): void
    {
        $result = $this->listTool()->call(['search' => 'Ann']);

        $member = $result['records'][0];

        $this->assertSame('a__@e______.com', $member['personal_email']);
        $this->assertStringEndsWith('0123', $member['mobile_number']);
        $this->assertStringNotContainsString('07700900', $member['mobile_number']);
        // Stated explicitly so a model cannot mistake a masked value for a
        // real address.
        $this->assertTrue($member['contact_details_masked']);
    }

    public function test_it_returns_real_contact_details_when_masking_is_off(): void
    {
        (new Settings())->save(['mask_pii' => false]);

        $result = $this->listTool()->call(['search' => 'Ann']);

        $this->assertSame('ann@example.com', $result['records'][0]['personal_email']);
        $this->assertFalse($result['records'][0]['contact_details_masked']);
    }

    public function test_it_filters_to_telephone_responders(): void
    {
        $result = $this->listTool()->call(['telephone_responders_only' => true]);

        $this->assertSame(1, $result['total']);
        $this->assertSame('Ann B', $result['records'][0]['anonymous_name']);
        $this->assertSame('Certified', $result['records'][0]['responder_certification']);
    }

    public function test_it_filters_to_twelfth_steppers(): void
    {
        $result = $this->listTool()->call(['twelfth_steppers_only' => true]);

        $this->assertSame(2, $result['total']);
    }

    public function test_it_filters_by_area_case_insensitively(): void
    {
        $result = $this->listTool()->call(['area' => 'north']);

        $this->assertSame(2, $result['total']);
    }

    public function test_search_matches_name_and_area(): void
    {
        $this->assertSame(1, $this->listTool()->call(['search' => 'cara'])['total']);
        $this->assertSame(1, $this->listTool()->call(['search' => 'South'])['total']);
    }

    public function test_it_pages_and_reports_that_more_remain(): void
    {
        $result = $this->listTool()->call(['limit' => 2, 'offset' => 0]);

        $this->assertSame(2, $result['returned']);
        $this->assertSame(3, $result['total']);
        $this->assertTrue($result['has_more']);

        $second = $this->listTool()->call(['limit' => 2, 'offset' => 2]);

        $this->assertSame(1, $second['returned']);
        // has_more is computed from the total, so a page landing exactly on
        // the end does not invite a pointless extra call.
        $this->assertFalse($second['has_more']);
    }

    /**
     * An over-large limit is clamped rather than rejected: the model has
     * guessed, not erred, and a first page plus an honest has_more is more
     * useful than an error it has to recover from.
     */
    public function test_an_absurd_limit_is_clamped_silently(): void
    {
        $result = $this->listTool()->call(['limit' => 100000]);

        $this->assertSame(200, $result['limit']);
    }

    public function test_get_member_returns_one_member_by_id(): void
    {
        $result = $this->getTool()->call(['id' => 2]);

        $this->assertSame('Bob C', $result['anonymous_name']);
    }

    public function test_get_member_accepts_a_numeric_string_id(): void
    {
        // A model that writes "id": "2" has expressed the same intent as one
        // that writes 2.
        $this->assertSame('Bob C', $this->getTool()->call(['id' => '2'])['anonymous_name']);
    }

    public function test_get_member_finds_by_exact_email(): void
    {
        $result = $this->getTool()->call(['email' => 'cara@example.net']);

        $this->assertSame(3, $result['id']);
    }

    public function test_get_member_reports_a_missing_id_as_a_tool_error(): void
    {
        $this->expectException(ToolException::class);
        $this->expectExceptionMessage('No member with id 99.');

        $this->getTool()->call(['id' => 99]);
    }

    public function test_get_member_rejects_a_call_with_neither_id_nor_email(): void
    {
        $this->expectException(ToolException::class);

        $this->getTool()->call([]);
    }

    public function test_get_member_rejects_a_negative_id(): void
    {
        $this->expectException(ToolException::class);

        $this->getTool()->call(['id' => -1]);
    }
}
