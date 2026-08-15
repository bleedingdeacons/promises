<?php

/**
 * Promises uninstall.
 *
 * Removes the single option row Promises owns — which holds the API key hash
 * and the two exposure switches — and nothing else. Every record the plugin
 * ever served belongs to Unity or Trusted, and uninstalling a read surface
 * must not take the data it read with it.
 *
 * Deleting the key hash is the point: an uninstall that left it behind would
 * mean reinstalling silently restored a working credential that whoever holds
 * it may still have.
 */

declare(strict_types=1);

// Only ever run by WordPress's uninstall routine.
if (!defined('WP_UNINSTALL_PLUGIN')) {
    exit;
}

if (!defined('ABSPATH')) {
    exit;
}

// Not PROMISES_OPTION_KEY: uninstall.php runs standalone, with the plugin's
// main file never loaded, so the constant does not exist here.
delete_option('promises_settings');

// The one-shot transients that hold a freshly generated key between the
// generate action and the settings screen that displays it. They expire on
// their own within the minute, but an uninstall should not leave a plaintext
// key sitting in the options table even briefly.
global $wpdb;

$wpdb->query(
    $wpdb->prepare(
        "DELETE FROM {$wpdb->options} WHERE option_name LIKE %s OR option_name LIKE %s",
        $wpdb->esc_like('_transient_promises_new_key_') . '%',
        $wpdb->esc_like('_transient_timeout_promises_new_key_') . '%'
    )
);
