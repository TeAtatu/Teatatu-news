<?php
/**
 * Template tags: small helpers shared by the templates. News is read only
 * through the Teatatu News plugin's functions; events only through the
 * Teatatu Events plugin's, and only when it is active. Items are the
 * plugins' plain normalised arrays — no post meta is read here.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Inline outline icon (decorative: aria-hidden). Lucide-style geometry at
 * the design system's 1.75 stroke on a 24px grid.
 *
 * @param string $name Icon name.
 * @param int    $size Pixel size.
 * @return string SVG markup.
 */
function ttn_icon( $name, $size = 20 ) {
	$paths = array(
		'search'   => '<circle cx="11" cy="11" r="7"/><path d="m20 20-3.5-3.5"/>',
		'arrow'    => '<path d="M5 12h14M13 6l6 6-6 6"/>',
		'share'    => '<path d="M12 15V3M7 8l5-5 5 5"/><path d="M5 13v7h14v-7"/>',
		'filter'   => '<path d="M4 6h16M7 12h10M10 18h4"/>',
		'close'    => '<path d="M6 6l12 12M18 6 6 18"/>',
		'photo'    => '<rect x="3" y="5" width="18" height="14" rx="2"/><circle cx="9" cy="10" r="2"/><path d="m21 16-5-5-8 8"/>',
		'pin'      => '<path d="M12 21s-7-6.5-7-12a7 7 0 0 1 14 0c0 5.5-7 12-7 12z"/><circle cx="12" cy="9" r="2.5"/>',
		'clock'    => '<circle cx="12" cy="12" r="9"/><path d="M12 7v5l3 2"/>',
		'calendar' => '<rect x="4" y="5" width="16" height="15" rx="2"/><path d="M4 10h16M8 3v4M16 3v4"/>',
		'external' => '<path d="M14 4h6v6"/><path d="M20 4 10 14"/><path d="M18 14v5a1 1 0 0 1-1 1H5a1 1 0 0 1-1-1V7a1 1 0 0 1 1-1h5"/>',
		'news'     => '<path d="M5 5h14v14a1 1 0 0 1-1 1H7a2 2 0 0 1-2-2z"/><path d="M9 9h6M9 13h6M9 17h3"/>',
		'tag'      => '<path d="M4 4h8l8 8-8 8-8-8z"/><circle cx="8.5" cy="8.5" r="1.2"/>',
	);
	if ( ! isset( $paths[ $name ] ) ) {
		return '';
	}
	return sprintf(
		'<svg width="%1$d" height="%1$d" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false">%2$s</svg>',
		(int) $size,
		$paths[ $name ]
	);
}

/**
 * URL of the full news listing (the post type archive).
 *
 * @return string
 */
function ttn_archive_url() {
	$url = get_post_type_archive_link( 'teatatu_news' );
	return $url ? $url : home_url( '/news/' );
}

/**
 * Stories for a block on a page, newest first.
 *
 * @param int   $count Number of stories.
 * @param array $args  Optional 'source' / 'category' slugs.
 * @return array[] Normalised items.
 */
function ttn_latest( $count = 6, $args = array() ) {
	if ( ! ttn_plugin_ready() ) {
		return array();
	}
	return teatatu_news_query_items(
		teatatu_news_shortcode_atts(
			array_merge(
				array(
					'count'   => $count,
					'orderby' => 'date',
					'order'   => 'DESC',
				),
				$args
			)
		)
	);
}

/**
 * Where "See all events" goes: the Customizer override, else the Events
 * section's address — this site's own Events archive on a single site, the
 * network's Events site on multisite (the Events plugin is usually
 * network-active, so every site has an empty /events/ archive of its own
 * while the events live elsewhere) — else the default Events address.
 *
 * @return string
 */
function ttn_events_url() {
	$custom = get_theme_mod( 'ttn_events_url', '' );
	if ( $custom ) {
		return $custom;
	}
	$def = ttn_section_plugins()['events'];
	$url = ttn_section_url( $def );
	return '' !== $url ? $url : $def['url'];
}

