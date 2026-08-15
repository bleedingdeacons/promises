<?php

declare(strict_types=1);

namespace Promises\Tests\Utils;

use BleedingDeacons\WpMocks\TestCase;
use Promises\Utils\Mask;

/**
 * PII masking.
 *
 * The property that matters is not the exact glyphs but that the original
 * value cannot be read back out, so these assert on what survives as well as
 * on the shape.
 */
final class MaskTest extends TestCase
{
    public function test_it_masks_an_email_keeping_first_characters_and_tld(): void
    {
        $this->assertSame('j___@e______.com', Mask::email('john@example.com'));
    }

    public function test_a_short_local_part_still_gets_at_least_two_underscores(): void
    {
        // Without the max(…, 2) floor a two-character local part would mask to
        // a single underscore, which leaks its length.
        $this->assertSame('a__@b__.uk', Mask::email('ab@bc.uk'));
    }

    public function test_it_leaves_a_value_that_is_not_an_address_alone(): void
    {
        $this->assertSame('', Mask::email(''));
        $this->assertSame('not-an-address', Mask::email('not-an-address'));
    }

    public function test_it_masks_a_phone_number_keeping_the_last_four_digits(): void
    {
        $this->assertSame('(***) ***-5309', Mask::phone('(555) 867-5309'));
    }

    public function test_it_preserves_non_digit_characters_in_place(): void
    {
        // 16 digits in, so 12 are masked and the trailing 4 survive; the plus
        // and both spaces stay exactly where they were.
        $this->assertSame('+** **** ******4567', Mask::phone('+44 7700 9001234567'));
    }

    public function test_a_number_with_four_or_fewer_digits_is_returned_intact(): void
    {
        // Nothing to hide: masking would be theatre and would break a genuine
        // short code.
        $this->assertSame('999', Mask::phone('999'));
    }

    public function test_an_empty_number_stays_empty(): void
    {
        $this->assertSame('', Mask::phone(''));
    }

    /**
     * The point of the exercise: no run of digits from the original survives
     * beyond the last four.
     */
    public function test_the_masked_number_does_not_contain_the_hidden_digits(): void
    {
        $masked = Mask::phone('07700900123');

        $this->assertStringNotContainsString('07700', $masked);
        $this->assertStringEndsWith('0123', $masked);
    }
}
