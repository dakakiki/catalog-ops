<?php
/**
 * The fallback template.
 *
 * WordPress requires it, and on this site almost nothing reaches it: there are
 * three pages, each with a template of its own, and no blog. What lands here is
 * a 404 or a stray post, and both are better served by saying so plainly and
 * offering the way back than by a page of empty furniture.
 *
 * @package CatalogOps_Theme
 */

get_header();
?>

<main class="wrap">

	<div class="page-head">
		<span class="eyebrow"><?php esc_html_e( 'Not here', 'catalogops' ); ?></span>
		<h1><?php esc_html_e( 'That page does not exist', 'catalogops' ); ?></h1>
		<p class="lede">
			<?php esc_html_e( 'It may have moved, or the link may have been mistyped. Everything this site has is one click away.', 'catalogops' ); ?>
		</p>

		<p style="margin-top:1.5rem">
			<a class="btn btn-primary" href="<?php echo esc_url( home_url( '/' ) ); ?>">
				<?php esc_html_e( 'Back to the start', 'catalogops' ); ?>
			</a>
		</p>
	</div>

</main>

<?php
get_footer();
