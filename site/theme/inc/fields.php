<?php
/**
 * The editing screens, declared in PHP.
 *
 * @package CatalogOps_Theme
 */

defined( 'ABSPATH' ) || exit;

/**
 * A plain field.
 *
 * @param string $name         Field name the template asks for.
 * @param string $label        Admin label.
 * @param string $type         ACF field type.
 * @param string $instructions Help text.
 * @param int    $rows         Rows, for a textarea.
 * @return array<string, mixed>
 */
function catalogops_field( string $name, string $label, string $type = 'text', string $instructions = '', int $rows = 3 ): array {
	$field = array(
		'key'          => 'field_co_' . $name,
		'name'         => $name,
		'label'        => $label,
		'type'         => $type,
		'instructions' => $instructions,
	);

	if ( 'textarea' === $type ) {
		$field['rows'] = $rows;
	}

	if ( 'wysiwyg' === $type ) {
		$field['media_upload'] = 0;
		$field['toolbar']      = 'basic';
	}

	return $field;
}

/**
 * A tab, so one long screen reads as the page it edits.
 *
 * @param string $name  Unique name.
 * @param string $label Tab label.
 * @return array<string, mixed>
 */
function catalogops_tab( string $name, string $label ): array {
	return array(
		'key'       => 'field_co_tab_' . $name,
		'label'     => $label,
		'type'      => 'tab',
		'placement' => 'left',
	);
}

/**
 * A group of fields describing one repeated-looking thing.
 *
 * @param string                     $name       Group name.
 * @param string                     $label      Admin label.
 * @param array<string, array{0:string,1:string}> $sub_fields Name => [label, type].
 * @return array<string, mixed>
 */
function catalogops_group_field( string $name, string $label, array $sub_fields ): array {
	$fields = array();

	foreach ( $sub_fields as $sub_name => $spec ) {
		$fields[] = array(
			'key'          => 'field_co_' . $name . '_' . $sub_name,
			'name'         => $sub_name,
			'label'        => $spec[0],
			'type'         => $spec[1] ?? 'text',
			'instructions' => $spec[2] ?? '',
			'rows'         => 4,
		);
	}

	return array(
		'key'        => 'field_co_' . $name,
		'name'       => $name,
		'label'      => $label,
		'type'       => 'group',
		'layout'     => 'block',
		'sub_fields' => $fields,
	);
}

/**
 * Register the theme's field groups with ACF.
 *
 * **Declared here rather than drawn in the admin and exported.** A field group
 * created through ACF's own screen lives in the database: not in version
 * control, not in a deployment, and not on a colleague's copy until somebody
 * remembers to export it. Declared in the theme it arrives with the templates
 * that read it, and a field the templates stop using disappears in the same
 * commit.
 *
 * **The free ACF has no Repeater**, and the shape below is an argument rather
 * than a workaround. Everything whose count the layout fixes — three pricing
 * columns, three promises, five pipeline stages, four formula examples — is a
 * Group per item, because adding a fourth column is a change to the design and
 * ought to feel like one. The two lists that genuinely grow, the FAQ and the
 * compatibility table, are post types instead: see inc/post-types.php.
 *
 * **Every field is optional.** A page nobody has edited renders the copy the
 * site was designed with — see {@see catalogops_text()} — so an empty database
 * is a finished site rather than a page of empty headings.
 */
