<?php
/**
 * Resolves a filter to a frozen list of product IDs.
 *
 * @package CatalogOps\Query
 */

namespace CatalogOps\Query;

use CatalogOps\Query\Fields\Filter_Providers;
use CatalogOps\Query\Fields\Storage_Compiler;
use CatalogOps\Query\Requirements\Requirement;
use wpdb;

/**
 * The read-only heart of M1. Turns a {@see Filter} into product IDs with a
 * single SELECT: numeric and stock conditions read straight off
 * wc_product_meta_lookup (which WooCommerce keeps indexed), and taxonomy/meta
 * conditions become correlated EXISTS subqueries rather than fan-out JOINs.
 *
 * Only `SELECT product_id` ever leaves the database — no product objects are
 * loaded (CONTEXT §9). The list it returns is meant to be frozen once and then
 * operated on, never re-queried mid-operation (CONTEXT §2).
 */
final class Query_Engine {

	/**
	 * WordPress database handle.
	 *
	 * @var wpdb
	 */
	private wpdb $wpdb;

	/**
	 * The module field registry, or null when the engine answers core keys only.
	 *
	 * @var Filter_Providers|null
	 */
	private ?Filter_Providers $providers;

	/**
	 * Compiles a provider's storage descriptor into SQL. Built once so its
	 * memoisation lasts the request — a preview renders its filter 2+N+W times.
	 *
	 * @var Storage_Compiler|null
	 */
	private ?Storage_Compiler $compiler = null;

	/**
	 * Build the engine over a database handle.
	 *
	 * The registry is nullable, and that is not laziness: thirteen sites construct
	 * a bare `new Query_Engine( $wpdb )` — twelve tests and the EXPLAIN harness —
	 * and a required argument would edit every one of them for no behavioural
	 * reason and bury the real diff. A null registry means "core keys only", which
	 * is exactly what those callers want.
	 *
	 * @param wpdb                  $wpdb      WordPress database handle.
	 * @param Filter_Providers|null $providers Module field registry, if any.
	 */
	public function __construct( wpdb $wpdb, ?Filter_Providers $providers = null ) {
		$this->wpdb      = $wpdb;
		$this->providers = $providers;
	}

	/**
	 * Resolve a filter to the matching product IDs, ascending.
	 *
	 * @param Filter        $filter       The filter to resolve.
	 * @param Requirement[] $requirements Applicability constraints every match must
	 *                                    satisfy — the objects an edit can actually
	 *                                    change, so ones it would only skip are
	 *                                    excluded up front.
	 * @param int           $limit        Stop after this many ids; 0 for all of them.
	 * @return list<int>
	 */
	public function resolve( Filter $filter, array $requirements = array(), int $limit = 0 ): array {
		$sql = $this->select( 'l.product_id', $filter, $requirements ) . ' ORDER BY l.product_id ASC';

		if ( $limit > 0 ) {
			// A caller that only wants a handful — the preview's sample — should not
			// pay to materialise every id in the catalogue to throw them away.
			$sql .= $this->wpdb->prepare( ' LIMIT %d', $limit );
		}

		// $sql is assembled in select() from trusted identifiers with all values
		// bound via $wpdb->prepare().
		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		return array_map( 'intval', $this->wpdb->get_col( $sql ) );
	}

	/**
	 * Count the products matching a filter without materializing the IDs.
	 *
	 * @param Filter        $filter       The filter to count.
	 * @param Requirement[] $requirements Applicability constraints (see {@see resolve()}).
	 */
	public function count( Filter $filter, array $requirements = array() ): int {
		// select() returns a prepared statement built from trusted identifiers.
		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		return (int) $this->wpdb->get_var( $this->select( 'COUNT(*)', $filter, $requirements ) );
	}

	/**
	 * The exact statement {@see resolve()} would run, without running it.
	 *
	 * Built for the EXPLAIN harness, which until now reached the private
	 * `select()` through `ReflectionMethod::setAccessible()`. That worked and was
	 * quietly corrosive: a reflective caller cannot be found by a rename, it does
	 * not appear in any call graph, and — the part that actually cost something —
	 * it made passing requirements awkward enough that the harness never did, so
	 * the one-to-three positive semi-joins {@see Write_Rules} contributes at
	 * preview and at freeze have never been EXPLAINed on a real catalogue. Those
	 * joins are the headroom every provider clause competes for.
	 *
	 * No projection parameter: the two shapes the engine actually runs are the two
	 * methods below, so nothing here widens what a caller can put into a statement.
	 *
	 * @param Filter        $filter       The filter.
	 * @param Requirement[] $requirements Applicability constraints.
	 * @param int           $limit        Stop after this many ids; 0 for all.
	 */
	public function resolve_sql( Filter $filter, array $requirements = array(), int $limit = 0 ): string {
		$sql = $this->select( 'l.product_id', $filter, $requirements ) . ' ORDER BY l.product_id ASC';

		if ( $limit > 0 ) {
			$sql .= $this->wpdb->prepare( ' LIMIT %d', $limit );
		}

		// wpdb::prepare() hands back a LIKE's '%' as an internal placeholder hash
		// and only restores it inside get_col()/get_var() at execution time. A
		// statement meant to be read or EXPLAINed has to be restored here, or its
		// LIKE carries the hash instead of a wildcard.
		return $this->wpdb->remove_placeholder_escape( $sql );
	}

	/**
	 * The exact statement {@see count()} would run, without running it.
	 *
	 * @param Filter        $filter       The filter.
	 * @param Requirement[] $requirements Applicability constraints.
	 */
	public function count_sql( Filter $filter, array $requirements = array() ): string {
		return $this->wpdb->remove_placeholder_escape( $this->select( 'COUNT(*)', $filter, $requirements ) );
	}

