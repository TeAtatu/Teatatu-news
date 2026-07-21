<?php
/**
 * RSS import engine: a recurring WP-Cron tick checks each active feed
 * against its own configured cadence, fetches it with fetch_feed() (core
 * SimplePie wrapper — no bundled library), and for every item either
 * creates a new News post (new URL), keeps an unreviewed draft in sync
 * (same URL, not yet published), or — if the matching post has since been
 * published — either applies the freshly-fetched fields directly or stashes
 * them as a pending update for a human to review, depending on that feed's
 * own settings.
 *
 * Draft-first and human-gated re-imports remain the default for every feed
 * (new items become `draft`; published items only ever get a pending
 * update). Each feed can independently opt into "Auto-Publish New Items"
 * and/or "Auto-Apply Updates to Published Items" — see
 * includes/rss-feeds.php — but only a user who already holds
 * publish_teatatu_news_items / edit_published_teatatu_news_items can turn
 * those on for a given feed, so the underlying capability boundary is
 * unchanged; what's opt-in is skipping the *review step* for feeds a human
 * with real publish rights has explicitly decided to trust.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

const TEATATU_NEWS_RSS_MAX_ITEMS_PER_FEED = 20;
const TEATATU_NEWS_RSS_MAX_PAGE_BYTES     = 2 * MB_IN_BYTES;

// -----------------------------------------------------------------------
// Cron scheduling
// -----------------------------------------------------------------------

add_filter( 'cron_schedules', 'teatatu_news_rss_cron_schedules' ); // phpcs:ignore WordPress.WP.CronInterval.CronSchedulesInterval

/**
 * Registers a 5-minute cron interval — the finest cadence a feed can use.
 *
 * @param array $schedules Existing schedules.
 * @return array
 */
function teatatu_news_rss_cron_schedules( $schedules ) {
	$schedules['teatatu_news_five_minutes'] = array(
		'interval' => 5 * MINUTE_IN_SECONDS,
		'display'  => __( 'Every 5 Minutes (Teatatu News RSS)', 'teatatu-news' ),
	);
	return $schedules;
}

/**
 * Schedules the recurring RSS check tick, if not already scheduled. Also
 * hooked to 'init' as a self-healing safety net — WP-Cron schedules can
 * occasionally go missing outside of deactivation (cleared option tables,
 * staging/migration resets, etc.); this check is cheap (one cached lookup)
 * and idempotent, so re-running it on every request is the standard
 * pattern for a recurring event that must keep working.
 */
function teatatu_news_rss_schedule_cron() {
	if ( ! wp_next_scheduled( 'teatatu_news_rss_cron_tick' ) ) {
		wp_schedule_event( time(), 'teatatu_news_five_minutes', 'teatatu_news_rss_cron_tick' );
	}
}
add_action( 'init', 'teatatu_news_rss_schedule_cron' );

/**
 * Clears the recurring RSS check tick.
 */
function teatatu_news_rss_unschedule_cron() {
	wp_clear_scheduled_hook( 'teatatu_news_rss_cron_tick' );
}

add_action( 'teatatu_news_rss_cron_tick', 'teatatu_news_rss_run_due_feeds' );

/**
 * Runs on every 5-minute tick: processes only the active feeds whose own
 * cadence has actually elapsed since they were last checked.
 */
function teatatu_news_rss_run_due_feeds() {
	$feed_ids = get_posts(
		array(
			'post_type'      => 'teatatu_news_rssfeed',
			'post_status'    => 'publish',
			'posts_per_page' => -1,
			'fields'         => 'ids',
			'meta_query'     => array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
				array(
					'key'   => '_teatatu_news_rss_active',
					'value' => '1',
				),
			),
		)
	);

	$now = time();
	foreach ( $feed_ids as $feed_id ) {
		$cadence      = max( 5, (int) get_post_meta( $feed_id, '_teatatu_news_rss_cadence', true ) );
		$last_checked = (int) get_post_meta( $feed_id, '_teatatu_news_rss_last_checked', true );
		if ( $now - $last_checked >= $cadence * MINUTE_IN_SECONDS ) {
			teatatu_news_rss_process_feed( $feed_id );
		}
	}
}

// -----------------------------------------------------------------------
// Per-feed processing
// -----------------------------------------------------------------------

/**
 * Fetches one feed and imports/updates its items. Always stamps
 * last-checked, even on failure, so a persistently broken feed doesn't get
 * hammered every tick.
 *
 * @param int $feed_id RSS Feed post ID.
 */
