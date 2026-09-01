<?php
/**
 * Why a targeted change was not written.
 *
 * @package CatalogOps\Operations
 */

namespace CatalogOps\Operations;

/**
 * A skipped row without a reason is unusable: "432 skipped" tells a user nothing
 * they can act on. Every skip therefore carries one of these codes, stored on the
 * change row (`skip_reason`) and surfaced in the preview, the result bar, and the
 * audit log.
 *
 * The same vocabulary describes both halves of the pipeline, which is what lets
 * the preview promise what execution reports:
 *
 *   - Predicted up front. {@see \CatalogOps\Operations\Write_Rules::requirements()}
 *     turns these into SQL, so the objects that would fail are excluded from the
 *     frozen target list and counted per reason in the preview. Nothing that
 *     cannot change is ever queued.
 *   - Caught at write time. The runner reads each field back after the save and,
 *     when WooCommerce did not keep the value, records the reason here. With the
 *     up-front rules in place this is a rare safety net — a formula whose result
 *     cannot be predicted in SQL, or a value another plugin overrode.
 *
 * The stored values are stable strings (the admin app and the CLI key off them),
 * so they are appended to, never renumbered.
 */
enum Skip_Reason: string {

	/**
	 * A field the change reads is empty or non-numeric, so no value could be
	 * computed (CONTEXT §3: never coerce to zero).
	 */
	case EMPTY_INPUT = 'empty_input';

	/**
	 * A sale price at or above the regular price. WooCommerce clears the sale
	 * price on save rather than storing it, so writing one is not just a no-op —
	 * it wipes whatever sale price the object already had.
	 */
	case SALE_NOT_BELOW_REGULAR = 'sale_not_below_regular';

	/**
	 * The stock status is managed. When `manage_stock` is on, WooCommerce derives
	 * the status from the quantity and backorder setting on every save and
	 * overwrites whatever was set.
	 */
	case STOCK_MANAGED = 'stock_managed';

	/**
	 * The object already held the target value, so there was nothing to write.
	 */
	case UNCHANGED = 'unchanged';

	/**
	 * The save did not keep the value and no specific rule explains it — another
	 * plugin's filter, or a validation rule this build does not model.
	 */
	case REJECTED = 'rejected';

	/**
	 * Undo only: the object changed after the operation ran and the conflict
	 * policy is skip, so it was left exactly as it is now (CONTEXT §3).
	 */
	case DRIFT = 'drift';

	/**
	 * Undo only: there is no recorded value to restore — retention pruned the
	 * parent's delta, or the provider that owned the field is gone.
	 */
	case NO_RECORD = 'no_record';

	/**
	 * A short human-readable explanation, for the PHP-side surfaces (the
	 * completion email and WP-CLI). The admin app renders its own translated
	 * strings from the stored code.
	 */
	public function label(): string {
		return match ( $this ) {
			self::EMPTY_INPUT             => __( 'a field the change reads is empty or non-numeric', 'catalogops' ),
			self::SALE_NOT_BELOW_REGULAR  => __( 'the sale price is not below the regular price', 'catalogops' ),
			self::STOCK_MANAGED           => __( 'stock is managed, so WooCommerce sets the status from the quantity', 'catalogops' ),
			self::UNCHANGED               => __( 'the value was already set', 'catalogops' ),
			self::REJECTED                => __( 'WooCommerce did not keep the value', 'catalogops' ),
			self::DRIFT                   => __( 'the object changed after the operation ran', 'catalogops' ),
			self::NO_RECORD               => __( 'there is no recorded value to restore', 'catalogops' ),
		};
	}
}
