<?php
/**
 * HTML page import engine — the counterpart to includes/rss-importer.php
 * for sites with a news/events listing page but no RSS/Atom feed. A
 * recurring WP-Cron tick checks each active HTML feed against its own
 * cadence, fetches the configured Page URL with a plain HTTP GET (no
 * SimplePie involved — this is just a webpage), finds every element
 * matching the feed's Repeating Item selector, and for each one either
 * creates a new News post, keeps an unreviewed draft in sync, or applies/
 * queues an update to an already-published post — exactly the same
 * create/resync/pending-update shape as RSS import.
 *
 * Deliberately reuses several already-generic RSS importer building blocks
 * rather than duplicating them:
 *  - teatatu_news_rss_fetch_page_html() for the HTTP fetch itself.
 *  - teatatu_news_rss_select_element() / _node_inner_html() / _node_image_url()
 *    / _node_link_url() / _node_date_gmt() / _clean_html() for all DOM work —
 *    none of that logic is actually RSS-specific, it's plain HTML-fragment
 *    parsing.
 *  - teatatu_news_rss_apply_pub_date() for the post_date/post_date_gmt write.
 *  - teatatu_news_rss_maybe_flag_pending_update() / _apply_fields_to_post()
 *    for the pending-update stash/apply logic, and the SAME
 *    `_teatatu_news_rss_pending_update` meta key + the News tab's existing
 *    Apply/Dismiss admin-post.php handlers — a pending update is just
 *    "fields waiting to be reviewed," regardless of which importer produced
 *    them, so HTML-sourced updates show up and get applied/dismissed
 *    through the exact same UI as RSS ones with zero extra code.
 * The "rss_" prefix on those shared names is a historical artifact of which
 * importer was built first, not a sign they're RSS-only — see prompt.md.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

const TEATATU_NEWS_HTML_MAX_ITEMS_PER_FEED = 20;

// -----------------------------------------------------------------------
// Cron scheduling
// -----------------------------------------------------------------------

/**
 * Schedules the recurring HTML feed check tick, if not already scheduled.
 * Reuses the 'teatatu_news_five_minutes' schedule registered by
 * includes/rss-importer.php (both files are always loaded together). Also
 * hooked to 'init' as a self-healing safety net, same reasoning as RSS's
 * equivalent function.
 */
function teatatu_news_html_schedule_cron() {
	if ( ! wp_next_scheduled( 'teatatu_news_html_cron_tick' ) ) {
		wp_schedule_event( time(), 'teatatu_news_five_minutes', 'teatatu_news_html_cron_tick' );
	}
}
add_action( 'init', 'teatatu_news_html_schedule_cron' );

/**
 * Clears the recurring HTML feed check tick.
 */
function teatatu_news_html_unschedule_cron() {
	wp_clear_scheduled_hook( 'teatatu_news_html_cron_tick' );
}

add_action( 'teatatu_news_html_cron_tick', 'teatatu_news_html_run_due_feeds' );

/**
 * Runs on every 5-minute tick: processes only the active HTML feeds whose
 * own cadence has actually elapsed since they were last checked.
 */
function teatatu_news_html_run_due_feeds() {
	$feed_ids = get_posts(
		array(
			'post_type'      => 'teatatu_news_webfeed',
			'post_status'    => 'publish',
			'posts_per_page' => -1,
			'fields'         => 'ids',
			'meta_query'     => array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
				array(
					'key'   => '_teatatu_news_html_active',
					'value' => '1',
				),
			),
		)
	);

	$now = time();
	foreach ( $feed_ids as $feed_id ) {
		$cadence      = max( 5, (int) get_post_meta( $feed_id, '_teatatu_news_html_cadence', true ) );
		$last_checked = (int) get_post_meta( $feed_id, '_teatatu_news_html_last_checked', true );
		if ( $now - $last_checked >= $cadence * MINUTE_IN_SECONDS ) {
			teatatu_news_html_process_feed( $feed_id );
		}
	}
}

