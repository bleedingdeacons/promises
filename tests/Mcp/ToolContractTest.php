<?php

declare(strict_types=1);

namespace Promises\Tests\Mcp;

use Promises\Mcp\Tool;
use Promises\Mcp\ToolRegistry;
use Promises\Settings\Settings;
use Promises\Support\Presenter;
use Promises\Support\RotaPresenter;
use Trusted\Testing\Doubles\InMemoryAssignmentRepository;
use Trusted\Testing\Doubles\InMemoryRotaRepository;
use Unity\Testing\Doubles\InMemoryGroupRepository;
use Unity\Testing\Doubles\InMemoryMeetingRepository;
use Unity\Testing\Doubles\InMemoryPositionRepository;
use Promises\Tools\Trusted\AssignMemberTool;
use Promises\Tools\Trusted\GetDayTool;
use Promises\Tools\Trusted\GetWeekTool;
use Promises\Tools\Trusted\UnassignTool;
use Promises\Tools\Unity\GetGroupTool;
use Promises\Tools\Unity\GetMeetingTool;
use Promises\Tools\Unity\GetMemberTool;
use Promises\Tools\Unity\GetPositionTool;
use Promises\Tools\Unity\ListGroupsTool;
use Promises\Tools\Unity\ListMeetingsTool;
use Promises\Tools\Unity\ListMembersTool;
use Promises\Tools\Unity\ListPositionsTool;
use Unity\Testing\Doubles\InMemoryMemberRepository;

/*
 * What every tool promises about itself.
 *
 * The metadata methods — name, title, description, inputSchema — are the
 * tools' entire contract with the model: they are what tools/list publishes,
 * and a model that never reads a good description will never call the tool
 * correctly. Nothing else in the suite exercises them, because the other
 * tests call call() directly.
 *
 * So this walks the whole set once and asserts the invariants that would
 * otherwise only surface as a model behaving oddly in production: a duplicate
 * name shadowing another tool, a schema that forgets to declare its required
 * arguments, a description too thin to choose from.
 */

/**
 * Every tool the plugin can register, built with doubles.
 *
 * Deliberately assembled by hand rather than through
 * PromisesServiceProvider: the provider's job is deciding *which* tools a
 * given site gets, and this test is about all of them regardless.
 *
 * @return list<Tool>
 */
function allContractTools(): array
{
    $settings = new Settings();
    $presenter = new Presenter($settings);
    $rotaPresenter = new RotaPresenter($settings);

    $members = new InMemoryMemberRepository();
    $groups = new InMemoryGroupRepository();
    $meetings = new InMemoryMeetingRepository();
    $positions = new InMemoryPositionRepository();
    $rota = new InMemoryRotaRepository();
    $assignments = new InMemoryAssignmentRepository();

    return [
        new ListMembersTool($members, $presenter),
        new GetMemberTool($members, $presenter),
        new ListGroupsTool($groups, $presenter),
        new GetGroupTool($groups, $presenter),
        new ListMeetingsTool($meetings, $presenter),
        new GetMeetingTool($meetings, $presenter),
        new ListPositionsTool($positions, $presenter),
        new GetPositionTool($positions, $presenter),
        new GetWeekTool($rota, $rotaPresenter),
        new GetDayTool($rota, $rotaPresenter),
        new AssignMemberTool($rota, $assignments, $members, $rotaPresenter),
        new UnassignTool($assignments, $rotaPresenter),
    ];
}

beforeEach(function () {
    if (!PROMISES_TESTS_HAVE_TRUSTED) {
        $this->markTestSkipped('Trusted is not checked out alongside this plugin.');
    }
});

it('names every tool for the plugin its data comes from', function () {
    foreach (allContractTools() as $tool) {
        // Prefixed by source plugin, not by "promises", so a model reading
        // a mixed tool list can tell Unity's data from Trusted's.
        expect($tool->name())->toMatch(
            '/^(unity|trusted)_[a-z_]+$/',
            $tool::class . ' has a name that does not identify its source plugin.'
        );
    }
});

it('keeps tool names unique', function () {
    $names = array_map(static fn (Tool $tool): string => $tool->name(), allContractTools());

    // A duplicate would not error — ToolRegistry keys by name, so the
    // second registration would silently replace the first.
    expect(array_unique($names))->toBe($names);
});

it('has every tool describe itself well enough to be chosen', function () {
    foreach (allContractTools() as $tool) {
        expect($tool->title())->not->toBe('', $tool->name() . ' has no title.');

        // A one-line description is not enough for a model to tell two
        // similar tools apart; these all say when to reach for them.
        expect(strlen($tool->description()))->toBeGreaterThan(
            60,
            $tool->name() . ' has a description too thin to choose from.'
        );
    }
});

it('makes every input schema a closed object', function () {
    foreach (allContractTools() as $tool) {
        $schema = $tool->inputSchema();

        expect($schema['type'])->toBe('object', $tool->name() . ' does not take an object.')
            ->and($schema)->toHaveKey('properties', message: $tool->name() . ' declares no properties.');
        // additionalProperties: false makes a mistyped argument name a
        // validation failure at the client rather than an argument
        // silently ignored here.
        expect($schema['additionalProperties'])->toBeFalse($tool->name() . ' accepts undeclared arguments.');
    }
});

it('declares every required argument as a property too', function () {
    foreach (allContractTools() as $tool) {
        $schema = $tool->inputSchema();

        foreach ($schema['required'] ?? [] as $required) {
            expect($schema['properties'])->toHaveKey(
                $required,
                message: $tool->name() . ' requires "' . $required . '" but never declares it.'
            );
        }
    }
});

it('types and describes every property', function () {
    foreach (allContractTools() as $tool) {
        foreach ($tool->inputSchema()['properties'] as $name => $property) {
            expect($property)
                ->toHaveKey('type', message: $tool->name() . '.' . $name . ' has no type.')
                ->toHaveKey(
                    'description',
                    message: $tool->name() . '.' . $name . ' has no description, so a model must guess what it means.'
                );
        }
    }
});

// Only the two rota-write tools may declare themselves writable. This is
// the assertion that would catch a read tool accidentally advertising
// itself as one a host should confirm — or, worse, a write tool
// advertising itself as safe.
it('leaves only the rota write tools not read-only', function () {
    $writable = array_values(array_map(
        static fn (Tool $tool): string => $tool->name(),
        array_filter(allContractTools(), static fn (Tool $tool): bool => !$tool->isReadOnly())
    ));

    expect($writable)->toBe(['trusted_assign_member', 'trusted_unassign']);
});

it('publishes registry annotations matching each tool', function () {
    $registry = new ToolRegistry();

    foreach (allContractTools() as $tool) {
        $registry->add($tool);
    }

    $described = $registry->describe();

    expect($described)->toHaveCount(12);

    foreach ($described as $entry) {
        $tool = $registry->get($entry['name']);

        expect($tool)->not->toBeNull()
            ->and($entry['annotations']['readOnlyHint'])->toBe($tool->isReadOnly())
            ->and($entry['annotations']['title'])->toBe($tool->title())
            // Nothing here deletes a member or a group, and an assignment is
            // re-creatable from the same arguments.
            ->and($entry['annotations']['destructiveHint'])->toBeFalse()
            ->and($entry['annotations']['openWorldHint'])->toBeFalse();
    }
});
