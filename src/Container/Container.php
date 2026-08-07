<?php
/**
 * Minimal dependency-injection container.
 *
 * @package CatalogOps\Container
 */

namespace CatalogOps\Container;

use Closure;
use RuntimeException;

/**
 * A small service container: bind factories, resolve them lazily, and cache
 * shared services. Deliberately tiny — no autowiring, no reflection. Services
 * declare their own dependencies inside their factory closures.
 */
final class Container {

	/**
	 * Registered factory closures, keyed by service id.
	 *
	 * @var array<string, Closure>
	 */
	private array $factories = array();

	/**
	 * Whether a given id resolves to a single shared instance.
	 *
	 * @var array<string, bool>
	 */
	private array $shared = array();

	/**
	 * Resolved shared instances and pre-built objects, keyed by service id.
	 *
	 * @var array<string, mixed>
	 */
	private array $instances = array();

	/**
	 * Register a factory that returns a new instance on every resolution.
	 *
	 * @param string  $id      Service identifier.
	 * @param Closure $factory Receives the container, returns the service.
	 */
	public function bind( string $id, Closure $factory ): void {
		$this->factories[ $id ] = $factory;
		$this->shared[ $id ]    = false;
		unset( $this->instances[ $id ] );
	}

	/**
	 * Register a factory whose result is built once and reused.
	 *
	 * @param string  $id      Service identifier.
	 * @param Closure $factory Receives the container, returns the service.
	 */
	public function singleton( string $id, Closure $factory ): void {
		$this->factories[ $id ] = $factory;
		$this->shared[ $id ]    = true;
		unset( $this->instances[ $id ] );
	}

	/**
	 * Register an already-built object as a shared instance.
	 *
	 * @param string $id       Service identifier.
	 * @param mixed  $instance The object to store.
	 */
	public function instance( string $id, mixed $instance ): void {
		$this->instances[ $id ] = $instance;
		$this->shared[ $id ]    = true;
	}

	/**
	 * Whether a service id is known to the container.
	 *
	 * @param string $id Service identifier.
	 */
	public function has( string $id ): bool {
		return isset( $this->factories[ $id ] ) || array_key_exists( $id, $this->instances );
	}

	/**
	 * Resolve a service by id.
	 *
	 * @param string $id Service identifier.
	 * @return mixed The resolved service.
	 *
	 * @throws RuntimeException When no binding is registered for the id.
	 */
	public function get( string $id ): mixed {
		if ( array_key_exists( $id, $this->instances ) ) {
			return $this->instances[ $id ];
		}

		if ( ! isset( $this->factories[ $id ] ) ) {
			$message = sprintf( 'CatalogOps container: no binding registered for "%s".', $id );
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Developer-facing message, never rendered as web output.
			throw new RuntimeException( $message );
		}

		$object = ( $this->factories[ $id ] )( $this );

		if ( $this->shared[ $id ] ) {
			$this->instances[ $id ] = $object;
		}

		return $object;
	}
}