	/**
	 * Build the full, prepared SELECT for a projection and filter. The scope
	 * decides the post type (parent products or their variations); a variation
	 * carries its own price, stock, sku, and meta, but inherits its category from
	 * the parent, and its chosen attribute value lives on the variation itself
	 * (CONTEXT §4).
	 *
	 * @param string        $projection   The select list, e.g. `l.product_id`.
	 * @param Filter        $filter       The filter to translate.
	 * @param Requirement[] $requirements Applicability constraints on a match.
	 */
	private function select( string $projection, Filter $filter, array $requirements = array() ): string {
		$lookup   = $this->wpdb->prefix . 'wc_product_meta_lookup';
		$posts    = $this->wpdb->posts;
		$postmeta = $this->wpdb->postmeta;
		$scope    = $filter->scope();

		// Set membership is joined, not tested in the WHERE clause.
		//
		// Every `l.product_id IN (SELECT …)` is an invitation for MySQL to re-plan
		// the whole query around it, and with two of them present it eventually
		// takes the invitation: measured on an 18.5k-product catalogue, filtering by
		// category held its plan up to a point and then flipped — the meta lookup
		// stopped driving, `posts` lost its primary-key path for a range scan with a
		// block-nested-loop join, and a one-second query became four minutes and
		// then a fatal. The tipping point moved with how many rows the optimiser
		// estimated, which is why it looked like "it breaks past ten thousand".
		//
		// Joining says the same thing in a shape with no such freedom, and it is the
		// only form that stayed stable across every selection measured, all the way
		// to every category at once.
		$joins     = array();
		$join_args = array();

		// Product scope targets simple, priced products. A variable product keeps
		// its price on its variations — its own _regular_price is empty — so it
		// belongs to the variation scope, not here. Requiring a regular price
		// excludes those parents (and any unpriced product), so a Product-scope
		// price edit never silently skips what it cannot compute (CONTEXT §4).
		if ( ! $scope->is_variation() ) {
			$joins[] = "INNER JOIN {$postmeta} co_price
				ON co_price.post_id = l.product_id
				AND co_price.meta_key = '_regular_price'
				AND co_price.meta_value <> ''";
		}

		list( $where, $where_args, $condition_joins, $condition_join_args ) = $this->build_where( $filter );

		$joins     = array( ...$joins, ...$condition_joins );
		$join_args = array( ...$join_args, ...$condition_join_args );

		// After the posts join, not before: under the variation scope a membership
		// join matches on the parent (p.post_parent), which does not exist yet.
		$sql = "SELECT {$projection}
			FROM {$lookup} l
			INNER JOIN {$posts} p ON p.ID = l.product_id
			" . implode( "\n\t\t\t", $joins ) . "
			WHERE p.post_type = %s AND p.post_status = 'publish'";

		// Placeholders bind in the order they appear in the statement, so the join
		// arguments come first — they sit in the FROM clause, ahead of the WHERE.
		$args = array( ...$join_args, $scope->post_type() );

		// Applicability: keep only the objects the edit can actually change — ones
		// carrying every field it reads, and ones whose new value WooCommerce will
		// keep rather than silently override on save. Excluding them here is what
		// makes the previewed count equal the applied count (CONTEXT §2); each
		// requirement carries the reason its exclusions are reported under.
		foreach ( $requirements as $requirement ) {
			list( $fragment, $fragment_args ) = $requirement->sql( $this->wpdb, $scope );

			if ( '' === $fragment ) {
				continue;
			}

			// Parenthesised for the same reason the condition fragments are, and this
			// side is the one with a live precedent: {@see Requirements\Not} already
			// wraps its inner fragment because it had to. A requirement that ever
			// returns a top-level OR would otherwise widen the whole statement, and a
			// requirement is the one thing here that may never widen — its entire
			// purpose is to keep the previewed count equal to the applied one.
			$sql .= ' AND ( ' . $fragment . ' )';
			$args = array( ...$args, ...$fragment_args );
		}

		if ( '' !== $where ) {
			$sql .= ' AND ' . $where;
			$args = array( ...$args, ...$where_args );
		}

		$this->assert_parity( $sql, $args );

		// Identifiers ($projection, table names) are trusted; every value —
		// including the post type — is a placeholder resolved here by prepare().
		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		return $this->wpdb->prepare( $sql, ...$args );
	}

	/**
	 * Refuse to run a statement whose placeholders and arguments do not match.
	 *
	 * The highest-value check available here, because both ways of getting it wrong
	 * are silent. **Too few arguments** and `prepare()` returns the empty string;
	 * `get_var('')` is null, `count()` casts null to 0, and the preview reports
	 * "0 products match" — a wrong answer the user believes, adjusts their filter
	 * around, and never reports as a bug. **Too many** and `vsprintf` binds the tail
	 * one slot off: a statement that runs, succeeds, and answers a different
	 * question than the one asked. Neither leaves a trace.
	 *
	 * It is stricter than wpdb on purpose. WordPress's escape pass accepts a space
	 * as a printf padding character, so a stray `100% dry` inside a fragment parses
	 * as a real placeholder there; here any percent sign that is not `%s`, `%d`,
	 * `%f` or a doubled `%%` is refused outright. This engine writes every byte of
	 * its own SQL and has no reason to contain one.
	 *
	 * The check covers the requirement path too, which is still hand-written SQL,
	 * and it catches a mistake of the engine's own as readily as a provider's — the
	 * reason it goes here rather than in any one clause builder.
	 *
	 * Refusing is right rather than merely safe: every one of these failures leaves
	 * the id set the run would freeze possibly wrong in the widening direction, and
	 * there is no narrower thing to substitute. As a {@see Filter_Field_Unavailable}
	 * it maps to 400 at the REST boundary and pauses a schedule with a recorded
	 * reason on the unattended path, rather than starving every later schedule.
	 *
	 * @param string  $sql  The assembled statement, before prepare().
	 * @param mixed[] $args The argument vector.
	 *
	 * @throws Filter_Field_Unavailable When they do not match.
	 */
	private function assert_parity( string $sql, array $args ): void {
		// Doubled percents are literals, not placeholders, and must not be counted
		// as either a placeholder or a stray.
		$stripped = str_replace( '%%', '', $sql );

		$placeholders = preg_match_all( '/%[sdf]/', $stripped );
		$strays       = substr_count( str_replace( array( '%s', '%d', '%f' ), '', $stripped ), '%' );

		if ( $strays > 0 ) {
			$this->refuse_statement(
				sprintf(
					'the query carries %d percent sign(s) that are not placeholders.',
					$strays
				)
			);
		}

		if ( count( $args ) !== $placeholders ) {
			$this->refuse_statement(
				sprintf(
					'the query has %1$d placeholder(s) and %2$d value(s).',
					$placeholders,
					count( $args )
				)
			);
		}

		foreach ( $args as $arg ) {
			if ( ! is_scalar( $arg ) ) {
				// wpdb replaces a non-scalar with '' after a _doing_it_wrong nobody
				// sees, so a nested array arriving from the schemaless REST body would
				// otherwise quietly become `= ''`.
				$this->refuse_statement( 'one of its values is not a single value.' );
			}
		}
	}

