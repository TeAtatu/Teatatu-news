<?php
/**
 * Teatatu News Theme — display only.
 *
 * Every story shown by this theme comes from the Teatatu News plugin: news
 * is read through teatatu_news_query_items() / teatatu_news_normalize_item().
 * The "What's on" events strip comes from the Teatatu Events plugin, and
 * only when that plugin is active — through its front-end API
 * teatatu_events_search() (or its network feed on multisite). The theme stores no news or
 * event data and reads no post meta itself. Without the News plugin it shows
 * a notice instead of stories.
 *
 * Styling follows the Te Atatū Local design system (assets/css/te-atatu-local*.css):
 * <html data-site="news"> gives every component the Kōtare blue accent, and
 * the design system's event components re-scope themselves to the Events
 * accent (Pōhutukawa), so events read as events inside the news site.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'TTN_THEME_VERSION', '1.1.3' );

require_once get_template_directory() . '/inc/template-tags.php';
require_once get_template_directory() . '/inc/customizer.php';
require_once get_template_directory() . '/inc/updater.php';

/**
 * Whether the Teatatu News plugin is active.
 *
 * @return bool
 */
function ttn_plugin_ready() {
	return function_exists( 'teatatu_news_normalize_item' ) && function_exists( 'teatatu_news_query_items' );
}

/**
 * Whether the Teatatu Events plugin is active (the "What's on" strip is
 * optional, so every use is guarded by this).
 *
 * @return bool
 */
function ttn_events_ready() {
	return function_exists( 'teatatu_events_search' ) || ( function_exists( 'teatatu_events_query_site_items' ) && function_exists( 'teatatu_events_shortcode_atts' ) );
}

add_action( 'after_setup_theme', 'ttn_setup' );

/**
 * Theme supports and menus.
 */
function ttn_setup() {
	load_theme_textdomain( 'teatatu-news-theme', get_template_directory() . '/languages' );
	add_theme_support( 'title-tag' );
	add_theme_support( 'html5', array( 'search-form', 'gallery', 'caption', 'style', 'script', 'navigation-widgets' ) );
	add_theme_support( 'responsive-embeds' );
	register_nav_menus(
		array(
			'primary' => __( 'Header menu', 'teatatu-news-theme' ),
			'footer'  => __( 'Footer menu', 'teatatu-news-theme' ),
		)
	);
}

add_action( 'wp_enqueue_scripts', 'ttn_assets' );

/**
 * Fonts, styles and the small progressive-enhancement script.
 */
function ttn_assets() {
	$css = get_template_directory_uri() . '/assets/css/';
	wp_enqueue_style( 'tal-font', 'https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;600;700;800&display=swap', array(), null );
	wp_enqueue_style( 'tal-tokens', $css . 'te-atatu-local-tokens.css', array(), TTN_THEME_VERSION );
	wp_enqueue_style( 'tal-components', $css . 'te-atatu-local.css', array( 'tal-tokens', 'tal-font' ), TTN_THEME_VERSION );
	wp_enqueue_style( 'ttn-style', get_stylesheet_uri(), array( 'tal-components' ), TTN_THEME_VERSION );
	wp_enqueue_script( 'ttn-theme', get_template_directory_uri() . '/assets/js/theme.js', array(), TTN_THEME_VERSION, array( 'in_footer' => true, 'strategy' => 'defer' ) );
	wp_localize_script(
		'ttn-theme',
		'ttnTheme',
		array(
			'copied' => __( 'Link copied', 'teatatu-news-theme' ),
		)
	);
}

add_action( 'wp_head', 'ttn_js_class', 1 );

/**
 * Marks the document as script-capable before paint.
 */
function ttn_js_class() {
	echo "<script>document.documentElement.classList.add('js');</script>\n";
}

/**
 * Whether the main query is a news listing: the News archive, a Source or
 * Category archive, or a search limited to News.
 *
 * @return bool
 */
function ttn_is_news_listing() {
	if ( ! ttn_plugin_ready() ) {
		return false;
	}
	if ( is_post_type_archive( 'teatatu_news' ) || is_tax( array( 'teatatu_news_source', 'teatatu_news_category' ) ) ) {
		return true;
	}
	return is_search() && 'teatatu_news' === get_query_var( 'post_type' );
}

add_filter( 'template_include', 'ttn_news_listing_template' );

/**
 * Every News listing (archive, Source/Category archive, news search) uses
 * the one News archive template.
 *
 * @param string $template Template path.
 * @return string
 */
function ttn_news_listing_template( $template ) {
	if ( ttn_is_news_listing() ) {
		$archive = locate_template( 'archive-teatatu_news.php' );
		if ( $archive ) {
			return $archive;
		}
	}
	return $template;
}

add_action( 'pre_get_posts', 'ttn_listing_query' );

/**
 * Page size and the optional ?sort= for News listings. Filters on source
 * and category use the plugin's own taxonomy query vars, so WordPress
 * applies them with no help from here.
 *
 * @param WP_Query $query Query.
 */
