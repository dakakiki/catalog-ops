<?php
/**
 * What language the admin is working in, asked of WPML at page load.
 *
 * @package CatalogOps\Admin
 */

namespace CatalogOps\Admin;

/**
 * Reads the one thing this plugin needs from WPML: which language the user is
 * looking at the catalogue in.
 *
 * **This is not a module and there is no WPML module.** Nothing here registers a
 * filter field, nothing is offered to the user to choose. The language is the
 * frame the whole plugin works inside — filter, results, bulk edit, schedules,
 * history — and a screen that asked "which language would you like to change?"
 * while the user was standing in one would be asking a question it can already
 * answer.
 *
 * **It is read here and only here, and that is the load-bearing decision.** WPML
 * resolves a request's language through {@see \SitePress::get_current_language()},
 * and both of its guards on the answer — `SitePress::is_valid_language()` and
 * `WPML_Language_Resolution::filter_for_legal_langs()` — accept `all` only when
 * `is_admin()` or WP-CLI is true. A REST request is neither. So asking WPML from
 * inside our own REST route would, on the one setting that means "work across the
 * whole catalogue", quietly answer with the site's default language instead and
 * confine the run to English. An admin page load is `is_admin()`, sees `all`, and
 * can say so.
 *
 * That is why the answer is localised into `catalogopsConfig` and travels back
 * with each request: captured once, while the user is on screen, exactly as it is
 * captured into a saved filter or a schedule for a cron tick to replay later.
 *
 * The calls here are deliberately confined to this class because they are not
 * pure. {@see \SitePress::get_active_languages()} branches on the current
 * language and parses `$_SERVER['QUERY_STRING']`, so it can answer differently to
 * an admin screen, a REST preview and a cron tick. That is harmless in the one
 * place a human is looking at the result and fatal anywhere a frozen filter is
 * replayed — which is why the engine reads `icl_translations` and nothing else.
 *
 * Everything is reached through WPML's documented filters rather than the
 * SitePress class, so a site without WPML simply gets no listener and the
 * defaults below.
 */
final class Wpml_Context {

	/**
	 * WPML's own name for "every language at once".
	 */
	private const ALL = 'all';

	/**
	 * Whether WPML is answering on this request.
	 *
	 * Asked by seeing whether anything answered `wpml_current_language` rather than
	 * by testing a constant or a class name: the filter is WPML's published
	 * interface, and it is the same thing this class reads for the answer itself.
	 */
	public function is_active(): bool {
		return null !== apply_filters( 'wpml_current_language', null );
	}

	/**
	 * The language the user is working in, or null for every language.
	 *
	 * Null is returned both when WPML is absent and when the user is on "All
	 * languages", because they are the same instruction to this plugin: place no
	 * constraint. Everything downstream — the filter, the engine, the history —
	 * knows one spelling of that.
	 */
	public function current_code(): ?string {
		$code = apply_filters( 'wpml_current_language', null );

		if ( ! is_string( $code ) ) {
			return null;
		}

		$code = trim( $code );

		return ( '' === $code || self::ALL === strtolower( $code ) ) ? null : $code;
	}

	/**
	 * What to call the current language on screen.
	 *
	 * The language's own name for itself (`Srpski`, not `Serbian`), because that is
	 * what WPML's own switcher shows two menus away and a user should not have to
	 * work out that the two are the same language. Falls back to the translated
	 * name, then to the bare code, so an indicator never renders empty.
	 *
	 * Returns null when there is nothing to label — no WPML at all. On "All
	 * languages" it returns null too, and the app supplies its own wording: that
	 * string belongs to this plugin's vocabulary, and taking WPML's would leave it
	 * untranslated in our own text domain.
	 */
	public function current_label(): ?string {
		$code = $this->current_code();

		if ( null === $code ) {
			return null;
		}

		$languages = apply_filters( 'wpml_active_languages', null );

		if ( ! is_array( $languages ) || ! isset( $languages[ $code ] ) ) {
			return $code;
		}

		$language = (array) $languages[ $code ];

		foreach ( array( 'native_name', 'display_name', 'translated_name' ) as $key ) {
			if ( ! empty( $language[ $key ] ) && is_string( $language[ $key ] ) ) {
				return $language[ $key ];
			}
		}

		return $code;
	}

	/**
	 * The same term, seen from another language.
	 *
	 * WPML translates taxonomy terms as well as posts, and a translated term is a
	 * **different row with a different id**: on the development catalogue
	 * Accessories is term 18 in English and 73 in Serbian, with the same name. So a
	 * category picker built in one language hands the filter ids that no product in
	 * the other language carries, and the run returns nothing — which reads as an
	 * over-narrow filter rather than as a mismatch, because nothing about it looks
	 * wrong. Measured: `category IN (18, 16, 26)` is 4,596 products unconstrained,
	 * 4,594 in English and **0** in Serbian.
	 *
	 * **`wpml_object_id`, never `wpml_switch_language`.** The switch action calls
	 * `SitePress::switch_lang( $code, true )`, whose second argument writes the
	 * user's language cookie — so a throw anywhere between the switch and the
	 * restore would leave the admin in a language they did not choose, on a REST
	 * request they cannot see. This filter changes nothing and returns an answer.
	 *
	 * **A term with no translation answers null, and is meant to.** The alternative
	 * — WPML's `$return_original_if_missing`, which hands back the original — would
	 * put an English category into a Serbian list, where it labels nothing and
	 * matches nothing. The owner's rule is that each language shows its own
	 * catalogue, and that a term nobody has translated is the shop's business
	 * rather than something for this plugin to paper over. So the list a language
	 * gets is that language's list, and a gap in it is visible as a gap.
	 *
	 * @param int         $term_id  The term as this request knows it.
	 * @param string      $taxonomy Its taxonomy.
	 * @param string|null $language Target language, or null to change nothing.
	 * @return int|null The term in that language, or null when there is none.
	 */
	public function term_in_language( int $term_id, string $taxonomy, ?string $language ): ?int {
		if ( null === $language || ! $this->is_active() ) {
			return $term_id;
		}

		// A taxonomy WPML does not translate has ONE set of terms, shared by every
		// language, and there is nothing to map — the term already is the answer.
		// Asking anyway would be worse than pointless: `wpml_object_id` has no
		// translation to find, so with the strictness below it answers null and the
		// whole control empties. WooCommerce's own `product_brand` is exactly this
		// case on a default install, and a brand is the same word in every language
		// anyway.
		if ( true !== apply_filters( 'wpml_is_translated_taxonomy', null, $taxonomy ) ) {
			return $term_id;
		}

		$translated = apply_filters( 'wpml_object_id', $term_id, $taxonomy, false, $language );

		return is_numeric( $translated ) ? (int) $translated : null;
	}

	/**
	 * The whole answer, in the shape the React app is handed.
	 *
	 * Null when WPML is not active, which is the app's signal to send no language
	 * and draw no indicator — a shop with one language must not learn that it has
	 * one.
	 *
	 * @return array{code: string|null, label: string|null}|null
	 */
	public function to_array(): ?array {
		if ( ! $this->is_active() ) {
			return null;
		}

		return array(
			// Null here says "All languages" as clearly as it says "no WPML" does
			// everywhere else, and the app tells the two apart by whether this whole
			// structure is present.
			'code'  => $this->current_code(),
			'label' => $this->current_label(),
		);
	}
}
