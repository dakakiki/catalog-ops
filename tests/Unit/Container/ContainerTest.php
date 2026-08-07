<?php
/**
 * Unit tests for the service container.
 *
 * @package CatalogOps\Tests\Unit\Container
 */

namespace CatalogOps\Tests\Unit\Container;

use CatalogOps\Container\Container;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use stdClass;

/**
 * @covers \CatalogOps\Container\Container
 */
final class ContainerTest extends TestCase {

	private Container $container;

	protected function setUp(): void {
		parent::setUp();
		$this->container = new Container();
	}

	public function test_bind_returns_a_new_instance_each_time(): void {
		$this->container->bind( 'thing', static fn() => new stdClass() );

		$first  = $this->container->get( 'thing' );
		$second = $this->container->get( 'thing' );

		$this->assertInstanceOf( stdClass::class, $first );
		$this->assertNotSame( $first, $second );
	}

	public function test_singleton_returns_the_same_instance(): void {
		$this->container->singleton( 'thing', static fn() => new stdClass() );

		$this->assertSame(
			$this->container->get( 'thing' ),
			$this->container->get( 'thing' )
		);
	}

	public function test_singleton_factory_runs_only_once(): void {
		$calls = 0;
		$this->container->singleton(
			'thing',
			static function () use ( &$calls ) {
				++$calls;
				return new stdClass();
			}
		);

		$this->container->get( 'thing' );
		$this->container->get( 'thing' );

		$this->assertSame( 1, $calls );
	}

	public function test_instance_stores_a_prebuilt_object(): void {
		$object = new stdClass();
		$object->marker = 'kept';

		$this->container->instance( 'thing', $object );

		$this->assertSame( $object, $this->container->get( 'thing' ) );
	}

	public function test_factory_receives_the_container(): void {
		$this->container->instance( 'dependency', 'value' );
		$this->container->bind(
			'consumer',
			static fn( Container $c ) => $c->get( 'dependency' )
		);

		$this->assertSame( 'value', $this->container->get( 'consumer' ) );
	}

	public function test_has_reports_known_and_unknown_ids(): void {
		$this->container->bind( 'bound', static fn() => new stdClass() );
		$this->container->instance( 'prebuilt', new stdClass() );

		$this->assertTrue( $this->container->has( 'bound' ) );
		$this->assertTrue( $this->container->has( 'prebuilt' ) );
		$this->assertFalse( $this->container->has( 'missing' ) );
	}

	public function test_get_throws_for_unknown_id(): void {
		$this->expectException( RuntimeException::class );
		$this->expectExceptionMessage( 'missing' );

		$this->container->get( 'missing' );
	}

	public function test_rebinding_replaces_a_previous_binding(): void {
		$this->container->singleton( 'thing', static fn() => 'first' );
		$this->container->get( 'thing' );

		$this->container->singleton( 'thing', static fn() => 'second' );

		$this->assertSame( 'second', $this->container->get( 'thing' ) );
	}
}
