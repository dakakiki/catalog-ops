<?php
/**
 * What WooCommerce will and will not keep, expressed once for both halves of the
 * pipeline.
 *
 * @package CatalogOps\Operations
 */

namespace CatalogOps\Operations;

use CatalogOps\Operations\Actions\Action;
use CatalogOps\Operations\Actions\Set_Value;
use CatalogOps\Query\Requirements\Meta_Present;
use CatalogOps\Query\Requirements\Not;
use CatalogOps\Query\Requirements\Requirement;
use CatalogOps\Query\Requirements\Sale_Price_Cleared;
use CatalogOps\Query\Requirements\Sale_Price_Sticks;
use CatalogOps\Query\Requirements\Stock_Status_Sticks;
use WC_Product;

/**
 * A bulk edit is only honest if the number it promises is the number it delivers.
 * Two things break that promise: an input the change reads is missing, and a value
 * WooCommerce refuses on save. This class holds both sets of rules, and hands them
 * to the two places that need them:
 *
 *   - {@see requirements()} renders them as SQL. The preview counts how many
 *     objects each rule excludes, and {@see Operation_Service::queue()} freezes
 *     only the survivors — so an inapplicable object is never queued, never
 *     counted, and never shows up as a bare "skipped" afterwards.
 *   - {@see explain()} answers the same question about an object already written,
 *     for the runner's post-save read-back. With the SQL rules in place this is a
 *     safety net rather than the main path: it catches formula results that cannot
 *     be predicted up front, and values another plugin overrode.
 *
 * Keeping both in one file is deliberate. These rules mirror WooCommerce internals
 * (`WC_Product::validate_props()` and the CPT data store's `handle_updated_props()`),
 * and the two halves must agree or the preview starts lying again.
 */
final class Write_Rules {

	/**
	 * Warning code: the new regular price sits at or below sale prices the objects
	 * already carry, so saving it will clear them.
	 */
	public const SALE_PRICE_CLEARED = 'sale_price_cleared';

	/**
	 * Warning code: objects left out of a sale-price edit that already carry a sale
	 * price — the ones the applicability rule just saved from being wiped. Not a
	 * hazard but an explanation, and usually the number the user is looking at when
	 * they ask why "nothing will change" for products that plainly have sale prices.
	 */
	public const SALE_PRICE_PROTECTED = 'sale_price_protected';

	/**
	 * The fields that hold money and therefore cannot go below zero.
	 *
	 * This is our rule, not WooCommerce's — and it is here because WooCommerce
	 * does not have one. `set_regular_price()` stores whatever it is handed, so a
	 * formula that turns negative for some rows (`regular_price - 10` on anything
	 * cheaper than ten) would write negative prices and nothing further down would
	 * object. A negative price is never what someone meant.
	 *
	 * Only the two core price fields. Stock is deliberately absent: WooCommerce
	 * uses negative quantities for backorders, so a negative there is meaningful.
	 * A `meta:` field carries no semantics we can read, so it is left alone.
	 *
	 * @var list<string>
	 */
	private const MONEY_FIELDS = array( 'regular_price', 'sale_price' );

	/**
	 * The postmeta key each readable core field is stored under. A `meta:` field
	 * lives under its bare key; anything else imposes no constraint (its presence
	 * cannot be tested here), erring toward including.
	 *
	 * @var array<string, string>
	 */
	private const READ_META_KEYS = array(
		'regular_price'  => '_regular_price',
		'sale_price'     => '_sale_price',
		'stock_quantity' => '_stock',
		'weight'         => '_weight',
	);

	/**
	 * Whether this field would be refused the value given — today, a negative on a
	 * price. Asked in two places, which is the point of it living here: the
	 * service refuses an action whose value is negative whatever object it lands
	 * on, and the plan drops the per-object results that come out negative.
	 *
	 * A non-numeric value is nobody's business here; the field's own provider and
	 * the formula's null handling deal with those.
	 *
	 * @param string $field The field key being written.
	 * @param mixed  $value The value about to be written.
	 */
	public function refuses( string $field, mixed $value ): bool {
		if ( ! in_array( $field, self::MONEY_FIELDS, true ) ) {
			return false;
		}

		return is_numeric( $value ) && (float) $value < 0.0;
	}