function teatatu_news_rss_process_feed( $feed_id ) {
	$url = get_post_meta( $feed_id, '_teatatu_news_rss_url', true );
	if ( ! $url ) {
		return;
	}
	$cadence = max( 5, (int) get_post_meta( $feed_id, '_teatatu_news_rss_cadence', true ) );

	// fetch_feed()'s own SimplePie cache defaults to a much longer lifetime
	// than most feed cadences; without this override a fast cadence would
	// keep re-reading stale cached data instead of actually re-checking.
	$lifetime_cb = function () use ( $cadence ) {
		return max( 5 * MINUTE_IN_SECONDS, ( $cadence * MINUTE_IN_SECONDS ) - MINUTE_IN_SECONDS );
	};
	add_filter( 'wp_feed_cache_transient_lifetime', $lifetime_cb );
	$feed = fetch_feed( $url );
	remove_filter( 'wp_feed_cache_transient_lifetime', $lifetime_cb );

	update_post_meta( $feed_id, '_teatatu_news_rss_last_checked', time() );

	if ( is_wp_error( $feed ) ) {
		update_post_meta( $feed_id, '_teatatu_news_rss_last_error', $feed->get_error_message() );
		return;
	}
	update_post_meta( $feed_id, '_teatatu_news_rss_last_error', '' );

	$field_map         = teatatu_news_rss_get_field_map( $feed_id );
	$default_source    = (int) get_post_meta( $feed_id, '_teatatu_news_rss_default_source', true );
	$default_category  = (int) get_post_meta( $feed_id, '_teatatu_news_rss_default_category', true );
	$auto_publish      = (bool) get_post_meta( $feed_id, '_teatatu_news_rss_auto_publish', true );
	$auto_apply_update = (bool) get_post_meta( $feed_id, '_teatatu_news_rss_auto_apply_updates', true );

	$items           = $feed->get_items( 0, TEATATU_NEWS_RSS_MAX_ITEMS_PER_FEED );
	$page_html_cache = array();

	foreach ( $items as $item ) {
		teatatu_news_rss_import_item( $feed_id, $item, $field_map, $default_source, $default_category, $auto_publish, $auto_apply_update, $page_html_cache );
	}
}

/**
 * Imports or updates one feed item.
 *
 * @param int             $feed_id           RSS Feed post ID.
 * @param SimplePie_Item  $item              Feed item.
 * @param array           $field_map         Per-field mapping config.
 * @param int             $default_source    Default Source term ID, or 0.
 * @param int             $default_category  Default Category term ID, or 0.
 * @param bool            $auto_publish      Whether new items from this feed publish immediately instead of saving as draft.
 * @param bool            $auto_apply_update Whether changes to an already-published item apply immediately instead of becoming a pending update.
 * @param array           $page_html_cache   Reference to a per-run cache of fetched article pages, keyed by URL.
 */
function teatatu_news_rss_import_item( $feed_id, $item, $field_map, $default_source, $default_category, $auto_publish, $auto_apply_update, &$page_html_cache ) {
	$link = $item->get_permalink();
	if ( ! $link ) {
		return;
	}
	$link = esc_url_raw( $link );

	$needs_page = ( 'page' === $field_map['title']['source'] )
		|| ( 'page' === $field_map['excerpt']['source'] )
		|| ( 'page' === $field_map['image']['source'] );

	$page_html = '';
	if ( $needs_page ) {
		if ( ! array_key_exists( $link, $page_html_cache ) ) {
			$page_html_cache[ $link ] = teatatu_news_rss_fetch_page_html( $link );
		}
		$page_html = $page_html_cache[ $link ];
	}

	$title   = teatatu_news_rss_resolve_text_field( $item, $field_map['title'], $page_html, 'title' );
	$excerpt = teatatu_news_rss_resolve_text_field( $item, $field_map['excerpt'], $page_html, 'excerpt' );
	$image   = teatatu_news_rss_resolve_image_field( $item, $field_map['image'], $page_html );

	if ( '' === $title ) {
		return;
	}

	$existing_id = teatatu_news_rss_find_existing_post( $feed_id, $link );

	if ( ! $existing_id ) {
		teatatu_news_rss_create_post( $feed_id, $link, $title, $excerpt, $image, $default_source, $default_category, $auto_publish );
		return;
	}

	if ( 'publish' !== get_post_status( $existing_id ) ) {
		wp_update_post(
			array(
				'ID'           => $existing_id,
				'post_title'   => $title,
				'post_excerpt' => $excerpt,
			),
			true
		);
		update_post_meta( $existing_id, '_teatatu_news_image_url', $image );
		return;
	}

	teatatu_news_rss_maybe_flag_pending_update( $existing_id, $title, $excerpt, $image, $auto_apply_update );
}

