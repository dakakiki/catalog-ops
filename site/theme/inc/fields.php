<?php
/**
 * Where the editing screens come from, and why they are shaped the way they are.
 *
 * @package CatalogOps_Theme
 */

defined( 'ABSPATH' ) || exit;

/*
 * ---------------------------------------------------------------------------
 * The field groups live in `acf-json/`, and this file only explains them.
 * ---------------------------------------------------------------------------
 *
 * There is no registration code here any more, and no configuration either:
 * ACF already saves to and loads from `get_stylesheet_directory() . '/acf-json'`
 * by default (local-json.php:47), which is this theme. Three files, one per
 * group - `group_co_home`, `group_co_legal`, `group_co_contact`.
 *
 * **Why JSON rather than the PHP that was here first.** Both keep the
 * definitions in version control, which was the whole reason for not drawing
 * them in the admin. But a PHP-registered group is invisible in ACF > Field
 * Groups, and not by omission: `ACF_Admin_Internal_Post_Type_List::setup_sync()`
 * skips anything whose `local` is not `json` with an explicit `continue`. So the
 * owner could not see, review or edit the groups anywhere - the fields worked on
 * the page editor and existed nowhere else. JSON is the shape ACF gives you for
 * "in the repository AND in the admin": the files are the source, the admin
 * lists them, and editing one writes the file back.
 *
 * A fresh install shows them as *Sync available* until they are imported once.
 * That is ACF's own workflow, and the reason the files are committed.
 *
 * **The location rules match on the TEMPLATE, not on a page id.** An exported
 * rule is static, and an id is only true on the site it was exported from - on
 * a fresh install id 7 is some other page or nothing at all, and the group would
 * attach to nothing with no error to say so. `page_template` travels, which is
 * why `page-legal.php` and `page-contact.php` declare a Template Name and the
 * two pages carry it explicitly. The landing page uses `front_page`, which needs
 * no id either.
 *
 * ---------------------------------------------------------------------------
 * The shape of the fields, which is an argument rather than a default
 * ---------------------------------------------------------------------------
 *
 * **The free ACF has no Repeater** (verified in ACF 6.8.9's own `pro/fields/`:
 * Repeater, Flexible Content, Clone and Gallery are the Pro four; Group, Tab and
 * Accordion are free). What that forced turned out better than what it replaced:
 *
 * - **Anything whose count the layout fixes is a Group** - three pricing
 *   columns, three promises, five pipeline stages, four formula examples, three
 *   numbers, three contact panels, the preview card. Adding a fourth pricing
 *   column means drawing a fourth column, so it should feel like a change to the
 *   design rather than data entry.
 * - **The two lists that genuinely grow are post types** - the FAQ and the
 *   compatibility table, ordered by dragging. See inc/post-types.php. That is a
 *   better editor than a repeater: it searches, keeps revisions, and does not
 *   put twenty answers in one database row.
 * - **A list inside a group is a textarea, one item per line.** It reorders by
 *   dragging a line and does not cap the count, which numbered fields do both.
 *   Plan features use a leading `-` for a greyed "not in this plan" row; seat
 *   prices read `5 sites | $199`; the preview card's rows read
 *   `Name | 24.00 | 20.99`.
 * - **A field rendered inside a `<p>` is never a WYSIWYG.** ACF puts a WYSIWYG
 *   value through wpautop, so `problem_text` and `contact_privacy_note` emitted
 *   `<p>` inside `<p>` the moment they held anything - which a browser repairs
 *   by closing the outer one early, taking the styling with it. Both are
 *   textareas; inline markup still works, because the templates print them
 *   through `wp_kses_post()`.
 *
 * **Every field is optional**, and the default is the shipped copy rather than a
 * placeholder - see {@see catalogops_text()}. A page nobody has edited renders
 * the site as designed, and clearing a field puts the designed line back.
 * `wp catalogops seed` writes that copy into the fields so the editor shows the
 * real text; see inc/seed.php for why it records rather than retypes.
 */
