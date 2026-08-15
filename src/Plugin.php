<?php

declare(strict_types=1);

namespace Promises;

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

use Promises\Http\McpController;
use Promises\Logger\HasLogger;
use Unity\Core\Interfaces\Container;

/**
 * Boots Promises on top of Unity.
 *
 * Promises registers its services into Unity's container (see
 * Core\PromisesServiceProvider, wired on `unity/register_services`) and boots
 * once Unity is fully loaded. It has no container of its own, and resolves
 * every repository it reads from Unity's.
 *
 * Deliberately thin: the only thing that happens at boot is a REST route
 * being declared. Nothing is built until a request arrives — no tool is
 * constructed, no repository resolved, no option read — so a site running
 * Promises with no MCP client attached pays essentially nothing for it.
 */
final class Plugin
{
    use HasLogger;

    private static ?Plugin $instance = null;

    private ?Container $container = null;

    public static function instance(): self
    {
        return self::$instance ??= new self();
    }

    private function __construct()
    {
    }

    /**
     * Wire WordPress hooks. Called on `unity/loaded` with Unity's container.
     */
    public function boot(Container $container): void
    {
        $this->container = $container;

        load_plugin_textdomain('promises', false, dirname(plugin_basename(PROMISES_PLUGIN_FILE)) . '/languages');

        add_action('rest_api_init', function () use ($container): void {
            try {
                $container->get(McpController::class)->registerRoutes();
            } catch (\Throwable $e) {
                // Resolving the controller builds the whole tool registry,
                // which touches every repository the site has bound. If one of
                // those is broken, failing here would take down the entire
                // REST API for the site — every route, not just this plugin's
                // — because rest_api_init is shared. Log it and leave the
                // endpoint unregistered instead: Promises stops working and
                // nothing else notices.
                self::logCritical('Promises could not register its REST routes: ' . $e->getMessage(), [
                    'exception' => $e->getMessage(),
                    'trace' => $e->getTraceAsString(),
                ]);
            }
        });

        /**
         * Fires once Promises is loaded and its route is due to be registered.
         *
         * Present for symmetry with the rest of the suite — dependent plugins
         * gate on `<plugin>/loaded` — though nothing depends on Promises
         * today. Carries Unity's container, which is what a dependent would
         * need.
         *
         * @param Container $container Unity's dependency container.
         */
        do_action('promises/loaded', $container);
    }

    /**
     * Unity's container, available after boot().
     */
    public function container(): ?Container
    {
        return $this->container;
    }
}
