<?php

declare(strict_types=1);

namespace Promises\Tests\Admin;

use BleedingDeacons\WpMocks\TestCase;
use BleedingDeacons\WpMocks\WpState;
use Promises\Admin\SettingsPage;

/**
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
final class SettingsPageTest extends TestCase
{
    public function test_it_registers_a_top_level_promises_menu(): void
    {
        (new SettingsPage())->registerMenu();

        $this->assertSame('menu', WpState::$menus[0]['type']);
        $this->assertSame('promises', WpState::$menus[0]['slug']);
        $this->assertSame('Promises', WpState::$menus[0]['title']);
        $this->assertSame('manage_options', WpState::$menus[0]['cap']);
    }

    public function test_the_settings_screen_is_the_first_child_of_that_menu(): void
    {
        (new SettingsPage())->registerMenu();

        $this->assertCount(2, WpState::$menus);

        $submenu = WpState::$menus[1];

        $this->assertSame('submenu', $submenu['type']);
        $this->assertSame('promises', $submenu['parent']);
        $this->assertSame('Settings', $submenu['title']);
        $this->assertSame('manage_options', $submenu['cap']);
    }

    /**
     * The submenu has to reuse the parent's slug. A different one would leave
     * the auto-generated "Promises" child in place and add "Settings" beside
     * it, so the menu would list the same screen twice.
     */
    public function test_the_child_reuses_the_parent_slug_so_it_renames_rather_than_duplicates(): void
    {
        (new SettingsPage())->registerMenu();

        $this->assertSame(WpState::$menus[0]['slug'], WpState::$menus[1]['slug']);
    }
}
