<?php
/**
 * (De)serialization of actions to and from actions_json.
 *
 * @package CatalogOps\Operations\Actions
 */

namespace CatalogOps\Operations\Actions;

use InvalidArgumentException;

/**
 * Rebuilds {@see Action} objects from the persisted array shape and back. Every
 * action type registers a single `type` token here; unknown or malformed entries
 * are rejected rather than silently dropped, so a corrupt actions_json fails
 * loudly instead of running a half-empty operation.
 */
final class Action_Factory {

	/**
	 * Rebuild a single action from its array form.
	 *
	 * @param array<string, mixed> $data Serialized action.
	 *
	 * @throws InvalidArgumentException When the type is unknown or the field is empty.
	 */
	public static function from_array( array $data ): Action {
		$type  = (string) ( $data['type'] ?? '' );
		$field = (string) ( $data['field'] ?? '' );

		if ( '' === $field ) {
			throw new InvalidArgumentException( 'An action must name a field.' );
		}

		switch ( $type ) {
			case Set_Value::TYPE:
				return new Set_Value( $field, $data['value'] ?? null );
			default:
				// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Developer-facing message, never rendered as web output.
				throw new InvalidArgumentException( sprintf( 'Unknown action type "%s".', $type ) );
		}
	}

	/**
	 * Rebuild a list of actions from actions_json's decoded array.
	 *
	 * @param array<int, array<string, mixed>> $items Serialized actions.
	 * @return list<Action>
	 *
	 * @throws InvalidArgumentException When the list is empty or any entry is invalid.
	 */
	public static function list_from_array( array $items ): array {
		if ( array() === $items ) {
			throw new InvalidArgumentException( 'An operation needs at least one action.' );
		}

		return array_map(
			static fn( array $item ): Action => self::from_array( $item ),
			array_values( $items )
		);
	}

	/**
	 * Serialize a list of actions to the actions_json shape.
	 *
	 * @param Action[] $actions Actions to serialize.
	 * @return list<array<string, mixed>>
	 */
	public static function list_to_array( array $actions ): array {
		return array_map(
			static fn( Action $action ): array => $action->to_array(),
			array_values( $actions )
		);
	}
}
