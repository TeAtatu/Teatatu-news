<?php
/**
 * Per-site shortcodes ([teatatu_news_list|grid|latest]) and their
 * network-wide counterparts (Master Site only), sharing one attribute
 * parser, one WP_Query builder, one aggregation/cache function (also used
 * by the network REST endpoint), and one HTML renderer.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

add_action( 'wp_enqueue_scripts', 'teatatu_news_register_style' );

/**
 * Registers (without enqueuing) the plugin's minimal structural stylesheet.
 */
function teatatu_news_register_style() {
	wp_register_style( 'teatatu-news', TEATATU_NEWS_URL . 'assets/css/teatatu-news.css', array(), TEATATU_NEWS_VERSION );
}

/**
 * Enqueues the stylesheet — called only from within a shortcode callback so
 * it loads solely on pages that actually use one.
 */
function teatatu_news_enqueue_style() {
	wp_enqueue_style( 'teatatu-news' );
}

/**
 * Merges shortcode attributes with shared defaults and normalizes types.
 *
 * @param array $atts           Raw shortcode attributes.
 * @param array $extra_defaults Additional/overriding defaults for this shortcode.
 * @return array
 */
function teatatu_news_shortcode_atts( $atts, $extra_defaults = array() ) {
	$defaults = array_merge(
		array(
			'count'         => 5,
			'columns'       => 3,
			'source'        => '',
			'category'      => '',
			'orderby'       => 'date',
			'order'         => 'DESC',
			'show_image'    => 'true',
			'show_excerpt'  => 'true',
			'show_source'   => 'true',
			'show_category' => 'true',
			'show_date'     => 'true',
			'link_target'   => '_self',
			'exclude_sites' => '',
			'site_label'    => 'false',
			'display'       => 'full',
		),
		$extra_defaults
	);

	$atts = shortcode_atts( $defaults, $atts );

	foreach ( array( 'show_image', 'show_excerpt', 'show_source', 'show_category', 'show_date', 'site_label' ) as $bool_key ) {
		$atts[ $bool_key ] = filter_var( $atts[ $bool_key ], FILTER_VALIDATE_BOOLEAN );
	}

	$atts['count']   = max( 1, (int) $atts['count'] );
	$atts['columns'] = max( 1, min( 6, (int) $atts['columns'] ) );

	$allowed_orderby = array( 'date', 'title', 'modified', 'rand' );
	if ( ! in_array( $atts['orderby'], $allowed_orderby, true ) ) {
		$atts['orderby'] = 'date';
	}
	$atts['order'] = 'ASC' === strtoupper( $atts['order'] ) ? 'ASC' : 'DESC';

	$atts['link_target'] = '_blank' === $atts['link_target'] ? '_blank' : '_self';

	// "summary" is a convenience preset for a minimal card: header, excerpt,
	// thumbnail, and the Read More link only — it suppresses the Source/
	// Category/Date/site-label meta line regardless of the individual
	// show_* attributes. show_image/show_excerpt still apply on top of it
	// (e.g. display="summary" show_excerpt="false" drops the excerpt too).
	$atts['display'] = 'summary' === $atts['display'] ? 'summary' : 'full';
	if ( 'summary' === $atts['display'] ) {
		$atts['show_source']   = false;
		$atts['show_category'] = false;
		$atts['show_date']     = false;
		$atts['site_label']    = false;
	}

	return $atts;
}

/**
 * Runs the scoped WP_Query for the current site and normalizes results.
 *
 * @param array $atts Parsed shortcode attributes.
 * @return array[]
 */
function teatatu_news_query_items( $atts ) {
	$args = array(
		'post_type'      => 'teatatu_news',
		'post_status'    => 'publish',
		'posts_per_page' => $atts['count'],
		'orderby'        => $atts['orderby'],
		'order'          => $atts['order'],
	);

	$tax_query = array();
	if ( ! empty( $atts['source'] ) ) {
		$tax_query[] = array( 'taxonomy' => 'teatatu_news_source', 'field' => 'slug', 'terms' => $atts['source'] );
	}
	if ( ! empty( $atts['category'] ) ) {
		$tax_query[] = array( 'taxonomy' => 'teatatu_news_category', 'field' => 'slug', 'terms' => $atts['category'] );
	}
	if ( ! empty( $tax_query ) ) {
		$args['tax_query'] = $tax_query; // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_tax_query
	}

	$query = new WP_Query( $args );

	$items = array();
	foreach ( $query->posts as $post ) {
		$items[] = teatatu_news_normalize_item( $post );
	}
	return $items;
}