	/**
	 * Translate a filter's conditions into a WHERE fragment, plus any joins the
	 * clauses would rather be expressed as.
	 *
	 * A join is an AND by construction, so a clause may only contribute one when
	 * the filter's conditions are ANDed together. Under OR every clause stays in
	 * the WHERE, where it can be combined with the others truthfully — the shape is
	 * slower, but an OR filter that returns the wrong products would be worse.
	 *
	 * @param Filter $filter The filter.
	 * @return array{0: string, 1: list<mixed>, 2: list<string>, 3: list<mixed>}
	 */
	private function build_where( Filter $filter ): array {
		$fragments = array();
		$args      = array();
		$joins     = array();
		$join_args = array();
		$joinable  = Filter::RELATION_OR !== $filter->relation();
		$index     = 0;

		foreach ( $filter->conditions() as $condition ) {
			$clause = $this->clause_for( $condition, $filter->scope(), $joinable ? $index : null );
			++$index;

			list( $fragment, $fragment_args ) = $clause;
			$join                             = $clause[2] ?? '';

			if ( '' !== $join ) {
				$joins[]   = $join;
				$join_args = array( ...$join_args, ...( $clause[3] ?? array() ) );
			}

			if ( '' === $fragment ) {
				continue;
			}

			// Each fragment gets its own parentheses before it is glued to the next.
			// Every clause this engine writes today is a single self-contained
			// predicate, so this changes no statement it currently produces — and it
			// is the difference between a filter that is right and one that is right
			// by luck. A fragment carrying a top-level OR, glued with ' AND ', binds
			// as `a AND b OR c`: the OR wins, and the filter matches everything c
			// matches regardless of the other conditions. That is silent widening,
			// which preview and run agree on perfectly, and the compiler M7 adds emits
			// exactly one such fragment (a SERIALIZED_LIST probe over several
			// operands). Two characters now, or a bug that cannot be seen later.
			$fragments[] = '( ' . $fragment . ' )';
			$args        = array( ...$args, ...$fragment_args );
		}

		if ( array() === $fragments ) {
			return array( '', array(), $joins, $join_args );
		}

		$glue = Filter::RELATION_OR === $filter->relation() ? ' OR ' : ' AND ';

		return array( '( ' . implode( $glue, $fragments ) . ' )', $args, $joins, $join_args );
	}

	/**
	 * Dispatch a condition to the right clause builder based on its field and the
	 * query scope.
	 *
	 * @param Condition   $condition The condition.
	 * @param Query_Scope $scope     The object type being queried.
	 * @param int|null    $join_slot A number unique to this condition, used to name
	 *                               a join alias — or null when the filter's shape
	 *                               forbids joins (an OR relation), in which case
	 *                               the clause must stay in the WHERE.
	 * @return array{0: string, 1: list<mixed>, 2?: string, 3?: list<mixed>}
	 *
	 * @throws Filter_Field_Unavailable When the condition cannot be turned into SQL.
	 */
	private function clause_for( Condition $condition, Query_Scope $scope, ?int $join_slot = null ): array {
		// Every path into the engine — the results table, the previewed count, each
		// incremental requirement count, the warning counts, the sample, the
		// other-scope hint, and the one freezing resolve — arrives here, which makes
		// this the seam where a question the engine cannot answer has to be refused.
		// Dropping it removed a constraint, and under AND that hands back a *larger*
		// set than was asked for, previewed and applied identically.
		//
		// A key this engine does not answer itself is offered to the registry before
		// it is refused, and the ORDER is the whole safety property. The check used
		// to be unconditional, which made the fall-through below provably dead;
		// asking `handles()` first keeps every core key on exactly the path it was
		// measured on, and only a key the engine has no builder for can reach a
		// provider. A module therefore cannot redefine `price`, and a typo cannot
		// reach anything: {@see provider_clause()} refuses when nobody claims it.
		if ( ! Filter_Fields::handles( $condition->field ) ) {
			return $this->provider_clause( $condition, $scope, $join_slot );
		}

		$field = $condition->field;

		if ( 'price' === $field ) {
			return $this->numeric_clause( 'l.min_price', '%f', $condition );
		}

		if ( 'stock_quantity' === $field ) {
			return $this->numeric_clause( 'l.stock_quantity', '%d', $condition );
		}

		if ( 'stock_status' === $field ) {
			return $this->string_clause( 'l.stock_status', $condition );
		}

		if ( 'sku' === $field ) {
			return $this->string_clause( 'l.sku', $condition );
		}

		if ( 'category' === $field ) {
			// A variation inherits its category from the parent product.
			return $this->taxonomy_clause( 'product_cat', $condition, $scope, $join_slot );
		}

		if ( 'tag' === $field ) {
			// Like category, a variation inherits its tags from the parent product.
			return $this->taxonomy_clause( 'product_tag', $condition, $scope, $join_slot );
		}

		if ( 'brand' === $field ) {
			// WooCommerce's own brand taxonomy, core since 9.6 — the same shape as
			// category and tag, and first-party for the same reason.
			//
			// It used to be a meta key. The seed command invented
			// `_catalogops_brand` for its fake catalogue on 2026-08-07, the brand
			// dropdown was built over that key two days later, and the filter
			// `catalogops_brand_meta_key` existed so a real shop could point the
			// plugin at wherever its brands actually were. Which meant that on a
			// shop using WooCommerce's brands — the overwhelming majority — the
			// brand filter found nothing until somebody wrote PHP. Nobody had
			// looked at where brands live; the UI simply followed the data the
			// seeder had left behind.
			return $this->taxonomy_clause( 'product_brand', $condition, $scope, $join_slot );
		}

		if ( str_starts_with( $field, 'attribute:' ) ) {
			$taxonomy = substr( $field, strlen( 'attribute:' ) );

			// On a variation the chosen attribute value is stored on the variation
			// itself, not as a parent taxonomy term.
			return $scope->is_variation()
				? $this->variation_attribute_clause( $taxonomy, $condition, $join_slot )
				: $this->taxonomy_clause( $taxonomy, $condition, $scope, $join_slot );
		}

		if ( str_starts_with( $field, 'meta:' ) ) {
			return $this->meta_clause( substr( $field, strlen( 'meta:' ) ), $condition, $join_slot );
		}

		// Unreachable while assert_field() stands guard above. Kept as a throw and
		// not a fall-through so a ninth field added here without a matching entry in
		// Filter_Fields fails loudly instead of quietly matching everything.
		return $this->refuse( $condition, 'no clause builder claims that field.' );
	}

