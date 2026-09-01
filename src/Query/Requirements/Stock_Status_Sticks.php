<?php
/**
 * Requirement: a stock status WooCommerce will actually keep.
 *
 * @package CatalogOps\Query\Requirements
 */

namespace CatalogOps\Query\Requirements;

use CatalogOps\Query\Query_Scope;
use wpdb;

/**
 * When a product manages its own stock, WooCommerce does not store the stock
 * status it is given — it derives one on every save and overwrites whatever was
 * set. `WC_Product::validate_props()`, in essence:
 *
 *     Stock not managed                    the status given, left alone.
 *     Quantity above the notify threshold  instock.
 *     Otherwise, backorders allowed        onbackorder.
 *     Otherwise                            outofstock.
 *
 * The threshold is `woocommerce_notify_no_stock_amount` (normally 0, so "above"
 * means at least one in stock), and backorders count as allowed unless the object
 * explicitly stores `no`.
 *
 * So "set every product to In stock" quietly does nothing for the managed ones
 * sitting at zero — the classic silent no-op this rule exists to predict. An
 * object qualifies when it does not manage stock at all, or when the status
 * WooCommerce would derive is the one being set; the rest are omitted under
 * {@see \CatalogOps\Operations\Skip_Reason::STOCK_MANAGED}.
 *
 * Variations inherit. `get_manage_stock()` returns the string `'parent'` for a
 * variation whose own flag is off while its parent manages stock, which is
 * truthy — so the derivation runs and reads the *parent's* quantity and backorder
 * setting (`WC_Product_Variation::get_stock_quantity()`). Under the variation
 * scope the rule therefore falls back to `p.post_parent` for objects that do not
 * manage their own stock, which is what makes a bulk "all variations in stock"
 * edit report honestly instead of promising changes it cannot make (CONTEXT §4).
 *
 * This models a *literal* stock status; the value is normalised the way
 * `WC_Product::set_stock_status()` does, which falls back to `instock` for
 * anything it does not recognise.
 */
final class Stock_Status_Sticks implements Requirement {

	/**
	 * The statuses WooCommerce derives, in the order validate_props() tests them.
	 */
	private const IN_STOCK     = 'instock';
	private const OUT_OF_STOCK = 'outofstock';
	private const ON_BACKORDER = 'onbackorder';

	/**
	 * The normalised status being set.
	 *
	 * @var string
	 */
	private string $status;

	/**
	 * Build the requirement.
	 *
	 * @param string $status    The literal stock status being set.
	 * @param float  $threshold The no-stock notification amount WooCommerce compares
	 *                          the quantity against (`woocommerce_notify_no_stock_amount`,
	 *                          normally 0), already absolute.
	 * @param string $reason    Skip-reason code for objects that would refuse it.
	 */
	public function __construct(
		string $status,
		private readonly float $threshold,
		private readonly string $reason,
	) {
		$this->status = in_array( $status, array( self::IN_STOCK, self::OUT_OF_STOCK, self::ON_BACKORDER ), true )
			? $status
			: self::IN_STOCK;
	}

	/**
	 * The reason objects failing this requirement are omitted under.
	 */
	public function reason(): string {
		return $this->reason;
	}

	/**
	 * Objects whose saved stock status would end up as the one being set.
	 *
	 * @param wpdb        $wpdb  Database handle.
	 * @param Query_Scope $scope The object type being queried.
	 * @return array{0: string, 1: list<mixed>}
	 */
	public function sql( wpdb $wpdb, Query_Scope $scope ): array {
		list( $managed, $managed_args ) = $this->manages_stock( $wpdb, 'l.product_id' );
		list( $derived, $derived_args ) = $this->derives_status( $wpdb, 'l.product_id', $this->own_quantity_above_threshold() );

		if ( ! $scope->is_variation() ) {
			// Unmanaged: validate_props() returns before touching the status, so the
			// write sticks. Managed: only if the derived status is the one we set.
			return array(
				"( NOT {$managed} OR {$derived} )",
				array( ...$managed_args, ...$derived_args ),
			);
		}

		list( $parent_managed, $parent_managed_args ) = $this->manages_stock( $wpdb, 'p.post_parent' );
		list( $parent_derived, $parent_derived_args ) = $this->derives_status(
			$wpdb,
			'p.post_parent',
			$this->stored_quantity_above_threshold( $wpdb, 'p.post_parent' )
		);

		// A variation managing its own stock derives from its own quantity; one
		// that does not, but whose parent manages stock, derives from the parent's;
		// a variation under an unmanaged parent keeps whatever status it is given.
		$fragment = "(
			( {$managed} AND {$derived} )
			OR ( NOT {$managed} AND ( NOT {$parent_managed} OR {$parent_derived} ) )
		)";

