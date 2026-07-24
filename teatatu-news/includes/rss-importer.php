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
 * @param bool            $auto_publish      Whether items from this feed publish immediately instead of saving/staying as draft.
 * @param bool            $auto_apply_update Whether changes to an already-published item apply immediately instead of becoming a pending update.
 * @param array           $page_html_cache   Reference to a per-run cache of fetched article pages, keyed by URL.
 */
function teatatu_news_rss_import_item( $feed_id, $item, $field_map, $default_source, $default_category, $auto_publish, $auto_apply_update, &$page_html_cache ) {
	$link = $item->get_permalink();
	if ( ! $link ) {
		return;
	}
	// The feed's own canonical link. Always the dedupe identity, regardless
	// of what the Read More URL field is mapped to (see
	// teatatu_news_rss_find_existing_post()) — and its fallback value.
	$link = esc_url_raw( $link );

	$needs_page = ( 'page' === $field_map['title']['source'] )
		|| ( 'page' === $field_map['excerpt']['source'] )
		|| ( 'page' === $field_map['image']['source'] )
		|| ( 'page' === $field_map['read_more_url']['source'] )
		|| ( 'page' === $field_map['pub_date']['source'] );

	$page_html = '';
	if ( $needs_page ) {
		if ( ! array_key_exists( $link, $page_html_cache ) ) {
			$page_html_cache[ $link ] = teatatu_news_rss_fetch_page_html( $link );
		}
		$page_html = $page_html_cache[ $link ];
	}

	$title         = teatatu_news_rss_resolve_text_field( $item, $field_map['title'], $page_html, 'title' );
	$excerpt       = teatatu_news_rss_resolve_text_field( $item, $field_map['excerpt'], $page_html, 'excerpt' );
	$image         = teatatu_news_rss_resolve_image_field( $item, $field_map['image'], $page_html );
	$read_more_url = teatatu_news_rss_resolve_read_more_url( $item, $field_map['read_more_url'], $page_html, $link );
	$pub_date_gmt  = teatatu_news_rss_resolve_pub_date_field( $item, $field_map['pub_date'], $page_html );

	if ( '' === $title ) {
		return;
	}

	$existing_id = teatatu_news_rss_find_existing_post( $feed_id, $link );

	if ( ! $existing_id ) {
		teatatu_news_rss_create_post( $feed_id, $link, $read_more_url, $title, $excerpt, $image, $default_source, $default_category, $auto_publish, $pub_date_gmt );
		return;
	}

	if ( 'publish' !== get_post_status( $existing_id ) ) {
		// An item this feed already imported (as draft/pending) is still
		// treated as belonging to that feed's current settings — if
		// Auto-Publish is now on, this is where a previously-imported,
		// still-unreviewed item gets promoted, not just brand new items.
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
 * Reads a feed item's original publish date, normalized to a MySQL GMT
 * datetime string. WordPress forces PHP's default timezone to UTC (see
 * wp-settings.php), so SimplePie's date() -based formatting is already in
 * GMT here — no separate conversion needed to use it as post_date_gmt.
 *
 * @param SimplePie_Item $item Feed item.
 * @return string 'Y-m-d H:i:s' in GMT, or '' if the feed didn't provide one.
 */
function teatatu_news_rss_get_item_pub_date_gmt( $item ) {
	$date = $item->get_date( 'Y-m-d H:i:s' );
	return $date ? $date : '';
}

/**
 * Resolves the Publish Date field per its mapping config. The default
 * source (rss_pub_date) uses the feed's own reported date directly. Every
 * other source REQUIRES a tag and/or class/id — unlike Title/Excerpt/Image,
 * there's no reliable way to find "the date" inside an unmarked block of
 * text, so mapping Publish Date to RSS Description/Content without
 * narrowing is treated the same as an unresolvable page scrape: no date,
 * fall back to import time.
 *
 * @param SimplePie_Item $item      Feed item.
 * @param array          $mapping   This field's mapping config.
 * @param string         $page_html Pre-fetched article page HTML (only used when source is 'page').
 * @return string 'Y-m-d H:i:s' in GMT, or '' if nothing could be resolved.
 */
function teatatu_news_rss_resolve_pub_date_field( $item, $mapping, $page_html ) {
	$source = $mapping['source'];

	if ( 'rss_pub_date' === $source ) {
		return teatatu_news_rss_get_item_pub_date_gmt( $item );
	}

	if ( ! $mapping['tag'] && ! $mapping['attr_value'] ) {
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
	return teatatu_news_rss_node_date_gmt( $node );
}

/**
 * Resolves the Read More URL field per its mapping config. Always falls
 * back to the feed's own canonical item link if the mapped source can't be
 * resolved — Read More URL is a required field everywhere else in this
 * plugin, so import must never produce an item without one. This fallback
 * is purely a display concern: which URL a reader lands on is independent
 * of dedupe identity — see teatatu_news_rss_find_existing_post(), which
 * always matches on the feed's own link regardless of this mapping.
 *
 * @param SimplePie_Item $item      Feed item.
 * @param array          $mapping   This field's mapping config.
 * @param string         $page_html Pre-fetched article page HTML (only used when source is 'page').
 * @param string         $fallback  The feed's own canonical link, already esc_url_raw()'d.
 * @return string
 */
function teatatu_news_rss_resolve_read_more_url( $item, $mapping, $page_html, $fallback ) {
	$source = $mapping['source'];

	if ( 'rss_link' === $source ) {
		return $fallback;
	}

	if ( 'page' === $source && ! $mapping['tag'] && ! $mapping['attr_value'] ) {
		return $fallback;
	}

	$raw_html = 'page' === $source ? $page_html : teatatu_news_rss_get_source_html( $item, $source );
	if ( '' === trim( (string) $raw_html ) ) {
		return $fallback;
	}

	$node = teatatu_news_rss_select_element( $raw_html, $mapping['tag'], $mapping['attr_type'], $mapping['attr_value'] );
	if ( ! $node ) {
		return $fallback;
	}
	$url = teatatu_news_rss_node_link_url( $node );
	return $url ? esc_url_raw( $url ) : $fallback;
}

/**
 * Adds post_date/post_date_gmt to a wp_insert_post()/wp_update_post() args
 * array, if a pub date was actually available.
 *
 * @param array  $args         Args array, modified in place.
 * @param string $pub_date_gmt 'Y-m-d H:i:s' in GMT, or ''.
 */
function teatatu_news_rss_apply_pub_date( array &$args, $pub_date_gmt ) {
	if ( ! $pub_date_gmt ) {
		return;
	}
	$args['post_date_gmt'] = $pub_date_gmt;
	$args['post_date']     = get_date_from_gmt( $pub_date_gmt );
}

/**
 * Creates a new News post for a not-yet-seen feed item — as a draft by
 * default, or published immediately if the feed has Auto-Publish enabled.
 * post_date is set to the feed's own reported publish date, when available,
 * so News Items sort by when the story actually happened rather than by
 * whenever this plugin's cron happened to import it.
 *
 * @param int    $feed_id          RSS Feed post ID.
 * @param string $link             The feed's own canonical item link — stored
 *                                 separately as the permanent dedupe identity,
 *                                 independent of what Read More URL is mapped to.
 * @param string $read_more_url    Resolved (possibly remapped) Read More URL — what's displayed.
 * @param string $title            Resolved title.
 * @param string $excerpt          Resolved excerpt.
 * @param string $image            Resolved image URL.
 * @param int    $default_source   Default Source term ID, or 0.
 * @param int    $default_category Default Category term ID, or 0.
 * @param bool   $auto_publish     Whether to publish immediately instead of saving as draft.
 * @param string $pub_date_gmt     'Y-m-d H:i:s' in GMT, or ''.
 */
function teatatu_news_rss_create_post( $feed_id, $link, $read_more_url, $title, $excerpt, $image, $default_source, $default_category, $auto_publish, $pub_date_gmt ) {
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
	update_post_meta( $new_id, '_teatatu_news_rss_source_link', $link );
	update_post_meta( $new_id, '_teatatu_news_image_url', $image );
	update_post_meta( $new_id, '_teatatu_news_rss_feed_id', $feed_id );
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
 * Stashes freshly-fetched field values as a pending update on an already
 * published post — or, if the feed has Auto-Apply Updates enabled, applies
 * them immediately instead — but only if something actually changed.
 *
 * @param int    $post_id       Existing, published News post ID.
 * @param string $title         Resolved title.
 * @param string $excerpt       Resolved excerpt.
 * @param string $image         Resolved image URL.
 * @param string $read_more_url Resolved (possibly remapped) Read More URL.
 * @param string $pub_date_gmt  'Y-m-d H:i:s' in GMT, or ''.
 * @param bool   $auto_apply    Whether to apply immediately instead of stashing a pending update.
 */
function teatatu_news_rss_maybe_flag_pending_update( $post_id, $title, $excerpt, $image, $read_more_url, $pub_date_gmt, $auto_apply ) {
	// Raw context on both sides: get_the_title()/the display context of
	// get_post_field() run values through filters like wptexturize(), which
	// would make an unchanged title/excerpt spuriously compare as different.
	$current_title         = get_post_field( 'post_title', $post_id, 'raw' );
	$current_excerpt       = get_post_field( 'post_excerpt', $post_id, 'raw' );
	$current_image         = get_post_meta( $post_id, '_teatatu_news_image_url', true );
	$current_read_more_url = get_post_meta( $post_id, '_teatatu_news_read_more_url', true );

	if ( $title === $current_title && $excerpt === $current_excerpt
		&& $image === $current_image && $read_more_url === $current_read_more_url ) {
		return;
	}

	if ( $auto_apply ) {
		teatatu_news_rss_apply_fields_to_post( $post_id, $title, $excerpt, $image, $read_more_url, $pub_date_gmt );
		delete_post_meta( $post_id, '_teatatu_news_rss_pending_update' );
		return;
	}

	update_post_meta(
		$post_id,
		'_teatatu_news_rss_pending_update',
		array(
			'title'         => $title,
			'excerpt'       => $excerpt,
			'image_url'     => $image,
			'read_more_url' => $read_more_url,
			'pub_date_gmt'  => $pub_date_gmt,
			'detected_at'   => time(),
		)
	);
}

/**
 * Writes resolved Title/Excerpt/Image/Read-More-URL/pub-date values
 * directly onto an existing post. Shared by the auto-apply path above and
 * the manual "Apply" admin action, so both take the exact same effect.
 *
 * @param int    $post_id       Post ID.
 * @param string $title         Title.
 * @param string $excerpt       Excerpt.
 * @param string $image         Image URL.
 * @param string $read_more_url Read More URL.
 * @param string $pub_date_gmt  'Y-m-d H:i:s' in GMT, or ''.
 */
function teatatu_news_rss_apply_fields_to_post( $post_id, $title, $excerpt, $image, $read_more_url, $pub_date_gmt = '' ) {
	$update_args = array(
		'ID'           => $post_id,
		'post_title'   => $title,
		'post_excerpt' => $excerpt,
	);
	teatatu_news_rss_apply_pub_date( $update_args, $pub_date_gmt );

	wp_update_post( $update_args, true );
	update_post_meta( $post_id, '_teatatu_news_image_url', $image );
	update_post_meta( $post_id, '_teatatu_news_read_more_url', $read_more_url );
	if ( $pub_date_gmt ) {
		update_post_meta( $post_id, '_teatatu_news_rss_pub_date', $pub_date_gmt );
	}
}

/**
 * Finds a News post previously imported from this feed with this canonical
 * link. Matches on _teatatu_news_rss_source_link — a dedicated field that
 * always holds the feed's own item link, independent of whatever the Read
 * More URL field is mapped to display — with a fallback OR-branch matching
 * the legacy _teatatu_news_read_more_url field for posts imported before
 * that dedicated field existed (when read_more_url was always the
 * canonical link itself, so the same match is still correct for them).
 * Without that fallback, every already-imported item from before this
 * change would look "new" again on the next sync and get duplicated.
 *
 * @param int    $feed_id RSS Feed post ID.
 * @param string $link    The feed's own canonical item link.
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
					'relation' => 'OR',
					array(
						'key'   => '_teatatu_news_rss_source_link',
						'value' => $link,
					),
					array(
						'relation' => 'AND',
						array(
							'key'     => '_teatatu_news_rss_source_link',
							'compare' => 'NOT EXISTS',
						),
						array(
							'key'   => '_teatatu_news_read_more_url',
							'value' => $link,
						),
					),
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
		return teatatu_news_rss_normalize_whitespace( wp_strip_all_tags( $raw_html ) );
	}

	return teatatu_news_rss_normalize_whitespace( teatatu_news_rss_clean_html( $raw_html ) );
}

/**
 * Trims a resolved Title/Excerpt, also converting non-breaking spaces to
 * regular ones first. Page-builder themes (Elementor, Divi, Avada/Fusion,
 * and similar) very commonly lead a paragraph's markup with `&nbsp;` — e.g.
 * `<p>&nbsp; Kia ora ...</p>` — which trim() alone doesn't remove, since
 * trim() only strips plain ASCII whitespace, not the U+00A0 character
 * `&nbsp;` decodes to. Left unhandled, that survives as a stray visible
 * leading space in the resolved field (a real, visible rendering glitch,
 * not just a data-cleanliness nitpick — a non-breaking space by definition
 * never collapses the way a normal space does).
 *
 * @param string $text Already tag-stripped/cleaned text.
 * @return string
 */
function teatatu_news_rss_normalize_whitespace( $text ) {
	return trim( str_replace( "\xC2\xA0", ' ', (string) $text ) );
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
 * the src of the first <img> descendant, or — since some sites set an
 * article's image as a CSS background-image rather than an <img> tag (seen
 * on real school-website news cards, e.g. an <a class="..." style=
 * "background-image:url(...)"> wrapping the whole card) — the first
 * background-image found on the node itself or any descendant.
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

	$bg = teatatu_news_rss_node_background_image_url( $node );
	if ( $bg ) {
		return $bg;
	}
	if ( $node->hasChildNodes() ) {
		$xpath      = new DOMXPath( $node->ownerDocument );
		$styled     = $xpath->query( './/*[contains(@style,"background-image")]', $node );
		if ( $styled && $styled->length > 0 ) {
			return teatatu_news_rss_node_background_image_url( $styled->item( 0 ) );
		}
	}
	return '';
}

/**
 * Extracts the URL from a `background-image:url(...)` declaration in a
 * single element's own inline style attribute, if present.
 *
 * @param DOMElement $node Element.
 * @return string
 */
function teatatu_news_rss_node_background_image_url( $node ) {
	if ( ! ( $node instanceof DOMElement ) || ! $node->hasAttribute( 'style' ) ) {
		return '';
	}
	if ( preg_match( '/background-image\s*:\s*url\(\s*[\'"]?([^\'")]+)[\'"]?\s*\)/i', $node->getAttribute( 'style' ), $matches ) ) {
		return trim( $matches[1] );
	}
	return '';
}

/**
 * Finds a link URL within a DOM element: its own href if it's an <a>,
 * otherwise the href of the first <a> descendant.
 *
 * @param DOMNode $node Element.
 * @return string
 */
function teatatu_news_rss_node_link_url( $node ) {
	if ( ! ( $node instanceof DOMElement ) ) {
		return '';
	}
	if ( 'a' === strtolower( $node->tagName ) ) {
		return $node->getAttribute( 'href' );
	}
	$links = $node->getElementsByTagName( 'a' );
	if ( $links->length > 0 ) {
		return $links->item( 0 )->getAttribute( 'href' );
	}
	return '';
}

/**
 * Finds a publish date within a DOM element, normalized to a MySQL GMT
 * datetime string: prefers a `datetime="..."` attribute (the HTML5
 * `<time datetime="...">` convention, e.g. from an article page), falling
 * back to parsing the element's own visible text with strtotime(). A
 * timestamp with no timezone info in the source string is treated as
 * already GMT, matching WordPress's own PHP-default-timezone-UTC posture.
 *
 * @param DOMNode $node Element.
 * @return string 'Y-m-d H:i:s' in GMT, or '' if nothing parseable was found.
 */
function teatatu_news_rss_node_date_gmt( $node ) {
	if ( ! ( $node instanceof DOMElement ) ) {
		return '';
	}
	if ( $node->hasAttribute( 'datetime' ) ) {
		$raw = $node->getAttribute( 'datetime' );
	} else {
		$raw = trim( wp_strip_all_tags( teatatu_news_rss_node_inner_html( $node ) ) );
	}
	if ( '' === $raw ) {
		return '';
	}
	$timestamp = strtotime( $raw );
	return $timestamp ? gmdate( 'Y-m-d H:i:s', $timestamp ) : '';
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
// Content Mapping preview (AJAX)
// -----------------------------------------------------------------------

add_action( 'wp_ajax_teatatu_news_rss_preview_mapping', 'teatatu_news_ajax_preview_rss_mapping' );

/**
 * AJAX handler behind the "Preview Example Values" button on the RSS Feeds
 * tab: fetches the feed's most recent item and resolves it against
 * whatever mapping is currently filled into the (possibly unsaved) form,
 * so an editor can see what each field will actually produce before
 * saving. Deliberately reuses the exact same resolver functions the real
 * import path (teatatu_news_rss_import_item()) calls — same sources, same
 * selector logic, same fallback behavior — so the preview can never show
 * something an actual import wouldn't also produce.
 */
function teatatu_news_ajax_preview_rss_mapping() {
	check_ajax_referer( 'teatatu_news_rss_preview_mapping', 'nonce' );

	if ( ! current_user_can( 'manage_teatatu_news_rss_feeds' ) ) {
		wp_send_json_error( array( 'message' => __( 'You do not have permission to do that.', 'teatatu-news' ) ) );
	}

	$feed_url = isset( $_POST['feed_url'] ) ? esc_url_raw( wp_unslash( $_POST['feed_url'] ) ) : '';
	if ( '' === $feed_url ) {
		wp_send_json_error( array( 'message' => __( 'Enter a Feed URL first.', 'teatatu-news' ) ) );
	}

	$feed = fetch_feed( $feed_url );
	if ( is_wp_error( $feed ) ) {
		wp_send_json_error( array( 'message' => $feed->get_error_message() ) );
	}

	$items = $feed->get_items( 0, 1 );
	if ( empty( $items ) ) {
		wp_send_json_error( array( 'message' => __( 'This feed has no items to preview.', 'teatatu-news' ) ) );
	}
	$item = $items[0];

	// Sanitized the same way Save will sanitize it — the preview must never
	// show an example for a mapping combination Save would actually reject
	// or normalize differently.
	$field_map = teatatu_news_rss_sanitize_field_map_from_request();

	$link = $item->get_permalink();
	$link = $link ? esc_url_raw( $link ) : '';

	$needs_page = ( 'page' === $field_map['title']['source'] )
		|| ( 'page' === $field_map['excerpt']['source'] )
		|| ( 'page' === $field_map['image']['source'] )
		|| ( 'page' === $field_map['read_more_url']['source'] )
		|| ( 'page' === $field_map['pub_date']['source'] );

	$page_html = '';
	if ( $needs_page && $link ) {
		$page_html = teatatu_news_rss_fetch_page_html( $link );
	}

	$title         = teatatu_news_rss_resolve_text_field( $item, $field_map['title'], $page_html, 'title' );
	$excerpt       = teatatu_news_rss_resolve_text_field( $item, $field_map['excerpt'], $page_html, 'excerpt' );
	$image         = teatatu_news_rss_resolve_image_field( $item, $field_map['image'], $page_html );
	$read_more_url = teatatu_news_rss_resolve_read_more_url( $item, $field_map['read_more_url'], $page_html, $link );
	$pub_date_gmt  = teatatu_news_rss_resolve_pub_date_field( $item, $field_map['pub_date'], $page_html );

	wp_send_json_success(
		array(
			'title'         => '' !== $title ? $title : __( '(unresolved — this item would be skipped entirely)', 'teatatu-news' ),
			'excerpt'       => '' !== $excerpt ? wp_trim_words( wp_strip_all_tags( $excerpt ), 40 ) : __( '(empty)', 'teatatu-news' ),
			'image'         => '' !== $image ? $image : __( '(empty)', 'teatatu-news' ),
			'read_more_url' => '' !== $read_more_url ? $read_more_url : __( '(empty)', 'teatatu-news' ),
			'pub_date'      => $pub_date_gmt
				? date_i18n( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), strtotime( get_date_from_gmt( $pub_date_gmt ) ) )
				: __( '(none — import time will be used instead)', 'teatatu-news' ),
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
		// read_more_url/pub_date_gmt may be absent on a pending update
		// stashed before those fields existed — leave the URL as whatever
		// is already on the post (don't blank it out) and treat a missing
		// date the same as "the feed had no date".
		$pending_read_more_url = isset( $pending['read_more_url'] ) ? $pending['read_more_url'] : get_post_meta( $post_id, '_teatatu_news_read_more_url', true );
		$pending_pub_date      = isset( $pending['pub_date_gmt'] ) ? $pending['pub_date_gmt'] : '';
		teatatu_news_rss_apply_fields_to_post( $post_id, $pending['title'], $pending['excerpt'], $pending['image_url'], $pending_read_more_url, $pending_pub_date );
		delete_post_meta( $post_id, '_teatatu_news_rss_pending_update' );
	}

	set_transient( 'teatatu_news_admin_notice_' . get_current_user_id(), __( 'Feed update applied.', 'teatatu-news' ), 45 );
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

	set_transient( 'teatatu_news_admin_notice_' . get_current_user_id(), __( 'Feed update dismissed.', 'teatatu-news' ), 45 );
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
