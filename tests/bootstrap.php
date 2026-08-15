<?php

declare(strict_types=1);

/**
 * PHPUnit bootstrap for Promises.
 *
 * The suite covers the parts that are pure PHP and carry the risk: the
 * JSON-RPC envelope and MCP dispatch, key verification, PII masking, and each
 * tool's argument handling and output shape. None of them need a WordPress
 * install — only the stub layer, plus Unity's interfaces and test doubles.
 *
 * Unity (and Trusted, for the rota tests) are loaded from the sibling
 * checkouts, which is the layout CI arranges — see the "Checkout Unity" and
 * "Checkout Trusted" steps in ci.yml — and the one a developer working across
 * the suite already has. Registering PSR-4 autoloaders over those trees,
 * rather than hand-copying the interfaces here, is deliberate: a copied
 * contract is kept in step by discipline alone, and Trusted's own bootstrap
 * records what that cost when the discipline lapsed.
 */

use BleedingDeacons\WpMocks\Bootstrap;
use BleedingDeacons\WpMocks\WpState;

require_once dirname(__DIR__) . '/vendor/autoload.php';

// Patchwork first, and nothing patchable before it. It rewrites functions as
// their defining file is included, so anything defined ahead of it can never
// be overridden per-test afterwards.
Bootstrap::loadPatchwork();

WpState::$pluginSlug = 'promises';

// Every Promises class opens with `if (!defined('ABSPATH')) { exit; }`, so the
// constant must exist before any of them is autoloaded or the file simply
// exits and the class is never declared.
if (!defined('ABSPATH')) {
    define('ABSPATH', dirname(__DIR__) . '/');
}

// The stub layer. 'rest' brings WP_REST_Request / WP_REST_Response, which the
// transport test drives directly; 'sentinel' defines wp_log() so HasLogger
// resolves a channel instead of no-opping, which is what lets the auth test
// assert that a rejection is recorded.
//
// 'acf' is deliberately absent: nothing in Promises touches ACF. It reads
// Unity's interfaces, and whether those are ACF-backed is Unity's business.
Bootstrap::load(['wordpress', 'rest', 'sentinel']);

// The constants promises.php would normally define. Set here because the
// plugin's main file is never included by the suite — it calls
// get_plugin_data() and registers hooks, neither of which belongs in a unit
// test.
if (!defined('PROMISES_VERSION')) {
    define('PROMISES_VERSION', '9.9.9-test');
}
if (!defined('PROMISES_OPTION_KEY')) {
    define('PROMISES_OPTION_KEY', 'promises_settings');
}
if (!defined('PROMISES_MCP_PROTOCOL_VERSION')) {
    define('PROMISES_MCP_PROTOCOL_VERSION', '2026-07-28');
}
if (!defined('PROMISES_PLUGIN_FILE')) {
    define('PROMISES_PLUGIN_FILE', dirname(__DIR__) . '/promises.php');
}

// ──────────────────────────────────────────────
//  Sibling plugins
//
//  Unity is required: its interfaces are type-hinted throughout, and its
//  Testing\Doubles (MemberStub, InMemoryMemberRepository, FakeContainer) are
//  what the tool tests are built on.
//
//  Trusted is optional here exactly as it is at runtime. Without it the rota
//  tests skip themselves, which keeps a bare two-repo checkout able to run
//  most of the suite — and, more usefully, exercises the same
//  interface_exists() condition the service provider relies on.
$promisesSibling = static function (string $prefix, string $dir): bool {
    $base = dirname(__DIR__, 2) . '/' . $dir . '/src/';

    if (!is_dir($base)) {
        return false;
    }

    spl_autoload_register(static function (string $class) use ($prefix, $base): void {
        if (strncmp($prefix, $class, strlen($prefix)) !== 0) {
            return;
        }

        $file = $base . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';

        if (is_file($file)) {
            require_once $file;
        }
    });

    return true;
};

if (!$promisesSibling('Unity\\', 'unity')) {
    fwrite(
        STDERR,
        "Promises' test suite needs Unity checked out alongside it (../unity).\n"
        . "CI arranges this; locally, clone bleedingdeacons/unity next to this plugin.\n"
    );
    exit(1);
}

// Recorded rather than asserted: the rota tests read it to decide whether to
// skip. Nothing else in the suite depends on Trusted being present.
define('PROMISES_TESTS_HAVE_TRUSTED', $promisesSibling('Trusted\\', 'trusted'));

// Nothing further to load. The entity stubs and in-memory repositories these
// tests build on — MemberStub, GroupStub, MeetingStub, LocationStub,
// PositionStub and the four InMemory*Repository classes — all ship from Unity
// under Unity\Testing\Doubles, and resolve through the autoloader registered
// above. Promises deliberately keeps no copies: a double for someone else's
// contract, kept in someone else's repo, is exactly the drift Unity started
// shipping its own doubles to prevent.
