<?php

declare(strict_types=1);

namespace Promises\Admin;

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

use Promises\Auth\ApiKeyManager;
use Promises\Settings\Settings;

/**
 * Promises → Settings.
 *
 * Four things an administrator needs: the endpoint URL to paste into a
 * client, a key to authenticate with, and the two switches that decide how
 * much this server gives away — whether contact details are masked, and
 * whether the rota can be written to.
 *
 * Lives under a top-level "Promises" menu rather than inside Settings. An MCP
 * server is a thing you administer — hand out a key, revoke it, decide what it
 * exposes — not a preference you set once, and the top-level entry is also
 * where any second screen would hang.
 *
 * Constructed outside Unity's container (promises.php registers it on
 * plugins_loaded) so the screen still loads when Unity is missing or
 * misconfigured. That is the case where an admin most needs to see something
 * other than a blank page, so the notice explaining it lives here too.
 */
class SettingsPage
{
    private const PAGE_SLUG = 'promises';
    private const GENERATE_ACTION = 'promises_generate_key';
    private const REVOKE_ACTION = 'promises_revoke_key';
    private const SAVE_ACTION = 'promises_save_settings';

    /**
     * A freshly minted key, held for exactly one page render.
     *
     * Deliberately a transient tied to the user rather than anything durable:
     * the plain key exists in memory once, is shown once, and if the admin
     * navigates away without copying it they generate another. Storing it to
     * make that friendlier would undo the reason it is hashed at all.
     */
    private const NEW_KEY_TRANSIENT = 'promises_new_key_';

    private Settings $settings;
    private ApiKeyManager $keys;

    public function __construct(?Settings $settings = null, ?ApiKeyManager $keys = null)
    {
        $this->settings = $settings ?? new Settings();
        $this->keys = $keys ?? new ApiKeyManager($this->settings);
    }

    public function register(): void
    {
        add_action('admin_menu', [$this, 'registerMenu']);
        add_action('admin_post_' . self::GENERATE_ACTION, [$this, 'handleGenerate']);
        add_action('admin_post_' . self::REVOKE_ACTION, [$this, 'handleRevoke']);
        add_action('admin_post_' . self::SAVE_ACTION, [$this, 'handleSave']);
    }

    public function registerMenu(): void
    {
        add_menu_page(
            __('Promises — MCP server', 'promises'),
            __('Promises', 'promises'),
            'manage_options',
            self::PAGE_SLUG,
            [$this, 'render'],
            'dashicons-rest-api',
            81
        );

        // add_menu_page() auto-creates a first child repeating the parent's
        // label. Registering the same slug again renames that child to
        // "Settings" rather than adding a second item, so the menu reads
        // Promises → Settings and has room for a sibling later.
        add_submenu_page(
            self::PAGE_SLUG,
            __('Promises — MCP server', 'promises'),
            __('Settings', 'promises'),
            'manage_options',
            self::PAGE_SLUG,
            [$this, 'render']
        );
    }

