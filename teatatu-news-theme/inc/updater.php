<?php
/**
 * Self-hosted GitHub-release-based updater for the theme, mirroring the
 * Teatatu News plugin's updater (teatatu-news/includes/updater.php).
 *
 * One GitHub release (latest, tag vX.Y.Z) ships both the plugin and this
 * theme. The tag only points at the release: the theme's *own* Version
 * header in teatatu-news-theme/style.css at that tag is compared with the
 * installed version, so a release that only changes the plugin never
 * offers a theme update.
 *
 * Installing: automatically only when "Enable auto-updates" is on for this
 * theme — a link that can be switched on and off at any time (Appearance >
 * Themes; Network Admin > Themes on multisite) — see
 * ttn_updater_ensure_registered() for why the link is always available.
 * Otherwise the "update available" notice shows and an admin clicks
 * Update now. The GitHub repository and token come from the plugin's
 * settings (Settings > Teatatu News) when the plugin is active.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The theme's folder name (its stylesheet/template slug).
 *
 * @return string
 */
function ttn_updater_slug() {
	return get_template();
}

/**
 * Installed theme version.
 *
 * @return string
 */
function ttn_updater_installed_version() {
	return (string) wp_get_theme( ttn_updater_slug() )->get( 'Version' );
}

/**
 * `owner/repo` for updates: the plugin's setting when available.
 *
 * @return string
 */
function ttn_updater_repo() {
	$repo = function_exists( 'teatatu_news_get_option' ) ? teatatu_news_get_option( 'teatatu_news_github_repo', '' ) : '';
	return (string) apply_filters( 'ttn_updater_repo', $repo ? $repo : 'TeAtatu/Teatatu-news' );
}

/**
 * GitHub token (only needed for a private repository).
 *
 * @return string
 */
function ttn_updater_token() {
	return function_exists( 'teatatu_news_get_option' ) ? (string) teatatu_news_get_option( 'teatatu_news_github_token', '' ) : '';
}

/**
 * Request headers for GitHub.
 *
 * @param string $accept Accept header.
 * @return array
 */
function ttn_updater_headers( $accept = 'application/vnd.github+json' ) {
	$headers = array(
		'User-Agent' => 'Teatatu-News-Theme/' . ttn_updater_installed_version() . ' (' . home_url() . ')',
		'Accept'     => $accept,
	);
	$token = ttn_updater_token();
	if ( $token ) {
		$headers['Authorization'] = 'token ' . $token;
	}
	return $headers;
}

/**
 * The theme's version and download at the latest release (cached 12h).
 *
 * @param bool $force Bypass the cache.
 * @return array|null {version, package, changelog, url, requires, requires_php}
 */
function ttn_updater_release_info( $force = false ) {
	$cache_key = 'ttn_theme_github_release';
	if ( ! $force ) {
		$cached = get_site_transient( $cache_key );
		if ( false !== $cached ) {
			return $cached ? $cached : null;
		}
	}

	$repo     = ttn_updater_repo();
	$response = wp_remote_get( "https://api.github.com/repos/{$repo}/releases/latest", array( 'headers' => ttn_updater_headers(), 'timeout' => 15 ) );
	if ( is_wp_error( $response ) || 200 !== (int) wp_remote_retrieve_response_code( $response ) ) {
		return null;
	}
	$release = json_decode( wp_remote_retrieve_body( $response ), true );
	if ( ! is_array( $release ) || empty( $release['tag_name'] ) || empty( $release['zipball_url'] ) ) {
		return null;
	}

	$style = wp_remote_get(
		"https://api.github.com/repos/{$repo}/contents/teatatu-news-theme/style.css?ref=" . rawurlencode( $release['tag_name'] ),
		array( 'headers' => ttn_updater_headers( 'application/vnd.github.raw+json' ), 'timeout' => 15 )
	);
	if ( is_wp_error( $style ) || 200 !== (int) wp_remote_retrieve_response_code( $style ) ) {
		return null;
	}
	$head    = substr( wp_remote_retrieve_body( $style ), 0, 8192 );
	$field   = function ( $name ) use ( $head ) {
		return preg_match( '/^[ \t\/*#@]*' . preg_quote( $name, '/' ) . ':\s*(.+)$/mi', $head, $m ) ? trim( $m[1] ) : '';
	};
	$version = $field( 'Version' );
	if ( '' === $version ) {
		return null;
	}

	$info = array(
		'version'      => $version,
		'package'      => $release['zipball_url'],
		'changelog'    => isset( $release['body'] ) ? (string) $release['body'] : '',
		'url'          => isset( $release['html_url'] ) ? $release['html_url'] : 'https://github.com/' . $repo,
		'requires'     => $field( 'Requires at least' ),
		'requires_php' => $field( 'Requires PHP' ),
	);
	set_site_transient( $cache_key, $info, 12 * HOUR_IN_SECONDS );
	return $info;
}

