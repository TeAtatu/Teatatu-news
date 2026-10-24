<?php
/**
 * Site header: the Te Atatū Local NetworkNav — the shared black strip
 * linking all five sites, then the News header (wordmark, search, one
 * primary action), the section links, and the 4px Kōtare accent rule.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
$share_url = get_theme_mod( 'ttn_share_url', '' );
?><!doctype html>
<html <?php language_attributes(); ?>>
<head>
	<meta charset="<?php bloginfo( 'charset' ); ?>" />
	<meta name="viewport" content="width=device-width, initial-scale=1" />
	<?php wp_head(); ?>
</head>
<body <?php body_class(); ?>>
<?php wp_body_open(); ?>
<a class="skip-link screen-reader-text" href="#content"><?php esc_html_e( 'Skip to content', 'teatatu-news-theme' ); ?></a>
<header class="tal-nav" data-site="news">
	<nav class="tal-network" aria-label="<?php esc_attr_e( 'Te Atatū Local sites', 'teatatu-news-theme' ); ?>">
		<?php foreach ( ttn_network_sites() as $key => $site ) : ?>
			<a class="tal-site" data-site="<?php echo esc_attr( $key ); ?>" href="<?php echo esc_url( $site[1] ); ?>" <?php echo 'news' === $key ? 'aria-current="page"' : ''; ?>><span class="tal-site-dot" aria-hidden="true"></span><?php echo esc_html( $site[0] ); ?></a>
		<?php endforeach; ?>
	</nav>
	<div class="tal-nav-bar">
		<a class="tal-wordmark" href="<?php echo esc_url( home_url( '/' ) ); ?>" rel="home">Te Atatū <span><?php esc_html_e( 'News', 'teatatu-news-theme' ); ?></span></a>
		<?php $news_search = ttn_plugin_ready(); ?>
		<form class="tal-nav-search" role="search" method="get" action="<?php echo esc_url( home_url( '/' ) ); ?>">
			<?php echo ttn_icon( 'search', 16 ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- static SVG. ?>
			<label class="screen-reader-text" for="tal-nav-q"><?php echo esc_html( $news_search ? __( 'Search news', 'teatatu-news-theme' ) : __( 'Search this site', 'teatatu-news-theme' ) ); ?></label>
			<input id="tal-nav-q" type="search" name="s" value="<?php echo is_search() ? esc_attr( get_search_query() ) : ''; ?>" placeholder="<?php echo esc_attr( $news_search ? __( 'Search news, notices, places…', 'teatatu-news-theme' ) : __( 'Search this site…', 'teatatu-news-theme' ) ); ?>" />
			<?php if ( $news_search ) : ?>
				<input type="hidden" name="post_type" value="teatatu_news" />
			<?php endif; ?>
		</form>
		<?php if ( $share_url ) : ?>
			<a class="tal-btn tal-btn-primary tal-btn-sm" href="<?php echo esc_url( $share_url ); ?>"><?php esc_html_e( 'Share a story', 'teatatu-news-theme' ); ?></a>
		<?php endif; ?>
	</div>
	<nav class="tal-nav-sub" aria-label="<?php esc_attr_e( 'News', 'teatatu-news-theme' ); ?>">
		<?php
		wp_nav_menu(
			array(
				'theme_location' => 'primary',
				'container'      => false,
				'depth'          => 1,
				'fallback_cb'    => 'ttn_primary_menu_fallback',
			)
		);
		?>
	</nav>
	<div class="tal-nav-accent" aria-hidden="true"></div>
</header>
<main id="content">