/**
 * Builds a plain-array representation of a News post for rendering/JSON.
 *
 * @param WP_Post $post News post.
 * @return array
 */
function teatatu_news_normalize_item( $post ) {
	$source_terms   = get_the_terms( $post->ID, 'teatatu_news_source' );
	$category_terms = get_the_terms( $post->ID, 'teatatu_news_category' );

	return array(
		'id'            => $post->ID,
		'title'         => get_the_title( $post ),
		'excerpt'       => get_the_excerpt( $post ),
		'permalink'     => get_permalink( $post ),
		'read_more_url' => get_post_meta( $post->ID, '_teatatu_news_read_more_url', true ),
		'image_url'     => teatatu_news_get_image_url( $post->ID ),
		'source'        => ( ! empty( $source_terms ) && ! is_wp_error( $source_terms ) ) ? $source_terms[0]->name : '',
		'category'      => ( ! empty( $category_terms ) && ! is_wp_error( $category_terms ) ) ? $category_terms[0]->name : '',
		'date'          => get_the_date( '', $post ),
		'date_gmt'      => get_post_field( 'post_date_gmt', $post ),
		'modified_gmt'  => get_post_field( 'post_modified_gmt', $post ),
		'site_id'       => null,
		'site_name'     => null,
		'site_url'      => null,
	);
}

// -----------------------------------------------------------------------
// Per-site shortcodes
// -----------------------------------------------------------------------

add_shortcode( 'teatatu_news_list', 'teatatu_news_shortcode_list' );
/**
 * [teatatu_news_list] — simple list view of the current site's News.
 *
 * @param array $atts Shortcode attributes.
 * @return string
 */
function teatatu_news_shortcode_list( $atts ) {
	teatatu_news_enqueue_style();
	$atts               = teatatu_news_shortcode_atts( (array) $atts );
	$atts['_variant']   = 'list';
	return teatatu_news_render_items( teatatu_news_query_items( $atts ), $atts );
}

add_shortcode( 'teatatu_news_grid', 'teatatu_news_shortcode_grid' );
/**
 * [teatatu_news_grid] — grid/card view of the current site's News.
 *
 * @param array $atts Shortcode attributes.
 * @return string
 */
function teatatu_news_shortcode_grid( $atts ) {
	teatatu_news_enqueue_style();
	$atts             = teatatu_news_shortcode_atts( (array) $atts );
	$atts['_variant'] = 'grid';
	return teatatu_news_render_items( teatatu_news_query_items( $atts ), $atts );
}

add_shortcode( 'teatatu_news_latest', 'teatatu_news_shortcode_latest' );
/**
 * [teatatu_news_latest] — single most recent item, headline style.
 *
 * @param array $atts Shortcode attributes.
 * @return string
 */
function teatatu_news_shortcode_latest( $atts ) {
	teatatu_news_enqueue_style();
	$atts             = teatatu_news_shortcode_atts( (array) $atts, array( 'count' => 1 ) );
	$atts['count']    = 1;
	$atts['_variant'] = 'latest';
	return teatatu_news_render_items( teatatu_news_query_items( $atts ), $atts );
}

// -----------------------------------------------------------------------
// Network-wide shortcodes (Master Site only)
// -----------------------------------------------------------------------

add_shortcode( 'teatatu_news_network_list', 'teatatu_news_shortcode_network_list' );
/**
 * [teatatu_news_network_list]
 *
 * @param array $atts Shortcode attributes.
 * @return string
 */
function teatatu_news_shortcode_network_list( $atts ) {
	return teatatu_news_render_network_shortcode( (array) $atts, 'list' );
}

add_shortcode( 'teatatu_news_network_grid', 'teatatu_news_shortcode_network_grid' );
/**
 * [teatatu_news_network_grid]
 *
 * @param array $atts Shortcode attributes.
 * @return string
 */
function teatatu_news_shortcode_network_grid( $atts ) {
	return teatatu_news_render_network_shortcode( (array) $atts, 'grid' );
}

add_shortcode( 'teatatu_news_network_latest', 'teatatu_news_shortcode_network_latest' );
/**
 * [teatatu_news_network_latest]
 *
 * @param array $atts Shortcode attributes.
 * @return string
 */
function teatatu_news_shortcode_network_latest( $atts ) {
	return teatatu_news_render_network_shortcode( (array) $atts, 'latest' );
}

/**
 * Shared implementation for all three network shortcodes: Master Site gate,
 * attribute parsing, aggregation, and rendering.
 *
 * @param array  $atts    Raw shortcode attributes.
 * @param string $variant 'list', 'grid', or 'latest'.
 * @return string
 */
