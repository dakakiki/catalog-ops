<?php
/**
 * The language a request is being made in, read off the request.
 *
 * @package CatalogOps\Rest
 */

namespace CatalogOps\Rest;

use WP_REST_Request;

/**
 * Under WPML the user's language frames everything this plugin does, and every
 * route that resolves a filter or lists past work needs to know which one. The
 * argument schema and the reading of it live here once, for the same reason
 * {@see Paging} exists: three controllers each parsing the same parameter is
 * three chances for one of them to parse it differently.
 *
 * **Why the client sends it, rather than the server asking WPML.** It looks
 * backwards, and it is not. WPML resolves a request's language through
 * {@see \SitePress::get_current_language()}, and its two guards on the value —
 * `is_valid_language()` and `WPML_Language_Resolution::filter_for_legal_langs()` —
 * both accept `all` **only** when `is_admin()` or WP-CLI is true. A REST request
 * is neither. So on the one setting where the user has asked to work across the
 * whole catalogue, a server-side read does not answer "all": it falls through to
 * the site's default language and quietly confines the run to English. That is a
 * wrong answer the user believes, which is the worst kind this pipeline can give.
 *
 * The admin page load is `is_admin()`, so it can see `all` and does. It puts the
 * answer in `catalogopsConfig` and the app sends it back with every request that
 * needs it — captured once, while the user is on screen, exactly as the language
 * on a saved filter is.
 *
 * The value is not checked against WPML's list of active languages here. An
 * unknown code cannot widen anything — the engine's INNER JOIN simply matches
 * nothing — and the shape below is strict enough that nothing but a language code
 * can arrive. What it means for a *schedule* to name a language the site no
 * longer has is a live question, and it is answered where it bites: in the
 * engine, which refuses outright when WPML is gone altogether.
 */
final class Language {

	/**
	 * The `language` argument for a route that works inside one.
	 *
	 * Optional with no default: a site without WPML sends nothing, and nothing is
	 * exactly the right answer for it. The pattern is WPML's own vocabulary — its
	 * `icl_translations.language_code` is `varchar(7)`, and its codes are ASCII
	 * letters, digits and hyphens (`en`, `sr`, `pt-br`, `zh-hans`).
	 *
	 * @return array<string, array<string, mixed>>
	 */
	public static function args(): array {
		return array(
			'language' => array(
				'type'    => 'string',
				'pattern' => '^[A-Za-z0-9_-]{1,7}$|^all$',
			),
		);
	}

	/**
	 * The language this request is working in, or null for the whole catalogue.
	 *
	 * `all` collapses to null here rather than being carried further, so that
	 * everything downstream has one spelling of "no confinement" to know about —
	 * the same normalisation {@see \CatalogOps\Query\Filter} performs on the way in.
	 *
	 * @param WP_REST_Request $request The request.
	 */
	public static function from_request( WP_REST_Request $request ): ?string {
		$language = $request->get_param( 'language' );

		if ( ! is_string( $language ) ) {
			return null;
		}

		$language = trim( $language );

		return ( '' === $language || 'all' === strtolower( $language ) ) ? null : $language;
	}
}