function ttn_listing_query( $query ) {
	if ( is_admin() || ! $query->is_main_query() ) {
		return;
	}
	$is_listing = $query->is_search() || $query->is_post_type_archive( 'teatatu_news' ) || $query->is_tax( array( 'teatatu_news_source', 'teatatu_news_category' ) );
	if ( ! $is_listing ) {
		return;
	}
	if ( $query->is_search() && 'teatatu_news' !== $query->get( 'post_type' ) ) {
		return;
	}
	$query->set( 'posts_per_page', 12 );

	// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only display option.
	$sort = isset( $_GET['sort'] ) ? sanitize_key( wp_unslash( $_GET['sort'] ) ) : '';
	if ( 'oldest' === $sort ) {
		$query->set( 'orderby', 'date' );
		$query->set( 'order', 'ASC' );
	} elseif ( 'title' === $sort ) {
		$query->set( 'orderby', 'title' );
		$query->set( 'order', 'ASC' );
	}
}

add_action( 'admin_notices', 'ttn_plugin_missing_notice' );

/**
 * Tells admins the theme needs the plugin.
 */
function ttn_plugin_missing_notice() {
	if ( ttn_plugin_ready() || ! current_user_can( 'activate_plugins' ) ) {
		return;
	}
	echo '<div class="notice notice-warning"><p>' . esc_html__( 'The Teatatu News theme displays stories from the Teatatu News plugin. Activate the plugin to show news.', 'teatatu-news-theme' ) . '</p></div>';
}

add_filter( 'document_title_parts', 'ttn_title_parts' );

/**
 * A clearer page title on news listings ("News from Te Atatū Peninsula").
 *
 * @param array $parts Title parts.
 * @return array
 */
function ttn_title_parts( $parts ) {
	if ( ttn_is_news_listing() ) {
		$parts['title'] = ttn_results_heading();
	}
	return $parts;
}

add_filter( 'language_attributes', 'ttn_site_attribute' );

/**
 * Marks the document as the News site of the Te Atatū Local network, so the
 * design system's components take the Kōtare blue accent.
 *
 * @param string $output Attributes for <html>.
 * @return string
 */
function ttn_site_attribute( $output ) {
	return $output . ' data-site="news"';
}

add_filter( 'body_class', 'ttn_body_class' );

/**
 * Applies the design system's root class (font, focus rings, reduced motion).
 *
 * @param string[] $classes Body classes.
 * @return string[]
 */
function ttn_body_class( $classes ) {
	$classes[] = 'tal-root';
	return $classes;
}

/**
 * The Te Atatū Local sites for the network strip, in the design system's
 * fixed order: Te Atatū and News (this site) always; Events, Homes and
 * Market only when their plugin is active (see ttn_section_plugins()).
 * Filter `ttn_network_sites` to change labels or addresses (e.g. on a
 * staging network) or to add/remove sites.
 *
 * @return array[] key => [label, url]
 */
function ttn_network_sites() {
	$sites  = array(
		'main' => array( __( 'Te Atatū', 'teatatu-news-theme' ), 'https://teatatu.nz/' ),
		'news' => array( __( 'News', 'teatatu-news-theme' ), home_url( '/' ) ),
	);
	$labels = array(
		'events' => __( 'Events', 'teatatu-news-theme' ),
		'homes'  => __( 'Homes', 'teatatu-news-theme' ),
		'market' => __( 'Market', 'teatatu-news-theme' ),
	);
	foreach ( ttn_section_plugins() as $key => $def ) {
		$url = ttn_section_url( $def );
		if ( '' !== $url ) {
			$sites[ $key ] = array( $labels[ $key ], $url );
		}
	}
	// Keep the design system's order: Te Atatū, Events, News, Homes, Market.
	$order = array_flip( array( 'main', 'events', 'news', 'homes', 'market' ) );
	uksort(
		$sites,
		function ( $a, $b ) use ( $order ) {
			return ( $order[ $a ] ?? 99 ) <=> ( $order[ $b ] ?? 99 );
		}
	);
	return apply_filters( 'ttn_network_sites', $sites );
}

/**
 * The sections that appear only when their plugin is active: key =>
 * {file: plugin main file name, master: the plugin's Master Site network
 * setting, post_type: its listing post type (for a single-site install),
 * url: default address}.
 *
 * @return array[]
 */
function ttn_section_plugins() {
	return array(
		'events' => array( 'file' => 'teatatu-events.php', 'master' => 'teatatu_events_master_site_id', 'post_type' => 'teatatu_event', 'url' => 'https://events.teatatu.nz/', 'key' => 'events' ),
		'homes'  => array( 'file' => 'teatatu-homes.php', 'master' => 'teatatu_homes_master_site_id', 'post_type' => 'teatatu_home', 'url' => 'https://homes.teatatu.nz/', 'key' => 'homes' ),
		'market' => array( 'file' => 'teatatu-market.php', 'master' => 'teatatu_market_master_site_id', 'post_type' => '', 'url' => 'https://market.teatatu.nz/', 'key' => 'market' ),
	);
}