function teatatu_news_render_network_shortcode( $atts, $variant ) {
	if ( ! is_multisite() ) {
		return '';
	}

	if ( ! teatatu_news_is_master_site() ) {
		if ( current_user_can( 'manage_options' ) ) {
			$message = teatatu_news_get_master_site_id()
				? __( "This shortcode only displays on the network's Master Site.", 'teatatu-news' )
				: __( 'No Master Site has been set yet — configure one in Network Admin > Settings > Teatatu News.', 'teatatu-news' );
			return '<p class="teatatu-news-admin-notice">' . esc_html( $message ) . '</p>';
		}
		return '';
	}

	teatatu_news_enqueue_style();

	$extra_defaults = 'latest' === $variant ? array( 'count' => 1 ) : array();
	$atts           = teatatu_news_shortcode_atts( (array) $atts, $extra_defaults );
	if ( 'latest' === $variant ) {
		$atts['count'] = 1;
	}
	$atts['_variant'] = $variant;

	$items = teatatu_news_get_network_feed_items( $atts );

	if ( $atts['site_label'] ) {
		foreach ( $items as &$item ) {
			$item['site_label'] = $item['site_name'];
		}
		unset( $item );
	}

	return teatatu_news_render_items( $items, $atts );
}

/**
 * Aggregates News items from every site in the network, with network-cache
 * support. Shared by the network shortcodes and the network REST endpoint
 * (`GET /wp-json/teatatu-news/v1/network-feed`) so both paths stay
 * consistent.
 *
 * @param array $atts Parsed attributes (must already be run through teatatu_news_shortcode_atts()).
 * @return array[]
 */
function teatatu_news_get_network_feed_items( $atts ) {
	// Only the attributes that actually change which items get fetched (see
	// teatatu_news_query_items()) belong in the cache key. Purely visual
	// attributes (columns, show_*, display, link_target, site_label) or
	// internal flags (_variant) must be excluded, or the network shortcodes
	// and the REST endpoint would never share a cache entry for identical
	// data.
	$cache_atts = array_intersect_key(
		$atts,
		array_flip( array( 'count', 'source', 'category', 'orderby', 'order', 'exclude_sites' ) )
	);
	ksort( $cache_atts );
	$cache_key = 'teatatu_news_net_cache_' . md5( wp_json_encode( $cache_atts ) );
	$cached    = get_site_transient( $cache_key );
	if ( false !== $cached ) {
		return $cached;
	}

	$exclude_sites = array();
	if ( ! empty( $atts['exclude_sites'] ) ) {
		$exclude_sites = array_map( 'absint', array_filter( array_map( 'trim', explode( ',', $atts['exclude_sites'] ) ) ) );
	}

	$site_ids  = get_sites( array( 'fields' => 'ids' ) );
	$all_items = array();

	foreach ( $site_ids as $site_id ) {
		$site_id = (int) $site_id;
		if ( in_array( $site_id, $exclude_sites, true ) ) {
			continue;
		}

		switch_to_blog( $site_id );
		$site_items = teatatu_news_query_items( $atts );
		$site_name  = get_bloginfo( 'name' );
		$site_url   = home_url();
		restore_current_blog();

		foreach ( $site_items as &$item ) {
			$item['site_id']   = $site_id;
			$item['site_name'] = $site_name;
			$item['site_url']  = $site_url;
		}
		unset( $item );

		$all_items = array_merge( $all_items, $site_items );
	}

	// Re-rank the merged, cross-site pool the same way each per-site
	// WP_Query already ranked its own results, so the network 'orderby'
	// attribute means the same thing before and after aggregation.
	if ( 'rand' === $atts['orderby'] ) {
		shuffle( $all_items );
	} else {
		$sort_field = 'title' === $atts['orderby'] ? 'title' : ( 'modified' === $atts['orderby'] ? 'modified_gmt' : 'date_gmt' );
		usort(
			$all_items,
			function ( $a, $b ) use ( $atts, $sort_field ) {
				$cmp = 'title' === $sort_field ? strcasecmp( $a[ $sort_field ], $b[ $sort_field ] ) : strcmp( $a[ $sort_field ], $b[ $sort_field ] );
				return 'ASC' === $atts['order'] ? $cmp : -$cmp;
			}
		);
	}
	$all_items = array_slice( $all_items, 0, $atts['count'] );

	set_site_transient( $cache_key, $all_items, HOUR_IN_SECONDS );
	teatatu_news_register_network_cache_key( $cache_key );

	return $all_items;
}

