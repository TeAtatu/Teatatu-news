<?php
/**
 * Multisite helpers: Master Site resolution and the network aggregate-cache
 * registry used by the network shortcodes and the network REST endpoint.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Gets the blog ID configured as the network's Master Site.
 *
 * @return int 0 if none has been configured yet.
 */
function teatatu_news_get_master_site_id() {
	if ( ! is_multisite() ) {
		return 0;
	}
	return (int) get_site_option( 'teatatu_news_master_site_id', 0 );
}

/**
 * Whether the current site is the configured Master Site.
 *
 * @return bool
 */
function teatatu_news_is_master_site() {
	if ( ! is_multisite() ) {
		return false;
	}
	$master_id = teatatu_news_get_master_site_id();
	return $master_id > 0 && $master_id === get_current_blog_id();
}

/**
 * Registers a network transient cache key so it can be flushed precisely
 * when News content changes anywhere in the network, instead of only
 * relying on the transient's own expiry.
 *
 * @param string $key Transient key (without the `teatatu_news_net_cache_` prefix already applied by caller).
 */
function teatatu_news_register_network_cache_key( $key ) {
	$keys = get_site_option( 'teatatu_news_network_cache_keys', array() );
	if ( ! in_array( $key, $keys, true ) ) {
		$keys[] = $key;
		update_site_option( 'teatatu_news_network_cache_keys', $keys );
	}
}

/**
 * Flushes every registered network aggregate cache entry and clears the
 * registry. Called whenever a News item is saved or deleted on any site.
 */
function teatatu_news_flush_network_cache() {
	$keys = get_site_option( 'teatatu_news_network_cache_keys', array() );
	foreach ( $keys as $key ) {
		delete_site_transient( $key );
	}
	if ( ! empty( $keys ) ) {
		update_site_option( 'teatatu_news_network_cache_keys', array() );
	}
}
add_action( 'save_post_teatatu_news', 'teatatu_news_flush_network_cache' );
add_action( 'before_delete_post', 'teatatu_news_maybe_flush_network_cache_on_delete' );

/**
 * Only flushes the network cache when the deleted post is a News item.
 *
 * @param int $post_id Post being deleted.
 */
function teatatu_news_maybe_flush_network_cache_on_delete( $post_id ) {
	if ( get_post_type( $post_id ) === 'teatatu_news' ) {
		teatatu_news_flush_network_cache();
	}
}
