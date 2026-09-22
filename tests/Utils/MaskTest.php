<?php

declare(strict_types=1);

namespace Promises\Tests\Utils;

use Promises\Utils\Mask;

/*
 * PII masking.
 *
 * The property that matters is not the exact glyphs but that the original
 * value cannot be read back out, so these assert on what survives as well as
 * on the shape.
 */

describe('email', function () {
    it('masks an email keeping the first characters and the TLD', function () {
        expect(Mask::email('john@example.com'))->toBe('j___@e______.com');
    });

    it('still gives a short local part at least two underscores', function () {
        // Without the max(…, 2) floor a two-character local part would mask to
        // a single underscore, which leaks its length.
        expect(Mask::email('ab@bc.uk'))->toBe('a__@b__.uk');
    });

    it('leaves a value that is not an address alone', function () {
        expect(Mask::email(''))->toBe('')
            ->and(Mask::email('not-an-address'))->toBe('not-an-address');
    });
});

describe('phone', function () {
    it('masks a phone number keeping the last four digits', function () {
        expect(Mask::phone('(555) 867-5309'))->toBe('(***) ***-5309');
    });

    it('preserves non-digit characters in place', function () {
        // 16 digits in, so 12 are masked and the trailing 4 survive; the plus
        // and both spaces stay exactly where they were.
        expect(Mask::phone('+44 7700 9001234567'))->toBe('+** **** ******4567');
    });

    it('returns a number with four or fewer digits intact', function () {
        // Nothing to hide: masking would be theatre and would break a genuine
        // short code.
        expect(Mask::phone('999'))->toBe('999');
    });

    it('keeps an empty number empty', function () {
        expect(Mask::phone(''))->toBe('');
    });

    // The point of the exercise: no run of digits from the original survives
    // beyond the last four.
    it('does not contain the hidden digits in the masked number', function () {
        $masked = Mask::phone('07700900123');

        expect($masked)->not->toContain('07700')
            ->and($masked)->toEndWith('0123');
    });
});