// -----------------------------------------------------------------------
// Per-feed processing
// -----------------------------------------------------------------------

/**
 * Fetches one HTML feed's page and imports/updates its items. Always
 * stamps last-checked, even on failure, so a persistently broken feed
 * doesn't get hammered every tick.
 *
 * @param int $feed_id HTML Feed post ID.
 */
function teatatu_news_html_process_feed( $feed_id ) {
	$url = get_post_meta( $feed_id, '_teatatu_news_html_url', true );
	if ( ! $url ) {
		return;
	}

	update_post_meta( $feed_id, '_teatatu_news_html_last_checked', time() );

	$page_html = teatatu_news_rss_fetch_page_html( $url );
	if ( '' === trim( $page_html ) ) {
		update_post_meta( $feed_id, '_teatatu_news_html_last_error', __( 'Could not fetch the page, or it returned no content.', 'teatatu-news' ) );
		return;
	}

	$item_selector = teatatu_news_html_get_item_selector( $feed_id );
	$item_nodes    = teatatu_news_html_select_all_items( $page_html, $item_selector['tag'], $item_selector['attr_type'], $item_selector['attr_value'], TEATATU_NEWS_HTML_MAX_ITEMS_PER_FEED );

	if ( empty( $item_nodes ) ) {
		update_post_meta( $feed_id, '_teatatu_news_html_last_error', __( 'The Repeating Item selector matched no elements on the page.', 'teatatu-news' ) );
		return;
	}
	update_post_meta( $feed_id, '_teatatu_news_html_last_error', '' );

	$field_map         = teatatu_news_html_get_field_map( $feed_id );
	$default_source    = (int) get_post_meta( $feed_id, '_teatatu_news_html_default_source', true );
	$default_category  = (int) get_post_meta( $feed_id, '_teatatu_news_html_default_category', true );
	$auto_publish      = (bool) get_post_meta( $feed_id, '_teatatu_news_html_auto_publish', true );
	$auto_apply_update = (bool) get_post_meta( $feed_id, '_teatatu_news_html_auto_apply_updates', true );

	$page_html_cache = array();
	foreach ( $item_nodes as $node ) {
		$item_html = teatatu_news_rss_node_inner_html( $node );
		teatatu_news_html_import_item( $feed_id, $item_html, $url, $field_map, $default_source, $default_category, $auto_publish, $auto_apply_update, $page_html_cache );
	}
}

/**
 * Imports or updates one repeating item found on an HTML feed's page.
 *
 * @param int    $feed_id            HTML Feed post ID.
 * @param string $item_html          The matched repeating element's inner HTML.
 * @param string $base_url           The feed's own Page URL, for resolving relative links.
 * @param array  $field_map          Per-field mapping config.
 * @param int    $default_source     Default Source term ID, or 0.
 * @param int    $default_category   Default Category term ID, or 0.
 * @param bool   $auto_publish       Whether items from this feed publish immediately instead of saving/staying as draft.
 * @param bool   $auto_apply_update  Whether changes to an already-published item apply immediately instead of becoming a pending update.
 * @param array  $page_html_cache    Reference to a per-run cache of fetched article pages, keyed by URL.
 */