	/**
	 * Answer a condition through a registered module, or refuse it.
	 *
	 * The seam the whole of M7 exists to open, and it is deliberately narrow. The
	 * registry validates the field, the scope, the operator and the licence before
	 * the provider is touched, so `storage_for()` is only ever called for a
	 * question the field said it could answer. What comes back is a description of
	 * where a value lives; {@see Storage_Compiler} writes every byte of the SQL.
	 *
	 * A provider's own code runs inside the try, and any failure it has becomes a
	 * refusal rather than a fatal. That matters more than it sounds: unconverted, a
	 * module's TypeError would be a 500 on the results table, and on the unattended
	 * path it would escape `Schedule_Runner::fire()`'s catch as an Error rather
	 * than an Exception and starve every later schedule on the tick. Converted, it
	 * pauses that one schedule with a recorded reason.
	 *
	 * @param Condition   $condition The condition.
	 * @param Query_Scope $scope     The object type being queried.
	 * @param int|null    $join_slot Alias number, or null under an OR relation.
	 * @return array{0: string, 1: list<mixed>, 2?: string, 3?: list<mixed>}
	 *
	 * @throws Filter_Field_Unavailable When no module answers this field.
	 */
	private function provider_clause( Condition $condition, Query_Scope $scope, ?int $join_slot ): array {
		if ( null === $this->providers ) {
			return $this->refuse( $condition, 'no clause builder claims that field.' );
		}

		// Throws Filter_Field_Unavailable for an unknown, dropped, out-of-scope or
		// undeclared-operator field, and License_Limited for an ungated module —
		// which is a different exception on purpose, so the client answers 402 and
		// can offer the upgrade instead of a dead end.
		$field    = $this->providers->field_for( $condition, $scope );
		$provider = $this->providers->for( $condition->field );

		try {
			$storage = $provider->storage_for( $condition->field, $scope );
		} catch ( Filter_Field_Unavailable $e ) {
			throw $e;
		} catch ( \Throwable $e ) {
			return $this->refuse(
				$condition,
				sprintf( 'the module that provides it could not answer (%s).', $e->getMessage() )
			);
		}

		if ( null === $this->compiler ) {
			$this->compiler = new Storage_Compiler( $this->wpdb );
		}

		$clause = $this->compiler->compile(
			$field,
			$storage,
			$condition->operator,
			$condition->value,
			$scope,
			$join_slot
		);

		// Unwrapped into the private four-slot tuple only here, at the boundary. The
		// tuple stays unpublished and the compiler is the only thing that fills a
		// Clause, so its slots cannot be transposed by anyone.
		return array( $clause->where, $clause->where_args, $clause->join, $clause->join_args );
	}

	/**
	 * Refuse a condition the engine cannot turn into SQL.
	 *
	 * Every one of these call sites used to `return array( '', array() )` — the
	 * shape {@see build_where()} skips, and therefore the shape that widens under
	 * AND. They are throws now so a field or an operator added without a matching
	 * branch fails loudly rather than silently matching everything.
	 *
	 * Declared `never` rather than the tuple its callers return: the call sites read
	 * as `return $this->refuse( … )` so each branch of a clause builder still ends in
	 * a return, but the type says plainly that nothing comes back.
	 *
	 * @param Condition $condition The condition that cannot be expressed.
	 * @param string    $reason    What about it cannot be expressed, one sentence.
	 *
	 * @throws Filter_Field_Unavailable Always.
	 */
	private function refuse( Condition $condition, string $reason ): never {
		throw new Filter_Field_Unavailable(
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- UI-facing message, sanitized at the REST boundary.
			sprintf( 'The filter condition on "%1$s" cannot be run: %2$s', $condition->field, $reason )
		);
	}

	/**
	 * Refuse the whole statement, rather than one condition in it.
	 *
	 * The sibling of {@see refuse()} for a fault that belongs to the assembled
	 * query and not to any single condition — a placeholder that has no value, a
	 * value that has no placeholder. Same exception, so it maps to 400 and pauses a
	 * schedule exactly as a refused condition does.
	 *
	 * @param string $reason What is wrong, one sentence, lower case.
	 *
	 * @throws Filter_Field_Unavailable Always.
	 */
	private function refuse_statement( string $reason ): never {
		throw new Filter_Field_Unavailable(
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- UI-facing message, sanitized at the REST boundary.
			'This filter cannot be run: ' . $reason
		);
	}

	/**
	 * The clause for a membership test whose operand set is empty.
	 *
	 * "Is one of nothing" matches nothing; "is not one of nothing" matches
	 * everything. Both are real answers, and neither is the empty fragment these
	 * sites used to return — {@see build_where()} skips an empty fragment, so under
	 * AND the condition simply vanished and the filter matched a strictly larger
	 * set than was asked for. That is the one direction that may never happen
	 * quietly: preview and run resolve the same widened filter, agree perfectly,
	 * and both report success.
	 *
	 * It is deliberately not a refusal. An empty list reaches here from the UI as
	 * an ordinary state — a term select cleared, a saved filter whose terms were
	 * all deleted — and refusing would turn "you have chosen nothing yet" into a
	 * dead end. The convention is the one {@see taxonomy_clause()} already used for
	 * terms that no longer exist, applied everywhere it was missing.
	 *
	 * @param Operator $operator The operator, positive or negative.
	 * @return array{0: string, 1: list<mixed>}
	 */
	private function empty_set( Operator $operator ): array {
		return array( $operator->is_negative() ? '1 = 1' : '1 = 0', array() );
	}