add_filter( 'pre_set_site_transient_update_themes', 'ttn_updater_check' );

/**
 * Offers the update in wp-admin when the release has a newer theme version.
 *
 * @param object $transient update_themes transient.
 * @return object
 */
function ttn_updater_check( $transient ) {
	if ( empty( $transient->checked ) ) {
		return $transient;
	}
	$slug = ttn_updater_slug();
	$info = ttn_updater_release_info();
	if ( ! $info || ! version_compare( $info['version'], ttn_updater_installed_version(), '>' ) ) {
		// Listed as "checked, nothing newer" so WordPress still offers the
		// theme's Enable auto-updates link before an update exists.
		if ( ! isset( $transient->no_update ) || ! is_array( $transient->no_update ) ) {
			$transient->no_update = array();
		}
		$transient->no_update[ $slug ] = array(
			'theme'        => $slug,
			'new_version'  => ttn_updater_installed_version(),
			'url'          => 'https://github.com/' . ttn_updater_repo(),
			'package'      => '',
			'requires'     => '',
			'requires_php' => '',
		);
		return $transient;
	}
	$transient->response[ $slug ] = array(
		'theme'        => $slug,
		'new_version'  => $info['version'],
		// "View version details" opens this in a modal; GitHub can't be framed, so it's our own page.
		'url'          => add_query_arg( array( 'action' => 'ttn_theme_changelog' ), admin_url( 'admin-ajax.php' ) ),
		'package'      => $info['package'],
		'requires'     => $info['requires'],
		'requires_php' => $info['requires_php'],
	);
	if ( isset( $transient->no_update[ $slug ] ) ) {
		unset( $transient->no_update[ $slug ] );
	}
	return $transient;
}

add_action( 'wp_ajax_ttn_theme_changelog', 'ttn_updater_changelog' );

/**
 * The "View version details" modal: the release notes.
 */
function ttn_updater_changelog() {
	if ( ! current_user_can( 'update_themes' ) ) {
		wp_die( esc_html__( 'You do not have permission to view this.', 'teatatu-news-theme' ), 403 );
	}
	$info = ttn_updater_release_info();
	echo '<!doctype html><html><head><meta charset="utf-8"><title>' . esc_html__( 'Teatatu News Theme', 'teatatu-news-theme' ) . '</title>';
	echo '<style>body{font:15px/1.6 -apple-system,BlinkMacSystemFont,"Segoe UI",sans-serif;margin:24px;color:#1d2327}h1{font-size:20px}</style></head><body>';
	if ( ! $info ) {
		echo '<p>' . esc_html__( 'Release details are not available right now.', 'teatatu-news-theme' ) . '</p>';
	} else {
		/* translators: %s: version. */
		echo '<h1>' . esc_html( sprintf( __( 'Teatatu News Theme %s', 'teatatu-news-theme' ), $info['version'] ) ) . '</h1>';
		echo $info['changelog'] ? wp_kses_post( wpautop( $info['changelog'] ) ) : '<p>' . esc_html__( 'No changelog available.', 'teatatu-news-theme' ) . '</p>';
		echo '<p><a href="' . esc_url( $info['url'] ) . '" target="_blank" rel="noopener">' . esc_html__( 'View the release on GitHub', 'teatatu-news-theme' ) . '</a></p>';
	}
	echo '</body></html>';
	exit;
}

add_filter( 'upgrader_source_selection', 'ttn_updater_source_dir', 10, 4 );

/**
 * Makes the downloaded release install as themes/teatatu-news-theme/.
 * GitHub's zipball holds the whole repository, so the
 * teatatu-news-theme/ subfolder is lifted out; a zip that already has
 * the theme at its top level is just renamed.
 *
 * @param string      $source        Extracted source path.
 * @param string      $remote_source Parent temp directory.
 * @param WP_Upgrader $upgrader      Upgrader.
 * @param array       $hook_extra    Includes 'theme'.
 * @return string|WP_Error
 */
