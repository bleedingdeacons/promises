<?php

declare(strict_types=1);

namespace Promises\Core;

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

use Promises\Auth\ApiKeyManager;
use Promises\Http\McpController;
use Promises\Mcp\Server;
use Promises\Mcp\ToolRegistry;
use Promises\Settings\Settings;
use Promises\Support\Presenter;
use Promises\Support\RotaPresenter;
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
use Psr\Container\ContainerInterface;
use Unity\Core\Interfaces\Container;
use Unity\Groups\Interfaces\GroupRepository;
use Unity\Meetings\Interfaces\MeetingRepository;
use Unity\Members\Interfaces\MemberRepository;
use Unity\Positions\Interfaces\PositionRepository;

/**
 * Registers Promises' services into Unity's container.
 *
 * Wired on `unity/register_services` (see promises.php), following Trusted and
 * Amber. Promises has no container of its own.
 *
 * Everything here is a factory, and that matters more than usual. Unity ships
 * headless: its repositories are bound not by Unity but by a companion plugin
 * — tsml-for-unity, in this suite — on `unity/loaded`, the same hook Promises
 * boots on. Resolving a repository during registration would therefore be a
 * race against plugin order. Because these closures do not run until
 * McpController is resolved at `rest_api_init`, which is long after every
 * plugin has loaded, the question never arises.
 */
class PromisesServiceProvider
{
    /**
     * @param Container $container Unity's dependency container
     */
    public function register(Container $container): void
    {
        $container->register(Settings::class, static function (): Settings {
            return new Settings();
        });

        $container->register(ApiKeyManager::class, static function (ContainerInterface $c): ApiKeyManager {
            return new ApiKeyManager($c->get(Settings::class));
        });

        $container->register(Presenter::class, static function (ContainerInterface $c): Presenter {
            return new Presenter($c->get(Settings::class));
        });

        $container->register(ToolRegistry::class, function (ContainerInterface $c): ToolRegistry {
            return $this->buildRegistry($c);
        });

        $container->register(Server::class, static function (ContainerInterface $c): Server {
            return new Server($c->get(ToolRegistry::class));
        });

        $container->register(McpController::class, static function (ContainerInterface $c): McpController {
            return new McpController(
                $c->get(Server::class),
                $c->get(ApiKeyManager::class),
                $c->get(ToolRegistry::class)
            );
        });
    }

    /**
     * Decide which tools this site actually exposes.
     *
     * Everything is feature-detected rather than assumed, for two different
     * reasons. Unity's repositories are optional in the sense that a
     * misconfigured site genuinely may not have bound them — Unity itself only
     * warns about that at boot — so registering a tool whose repository is
     * absent would turn a diagnosable misconfiguration into a tool that throws
     * when a model calls it. Trusted is optional in the ordinary sense: it may
     * simply not be installed.
     *
     * The registry is the whole permission model: a tool that is not added
     * here is invisible to tools/list and unreachable through tools/call, so
     * there is no second check anywhere downstream.
     */
    private function buildRegistry(ContainerInterface $c): ToolRegistry
    {
        $registry = new ToolRegistry();

        /** @var Settings $settings */
        $settings = $c->get(Settings::class);
        /** @var Presenter $presenter */
        $presenter = $c->get(Presenter::class);

        if ($c->has(MemberRepository::class)) {
            /** @var MemberRepository $members */
            $members = $c->get(MemberRepository::class);
            $registry->add(new ListMembersTool($members, $presenter));
            $registry->add(new GetMemberTool($members, $presenter));
        }

        if ($c->has(GroupRepository::class)) {
            /** @var GroupRepository $groups */
            $groups = $c->get(GroupRepository::class);
            $registry->add(new ListGroupsTool($groups, $presenter));
            $registry->add(new GetGroupTool($groups, $presenter));
        }

        if ($c->has(MeetingRepository::class)) {
            /** @var MeetingRepository $meetings */
            $meetings = $c->get(MeetingRepository::class);
            $registry->add(new ListMeetingsTool($meetings, $presenter));
            $registry->add(new GetMeetingTool($meetings, $presenter));
        }

        if ($c->has(PositionRepository::class)) {
            /** @var PositionRepository $positions */
            $positions = $c->get(PositionRepository::class);
            $registry->add(new ListPositionsTool($positions, $presenter));
            $registry->add(new GetPositionTool($positions, $presenter));
        }

        $this->addRotaTools($registry, $c, $settings);

        return $registry;
    }

    /**
     * The Trusted rota tools, when Trusted is present.
     *
     * Guarded by interface_exists() before the container is asked anything.
     * The container check alone is not enough: `$c->has(...)` needs the
     * interface name as a string, and while that is harmless, everything
     * built inside this method type-hints Trusted's classes. Confirming the
     * contracts are loadable first keeps a site without Trusted from ever
     * touching a class that cannot resolve.
     */
    private function addRotaTools(
        ToolRegistry $registry,
        ContainerInterface $c,
        Settings $settings
    ): void {
        if (
            !interface_exists(\Trusted\Contracts\RotaRepositoryInterface::class)
            || !interface_exists(\Trusted\Contracts\AssignmentRepositoryInterface::class)
        ) {
            return;
        }

        if (
            !$c->has(\Trusted\Contracts\RotaRepositoryInterface::class)
            || !$c->has(\Trusted\Contracts\AssignmentRepositoryInterface::class)
        ) {
            return;
        }

        /** @var \Trusted\Contracts\RotaRepositoryInterface $rota */
        $rota = $c->get(\Trusted\Contracts\RotaRepositoryInterface::class);
        /** @var \Trusted\Contracts\AssignmentRepositoryInterface $assignments */
        $assignments = $c->get(\Trusted\Contracts\AssignmentRepositoryInterface::class);

        $rotaPresenter = new RotaPresenter($settings);

        $registry->add(new GetWeekTool($rota, $rotaPresenter));
        $registry->add(new GetDayTool($rota, $rotaPresenter));

        // Writes are opt-in and additionally need Unity's members, since
        // assigning validates that the person is a flagged responder.
        if (!$settings->allowRotaWrites() || !$c->has(MemberRepository::class)) {
            return;
        }

        /** @var MemberRepository $members */
        $members = $c->get(MemberRepository::class);

        $registry->add(new AssignMemberTool($rota, $assignments, $members, $rotaPresenter));
        $registry->add(new UnassignTool($assignments, $rotaPresenter));
    }
}
