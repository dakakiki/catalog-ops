<?php
/**
 * A page with no template of its own.
 *
 * The three real pages each have one; this renders anything else the owner adds
 * later — a thank-you page after checkout, a download page — in the site's own
 * type rather than leaving it unstyled.
 *
 * @package CatalogOps_Theme
 */

get_header();
?>

<main class="wrap">

	<?php while ( have_posts() ) : ?>
		<?php the_post(); ?>

		<div class="page-head">
			<h1><?php the_title(); ?></h1>
		</div>

		<article class="entry">
			<?php the_content(); ?>
		</article>

	<?php endwhile; ?>

</main>

<?php
get_footer();