	/**
	 * The applicability constraints a set of actions imposes: the objects that can
	 * actually take the change. Ordering is meaningful — the preview attributes each
	 * omitted object to the first requirement it fails, so the counts partition the
	 * omitted total exactly instead of double-counting overlaps.
	 *
	 * @param Action[] $actions The operation's actions.
	 * @return list<Requirement>
	 */
	public function requirements( array $actions ): array {
		$requirements = array();

		// Inputs first: an object missing a field the change reads cannot produce a
		// value at all, which subsumes any question of what WooCommerce would keep.
		foreach ( $this->read_meta_keys( $actions ) as $meta_key ) {
			$requirements[] = new Meta_Present( $meta_key, Skip_Reason::EMPTY_INPUT->value );
		}

		$regular_price = $this->literal_for( $actions, 'regular_price' );

		foreach ( $actions as $action ) {
			$value = $this->literal( $action );

			if ( null === $value ) {
				// Not a literal write: a formula's result depends on each object, so
				// it cannot be tested in SQL. The runner's read-back covers it.
				continue;
			}

			if ( 'sale_price' === $action->field() && '' !== $value && is_numeric( $value ) ) {
				$requirements[] = new Sale_Price_Sticks(
					(float) $value,
					Skip_Reason::SALE_NOT_BELOW_REGULAR->value,
					$regular_price
				);
			}

			if ( 'stock_status' === $action->field() ) {
				$requirements[] = new Stock_Status_Sticks(
					$value,
					$this->no_stock_threshold(),
					Skip_Reason::STOCK_MANAGED->value
				);
			}
		}

		return $requirements;
	}

	/**
	 * Things the preview should count and show but never subtract from the target
	 * list. Each entry is a code plus the predicates an object must satisfy to be
	 * counted under it (ANDed), so a warning can combine rules — including the
	 * inverse of an applicability rule.
	 *
	 * Both of today's warnings come from the same WooCommerce behaviour, seen from
	 * opposite sides:
	 *
	 *   - Writing a **regular** price re-tests the sale price, so lowering prices in
	 *     bulk deletes every sale price now at or above the new figure. The edit
	 *     itself applies perfectly well; the sale prices simply vanish, with no
	 *     change row of their own for undo to restore. That needs a number in front
	 *     of the user before they press Apply, not a discovery afterwards.
	 *   - Writing a **sale** price WooCommerce would refuse does the same damage, but
	 *     the applicability rule already excluded those objects. Counting how many of
	 *     them held a sale price turns "none of the 31 will change" — baffling when
	 *     you can see products with sale prices in the list — into "and 4 of them
	 *     would have lost the sale price they have".
	 *
	 * @param Action[] $actions The operation's actions.
	 * @return list<array{code: string, predicates: list<Requirement>}>
	 */
	public function warnings( array $actions ): array {
		$warnings      = array();
		$regular_price = $this->literal_for( $actions, 'regular_price' );

		foreach ( $actions as $action ) {
			$value = $this->literal( $action );

			if ( null === $value || ! is_numeric( $value ) ) {
				continue;
			}

			if ( 'regular_price' === $action->field() ) {
				$warnings[] = array(
					'code'       => self::SALE_PRICE_CLEARED,
					'predicates' => array( new Sale_Price_Cleared( (float) $value, self::SALE_PRICE_CLEARED ) ),
				);
			}

			if ( 'sale_price' === $action->field() ) {
				$warnings[] = array(
					'code'       => self::SALE_PRICE_PROTECTED,
					'predicates' => array(
						new Meta_Present( '_sale_price', self::SALE_PRICE_PROTECTED ),
						new Not(
							new Sale_Price_Sticks( (float) $value, self::SALE_PRICE_PROTECTED, $regular_price ),
							self::SALE_PRICE_PROTECTED
						),
					),
				);
			}
		}

		return $warnings;
	}

