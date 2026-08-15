<?php

declare(strict_types=1);

namespace Promises\Tests\Mcp;

use BleedingDeacons\WpMocks\TestCase;
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

/**
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
final class ToolContractTest extends TestCase
{
    /**
     * Every tool the plugin can register, built with doubles.
     *
     * Deliberately assembled by hand rather than through
     * PromisesServiceProvider: the provider's job is deciding *which* tools a
     * given site gets, and this test is about all of them regardless.
     *
     * @return list<Tool>
     */
    private function allTools(): array
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

    protected function setUp(): void
    {
        parent::setUp();

        if (!PROMISES_TESTS_HAVE_TRUSTED) {
            $this->markTestSkipped('Trusted is not checked out alongside this plugin.');
        }
    }

    public function test_every_tool_is_named_for_the_plugin_its_data_comes_from(): void
    {
        foreach ($this->allTools() as $tool) {
            // Prefixed by source plugin, not by "promises", so a model reading
            // a mixed tool list can tell Unity's data from Trusted's.
            $this->assertMatchesRegularExpression(
                '/^(unity|trusted)_[a-z_]+$/',
                $tool->name(),
                $tool::class . ' has a name that does not identify its source plugin.'
            );
        }
    }

    public function test_tool_names_are_unique(): void
    {
        $names = array_map(static fn (Tool $tool): string => $tool->name(), $this->allTools());

        // A duplicate would not error — ToolRegistry keys by name, so the
        // second registration would silently replace the first.
        $this->assertSame($names, array_unique($names));
    }

    public function test_every_tool_describes_itself_well_enough_to_be_chosen(): void
    {
        foreach ($this->allTools() as $tool) {
            $this->assertNotSame('', $tool->title(), $tool->name() . ' has no title.');

            // A one-line description is not enough for a model to tell two
            // similar tools apart; these all say when to reach for them.
            $this->assertGreaterThan(
                60,
                strlen($tool->description()),
                $tool->name() . ' has a description too thin to choose from.'
            );
        }
    }

    public function test_every_input_schema_is_a_closed_object(): void
    {
        foreach ($this->allTools() as $tool) {
            $schema = $tool->inputSchema();

            $this->assertSame('object', $schema['type'], $tool->name() . ' does not take an object.');
            $this->assertArrayHasKey('properties', $schema, $tool->name() . ' declares no properties.');
            // additionalProperties: false makes a mistyped argument name a
            // validation failure at the client rather than an argument
            // silently ignored here.
            $this->assertFalse(
                $schema['additionalProperties'],
                $tool->name() . ' accepts undeclared arguments.'
            );
        }
    }

    public function test_every_declared_required_argument_is_also_a_declared_property(): void
    {
        foreach ($this->allTools() as $tool) {
            $schema = $tool->inputSchema();

            foreach ($schema['required'] ?? [] as $required) {
                $this->assertArrayHasKey(
                    $required,
                    $schema['properties'],
                    $tool->name() . ' requires "' . $required . '" but never declares it.'
                );
            }
        }
    }

    public function test_every_property_is_typed_and_described(): void
    {
        foreach ($this->allTools() as $tool) {
            foreach ($tool->inputSchema()['properties'] as $name => $property) {
                $this->assertArrayHasKey('type', $property, $tool->name() . '.' . $name . ' has no type.');
                $this->assertArrayHasKey(
                    'description',
                    $property,
                    $tool->name() . '.' . $name . ' has no description, so a model must guess what it means.'
                );
            }
        }
    }

    /**
     * Only the two rota-write tools may declare themselves writable. This is
     * the assertion that would catch a read tool accidentally advertising
     * itself as one a host should confirm — or, worse, a write tool
     * advertising itself as safe.
     */
    public function test_only_the_rota_write_tools_are_not_read_only(): void
    {
        $writable = array_values(array_map(
            static fn (Tool $tool): string => $tool->name(),
            array_filter($this->allTools(), static fn (Tool $tool): bool => !$tool->isReadOnly())
        ));

        $this->assertSame(['trusted_assign_member', 'trusted_unassign'], $writable);
    }

    public function test_the_registry_publishes_annotations_matching_each_tool(): void
    {
        $registry = new ToolRegistry();

        foreach ($this->allTools() as $tool) {
            $registry->add($tool);
        }

        $described = $registry->describe();

        $this->assertCount(12, $described);

        foreach ($described as $entry) {
            $tool = $registry->get($entry['name']);

            $this->assertNotNull($tool);
            $this->assertSame($tool->isReadOnly(), $entry['annotations']['readOnlyHint']);
            $this->assertSame($tool->title(), $entry['annotations']['title']);
            // Nothing here deletes a member or a group, and an assignment is
            // re-creatable from the same arguments.
            $this->assertFalse($entry['annotations']['destructiveHint']);
            $this->assertFalse($entry['annotations']['openWorldHint']);
        }
    }
}
