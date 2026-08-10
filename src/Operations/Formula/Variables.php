<?php
/**
 * The numeric fields a formula may reference, by friendly name.
 *
 * @package CatalogOps\Operations\Formula
 */

namespace CatalogOps\Operations\Formula;

/**
 * A curated whitelist mapping the short names a formula author writes
 * (`regular_price`, `cost`, ...) to the field keys the providers read (CONTEXT
 * §3, the M5 decision to keep formula variables a small, vetted set rather than
 * exposing raw WooCommerce meta keys like `_price` or `attribute_*`).
 *
 * Two responsibilities, one source of truth: the parser validates every name in
 * a formula against {@see names()}, and {@see Apply_Plan} resolves a name to a
 * value through {@see field_key()}. `cost` has no core WooCommerce field, so it
 * maps to a meta key — filterable, mirroring how brand is handled — because
 * different Cost-of-Goods plugins store it under different keys.
 */
final class Variables {

	/**
	 * The default meta key the `cost` variable reads from when no Cost-of-Goods
	 * plugin overrides it via the filter below.
	 */
	public const DEFAULT_COST_META_KEY = '_catalogops_cost';

	/**
	 * Friendly formula name => provider field key. `cost` is resolved lazily in
	 * {@see field_key()} because its meta key is filterable.
	 *
	 * @var array<string, string>
	 */
	private const CORE = array(
		'regular_price' => 'regular_price',
		'sale_price'    => 'sale_price',
		'stock'         => 'stock_quantity',
		'weight'        => 'weight',
	);

	/**
	 * Every variable name a formula may use.
	 *
	 * @return list<string>
	 */
	public static function names(): array {
		return array( ...array_keys( self::CORE ), 'cost' );
	}

	/**
	 * Whether $name is a known variable.
	 *
	 * @param string $name Candidate variable name.
	 */
	public static function exists( string $name ): bool {
		return in_array( $name, self::names(), true );
	}

	/**
	 * The provider field key a variable resolves to. `cost` maps to a meta key
	 * (with the `meta:` prefix the meta provider expects), filterable so a
	 * catalog's existing cost field can be pointed at without code.
	 *
	 * @param string $name A known variable name.
	 * @return string The provider field key, or an empty string when unknown.
	 */
	public static function field_key( string $name ): string {
		if ( isset( self::CORE[ $name ] ) ) {
			return self::CORE[ $name ];
		}

		if ( 'cost' === $name ) {
			return \CatalogOps\Operations\Fields\Meta_Fields::PREFIX . self::cost_meta_key();
		}

		return '';
	}

	/**
	 * The meta key the `cost` variable reads, overridable via filter.
	 */
	private static function cost_meta_key(): string {
		$key = self::DEFAULT_COST_META_KEY;

		if ( function_exists( 'apply_filters' ) ) {
			/**
			 * Filters the meta key the `cost` formula variable reads from.
			 *
			 * @param string $key Meta key (default `_catalogops_cost`).
			 */
			$key = (string) apply_filters( 'catalogops_cost_meta_key', $key );
		}

		return '' === $key ? self::DEFAULT_COST_META_KEY : $key;
	}
}
