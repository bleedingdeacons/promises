<?php

declare(strict_types=1);

namespace Promises\Settings;

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Reads and writes the single wp_options row Promises owns.
 *
 * One row (PROMISES_OPTION_KEY), as Tamar and WhatsApp do, rather than an
 * option per field — it keeps uninstall to a single delete_option() and means
 * a partially-written settings form can never leave half the plugin
 * configured.
 *
 * Defaults are chosen so that a freshly activated Promises exposes nothing it
 * shouldn't: no key means the endpoint rejects everything, masking is on, and
 * the rota write tools are off.
 */
class Settings
{
    /**
     * The stored shape. Every getter below falls back to these, so a missing
     * or truncated option row reads as "safe defaults" rather than as null.
     *
     * @var array{
     *     api_key_hash: string,
     *     api_key_prefix: string,
     *     api_key_created_at: string,
     *     mask_pii: bool,
     *     allow_rota_writes: bool
     * }
     */
    private const DEFAULTS = [
        'api_key_hash' => '',
        'api_key_prefix' => '',
        'api_key_created_at' => '',
        // On by default: tool output goes to a language model, not just to the
        // client that holds the key. See Utils\Mask.
        'mask_pii' => true,
        // Off by default. Reading the rota is a low-stakes thing to get wrong;
        // reassigning a helpline shift is not, so an admin has to opt in.
        'allow_rota_writes' => false,
    ];

    /**
     * @return array{
     *     api_key_hash: string,
     *     api_key_prefix: string,
     *     api_key_created_at: string,
     *     mask_pii: bool,
     *     allow_rota_writes: bool
     * }
     */
    public function all(): array
    {
        $stored = get_option(PROMISES_OPTION_KEY, []);

        if (!is_array($stored)) {
            $stored = [];
        }

        return [
            'api_key_hash' => isset($stored['api_key_hash']) ? (string) $stored['api_key_hash'] : self::DEFAULTS['api_key_hash'],
            'api_key_prefix' => isset($stored['api_key_prefix']) ? (string) $stored['api_key_prefix'] : self::DEFAULTS['api_key_prefix'],
            'api_key_created_at' => isset($stored['api_key_created_at']) ? (string) $stored['api_key_created_at'] : self::DEFAULTS['api_key_created_at'],
            'mask_pii' => isset($stored['mask_pii']) ? (bool) $stored['mask_pii'] : self::DEFAULTS['mask_pii'],
            'allow_rota_writes' => isset($stored['allow_rota_writes']) ? (bool) $stored['allow_rota_writes'] : self::DEFAULTS['allow_rota_writes'],
        ];
    }

    /**
     * Merge the given keys into the stored row, leaving the rest alone.
     *
     * @param array<string, mixed> $values
     */
    public function save(array $values): bool
    {
        $merged = array_merge($this->all(), $values);

        return (bool) update_option(PROMISES_OPTION_KEY, $merged);
    }

    public function apiKeyHash(): string
    {
        return $this->all()['api_key_hash'];
    }

    public function apiKeyPrefix(): string
    {
        return $this->all()['api_key_prefix'];
    }

    public function apiKeyCreatedAt(): string
    {
        return $this->all()['api_key_created_at'];
    }

    public function hasApiKey(): bool
    {
        return $this->apiKeyHash() !== '';
    }

    public function maskPii(): bool
    {
        return $this->all()['mask_pii'];
    }

    public function allowRotaWrites(): bool
    {
        return $this->all()['allow_rota_writes'];
    }
}
