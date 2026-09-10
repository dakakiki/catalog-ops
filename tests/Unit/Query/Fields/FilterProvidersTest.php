<?php
/**
 * The registry's refusal ladder.
 *
 * Every branch here exists because the alternative is a filter that runs against
 * a wider set than it names. A provider that vanishes when a licence lapses, a
 * field withdrawn by a module upgrade, a scope the field never meant, an operator
 * it cannot answer — each of those, left unrefused, removes a constraint from an
 * AND filter, and a removed constraint is invisible: preview and run agree, and
 * both report success.
 *
 * Pure unit tests: the registry touches no database.
 *
 * @package CatalogOps\Tests\Unit\Query\Fields
 */

namespace CatalogOps\Tests\Unit\Query\Fields;

use CatalogOps\Licensing\License;
use CatalogOps\Licensing\License_Limited;
use CatalogOps\Query\Condition;
use CatalogOps\Query\Fields\Field_Storage;
use CatalogOps\Query\Fields\Filter_Control;
use CatalogOps\Query\Fields\Filter_Field;
use CatalogOps\Query\Fields\Filter_Provider;
use CatalogOps\Query\Fields\Filter_Providers;
use CatalogOps\Query\Fields\Value_Kind;
use CatalogOps\Query\Filter;
use CatalogOps\Query\Filter_Field_Unavailable;
use CatalogOps\Query\Operator;
use CatalogOps\Query\Query_Scope;
use PHPUnit\Framework\TestCase;

/**
 * @covers \CatalogOps\Query\Fields\Filter_Providers
 * @covers \CatalogOps\Query\Fields\Filter_Field
 */
final class FilterProvidersTest extends TestCase {

	public function test_an_unclaimed_key_is_refused_and_names_the_field(): void {
		$registry = new Filter_Providers( License::unlimited(), $this->provider() );

		try {
			$registry->field_for( new Condition( 'acf:gone', Operator::EQUALS, 'x' ), Query_Scope::PRODUCT );
			$this->fail( 'An unclaimed key must be refused.' );
		} catch ( Filter_Field_Unavailable $e ) {
			$this->assertSame( 'acf:gone', $e->field, 'The client needs the key to offer a repair.' );
			$this->assertStringContainsString( 'no active plugin provides', $e->getMessage() );
		}
	}

	/**
	 * A core key is the engine's own and a provider may not redefine it. Resolution
	 * is first-claim-wins, so without this a module registering `price` would
	 * silently change what a core filter means.
	 */
	public function test_a_provider_cannot_claim_a_core_key(): void {
		$greedy = $this->provider( array( 'price', 'demo:colour' ), 'price' );

		$registry = new Filter_Providers( License::unlimited(), $greedy );

		$this->assertNull( $registry->for( 'price' ) );
		$this->assertNull( $registry->for( 'meta:anything' ), 'Reserved prefixes too.' );
		$this->assertNotNull( $registry->for( 'demo:colour' ) );
	}

	/**
	 * The gate lives here rather than in a REST permission callback because a
	 * schedule rehydrates a stored filter on a cron tick without touching a
	 * controller. A REST-only gate looks right in every manual test and is bypassed
	 * by the first unattended run under a lapsed licence.
	 *
	 * It is a different exception on purpose: the REST layer answers 402 for this
	 * one, so the client can offer the upgrade rather than a dead end.
	 */
	public function test_an_unlicensed_module_is_refused_with_the_upgrade_exception(): void {
		$registry = new Filter_Providers( new License( true, false ), $this->provider() );

		$this->expectException( License_Limited::class );
		$this->expectExceptionMessageMatches( '/Studio plan/' );

		$registry->field_for( new Condition( 'demo:colour', Operator::EQUALS, 'red' ), Query_Scope::PRODUCT );
	}

	/**
	 * And the provider stays registered while unlicensed rather than disappearing.
	 * A provider that vanishes turns every saved filter naming its fields into a
	 * *wider* filter, which is the failure the gate exists to prevent.
	 */
	public function test_an_unlicensed_field_is_still_listed_so_the_ui_can_lock_it(): void {
		$registry = new Filter_Providers( new License( true, false ), $this->provider() );

		$all = $registry->all_fields();

		$this->assertCount( 1, $all );
		$this->assertSame( 'demo:colour', $all[0]['field']->key );
		$this->assertFalse( $all[0]['available'], 'Listed, and flagged — not dropped.' );
	}

