<?php
/**
 * Uninstall routine. Runs once, standalone — the main plugin file is not
 * loaded, so only core WordPress APIs and the two included files below are
 * available. Always removes the `teatatu_news_agent` role/capabilities and
 * the plugin's own settings on every site in the network (or the single
 * site). Additionally deletes all News content only if the admin opted in
 * via the "Delete all Teatatu News data when this plugin is uninstalled"
 * checkbox on the settings page.
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

require_once __DIR__ . '/includes/capabilities.php';
require_once __DIR__ . '/includes/taxonomies.php';

// Populate $wp_taxonomies so get_terms() recognizes our taxonomies below;
// the main plugin file (and its 'init' hook) never loads during uninstall.
teatatu_news_register_taxonomies();

/**
 * Removes the role/caps, the recurring RSS cron tick, and optionally all
 * News content and RSS feed configurations, on the current site. Images
 * were never stored locally (link-only), so there is no media to clean up
 * alongside posts/terms.
 *
 * @param bool $delete_data Whether to also delete News posts, RSS feed configs, and terms.
 */
function teatatu_news_uninstall_cleanup_site( $delete_data ) {
	teatatu_news_remove_role_and_caps();
	wp_clear_scheduled_hook( 'teatatu_news_rss_cron_tick' );

	if ( ! $delete_data ) {
		return;
	}

	foreach ( array( 'teatatu_news', 'teatatu_news_rssfeed' ) as $post_type ) {
		$post_ids = get_posts(
			array(
				'post_type'      => $post_type,
				'post_status'    => 'any',
				'numberposts'    => -1,
				'fields'         => 'ids',
				'suppress_filters' => true,
			)
		);
		foreach ( $post_ids as $post_id ) {
			wp_delete_post( $post_id, true );
		}
	}

	foreach ( array( 'teatatu_news_source', 'teatatu_news_category' ) as $taxonomy ) {
		$term_ids = get_terms(
			array(
				'taxonomy'   => $taxonomy,
				'hide_empty' => false,
				'fields'     => 'ids',
			)
		);
		if ( is_wp_error( $term_ids ) ) {
			continue;
		}
		foreach ( $term_ids as $term_id ) {
			wp_delete_term( $term_id, $taxonomy );
		}
	}
}

if ( is_multisite() ) {
	$delete_data = (bool) get_site_option( 'teatatu_news_delete_data_on_uninstall', false );

	$site_ids = get_sites( array( 'fields' => 'ids' ) );
	foreach ( $site_ids as $site_id ) {
		switch_to_blog( $site_id );
		teatatu_news_uninstall_cleanup_site( $delete_data );
		restore_current_blog();
	}

	delete_site_option( 'teatatu_news_master_site_id' );
	delete_site_option( 'teatatu_news_github_repo' );
	delete_site_option( 'teatatu_news_github_token' );
	delete_site_option( 'teatatu_news_delete_data_on_uninstall' );
	delete_site_option( 'teatatu_news_network_cache_keys' );
	delete_site_transient( 'teatatu_news_github_release' );
} else {
	$delete_data = (bool) get_option( 'teatatu_news_delete_data_on_uninstall', false );

	teatatu_news_uninstall_cleanup_site( $delete_data );

	delete_option( 'teatatu_news_github_repo' );
	delete_option( 'teatatu_news_github_token' );
	delete_option( 'teatatu_news_delete_data_on_uninstall' );
	delete_transient( 'teatatu_news_github_release' );
}
