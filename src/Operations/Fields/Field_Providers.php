<?php
/**
 * Registry that routes a field key to the provider that owns it.
 *
 * @package CatalogOps\Operations\Fields
 */

namespace CatalogOps\Operations\Fields;

/**
 * Holds the registered {@see Field_Provider}s and resolves a field key to the
 * first one that claims it. Core providers register at boot; M7 module providers
 * (ACF, WPML, brands) append here through a filter without the runner knowing
 * they exist.
 */
final class Field_Providers {

	/**
	 * Registered providers, in resolution order.
	 *
	 * @var list<Field_Provider>
	 */
	private array $providers;

	/**
	 * Build the registry from an ordered list of providers.
	 *
	 * @param Field_Provider ...$providers Providers, tried in the given order.
	 */
	public function __construct( Field_Provider ...$providers ) {
		$this->providers = array_values( $providers );
	}

	/**
	 * The provider that handles a field key, or null when none does.
	 *
	 * @param string $key Field key.
	 */
	public function for( string $key ): ?Field_Provider {
		foreach ( $this->providers as $provider ) {
			if ( $provider->handles( $key ) ) {
				return $provider;
			}
		}

		return null;
	}

	/**
	 * Whether some provider handles the key.
	 *
	 * @param string $key Field key.
	 */
	public function handles( string $key ): bool {
		return null !== $this->for( $key );
	}

	/**
	 * Every editable field advertised across all providers, for the UI.
	 *
	 * @return list<array{key: string, label: string, type: string}>
	 */
	public function all_fields(): array {
		$fields = array();

		foreach ( $this->providers as $provider ) {
			foreach ( $provider->get_fields() as $field ) {
				$fields[] = $field;
			}
		}

		return $fields;
	}
}
