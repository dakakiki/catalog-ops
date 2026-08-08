<?php
/**
 * Main plugin bootstrap and service wiring.
 *
 * @package CatalogOps
 */

namespace CatalogOps;

use CatalogOps\Container\Container;
use CatalogOps\Database\Schema;
use CatalogOps\Query\Query_Engine;

/**
 * The plugin's composition root: a single entry point that owns the service
 * container and boots the plugin once per request. Milestone service providers
 * (Database, Query, Operations, Admin, CLI) register themselves here as they land.
 */
final class Plugin {

	/**
	 * Single shared instance.
	 *
	 * @var Plugin|null
	 */
	private static ?Plugin $instance = null;

	/**
	 * Service container.
	 *
	 * @var Container
	 */
	private Container $container;

	/**
	 * Absolute path to the main plugin file.
	 *
	 * @var string
	 */
	private string $file;

	/**
	 * Whether boot() has already run.
	 *
	 * @var bool
	 */
	private bool $booted = false;

	/**
	 * Private: use {@see Plugin::instance()}.
	 *
	 * @param string $file Absolute path to the main plugin file.
	 */
	private function __construct( string $file ) {
		$this->file      = $file;
		$this->container = new Container();
	}

	/**
	 * Get (and on first call, create) the shared instance.
	 *
	 * @param string $file Absolute path to the main plugin file. Used only on
	 *                     the first call, when the instance is created.
	 */
	public static function instance( string $file = '' ): Plugin {
		if ( null === self::$instance ) {
			self::$instance = new self( $file );
		}

		return self::$instance;
	}

	/**
	 * Wire services and hand off to the rest of the plugin. Idempotent.
	 */
	public function boot(): void {
		if ( $this->booted ) {
			return;
		}

		$this->booted = true;

		$this->register_services();
		$this->register_cli();

		// Apply pending migrations after a plugin update (no reactivation needed).
		add_action( 'admin_init', array( $this, 'maybe_upgrade_database' ) );

		/**
		 * Fires once the plugin has wired its services and is ready.
		 *
		 * @param Plugin $plugin The booted plugin instance.
		 */
		do_action( 'catalogops_booted', $this );
	}

	/**
	 * Activation hook: ensure services are wired, then create/upgrade the
	 * schema. Registered in the main plugin file via register_activation_hook().
	 */
	public function activate(): void {
		$this->boot();
		$this->container->get( Schema::class )->install();
	}

	/**
	 * Bring the database schema up to date if a newer version ships. Hooked to
	 * admin_init; the version check short-circuits when nothing is pending.
	 */
	public function maybe_upgrade_database(): void {
		$this->container->get( Schema::class )->maybe_upgrade();
	}

	/**
	 * The service container.
	 */
	public function container(): Container {
		return $this->container;
	}

	/**
	 * Absolute path to the main plugin file.
	 */
	public function file(): string {
		return $this->file;
	}

	/**
	 * Current plugin version.
	 */
	public function version(): string {
		return CATALOGOPS_VERSION;
	}

	/**
	 * Register core bindings. Milestone providers extend this as they land.
	 */
	private function register_services(): void {
		$this->container->instance( self::class, $this );

		$this->container->singleton(
			Schema::class,
			static function (): Schema {
				global $wpdb;

				return new Schema( $wpdb );
			}
		);

		$this->container->singleton(
			Query_Engine::class,
			static function (): Query_Engine {
				global $wpdb;

				return new Query_Engine( $wpdb );
			}
		);

		// Operations, Providers, Admin, and CLI services register here as their
		// milestones are implemented.
	}

	/**
	 * Register WP-CLI commands. No-op outside a WP-CLI context.
	 */
	private function register_cli(): void {
		if ( ! defined( 'WP_CLI' ) || ! WP_CLI ) {
			return;
		}

		\WP_CLI::add_command( 'catalogops seed', new CLI\Seed_Command() );
	}
}
