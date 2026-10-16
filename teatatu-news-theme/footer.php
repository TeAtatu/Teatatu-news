<?php
/**
 * Site footer.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
$footer_text = get_theme_mod( 'ttn_footer_text', '' );
?>
</main>
<footer class="ttn-footer">
	<div class="ttn-wrap ttn-footer__grid">
		<div>
			<a class="tal-wordmark" href="<?php echo esc_url( home_url( '/' ) ); ?>">Te Atatū <span><?php esc_html_e( 'News', 'teatatu-news-theme' ); ?></span></a>
			<p><?php echo esc_html( $footer_text ? $footer_text : __( 'Local news and notices from Te Atatū Peninsula, Auckland — every story links back to where it was first published.', 'teatatu-news-theme' ) ); ?></p>
		</div>
		<?php if ( ttn_plugin_ready() ) : ?>
			<?php
			$categories = get_terms( array( 'taxonomy' => 'teatatu_news_category', 'hide_empty' => true ) );
			$sources    = get_terms( array( 'taxonomy' => 'teatatu_news_source', 'hide_empty' => true ) );
			?>
			<nav aria-labelledby="ttn-footer-browse">
				<h2 id="ttn-footer-browse"><?php esc_html_e( 'Browse', 'teatatu-news-theme' ); ?></h2>
				<ul>
					<li><a href="<?php echo esc_url( ttn_archive_url() ); ?>"><?php esc_html_e( 'Latest news', 'teatatu-news-theme' ); ?></a></li>
					<?php if ( $categories && ! is_wp_error( $categories ) ) : ?>
						<?php foreach ( $categories as $term ) : ?>
							<li><a href="<?php echo esc_url( get_term_link( $term ) ); ?>"><?php echo esc_html( $term->name ); ?></a></li>
						<?php endforeach; ?>
					<?php endif; ?>
					<?php if ( ttn_events_ready() ) : ?>
						<li><a href="<?php echo esc_url( ttn_events_url() ); ?>"><?php esc_html_e( 'What’s on', 'teatatu-news-theme' ); ?></a></li>
					<?php endif; ?>
				</ul>
			</nav>
			<?php if ( $sources && ! is_wp_error( $sources ) ) : ?>
				<nav aria-labelledby="ttn-footer-sources">
					<h2 id="ttn-footer-sources"><?php esc_html_e( 'Sources', 'teatatu-news-theme' ); ?></h2>
					<ul>
						<?php foreach ( $sources as $term ) : ?>
							<li><a href="<?php echo esc_url( get_term_link( $term ) ); ?>"><?php echo esc_html( $term->name ); ?></a></li>
						<?php endforeach; ?>
					</ul>
				</nav>
			<?php endif; ?>
		<?php endif; ?>
		<?php if ( has_nav_menu( 'footer' ) ) : ?>
			<nav aria-labelledby="ttn-footer-site">
				<h2 id="ttn-footer-site"><?php bloginfo( 'name' ); ?></h2>
				<?php wp_nav_menu( array( 'theme_location' => 'footer', 'container' => false, 'depth' => 1 ) ); ?>
			</nav>
		<?php endif; ?>
	</div>
</footer>
<?php wp_footer(); ?>
</body>
</html>