/**
 * Upcoming events for the "What's on" strip. Empty (and so the strip is
 * hidden) unless the Events plugin is active and the strip is switched on
 * in the Customizer. On multisite the plugin's network feed is used, so
 * events held on a different site of the network still appear.
 *
 * @param int $count Number of events.
 * @return array[] Normalised event items.
 */
function ttn_upcoming_events( $count = 3 ) {
	if ( ! ttn_events_ready() || ! get_theme_mod( 'ttn_show_events', true ) ) {
		return array();
	}
	if ( is_multisite() && function_exists( 'teatatu_events_get_network_feed_items' ) && function_exists( 'teatatu_events_shortcode_atts' ) ) {
		$result = teatatu_events_get_network_feed_items( teatatu_events_shortcode_atts( array( 'count' => $count, 'when' => 'upcoming' ) ) );
	} elseif ( function_exists( 'teatatu_events_search' ) ) {
		$result = teatatu_events_search( array( 'when' => 'upcoming' ), 1, $count );
	} else {
		$result = teatatu_events_query_site_items( teatatu_events_shortcode_atts( array( 'count' => $count, 'when' => 'upcoming' ) ) );
	}
	$items = ( is_array( $result ) && ! empty( $result['items'] ) ) ? array_slice( $result['items'], 0, $count ) : array();
	return (array) apply_filters( 'ttn_upcoming_events', $items, $count );
}

/**
 * Prints an <img> for a story photo (always a hotlinked URL — this plugin
 * never stores images), or a neutral placeholder.
 *
 * @param string $url     Image URL, or ''.
 * @param string $alt     Alt text (decorative by default).
 * @param string $loading 'lazy' or 'eager'.
 */
function ttn_image( $url, $alt = '', $loading = 'lazy' ) {
	if ( ! $url ) {
		echo '<span class="ttn-photo-empty">' . ttn_icon( 'photo', 28 ) . '<span class="screen-reader-text">' . esc_html__( 'No image', 'teatatu-news-theme' ) . '</span></span>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- static SVG.
		return;
	}
	printf(
		'<img src="%s" alt="%s" loading="%s" decoding="async" referrerpolicy="no-referrer" />',
		esc_url( $url ),
		esc_attr( $alt ),
		esc_attr( $loading )
	);
}

/**
 * A story's date as a <time> element.
 *
 * @param array $item Normalised news item.
 */
