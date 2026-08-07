<?php
/**
 * Integration tests for the `wp catalogops seed` command.
 *
 * Requires WooCommerce; skipped when it is not loaded in the test WordPress.
 *
 * @package CatalogOps\Tests\Integration\CLI
 */

namespace CatalogOps\Tests\Integration\CLI;

use CatalogOps\CLI\Seed_Command;
use WP_UnitTestCase;

/**
 * @covers \CatalogOps\CLI\Seed_Command
 */
final class SeedCommandTest extends WP_UnitTestCase {

	private Seed_Command $command;

	public function set_up(): void {
		parent::set_up();

		if ( ! function_exists( 'wc_get_product' ) ) {
			$this->markTestSkipped( 'WooCommerce is not available in the test environment.' );
		}

		$this->command = new Seed_Command();
	}

	public function tear_down(): void {
		// Undo any bulk-write flags the command may have left on if it threw.
		wp_suspend_cache_invalidation( false );
		$this->command->__invoke( array(), array( 'reset' => true ) );

		parent::tear_down();
	}

	public function test_seed_creates_the_requested_number_of_products(): void {
		$this->command->__invoke( array(), array( 'products' => '4' ) );

		$this->assertSame( 4, $this->seeded_count( 'product' ) );
	}

	public function test_variable_products_create_variations(): void {
		$this->command->__invoke( array(), array( 'products' => '3', 'variable' => '100' ) );

		$this->assertSame( 3, $this->seeded_count( 'product' ) );
		// Each variable product yields one variation per size (S, M, L, XL).
		$this->assertSame( 12, $this->seeded_count( 'product_variation' ) );
	}

	public function test_seeded_product_carries_price_category_and_meta(): void {
		$this->command->__invoke( array(), array( 'products' => '1', 'variable' => '0' ) );

		$ids     = $this->seeded_ids( 'product' );
		$product = wc_get_product( $ids[0] );

		$this->assertNotFalse( $product );
		$this->assertNotSame( '', $product->get_regular_price() );
		$this->assertNotEmpty( $product->get_category_ids() );
		$this->assertNotEmpty( wp_get_object_terms( $ids[0], 'pa_color', array( 'fields' => 'ids' ) ) );
		$this->assertNotEmpty( $product->get_meta( '_catalogops_brand' ) );
		$this->assertSame( CATALOGOPS_VERSION, $product->get_meta( '_catalogops_seeded' ) );
	}

	public function test_reset_removes_only_seeded_products(): void {
		$keeper = self::factory()->post->create( array( 'post_type' => 'product' ) );
		$this->command->__invoke( array(), array( 'products' => '3' ) );
		$this->assertSame( 3, $this->seeded_count( 'product' ) );

		$this->command->__invoke( array(), array( 'reset' => true ) );

		$this->assertSame( 0, $this->seeded_count( 'product' ) );
		$this->assertSame( 'product', get_post_type( $keeper ) );
	}

	/**
	 * @param string $post_type Product or product_variation.
	 * @return array<int, int>
	 */
	private function seeded_ids( string $post_type ): array {
		return array_map(
			'intval',
			get_posts(
				array(
					'post_type'      => $post_type,
					'post_status'    => 'any',
					'posts_per_page' => -1,
					'fields'         => 'ids',
					'meta_key'       => '_catalogops_seeded',
					'no_found_rows'  => true,
				)
			)
		);
	}

	private function seeded_count( string $post_type ): int {
		return count( $this->seeded_ids( $post_type ) );
	}
}
