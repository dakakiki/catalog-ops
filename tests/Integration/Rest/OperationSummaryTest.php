<?php
/**
 * What a run did, put back into words.
 *
 * @package CatalogOps\Tests\Integration\Rest
 */

namespace CatalogOps\Tests\Integration\Rest;

use CatalogOps\Rest\Operation_Summary;
use WP_UnitTestCase;

/**
 * @covers \CatalogOps\Rest\Operation_Summary
 */
final class OperationSummaryTest extends WP_UnitTestCase {

	/**
	 * The describer over the live handle.
	 *
	 * @var Operation_Summary
	 */
	private Operation_Summary $summary;

	/**
	 * Build the describer.
	 */
	public function set_up(): void {
		parent::set_up();

		global $wpdb;

		$this->summary = new Operation_Summary( $wpdb );
	}

	/**
	 * A filter of one condition and one action, in words.
	 */
	public function test_it_names_the_scope_the_field_the_operator_and_the_value(): void {
		$described = $this->summary->describe(
			array(
				'scope'      => 'product',
				'relation'   => 'AND',
				'conditions' => array(
					array(
						'field'    => 'stock_status',
						'operator' => '=',
						'value'    => 'instock',
					),
				),
			),
			array(
				array(
					'type'       => 'formula',
					'field'      => 'regular_price',
					'expression' => 'regular_price * 1.2',
				),
			)
		);

		$this->assertSame( 'Products', $described['scope'] );
		$this->assertSame( 'all of these', $described['relation'] );
		$this->assertSame( 'Stock status', $described['conditions'][0]['label'] );
		$this->assertSame( 'is', $described['conditions'][0]['operator'] );
		$this->assertSame( 'instock', $described['conditions'][0]['value'] );
		$this->assertSame( 'Regular price', $described['actions'][0]['label'] );
		$this->assertSame( 'calculated as regular_price * 1.2', $described['actions'][0]['change'] );
	}

	/**
	 * Term ids are the operand a taxonomy condition stores, and a list of numbers
	 * tells the reader nothing about what the run targeted.
	 */
	public function test_it_resolves_term_ids_to_names(): void {
		$one = (int) wp_insert_term( 'Summary Footwear', 'product_cat' )['term_id'];
		$two = (int) wp_insert_term( 'Summary Apparel', 'product_cat' )['term_id'];

		$described = $this->summary->describe(
			array(
				'conditions' => array(
					array(
						'field'    => 'category',
						'operator' => 'in',
						'value'    => array( $one, $two ),
					),
				),
			),
			array()
		);

		$this->assertSame( 'Category', $described['conditions'][0]['label'] );
		$this->assertSame( 'is any of', $described['conditions'][0]['operator'] );
		$this->assertSame( 'Summary Footwear, Summary Apparel', $described['conditions'][0]['value'] );
	}

	/**
	 * WordPress stores term names entity-encoded and React renders this as text,
	 * escaping it a second time — so "Home & Kitchen" reached the screen as
	 * "Home &amp; Kitchen". The results table had exactly this bug and a test pins
	 * the fix there; this reads the same column and needed the same decode.
	 */
	public function test_a_term_name_with_an_ampersand_reads_as_written(): void {
		global $wpdb;

		$term_id = (int) wp_insert_term( 'Summary Home and Kitchen', 'product_cat' )['term_id'];

		// Written as the encoded form a real catalogue holds, rather than trusting
		// wp_insert_term to encode it for us.
		$wpdb->update( $wpdb->terms, array( 'name' => 'Home &amp; Kitchen' ), array( 'term_id' => $term_id ) );
		clean_term_cache( $term_id, 'product_cat' );

		$described = $this->summary->describe(
			array(
				'conditions' => array(
					array(
						'field'    => 'category',
						'operator' => 'in',
						'value'    => array( $term_id ),
					),
				),
			),
			array()
		);

		$this->assertSame( 'Home & Kitchen', $described['conditions'][0]['value'] );
	}

	/**
	 * A term deleted since the run shows as its id rather than vanishing. The
	 * frozen filter still names it, and a condition missing from this list would
	 * make the summary describe a wider run than the one that happened — which is
	 * the single error this plugin is built to avoid.
	 */
	public function test_a_deleted_term_shows_as_its_id_rather_than_disappearing(): void {
		$described = $this->summary->describe(
			array(
				'conditions' => array(
					array(
						'field'    => 'brand',
						'operator' => 'in',
						'value'    => array( 999999 ),
					),
				),
			),
			array()
		);

		$this->assertCount( 1, $described['conditions'] );
		$this->assertSame( '#999999', $described['conditions'][0]['value'] );
	}

	/**
	 * Presence asks about the field, not about a value, and printing an empty
	 * string beside "has no value" reads as a value that failed to load.
	 */
	public function test_presence_carries_no_value(): void {
		$described = $this->summary->describe(
			array(
				'conditions' => array(
					array(
						'field'    => 'tag',
						'operator' => 'not_exists',
					),
				),
			),
			array()
		);

		$this->assertSame( 'has no value', $described['conditions'][0]['operator'] );
		$this->assertSame( '', $described['conditions'][0]['value'] );
	}

	/**
	 * The sign is the whole meaning of an adjustment: +2 and -2 are a rise and a
	 * cut, and "2" twice would describe two different runs identically.
	 */
	public function test_an_adjustment_keeps_its_sign(): void {
		$described = $this->summary->describe(
			array(),
			array(
				array(
					'type'   => 'adjust',
					'field'  => 'regular_price',
					'amount' => -2.5,
				),
			)
		);

		$this->assertSame( 'changed by -2.5', $described['actions'][0]['change'] );
	}

	/**
	 * An empty filter is every object in the scope, not nothing. The client says
	 * so in words; this has to hand it an empty list rather than fail.
	 */
	public function test_an_empty_filter_describes_as_no_conditions(): void {
		$described = $this->summary->describe( array(), array() );

		$this->assertSame( array(), $described['conditions'] );
		$this->assertSame( 'Products', $described['scope'] );
	}

	/**
	 * The variation scope is half of what a run targeted, and the two are the
	 * same filter over different objects.
	 */
	public function test_it_names_the_variation_scope(): void {
		$described = $this->summary->describe( array( 'scope' => 'variation' ), array() );

		$this->assertSame( 'Variations', $described['scope'] );
	}

	/**
	 * A meta key is shown as itself. There is no friendlier name to give it —
	 * the user typed it — and inventing one would hide which key ran.
	 */
	public function test_a_meta_field_is_named_by_its_key(): void {
		$described = $this->summary->describe(
			array(
				'conditions' => array(
					array(
						'field'    => 'meta:_wholesale_price',
						'operator' => '>',
						'value'    => 10,
					),
				),
			),
			array()
		);

		$this->assertSame( '_wholesale_price', $described['conditions'][0]['label'] );
		$this->assertSame( 'is more than', $described['conditions'][0]['operator'] );
	}
}
