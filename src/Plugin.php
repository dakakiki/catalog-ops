<?php
/**
 * Main plugin bootstrap and service wiring.
 *
 * @package CatalogOps
 */

namespace CatalogOps;

use CatalogOps\Admin\Admin_Page;
use CatalogOps\Container\Container;
use CatalogOps\Database\Schema;
use CatalogOps\Licensing\License;
use CatalogOps\Modules\Acf\Acf_Fields;
use CatalogOps\Modules\Acf\Acf_Filter_Provider;
use CatalogOps\Modules\Acf\Acf_Options_Controller;
use CatalogOps\Operations\Changes;
use CatalogOps\Operations\Chunk_Runner;
use CatalogOps\Operations\Fields\Core_Fields;
use CatalogOps\Operations\Fields\Field_Providers;
use CatalogOps\Operations\Fields\Meta_Fields;
use CatalogOps\Operations\Lock;
use CatalogOps\Operations\Notifier;
use CatalogOps\Operations\Operation_Service;
use CatalogOps\Operations\Operations;
use CatalogOps\Operations\Recovery;
use CatalogOps\Operations\Retention;
use CatalogOps\Operations\Schedule_Runner;
use CatalogOps\Operations\Schedules;
use CatalogOps\Operations\Scheduler;
use CatalogOps\Operations\Watchdog;
use CatalogOps\Operations\Write_Rules;
use CatalogOps\Query\Fields\Filter_Providers;
use CatalogOps\Query\Query_Engine;
use CatalogOps\Query\Saved_Filters;
use CatalogOps\Rest\Fields_Controller;
use CatalogOps\Rest\Operations_Controller;
use CatalogOps\Rest\Query_Controller;
use CatalogOps\Rest\Schedules_Controller;
use CatalogOps\Rest\Settings_Controller;

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

		// Load translations. On `init` (not earlier) to satisfy WP 6.7's just-in-time
		// loading guard; the .mo/.json files live under /languages (Domain Path).
		$languages_rel_path = dirname( plugin_basename( $this->file ) ) . '/languages';
		add_action(
			'init',
			static function () use ( $languages_rel_path ): void {
				load_plugin_textdomain( 'catalogops', false, $languages_rel_path );
			}
		);

		// Apply pending migrations after a plugin update (no reactivation needed).
		add_action( 'admin_init', array( $this, 'maybe_upgrade_database' ) );

		// Early, and on `init` rather than `admin_init`, because the requests most
		// certain to be arriving while a run is dying are the admin screen's own REST
		// polls — which `admin_init` never sees — and the cron request, which reaches
		// a site nobody is looking at. See Recovery for why this cannot be a
		// scheduled job.
		add_action( 'init', array( $this, 'maybe_recover_operation' ), 5 );

		add_action(
			'rest_api_init',
			function (): void {
				$this->container->get( Query_Controller::class )->register_routes();
				$this->container->get( Operations_Controller::class )->register_routes();
				$this->container->get( Settings_Controller::class )->register_routes();
				$this->container->get( Fields_Controller::class )->register_routes();
				$this->container->get( Schedules_Controller::class )->register_routes();

				// Only when ACF is here. The route is named by the ACF module's own
				// descriptors and by nothing else, so registering it on a site without
				// ACF would publish a path that can only ever answer an empty list.
				if ( class_exists( 'ACF' ) || function_exists( 'acf_get_field_groups' ) ) {
					$this->container->get( Acf_Options_Controller::class )->register_routes();
				}
			}
		);

		$this->register_operation_hooks();

		// Install the schema on any site created while the plugin is network active.
		add_action( 'wp_initialize_site', array( $this, 'on_new_site' ), 20 );

		if ( is_admin() ) {
			$admin_page = $this->container->get( Admin_Page::class );
			add_action( 'admin_menu', array( $admin_page, 'register_menu' ) );
			add_action( 'admin_enqueue_scripts', array( $admin_page, 'enqueue_assets' ) );
		}

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
	 *
	 * On a network-wide activation the schema is installed on every existing
	 * site; per-site tables (the plugin's data is per-site, keyed by $wpdb->prefix)
	 * are what a network of independent shops needs. A single-site activation, or
	 * a per-site activation within a network, installs only the current site.
	 *
	 * @param bool $network_wide Whether the plugin was network-activated.
	 */
	public function activate( bool $network_wide = false ): void {
		$this->boot();

		if ( $network_wide && is_multisite() ) {
			$this->install_on_all_sites();

			return;
		}

		$this->container->get( Schema::class )->install();
	}

	/**
	 * Install (or upgrade) the schema on a newly created site, but only when the
	 * plugin is network active — otherwise a new site that isn't running the
	 * plugin would get orphan tables. Hooked to wp_initialize_site.
	 *
	 * @param \WP_Site $new_site The site just created.
	 */
	public function on_new_site( \WP_Site $new_site ): void {
		$active = (array) get_site_option( 'active_sitewide_plugins', array() );

		if ( ! isset( $active[ plugin_basename( $this->file ) ] ) ) {
			return;
		}

		switch_to_blog( (int) $new_site->blog_id );

		try {
			$this->container->get( Schema::class )->install();
		} finally {
			restore_current_blog();
		}
	}

	/**
	 * Bring the database schema up to date if a newer version ships. Hooked to
	 * admin_init; the version check short-circuits when nothing is pending.
	 */
	public function maybe_upgrade_database(): void {
		$this->container->get( Schema::class )->maybe_upgrade();
	}

	/**
	 * Hand a run whose writer disappeared to a new one. Hooked to init; costs an
	 * integer comparison when nothing is running, which is nearly always.
	 */
	public function maybe_recover_operation(): void {
		$this->container->get( Recovery::class )->run();
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
	 * Install the schema on every site in the network. Used for a network-wide
	 * activation; the same schema is created per site because each site keeps its
	 * own catalog and operation history under its own table prefix.
	 */
	private function install_on_all_sites(): void {
		$schema = $this->container->get( Schema::class );

		foreach ( get_sites(
			array(
				'fields' => 'ids',
				'number' => 0,
			)
		) as $site_id ) {
			switch_to_blog( (int) $site_id );

			try {
				$schema->install();
			} finally {
				restore_current_blog();
			}
		}
	}

	/**
	 * Register core bindings. Milestone providers extend this as they land.
	 */
	private function register_services(): void {
		$this->container->instance( self::class, $this );

		$this->container->singleton(
			License::class,
			static fn(): License => License::resolve()
		);

		$this->container->singleton(
			Schema::class,
			static function (): Schema {
				global $wpdb;

				return new Schema( $wpdb );
			}
		);

		$this->container->singleton(
			Acf_Fields::class,
			static function (): Acf_Fields {
				global $wpdb;

				return new Acf_Fields( $wpdb );
			}
		);

		$this->container->singleton(
			Acf_Options_Controller::class,
			static fn( Container $container ): Acf_Options_Controller => new Acf_Options_Controller(
				$container->get( Acf_Fields::class )
			)
		);

		$this->container->singleton(
			Filter_Providers::class,
			static function ( Container $container ): Filter_Providers {
				global $wpdb;

				$providers = array();

				// Registered first-party, not through a hook. `catalogops_filter_providers`
				// is still unpublished, and publishing it is the point of no return for
				// the provider API — it freezes the interfaces, the enums and the named
				// constructors. A first-party module needs none of that: it can prove
				// the vocabulary carries a real plugin's storage while the surface is
				// still free to change.
				//
				// Gated on the class rather than on a plugin path, so a site loading ACF
				// from a theme or a must-use plugin is served too. `is_expressible()`
				// keeps the field list to what the clause path can actually answer, so
				// an ACF install with nothing but flexible content registers a provider
				// that offers no fields — which is the honest answer, not a bug.
				if ( class_exists( 'ACF' ) || function_exists( 'acf_get_field_groups' ) ) {
					$providers[] = new Acf_Filter_Provider( $wpdb, $container->get( Acf_Fields::class ) );
				}

				return new Filter_Providers( $container->get( License::class ), ...$providers );
			}
		);

		$this->container->singleton(
			Query_Engine::class,
			static function ( Container $container ): Query_Engine {
				global $wpdb;

				return new Query_Engine( $wpdb, $container->get( Filter_Providers::class ) );
			}
		);

		$this->container->singleton(
			Saved_Filters::class,
			static function ( Container $container ): Saved_Filters {
				global $wpdb;

				return new Saved_Filters( $wpdb, $container->get( Schema::class ) );
			}
		);

		$this->container->singleton(
			Query_Controller::class,
			static function ( Container $container ): Query_Controller {
				global $wpdb;

				return new Query_Controller( $container->get( Query_Engine::class ), $wpdb );
			}
		);

		$plugin_file = $this->file;
		$this->container->singleton(
			Admin_Page::class,
			static fn( Container $container ): Admin_Page => new Admin_Page(
				$plugin_file,
				$container->get( License::class )
			)
		);

		$this->register_operation_services();
	}

	/**
	 * Register the M2 write-engine services: field providers, the operations and
	 * changes repositories, the lock, the Action Scheduler wrapper, and the
	 * service/runner/watchdog that drive the pipeline.
	 */
	private function register_operation_services(): void {
		$this->container->singleton(
			Field_Providers::class,
			static function (): Field_Providers {
				$providers = array( new Core_Fields(), new Meta_Fields() );

				/**
				 * Filters the ordered list of field providers. M7 modules (ACF,
				 * WPML, brands) append their providers here.
				 *
				 * @param array $providers List of Field_Provider instances.
				 */
				$providers = apply_filters( 'catalogops_field_providers', $providers );

				return new Field_Providers( ...$providers );
			}
		);

		$this->container->singleton(
			Operations::class,
			static function ( Container $container ): Operations {
				global $wpdb;

				return new Operations( $wpdb, $container->get( Schema::class ) );
			}
		);

		$this->container->singleton(
			Changes::class,
			static function ( Container $container ): Changes {
				global $wpdb;

				return new Changes( $wpdb, $container->get( Schema::class ) );
			}
		);

		$this->container->singleton(
			Lock::class,
			static fn( Container $container ): Lock => new Lock( $container->get( Operations::class ) )
		);

		$this->container->singleton(
			Scheduler::class,
			static fn(): Scheduler => new Scheduler()
		);

		$this->container->singleton(
			Write_Rules::class,
			static fn(): Write_Rules => new Write_Rules()
		);

		$this->container->singleton(
			Operation_Service::class,
			static fn( Container $container ): Operation_Service => new Operation_Service(
				$container->get( Query_Engine::class ),
				$container->get( Operations::class ),
				$container->get( Changes::class ),
				$container->get( Field_Providers::class ),
				$container->get( Lock::class ),
				$container->get( Scheduler::class ),
				$container->get( License::class ),
				$container->get( Write_Rules::class ),
				$container->get( Schedules::class ),
				$container->get( Filter_Providers::class )
			)
		);

		$this->container->singleton(
			Recovery::class,
			static fn( Container $container ): Recovery => new Recovery(
				$container->get( Operations::class ),
				$container->get( Changes::class ),
				$container->get( Lock::class ),
				$container->get( Scheduler::class )
			)
		);

		$this->container->singleton(
			Chunk_Runner::class,
			static fn( Container $container ): Chunk_Runner => new Chunk_Runner(
				$container->get( Operations::class ),
				$container->get( Changes::class ),
				$container->get( Field_Providers::class ),
				$container->get( Scheduler::class ),
				$container->get( Lock::class ),
				$container->get( Write_Rules::class )
			)
		);

		$this->container->singleton(
			Watchdog::class,
			static fn( Container $container ): Watchdog => new Watchdog(
				$container->get( Operations::class ),
				$container->get( Lock::class )
			)
		);

		$this->container->singleton(
			Retention::class,
			static fn( Container $container ): Retention => new Retention(
				$container->get( Changes::class )
			)
		);

		$this->container->singleton(
			Operations_Controller::class,
			static function ( Container $container ): Operations_Controller {
				global $wpdb;

				return new Operations_Controller(
					$container->get( Operation_Service::class ),
					$container->get( Operations::class ),
					$container->get( Changes::class ),
					$wpdb,
					$container->get( License::class )
				);
			}
		);

		$this->container->singleton(
			Settings_Controller::class,
			static fn( Container $container ): Settings_Controller => new Settings_Controller(
				$container->get( Retention::class )
			)
		);

		$this->container->singleton(
			Fields_Controller::class,
			static function ( Container $container ): Fields_Controller {
				global $wpdb;

				return new Fields_Controller( $wpdb, $container->get( Filter_Providers::class ) );
			}
		);

		$this->container->singleton(
			Schedules::class,
			static function ( Container $container ): Schedules {
				global $wpdb;

				return new Schedules( $wpdb, $container->get( Schema::class ) );
			}
		);

		$this->container->singleton(
			Schedule_Runner::class,
			static fn( Container $container ): Schedule_Runner => new Schedule_Runner(
				$container->get( Schedules::class ),
				$container->get( Operation_Service::class ),
				$container->get( Operations::class )
			)
		);

		$this->container->singleton(
			Schedules_Controller::class,
			static fn( Container $container ): Schedules_Controller => new Schedules_Controller(
				$container->get( Schedules::class ),
				$container->get( Schedule_Runner::class ),
				$container->get( License::class )
			)
		);

		$this->container->singleton(
			Notifier::class,
			static fn( Container $container ): Notifier => new Notifier(
				$container->get( Operations::class ),
				$container->get( Changes::class ),
				$container->get( Schedules::class )
			)
		);
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

	/**
	 * Wire the Action Scheduler action hooks and ensure the recurring watchdog is
	 * scheduled. Registered on every request so the async worker (cron or CLI)
	 * finds the callbacks when it runs the queue.
	 */
	private function register_operation_hooks(): void {
		add_action(
			Scheduler::CHUNK_HOOK,
			function ( $op_id = 0, $batch = 0 ): void {
				$this->container->get( Chunk_Runner::class )->run( (int) $op_id, (int) $batch );
			},
			10,
			2
		);

		add_action(
			Scheduler::WATCHDOG_HOOK,
			function (): void {
				$this->container->get( Watchdog::class )->run();
			}
		);

		add_action(
			Scheduler::RETENTION_HOOK,
			function (): void {
				$this->container->get( Retention::class )->purge();
			}
		);

		add_action(
			Scheduler::SCHEDULES_HOOK,
			function (): void {
				$this->container->get( Schedule_Runner::class )->run_due();
			}
		);

		add_action(
			'catalogops_operation_completed',
			function ( $op_id = 0 ): void {
				$this->container->get( Notifier::class )->notify( (int) $op_id );
			}
		);

		// The two messages the plugin lacked. Until these, it announced only success:
		// a run that died and a schedule that stopped itself both went unmentioned,
		// which is exactly backwards for work nobody is sitting and watching.
		add_action(
			'catalogops_operation_failed',
			function ( $op_id = 0 ): void {
				$this->container->get( Notifier::class )->notify_failed( (int) $op_id );
			}
		);

		add_action(
			'catalogops_schedule_paused',
			function ( $schedule_id = 0, $error = null ): void {
				$this->container->get( Notifier::class )->notify_schedule_paused( (int) $schedule_id, $error );
			},
			10,
			2
		);

		/*
		 * Shorten the pause between queue runs while one of our operations is
		 * writing.
		 *
		 * Action Scheduler sleeps five seconds between chained queue runs, and on
		 * the 18.5k catalogue that is most of the gap between chunks: measured on a
		 * 1,855-product run, each chunk spent about eight seconds writing and about
		 * six seconds waiting, so roughly 43% of the wall clock was this pause plus
		 * a WordPress bootstrap. Five seconds exists to stop chained loopbacks
		 * hammering a shared host, which is a real concern for a queue that might
		 * run for hours — but a bulk edit the user is watching is a burst, not a
		 * background trickle, and it holds the write lock while it waits.
		 *
		 * So the sleep is only shortened while a CatalogOps operation is actually
		 * active. Any other queue work on the site — WooCommerce's own, another
		 * plugin's — sees the value untouched, because this returns $seconds
		 * unchanged the moment we have nothing running. A host that wants the full
		 * pause back can filter `catalogops_queue_sleep_seconds` to 5.
		 */
		add_filter(
			'action_scheduler_async_request_sleep_seconds',
			function ( $seconds ) {
				if ( null === $this->container->get( Operations::class )->active_excluding( 0 ) ) {
					return $seconds;
				}

				/**
				 * Filters the seconds Action Scheduler waits between queue runs
				 * while a CatalogOps operation is writing.
				 *
				 * @param int $seconds Pause between chained queue runs.
				 */
				return apply_filters( 'catalogops_queue_sleep_seconds', 1 );
			}
		);

		// Schedule the recurring watchdog, retention purge, and schedule
		// supervisor once Action Scheduler is ready.
		add_action(
			'action_scheduler_init',
			function (): void {
				$scheduler = $this->container->get( Scheduler::class );
				$scheduler->ensure_watchdog();
				$scheduler->ensure_retention();
				$scheduler->ensure_schedules();
			}
		);
	}
}
