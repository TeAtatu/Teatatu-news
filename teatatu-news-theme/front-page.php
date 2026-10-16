<?php
/**
 * Home page: the latest story and search, the latest news, what's on, topics
 * and sources, and the share-a-story call to action.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
get_header();

$ready      = ttn_plugin_ready();
$hero_image = get_theme_mod( 'ttn_hero_image', '' );
$hero_alt   = get_theme_mod( 'ttn_hero_image_alt', '' );
$share_url  = get_theme_mod( 'ttn_share_url', '' );
$stories    = $ready ? ttn_latest( 7 ) : array();
$featured   = ( ! $hero_image && $stories ) ? array_shift( $stories ) : null;
$stories    = array_slice( $stories, 0, 6 );
$categories = $ready ? get_terms( array( 'taxonomy' => 'teatatu_news_category', 'hide_empty' => true ) ) : array();
$sources    = $ready ? get_terms( array( 'taxonomy' => 'teatatu_news_source', 'hide_empty' => true ) ) : array();
$categories = is_wp_error( $categories ) ? array() : $categories;
$sources    = is_wp_error( $sources ) ? array() : $sources;
?>

<div class="ttn-wrap">
<section class="tal-band ttn-hero-band" aria-labelledby="ttn-hero-title">
<div class="ttn-hero">
	<div class="ttn-hero__copy">
		<p class="ttn-eyebrow"><?php esc_html_e( 'Te Atatū Peninsula · West Auckland', 'teatatu-news-theme' ); ?></p>
		<h1 id="ttn-hero-title"><?php esc_html_e( 'The Peninsula’s news, in one place.', 'teatatu-news-theme' ); ?></h1>
		<p class="ttn-lead"><?php esc_html_e( 'Local stories, notices and updates from the sources our community already trusts — each one linked back to where it was first published.', 'teatatu-news-theme' ); ?></p>

		<?php if ( $ready ) : ?>
			<form class="ttn-search-card" role="search" method="get" action="<?php echo esc_url( home_url( '/' ) ); ?>">
				<label class="ttn-field"><?php esc_html_e( 'Search the news', 'teatatu-news-theme' ); ?>
					<input class="ttn-control" type="search" name="s" placeholder="<?php esc_attr_e( 'e.g. school, roadworks, library', 'teatatu-news-theme' ); ?>" />
				</label>
				<input type="hidden" name="post_type" value="teatatu_news" />
				<button class="tal-btn tal-btn-primary tal-btn-block" type="submit"><?php echo ttn_icon( 'search' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- static SVG. ?><?php esc_html_e( 'Search news', 'teatatu-news-theme' ); ?></button>
			</form>
			<?php if ( $categories ) : ?>
				<div class="ttn-chips">
					<?php foreach ( array_slice( $categories, 0, 5 ) as $term ) : ?>
						<a class="ttn-chip" href="<?php echo esc_url( get_term_link( $term ) ); ?>"><?php echo esc_html( $term->name ); ?></a>
					<?php endforeach; ?>
				</div>
			<?php endif; ?>
		<?php endif; ?>
	</div>

	<?php if ( $hero_image ) : ?>
		<div class="ttn-hero__media">
			<img src="<?php echo esc_url( $hero_image ); ?>" alt="<?php echo esc_attr( $hero_alt ); ?>" fetchpriority="high" />
		</div>
	<?php elseif ( $featured ) : ?>
		<a class="ttn-feature" href="<?php echo esc_url( $featured['permalink'] ); ?>">
			<span class="ttn-feature__media"><?php ttn_image( $featured['image_url'], '', 'eager' ); ?></span>
			<span class="ttn-feature__body">
				<span class="ttn-card__meta">
					<span class="ttn-badge ttn-badge--accent"><?php esc_html_e( 'Latest', 'teatatu-news-theme' ); ?></span>
					<?php if ( $featured['source'] ) : ?>
						<span class="ttn-card__source"><?php echo esc_html( $featured['source'] ); ?></span>
					<?php endif; ?>
					<?php ttn_time( $featured ); ?>
				</span>
				<span class="ttn-feature__title"><?php echo esc_html( $featured['title'] ); ?></span>
				<?php if ( ttn_teaser( $featured, 28 ) ) : ?>
					<span class="ttn-card__teaser"><?php echo esc_html( ttn_teaser( $featured, 28 ) ); ?></span>
				<?php endif; ?>
			</span>
		</a>
	<?php endif; ?>
</div>
</section>
</div>

<?php if ( $ready ) : ?>
	<section class="ttn-wrap ttn-section" aria-labelledby="ttn-latest-heading">
		<div class="ttn-section-head">
			<div>
				<h2 id="ttn-latest-heading"><?php esc_html_e( 'Latest news', 'teatatu-news-theme' ); ?></h2>
				<p><?php esc_html_e( 'The newest stories from around the Peninsula.', 'teatatu-news-theme' ); ?></p>
			</div>
		</div>
		<?php if ( $stories ) : ?>
			<div class="ttn-card-grid">
				<?php foreach ( $stories as $story ) : ?>
					<?php ttn_card( $story ); ?>
				<?php endforeach; ?>
			</div>
		<?php else : ?>
			<div class="ttn-empty"><p><?php esc_html_e( 'No news has been published yet.', 'teatatu-news-theme' ); ?></p></div>
		<?php endif; ?>
		<p class="ttn-more-link"><a class="ttn-arrow-link" href="<?php echo esc_url( ttn_archive_url() ); ?>"><?php esc_html_e( 'View all news', 'teatatu-news-theme' ); ?><?php echo ttn_icon( 'arrow', 18 ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- static SVG. ?></a></p>
	</section>

	<?php get_template_part( 'template-parts/whats-on', null, array( 'variant' => 'band', 'count' => 3 ) ); ?>

	<?php if ( $categories ) : ?>
		<section class="ttn-wrap ttn-section" aria-labelledby="ttn-topics-heading">
			<div class="ttn-section-head">
				<div>
					<h2 id="ttn-topics-heading"><?php esc_html_e( 'Browse by topic', 'teatatu-news-theme' ); ?></h2>
					<p><?php esc_html_e( 'Jump straight to the kind of news you want.', 'teatatu-news-theme' ); ?></p>
				</div>
			</div>
			<div class="ttn-tiles">
				<?php foreach ( $categories as $term ) : ?>
					<a class="ttn-tile" href="<?php echo esc_url( get_term_link( $term ) ); ?>">
						<span class="ttn-tile__icon"><?php echo ttn_icon( 'news', 24 ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- static SVG. ?></span>
						<span class="ttn-tile__title"><?php echo esc_html( $term->name ); ?></span>
						<?php if ( $term->description ) : ?>
							<span class="ttn-tile__text"><?php echo esc_html( $term->description ); ?></span>
						<?php endif; ?>
						<span class="ttn-tile__meta">
							<?php
							/* translators: %d: number of stories. */
							echo esc_html( sprintf( _n( '%d story', '%d stories', (int) $term->count, 'teatatu-news-theme' ), (int) $term->count ) );
							?>
						</span>
					</a>
				<?php endforeach; ?>
			</div>
		</section>
	<?php endif; ?>

	<?php if ( $sources ) : ?>
		<section class="ttn-wrap ttn-section ttn-section--tight" aria-labelledby="ttn-sources-heading">
			<div class="ttn-section-head">
				<div>
					<h2 id="ttn-sources-heading"><?php esc_html_e( 'Where the news comes from', 'teatatu-news-theme' ); ?></h2>
					<p><?php esc_html_e( 'We link to every original — read the full story at its source.', 'teatatu-news-theme' ); ?></p>
				</div>
			</div>
			<div class="ttn-chips">
				<?php foreach ( $sources as $term ) : ?>
					<a class="ttn-chip" href="<?php echo esc_url( get_term_link( $term ) ); ?>"><?php echo esc_html( $term->name ); ?></a>
				<?php endforeach; ?>
			</div>
		</section>
	<?php endif; ?>
<?php else : ?>
	<section class="ttn-wrap ttn-section"><?php ttn_plugin_missing_message(); ?></section>
<?php endif; ?>

<?php if ( $share_url ) : ?>
	<section class="ttn-wrap ttn-section">
		<div class="tal-band ttn-cta">
			<div>
				<h2><?php esc_html_e( 'Got news for the Peninsula?', 'teatatu-news-theme' ); ?></h2>
				<p><?php esc_html_e( 'Tell us about a story, notice or announcement and we will get it in front of your neighbours.', 'teatatu-news-theme' ); ?></p>
			</div>
			<div class="ttn-cta__actions">
				<a class="tal-btn tal-btn-primary" href="<?php echo esc_url( $share_url ); ?>"><?php esc_html_e( 'Share a story', 'teatatu-news-theme' ); ?></a>
			</div>
		</div>
	</section>
<?php endif; ?>

<?php
get_footer();