/**
 * Creates a new News post for a not-yet-seen feed item — as a draft by
 * default, or published immediately if the feed has Auto-Publish enabled.
 *
 * @param int    $feed_id          RSS Feed post ID.
 * @param string $link             Article URL (also the dedupe key).
 * @param string $title            Resolved title.
 * @param string $excerpt          Resolved excerpt.
 * @param string $image            Resolved image URL.
 * @param int    $default_source   Default Source term ID, or 0.
 * @param int    $default_category Default Category term ID, or 0.
 * @param bool   $auto_publish     Whether to publish immediately instead of saving as draft.
 */
function teatatu_news_rss_create_post( $feed_id, $link, $title, $excerpt, $image, $default_source, $default_category, $auto_publish ) {
	$new_id = wp_insert_post(
		array(
			'post_type'    => 'teatatu_news',
			'post_title'   => $title,
			'post_excerpt' => $excerpt,
			'post_status'  => $auto_publish ? 'publish' : 'draft',
		),
		true
	);
	if ( is_wp_error( $new_id ) ) {
		return;
	}

	update_post_meta( $new_id, '_teatatu_news_read_more_url', $link );
	update_post_meta( $new_id, '_teatatu_news_image_url', $image );
	update_post_meta( $new_id, '_teatatu_news_rss_feed_id', $feed_id );

	if ( $default_source ) {
		wp_set_post_terms( $new_id, array( $default_source ), 'teatatu_news_source' );
	}
	if ( $default_category ) {
		wp_set_post_terms( $new_id, array( $default_category ), 'teatatu_news_category' );
	}
}

/**
 * Stashes freshly-fetched field values as a pending update on an already
 * published post — or, if the feed has Auto-Apply Updates enabled, applies
 * them immediately instead — but only if something actually changed.
 *
 * @param int    $post_id     Existing, published News post ID.
 * @param string $title       Resolved title.
 * @param string $excerpt     Resolved excerpt.
 * @param string $image       Resolved image URL.
 * @param bool   $auto_apply  Whether to apply immediately instead of stashing a pending update.
 */
function teatatu_news_rss_maybe_flag_pending_update( $post_id, $title, $excerpt, $image, $auto_apply ) {
	// Raw context on both sides: get_the_title()/the display context of
	// get_post_field() run values through filters like wptexturize(), which
	// would make an unchanged title/excerpt spuriously compare as different.
	$current_title   = get_post_field( 'post_title', $post_id, 'raw' );
	$current_excerpt = get_post_field( 'post_excerpt', $post_id, 'raw' );
	$current_image   = get_post_meta( $post_id, '_teatatu_news_image_url', true );

	if ( $title === $current_title && $excerpt === $current_excerpt && $image === $current_image ) {
		return;
	}

	if ( $auto_apply ) {
		teatatu_news_rss_apply_fields_to_post( $post_id, $title, $excerpt, $image );
		delete_post_meta( $post_id, '_teatatu_news_rss_pending_update' );
		return;
	}

	update_post_meta(
		$post_id,
		'_teatatu_news_rss_pending_update',
		array(
			'title'       => $title,
			'excerpt'     => $excerpt,
			'image_url'   => $image,
			'detected_at' => time(),
		)
	);
}

/**
 * Writes resolved Title/Excerpt/Image values directly onto an existing
 * post. Shared by the auto-apply path above and the manual "Apply" admin
 * action, so both take the exact same effect.
 *
 * @param int    $post_id Post ID.
 * @param string $title   Title.
 * @param string $excerpt Excerpt.
 * @param string $image   Image URL.
 */
function teatatu_news_rss_apply_fields_to_post( $post_id, $title, $excerpt, $image ) {
	wp_update_post(
		array(
			'ID'           => $post_id,
			'post_title'   => $title,
			'post_excerpt' => $excerpt,
		),
		true
	);
	update_post_meta( $post_id, '_teatatu_news_image_url', $image );
}

