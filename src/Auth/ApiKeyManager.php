<?php

declare(strict_types=1);

namespace Promises\Auth;

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

use Promises\Settings\Settings;

/**
 * Issues and verifies the single API key that guards the MCP endpoint.
 *
 * Deliberately smaller than Integrity's ApiKeyManager, which carries a custom
 * table, per-key permissions, rate limits and IP allow-lists. Promises has one
 * key because it has one consumer shape — an MCP client configured once by the
 * admin who generated it. If that ever becomes several clients with different
 * reach, this grows into Integrity's shape rather than sprouting a second
 * half-measure.
 *
 * What it does keep from Integrity: Argon2id hashing (the plain key is shown
 * once and never stored) and timing equalisation on the miss path.
 */
class ApiKeyManager
{
    private const KEY_BYTES = 32;
    private const PREFIX_LENGTH = 12;

    public function __construct(private Settings $settings)
    {
    }

    /**
     * Mint a new key, store its hash, and return the plain text exactly once.
     *
     * Any previously issued key stops working the moment this returns —
     * there is only ever one hash on the option row, so generating is also
     * how you rotate.
     *
     * @return string The plain key. Not recoverable afterwards.
     */
    public function generate(): string
    {
        $key = 'prm_' . bin2hex(random_bytes(self::KEY_BYTES));

        $this->settings->save([
            'api_key_hash' => $this->hash($key),
            // Stored so the settings screen can show *which* key is active
            // without holding anything that would let it be reconstructed.
            'api_key_prefix' => substr($key, 0, self::PREFIX_LENGTH),
            'api_key_created_at' => gmdate('Y-m-d H:i:s'),
        ]);

        return $key;
    }

    /**
     * Forget the current key. The endpoint rejects everything afterwards.
     */
    public function revoke(): void
    {
        $this->settings->save([
            'api_key_hash' => '',
            'api_key_prefix' => '',
            'api_key_created_at' => '',
        ]);
    }

    public function hash(string $key): string
    {
        return password_hash($key, PASSWORD_ARGON2ID, [
            'memory_cost' => 65536,
            'time_cost' => 4,
            'threads' => 1,
        ]);
    }

    /**
     * Whether the presented key matches the stored hash.
     *
     * With no key configured this is false for every input, including the
     * empty string — an unconfigured Promises is a closed door, not an open
     * one.
     */
    public function verify(string $presented): bool
    {
        $hash = $this->settings->apiKeyHash();

        if ($hash === '' || $presented === '') {
            // Still spend the Argon2id cost. Without this, "no key configured"
            // and "wrong key" are separated by ~100ms, which tells an unauthed
            // caller whether the endpoint is live and worth attacking.
            $this->equaliseTiming();
            return false;
        }

        return password_verify($presented, $hash);
    }

    /**
     * Burn one password_verify() against a well-formed throwaway hash so the
     * reject path costs what the accept path costs.
     *
     * The hash is computed once per request with the same cost parameters as
     * hash(), so the two paths have the same profile rather than merely both
     * being "slow".
     */
    private function equaliseTiming(): void
    {
        static $dummy = null;

        if ($dummy === null) {
            $dummy = $this->hash('timing-equalisation-placeholder');
        }

        password_verify('timing-equalisation-placeholder', $dummy);
    }
}
