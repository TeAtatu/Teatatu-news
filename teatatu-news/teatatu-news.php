<?php
/**
 * Plugin Name: Teatatu News
 * Plugin URI: https://github.com/TeAtatu/Teatatu-news
 * Description: Aggregates news items into a custom "News" post type, with Source/Category taxonomies, RSS and HTML-page feed importers, link-only image handling (no local downloads), REST API access for AI agents, and per-site or network-wide shortcodes. Uses only standard WordPress database tables.
 * Version: 1.7.2
 * Requires at least: 6.0
 * Requires PHP: 7.4
 * Author: TeAtatu
 * Author URI: https://github.com/TeAtatu
 * License: GPL-3.0
 * License URI: https://www.gnu.org/licenses/gpl-3.0.html
 * Text Domain: teatatu-news
 * Update URI: https://github.com/TeAtatu/Teatatu-news
 * Network: true
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'TEATATU_NEWS_VERSION', '1.7.2' );
define( 'TEATATU_NEWS_FILE', __FILE__ );
define( 'TEATATU_NEWS_DIR', plugin_dir_path( __FILE__ ) );
define( 'TEATATU_NEWS_URL', plugin_dir_url( __FILE__ ) );
define( 'TEATATU_NEWS_BASENAME', plugin_basename( __FILE__ ) );

require_once TEATATU_NEWS_DIR . 'includes/capabilities.php';
require_once TEATATU_NEWS_DIR . 'includes/multisite.php';
require_once TEATATU_NEWS_DIR . 'includes/settings.php';
require_once TEATATU_NEWS_DIR . 'includes/post-type.php';
require_once TEATATU_NEWS_DIR . 'includes/taxonomies.php';
require_once TEATATU_NEWS_DIR . 'includes/image-url.php';
require_once TEATATU_NEWS_DIR . 'includes/meta-boxes.php';
require_once TEATATU_NEWS_DIR . 'includes/admin-columns.php';
require_once TEATATU_NEWS_DIR . 'includes/admin-page.php';
require_once TEATATU_NEWS_DIR . 'includes/rest-api.php';
require_once TEATATU_NEWS_DIR . 'includes/shortcodes.php';
require_once TEATATU_NEWS_DIR . 'includes/updater.php';
require_once TEATATU_NEWS_DIR . 'includes/theme-updater.php';
require_once TEATATU_NEWS_DIR . 'includes/rss-feeds.php';
require_once TEATATU_NEWS_DIR . 'includes/rss-importer.php';
require_once TEATATU_NEWS_DIR . 'includes/html-feeds.php';
require_once TEATATU_NEWS_DIR . 'includes/html-importer.php';
require_once TEATATU_NEWS_DIR . 'includes/teatatu-data-bridge.php';

/**
 * Fired on plugin activation. Handles both single-site and network activation.
 *
 * @param bool $network_wide Whether the plugin is being network-activated.
 */
function teatatu_news_activate( $network_wide ) {
	if ( is_multisite() && $network_wide ) {
		$site_ids = get_sites( array( 'fields' => 'ids' ) );
		foreach ( $site_ids as $site_id ) {
			switch_to_blog( $site_id );
			teatatu_news_provision_site();
			restore_current_blog();
		}
	} else {
		teatatu_news_provision_site();
	}
}
register_activation_hook( TEATATU_NEWS_FILE, 'teatatu_news_activate' );

/**
 * Runs the per-site provisioning routine: roles/capabilities plus a rewrite flush.
 */
function teatatu_news_provision_site() {
	teatatu_news_add_role_and_caps();
	teatatu_news_register_post_type();
	teatatu_news_register_taxonomies();
	flush_rewrite_rules();
	teatatu_news_rss_schedule_cron();
	teatatu_news_html_schedule_cron();
}

/**
 * Fired on plugin deactivation.
 *
 * @param bool $network_wide Whether the plugin was network-activated.
 */
function teatatu_news_deactivate( $network_wide ) {
	if ( is_multisite() && $network_wide ) {
		$site_ids = get_sites( array( 'fields' => 'ids' ) );
		foreach ( $site_ids as $site_id ) {
			switch_to_blog( $site_id );
			flush_rewrite_rules();
			teatatu_news_rss_unschedule_cron();
			teatatu_news_html_unschedule_cron();
			restore_current_blog();
		}
	} else {
		flush_rewrite_rules();
		teatatu_news_rss_unschedule_cron();
		teatatu_news_html_unschedule_cron();
	}
}
register_deactivation_hook( TEATATU_NEWS_FILE, 'teatatu_news_deactivate' );

/**
 * Provisions a brand-new site created after network activation.
 *
 * @param WP_Site $new_site Newly created site.
 */
function teatatu_news_new_site( $new_site ) {
	if ( ! function_exists( 'is_plugin_active_for_network' ) ) {
		require_once ABSPATH . 'wp-admin/includes/plugin.php';
	}
	if ( ! is_plugin_active_for_network( TEATATU_NEWS_BASENAME ) ) {
		return;
	}
	switch_to_blog( (int) $new_site->blog_id );
	teatatu_news_provision_site();
	restore_current_blog();
}
add_action( 'wp_initialize_site', 'teatatu_news_new_site' );