function teatatu_news_html_import_item( $feed_id, $item_html, $base_url, $field_map, $default_source, $default_category, $auto_publish, $auto_apply_update, &$page_html_cache ) {
	// Read More URL is this item's identity — it can only come from within
	// the item itself (see teatatu_news_html_field_source_options()), so
	// it's always safe to resolve first, before anything that might need
	// the linked page's HTML.
	$read_more_url = teatatu_news_html_resolve_read_more_url( $item_html, $field_map['read_more_url'], $base_url );
	if ( '' === $read_more_url ) {
		return;
	}

	$needs_page = ( 'page' === $field_map['title']['source'] )
		|| ( 'page' === $field_map['excerpt']['source'] )
		|| ( 'page' === $field_map['image']['source'] )
		|| ( 'page' === $field_map['pub_date']['source'] );

	$page_html = '';
	if ( $needs_page ) {
		if ( ! array_key_exists( $read_more_url, $page_html_cache ) ) {
			$page_html_cache[ $read_more_url ] = teatatu_news_rss_fetch_page_html( $read_more_url );
		}
		$page_html = $page_html_cache[ $read_more_url ];
	}

	$title        = teatatu_news_html_resolve_text_field( $item_html, $field_map['title'], $page_html, 'title' );
	$excerpt      = teatatu_news_html_resolve_text_field( $item_html, $field_map['excerpt'], $page_html, 'excerpt' );
	$image        = teatatu_news_html_resolve_image_field( $item_html, $field_map['image'], $page_html );
	$pub_date_gmt = teatatu_news_html_resolve_pub_date_field( $item_html, $field_map['pub_date'], $page_html );

	if ( '' === $title ) {
		return;
	}

	$existing_id = teatatu_news_html_find_existing_post( $feed_id, $read_more_url );

	if ( ! $existing_id ) {
		teatatu_news_html_create_post( $feed_id, $read_more_url, $title, $excerpt, $image, $default_source, $default_category, $auto_publish, $pub_date_gmt );
		return;
	}

	if ( 'publish' !== get_post_status( $existing_id ) ) {
		// Same "still promote on re-sync" rule as RSS import — see the
		// matching branch in teatatu_news_rss_import_item() for why this
		// also needs to re-check auto_publish, not just brand-new items.
		$update_args = array(
			'ID'           => $existing_id,
			'post_title'   => $title,
			'post_excerpt' => $excerpt,
		);
		if ( $auto_publish ) {
			$update_args['post_status'] = 'publish';
		}
		teatatu_news_rss_apply_pub_date( $update_args, $pub_date_gmt );

		wp_update_post( $update_args, true );
		update_post_meta( $existing_id, '_teatatu_news_image_url', $image );
		update_post_meta( $existing_id, '_teatatu_news_read_more_url', $read_more_url );
		if ( $pub_date_gmt ) {
			update_post_meta( $existing_id, '_teatatu_news_rss_pub_date', $pub_date_gmt );
		}
		return;
	}

	teatatu_news_rss_maybe_flag_pending_update( $existing_id, $title, $excerpt, $image, $read_more_url, $pub_date_gmt, $auto_apply_update );
}

/**
 * Creates a new News post for a not-yet-seen HTML feed item — as a draft by
 * default, or published immediately if the feed has Auto-Publish enabled.
 *
 * @param int    $feed_id          HTML Feed post ID.
 * @param string $read_more_url    Resolved Read More URL — this item's identity and what's displayed.
 * @param string $title            Resolved title.
 * @param string $excerpt          Resolved excerpt.
 * @param string $image            Resolved image URL.
 * @param int    $default_source   Default Source term ID, or 0.
 * @param int    $default_category Default Category term ID, or 0.
 * @param bool   $auto_publish     Whether to publish immediately instead of saving as draft.
 * @param string $pub_date_gmt     'Y-m-d H:i:s' in GMT, or ''.
 */
function teatatu_news_html_create_post( $feed_id, $read_more_url, $title, $excerpt, $image, $default_source, $default_category, $auto_publish, $pub_date_gmt ) {
	$postarr = array(
		'post_type'    => 'teatatu_news',
		'post_title'   => $title,
		'post_excerpt' => $excerpt,
		'post_status'  => $auto_publish ? 'publish' : 'draft',
	);
	teatatu_news_rss_apply_pub_date( $postarr, $pub_date_gmt );

	$new_id = wp_insert_post( $postarr, true );
	if ( is_wp_error( $new_id ) ) {
		return;
	}

	update_post_meta( $new_id, '_teatatu_news_read_more_url', $read_more_url );
	update_post_meta( $new_id, '_teatatu_news_html_source_link', $read_more_url );
	update_post_meta( $new_id, '_teatatu_news_image_url', $image );
	update_post_meta( $new_id, '_teatatu_news_html_feed_id', $feed_id );
	if ( $pub_date_gmt ) {
		update_post_meta( $new_id, '_teatatu_news_rss_pub_date', $pub_date_gmt );
	}

	if ( $default_source ) {
		wp_set_post_terms( $new_id, array( $default_source ), 'teatatu_news_source' );
	}
	if ( $default_category ) {
		wp_set_post_terms( $new_id, array( $default_category ), 'teatatu_news_category' );
	}
}