/**
 * Finds a News post previously imported from this feed with this URL.
 *
 * @param int    $feed_id RSS Feed post ID.
 * @param string $link    Article URL.
 * @return int Post ID, or 0 if none found.
 */
function teatatu_news_rss_find_existing_post( $feed_id, $link ) {
	$posts = get_posts(
		array(
			'post_type'      => 'teatatu_news',
			'post_status'    => 'any',
			'posts_per_page' => 1,
			'fields'         => 'ids',
			'meta_query'     => array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
				'relation' => 'AND',
				array(
					'key'   => '_teatatu_news_rss_feed_id',
					'value' => $feed_id,
				),
				array(
					'key'   => '_teatatu_news_read_more_url',
					'value' => $link,
				),
			),
		)
	);
	return ! empty( $posts ) ? (int) $posts[0] : 0;
}

// -----------------------------------------------------------------------
// Field mapping resolution
// -----------------------------------------------------------------------

/**
 * Fetches an article page's raw HTML for "page" source fields. Admin- or
 * editor-configured feed URLs are trusted, but the individual article URLs
 * inside a feed come from the feed publisher, not our own admin — so this
 * still goes through wp_safe_remote_get() with a size cap, same posture as
 * any other plugin-initiated fetch of a third-party-supplied URL.
 *
 * @param string $url Article URL.
 * @return string HTML, or '' on failure.
 */
function teatatu_news_rss_fetch_page_html( $url ) {
	$response = wp_safe_remote_get(
		$url,
		array(
			'timeout'             => 15,
			'limit_response_size' => TEATATU_NEWS_RSS_MAX_PAGE_BYTES,
			'headers'             => array( 'Accept' => 'text/html' ),
		)
	);
	if ( is_wp_error( $response ) || 200 !== (int) wp_remote_retrieve_response_code( $response ) ) {
		return '';
	}
	return (string) wp_remote_retrieve_body( $response );
}

/**
 * Reads a feed item's own raw title/description/content HTML.
 *
 * @param SimplePie_Item $item   Feed item.
 * @param string         $source 'rss_title', 'rss_description', or 'rss_content'.
 * @return string
 */
function teatatu_news_rss_get_source_html( $item, $source ) {
	switch ( $source ) {
		case 'rss_title':
			return (string) $item->get_title();
		case 'rss_description':
			return (string) $item->get_description();
		case 'rss_content':
			$content = $item->get_content();
			return $content ? (string) $content : (string) $item->get_description();
		default:
			return '';
	}
}

/**
 * Resolves a text field (Title or Excerpt) per its mapping config.
 *
 * @param SimplePie_Item $item       Feed item.
 * @param array          $mapping    This field's mapping config.
 * @param string         $page_html  Pre-fetched article page HTML (only used when source is 'page').
 * @param string         $field_type 'title' (plain text) or 'excerpt' (links preserved).
 * @return string
 */
function teatatu_news_rss_resolve_text_field( $item, $mapping, $page_html, $field_type ) {
	$source = $mapping['source'];

	if ( 'page' === $source && ! $mapping['tag'] && ! $mapping['attr_value'] ) {
		return '';
	}

	$raw_html = 'page' === $source ? $page_html : teatatu_news_rss_get_source_html( $item, $source );
	if ( '' === trim( (string) $raw_html ) ) {
		return '';
	}

	if ( $mapping['tag'] || $mapping['attr_value'] ) {
		$node     = teatatu_news_rss_select_element( $raw_html, $mapping['tag'], $mapping['attr_type'], $mapping['attr_value'] );
		$raw_html = $node ? teatatu_news_rss_node_inner_html( $node ) : '';
	}

	if ( 'title' === $field_type ) {
		return trim( wp_strip_all_tags( $raw_html ) );
	}

	return trim( teatatu_news_rss_clean_html( $raw_html ) );
}

/**
 * Resolves the Image URL field per its mapping config.
 *
 * @param SimplePie_Item $item      Feed item.
 * @param array          $mapping   This field's mapping config.
 * @param string         $page_html Pre-fetched article page HTML (only used when source is 'page').
 * @return string
 */