// -----------------------------------------------------------------------
// Shared HTML renderer
// -----------------------------------------------------------------------

/**
 * Renders a normalized item list as list/grid/latest markup.
 *
 * @param array[] $items Normalized items (see teatatu_news_normalize_item()).
 * @param array   $atts  Parsed attributes, including internal `_variant`.
 * @return string
 */
function teatatu_news_render_items( array $items, array $atts ) {
	if ( empty( $items ) ) {
		return '<p class="teatatu-news-empty">' . esc_html__( 'No news items found.', 'teatatu-news' ) . '</p>';
	}

	$variant       = isset( $atts['_variant'] ) ? $atts['_variant'] : 'list';
	$summary_class = 'summary' === $atts['display'] ? ' teatatu-news-summary' : '';

	ob_start();
	if ( 'grid' === $variant ) {
		?>
		<div class="teatatu-news-grid<?php echo esc_attr( $summary_class ); ?>" style="--teatatu-news-columns: <?php echo esc_attr( $atts['columns'] ); ?>;">
			<?php foreach ( $items as $item ) : ?>
				<div class="teatatu-news-card"><?php echo teatatu_news_render_item_markup( $item, $atts ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- pre-escaped piece by piece ?></div>
			<?php endforeach; ?>
		</div>
		<?php
	} elseif ( 'latest' === $variant ) {
		?>
		<div class="teatatu-news-latest<?php echo esc_attr( $summary_class ); ?>"><?php echo teatatu_news_render_item_markup( $items[0], $atts ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- pre-escaped piece by piece ?></div>
		<?php
	} else {
		?>
		<ul class="teatatu-news-list<?php echo esc_attr( $summary_class ); ?>">
			<?php foreach ( $items as $item ) : ?>
				<li class="teatatu-news-list-item"><?php echo teatatu_news_render_item_markup( $item, $atts ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- pre-escaped piece by piece ?></li>
			<?php endforeach; ?>
		</ul>
		<?php
	}
	return ob_get_clean();
}

/**
 * Renders the inner markup for a single item, honoring the show_* toggles.
 *
 * @param array $item Normalized item.
 * @param array $atts Parsed attributes.
 * @return string
 */
function teatatu_news_render_item_markup( $item, $atts ) {
	$link = $item['permalink'] ? $item['permalink'] : $item['read_more_url'];

	ob_start();

	if ( $atts['show_image'] && ! empty( $item['image_url'] ) ) {
		printf(
			'<a class="teatatu-news-image-link" href="%1$s" target="%2$s" rel="noopener noreferrer"><img class="teatatu-news-image" src="%3$s" alt="%4$s" loading="lazy" /></a>',
			esc_url( $link ),
			esc_attr( $atts['link_target'] ),
			esc_url( $item['image_url'] ),
			esc_attr( $item['title'] )
		);
	}

	printf(
		'<h3 class="teatatu-news-title"><a href="%1$s" target="%2$s" rel="noopener noreferrer">%3$s</a></h3>',
		esc_url( $link ),
		esc_attr( $atts['link_target'] ),
		esc_html( $item['title'] )
	);

	$meta_bits = array();
	if ( $atts['show_source'] && ! empty( $item['source'] ) ) {
		$meta_bits[] = esc_html( $item['source'] );
	}
	if ( $atts['show_category'] && ! empty( $item['category'] ) ) {
		$meta_bits[] = esc_html( $item['category'] );
	}
	if ( $atts['show_date'] && ! empty( $item['date'] ) ) {
		$meta_bits[] = esc_html( $item['date'] );
	}
	if ( ! empty( $item['site_label'] ) ) {
		$meta_bits[] = esc_html( $item['site_label'] );
	}
	if ( ! empty( $meta_bits ) ) {
		echo '<div class="teatatu-news-meta">' . implode( ' &middot; ', $meta_bits ) . '</div>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
	}

	if ( $atts['show_excerpt'] && ! empty( $item['excerpt'] ) ) {
		echo '<div class="teatatu-news-excerpt">' . wp_kses_post( $item['excerpt'] ) . '</div>';
	}

	if ( ! empty( $item['read_more_url'] ) ) {
		printf(
			'<a class="teatatu-news-read-more" href="%1$s" target="%2$s" rel="noopener noreferrer">%3$s</a>',
			esc_url( $item['read_more_url'] ),
			esc_attr( $atts['link_target'] ),
			esc_html__( 'Read More', 'teatatu-news' )
		);
	}

	return ob_get_clean();
}
