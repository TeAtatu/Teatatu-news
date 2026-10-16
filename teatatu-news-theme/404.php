<?php
/**
 * Not found. Stories that have been removed land here too, so point people
 * back to the news.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
get_header();
?>
<div class="ttn-wrap ttn-page">
	<h1><?php esc_html_e( 'This page has moved on', 'teatatu-news-theme' ); ?></h1>
	<p class="ttn-lead ttn-lead--gap"><?php esc_html_e( 'The story may have been removed, or the link may be out of date.', 'teatatu-news-theme' ); ?></p>
	<a class="tal-btn tal-btn-primary" href="<?php echo esc_url( ttn_plugin_ready() ? ttn_archive_url() : home_url( '/' ) ); ?>"><?php esc_html_e( 'Read the latest news', 'teatatu-news-theme' ); ?></a>
</div>
<?php
get_footer();