function teatatu_news_rss_resolve_image_field( $item, $mapping, $page_html ) {
	$source = $mapping['source'];

	if ( 'rss_image' === $source ) {
		$enclosure = $item->get_enclosure();
		if ( ! $enclosure ) {
			return '';
		}
		$url = $enclosure->get_link();
		if ( ! $url ) {
			$url = $enclosure->get_thumbnail();
		}
		return $url ? esc_url_raw( $url ) : '';
	}

	if ( 'page' === $source && ! $mapping['tag'] && ! $mapping['attr_value'] ) {
		return '';
	}

	$raw_html = 'page' === $source ? $page_html : teatatu_news_rss_get_source_html( $item, $source );
	if ( '' === trim( (string) $raw_html ) ) {
		return '';
	}

	$node = teatatu_news_rss_select_element( $raw_html, $mapping['tag'], $mapping['attr_type'], $mapping['attr_value'] );
	if ( ! $node ) {
		return '';
	}
	$url = teatatu_news_rss_node_image_url( $node );
	return $url ? esc_url_raw( $url ) : '';
}

// -----------------------------------------------------------------------
// HTML element selection (tag + class/id), using core PHP DOM — no
// external library.
// -----------------------------------------------------------------------

/**
 * Finds the first element matching a tag and/or class/id within an HTML
 * fragment. With neither tag nor class/id given, returns the fragment's
 * own wrapper root (i.e. "the whole thing").
 *
 * @param string $html       HTML fragment.
 * @param string $tag        Lowercase tag name (already sanitized to [a-z0-9]), or ''.
 * @param string $attr_type  'class' or 'id'.
 * @param string $attr_value Already-sanitized class/id name (sanitize_html_class()), or ''.
 * @return DOMElement|null
 */
function teatatu_news_rss_select_element( $html, $tag, $attr_type, $attr_value ) {
	$html = (string) $html;
	if ( '' === trim( $html ) ) {
		return null;
	}

	$tag        = $tag ? preg_replace( '/[^a-z0-9]/', '', strtolower( $tag ) ) : '';
	$attr_value = $attr_value ? sanitize_html_class( $attr_value ) : '';

	$dom  = new DOMDocument();
	$prev = libxml_use_internal_errors( true );
	$dom->loadHTML( '<?xml encoding="UTF-8">' . $html, LIBXML_NOERROR | LIBXML_NOWARNING | LIBXML_NONET );
	libxml_clear_errors();
	libxml_use_internal_errors( $prev );

	// loadHTML() always wraps whatever was given in exactly one <html><body>
	// pair, so scoping every lookup to //body// reliably targets only our
	// fragment's own content, without depending on getElementById()'s
	// HTML-specific (and not universally guaranteed) ID-attribute handling.
	$body = $dom->getElementsByTagName( 'body' )->item( 0 );
	if ( ! $body ) {
		return null;
	}

	if ( ! $tag && ! $attr_value ) {
		return $body;
	}

	$xpath     = new DOMXPath( $dom );
	$xpath_tag = $tag ? $tag : '*';
	if ( $attr_value && in_array( $attr_type, array( 'class', 'id' ), true ) ) {
		if ( 'class' === $attr_type ) {
			$query = sprintf( "//body//%s[contains(concat(' ', normalize-space(@class), ' '), ' %s ')]", $xpath_tag, $attr_value );
		} else {
			$query = sprintf( "//body//%s[@id='%s']", $xpath_tag, $attr_value );
		}
	} else {
		$query = '//body//' . $xpath_tag;
	}

	$nodes = $xpath->query( $query );
	if ( ! $nodes || 0 === $nodes->length ) {
		return null;
	}
	return $nodes->item( 0 );
}

/**
 * Returns a DOM element's inner HTML (its children's markup, concatenated).
 *
 * @param DOMNode $node Element.
 * @return string
 */
function teatatu_news_rss_node_inner_html( $node ) {
	$html = '';
	foreach ( $node->childNodes as $child ) {
		$html .= $node->ownerDocument->saveHTML( $child );
	}
	return $html;
}

/**
 * Finds an image URL within a DOM element: its own src if it's an <img>,
 * otherwise the src of the first <img> descendant.
 *
 * @param DOMNode $node Element.
 * @return string
 */
function teatatu_news_rss_node_image_url( $node ) {
	if ( ! ( $node instanceof DOMElement ) ) {
		return '';
	}
	if ( 'img' === strtolower( $node->tagName ) ) {
		return $node->getAttribute( 'src' );
	}
	$imgs = $node->getElementsByTagName( 'img' );
	if ( $imgs->length > 0 ) {
		return $imgs->item( 0 )->getAttribute( 'src' );
	}
	return '';
}