/**
 * Finds a News post previously imported from this HTML feed with this
 * Read More URL. Unlike RSS import, there's no separate "feed's own link"
 * to decouple from — Read More URL for an HTML feed can only ever come
 * from within the repeating item (see
 * teatatu_news_html_field_source_options()), so it already IS the stable
 * identity; no legacy-fallback matching is needed the way RSS's
 * _teatatu_news_rss_source_link required.
 *
 * @param int    $feed_id       HTML Feed post ID.
 * @param string $read_more_url Resolved Read More URL.
 * @return int Post ID, or 0 if none found.
 */
function teatatu_news_html_find_existing_post( $feed_id, $read_more_url ) {
	$posts = get_posts(
		array(
			'post_type'      => 'teatatu_news',
			'post_status'    => 'any',
			'posts_per_page' => 1,
			'fields'         => 'ids',
			'meta_query'     => array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
				'relation' => 'AND',
				array(
					'key'   => '_teatatu_news_html_feed_id',
					'value' => $feed_id,
				),
				array(
					'key'   => '_teatatu_news_html_source_link',
					'value' => $read_more_url,
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
 * Finds every element matching the Repeating Item selector on a page.
 * Unlike teatatu_news_rss_select_element() (which returns only the first
 * match, for narrowing a single field down to one element), this returns
 * every match up to $limit, since each one becomes a separate News item.
 * A selector is always required here — there is no sensible "whole page"
 * fallback the way an empty field-level selector falls back to "the whole
 * source" elsewhere in this plugin.
 *
 * @param string $html       Page HTML.
 * @param string $tag        Lowercase tag name (already sanitized to [a-z0-9]), or ''.
 * @param string $attr_type  'class' or 'id'.
 * @param string $attr_value Already-sanitized class/id name, or ''.
 * @param int    $limit      Maximum number of items to return.
 * @return DOMElement[]
 */
function teatatu_news_html_select_all_items( $html, $tag, $attr_type, $attr_value, $limit ) {
	$html = (string) $html;
	if ( '' === trim( $html ) ) {
		return array();
	}

	$tag        = $tag ? preg_replace( '/[^a-z0-9]/', '', strtolower( $tag ) ) : '';
	$attr_value = $attr_value ? sanitize_html_class( $attr_value ) : '';
	if ( ! $tag && ! $attr_value ) {
		return array();
	}

	$dom  = new DOMDocument();
	$prev = libxml_use_internal_errors( true );
	$dom->loadHTML( '<?xml encoding="UTF-8">' . $html, LIBXML_NOERROR | LIBXML_NOWARNING | LIBXML_NONET );
	libxml_clear_errors();
	libxml_use_internal_errors( $prev );

	$xpath     = new DOMXPath( $dom );
	$xpath_tag = $tag ? $tag : '*';
	if ( $attr_value && 'id' === $attr_type ) {
		// Element IDs are only supposed to be unique, but real pages don't
		// always honour that — match every element with this id, the same
		// as class matching, rather than assuming there's exactly one.
		$query = sprintf( "//body//%s[@id='%s']", $xpath_tag, $attr_value );
	} elseif ( $attr_value ) {
		$query = sprintf( "//body//%s[contains(concat(' ', normalize-space(@class), ' '), ' %s ')]", $xpath_tag, $attr_value );
	} else {
		$query = '//body//' . $xpath_tag;
	}

	$nodes = $xpath->query( $query );
	if ( ! $nodes || 0 === $nodes->length ) {
		return array();
	}

	$items = array();
	foreach ( $nodes as $node ) {
		$items[] = $node;
		if ( count( $items ) >= $limit ) {
			break;
		}
	}
	return $items;
}

/**
 * Resolves a text field (Title or Excerpt) per its mapping config. Unlike
 * RSS's equivalent, there is no "whole value" fallback for an unset
 * selector — an HTML repeating item mixes title/date/excerpt/links
 * together with no single field that's "just the text", so a selector is
 * always required.
 *
 * @param string $item_html  The repeating item's inner HTML.
 * @param array  $mapping    This field's mapping config.
 * @param string $page_html  Pre-fetched linked-page HTML (only used when source is 'page').
 * @param string $field_type 'title' (plain text) or 'excerpt' (links preserved).
 * @return string
 */
function teatatu_news_html_resolve_text_field( $item_html, $mapping, $page_html, $field_type ) {
	if ( ! $mapping['tag'] && ! $mapping['attr_value'] ) {
		return '';
	}
	$raw_html = 'page' === $mapping['source'] ? $page_html : $item_html;
	if ( '' === trim( (string) $raw_html ) ) {
		return '';
	}
	$node = teatatu_news_rss_select_element( $raw_html, $mapping['tag'], $mapping['attr_type'], $mapping['attr_value'] );
	if ( ! $node ) {
		return '';
	}
	$html = teatatu_news_rss_node_inner_html( $node );
	if ( 'title' === $field_type ) {
		return teatatu_news_rss_normalize_whitespace( wp_strip_all_tags( $html ) );
	}
	return teatatu_news_rss_normalize_whitespace( teatatu_news_rss_clean_html( $html ) );
}

/**
 * Resolves the Image URL field per its mapping config. With no selector
 * set, searches the whole item (or linked page) for its own first image —
 * a safe default, unlike text fields, since it looks for a specific
 * element type rather than concatenating everything's text together.
 *
 * @param string $item_html Item's inner HTML.
 * @param array  $mapping   This field's mapping config.
 * @param string $page_html Pre-fetched linked-page HTML (only used when source is 'page').
 * @return string
 */
function teatatu_news_html_resolve_image_field( $item_html, $mapping, $page_html ) {
	$raw_html = 'page' === $mapping['source'] ? $page_html : $item_html;
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

/**
 * Resolves the Read More URL field: always found within the repeating item
 * itself (see teatatu_news_html_field_source_options()). With no selector
 * set, searches the whole item for its own first link — which, on both
 * reference sites this feature was built against, already happens to be
 * the correct article link (the card's image or heading is usually wrapped
 * in it).
 *
 * @param string $item_html Item's inner HTML.
 * @param array  $mapping   This field's mapping config.
 * @param string $base_url  The feed's own Page URL, for resolving a relative href.
 * @return string
 */
function teatatu_news_html_resolve_read_more_url( $item_html, $mapping, $base_url ) {
	if ( '' === trim( (string) $item_html ) ) {
		return '';
	}
	$node = teatatu_news_rss_select_element( $item_html, $mapping['tag'], $mapping['attr_type'], $mapping['attr_value'] );
	if ( ! $node ) {
		return '';
	}
	$url = teatatu_news_rss_node_link_url( $node );
	if ( ! $url ) {
		return '';
	}
	return esc_url_raw( teatatu_news_html_resolve_relative_url( $url, $base_url ) );
}

/**
 * Resolves the Publish Date field per its mapping config. Always requires a
 * selector, same reasoning and same behavior as RSS's non-default pub_date
 * sources — there's no reliable way to find a date in unmarked text.
 *
 * @param string $item_html Item's inner HTML.
 * @param array  $mapping   This field's mapping config.
 * @param string $page_html Pre-fetched linked-page HTML (only used when source is 'page').
 * @return string 'Y-m-d H:i:s' in GMT, or '' if nothing could be resolved.
 */
function teatatu_news_html_resolve_pub_date_field( $item_html, $mapping, $page_html ) {
	if ( ! $mapping['tag'] && ! $mapping['attr_value'] ) {
		return '';
	}
	$raw_html = 'page' === $mapping['source'] ? $page_html : $item_html;
	if ( '' === trim( (string) $raw_html ) ) {
		return '';
	}
	$node = teatatu_news_rss_select_element( $raw_html, $mapping['tag'], $mapping['attr_type'], $mapping['attr_value'] );
	if ( ! $node ) {
		return '';
	}
	return teatatu_news_rss_node_date_gmt( $node );
}

/**
 * Resolves a possibly-relative URL (root-relative, scheme-relative, or
 * relative to the base page's own directory) against the feed's Page URL.
 * Real-world listing pages generally use either absolute URLs or
 * root-relative paths for their article links, both handled directly;
 * "../"-style traversal is not specially resolved, which is an accepted
 * simplification rather than a full RFC 3986 implementation.
 *
 * @param string $url      Extracted href, possibly relative.
 * @param string $base_url The feed's own Page URL.
 * @return string
 */
function teatatu_news_html_resolve_relative_url( $url, $base_url ) {
	$url = trim( (string) $url );
	if ( '' === $url || 0 === stripos( $url, 'javascript:' ) || 0 === stripos( $url, 'mailto:' ) ) {
		return '';
	}
	if ( preg_match( '#^[a-z][a-z0-9+.-]*://#i', $url ) ) {
		return $url;
	}

	$base = wp_parse_url( $base_url );
	if ( empty( $base['scheme'] ) || empty( $base['host'] ) ) {
		return $url;
	}
	$origin = $base['scheme'] . '://' . $base['host'] . ( isset( $base['port'] ) ? ':' . $base['port'] : '' );

	if ( 0 === strpos( $url, '//' ) ) {
		return $base['scheme'] . ':' . $url;
	}
	if ( 0 === strpos( $url, '/' ) ) {
		return $origin . $url;
	}

	$base_path = isset( $base['path'] ) ? $base['path'] : '/';
	$dir       = substr( $base_path, 0, strrpos( $base_path, '/' ) + 1 );
	return $origin . $dir . $url;
}

// -----------------------------------------------------------------------
// Content Mapping preview (AJAX)
// -----------------------------------------------------------------------

add_action( 'wp_ajax_teatatu_news_html_preview_mapping', 'teatatu_news_ajax_preview_html_mapping' );

/**
 * AJAX handler behind the "Preview Example Values" button on the HTML
 * Feeds tab — the counterpart to teatatu_news_ajax_preview_rss_mapping()
 * in includes/rss-importer.php. Fetches the configured Page URL, finds the
 * first element matching the (possibly unsaved) Repeating Item selector,
 * and resolves it against the (also possibly unsaved) field mapping —
 * reusing the exact same resolver functions the real import path calls.
 */
function teatatu_news_ajax_preview_html_mapping() {
	check_ajax_referer( 'teatatu_news_html_preview_mapping', 'nonce' );

	if ( ! current_user_can( 'manage_teatatu_news_html_feeds' ) ) {
		wp_send_json_error( array( 'message' => __( 'You do not have permission to do that.', 'teatatu-news' ) ) );
	}

	$page_url = isset( $_POST['page_url'] ) ? esc_url_raw( wp_unslash( $_POST['page_url'] ) ) : '';
	if ( '' === $page_url ) {
		wp_send_json_error( array( 'message' => __( 'Enter a Page URL first.', 'teatatu-news' ) ) );
	}

	$page_html = teatatu_news_rss_fetch_page_html( $page_url );
	if ( '' === trim( $page_html ) ) {
		wp_send_json_error( array( 'message' => __( 'Could not fetch the page, or it returned no content.', 'teatatu-news' ) ) );
	}

	$item_selector = teatatu_news_html_sanitize_item_selector_from_request();
	$item_nodes    = teatatu_news_html_select_all_items( $page_html, $item_selector['tag'], $item_selector['attr_type'], $item_selector['attr_value'], 1 );
	if ( empty( $item_nodes ) ) {
		wp_send_json_error( array( 'message' => __( 'The Repeating Item selector matched no elements on this page.', 'teatatu-news' ) ) );
	}
	$item_html = teatatu_news_rss_node_inner_html( $item_nodes[0] );

	$field_map = teatatu_news_html_sanitize_field_map_from_request();

	$read_more_url = teatatu_news_html_resolve_read_more_url( $item_html, $field_map['read_more_url'], $page_url );

	$needs_page = ( 'page' === $field_map['title']['source'] )
		|| ( 'page' === $field_map['excerpt']['source'] )
		|| ( 'page' === $field_map['image']['source'] )
		|| ( 'page' === $field_map['pub_date']['source'] );

	$linked_page_html = '';
	if ( $needs_page && $read_more_url ) {
		$linked_page_html = teatatu_news_rss_fetch_page_html( $read_more_url );
	}

	$title        = teatatu_news_html_resolve_text_field( $item_html, $field_map['title'], $linked_page_html, 'title' );
	$excerpt      = teatatu_news_html_resolve_text_field( $item_html, $field_map['excerpt'], $linked_page_html, 'excerpt' );
	$image        = teatatu_news_html_resolve_image_field( $item_html, $field_map['image'], $linked_page_html );
	$pub_date_gmt = teatatu_news_html_resolve_pub_date_field( $item_html, $field_map['pub_date'], $linked_page_html );

	wp_send_json_success(
		array(
			'title'         => '' !== $title ? $title : __( '(unresolved — this item would be skipped entirely)', 'teatatu-news' ),
			'excerpt'       => '' !== $excerpt ? wp_trim_words( wp_strip_all_tags( $excerpt ), 40 ) : __( '(empty)', 'teatatu-news' ),
			'image'         => '' !== $image ? $image : __( '(empty)', 'teatatu-news' ),
			'read_more_url' => '' !== $read_more_url ? $read_more_url : __( '(unresolved — this item would be skipped entirely)', 'teatatu-news' ),
			'pub_date'      => $pub_date_gmt
				? date_i18n( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), strtotime( get_date_from_gmt( $pub_date_gmt ) ) )
				: __( '(none — import time will be used instead)', 'teatatu-news' ),
		)
	);
}

// -----------------------------------------------------------------------
// Manual "Check Now"
// -----------------------------------------------------------------------

add_action( 'admin_post_teatatu_news_check_html_feed_now', 'teatatu_news_handle_check_html_feed_now' );

/**
 * Runs one HTML feed's check immediately, bypassing its cadence — for
 * verifying a mapping configuration without waiting on cron.
 */
function teatatu_news_handle_check_html_feed_now() {
	$feed_id = isset( $_GET['feed_id'] ) ? absint( $_GET['feed_id'] ) : 0;
	check_admin_referer( 'teatatu_news_check_html_feed_now_' . $feed_id );

	if ( ! $feed_id || 'teatatu_news_webfeed' !== get_post_type( $feed_id ) || ! current_user_can( 'manage_teatatu_news_html_feeds' ) ) {
		wp_die( esc_html__( 'You do not have permission to do that.', 'teatatu-news' ), 403 );
	}

	teatatu_news_html_process_feed( $feed_id );

	set_transient( 'teatatu_news_admin_notice_' . get_current_user_id(), __( 'Feed checked.', 'teatatu-news' ), 45 );
	wp_safe_redirect( add_query_arg( array( 'page' => 'teatatu-news', 'tab' => 'html' ), admin_url( 'admin.php' ) ) );
	exit;
}
