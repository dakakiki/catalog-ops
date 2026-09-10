<?php
/**
 * The choices an ACF select, radio or checkbox offers.
 *
 * @package CatalogOps\Modules\Acf
 */

namespace CatalogOps\Modules\Acf;

use CatalogOps\Rest\Language;
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
	/**
	 * The translations WPML holds for this site's ACF text.
	 *
	 * @var Acf_Strings
	 */
	private Acf_Strings $strings;

	/**
	 * Build the controller.
	 *
	 * @param Acf_Fields       $fields  The definition reader.
	 * @param Acf_Strings|null $strings The translation reader; the default reads
	 *                                  this site's own field groups.
	 */
	public function __construct( private readonly Acf_Fields $fields, ?Acf_Strings $strings = null ) {
		global $wpdb;

		$this->strings = $strings ?? new Acf_Strings( $wpdb );
	}

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
				) + Language::args(),
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
	 * **The name is translated and the id is never touched**, and the split is the
	 * whole point. ACFML registers each choice's label with WPML String Translation,
	 * so a shop that has translated "On sale" into Serbian should pick from a
	 * Serbian list — but what the condition carries, and what is frozen into
	 * filter_json for a cron tick to replay months later, has to stay `sale`. A
	 * translated id would be a filter that matched nothing, and matched nothing
	 * silently.
	 *
	 * @param WP_REST_Request $request The request.
	 */
	public function options( WP_REST_Request $request ): WP_REST_Response {
		$field_key  = (string) $request->get_param( 'field' );
		$definition = '' === $field_key ? null : $this->fields->definition( $field_key );

		if ( null === $definition ) {
			return new WP_REST_Response( array( 'options' => array() ) );
		}

		$language = Language::from_request( $request );
		$options  = array();

		foreach ( $this->fields->choices( $definition ) as $value => $label ) {
			$name = '' === $label ? (string) $value : (string) $label;

			$options[] = array(
				'id'   => $value,
				'name' => $this->strings->translate( $field_key, 'choices', $name, $language ),
			);
		}

		return new WP_REST_Response( array( 'options' => $options ) );
	}
}
