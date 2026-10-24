<?php
/**
 * Updates for the Teatatu News Theme, run by this plugin.
 *
 * A theme's own code only loads on sites where it is the active theme, but
 * WordPress checks for theme updates (and shows Network Admin > Themes) from
 * other sites — on a network, usually the main site — and rebuilds its theme
 * update list from scratch each time. A theme that isn't running there drops
 * out of the list, and WordPress only shows a theme's "Enable / Disable
 * auto-updates" link while the theme is in it. So the plugin, which is loaded
 * everywhere, does the theme's update checks too. This file stands alone: it
 * reads nothing from the theme's files, and the theme keeps its own copy of
 * this logic (teatatu-news-theme/inc/updater.php) that only runs when this
 * plugin isn't active — so the plugin and the theme can each be edited,
 * versioned and released without touching the other.
 *
 * - One GitHub release (latest, tag vX.Y.Z) can carry the plugin, the theme
 *   or both. The theme's *own* Version in teatatu-news-theme/style.css at
 *   that tag is compared with the installed theme (and the plugin's own
 *   Version with the installed plugin, in updater.php), so a release that
 *   only changes one never offers an update for the other.
 * - Package: GitHub's source zip of the tag, from which only the
 *   teatatu-news-theme/ folder is installed.
 * - Auto-updates are switched on once, when the theme is first found, and
 *   from then on follow WordPress's own "Enable / Disable auto-updates" link
 *   for the theme (Network Admin > Themes on multisite, Appearance > Themes
 *   on a single site). Turning them off there is never overridden.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Tells the theme its updates are handled here (it then skips its own copy).
 *
 * @return bool
 */
function teatatu_news_theme_updater_loaded() {
	return true;
}

/**
 * The installed theme's folder (stylesheet), or '' when it isn't installed.
 *
 * @return string
 */
function teatatu_news_theme_slug() {
	static $slug = null;
	if ( null !== $slug ) {
		return $slug;
	}
	$slug = '';
	if ( wp_get_theme( 'teatatu-news-theme' )->exists() ) {
		$slug = 'teatatu-news-theme';
	} else {
		foreach ( wp_get_themes() as $stylesheet => $theme ) {
			if ( 'teatatu-news-theme' === $theme->get( 'TextDomain' ) ) {
				$slug = (string) $stylesheet;
				break;
			}
		}
	}
	return $slug;
}

/**
 * Installed theme version.
 *
 * @return string
 */
function teatatu_news_theme_installed_version() {
	return (string) wp_get_theme( teatatu_news_theme_slug() )->get( 'Version' );
}

/**
 * `owner/repo` for updates: the plugin's setting.
 *
 * @return string
 */
function teatatu_news_theme_repo() {
	return (string) apply_filters( 'teatatu_news_theme_repo', teatatu_news_get_github_repo() );
}

/**
 * GitHub token (only needed for a private repository).
 *
 * @return string
 */
function teatatu_news_theme_token() {
	return (string) teatatu_news_get_option( 'teatatu_news_github_token', '' );
}

/**
 * Request headers for GitHub.
 *
 * @param string $accept Accept header.
 * @return array
 */
