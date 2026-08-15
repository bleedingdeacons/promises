<?php

declare(strict_types=1);

namespace Promises\Utils;

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

/**
 * PII masking for MCP tool output.
 *
 * Promises hands member records to a language model, which is a wider audience
 * than a REST client with a key: the values may be quoted back into a chat
 * transcript, and from there into whatever the client logs. So personal email
 * addresses and mobile numbers are masked by default (see Settings::maskPii),
 * and an admin has to turn masking off deliberately.
 *
 * The conventions match Integrity\Utils\Mask so a member's masked email reads
 * the same whichever surface produced it:
 *
 *   Email:  underscores replace hidden characters   -> j___@e______.com
 *   Phone:  asterisks replace hidden digits, last 4 -> (***) ***-5309
 *
 * Unlike Integrity's copy there is no round-trip detection here, because
 * nothing masked by Promises is ever accepted back as input — every write tool
 * takes ids, dates and notes, never a contact detail. That is also why this is
 * a local copy rather than a shared package: the two files agree on output
 * today, but Integrity's has a second obligation (its masked values are
 * resubmittable) that this one must not inherit by accident.
 */
class Mask
{
    /**
     * Mask an email address.
     *
     * Preserves the first character of the local part, the first character of
     * the domain name, and the full TLD.
     *
     * @param string $email The plain email address
     * @return string The masked email; unchanged when empty or not an address
     */
    public static function email(string $email): string
    {
        if ($email === '' || !str_contains($email, '@')) {
            return $email;
        }

        [$local, $domain] = explode('@', $email, 2);

        $maskedLocal = mb_substr($local, 0, 1)
            . str_repeat('_', max(mb_strlen($local) - 1, 2));

        $domainParts = explode('.', $domain);
        $tld = array_pop($domainParts);
        $domainName = implode('.', $domainParts);

        $maskedDomain = mb_substr($domainName, 0, 1)
            . str_repeat('_', max(mb_strlen($domainName) - 1, 2))
            . '.' . $tld;

        return $maskedLocal . '@' . $maskedDomain;
    }

    /**
     * Mask a phone number, keeping the last four digits visible.
     *
     * Non-digit characters (dashes, spaces, parentheses) keep their positions.
     *
     * @param string $phone The plain phone number
     * @return string The masked phone number
     */
    public static function phone(string $phone): string
    {
        if ($phone === '') {
            return '';
        }

        // Counted with an explicit walk rather than preg_replace(), which
        // returns null on a PCRE failure — coalescing that to '' would report
        // zero digits and leave the whole number unmasked, which is the wrong
        // direction for a masking helper to fail in.
        $totalDigits = 0;
        for ($i = 0, $len = strlen($phone); $i < $len; $i++) {
            if (ctype_digit($phone[$i])) {
                $totalDigits++;
            }
        }

        $visibleCount = min(4, $totalDigits);
        $hideCount = $totalDigits - $visibleCount;

        $digitsSeen = 0;
        $result = '';

        for ($i = 0, $len = strlen($phone); $i < $len; $i++) {
            if (ctype_digit($phone[$i])) {
                $digitsSeen++;
                $result .= ($digitsSeen <= $hideCount) ? '*' : $phone[$i];
            } else {
                $result .= $phone[$i];
            }
        }

        return $result;
    }
}
