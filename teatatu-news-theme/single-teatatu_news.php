<?php
/**
 * A single story: headline, source and date, image, the summary, and a
 * prominent link to read the full story where it was first published.
 * Alongside: what's on, and more stories like it.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
get_header();

if ( ! ttn_plugin_ready() ) {
	echo '<div class="ttn-wrap ttn-section">';
	ttn_plugin_missing_message();
	echo '</div>';
	get_footer();
	return;
}

while ( have_posts() ) :
	the_post();
	$item     = teatatu_news_normalize_item( get_post() );
	$cats     = get_the_terms( $item['id'], 'teatatu_news_category' );
	$srcs     = get_the_terms( $item['id'], 'teatatu_news_source' );
	$cat      = ( $cats && ! is_wp_error( $cats ) ) ? $cats[0] : null;
	$src      = ( $srcs && ! is_wp_error( $srcs ) ) ? $srcs[0] : null;
	$related  = array();
	foreach ( ttn_latest( 4, $cat ? array( 'category' => $cat->slug ) : array() ) as $candidate ) {
		if ( (int) $candidate['id'] !== (int) $item['id'] ) {
			$related[] = $candidate;
		}
	}
	$related  = array_slice( $related, 0, 3 );
	$summary  = $item['excerpt'] ? wp_kses(
		wpautop( $item['excerpt'] ),
		array(
			'a'  => array( 'href' => true, 'rel' => true, 'target' => true ),
			'p'  => array(),
			'br' => array(),
		)
	) : '';
	?>
	<article class="ttn-wrap ttn-story">
		<nav class="ttn-crumbs" aria-label="<?php esc_attr_e( 'Breadcrumb', 'teatatu-news-theme' ); ?>">
			<ol>
				<li><a href="<?php echo esc_url( ttn_archive_url() ); ?>"><?php esc_html_e( 'News', 'teatatu-news-theme' ); ?></a></li>
				<?php if ( $cat ) : ?>
					<li><a href="<?php echo esc_url( get_term_link( $cat ) ); ?>"><?php echo esc_html( $cat->name ); ?></a></li>
				<?php endif; ?>
			</ol>
		</nav>

		<div class="ttn-story__cols">
			<div class="ttn-story__main">
				<header class="ttn-story__head">
					<?php if ( $cat ) : ?>
						<p class="ttn-eyebrow"><?php echo esc_html( $cat->name ); ?></p>
					<?php endif; ?>
					<h1><?php echo esc_html( $item['title'] ); ?></h1>
					<p class="ttn-story__meta">
						<?php if ( $src ) : ?>
							<a href="<?php echo esc_url( get_term_link( $src ) ); ?>"><?php echo esc_html( $src->name ); ?></a>
							<span aria-hidden="true">·</span>
						<?php endif; ?>
						<?php ttn_time( $item ); ?>
					</p>
				</header>

				<?php if ( $item['image_url'] ) : ?>
					<figure class="ttn-story__media"><?php ttn_image( $item['image_url'], '', 'eager' ); ?></figure>
				<?php endif; ?>

				<?php if ( $summary ) : ?>
					<div class="ttn-prose"><?php echo $summary; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- wp_kses above. ?></div>
				<?php endif; ?>

				<div class="ttn-story__actions">
					<?php if ( $item['read_more_url'] ) : ?>
						<a class="tal-btn tal-btn-primary" href="<?php echo esc_url( $item['read_more_url'] ); ?>" target="_blank" rel="noopener noreferrer">
							<?php
							/* translators: %s: source name. */
							echo esc_html( $src ? sprintf( __( 'Read the full story at %s', 'teatatu-news-theme' ), $src->name ) : __( 'Read the full story', 'teatatu-news-theme' ) );
							?>
							<?php echo ttn_icon( 'external', 18 ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- static SVG. ?>
						</a>
					<?php endif; ?>
					<button class="tal-btn tal-btn-secondary" type="button" data-ttn-share="<?php echo esc_url( $item['permalink'] ); ?>"><?php echo ttn_icon( 'share', 18 ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- static SVG. ?><?php esc_html_e( 'Share', 'teatatu-news-theme' ); ?></button>
				</div>
			</div>

			<aside class="ttn-story__aside" aria-label="<?php esc_attr_e( 'More on the Peninsula', 'teatatu-news-theme' ); ?>">
				<?php get_template_part( 'template-parts/whats-on', null, array( 'variant' => 'panel', 'count' => 3 ) ); ?>
				<?php if ( $src ) : ?>
					<section class="ttn-panel">
						<h2><?php echo esc_html( $src->name ); ?></h2>
						<?php if ( $src->description ) : ?>
							<p class="ttn-small"><?php echo esc_html( $src->description ); ?></p>
						<?php endif; ?>
						<a class="ttn-arrow-link" href="<?php echo esc_url( get_term_link( $src ) ); ?>">
							<?php
							/* translators: %s: source name. */
							echo esc_html( sprintf( __( 'More from %s', 'teatatu-news-theme' ), $src->name ) );
							?>
							<?php echo ttn_icon( 'arrow', 18 ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- static SVG. ?>
						</a>
					</section>
				<?php endif; ?>
			</aside>
		</div>

		<?php if ( $related ) : ?>
			<section class="ttn-section" aria-labelledby="ttn-related-heading">
				<div class="ttn-section-head">
					<h2 id="ttn-related-heading"><?php echo esc_html( $cat ? sprintf( /* translators: %s: category. */ __( 'More %s news', 'teatatu-news-theme' ), $cat->name ) : __( 'More news', 'teatatu-news-theme' ) ); ?></h2>
				</div>
				<div class="ttn-card-grid">
					<?php foreach ( $related as $story ) : ?>
						<?php ttn_card( $story ); ?>
					<?php endforeach; ?>
				</div>
			</section>
		<?php endif; ?>
	</article>
	<?php
endwhile;

get_footer();
