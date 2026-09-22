<?php

declare(strict_types=1);

namespace Promises\Tests\Admin;

use BleedingDeacons\WpMocks\WpState;
use Promises\Admin\SettingsPage;

/*
 * Where the settings screen hangs in the admin menu.
 *
 * The screen itself is excluded from coverage — it is form markup and
 * admin-post handlers that end in wp_safe_redirect() + exit — but its menu
 * registration is neither of those things, and it is the part that decides
 * whether an admin can find the API key at all. It moved out of Settings to a
 * top-level menu, so the shape is worth pinning: two calls against one slug,
 * because the second renames the child add_menu_page() creates rather than
 * adding a second item.
 */

it('registers a top-level Promises menu', function () {
    (new SettingsPage())->registerMenu();

    expect(WpState::$menus[0])
        ->type->toBe('menu')
        ->slug->toBe('promises')
        ->title->toBe('Promises')
        ->cap->toBe('manage_options');
});

it('makes the settings screen the first child of that menu', function () {
    (new SettingsPage())->registerMenu();

    expect(WpState::$menus)->toHaveCount(2);

    $submenu = WpState::$menus[1];

    expect($submenu)
        ->type->toBe('submenu')
        ->parent->toBe('promises')
        ->title->toBe('Settings')
        ->cap->toBe('manage_options');
});

// The submenu has to reuse the parent's slug. A different one would leave
// the auto-generated "Promises" child in place and add "Settings" beside
// it, so the menu would list the same screen twice.
it('reuses the parent slug for the child so it renames rather than duplicates', function () {
    (new SettingsPage())->registerMenu();

    expect(WpState::$menus[1]['slug'])->toBe(WpState::$menus[0]['slug']);
});
