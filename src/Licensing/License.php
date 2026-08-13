<?php
/**
 * The plan-gating facade over the Freemius SDK.
 *
 * @package CatalogOps\Licensing
 */

namespace CatalogOps\Licensing;

/**
 * Answers "may this site use feature X?" for the rest of the plugin, so no other
 * class has to know Freemius exists. Two capabilities are modelled:
 *
 *  - **premium** — any paid plan (Solo or Studio). Gates the object-per-operation
 *    cap and the undo / formulas / scheduling features (CONTEXT §5): the free tier
 *    gets safe bulk edits up to {@see FREE_MAX_OBJECTS} objects and nothing else.
 *  - **modules** — the Studio plan (and its bulk 5/25/∞ packages). Gates the M7
 *    ACF / WPML / brands field providers.
 *
 * When the Freemius SDK is not bundled (a source checkout, CI, or the test suite —
 * the `freemius/` directory and its keys ship only in the deployed build), the
 * license resolves to unlimited: the plugin is fully functional for development
 * and the 201-test suite runs without a licensing backend.
 */
final class License {

	/**
	 * Objects a free-tier site may touch in a single operation (CONTEXT §5).
	 */
	public const FREE_MAX_OBJECTS = 200;

	/**
	 * The Studio plan's unique name in Freemius — the module boundary.
	 */
	private const MODULE_PLAN = 'studio';

	/**
	 * Whether any paid plan is active (undo / formulas / scheduling / no cap).
	 *
	 * @var bool
	 */
	private bool $premium;

	/**
	 * Whether the Studio plan (modules) is active.
	 *
	 * @var bool
	 */
	private bool $modules;

	/**
	 * Build a license from its two resolved capabilities.
	 *
	 * @param bool $premium Whether a paid plan grants the premium features.
	 * @param bool $modules Whether the Studio plan grants the M7 modules.
	 */
	public function __construct( bool $premium, bool $modules ) {
		$this->premium = $premium;
		$this->modules = $modules;
	}

	/**
	 * A fully-unlocked license — used for development, CI, and tests, where no
	 * Freemius backend is present and nothing should be gated.
	 */
	public static function unlimited(): self {
		return new self( true, true );
	}

	/**
	 * A free-tier license — the gated defaults.
	 */
	public static function free(): self {
		return new self( false, false );
	}

	/**
	 * Resolve the current site's license from the bundled Freemius SDK, falling
	 * back to {@see unlimited()} when the SDK is absent (see the class docblock).
	 */
	public static function resolve(): self {
		if ( ! function_exists( 'cat_fs' ) ) {
			return self::unlimited();
		}

		$fs = cat_fs();

		// `can_use_premium_code()` stays true after expiry under the "Keep
		// features, only block updates and support" policy chosen for the plans,
		// so a lapsed customer keeps their features — only never-paid free sites
		// are gated.
		return new self(
			(bool) $fs->can_use_premium_code(),
			(bool) $fs->is_plan_or_trial( self::MODULE_PLAN )
		);
	}

	/**
	 * The most objects a single operation may target. Unbounded on a paid plan;
	 * {@see FREE_MAX_OBJECTS} on the free tier.
	 */
	public function max_objects_per_op(): int {
		return $this->premium ? PHP_INT_MAX : self::FREE_MAX_OBJECTS;
	}

	/**
	 * Whether undo / revert is available (paid only).
	 */
	public function can_undo(): bool {
		return $this->premium;
	}

	/**
	 * Whether formula actions are available (paid only).
	 */
	public function can_use_formulas(): bool {
		return $this->premium;
	}

	/**
	 * Whether scheduled and recurring operations are available (paid only).
	 */
	public function can_schedule(): bool {
		return $this->premium;
	}

	/**
	 * Whether the Studio-tier field-provider modules (ACF, WPML, brands) are
	 * available. The argument is accepted for forward compatibility with
	 * per-module gating; today every module rides the single Studio boundary.
	 *
	 * @param string $module Module identifier (unused today).
	 */
	public function has_module( string $module = '' ): bool {
		unset( $module );

		return $this->modules;
	}

	/**
	 * Whether any paid plan is active.
	 */
	public function is_premium(): bool {
		return $this->premium;
	}
}