	/**
	 * Numeric comparison against a lookup column.
	 *
	 * @param string    $column    Trusted column expression, e.g. `l.min_price`.
	 * @param string    $format    Placeholder for values: `%f` or `%d`.
	 * @param Condition $condition The condition.
	 * @return array{0: string, 1: list<mixed>}
	 */
	private function numeric_clause( string $column, string $format, Condition $condition ): array {
		$operator = $condition->operator;

		$simple = array(
			Operator::EQUALS->name           => '=',
			Operator::NOT_EQUALS->name       => '!=',
			Operator::GREATER_THAN->name     => '>',
			Operator::GREATER_OR_EQUAL->name => '>=',
			Operator::LESS_THAN->name        => '<',
			Operator::LESS_OR_EQUAL->name    => '<=',
		);

		if ( isset( $simple[ $operator->name ] ) ) {
			return array( "{$column} {$simple[ $operator->name ]} {$format}", array( $condition->value ) );
		}

		if ( Operator::BETWEEN === $operator ) {
			$range = array_values( (array) $condition->value );
			if ( count( $range ) < 2 ) {
				return $this->refuse( $condition, 'a "between" filter needs both a low and a high value.' );
			}

			return array( "{$column} BETWEEN {$format} AND {$format}", array( $range[0], $range[1] ) );
		}

		if ( Operator::IN === $operator || Operator::NOT_IN === $operator ) {
			$values = array_values( (array) $condition->value );
			if ( array() === $values ) {
				return $this->empty_set( $operator );
			}

			$placeholders = implode( ', ', array_fill( 0, count( $values ), $format ) );
			$keyword      = Operator::NOT_IN === $operator ? 'NOT IN' : 'IN';

			return array( "{$column} {$keyword} ( {$placeholders} )", $values );
		}

		return $this->refuse(
			$condition,
			sprintf( 'no "%s" comparison is available on a numeric field.', $operator->value )
		);
	}

	/**
	 * String comparison against a lookup column.
	 *
	 * @param string    $column    Trusted column expression, e.g. `l.stock_status`.
	 * @param Condition $condition The condition.
	 * @return array{0: string, 1: list<mixed>}
	 */
	private function string_clause( string $column, Condition $condition ): array {
		$operator = $condition->operator;

		if ( Operator::EQUALS === $operator || Operator::NOT_EQUALS === $operator ) {
			$comparison = Operator::NOT_EQUALS === $operator ? '!=' : '=';

			return array( "{$column} {$comparison} %s", array( (string) $condition->value ) );
		}

		if ( Operator::CONTAINS === $operator ) {
			// Substring search, e.g. the SKU box in the admin table.
			return array( "{$column} LIKE %s", array( '%' . $this->wpdb->esc_like( (string) $condition->value ) . '%' ) );
		}

		if ( Operator::IN === $operator || Operator::NOT_IN === $operator ) {
			$values = array_map( 'strval', array_values( (array) $condition->value ) );
			if ( array() === $values ) {
				return $this->empty_set( $operator );
			}

			$placeholders = implode( ', ', array_fill( 0, count( $values ), '%s' ) );
			$keyword      = Operator::NOT_IN === $operator ? 'NOT IN' : 'IN';

			return array( "{$column} {$keyword} ( {$placeholders} )", $values );
		}

		return $this->refuse(
			$condition,
			sprintf( 'no "%s" comparison is available on a text field.', $operator->value )
		);
	}

