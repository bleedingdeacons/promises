<?php

declare(strict_types=1);

namespace Promises\Tests\Auth;

use Promises\Auth\ApiKeyManager;
use Promises\Settings\Settings;

/*
 * API key issue and verification.
 *
 * Argon2id is deliberately slow, so this suite generates as few keys as it
 * can get away with — each generate() costs roughly a tenth of a second.
 */

beforeEach(function () {
    $this->manager = new ApiKeyManager(new Settings());
});

it('verifies a generated key', function () {
    $key = $this->manager->generate();

    expect($key)->toStartWith('prm_')
        ->and($this->manager->verify($key))->toBeTrue();
});

it('never stores the plain key', function () {
    $settings = new Settings();

    $key = $this->manager->generate();

    $stored = get_option(PROMISES_OPTION_KEY);

    // The hash is stored; the key itself must appear nowhere in the row.
    expect(serialize($stored))->not->toContain($key)
        ->and($settings->apiKeyHash())->toStartWith('$argon2id$');
});

it('stores a prefix that identifies the key without revealing it', function () {
    $settings = new Settings();

    $key = $this->manager->generate();

    $prefix = $settings->apiKeyPrefix();

    expect($prefix)->toBe(substr($key, 0, 12))
        // Twelve characters of a 68-character key: enough for an admin to tell
        // two keys apart on screen, useless for reconstructing one.
        ->and(strlen($prefix))->toBeLessThan(strlen($key) / 2);
});

it('does not verify a wrong key', function () {
    $this->manager->generate();

    expect($this->manager->verify('prm_' . str_repeat('0', 64)))->toBeFalse();
});

// An unconfigured Promises is a closed door, not an open one.
it('verifies nothing when no key is configured', function () {
    expect($this->manager->verify(''))->toBeFalse()
        ->and($this->manager->verify('prm_anything'))->toBeFalse();
});

it('rejects an empty presented key even when one is configured', function () {
    $this->manager->generate();

    expect($this->manager->verify(''))->toBeFalse();
});

it('invalidates the previous key when generating again', function () {
    $first = $this->manager->generate();
    $second = $this->manager->generate();

    expect($first)->not->toBe($second)
        ->and($this->manager->verify($first))->toBeFalse()
        ->and($this->manager->verify($second))->toBeTrue();
});

it('clears the key and its metadata on revoke', function () {
    $settings = new Settings();

    $key = $this->manager->generate();
    $this->manager->revoke();

    expect($this->manager->verify($key))->toBeFalse()
        ->and($settings->hasApiKey())->toBeFalse()
        ->and($settings->apiKeyPrefix())->toBe('')
        ->and($settings->apiKeyCreatedAt())->toBe('');
});
