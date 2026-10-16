<?php
/**
 * Plain pages (About, Contact, Privacy…).
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
get_header();
while ( have_posts() ) :
	the_post();
	?>
	<article <?php post_class( 'ttn-wrap ttn-page' ); ?>>
		<h1><?php the_title(); ?></h1>
		<div class="entry-content"><?php the_content(); ?></div>
	</article>
	<?php
endwhile;
get_footer();
