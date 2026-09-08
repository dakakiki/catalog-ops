<?php
/**
 * The choices an ACF select, radio or checkbox offers.
 *
 * @package CatalogOps\Modules\Acf
 */

namespace CatalogOps\Modules\Acf;

use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

/**
 * A module serving its own options, which is the whole point of
 * {@see \CatalogOps\Query\Fields\Filter_Field::$options_route}: the descriptor names
 * a route and the client fetches it, so the admin bundle learns nothing about ACF.
 *
 * It lives here rather than as another method on {@see \CatalogOps\Rest\Fields_Controller}
 * for the same reason. That controller serves the eight built-in controls and knows
 * what each of them means; teaching it a ninth thing that is only true when a third
 * plugin is installed would put a module's knowledge in core code and would have to
 * be undone for WPML, then again for whoever is next.
 *
 * The field key arrives in the query string because one route serves every choice
 * field. It is read as an opaque string and used only as a `WHERE post_name = %s`
 * operand — an unknown key gets an empty list, never an error, because the control it
 * feeds renders regardless and an empty picker says "nothing to choose" honestly.
 */
final class Acf_Options_Controller {

	/**
	 * The REST namespace the rest of the plugin uses.
	 */
	private const REST_NAMESPACE = 'catalogops/v1';

	/**
	 * The full path a descriptor points at, matching what `apiFetch` expects.
	 */
	public const ROUTE = '/catalogops/v1/fields/acf-options';

	/**
	 * Build the controller.
	 *
	 * @param Acf_Fields $fields The definition reader.
	 */
	public function __construct( private readonly Acf_Fields $fields ) {}

	/**
	 * Register the route.
	 */
	public function register_routes(): void {
		register_rest_route(
			self::REST_NAMESPACE,
			'/fields/acf-options',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( $this, 'options' ),
				'permission_callback' => array( $this, 'can_manage' ),
				'args'                => array(
					'field' => array(
						'type'              => 'string',
						'required'          => true,
						'sanitize_callback' => 'sanitize_text_field',
					),
				),
			)
		);
	}

	/**
	 * Whether the current user may use the filter at all — the same capability every
	 * other CatalogOps route checks, so this one cannot become the loose door.
	 */
	public function can_manage(): bool {
		return current_user_can( 'manage_woocommerce' );
	}

	/**
	 * The choices for one field, as the client's `{ id, name }` pairs.
	 *
	 * `id` carries ACF's stored value — the array *key* of the choices list, which is
	 * what a product's meta row holds — while `name` is the label the shop owner
	 * wrote. Sending the label as the id would build a condition that matches
	 * nothing: `co_origin` stores `us`, not `United States`.
	 *
	 * @param WP_REST_Request $request The request.
	 */
	public function options( WP_REST_Request $request ): WP_REST_Response {
		$field_key  = (string) $request->get_param( 'field' );
		$definition = '' === $field_key ? null : $this->fields->definition( $field_key );

		if ( null === $definition ) {
			return new WP_REST_Response( array( 'options' => array() ) );
		}

		$options = array();

		foreach ( $this->fields->choices( $definition ) as $value => $label ) {
			$options[] = array(
				'id'   => $value,
				'name' => '' === $label ? $value : $label,
			);
		}

		return new WP_REST_Response( array( 'options' => $options ) );
	}
}