function catalogops_register_fields(): void {
	if ( ! function_exists( 'acf_add_local_field_group' ) ) {
		return;
	}

	$title_body = array(
		'title' => array( __( 'Heading', 'catalogops' ), 'text' ),
		'body'  => array( __( 'Text', 'catalogops' ), 'textarea' ),
	);

	$plan_fields = array(
		'name'     => array( __( 'Name', 'catalogops' ), 'text' ),
		'tag'      => array( __( 'Badge', 'catalogops' ), 'text', __( 'The small label beside the name, e.g. "Most shops". Leave empty for none.', 'catalogops' ) ),
		'price'    => array( __( 'Price', 'catalogops' ), 'text' ),
		'period'   => array( __( 'Period', 'catalogops' ), 'text' ),
		'features' => array( __( 'What it includes', 'catalogops' ), 'textarea', __( 'One per line. Begin a line with a minus to show it greyed out as something this plan does not have.', 'catalogops' ) ),
		'seats'    => array( __( 'Seat prices', 'catalogops' ), 'textarea', __( 'One per line, as "5 sites | $199". Leave empty for no table.', 'catalogops' ) ),
		'cta'      => array( __( 'Button text', 'catalogops' ), 'text' ),
		'url'      => array( __( 'Button link', 'catalogops' ), 'url', __( 'The checkout link for this plan. Leave empty on the free tier to use the download link.', 'catalogops' ) ),
		'note'     => array( __( 'Note under the button', 'catalogops' ), 'text' ),
	);

	$fields = array(

		catalogops_tab( 'hero', __( 'Hero', 'catalogops' ) ),
		catalogops_field( 'hero_eyebrow', __( 'Eyebrow', 'catalogops' ) ),
		catalogops_field( 'hero_heading', __( 'Headline', 'catalogops' ), 'textarea', __( 'Wrap the words that should take the accent colour in &lt;span&gt;…&lt;/span&gt;, and use &lt;br&gt; to break the line.', 'catalogops' ) ),
		catalogops_field( 'hero_lede', __( 'Opening paragraph', 'catalogops' ), 'textarea', '', 4 ),
		catalogops_field( 'hero_cta_primary', __( 'Main button', 'catalogops' ) ),
		catalogops_field( 'hero_cta_secondary', __( 'Second button', 'catalogops' ) ),
		catalogops_field( 'hero_cta_note', __( 'Note under the buttons', 'catalogops' ) ),

		catalogops_tab( 'panel', __( 'Preview card', 'catalogops' ) ),
		catalogops_group_field(
			'preview_panel',
			__( 'The card beside the headline', 'catalogops' ),
			array(
				'title'       => array( __( 'Card title', 'catalogops' ), 'text' ),
				'scope'       => array( __( 'Scope chip', 'catalogops' ), 'text' ),
				'count'       => array( __( 'The number', 'catalogops' ), 'text', __( 'Written as it should read, e.g. "1,204". The count-up animation is worked out from it.', 'catalogops' ) ),
				'caption'     => array( __( 'Caption after the number', 'catalogops' ), 'text' ),
				'criteria'    => array( __( 'Filter chips', 'catalogops' ), 'textarea', __( 'One per line.', 'catalogops' ) ),
				'formula'     => array( __( 'Formula chip', 'catalogops' ), 'text' ),
				'col_product' => array( __( 'Column: product', 'catalogops' ), 'text' ),
				'col_now'     => array( __( 'Column: now', 'catalogops' ), 'text' ),
				'col_after'   => array( __( 'Column: after', 'catalogops' ), 'text' ),
				'rows'        => array( __( 'Sample rows', 'catalogops' ), 'textarea', __( 'One per line, as "Product name | 24.00 | 20.99".', 'catalogops' ) ),
				'foot'        => array( __( 'Footer line', 'catalogops' ), 'text' ),
				'undo'        => array( __( 'Undo label', 'catalogops' ), 'text' ),
				'alt'         => array( __( 'Description for screen readers', 'catalogops' ), 'textarea', __( 'The card is one picture as far as assistive technology is concerned, so this sentence is what it reads out.', 'catalogops' ) ),
			)
		),

		catalogops_tab( 'problem', __( 'Problem', 'catalogops' ) ),
		// A textarea and not a WYSIWYG, and the reason is structural rather than
		// stylistic: this renders INSIDE a <p>, and ACF runs a WYSIWYG value
		// through wpautop on the way out — so the editor produced <p> inside <p>,
		// which browsers repair by closing the outer one early and taking the
		// styling with it. A WYSIWYG would also invite headings and lists into a
		// one-paragraph strip that has no room for them. Inline markup still
		// works: the value goes through wp_kses_post().
		catalogops_field( 'problem_text', __( 'The problem, in one paragraph', 'catalogops' ), 'textarea', __( 'One paragraph, shown in the red strip under the hero. Emphasis with &lt;strong&gt; is allowed.', 'catalogops' ), 4 ),

		catalogops_tab( 'pipeline', __( 'How it works', 'catalogops' ) ),
		catalogops_field( 'how_eyebrow', __( 'Eyebrow', 'catalogops' ) ),
		catalogops_field( 'how_heading', __( 'Heading', 'catalogops' ) ),
		catalogops_field( 'how_lede', __( 'Introduction', 'catalogops' ), 'textarea', '', 4 ),
		catalogops_group_field( 'stage_1', __( 'Stage 1', 'catalogops' ), $title_body ),
		catalogops_group_field( 'stage_2', __( 'Stage 2', 'catalogops' ), $title_body ),
		catalogops_group_field( 'stage_3', __( 'Stage 3', 'catalogops' ), $title_body ),
		catalogops_group_field( 'stage_4', __( 'Stage 4', 'catalogops' ), $title_body ),
		catalogops_group_field( 'stage_5', __( 'Stage 5', 'catalogops' ), $title_body ),

		catalogops_tab( 'promises', __( 'Promises', 'catalogops' ) ),
		catalogops_group_field( 'promise_1', __( 'Promise 1', 'catalogops' ), $title_body ),
		catalogops_group_field( 'promise_2', __( 'Promise 2', 'catalogops' ), $title_body ),
		catalogops_group_field( 'promise_3', __( 'Promise 3', 'catalogops' ), $title_body ),

		catalogops_tab( 'formulas', __( 'Formulas', 'catalogops' ) ),
		catalogops_field( 'formulas_eyebrow', __( 'Eyebrow', 'catalogops' ) ),
		catalogops_field( 'formulas_heading', __( 'Heading', 'catalogops' ) ),
		catalogops_field( 'formulas_lede', __( 'Introduction', 'catalogops' ), 'textarea', '', 4 ),
		catalogops_group_field( 'formula_1', __( 'Example 1', 'catalogops' ), array( 'code' => array( __( 'The rule', 'catalogops' ), 'text' ), 'body' => array( __( 'What it does', 'catalogops' ), 'textarea' ) ) ),
		catalogops_group_field( 'formula_2', __( 'Example 2', 'catalogops' ), array( 'code' => array( __( 'The rule', 'catalogops' ), 'text' ), 'body' => array( __( 'What it does', 'catalogops' ), 'textarea' ) ) ),
		catalogops_group_field( 'formula_3', __( 'Example 3', 'catalogops' ), array( 'code' => array( __( 'The rule', 'catalogops' ), 'text' ), 'body' => array( __( 'What it does', 'catalogops' ), 'textarea' ) ) ),
		catalogops_group_field( 'formula_4', __( 'Example 4', 'catalogops' ), array( 'code' => array( __( 'The rule', 'catalogops' ), 'text' ), 'body' => array( __( 'What it does', 'catalogops' ), 'textarea' ) ) ),

		catalogops_tab( 'scheduling', __( 'Scheduling', 'catalogops' ) ),
		catalogops_field( 'scheduling_eyebrow', __( 'Eyebrow', 'catalogops' ) ),
		catalogops_field( 'scheduling_heading', __( 'Heading', 'catalogops' ) ),
		catalogops_field( 'scheduling_lede', __( 'Introduction', 'catalogops' ), 'textarea', '', 4 ),
		catalogops_group_field( 'fact_1', __( 'Number 1', 'catalogops' ), array( 'figure' => array( __( 'The figure', 'catalogops' ), 'text', __( 'Markup is allowed here — this is where the count-up animation lives.', 'catalogops' ) ), 'caption' => array( __( 'Caption', 'catalogops' ), 'textarea' ) ) ),
		catalogops_group_field( 'fact_2', __( 'Number 2', 'catalogops' ), array( 'figure' => array( __( 'The figure', 'catalogops' ), 'text' ), 'caption' => array( __( 'Caption', 'catalogops' ), 'textarea' ) ) ),
		catalogops_group_field( 'fact_3', __( 'Number 3', 'catalogops' ), array( 'figure' => array( __( 'The figure', 'catalogops' ), 'text' ), 'caption' => array( __( 'Caption', 'catalogops' ), 'textarea' ) ) ),

		catalogops_tab( 'pricing', __( 'Pricing', 'catalogops' ) ),
		catalogops_field( 'pricing_eyebrow', __( 'Eyebrow', 'catalogops' ) ),
		catalogops_field( 'pricing_heading', __( 'Heading', 'catalogops' ) ),
		catalogops_field( 'pricing_lede', __( 'Introduction', 'catalogops' ), 'textarea', '', 4 ),
		catalogops_group_field( 'plan_free', __( 'Free plan', 'catalogops' ), $plan_fields ),
		catalogops_group_field( 'plan_solo', __( 'Solo plan', 'catalogops' ), $plan_fields ),
		catalogops_group_field( 'plan_studio', __( 'Studio plan', 'catalogops' ), $plan_fields ),
		catalogops_field( 'guarantee_text', __( 'Guarantee', 'catalogops' ), 'text', __( 'Markup is allowed; the shipped line puts the first sentence in bold.', 'catalogops' ) ),
		catalogops_field( 'guarantee_note', __( 'Guarantee note', 'catalogops' ) ),

		catalogops_tab( 'faq', __( 'FAQ', 'catalogops' ) ),
		catalogops_field( 'faq_eyebrow', __( 'Eyebrow', 'catalogops' ) ),
		catalogops_field( 'faq_heading', __( 'Heading', 'catalogops' ) ),
		array(
			'key'     => 'field_co_faq_msg',
			'label'   => __( 'The questions themselves', 'catalogops' ),
			'type'    => 'message',
			'message' => __( 'The questions and answers live under <strong>FAQ</strong> in the sidebar, one entry each, dragged into the order they should be read in. Until the first one is added, the page shows the questions it shipped with.', 'catalogops' ),
		),

		catalogops_tab( 'compatibility', __( 'Compatibility', 'catalogops' ) ),
		catalogops_field( 'compat_eyebrow', __( 'Eyebrow', 'catalogops' ) ),
		catalogops_field( 'compat_heading', __( 'Heading', 'catalogops' ) ),
		array(
			'key'     => 'field_co_compat_msg',
			'label'   => __( 'The rows themselves', 'catalogops' ),
			'type'    => 'message',
			'message' => __( 'The rows live under <strong>Compatibility</strong> in the sidebar — the title is the thing, the editor is what is said about it.', 'catalogops' ),
		),
	);

	acf_add_local_field_group(
		array(
			'key'             => 'group_co_home',
			'title'           => __( 'Landing page', 'catalogops' ),
			'location'        => array( array( array( 'param' => 'page_type', 'operator' => '==', 'value' => 'front_page' ) ) ),
			'menu_order'      => 0,
			'position'        => 'normal',
			'style'           => 'default',
			'label_placement' => 'top',
			'hide_on_screen'  => array( 'the_content' ),
			'description'     => __( 'Every field is optional. Leave one empty and the page shows the copy it was designed with.', 'catalogops' ),
			'fields'          => $fields,
		)
	);

	// ---- the legal page ---------------------------------------------------

	$documents = array(
		'privacy'  => __( 'Privacy Policy', 'catalogops' ),
		'cookies'  => __( 'Cookie Policy', 'catalogops' ),
		'terms'    => __( 'Terms of Service', 'catalogops' ),
		'refunds'  => __( 'Refund Policy', 'catalogops' ),
		'licence'  => __( 'Plugin Licence', 'catalogops' ),
	);

	$legal_fields = array(
		catalogops_tab( 'legal_head', __( 'Page head', 'catalogops' ) ),
		catalogops_field( 'legal_eyebrow', __( 'Eyebrow', 'catalogops' ) ),
		catalogops_field( 'legal_heading', __( 'Heading', 'catalogops' ) ),
		catalogops_field( 'legal_lede', __( 'Introduction', 'catalogops' ), 'textarea', '', 4 ),
		catalogops_field( 'legal_draft_note', __( 'The draft warning', 'catalogops' ), 'wysiwyg', __( 'The amber box under the introduction. Empty it to remove the box entirely — which is what to do once a lawyer has read these.', 'catalogops' ) ),
	);

	foreach ( $documents as $slug => $label ) {
		$legal_fields[] = catalogops_tab( 'legal_' . $slug, $label );
		$legal_fields[] = catalogops_field( 'legal_' . $slug . '_title', __( 'Title', 'catalogops' ) );
		$legal_fields[] = catalogops_field( 'legal_' . $slug . '_updated', __( 'Last updated', 'catalogops' ) );
		$legal_fields[] = catalogops_field(
			'legal_' . $slug . '_body',
			__( 'The document', 'catalogops' ),
			'wysiwyg',
			__( '<strong>Leave this empty to keep the shipped text.</strong> Anything written here replaces that document entirely — which is what to do once these have been through a lawyer.', 'catalogops' )
		);
	}

	acf_add_local_field_group(
		array(
			'key'             => 'group_co_legal',
			'title'           => __( 'Legal documents', 'catalogops' ),
			'location'        => array( array( array( 'param' => 'page', 'operator' => '==', 'value' => catalogops_page_id( 'legal' ) ) ) ),
			'menu_order'      => 0,
			'position'        => 'normal',
			'label_placement' => 'top',
			'hide_on_screen'  => array( 'the_content' ),
			'description'     => __( 'Each document keeps its shipped text until you write something here.', 'catalogops' ),
			'fields'          => $legal_fields,
		)
	);

	// ---- the contact page -------------------------------------------------

	acf_add_local_field_group(
		array(
			'key'             => 'group_co_contact',
			'title'           => __( 'Contact page', 'catalogops' ),
			'location'        => array( array( array( 'param' => 'page', 'operator' => '==', 'value' => catalogops_page_id( 'contact' ) ) ) ),
			'menu_order'      => 0,
			'position'        => 'normal',
			'label_placement' => 'top',
			'hide_on_screen'  => array( 'the_content' ),
			'fields'          => array(
				catalogops_tab( 'contact_head', __( 'Page head', 'catalogops' ) ),
				catalogops_field( 'contact_eyebrow', __( 'Eyebrow', 'catalogops' ) ),
				catalogops_field( 'contact_heading', __( 'Heading', 'catalogops' ) ),
				catalogops_field( 'contact_lede', __( 'Introduction', 'catalogops' ), 'textarea', '', 4 ),

				catalogops_tab( 'contact_form', __( 'Form', 'catalogops' ) ),
				catalogops_field( 'contact_submit', __( 'Button text', 'catalogops' ) ),
				// Textarea for the same reason as problem_text: it renders inside a <p>.
				catalogops_field( 'contact_privacy_note', __( 'Note under the button', 'catalogops' ), 'textarea', __( 'One paragraph. Write %s where the link to the Privacy Policy should go.', 'catalogops' ), 4 ),

				catalogops_tab( 'contact_aside', __( 'Beside the form', 'catalogops' ) ),
				catalogops_group_field( 'aside_1', __( 'First panel', 'catalogops' ), array( 'kicker' => array( __( 'Kicker', 'catalogops' ), 'text' ), 'title' => array( __( 'Heading', 'catalogops' ), 'text' ), 'body' => array( __( 'Text', 'catalogops' ), 'textarea' ) ) ),
				catalogops_group_field( 'aside_2', __( 'Second panel', 'catalogops' ), array( 'kicker' => array( __( 'Kicker', 'catalogops' ), 'text' ), 'title' => array( __( 'Heading', 'catalogops' ), 'text' ), 'body' => array( __( 'Text', 'catalogops' ), 'textarea' ) ) ),
				catalogops_group_field( 'aside_3', __( 'Third panel', 'catalogops' ), array( 'kicker' => array( __( 'Kicker', 'catalogops' ), 'text' ), 'title' => array( __( 'Heading', 'catalogops' ), 'text' ), 'body' => array( __( 'Text', 'catalogops' ), 'textarea' ) ) ),
			),
		)
	);
}
add_action( 'acf/init', 'catalogops_register_fields' );

/**
 * The id of one of the site's own pages, for an ACF location rule.
 *
 * ACF's `page` rule wants an id, and the theme knows its pages by slug — so this
 * is the one place the two meet. Zero when the page does not exist yet, which
 * simply means the group attaches to nothing rather than to everything.
 *
 * @param string $slug Page slug.
 */
function catalogops_page_id( string $slug ): int {
	$page = get_page_by_path( $slug );

	return $page instanceof WP_Post ? (int) $page->ID : 0;
}
