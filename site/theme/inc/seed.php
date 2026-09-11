<?php
/**
 * Putting the shipped copy into the database.
 *
 * @package CatalogOps_Theme
 */

defined( 'ABSPATH' ) || exit;

/**
 * Fill every empty field on the three pages with the copy the templates ship.
 *
 * **Why this exists.** Until it is run, every field in the editor is blank and
 * the site's words live in the templates — which is fine for rendering and
 * useless for editing: somebody opening the Landing page sees forty-six empty
 * boxes and no way to tell what any of them currently says. Seeding turns the
 * shipped copy into content, so the editor shows the real text and changing a
 * line is an edit rather than a deployment.
 *
 * **It records rather than retypes, and that is the point.** The copy is not
 * duplicated here: the pages are rendered with a recorder switched on
 * ({@see catalogops_record_default()}), and whatever the templates asked for is
 * exactly what is written. Half of it could not be transcribed reliably anyway —
 * the stages, the plans and the preview card are PHP arrays the templates loop
 * over, so only the call itself knows which key received which line.
 *
 * **Nothing already written is touched** unless `$overwrite` is true. Running it
 * twice is safe, and running it after adding a section fills in the new fields
 * and leaves the edited ones alone.
 *
 * @param bool $overwrite Replace values that are already there.
 * @return array<string, int> What it did, per page.
 */
function catalogops_seed_content( bool $overwrite = false ): array {
	if ( ! catalogops_has_acf() ) {
		return array( 'error' => 0 );
	}

	$pages = array(
		'home'    => array( (int) get_option( 'page_on_front' ), 'front-page.php' ),
		'legal'   => array( catalogops_page_id( 'legal' ), 'page-legal.php' ),
		'contact' => array( catalogops_page_id( 'contact' ), 'page-contact.php' ),
	);

	$done = array();

	foreach ( $pages as $label => list( $page_id, $template ) ) {
		if ( 0 === $page_id ) {
			$done[ $label ] = 0;
			continue;
		}

		list( $defaults, $html ) = catalogops_render_with_recorder( $page_id, $template );

		$done[ $label ] = catalogops_write_defaults( $page_id, $defaults, $overwrite );

		if ( 'home' === $label ) {
			$done['faq']    = catalogops_seed_entries( 'co_faq', catalogops_shipped_questions( $html ) );
			$done['compat'] = catalogops_seed_entries( 'co_compat', catalogops_shipped_compat( $html ) );
		}
	}

	return $done;
}

/**
 * Render one page's template in this process, with the recorder listening.
 *
 * In this process rather than over HTTP, because the recorder lives in memory —
 * a request to the site would do its recording in a process this one never sees.
 *
 * @param int    $page_id  Page to render.
 * @param string $template Template file, relative to the theme.
 * @return array{0: array<string, string>, 1: string} The defaults, and the HTML.
 */
function catalogops_render_with_recorder( int $page_id, string $template ): array {
	global $wp_query, $post;

	$saved_query = $wp_query;
	$saved_post  = $post;

	$GLOBALS['catalogops_recorded_defaults'] = array();

	$wp_query = new WP_Query( array( 'page_id' => $page_id ) ); // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited
	$wp_query->the_post();

	ob_start();
	include get_template_directory() . '/' . $template;
	$html = (string) ob_get_clean();

	$defaults = $GLOBALS['catalogops_recorded_defaults'];
	unset( $GLOBALS['catalogops_recorded_defaults'] );

	wp_reset_postdata();

	$wp_query = $saved_query; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited
	$post     = $saved_post;  // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited

	return array( $defaults, $html );
}

/**
 * Write recorded defaults into a page's fields.
 *
 * A name carrying a slash belongs to a group, and a group has to be written
 * whole — ACF stores it as one value, so writing `plan_solo/price` on its own
 * would replace the plan with a price. They are gathered first and written once.
 *
 * @param int                   $page_id   Page to write to.
 * @param array<string, string> $defaults  Recorded field => shipped copy.
 * @param bool                  $overwrite Replace values already there.
 * @return int How many fields were written.
 */