	/**
	 * Membership in a taxonomy's terms. Terms are attached to the parent product,
	 * so under the variation scope the match is on the variation's parent
	 * (p.post_parent) rather than the variation itself.
	 *
	 * Two forms:
	 *   - IN / NOT_IN with a list of term ids — has (or lacks) one of those terms.
	 *   - EXISTS / NOT_EXISTS with no value — has (or lacks) any term of the
	 *     taxonomy at all, i.e. "filter by this attribute, any value" (M5 filter UI).
	 *
	 * Positive membership is expressed as `{object} IN (SELECT object_id …)` rather
	 * than a correlated `EXISTS`: the subquery selects the (usually small) set of
	 * objects carrying the term, which MySQL can materialise and drive the whole
	 * query from. A correlated EXISTS instead makes the optimiser scan every
	 * published product and test each one — on a large catalogue with several
	 * conditions that mis-planned into a full scan and timed out (CONTEXT §3/§9).
	 * The negative forms stay as NOT EXISTS: an anti-join is both the right shape
	 * and free of NOT IN's NULL pitfalls.
	 *
	 * **The subquery reads one table.** term_relationships is keyed by
	 * term_taxonomy_id, not term_id, so asking it for terms means joining
	 * term_taxonomy inside the subquery — and that two-table subquery is what the
	 * optimiser gets wrong. Measured on a 18.5k-product catalogue: three categories
	 * planned well and answered in 1s; a fourth flipped the plan, `p` lost its
	 * primary-key path for a range scan with a block-nested-loop join, and the same
	 * query took **four minutes**. DISTINCT, GROUP BY and a wrapped derived table
	 * all still timed out.
	 *
	 * Resolving the term ids to term_taxonomy_ids first — one small indexed lookup,
	 * the same trick {@see term_slugs()} already uses — leaves a subquery over a
	 * single indexed table, which plans correctly at any number of terms: 992ms for
	 * the four-category case that used to take four minutes, and flat from there.
	 *
	 * Positive membership is a join when the filter's shape allows one. See
	 * {@see select()} for the measurements: as a WHERE subquery this is the clause
	 * that eventually tips the optimiser into a four-minute plan, and joining is
	 * the only form that held across every selection tried.
	 *
	 * @param string      $taxonomy  Taxonomy name, e.g. `product_cat` or `pa_color`.
	 * @param Condition   $condition Term id(s) for IN/NOT_IN, or none for (NOT_)EXISTS.
	 * @param Query_Scope $scope     The object type being queried.
	 * @param int|null    $join_slot Alias number for a join, or null if the clause
	 *                               must stay in the WHERE (an OR filter).
	 * @return array{0: string, 1: list<mixed>, 2?: string, 3?: list<mixed>}
	 */
	private function taxonomy_clause( string $taxonomy, Condition $condition, Query_Scope $scope, ?int $join_slot = null ): array {
		if ( '' === $taxonomy ) {
			// Unreachable: `attribute:` with nothing after the colon names no
			// taxonomy, and Filter_Fields refuses the key before dispatch.
			return $this->refuse( $condition, 'it names no attribute taxonomy.' );
		}

		$operator = $condition->operator;
		$any_term = Operator::EXISTS === $operator || Operator::NOT_EXISTS === $operator;

		// "Has any term of this taxonomy" (an attribute chosen with no value) means
		// every term of it; otherwise just the ones asked for.
		$term_ids = $any_term
			? array()
			: array_values( array_filter( array_map( 'intval', (array) $condition->value ) ) );

		if ( ! $any_term && array() === $term_ids ) {
			return $this->empty_set( $operator );
		}

		$tt_ids = $this->term_taxonomy_ids( $taxonomy, $term_ids );

		if ( array() === $tt_ids ) {
			// Nothing in the catalogue can carry a term that does not exist. "Has
			// one of these" matches nothing; "has none of these" matches everything.
			return array( $operator->is_negative() ? '1 = 1' : '1 = 0', array() );
		}

		$relationships = $this->wpdb->term_relationships;
		$object_column = $scope->is_variation() ? 'p.post_parent' : 'l.product_id';
		$placeholders  = implode( ', ', array_fill( 0, count( $tt_ids ), '%d' ) );

		// Exclusion stays an anti-join in the WHERE: that is the right shape for it,
		// it is free of NOT IN's NULL pitfalls, and it does not have the plan
		// problem positive membership does.
		if ( $operator->is_negative() ) {
			$fragment = "NOT EXISTS (
				SELECT 1 FROM {$relationships} tr
				WHERE tr.object_id = {$object_column} AND tr.term_taxonomy_id IN ( {$placeholders} )
			)";

			return array( $fragment, $tt_ids );
		}

		if ( null === $join_slot ) {
			// An OR filter: a join would silently turn this condition into an AND.
			$fragment = "{$object_column} IN (
				SELECT tr.object_id FROM {$relationships} tr
				WHERE tr.term_taxonomy_id IN ( {$placeholders} )
			)";