/**
 * The Master Site a section's plugin has set (Network Admin > Settings >
 * Teatatu Events / Homes / Market), or 0. Read through the plugin's own
 * teatatu_<name>_get_master_site_id() when it has one, else its network
 * setting directly (the theme may run on a site where the plugin isn't
 * loaded). Ignored if that site no longer exists.
 *
 * @param array $def Section definition from ttn_section_plugins().
 * @return int
 */
function ttn_section_master_site_id( $def ) {
	if ( ! is_multisite() || empty( $def['master'] ) ) {
		return 0;
	}
	$getter = str_replace( '_master_site_id', '_get_master_site_id', $def['master'] );
	$id     = function_exists( $getter ) ? (int) call_user_func( $getter ) : (int) get_site_option( $def['master'], 0 );
	return $id && get_site( $id ) ? $id : 0;
}

/**
 * Where a plugin (by main file name, any folder) is active. The network-wide
 * list is read fresh every time (one stored option); the slower scan of
 * each site's own list is cached for 10 minutes, and cleared whenever a
 * plugin is (de)activated.
 *
 * @param string $file Plugin main file name, e.g. teatatu-homes.php.
 * @return array {network: bool, sites: int[]} — sites is every site it is
 *               activated on individually (on single site: this site).
 */
function ttn_plugin_activity( $file ) {
	$matches = function ( $plugins ) use ( $file ) {
		foreach ( (array) $plugins as $plugin ) {
			if ( basename( (string) $plugin ) === $file ) {
				return true;
			}
		}
		return false;
	};
	if ( ! is_multisite() ) {
		return array( 'network' => false, 'sites' => $matches( get_option( 'active_plugins', array() ) ) ? array( get_current_blog_id() ) : array() );
	}
	if ( $matches( array_keys( (array) get_site_option( 'active_sitewide_plugins', array() ) ) ) ) {
		return array( 'network' => true, 'sites' => array() );
	}
	$scan = get_site_transient( 'ttn_plugin_activity' );
	$scan = is_array( $scan ) ? $scan : array();
	if ( ! isset( $scan[ $file ] ) ) {
		$scan[ $file ] = array();
		foreach ( get_sites( array( 'fields' => 'ids', 'number' => 500 ) ) as $site_id ) {
			if ( $matches( get_blog_option( $site_id, 'active_plugins', array() ) ) ) {
				$scan[ $file ][] = (int) $site_id;
			}
		}
		set_site_transient( 'ttn_plugin_activity', $scan, 10 * MINUTE_IN_SECONDS );
	}
	return array( 'network' => false, 'sites' => $scan[ $file ] );
}

add_action( 'activated_plugin', 'ttn_forget_plugin_activity' );
add_action( 'deactivated_plugin', 'ttn_forget_plugin_activity' );

/**
 * Plugin (de)activation anywhere on the network refreshes the strip.
 */
function ttn_forget_plugin_activity() {
	delete_site_transient( 'ttn_plugin_activity' );
}

/**
 * Whether a section's plugin is active here, network-wide, or on any site.
 *
 * @param string $key Section key from ttn_section_plugins(): events, homes, market.
 * @return bool
 */
function ttn_section_active( $key ) {
	$defs = ttn_section_plugins();
	if ( ! isset( $defs[ $key ] ) ) {
		return false;
	}
	$activity = ttn_plugin_activity( $defs[ $key ]['file'] );
	return $activity['network'] || ! empty( $activity['sites'] );
}

/**
 * The address for a section, or '' when its plugin isn't active.
 *
 * - Multisite: the plugin's own Master Site (Network Admin > Settings >
 *   Teatatu Events / Homes / Market) when one is set; else the site whose
 *   address starts with the section name (events.example.nz or
 *   example.nz/events/) when the plugin runs there; else the one site it is
 *   activated on; else the default address.
 * - Single site (the plugin runs on this site): its listing page, else the
 *   default address.
 *
 * @param array $def Section definition from ttn_section_plugins().
 * @return string
 */
function ttn_section_url( $def ) {
	$activity = ttn_plugin_activity( $def['file'] );
	if ( ! $activity['network'] && ! $activity['sites'] ) {
		return '';
	}
	if ( ! is_multisite() ) {
		$archive = $def['post_type'] && post_type_exists( $def['post_type'] ) ? get_post_type_archive_link( $def['post_type'] ) : '';
		return $archive ? $archive : $def['url'];
	}
	$master = ttn_section_master_site_id( $def );
	if ( $master ) {
		return get_home_url( $master, '/' );
	}
	$candidates = $activity['network'] ? get_sites( array( 'fields' => 'ids', 'number' => 500 ) ) : $activity['sites'];
	foreach ( $candidates as $site_id ) {
		$site = get_site( $site_id );
		if ( $site && ( 0 === strpos( $site->domain, $def['key'] . '.' ) || 0 === strpos( trim( $site->path, '/' ) . '/', $def['key'] . '/' ) ) ) {
			return get_home_url( $site_id, '/' );
		}
	}
	if ( ! $activity['network'] && 1 === count( $activity['sites'] ) ) {
		return get_home_url( $activity['sites'][0], '/' );
	}
	return $def['url'];
}
