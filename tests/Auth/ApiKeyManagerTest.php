<?php

declare(strict_types=1);

namespace Promises\Tests\Auth;

use BleedingDeacons\WpMocks\TestCase;
use Promises\Auth\ApiKeyManager;
use Promises\Settings\Settings;

/**
 * API key issue and verification.
 *
 * Argon2id is deliberately slow, so this suite generates as few keys as it
 * can get away with — each generate() costs roughly a tenth of a second.
 */
final class ApiKeyManagerTest extends TestCase
{
    private function manager(): ApiKeyManager
    {
        return new ApiKeyManager(new Settings());
    }

    public function test_a_generated_key_verifies(): void
    {
        $manager = $this->manager();

        $key = $manager->generate();

        $this->assertStringStartsWith('prm_', $key);
        $this->assertTrue($manager->verify($key));
    }

    public function test_the_plain_key_is_never_stored(): void
    {
        $manager = $this->manager();
        $settings = new Settings();

        $key = $manager->generate();

        $stored = get_option(PROMISES_OPTION_KEY);

        // The hash is stored; the key itself must appear nowhere in the row.
        $this->assertStringNotContainsString($key, serialize($stored));
        $this->assertStringStartsWith('$argon2id$', $settings->apiKeyHash());
    }

    public function test_the_stored_prefix_identifies_the_key_without_revealing_it(): void
    {
        $manager = $this->manager();
        $settings = new Settings();

        $key = $manager->generate();

        $prefix = $settings->apiKeyPrefix();

        $this->assertSame(substr($key, 0, 12), $prefix);
        // Twelve characters of a 68-character key: enough for an admin to tell
        // two keys apart on screen, useless for reconstructing one.
        $this->assertLessThan(strlen($key) / 2, strlen($prefix));
    }

    public function test_a_wrong_key_does_not_verify(): void
    {
        $manager = $this->manager();

        $manager->generate();

        $this->assertFalse($manager->verify('prm_' . str_repeat('0', 64)));
    }

    /**
     * An unconfigured Promises is a closed door, not an open one.
     */
    public function test_nothing_verifies_when_no_key_is_configured(): void
    {
        $manager = $this->manager();

        $this->assertFalse($manager->verify(''));
        $this->assertFalse($manager->verify('prm_anything'));
    }

    public function test_an_empty_presented_key_is_rejected_even_when_one_is_configured(): void
    {
        $manager = $this->manager();

        $manager->generate();

        $this->assertFalse($manager->verify(''));
    }

    public function test_generating_again_invalidates_the_previous_key(): void
    {
        $manager = $this->manager();

        $first = $manager->generate();
        $second = $manager->generate();

        $this->assertNotSame($first, $second);
        $this->assertFalse($manager->verify($first));
        $this->assertTrue($manager->verify($second));
    }

    public function test_revoking_clears_the_key_and_its_metadata(): void
    {
        $manager = $this->manager();
        $settings = new Settings();

        $key = $manager->generate();
        $manager->revoke();

        $this->assertFalse($manager->verify($key));
        $this->assertFalse($settings->hasApiKey());
        $this->assertSame('', $settings->apiKeyPrefix());
        $this->assertSame('', $settings->apiKeyCreatedAt());
    }
}
