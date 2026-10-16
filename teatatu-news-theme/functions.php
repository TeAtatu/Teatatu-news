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

define( 'TTN_THEME_VERSION', '1.0.0' );

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
 * The five Te Atatū Local sites for the network strip, in the design
 * system's fixed order. Filter `ttn_network_sites` to change addresses
 * (e.g. on a staging network).
 *
 * @return array[] key => [label, url]
 */
function ttn_network_sites() {
	return apply_filters(
		'ttn_network_sites',
		array(
			'main'   => array( __( 'Te Atatū', 'teatatu-news-theme' ), 'https://teatatu.nz/' ),
			'events' => array( __( 'Events', 'teatatu-news-theme' ), 'https://events.teatatu.nz/' ),
			'news'   => array( __( 'News', 'teatatu-news-theme' ), home_url( '/' ) ),
			'homes'  => array( __( 'Homes', 'teatatu-news-theme' ), 'https://homes.teatatu.nz/' ),
			'market' => array( __( 'Market', 'teatatu-news-theme' ), 'https://market.teatatu.nz/' ),
		)
	);
}
