<?php
/**
 * News listings: the News archive, every Source and Category archive, and
 * news search (see ttn_news_listing_template()). WordPress has already
 * applied the URL filters to the main query; this template renders the
 * filter bar, the results and pagination.
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

global $wp_query;
$filters   = ttn_current_filters();
$base_url  = ttn_current_base_url();
$on_cat    = is_tax( 'teatatu_news_category' );
$on_source = is_tax( 'teatatu_news_source' );
$items     = array_map( 'teatatu_news_normalize_item', $wp_query->posts );
$total     = (int) $wp_query->found_posts;
$active    = array();
foreach ( array( 's', 'category', 'source' ) as $key ) {
	if ( '' !== $filters[ $key ] && ! ( 'category' === $key && $on_cat ) && ! ( 'source' === $key && $on_source ) ) {
		$active[ $key ] = $filters[ $key ];
	}
}
$param = array( 's' => 's', 'category' => 'teatatu_news_category', 'source' => 'teatatu_news_source' );
?>

<section class="ttn-filterbar" aria-label="<?php esc_attr_e( 'Search and filter', 'teatatu-news-theme' ); ?>">
	<form class="ttn-wrap" role="search" method="get" action="<?php echo esc_url( $base_url ); ?>">
		<label class="ttn-field ttn-field--grow"><?php esc_html_e( 'Search', 'teatatu-news-theme' ); ?>
			<input class="ttn-control" type="search" name="s" value="<?php echo esc_attr( $filters['s'] ); ?>" placeholder="<?php esc_attr_e( 'Words in a headline or summary', 'teatatu-news-theme' ); ?>" />
		</label>
		<?php if ( ! $on_cat ) : ?>
			<label class="ttn-field ttn-field--sm"><?php esc_html_e( 'Topic', 'teatatu-news-theme' ); ?>
				<?php ttn_select( 'teatatu_news_category', 'ttn-category', ttn_term_options( 'teatatu_news_category', __( 'All topics', 'teatatu-news-theme' ) ), $filters['category'] ); ?>
			</label>
		<?php endif; ?>
		<?php if ( ! $on_source ) : ?>
			<label class="ttn-field ttn-field--sm"><?php esc_html_e( 'Source', 'teatatu-news-theme' ); ?>
				<?php ttn_select( 'teatatu_news_source', 'ttn-source', ttn_term_options( 'teatatu_news_source', __( 'All sources', 'teatatu-news-theme' ) ), $filters['source'] ); ?>
			</label>
		<?php endif; ?>
		<?php if ( ! is_tax() ) : ?>
			<input type="hidden" name="post_type" value="teatatu_news" />
		<?php endif; ?>
		<input type="hidden" name="sort" value="<?php echo esc_attr( $filters['sort'] ); ?>" />
		<button class="tal-btn tal-btn-primary" type="submit"><?php echo ttn_icon( 'search', 18 ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- static SVG. ?><?php esc_html_e( 'Search', 'teatatu-news-theme' ); ?></button>
	</form>
</section>

<section class="ttn-wrap ttn-section ttn-section--tight">
	<div class="ttn-results__head">
		<div>
			<h1 class="ttn-display"><?php echo esc_html( ttn_results_heading() ); ?></h1>
			<p class="ttn-muted" role="status">
				<?php
				/* translators: %s: number of stories. */
				echo esc_html( sprintf( _n( '%s story', '%s stories', $total, 'teatatu-news-theme' ), number_format_i18n( $total ) ) );
				?>
			</p>
			<?php if ( ( $on_cat || $on_source ) && term_description() ) : ?>
				<p class="ttn-muted"><?php echo esc_html( wp_strip_all_tags( term_description() ) ); ?></p>
			<?php endif; ?>
		</div>
		<div class="ttn-results__tools">
			<?php if ( $active ) : ?>
				<div class="ttn-chips">
					<?php
					foreach ( $active as $key => $value ) :
						$without = array();
						foreach ( $active as $k => $v ) {
							if ( $k !== $key ) {
								$without[ $param[ $k ] ] = $v;
							}
						}
						$without['sort'] = $filters['sort'];
						/* translators: %s: filter description. */
						$remove_label = sprintf( __( 'Remove filter: %s', 'teatatu-news-theme' ), ttn_filter_chip_label( $key, $value ) );
						$remove_url   = ttn_filters_url( $without, $base_url );
						if ( ! $on_cat && ! $on_source && 's' === $key && count( $active ) === 1 ) {
							$remove_url = ttn_archive_url();
						}
						?>
						<a class="ttn-chip ttn-chip--active" href="<?php echo esc_url( $remove_url ); ?>" aria-label="<?php echo esc_attr( $remove_label ); ?>"><?php echo esc_html( ttn_filter_chip_label( $key, $value ) ); ?><?php echo ttn_icon( 'close', 14 ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- static SVG. ?></a>
					<?php endforeach; ?>
				</div>
			<?php endif; ?>
			<form method="get" action="<?php echo esc_url( $base_url ); ?>" class="ttn-sort" data-ttn-autosubmit>
				<?php
				foreach ( $active as $key => $value ) {
					printf( '<input type="hidden" name="%s" value="%s" />', esc_attr( $param[ $key ] ), esc_attr( $value ) );
				}
				if ( ! is_tax() && ( $active || is_search() ) ) {
					echo '<input type="hidden" name="post_type" value="teatatu_news" />';
				}
				?>
				<label class="ttn-field ttn-field--inline"><?php esc_html_e( 'Sort', 'teatatu-news-theme' ); ?>
					<?php
					ttn_select(
						'sort',
						'ttn-sort',
						array(
							''       => __( 'Newest first', 'teatatu-news-theme' ),
							'oldest' => __( 'Oldest first', 'teatatu-news-theme' ),
							'title'  => __( 'Headline A–Z', 'teatatu-news-theme' ),
						),
						$filters['sort']
					);
					?>
				</label>
				<noscript><button class="tal-btn tal-btn-secondary" type="submit"><?php esc_html_e( 'Sort', 'teatatu-news-theme' ); ?></button></noscript>
			</form>
		</div>
	</div>

	<div class="ttn-results">
		<div class="ttn-results__list">
			<?php if ( $items ) : ?>
				<?php foreach ( $items as $item ) : ?>
					<?php ttn_card( $item, 'row' ); ?>
				<?php endforeach; ?>
				<nav class="ttn-pagination" aria-label="<?php esc_attr_e( 'Results pages', 'teatatu-news-theme' ); ?>">
					<div class="nav-links">
						<?php
						echo paginate_links( // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- core-escaped.
							array(
								'total'     => (int) $wp_query->max_num_pages,
								'current'   => max( 1, (int) get_query_var( 'paged' ) ),
								'prev_text' => __( 'Previous', 'teatatu-news-theme' ),
								'next_text' => __( 'Next', 'teatatu-news-theme' ),
							)
						);
						?>
					</div>
				</nav>
			<?php else : ?>
				<div class="ttn-empty">
					<h2><?php esc_html_e( 'No stories match this search', 'teatatu-news-theme' ); ?></h2>
					<p class="ttn-muted"><?php esc_html_e( 'Try different words, or remove a filter.', 'teatatu-news-theme' ); ?></p>
					<a class="tal-btn tal-btn-secondary" href="<?php echo esc_url( ttn_archive_url() ); ?>"><?php esc_html_e( 'See all news', 'teatatu-news-theme' ); ?></a>
				</div>
			<?php endif; ?>
		</div>

		<aside class="ttn-results__aside" aria-label="<?php esc_attr_e( 'More on the Peninsula', 'teatatu-news-theme' ); ?>">
			<?php get_template_part( 'template-parts/whats-on', null, array( 'variant' => 'panel', 'count' => 4 ) ); ?>
		</aside>
	</div>
</section>

<?php
get_footer();