function ttn_time( $item ) {
	$iso = ! empty( $item['date_gmt'] ) && '0000-00-00 00:00:00' !== $item['date_gmt'] ? mysql2date( 'c', $item['date_gmt'], false ) : '';
	printf( '<time%s>%s</time>', $iso ? ' datetime="' . esc_attr( $iso ) . '"' : '', esc_html( $item['date'] ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped above.
}

/**
 * A plain-text teaser for a card. Excerpts can hold links, and cards are
 * links themselves, so tags are stripped here.
 *
 * @param array $item  Normalised news item.
 * @param int   $words Maximum words.
 * @return string
 */
function ttn_teaser( $item, $words = 24 ) {
	return wp_trim_words( wp_strip_all_tags( $item['excerpt'] ), $words );
}

/**
 * A story card (grid) or row (results list).
 *
 * @param array  $item    Normalised news item.
 * @param string $variant 'card' or 'row'.
 */
function ttn_card( $item, $variant = 'card' ) {
	get_template_part( 'template-parts/news', $variant, array( 'item' => $item ) );
}

/**
 * An event as a date-led card or compact row, using the design system's
 * event components (which take the Events accent on their own).
 *
 * @param array  $item    Normalised event item.
 * @param string $variant 'card' or 'row'.
 */
function ttn_event( $item, $variant = 'card' ) {
	get_template_part( 'template-parts/event', $variant, array( 'item' => $item ) );
}

/**
 * The design system's date block for an event ("SAT / 3 / OCT").
 *
 * @param array $item Normalised event item.
 */
function ttn_event_date_block( $item ) {
	$start = (int) $item['start'];
	if ( ! $start ) {
		return;
	}
	printf(
		'<span class="tal-date" aria-hidden="true"><span class="tal-date-dow">%s</span><span class="tal-date-day">%s</span><span class="tal-date-mon">%s</span></span>',
		esc_html( wp_date( 'D', $start ) ),
		esc_html( wp_date( 'j', $start ) ),
		esc_html( wp_date( 'M', $start ) )
	);
}

/**
 * Where an event goes: the plugin's resolved link (local page or the
 * organiser's), falling back to its permalink.
 *
 * @param array $item Normalised event item.
 * @return string
 */
function ttn_event_url( $item ) {
	return ! empty( $item['link'] ) ? $item['link'] : $item['permalink'];
}

/**
 * Current listing filters, from the URL (read-only, sanitised).
 *
 * @return array {s, category, source, sort}
 */
function ttn_current_filters() {
	// phpcs:disable WordPress.Security.NonceVerification.Recommended -- read-only display filters.
	$filters = array(
		's'        => isset( $_GET['s'] ) ? sanitize_text_field( wp_unslash( $_GET['s'] ) ) : '',
		'category' => isset( $_GET['teatatu_news_category'] ) ? sanitize_title( wp_unslash( $_GET['teatatu_news_category'] ) ) : '',
		'source'   => isset( $_GET['teatatu_news_source'] ) ? sanitize_title( wp_unslash( $_GET['teatatu_news_source'] ) ) : '',
		'sort'     => isset( $_GET['sort'] ) ? sanitize_key( wp_unslash( $_GET['sort'] ) ) : '',
	);
	// phpcs:enable
	if ( ! in_array( $filters['sort'], array( 'oldest', 'title' ), true ) ) {
		$filters['sort'] = '';
	}
	return $filters;
}

/**
 * The current listing's URL without its query string or page number: the
 * term link on a Source/Category archive, otherwise the News archive.
 *
 * @return string
 */
function ttn_current_base_url() {
	if ( is_tax() ) {
		$link = get_term_link( get_queried_object() );
		if ( ! is_wp_error( $link ) ) {
			return $link;
		}
	}
	return ttn_archive_url();
}

/**
 * URL for the current listing with the given filters.
 *
 * @param array  $filters Query args (empty values are dropped).
 * @param string $base    Base URL (defaults to the current listing).
 * @return string
 */
function ttn_filters_url( $filters, $base = '' ) {
	$args = array();
	foreach ( $filters as $key => $value ) {
		if ( '' !== (string) $value ) {
			$args[ $key ] = rawurlencode( $value );
		}
	}
	return add_query_arg( $args, $base ? $base : ttn_current_base_url() );
}

/**
 * Heading for a listing: "News from Te Atatū Peninsula", "Community
 * news", "News from <source>", or the search.
 *
 * @return string
 */
function ttn_results_heading() {
	$filters = ttn_current_filters();
	if ( is_tax( 'teatatu_news_category' ) ) {
		/* translators: %s: category name. */
		return sprintf( __( '%s news', 'teatatu-news-theme' ), single_term_title( '', false ) );
	}
	if ( is_tax( 'teatatu_news_source' ) ) {
		/* translators: %s: source name. */
		return sprintf( __( 'News from %s', 'teatatu-news-theme' ), single_term_title( '', false ) );
	}
	if ( '' !== $filters['s'] ) {
		/* translators: %s: search words. */
		return sprintf( __( 'News matching “%s”', 'teatatu-news-theme' ), $filters['s'] );
	}
	return __( 'News from Te Atatū Peninsula', 'teatatu-news-theme' );
}

/**
 * Label for an active filter chip.
 *
 * @param string $key   Filter key.
 * @param string $value Value.
 * @return string
 */
function ttn_filter_chip_label( $key, $value ) {
	switch ( $key ) {
		case 's':
			/* translators: %s: search text. */
			return sprintf( __( '“%s”', 'teatatu-news-theme' ), $value );
		case 'category':
			$term = get_term_by( 'slug', $value, 'teatatu_news_category' );
			return $term ? $term->name : $value;
		case 'source':
			$term = get_term_by( 'slug', $value, 'teatatu_news_source' );
			return $term ? $term->name : $value;
	}
	return (string) $value;
}

/**
 * Section links under the header when no menu is assigned.
 *
 * With the News plugin active: Latest, the most used topics, and What's on
 * when the Events plugin is active. Without it the header is not left
 * empty: Home, the site's top pages, and What's on (same rule). The
 * current section gets aria-current (underlined in the site colour).
 */
function ttn_primary_menu_fallback() {
	$links = array();
	if ( ttn_plugin_ready() ) {
		$links[] = array( __( 'Latest', 'teatatu-news-theme' ), ttn_archive_url(), is_post_type_archive( 'teatatu_news' ) );
		$terms   = get_terms(
			array(
				'taxonomy'   => 'teatatu_news_category',
				'hide_empty' => true,
				'orderby'    => 'count',
				'order'      => 'DESC',
				'number'     => 4,
			)
		);
		if ( $terms && ! is_wp_error( $terms ) ) {
			foreach ( $terms as $term ) {
				$links[] = array( $term->name, get_term_link( $term ), is_tax( 'teatatu_news_category', $term->term_id ) );
			}
		}
	} else {
		$links[] = array( __( 'Home', 'teatatu-news-theme' ), home_url( '/' ), is_front_page() );
		foreach ( get_pages( array( 'parent' => 0, 'sort_column' => 'menu_order,post_title', 'number' => 5 ) ) as $page ) {
			$links[] = array( get_the_title( $page ), get_permalink( $page ), is_page( $page->ID ) );
		}
	}
	if ( ttn_section_active( 'events' ) ) {
		$links[] = array( __( 'What’s on', 'teatatu-news-theme' ), ttn_events_url(), false );
	}
	foreach ( $links as $link ) {
		printf( '<a href="%s"%s>%s</a>', esc_url( $link[1] ), $link[2] ? ' aria-current="page"' : '', esc_html( $link[0] ) );
	}
}

/**
 * Prints a <select> of options.
 *
 * @param string $name    Field name.
 * @param string $id      Element ID.
 * @param array  $options value => label (first is the "any" option).
 * @param mixed  $current Current value.
 */
function ttn_select( $name, $id, $options, $current ) {
	printf( '<select class="ttn-control" name="%s" id="%s">', esc_attr( $name ), esc_attr( $id ) );
	foreach ( $options as $value => $label ) {
		printf( '<option value="%s"%s>%s</option>', esc_attr( $value ), selected( (string) $current, (string) $value, false ), esc_html( $label ) );
	}
	echo '</select>';
}

/**
 * Options for a taxonomy dropdown: slug => name, with a leading "any".
 *
 * @param string $taxonomy Taxonomy.
 * @param string $any      Label of the "any" option.
 * @return array
 */
function ttn_term_options( $taxonomy, $any ) {
	$options = array( '' => $any );
	$terms   = get_terms( array( 'taxonomy' => $taxonomy, 'hide_empty' => true ) );
	if ( $terms && ! is_wp_error( $terms ) ) {
		foreach ( $terms as $term ) {
			$options[ $term->slug ] = $term->name;
		}
	}
	return $options;
}

/**
 * Notice shown in place of stories when the plugin is missing.
 */
function ttn_plugin_missing_message() {
	echo '<div class="ttn-empty"><p>' . esc_html__( 'News is not available right now.', 'teatatu-news-theme' ) . '</p></div>';
}