			return array( $fragment, $tt_ids );
		}

		// DISTINCT because a product in two of the chosen categories has two
		// relationship rows, and without it the join would count it twice.
		$alias = 'co_tax' . $join_slot;
		$join  = "INNER JOIN (
			SELECT DISTINCT tr.object_id FROM {$relationships} tr
			WHERE tr.term_taxonomy_id IN ( {$placeholders} )
		) {$alias} ON {$alias}.object_id = {$object_column}";

		return array( '', array(), $join, $tt_ids );
	}

	/**
	 * The term_taxonomy_ids for a taxonomy, optionally narrowed to specific terms.
	 *
	 * One indexed lookup that keeps the membership subquery down to a single table
	 * — see {@see taxonomy_clause()} for why that matters so much.
	 *
	 * @param string $taxonomy Taxonomy name.
	 * @param int[]  $term_ids Term ids, or empty for every term of the taxonomy.
	 * @return list<int>
	 */
	private function term_taxonomy_ids( string $taxonomy, array $term_ids ): array {
		$taxonomies = $this->wpdb->term_taxonomy;

		$sql  = "SELECT term_taxonomy_id FROM {$taxonomies} WHERE taxonomy = %s";
		$args = array( $taxonomy );

		if ( array() !== $term_ids ) {
			$sql .= ' AND term_id IN ( ' . implode( ', ', array_fill( 0, count( $term_ids ), '%d' ) ) . ' )';
			$args = array( ...$args, ...$term_ids );
		}

		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$ids = $this->wpdb->get_col( $this->wpdb->prepare( $sql, ...$args ) );
		// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching

		return array_map( 'intval', $ids );
	}

	/**
	 * Match a variation by its own chosen attribute value. Unlike a parent
	 * product, whose attributes are taxonomy terms, a variation stores the single
	 * value it was created for as post meta `attribute_{taxonomy}` holding the
	 * term slug. So a size-L filter matches only the L variations, not every
	 * variation of a parent that offers size L (CONTEXT §4 — variations first
	 * class). The condition's term ids are resolved to slugs to compare.
	 *
	 * @param string    $taxonomy  Attribute taxonomy, e.g. `pa_size`.
	 * @param Condition $condition Term id(s) for IN/NOT_IN, or none for (NOT_)EXISTS.
	 * @param int|null  $join_slot Alias number for a join, or null if the clause must
	 *                             stay in the WHERE (an OR filter).
	 * @return array{0: string, 1: list<mixed>, 2?: string, 3?: list<mixed>}
	 */
	private function variation_attribute_clause( string $taxonomy, Condition $condition, ?int $join_slot = null ): array {
		if ( '' === $taxonomy ) {
			// Unreachable: `attribute:` with nothing after the colon names no
			// taxonomy, and Filter_Fields refuses the key before dispatch.
			return $this->refuse( $condition, 'it names no attribute taxonomy.' );
		}

		$postmeta = $this->wpdb->postmeta;
		$meta_key = 'attribute_' . $taxonomy;
		$operator = $condition->operator;

		// Positive membership is a join over a DISTINCT derived table when the
		// filter's shape allows one, and the semi-join only under OR — the same
		// rewrite {@see taxonomy_clause()} and {@see meta_clause()} both had, and
		// the last builder to get it.
		//
		// It was left as a bare semi-join because a correlated EXISTS over 50k
		// variations was worse, which was true and was not the end of the story. The
		// EXPLAIN harness measured what the semi-join actually costs on this
		// catalogue: a single `attribute:pa_size` filter under the variation scope
		// took **forty-six minutes** to resolve, and could not COUNT inside thirty
		// seconds. That is the same optimiser tip-over the product-scope clause was
		// rewritten away from — reached here by the same route, and unnoticed for
		// longer because nothing had ever EXPLAINed the variation scope.
		//
		// Negatives stay NOT EXISTS: an anti-join, NULL-safe, and not the shape with
		// the plan problem.

		// "Has any value for this attribute" (chosen with no specific value), or none.
		if ( Operator::EXISTS === $operator || Operator::NOT_EXISTS === $operator ) {
			if ( Operator::NOT_EXISTS === $operator ) {
				$fragment = "NOT EXISTS (
					SELECT 1 FROM {$postmeta} pm
					WHERE pm.post_id = l.product_id AND pm.meta_key = %s AND pm.meta_value <> ''
				)";

				return array( $fragment, array( $meta_key ) );
			}

			if ( null === $join_slot ) {
				$fragment = "l.product_id IN (
					SELECT pm.post_id FROM {$postmeta} pm
					WHERE pm.meta_key = %s AND pm.meta_value <> ''
				)";

				return array( $fragment, array( $meta_key ) );
			}

			$alias = 'co_var' . $join_slot;
			$join  = "INNER JOIN (
				SELECT DISTINCT pm.post_id FROM {$postmeta} pm
				WHERE pm.meta_key = %s AND pm.meta_value <> ''
			) {$alias} ON {$alias}.post_id = l.product_id";

			return array( '', array(), $join, array( $meta_key ) );
		}

		$term_ids = array_values( array_filter( array_map( 'intval', (array) $condition->value ) ) );

		if ( array() === $term_ids ) {
			return $this->empty_set( $condition->operator );
		}

		$slugs = $this->term_slugs( $term_ids );

		if ( array() === $slugs ) {
			// Nothing can carry a term that no longer exists. "Has one of these"
			// matches nothing; "has none of these" matches everything. This is the
			// answer the product scope has always given ({@see taxonomy_clause()});
			// dropping the clause here made the same filter match every variation.
			return array( $operator->is_negative() ? '1 = 1' : '1 = 0', array() );
		}

		$placeholders = implode( ', ', array_fill( 0, count( $slugs ), '%s' ) );

		if ( $operator->is_negative() ) {
			$fragment = "NOT EXISTS (
				SELECT 1 FROM {$postmeta} pm
				WHERE pm.post_id = l.product_id AND pm.meta_key = %s AND pm.meta_value IN ( {$placeholders} )
			)";

			return array( $fragment, array( $meta_key, ...$slugs ) );
		}

		if ( null === $join_slot ) {
			$fragment = "l.product_id IN (
				SELECT pm.post_id FROM {$postmeta} pm
				WHERE pm.meta_key = %s AND pm.meta_value IN ( {$placeholders} )
			)";

			return array( $fragment, array( $meta_key, ...$slugs ) );
		}

		$alias = 'co_var' . $join_slot;
		$join  = "INNER JOIN (
			SELECT DISTINCT pm.post_id FROM {$postmeta} pm
			WHERE pm.meta_key = %s AND pm.meta_value IN ( {$placeholders} )
		) {$alias} ON {$alias}.post_id = l.product_id";

		return array( '', array(), $join, array( $meta_key, ...$slugs ) );
	}

	/**
	 * Resolve term ids to their slugs (variation attribute meta stores slugs).
	 *
	 * @param int[] $term_ids Term ids.
	 * @return list<string>
	 */
	private function term_slugs( array $term_ids ): array {
		$terms        = $this->wpdb->terms;
		$placeholders = implode( ', ', array_fill( 0, count( $term_ids ), '%d' ) );

		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$slugs = $this->wpdb->get_col(
			$this->wpdb->prepare(
				"SELECT slug FROM {$terms} WHERE term_id IN ( {$placeholders} )",
				...$term_ids
			)
		);
		// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching

		return array_map( 'strval', $slugs );
	}

	/**
	 * Post-meta comparison against a meta key.
	 *
	 * Positive tests drive from the postmeta meta_key index (one pass) rather than
	 * a correlated EXISTS that re-scans every product's meta — over WooCommerce
	 * products (~20-30 meta rows each) that difference is ~12x.
	 *
	 * A negative test only flips that keyword to NOT IN; the value test inside
	 * stays positive. Pushing the negation inside instead — `IN (… meta_value !=
	 * x)` — asks a different question, "has some *other* value for this key",
	 * which silently drops every product that has no such meta row at all. A
	 * brand exclusion has to keep the unbranded products: they are, definitively,
	 * not that brand. (It also builds a near-catalogue-sized id list to do it.)
	 *
	 * NOT IN, not a correlated NOT EXISTS. Both are NULL-safe here — postmeta's
	 * post_id is NOT NULL — but the correlated form re-runs per candidate row,
	 * and the uncorrelated one is materialised once. Measured on the 18.5k
	 * catalogue: excluding a brand with nothing else selected took **13.6s** as
	 * NOT EXISTS and **2.5s** as NOT IN. Once any positive condition narrows the
	 * candidates the two converge (594ms vs 568ms with a category and a tag
	 * joined), so the cheap shape costs nothing in the common case and saves the
	 * degenerate one — a lone exclusion is exactly what a user gets by opening
	 * the filter and setting one field to "is not".
	 *
	 * Unlike positive membership, this subquery is not a plan hazard: an
	 * anti-join gives the optimiser no join order to re-plan around. Two of them
	 * in one statement measured 800ms with a category and tag joined (CONTEXT §3
	 * — the four-minute plan needed positive `IN (SELECT …)` semi-joins).
	 *
	 * **Positive membership is joined under AND, exactly as taxonomy already is,
	 * and the omission was expensive.** The rule this class is built around was
	 * applied to categories and tags and then never carried across to meta, so a
	 * brand — the one meta field the UI actually offers — kept writing the very
	 * `l.product_id IN (SELECT …)` the comment at the top of {@see sql_for()}
	 * warns about. Measured on the live catalogue on 2026-09-06, brands being
	 * ~10,000 products each: four brands with nothing else selected **did not
	 * finish inside 45 seconds** as a tested `IN`, and took **4.4s** joined; with
	 * five categories alongside, 9.1s against 3.8s. Same answers, 3,830 both ways.
	 * The user met it as a filter that simply stopped responding.
	 *
	 * DISTINCT for the same reason the taxonomy join needs it: postmeta does not
	 * stop a product carrying the key twice, and two matching rows would otherwise
	 * count it twice.
	 *
	 * Under OR there is no join to be had — a join is an AND — so that path keeps
	 * the tested form, and so does every negation, which {@see taxonomy_clause()}
	 * and the paragraphs above explain is the shape negation actually wants.
	 *
	 * @param string    $meta_key  The meta key to test.
	 * @param Condition $condition The condition.
	 * @param int|null  $join_slot Alias number for a join, or null if the clause
	 *                             must stay in the WHERE (an OR filter).
	 * @return array{0: string, 1: list<mixed>, 2?: string, 3?: list<mixed>}
	 */
	private function meta_clause( string $meta_key, Condition $condition, ?int $join_slot = null ): array {
		if ( '' === $meta_key ) {
			// Unreachable: `meta:` on its own names no key.
			return $this->refuse( $condition, 'it names no meta key.' );
		}

		$postmeta = $this->wpdb->postmeta;
		$operator = $condition->operator;

		if ( ( Operator::IN === $operator || Operator::NOT_IN === $operator )
			&& array() === array_values( (array) $condition->value ) ) {
			// An empty list is not a question: asked positively it would decay into
			// "has this key at all", and negatively into "has it not".
			return $this->empty_set( $operator );
		}

		// A negative operator is asked as its positive twin and negated by the
		// keyword outside the subquery — see Operator::positive_twin() for why the
		// negation may not be pushed in beside the value.
		list( $value_test, $value_args ) = $this->meta_value_test( $operator->positive_twin(), $condition );

		if ( $operator->is_negative() || null === $join_slot ) {
			$keyword = $operator->is_negative() ? 'NOT IN' : 'IN';

			$fragment = "l.product_id {$keyword} (
				SELECT pm.post_id FROM {$postmeta} pm
				WHERE pm.meta_key = %s{$value_test}
			)";

			return array( $fragment, array( $meta_key, ...$value_args ) );
		}

		$alias = 'co_meta' . $join_slot;
		$join  = "INNER JOIN (
			SELECT DISTINCT pm.post_id FROM {$postmeta} pm
			WHERE pm.meta_key = %s{$value_test}
		) {$alias} ON {$alias}.post_id = l.product_id";

		return array( '', array(), $join, array( $meta_key, ...$value_args ) );
	}

	/**
	 * Build the value comparison inside a meta subquery.
	 *
	 * Positive operators only. Negation belongs to the caller, which wraps this
	 * test in a NOT EXISTS — see {@see meta_clause()} for why it may not be pushed
	 * in here alongside the value.
	 *
	 * An unanswerable comparison has to refuse here rather than return an empty
	 * test, and for a reason peculiar to this method: the caller keeps its subquery
	 * either way, so an empty test does not drop the condition — it decays it into
	 * "has this meta key at all", which is *wider* than what was asked. A one-ended
	 * `meta:_cost between 10` matching every costed product is the same silent
	 * widening as an ignored condition, arriving by a different route.
	 *
	 * @param Operator  $operator  The operator, already mapped to its positive form.
	 * @param Condition $condition The condition, for its operand and for refusal.
	 * @return array{0: string, 1: list<mixed>} SQL fragment (with leading " AND ") and its args.
	 *
	 * @throws Filter_Field_Unavailable When the comparison cannot be expressed.
	 */
	private function meta_value_test( Operator $operator, Condition $condition ): array {
		$value = $condition->value;

		$numeric = array(
			Operator::GREATER_THAN->name     => '>',
			Operator::GREATER_OR_EQUAL->name => '>=',
			Operator::LESS_THAN->name        => '<',
			Operator::LESS_OR_EQUAL->name    => '<=',
		);

		if ( Operator::EXISTS === $operator ) {
			return array( '', array() );
		}

		if ( Operator::EQUALS === $operator ) {
			return array( ' AND pm.meta_value = %s', array( (string) $value ) );
		}

		if ( Operator::CONTAINS === $operator ) {
			return array( ' AND pm.meta_value LIKE %s', array( '%' . $this->wpdb->esc_like( (string) $value ) . '%' ) );
		}

		if ( isset( $numeric[ $operator->name ] ) ) {
			return array( " AND CAST( pm.meta_value AS DECIMAL(20,4) ) {$numeric[ $operator->name ]} %f", array( $value ) );
		}

		if ( Operator::BETWEEN === $operator ) {
			$range = array_values( (array) $value );
			if ( count( $range ) < 2 ) {
				return $this->refuse( $condition, 'a "between" filter needs both a low and a high value.' );
			}

			return array( ' AND CAST( pm.meta_value AS DECIMAL(20,4) ) BETWEEN %f AND %f', array( $range[0], $range[1] ) );
		}

		if ( Operator::IN === $operator ) {
			$values = array_map( 'strval', array_values( (array) $value ) );
			if ( array() === $values ) {
				// Unreachable: meta_clause() answers an empty list before it gets
				// here. Kept as a refusal rather than the empty test it used to
				// return, because an empty test here is the widening this method's
				// own docblock exists to forbid — it leaves the subquery standing and
				// decays the condition into "has this meta key at all".
				return $this->refuse( $condition, 'an "is one of" filter needs at least one value.' );
			}

			$placeholders = implode( ', ', array_fill( 0, count( $values ), '%s' ) );

			return array( " AND pm.meta_value IN ( {$placeholders} )", $values );
		}

		// Unreachable: every positive operator has a branch above, and the negative
		// forms are mapped to their twins before the call. Kept as a throw so an
		// operator added to the enum without a branch here fails loudly instead of
		// decaying the condition into "has this meta key at all".
		return $this->refuse(
			$condition,
			sprintf( 'no "%s" comparison is available on a meta field.', $condition->operator->value )
		);
	}
}