function ttn_updater_source_dir( $source, $remote_source, $upgrader, $hook_extra = array() ) {
	global $wp_filesystem;
	$slug = ttn_updater_slug();
	if ( empty( $hook_extra['theme'] ) || $slug !== $hook_extra['theme'] || ! $wp_filesystem ) {
		return $source;
	}

	$source  = trailingslashit( $source );
	$desired = trailingslashit( $remote_source ) . $slug . '/';

	if ( $wp_filesystem->exists( $source . 'teatatu-news-theme/style.css' ) ) {
		$from = $source . 'teatatu-news-theme/';
	} elseif ( $wp_filesystem->exists( $source . 'style.css' ) && $wp_filesystem->exists( $source . 'single-teatatu_news.php' ) ) {
		$from = $source;
	} else {
		return new WP_Error( 'ttn_bad_package', __( 'The downloaded update does not contain the Teatatu News theme. Nothing was changed.', 'teatatu-news-theme' ) );
	}

	if ( $from === $desired ) {
		return $desired;
	}
	if ( $wp_filesystem->move( untrailingslashit( $from ), untrailingslashit( $desired ), true ) ) {
		return $desired;
	}
	return new WP_Error( 'ttn_move_failed', __( 'Could not prepare the Teatatu News theme update. Nothing was changed.', 'teatatu-news-theme' ) );
}

add_action( 'init', 'ttn_updater_ensure_registered', 30 );

/**
 * Keeps the theme in WordPress's theme update list, which is what makes the
 * "Enable / Disable auto-updates" link appear for it (Appearance > Themes;
 * Network Admin > Themes on multisite).
 *
 * WordPress rebuilds that list from scratch on every update check, and only
 * runs the update code of the theme that is *active on the site doing the
 * check*. On a network, a check run from any other site (or the Network
 * Admin) therefore drops this theme from the list, and its toggle shows as
 * unavailable until a check happens to run on the news site. So when this
 * theme is running and finds itself missing, it puts itself back through the
 * normal filter. Checked at most every ten minutes, at the cost of one
 * option read per request in between.
 */
function ttn_updater_ensure_registered() {
	$last = (int) get_option( 'ttn_updater_ensured', 0 );
	if ( time() - $last < 10 * MINUTE_IN_SECONDS ) {
		return;
	}
	update_option( 'ttn_updater_ensured', time() );

	$slug      = ttn_updater_slug();
	$transient = get_site_transient( 'update_themes' );
	// Not built yet: core's own check will run our filter when it is.
	if ( ! is_object( $transient ) || empty( $transient->checked ) ) {
		return;
	}
	if ( ! empty( $transient->response[ $slug ] ) || ! empty( $transient->no_update[ $slug ] ) ) {
		return;
	}
	// Re-saving runs ttn_updater_check() through core's own filter.
	set_site_transient( 'update_themes', $transient );
}

add_filter( 'auto_update_theme', 'ttn_updater_auto_update_gate', PHP_INT_MAX, 2 );

/**
 * Automatic updates only when "Enable auto-updates" is on for this theme.
 * Left alone when WordPress only asks whether auto-updates are forced
 * (null), so the Enable/Disable link stays clickable.
 *
 * @param bool|null $update Whether to update automatically.
 * @param object    $item   The update offer.
 * @return bool|null
 */
function ttn_updater_auto_update_gate( $update, $item ) {
	if ( null === $update || empty( $item->theme ) || ttn_updater_slug() !== $item->theme ) {
		return $update;
	}
	return in_array( ttn_updater_slug(), (array) get_site_option( 'auto_update_themes', array() ), true );
}

add_filter( 'http_request_args', 'ttn_updater_authorize_download', 10, 2 );

/**
 * Sends the GitHub token (if set) when downloading the release zip, so
 * updates work from a private repository.
 *
 * @param array  $args Request arguments.
 * @param string $url  URL.
 * @return array
 */
function ttn_updater_authorize_download( $args, $url ) {
	$token = ttn_updater_token();
	if ( $token && 0 === strpos( $url, 'https://api.github.com/repos/' . ttn_updater_repo() . '/zipball/' ) ) {
		$args['headers']                  = isset( $args['headers'] ) ? (array) $args['headers'] : array();
		$args['headers']['Authorization'] = 'token ' . $token;
	}
	return $args;
}

add_action( 'load-update-core.php', 'ttn_updater_force_check' );
add_action( 'admin_post_teatatu_news_check_updates', 'ttn_updater_clear_cache', 1 );

/**
 * Dashboard > Updates > "Check again" skips the release cache.
 */
function ttn_updater_force_check() {
	// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only cache clear, same as core's force-check.
	if ( ! empty( $_GET['force-check'] ) && current_user_can( 'update_themes' ) ) {
		delete_site_transient( 'ttn_theme_github_release' );
	}
}

/**
 * The plugin's "Check for Updates Now" button checks the theme too. The
 * plugin's own handler verifies the nonce and capability and redirects;
 * this only clears caches, after the same checks.
 */
function ttn_updater_clear_cache() {
	if ( isset( $_POST['teatatu_news_check_updates_nonce'] ) && wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['teatatu_news_check_updates_nonce'] ) ), 'teatatu_news_check_updates' ) && current_user_can( 'update_themes' ) ) {
		delete_site_transient( 'ttn_theme_github_release' );
		delete_site_transient( 'update_themes' );
	}
}
