<?php
/**
 * Self-hosted GitHub-release-based auto-updater. Teatatu News isn't on
 * WordPress.org, so this plugs into the normal wp-admin "update available"
 * UI (and the native auto-update opt-in) using only wp_remote_get() and
 * core update-related filters — no bundled update library.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Reads a header from this plugin's own file, with a tiny static cache.
 *
 * @param string $key 'Name', 'Description', 'RequiresWP', or 'RequiresPHP'.
 * @return string
 */
function teatatu_news_get_plugin_header( $key ) {
	static $headers = null;
	if ( null === $headers ) {
		$headers = get_file_data(
			TEATATU_NEWS_FILE,
			array(
				'Name'        => 'Plugin Name',
				'Description' => 'Description',
				'RequiresWP'  => 'Requires at least',
				'RequiresPHP' => 'Requires PHP',
			)
		);
	}
	return isset( $headers[ $key ] ) ? $headers[ $key ] : '';
}

/**
 * Gets the configured `owner/repo` GitHub slug.
 *
 * @return string
 */
function teatatu_news_get_github_repo() {
	$repo = teatatu_news_get_option( 'teatatu_news_github_repo', 'TeAtatu/Teatatu-news' );
	return $repo ? $repo : 'TeAtatu/Teatatu-news';
}

/**
 * Fetches (and caches) the latest GitHub release for the configured repo.
 *
 * @param bool $force Bypass the cache.
 * @return array|false Decoded release data, or false on failure.
 */
function teatatu_news_fetch_latest_release( $force = false ) {
	$cache_key = 'teatatu_news_github_release';

	if ( ! $force ) {
		$cached = get_site_transient( $cache_key );
		if ( false !== $cached ) {
			return $cached;
		}
	}

	$repo = teatatu_news_get_github_repo();

	$headers = array(
		'User-Agent' => 'Teatatu-News-Plugin/' . TEATATU_NEWS_VERSION . ' (' . home_url() . ')',
		'Accept'     => 'application/vnd.github+json',
	);
	$token = teatatu_news_get_option( 'teatatu_news_github_token', '' );
	if ( $token ) {
		$headers['Authorization'] = 'token ' . $token;
	}

	$response = wp_remote_get(
		"https://api.github.com/repos/{$repo}/releases/latest",
		array(
			'headers' => $headers,
			'timeout' => 15,
		)
	);

	if ( is_wp_error( $response ) || 200 !== (int) wp_remote_retrieve_response_code( $response ) ) {
		return false;
	}

	$body = json_decode( wp_remote_retrieve_body( $response ), true );
	if ( ! is_array( $body ) ) {
		return false;
	}

	set_site_transient( $cache_key, $body, 12 * HOUR_IN_SECONDS );
	return $body;
}

add_filter( 'pre_set_site_transient_update_plugins', 'teatatu_news_check_for_update' );

/**
 * Populates the update_plugins transient when a newer GitHub release exists.
 *
 * @param object $transient Existing update_plugins transient.
 * @return object
 */
function teatatu_news_check_for_update( $transient ) {
	if ( empty( $transient->checked ) ) {
		return $transient;
	}

	$release = teatatu_news_fetch_latest_release();
	if ( ! $release || empty( $release['tag_name'] ) ) {
		return $transient;
	}

	$remote_version = ltrim( $release['tag_name'], 'vV' );
	if ( ! $remote_version || ! version_compare( $remote_version, TEATATU_NEWS_VERSION, '>' ) ) {
		return $transient;
	}

	$item = array(
		'id'            => 'teatatu-news/teatatu-news.php',
		'slug'          => 'teatatu-news',
		'plugin'        => TEATATU_NEWS_BASENAME,
		'new_version'   => $remote_version,
		'url'           => 'https://github.com/' . teatatu_news_get_github_repo(),
		'package'       => isset( $release['zipball_url'] ) ? $release['zipball_url'] : '',
		'tested'        => get_bloginfo( 'version' ),
		'requires'      => teatatu_news_get_plugin_header( 'RequiresWP' ),
		'requires_php'  => teatatu_news_get_plugin_header( 'RequiresPHP' ),
		'icons'         => array(),
		'banners'       => array(),
	);

	$transient->response[ TEATATU_NEWS_BASENAME ] = (object) $item;
	if ( isset( $transient->no_update[ TEATATU_NEWS_BASENAME ] ) ) {
		unset( $transient->no_update[ TEATATU_NEWS_BASENAME ] );
	}

	return $transient;
}