function teatatu_news_theme_headers( $accept = 'application/vnd.github+json' ) {
	$headers = array(
		'User-Agent' => 'Teatatu-News-Theme/' . teatatu_news_theme_installed_version() . ' (' . home_url() . ')',
		'Accept'     => $accept,
	);
	$token = teatatu_news_theme_token();
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
function teatatu_news_theme_release_info( $force = false ) {
	$cache_key = 'teatatu_news_theme_github_release';
	if ( ! $force ) {
		$cached = get_site_transient( $cache_key );
		if ( false !== $cached ) {
			return $cached ? $cached : null;
		}
	}

	$repo     = teatatu_news_theme_repo();
	$response = wp_remote_get( "https://api.github.com/repos/{$repo}/releases/latest", array( 'headers' => teatatu_news_theme_headers(), 'timeout' => 15 ) );
	if ( is_wp_error( $response ) || 200 !== (int) wp_remote_retrieve_response_code( $response ) ) {
		return null;
	}
	$release = json_decode( wp_remote_retrieve_body( $response ), true );
	if ( ! is_array( $release ) || empty( $release['tag_name'] ) || empty( $release['zipball_url'] ) ) {
		return null;
	}

	$style = wp_remote_get(
		"https://api.github.com/repos/{$repo}/contents/teatatu-news-theme/style.css?ref=" . rawurlencode( $release['tag_name'] ),
		array( 'headers' => teatatu_news_theme_headers( 'application/vnd.github.raw+json' ), 'timeout' => 15 )
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

add_filter( 'pre_set_site_transient_update_themes', 'teatatu_news_theme_check' );

/**
 * Offers the update in wp-admin when the release has a newer theme version.
 *
 * @param object $transient update_themes transient.
 * @return object
 */
function teatatu_news_theme_check( $transient ) {
	if ( empty( $transient->checked ) ) {
		return $transient;
	}
	$slug = teatatu_news_theme_slug();
	if ( '' === $slug ) {
		return $transient;
	}
	$info = teatatu_news_theme_release_info();
	if ( ! $info || ! version_compare( $info['version'], teatatu_news_theme_installed_version(), '>' ) ) {
		// Listed as "checked, nothing newer" so WordPress still offers the
		// theme's Enable auto-updates link before an update exists.
		if ( ! isset( $transient->no_update ) || ! is_array( $transient->no_update ) ) {
			$transient->no_update = array();
		}
		$transient->no_update[ $slug ] = array(
			'theme'        => $slug,
			'new_version'  => teatatu_news_theme_installed_version(),
			'url'          => 'https://github.com/' . teatatu_news_theme_repo(),
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
		'url'          => add_query_arg( array( 'action' => 'teatatu_news_theme_changelog' ), admin_url( 'admin-ajax.php' ) ),
		'package'      => $info['package'],
		'requires'     => $info['requires'],
		'requires_php' => $info['requires_php'],
	);
	if ( isset( $transient->no_update[ $slug ] ) ) {
		unset( $transient->no_update[ $slug ] );
	}
	return $transient;
}

add_action( 'wp_ajax_teatatu_news_theme_changelog', 'teatatu_news_theme_changelog' );

/**
 * The "View version details" modal: the release notes.
 */
function teatatu_news_theme_changelog() {
	if ( ! current_user_can( 'update_themes' ) ) {
		wp_die( esc_html__( 'You do not have permission to view this.', 'teatatu-news' ), 403 );
	}
	$info = teatatu_news_theme_release_info();
	echo '<!doctype html><html><head><meta charset="utf-8"><title>' . esc_html__( 'Teatatu News Theme', 'teatatu-news' ) . '</title>';
	echo '<style>body{font:15px/1.6 -apple-system,BlinkMacSystemFont,"Segoe UI",sans-serif;margin:24px;color:#1d2327}h1{font-size:20px}</style></head><body>';
	if ( ! $info ) {
		echo '<p>' . esc_html__( 'Release details are not available right now.', 'teatatu-news' ) . '</p>';
	} else {
		/* translators: %s: version. */
		echo '<h1>' . esc_html( sprintf( __( 'Teatatu News Theme %s', 'teatatu-news' ), $info['version'] ) ) . '</h1>';
		echo $info['changelog'] ? wp_kses_post( wpautop( $info['changelog'] ) ) : '<p>' . esc_html__( 'No changelog available.', 'teatatu-news' ) . '</p>';
		echo '<p><a href="' . esc_url( $info['url'] ) . '" target="_blank" rel="noopener">' . esc_html__( 'View the release on GitHub', 'teatatu-news' ) . '</a></p>';
	}
	echo '</body></html>';
	exit;
}

add_filter( 'upgrader_source_selection', 'teatatu_news_theme_source_dir', 10, 4 );

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
function teatatu_news_theme_source_dir( $source, $remote_source, $upgrader, $hook_extra = array() ) {
	global $wp_filesystem;
	$slug = teatatu_news_theme_slug();
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
		return new WP_Error( 'teatatu_news_theme_bad_package', __( 'The downloaded update does not contain the Teatatu News theme. Nothing was changed.', 'teatatu-news' ) );
	}

	if ( $from === $desired ) {
		return $desired;
	}
	if ( $wp_filesystem->move( untrailingslashit( $from ), untrailingslashit( $desired ), true ) ) {
		return $desired;
	}
	return new WP_Error( 'teatatu_news_theme_move_failed', __( 'Could not prepare the Teatatu News theme update. Nothing was changed.', 'teatatu-news' ) );
}

add_action( 'admin_init', 'teatatu_news_theme_default_auto_update' );

/**
 * Switches auto-updates on for the theme once, the first time this runs
 * with the theme installed. After that WordPress's own "Enable / Disable
 * auto-updates" link decides: turning them off is remembered and never
 * undone here. Filter `teatatu_news_theme_default_auto_update` to false to skip.
 */
function teatatu_news_theme_default_auto_update() {
	if ( get_site_option( 'teatatu_news_theme_autoupdate_seeded' ) || get_site_option( 'ttn_theme_autoupdate_seeded' ) || ! apply_filters( 'teatatu_news_theme_default_auto_update', true ) ) {
		return;
	}
	$slug = teatatu_news_theme_slug();
	if ( '' === $slug ) {
		return;
	}
	$enabled = (array) get_site_option( 'auto_update_themes', array() );
	if ( ! in_array( $slug, $enabled, true ) ) {
		$enabled[] = $slug;
		update_site_option( 'auto_update_themes', array_values( array_unique( $enabled ) ) );
	}
	update_site_option( 'teatatu_news_theme_autoupdate_seeded', 1 );
}

add_filter( 'auto_update_theme', 'teatatu_news_theme_auto_update_gate', PHP_INT_MAX, 2 );

/**
 * Automatic updates only when "Enable auto-updates" is on for this theme.
 * Left alone when WordPress only asks whether auto-updates are forced
 * (null), so the Enable/Disable link stays clickable.
 *
 * @param bool|null $update Whether to update automatically.
 * @param object    $item   The update offer.
 * @return bool|null
 */
function teatatu_news_theme_auto_update_gate( $update, $item ) {
	if ( null === $update || empty( $item->theme ) || teatatu_news_theme_slug() !== $item->theme ) {
		return $update;
	}
	return in_array( teatatu_news_theme_slug(), (array) get_site_option( 'auto_update_themes', array() ), true );
}

add_filter( 'http_request_args', 'teatatu_news_theme_authorize_download', 10, 2 );

/**
 * Sends the GitHub token (if set) when downloading the release zip, so
 * updates work from a private repository.
 *
 * @param array  $args Request arguments.
 * @param string $url  URL.
 * @return array
 */
function teatatu_news_theme_authorize_download( $args, $url ) {
	$token = teatatu_news_theme_token();
	if ( $token && 0 === strpos( $url, 'https://api.github.com/repos/' . teatatu_news_theme_repo() . '/zipball/' ) ) {
		$args['headers']                  = isset( $args['headers'] ) ? (array) $args['headers'] : array();
		$args['headers']['Authorization'] = 'token ' . $token;
	}
	return $args;
}

add_action( 'load-update-core.php', 'teatatu_news_theme_force_check' );

/**
 * Dashboard > Updates > "Check again" skips the release cache.
 */
function teatatu_news_theme_force_check() {
	// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only cache clear, same as core's force-check.
	if ( ! empty( $_GET['force-check'] ) && current_user_can( 'update_themes' ) ) {
		delete_site_transient( 'teatatu_news_theme_github_release' );
	}
}