	public function test_a_field_the_module_no_longer_offers_is_refused(): void {
		// Claims the key but no longer describes it — a module upgrade that dropped
		// a field while a saved filter still names it.
		$forgetful = $this->provider( array( 'demo:colour' ), '', array() );

		$registry = new Filter_Providers( License::unlimited(), $forgetful );

		$this->expectException( Filter_Field_Unavailable::class );
		$this->expectExceptionMessageMatches( '/no longer offered/' );

		$registry->field_for( new Condition( 'demo:colour', Operator::EQUALS, 'red' ), Query_Scope::PRODUCT );
	}

	public function test_a_field_outside_its_declared_scope_is_refused_by_name(): void {
		$registry = new Filter_Providers( License::unlimited(), $this->provider() );

		$this->expectException( Filter_Field_Unavailable::class );
		$this->expectExceptionMessageMatches( '/does not apply to variations/' );

		$registry->field_for( new Condition( 'demo:colour', Operator::EQUALS, 'red' ), Query_Scope::VARIATION );
	}

	/**
	 * The operator check runs *before* the provider is asked for a storage, which is
	 * what makes "I do not support this comparison" a hard error rather than the
	 * empty fragment that silently widens an AND filter.
	 */
	public function test_an_undeclared_operator_is_refused_before_the_provider_is_asked(): void {
		$registry = new Filter_Providers( License::unlimited(), $this->provider() );

		$this->expectException( Filter_Field_Unavailable::class );
		$this->expectExceptionMessageMatches( '/is not available for the field/' );

		$registry->field_for( new Condition( 'demo:colour', Operator::BETWEEN, array( 1, 2 ) ), Query_Scope::PRODUCT );
	}

	public function test_assert_supported_skips_core_keys_and_checks_the_rest(): void {
		$registry = new Filter_Providers( License::unlimited(), $this->provider() );

		// A filter of core keys alone asks the registry nothing.
		$registry->assert_supported(
			new Filter(
				array(
					new Condition( 'price', Operator::GREATER_THAN, 10 ),
					new Condition( 'meta:whatever', Operator::EQUALS, 'x' ),
				)
			)
		);

		$this->expectException( Filter_Field_Unavailable::class );

		$registry->assert_supported(
			new Filter(
				array(
					new Condition( 'price', Operator::GREATER_THAN, 10 ),
					new Condition( 'demo:missing', Operator::EQUALS, 'x' ),
				)
			)
		);
	}

	public function test_a_supported_condition_resolves_to_its_descriptor(): void {
		$registry = new Filter_Providers( License::unlimited(), $this->provider() );

		$field = $registry->field_for(
			new Condition( 'demo:colour', Operator::EQUALS, 'red' ),
			Query_Scope::PRODUCT
		);

		$this->assertSame( 'demo:colour', $field->key );
		$this->assertSame( Filter_Control::TEXT, $field->control );
	}

	/**
	 * A provider offering one text field on products only.
	 *
	 * @param list<string>            $claims Keys it claims.
	 * @param string                  $module Its licensed module, or '' for free.
	 * @param list<Filter_Field>|null $fields Descriptors, or null for the default one.
	 */
	private function provider( array $claims = array( 'demo:colour' ), string $module = 'acf', ?array $fields = null ): Filter_Provider {
		$default = array(
			new Filter_Field(
				'demo:colour',
				'Colour',
				Filter_Control::TEXT,
				array( Operator::EQUALS, Operator::NOT_EQUALS, Operator::IN ),
				array( Query_Scope::PRODUCT )
			),
		);

		return new class( $claims, $module, $fields ?? $default ) implements Filter_Provider {

			/**
			 * @param list<string>       $claims Keys it claims.
			 * @param string             $module Its licensed module.
			 * @param list<Filter_Field> $fields Descriptors.
			 */
			public function __construct(
				private readonly array $claims,
				private readonly string $module,
				private readonly array $fields
			) {}

			public function module(): string {
				return $this->module;
			}

			public function label(): string {
				return 'Test module';
			}

			public function filter_fields( ?string $language = null ): array {
				unset( $language );

				return $this->fields;
			}

			public function handles_filter( string $key ): bool {
				return in_array( $key, $this->claims, true );
			}

			public function storage_for( string $key, Query_Scope $scope ): Field_Storage {
				return Field_Storage::post_meta( 'demo_colour', Value_Kind::TEXT );
			}
		};
	}
}