function catalogops_write_defaults( int $page_id, array $defaults, bool $overwrite ): int {
	$written = 0;
	$groups  = array();

	foreach ( $defaults as $name => $value ) {
		// A field whose shipped copy is empty has nothing to seed. The legal
		// documents' bodies are exactly this: they are overrides, and empty is
		// what "keep the shipped text" looks like. Writing an empty string into
		// them would leave them empty and report itself as work.
		if ( '' === trim( $value ) ) {
			continue;
		}

		if ( str_contains( $name, '/' ) ) {
			list( $group, $key )      = explode( '/', $name, 2 );
			$groups[ $group ][ $key ] = $value;
			continue;
		}

		$current = get_field( $name, $page_id );

		if ( $overwrite || ! is_string( $current ) || '' === trim( $current ) ) {
			update_field( $name, $value, $page_id );
			$written++;
		}
	}

	foreach ( $groups as $group => $values ) {
		$current = get_field( $group, $page_id );
		$changed = 0;

		if ( ! $overwrite && is_array( $current ) ) {
			// Keep anything already written; fill only the empty keys.
			foreach ( $values as $key => $value ) {
				if ( isset( $current[ $key ] ) && is_string( $current[ $key ] ) && '' !== trim( $current[ $key ] ) ) {
					$values[ $key ] = $current[ $key ];
					continue;
				}

				$changed++;
			}
		} else {
			$changed = count( $values );
		}

		// Only write when something would actually change. A group is stored as
		// one value, so rewriting it with what it already holds is a database
		// write that reports itself as work and is not — and a count that says
		// seventy on a run that did nothing is a count nobody can trust again.
		if ( $changed > 0 ) {
			update_field( $group, $values, $page_id );
			$written += $changed;
		}
	}

	return $written;
}

/**
 * The questions the landing page ships with, read out of its own markup.
 *
 * @param string $html The rendered landing page.
 * @return list<array{0: string, 1: string}> Title and answer.
 */
function catalogops_shipped_questions( string $html ): array {
	preg_match_all(
		'#<details>\s*<summary>(.*?)</summary>\s*<div class="faq-body"><div class="faq-body-inner">(.*?)</div></div>\s*</details>#s',
		$html,
		$matches,
		PREG_SET_ORDER
	);

	return array_map(
		static fn( array $m ): array => array( trim( wp_strip_all_tags( $m[1] ) ), trim( $m[2] ) ),
		$matches
	);
}

/**
 * The compatibility rows the landing page ships with.
 *
 * @param string $html The rendered landing page.
 * @return list<array{0: string, 1: string}> Term and description.
 */
function catalogops_shipped_compat( string $html ): array {
	preg_match_all(
		'#<div class="compat-row">\s*<dt>(.*?)</dt>\s*<dd>(.*?)</dd>\s*</div>#s',
		$html,
		$matches,
		PREG_SET_ORDER
	);

	return array_map(
		static fn( array $m ): array => array( trim( wp_strip_all_tags( $m[1] ) ), trim( $m[2] ) ),
		$matches
	);
}

/**
 * Create the entries of a list post type, in order, if it has none.
 *
 * Refuses to add to a list that already has something in it: these are the
 * shipped items, and a second run must not put a duplicate set beside whatever
 * the shop has since written.
 *
 * @param string                            $post_type `co_faq` or `co_compat`.
 * @param list<array{0: string, 1: string}> $entries   Title and body.
 * @return int How many were created.
 */
function catalogops_seed_entries( string $post_type, array $entries ): int {
	if ( array() !== catalogops_entries( $post_type ) ) {
		return 0;
	}

	$order = 0;

	foreach ( $entries as list( $title, $body ) ) {
		$order++;

		wp_insert_post(
			array(
				'post_type'    => $post_type,
				'post_status'  => 'publish',
				'post_title'   => $title,
				'post_content' => $body,
				'menu_order'   => $order,
			)
		);
	}

	return $order;
}

if ( defined( 'WP_CLI' ) && WP_CLI ) {
	/**
	 * Put the shipped copy into the database.
	 *
	 * ## OPTIONS
	 *
	 * [--overwrite]
	 * : Replace values that have already been written.
	 *
	 * @param array<int, string>    $args       Positional arguments.
	 * @param array<string, string> $assoc_args Flags.
	 */
	WP_CLI::add_command(
		'catalogops seed',
		static function ( array $args, array $assoc_args ): void {
			unset( $args );

			$done = catalogops_seed_content( isset( $assoc_args['overwrite'] ) );

			foreach ( $done as $what => $count ) {
				WP_CLI::log( sprintf( '  %-8s %d', $what, $count ) );
			}

			WP_CLI::success( 'Seeded.' );
		}
	);
}