add_filter( 'plugins_api', 'teatatu_news_plugins_api', 10, 3 );

/**
 * Powers the "View version details" popup from the latest GitHub release.
 *
 * @param false|object|array $result The result object/array (default false).
 * @param string              $action  The type of information being requested.
 * @param object              $args    Plugin API arguments.
 * @return false|object
 */
function teatatu_news_plugins_api( $result, $action, $args ) {
	if ( 'plugin_information' !== $action || empty( $args->slug ) || 'teatatu-news' !== $args->slug ) {
		return $result;
	}

	$release        = teatatu_news_fetch_latest_release();
	$remote_version = ( $release && ! empty( $release['tag_name'] ) ) ? ltrim( $release['tag_name'], 'vV' ) : TEATATU_NEWS_VERSION;
	$changelog      = ( $release && ! empty( $release['body'] ) ) ? $release['body'] : '';

	$info = array(
		'name'          => teatatu_news_get_plugin_header( 'Name' ),
		'slug'          => 'teatatu-news',
		'version'       => $remote_version,
		'author'        => '<a href="https://github.com/TeAtatu">TeAtatu</a>',
		'homepage'      => 'https://github.com/' . teatatu_news_get_github_repo(),
		'requires'      => teatatu_news_get_plugin_header( 'RequiresWP' ),
		'requires_php'  => teatatu_news_get_plugin_header( 'RequiresPHP' ),
		'download_link' => $release && ! empty( $release['zipball_url'] ) ? $release['zipball_url'] : '',
		'sections'      => array(
			'description' => wp_kses_post( teatatu_news_get_plugin_header( 'Description' ) ),
			'changelog'   => $changelog ? wpautop( wp_kses_post( $changelog ) ) : '<p>' . esc_html__( 'No changelog available.', 'teatatu-news' ) . '</p>',
		),
	);

	return (object) $info;
}

add_filter( 'upgrader_source_selection', 'teatatu_news_fix_source_dir', 10, 4 );

/**
 * Renames the extracted GitHub zipball folder (`owner-repo-<sha>`) back to
 * `teatatu-news` so the update replaces the existing plugin folder cleanly.
 *
 * @param string      $source        Path to the extracted, unrenamed source.
 * @param string      $remote_source Path to the parent temp directory.
 * @param WP_Upgrader $upgrader      Upgrader instance.
 * @param array       $hook_extra    Extra arguments, including 'plugin'.
 * @return string
 */
function teatatu_news_fix_source_dir( $source, $remote_source, $upgrader, $hook_extra = array() ) {
	global $wp_filesystem;

	if ( empty( $hook_extra['plugin'] ) || TEATATU_NEWS_BASENAME !== $hook_extra['plugin'] ) {
		return $source;
	}
	if ( ! $wp_filesystem ) {
		return $source;
	}

	$desired = trailingslashit( $remote_source ) . 'teatatu-news/';
	if ( trailingslashit( $source ) === $desired ) {
		return $source;
	}

	if ( $wp_filesystem && $wp_filesystem->move( $source, $desired, true ) ) {
		return $desired;
	}

	return $source;
}

add_action( 'admin_post_teatatu_news_check_updates', 'teatatu_news_handle_check_updates' );

/**
 * Handles the "Check for Updates Now" button: clears cached GitHub data and
 * the core update_plugins transient so the next admin page load re-checks.
 */
function teatatu_news_handle_check_updates() {
	if ( ! isset( $_POST['teatatu_news_check_updates_nonce'] ) || ! wp_verify_nonce( wp_unslash( $_POST['teatatu_news_check_updates_nonce'] ), 'teatatu_news_check_updates' ) ) {
		wp_die( esc_html__( 'Security check failed.', 'teatatu-news' ) );
	}
	$cap = is_multisite() ? 'manage_network_options' : 'manage_options';
	if ( ! current_user_can( $cap ) ) {
		wp_die( esc_html__( 'You do not have permission to do that.', 'teatatu-news' ), 403 );
	}

	delete_site_transient( 'teatatu_news_github_release' );
	delete_site_transient( 'update_plugins' );

	$redirect_base = is_multisite() ? network_admin_url( 'settings.php' ) : admin_url( 'options-general.php' );
	wp_safe_redirect( add_query_arg( array( 'page' => 'teatatu-news-settings', 'teatatu_news_settings_saved' => '1' ), $redirect_base ) );
	exit;
}
