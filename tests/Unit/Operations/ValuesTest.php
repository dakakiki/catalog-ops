<?php
/**
 * Unit tests for value normalization and the drift comparator.
 *
 * @package CatalogOps\Tests\Unit\Operations
 */

namespace CatalogOps\Tests\Unit\Operations;

use CatalogOps\Operations\Values;
use PHPUnit\Framework\TestCase;

/**
 * @covers \CatalogOps\Operations\Values
 */
final class ValuesTest extends TestCase {

	public function test_scalars_and_null_normalize_to_their_stored_form(): void {
		$this->assertNull( Values::to_string( null ) );
		$this->assertSame( '9.99', Values::to_string( '9.99' ) );
		$this->assertSame( '5', Values::to_string( 5 ) );
		$this->assertSame( '', Values::to_string( '' ) );
	}

	public function test_numeric_values_compare_by_value_not_formatting(): void {
		// The core drift case from CONTEXT §3: same price, different formatting.
		$this->assertTrue( Values::equal( '19.90', '19.9' ) );
		$this->assertTrue( Values::equal( '19.9', '19.90' ) );
		$this->assertTrue( Values::equal( '5', '5.00' ) );
		$this->assertTrue( Values::equal( '0', '0.0' ) );
		$this->assertTrue( Values::equal( '10.00', '10' ) );
	}

	public function test_different_numeric_values_are_not_equal(): void {
		$this->assertFalse( Values::equal( '19.90', '19.99' ) );
		$this->assertFalse( Values::equal( '5', '6' ) );
	}

	public function test_null_equals_only_null_and_is_distinct_from_empty_string(): void {
		$this->assertTrue( Values::equal( null, null ) );
		$this->assertFalse( Values::equal( null, '' ) );
		$this->assertFalse( Values::equal( '', null ) );
		$this->assertFalse( Values::equal( null, '0' ) );
	}

	public function test_non_numeric_values_compare_as_exact_strings(): void {
		$this->assertTrue( Values::equal( 'instock', 'instock' ) );
		$this->assertFalse( Values::equal( 'instock', 'outofstock' ) );
		$this->assertFalse( Values::equal( 'Acme', 'acme' ) );
		$this->assertTrue( Values::equal( '', '' ) );
	}

	public function test_a_numeric_string_and_a_non_numeric_string_are_not_equal(): void {
		$this->assertFalse( Values::equal( '5', 'five' ) );
		$this->assertFalse( Values::equal( 'instock', '0' ) );
	}
}
