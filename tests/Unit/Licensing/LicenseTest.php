<?php
/**
 * Unit tests for the plan-gating policy.
 *
 * @package CatalogOps\Tests\Unit\Licensing
 */

namespace CatalogOps\Tests\Unit\Licensing;

use CatalogOps\Licensing\License;
use PHPUnit\Framework\TestCase;

/**
 * The policy the rest of the plugin gates on (CONTEXT §5): the free tier caps
 * object counts and withholds undo / formulas / scheduling / modules; a paid plan
 * lifts the cap and the features; only the Studio tier unlocks the M7 modules.
 *
 * @covers \CatalogOps\Licensing\License
 */
final class LicenseTest extends TestCase {

	public function test_free_tier_caps_objects_and_withholds_paid_features(): void {
		$free = License::free();

		$this->assertSame( 200, $free->max_objects_per_op() );
		$this->assertFalse( $free->can_undo() );
		$this->assertFalse( $free->can_use_formulas() );
		$this->assertFalse( $free->can_schedule() );
		$this->assertFalse( $free->has_module() );
		$this->assertFalse( $free->is_premium() );
	}

	public function test_unlimited_lifts_the_cap_and_grants_everything(): void {
		$unlimited = License::unlimited();

		$this->assertSame( PHP_INT_MAX, $unlimited->max_objects_per_op() );
		$this->assertTrue( $unlimited->can_undo() );
		$this->assertTrue( $unlimited->can_use_formulas() );
		$this->assertTrue( $unlimited->can_schedule() );
		$this->assertTrue( $unlimited->has_module() );
		$this->assertTrue( $unlimited->is_premium() );
	}

	/**
	 * A paid-but-module-less plan (Solo): every premium feature and no object cap,
	 * but the ACF/WPML modules stay behind the Studio boundary.
	 */
	public function test_paid_without_modules_gates_only_the_modules(): void {
		$solo = new License( true, false );

		$this->assertSame( PHP_INT_MAX, $solo->max_objects_per_op() );
		$this->assertTrue( $solo->can_undo() );
		$this->assertTrue( $solo->can_use_formulas() );
		$this->assertTrue( $solo->can_schedule() );
		$this->assertTrue( $solo->is_premium() );
		$this->assertFalse( $solo->has_module() );
	}

	public function test_free_object_cap_constant(): void {
		$this->assertSame( 200, License::FREE_MAX_OBJECTS );
	}
}