	/**
	 * Why a written field came back unchanged, for a row the runner could not apply.
	 *
	 * Called after `$product->save()`, so the product's in-memory props are the ones
	 * WooCommerce kept — `validate_props()` and the data store have already had their
	 * say. The answer is best-effort by design: an unrecognised override is reported
	 * as {@see Skip_Reason::REJECTED} rather than guessed at.
	 *
	 * @param WC_Product  $product  The product, as saved.
	 * @param string      $field    The field key that was written.
	 * @param string|null $intended The value the action asked for.
	 * @param string|null $old      The value read before the write.
	 */
	public function explain( WC_Product $product, string $field, ?string $intended, ?string $old ): Skip_Reason {
		if ( Values::equal( $intended, $old ) ) {
			// Nothing was ever going to change: the object already held the target.
			return Skip_Reason::UNCHANGED;
		}

		if ( 'sale_price' === $field && null !== $intended && '' !== $intended && is_numeric( $intended ) ) {
			$regular = Values::to_string( $product->get_regular_price( 'edit' ) );

			if ( null === $regular || '' === $regular || ! is_numeric( $regular ) || (float) $intended >= (float) $regular ) {
				return Skip_Reason::SALE_NOT_BELOW_REGULAR;
			}
		}

		if ( 'stock_status' === $field && (bool) $product->get_manage_stock() ) {
			// Managed stock: validate_props() derives the status from the quantity
			// and backorder setting on every save, so the value never lands.
			return Skip_Reason::STOCK_MANAGED;
		}

		return Skip_Reason::REJECTED;
	}

	/**
	 * The postmeta keys the actions' read fields map to, deduplicated in first-seen
	 * order ({@see Action::reads()}).
	 *
	 * @param Action[] $actions The operation's actions.
	 * @return list<string>
	 */
	private function read_meta_keys( array $actions ): array {
		$keys = array();

		foreach ( $actions as $action ) {
			foreach ( $action->reads() as $field ) {
				if ( isset( self::READ_META_KEYS[ $field ] ) ) {
					$keys[] = self::READ_META_KEYS[ $field ];
				} elseif ( str_starts_with( $field, 'meta:' ) ) {
					$keys[] = substr( $field, strlen( 'meta:' ) );
				}
			}
		}

		return array_values( array_unique( $keys ) );
	}

	/**
	 * The literal value an action writes, or null when it derives one per object.
	 * Read through {@see Action::to_array()} so any future literal action is covered
	 * without naming its class.
	 *
	 * @param Action $action The action to inspect.
	 */
	private function literal( Action $action ): ?string {
		$shape = $action->to_array();

		if ( Set_Value::TYPE !== ( $shape['type'] ?? '' ) || ! array_key_exists( 'value', $shape ) ) {
			return null;
		}

		return Values::to_string( $shape['value'] );
	}

	/**
	 * The literal a set of actions writes to one field, when exactly that shape is
	 * present — used to compare two prices the same operation sets against each
	 * other rather than against what is stored.
	 *
	 * @param Action[] $actions The operation's actions.
	 * @param string   $field   The field key to look for.
	 */
	private function literal_for( array $actions, string $field ): ?string {
		foreach ( $actions as $action ) {
			if ( $field === $action->field() ) {
				return $this->literal( $action );
			}
		}

		return null;
	}

	/**
	 * The quantity WooCommerce treats as "no stock" when deriving a managed
	 * product's status, absolute as `validate_props()` takes it.
	 */
	private function no_stock_threshold(): float {
		return abs( (float) get_option( 'woocommerce_notify_no_stock_amount', 0 ) );
	}
}