		return array(
			$fragment,
			array(
				...$managed_args,
				...$derived_args,
				...$managed_args,
				...$parent_managed_args,
				...$parent_derived_args,
			),
		);
	}

	/**
	 * Whether the object has `manage_stock` on — `_manage_stock` is stored as
	 * `yes`/`no` and read back through `wc_string_to_bool()`.
	 *
	 * @param wpdb   $wpdb          Database handle.
	 * @param string $object_column Trusted column expression identifying the object.
	 * @return array{0: string, 1: list<mixed>}
	 */
	private function manages_stock( wpdb $wpdb, string $object_column ): array {
		$postmeta = $wpdb->postmeta;

		$fragment = "{$object_column} IN (
			SELECT pm.post_id FROM {$postmeta} pm
			WHERE pm.meta_key = '_manage_stock' AND pm.meta_value = 'yes'
		)";

		return array( $fragment, array() );
	}

	/**
	 * Whether the status WooCommerce derives for a managed object is the one being
	 * set: above the notification threshold means in stock, otherwise on backorder
	 * when backorders are allowed and out of stock when they are not.
	 *
	 * @param wpdb                             $wpdb          Database handle.
	 * @param string                           $object_column Trusted column expression identifying the object.
	 * @param array{0: string, 1: list<mixed>} $above        The "quantity above threshold"
	 *                                                       test for this object.
	 * @return array{0: string, 1: list<mixed>}
	 */
	private function derives_status( wpdb $wpdb, string $object_column, array $above ): array {
		list( $above_sql, $above_args ) = $above;

		if ( self::IN_STOCK === $this->status ) {
			return array( $above_sql, $above_args );
		}

		list( $backorders, $backorders_args ) = $this->backorders_allowed( $wpdb, $object_column );

		// Below the threshold the status turns on whether backorders are allowed.
		$fragment = self::ON_BACKORDER === $this->status
			? "( NOT {$above_sql} AND {$backorders} )"
			: "( NOT {$above_sql} AND NOT {$backorders} )";

		return array( $fragment, array( ...$above_args, ...$backorders_args ) );
	}

	/**
	 * Whether the queried object's own stock quantity is above the threshold, read
	 * off the meta-lookup row the query already joins.
	 *
	 * WooCommerce keeps `wc_product_meta_lookup.stock_quantity` in step with the
	 * `_stock` meta on every save, and the filter layer already compares against
	 * this column ({@see \CatalogOps\Query\Query_Engine} numeric conditions) — so
	 * this is the same number, without the postmeta subquery. On a 55k catalogue
	 * that is worth about a third of the whole preview's time. The column is NULL
	 * for an unmanaged object, which compares false, and the unmanaged case is
	 * already short-circuited before this test is reached.
	 *
	 * @return array{0: string, 1: list<mixed>}
	 */
	private function own_quantity_above_threshold(): array {
		return array( 'l.stock_quantity > %f', array( $this->threshold ) );
	}

	/**
	 * The same test for an object the query has no lookup row joined for — a
	 * variation's parent — read from `_stock` postmeta instead. A missing or empty
	 * value reads back as 0, which never clears a threshold of 0 or more, so
	 * absence of the row is absence of stock.
	 *
	 * @param wpdb   $wpdb          Database handle.
	 * @param string $object_column Trusted column expression identifying the object.
	 * @return array{0: string, 1: list<mixed>}
	 */
	private function stored_quantity_above_threshold( wpdb $wpdb, string $object_column ): array {
		$postmeta = $wpdb->postmeta;

		$fragment = "{$object_column} IN (
			SELECT pm.post_id FROM {$postmeta} pm
			WHERE pm.meta_key = '_stock' AND pm.meta_value <> ''
				AND CAST( pm.meta_value AS DECIMAL(20,4) ) > %f
		)";

		return array( $fragment, array( $this->threshold ) );
	}

	/**
	 * Whether backorders are allowed. WooCommerce's test is `'no' !== get_backorders()`,
	 * so only an explicitly stored `no` disallows them — an absent row reads back as
	 * null and counts as allowed. Expressed as NOT IN to match that exactly.
	 *
	 * @param wpdb   $wpdb          Database handle.
	 * @param string $object_column Trusted column expression identifying the object.
	 * @return array{0: string, 1: list<mixed>}
	 */
	private function backorders_allowed( wpdb $wpdb, string $object_column ): array {
		$postmeta = $wpdb->postmeta;

		$fragment = "{$object_column} NOT IN (
			SELECT pm.post_id FROM {$postmeta} pm
			WHERE pm.meta_key = '_backorders' AND pm.meta_value = 'no'
		)";

		return array( $fragment, array() );
	}
}