/**
 * Strips all HTML formatting except links, per the mapping requirement
 * that only <a> tags survive into a News item's excerpt.
 *
 * @param string $html Raw HTML.
 * @return string
 */
function teatatu_news_rss_clean_html( $html ) {
	return wp_kses(
		(string) $html,
		array(
			'a' => array(
				'href'   => true,
				'title'  => true,
				'target' => true,
				'rel'    => true,
			),
		)
	);
}

// -----------------------------------------------------------------------
// Pending-update review (Apply / Dismiss) and manual "Check Now"
// -----------------------------------------------------------------------

add_action( 'admin_post_teatatu_news_apply_rss_update', 'teatatu_news_handle_apply_rss_update' );

/**
 * Applies a pending RSS update to its published post.
 */
function teatatu_news_handle_apply_rss_update() {
	$post_id = isset( $_GET['post_id'] ) ? absint( $_GET['post_id'] ) : 0;
	check_admin_referer( 'teatatu_news_apply_rss_update_' . $post_id );

	if ( ! $post_id || 'teatatu_news' !== get_post_type( $post_id ) || ! current_user_can( 'edit_post', $post_id ) ) {
		wp_die( esc_html__( 'You do not have permission to do that.', 'teatatu-news' ), 403 );
	}

	$pending = get_post_meta( $post_id, '_teatatu_news_rss_pending_update', true );
	if ( is_array( $pending ) ) {
		teatatu_news_rss_apply_fields_to_post( $post_id, $pending['title'], $pending['excerpt'], $pending['image_url'] );
		delete_post_meta( $post_id, '_teatatu_news_rss_pending_update' );
	}

	set_transient( 'teatatu_news_admin_notice_' . get_current_user_id(), __( 'RSS update applied.', 'teatatu-news' ), 45 );
	wp_safe_redirect( add_query_arg( array( 'page' => 'teatatu-news', 'tab' => 'news' ), admin_url( 'admin.php' ) ) );
	exit;
}

add_action( 'admin_post_teatatu_news_dismiss_rss_update', 'teatatu_news_handle_dismiss_rss_update' );

/**
 * Dismisses a pending RSS update without applying it.
 */
function teatatu_news_handle_dismiss_rss_update() {
	$post_id = isset( $_GET['post_id'] ) ? absint( $_GET['post_id'] ) : 0;
	check_admin_referer( 'teatatu_news_dismiss_rss_update_' . $post_id );

	if ( ! $post_id || 'teatatu_news' !== get_post_type( $post_id ) || ! current_user_can( 'edit_post', $post_id ) ) {
		wp_die( esc_html__( 'You do not have permission to do that.', 'teatatu-news' ), 403 );
	}

	delete_post_meta( $post_id, '_teatatu_news_rss_pending_update' );

	set_transient( 'teatatu_news_admin_notice_' . get_current_user_id(), __( 'RSS update dismissed.', 'teatatu-news' ), 45 );
	wp_safe_redirect( add_query_arg( array( 'page' => 'teatatu-news', 'tab' => 'news' ), admin_url( 'admin.php' ) ) );
	exit;
}

add_action( 'admin_post_teatatu_news_check_rss_feed_now', 'teatatu_news_handle_check_rss_feed_now' );

/**
 * Runs one feed's check immediately, bypassing its cadence — for
 * verifying a mapping configuration without waiting on cron.
 */
function teatatu_news_handle_check_rss_feed_now() {
	$feed_id = isset( $_GET['feed_id'] ) ? absint( $_GET['feed_id'] ) : 0;
	check_admin_referer( 'teatatu_news_check_rss_feed_now_' . $feed_id );

	if ( ! $feed_id || 'teatatu_news_rssfeed' !== get_post_type( $feed_id ) || ! current_user_can( 'manage_teatatu_news_rss_feeds' ) ) {
		wp_die( esc_html__( 'You do not have permission to do that.', 'teatatu-news' ), 403 );
	}

	teatatu_news_rss_process_feed( $feed_id );

	set_transient( 'teatatu_news_admin_notice_' . get_current_user_id(), __( 'Feed checked.', 'teatatu-news' ), 45 );
	wp_safe_redirect( add_query_arg( array( 'page' => 'teatatu-news', 'tab' => 'rss' ), admin_url( 'admin.php' ) ) );
	exit;
}