    public function render(): void
    {
        if (!current_user_can('manage_options')) {
            wp_die(esc_html__('You do not have permission to manage Promises.', 'promises'));
        }

        $settings = $this->settings->all();
        $endpoint = rest_url('promises/v1/mcp');
        $newKey = get_transient(self::NEW_KEY_TRANSIENT . get_current_user_id());

        if (is_string($newKey) && $newKey !== '') {
            delete_transient(self::NEW_KEY_TRANSIENT . get_current_user_id());
        } else {
            $newKey = '';
        }

        echo '<div class="wrap">';
        echo '<h1>' . esc_html__('Promises — MCP server', 'promises') . '</h1>';

        if (!did_action('unity/loaded')) {
            echo '<div class="notice notice-error"><p>';
            echo esc_html__(
                'Unity has not loaded, so the MCP endpoint is not registered. Promises reads all of its data through Unity; check that Unity is active and correctly configured.',
                'promises'
            );
            echo '</p></div>';
        }

        if ($newKey !== '') {
            echo '<div class="notice notice-success"><p><strong>'
                . esc_html__('Your new API key — copy it now, it will not be shown again:', 'promises')
                . '</strong></p><p><code style="user-select:all;font-size:14px;">'
                . esc_html($newKey)
                . '</code></p></div>';
        }

        echo '<h2>' . esc_html__('Endpoint', 'promises') . '</h2>';
        echo '<p>' . esc_html__('Point your MCP client at this URL and send the API key as a bearer token.', 'promises') . '</p>';
        echo '<p><code style="user-select:all;">' . esc_html($endpoint) . '</code></p>';

        echo '<h2>' . esc_html__('API key', 'promises') . '</h2>';

        if ($settings['api_key_hash'] === '') {
            echo '<p>' . esc_html__('No key has been generated. Until one is, the endpoint rejects every request.', 'promises') . '</p>';
        } else {
            echo '<p>'
                . sprintf(
                    /* translators: 1: the first characters of the key, 2: creation date */
                    esc_html__('Active key %1$s… created %2$s. Generating a new key immediately invalidates this one.', 'promises'),
                    '<code>' . esc_html($settings['api_key_prefix']) . '</code>',
                    esc_html($settings['api_key_created_at'])
                )
                . '</p>';
        }

        $this->renderActionForm(
            self::GENERATE_ACTION,
            $settings['api_key_hash'] === ''
                ? __('Generate API key', 'promises')
                : __('Regenerate API key', 'promises')
        );

        if ($settings['api_key_hash'] !== '') {
            $this->renderActionForm(self::REVOKE_ACTION, __('Revoke API key', 'promises'));
        }

        echo '<h2>' . esc_html__('What the server exposes', 'promises') . '</h2>';

        echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '">';
        wp_nonce_field(self::SAVE_ACTION);
        echo '<input type="hidden" name="action" value="' . esc_attr(self::SAVE_ACTION) . '" />';
        echo '<table class="form-table" role="presentation"><tbody>';

        echo '<tr><th scope="row">' . esc_html__('Mask contact details', 'promises') . '</th><td>';
        echo '<label><input type="checkbox" name="mask_pii" value="1" ' . checked($settings['mask_pii'], true, false) . ' /> ';
        echo esc_html__('Mask members\' personal email addresses and mobile numbers in tool output.', 'promises');
        echo '</label><p class="description">';
        echo esc_html__(
            'Recommended. Tool output is read by a language model and may be repeated into a transcript the client stores. Turn this off only if the client genuinely needs to contact members directly.',
            'promises'
        );
        echo '</p></td></tr>';

        echo '<tr><th scope="row">' . esc_html__('Allow rota changes', 'promises') . '</th><td>';
        echo '<label><input type="checkbox" name="allow_rota_writes" value="1" ' . checked($settings['allow_rota_writes'], true, false) . ' /> ';
        echo esc_html__('Let the MCP client assign and unassign telephone responders in Trusted.', 'promises');
        echo '</label><p class="description">';
        echo esc_html__(
            'Off by default. With this on, a client can change who answers the helpline. Reading the rota is unaffected either way, and the tools do not appear at all unless Trusted is active.',
            'promises'
        );
        echo '</p></td></tr>';

        echo '</tbody></table>';
        submit_button(__('Save settings', 'promises'));
        echo '</form>';

        echo '</div>';
    }

    public function handleGenerate(): void
    {
        $this->authorise(self::GENERATE_ACTION);

        $key = $this->keys->generate();

        // Held just long enough to survive the redirect back to the settings
        // screen, which is the only place it is ever displayed.
        set_transient(self::NEW_KEY_TRANSIENT . get_current_user_id(), $key, 60);

        $this->redirectBack();
    }

    public function handleRevoke(): void
    {
        $this->authorise(self::REVOKE_ACTION);

        $this->keys->revoke();

        $this->redirectBack();
    }

    public function handleSave(): void
    {
        $this->authorise(self::SAVE_ACTION);

        // Unchecked checkboxes are absent from the POST body rather than
        // present-and-false, so both are read as "is the key there at all"
        // and never as a value.
        $this->settings->save([
            'mask_pii' => isset($_POST['mask_pii']),
            'allow_rota_writes' => isset($_POST['allow_rota_writes']),
        ]);

        $this->redirectBack();
    }

    /**
     * Capability plus nonce, or stop.
     */
    private function authorise(string $action): void
    {
        if (!current_user_can('manage_options')) {
            wp_die(esc_html__('You do not have permission to manage Promises.', 'promises'));
        }

        check_admin_referer($action);
    }

    private function redirectBack(): void
    {
        // admin.php, not options-general.php: the screen is a top-level menu
        // page now, and the old URL 404s.
        wp_safe_redirect(admin_url('admin.php?page=' . self::PAGE_SLUG));
        exit;
    }

    private function renderActionForm(string $action, string $label): void
    {
        echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '" style="display:inline-block;margin-right:8px;">';
        wp_nonce_field($action);
        echo '<input type="hidden" name="action" value="' . esc_attr($action) . '" />';
        submit_button($label, 'secondary', 'submit', false);
        echo '</form>';
    }
}
