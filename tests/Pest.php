<?php

declare(strict_types=1);

// Pest configuration.
//
// Every file in this suite ran on wp-mocks' TestCase as a PHPUnit class, and
// every one still does. Most of them reach the stub layer's options —
// Settings backs the API key, the masking switch and every presenter — and
// WpState is only reset between tests by that TestCase's setUp(). Without it
// one test's generated API key or saved setting leaks into the next. Brain
// Monkey's hook functions are likewise only defined inside it.
//
// There is no plain-PHPUnit half here, unlike Trusted and Scrutiny. So this
// list is load-bearing: a new test directory has to be named here, or its
// tests run without the stub lifecycle.

use BleedingDeacons\WpMocks\TestCase;

pest()->extend(TestCase::class)->in(
    'Admin',
    'Auth',
    'Http',
    'Mcp',
    'Tools',
    'Utils',
);
