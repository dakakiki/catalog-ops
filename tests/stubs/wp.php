<?php
/**
 * The smallest slice of WordPress the unit suite needs.
 *
 * The unit suite deliberately loads no WordPress: it is the fast tier, and code
 * that needs WordPress belongs in the integration tier. But some pure logic sits on
 * a class that merely *holds* a `wpdb` — the ACF type map is a decision table that
 * touches no database and could not be unit-tested at all without a `wpdb` symbol
 * for `createStub()` to reflect over — and some of it builds a user-facing refusal
 * with `__()`.
 *
 * Both stubs are guarded, so the real WordPress always wins in the integration
 * suite and at runtime. Neither pretends to behave like WordPress: `__()` returns
 * its input, and the `wpdb` methods return nothing. A test that needs a real answer
 * from either belongs in the integration suite.
 *
 * @package CatalogOps\Tests
 */

if ( ! function_exists( '__' ) ) {
	/**
	 * Translation passthrough.
	 *
	 * @param string $text   The text.
	 * @param string $domain Text domain, ignored.
	 * @return string
	 */
	function __( string $text, string $domain = 'default' ): string { // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedFunctionFound -- Stubbing a WordPress core function under test.
		unset( $domain );

		return $text;
	}
}

if ( ! function_exists( 'maybe_unserialize' ) ) {
	/**
	 * Unserialize a value if it looks serialized.
	 *
	 * @param mixed $data Value to unserialize.
	 * @return mixed
	 */
	function maybe_unserialize( $data ) { // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedFunctionFound -- Stubbing a WordPress core function under test.
		if ( is_string( $data ) && 1 === preg_match( '/^[aOs]:\d+:/', $data ) ) {
			return unserialize( $data ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.serialize_unserialize -- Test stub mirroring core behaviour.
		}

		return $data;
	}
}

if ( ! class_exists( 'wpdb' ) ) {
	/**
	 * A `wpdb` shape with no behaviour, so a stub can be built from it.
	 *
	 * Every method returns null. That is the point: a unit test that reaches one has
	 * strayed out of the tier, and a null is a loud enough failure to notice.
	 */
	class wpdb { // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedClassFound, PEAR.NamingConventions.ValidClassName.StartWithCapital, Generic.Classes.OpeningBraceSameLine.ContentAfterBrace -- Stubbing a WordPress core class under test.

		/**
		 * Table prefix.
		 *
		 * @var string
		 */
		public $prefix = 'wp_';

		/**
		 * Posts table.
		 *
		 * @var string
		 */
		public $posts = 'wp_posts';

		/**
		 * Post meta table.
		 *
		 * @var string
		 */
		public $postmeta = 'wp_postmeta';

		/**
		 * Terms table.
		 *
		 * @var string
		 */
		public $terms = 'wp_terms';

		/**
		 * Term taxonomy table.
		 *
		 * @var string
		 */
		public $term_taxonomy = 'wp_term_taxonomy';

		/**
		 * Term relationships table.
		 *
		 * @var string
		 */
		public $term_relationships = 'wp_term_relationships';

		/**
		 * Last error message.
		 *
		 * @var string
		 */
		public $last_error = '';

		/**
		 * Interpolate a query.
		 *
		 * @param string $query Query with placeholders.
		 * @param mixed  ...$args Arguments.
		 * @return string|null
		 */
		public function prepare( $query, ...$args ) { // phpcs:ignore Squiz.Commenting.FunctionComment.ParamCommentFullStop
			unset( $query, $args );

			return null;
		}

		/**
		 * Run a query returning one row.
		 *
		 * @param string|null $query  Query.
		 * @param string      $output Output type.
		 * @return mixed
		 */
		public function get_row( $query = null, $output = OBJECT ) { // phpcs:ignore
			unset( $query, $output );

			return null;
		}

		/**
		 * Run a query returning many rows.
		 *
		 * @param string|null $query  Query.
		 * @param string      $output Output type.
		 * @return mixed
		 */
		public function get_results( $query = null, $output = OBJECT ) { // phpcs:ignore
			unset( $query, $output );

			return null;
		}

		/**
		 * Run a query returning one value.
		 *
		 * @param string|null $query Query.
		 * @return mixed
		 */
		public function get_var( $query = null ) { // phpcs:ignore
			unset( $query );

			return null;
		}

		/**
		 * Run a query.
		 *
		 * @param string $query Query.
		 * @return mixed
		 */
		public function query( $query ) { // phpcs:ignore
			unset( $query );

			return null;
		}

		/**
		 * Escape a LIKE operand.
		 *
		 * @param string $text Text.
		 * @return string
		 */
		public function esc_like( $text ) { // phpcs:ignore
			return addcslashes( (string) $text, '_%\\' );
		}

		/**
		 * Undo prepare()'s internal escaping of a literal percent.
		 *
		 * Unlike its neighbours this one returns its input rather than null, because
		 * it is not a database call: real wpdb only strips a placeholder hash that
		 * its own prepare() put there. Present at all because PHP resolves a method
		 * before it evaluates the arguments of the call — so a test asserting that
		 * `count_sql()` refuses a filter would fail on the missing method here,
		 * naming it, rather than on the refusal it is actually about.
		 *
		 * @param string $query Query.
		 * @return string
		 */
		public function remove_placeholder_escape( $query ) { // phpcs:ignore
			return (string) $query;
		}
	}
}

if ( ! defined( 'OBJECT' ) ) {
	define( 'OBJECT', 'OBJECT' );
}

if ( ! defined( 'ARRAY_A' ) ) {
	define( 'ARRAY_A', 'ARRAY_A' );
}
