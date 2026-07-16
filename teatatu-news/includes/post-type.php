<?php
/**
 * Registers the `teatatu_news` custom post type and fills in a themed
 * single-view "content" area since the post type has no editor of its own.
 * No 'thumbnail' support — images are link-only (see includes/image-url.php).
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

add_action( 'init', 'teatatu_news_register_post_type' );

/**
 * Registers the News post type.
 */
function teatatu_news_register_post_type() {
	$labels = array(
		'name'                  => __( 'News', 'teatatu-news' ),
		'singular_name'         => __( 'News Item', 'teatatu-news' ),
		'menu_name'             => __( 'News', 'teatatu-news' ),
		'add_new'               => __( 'Add New', 'teatatu-news' ),
		'add_new_item'          => __( 'Add New News Item', 'teatatu-news' ),
		'edit_item'             => __( 'Edit News Item', 'teatatu-news' ),
		'new_item'              => __( 'New News Item', 'teatatu-news' ),
		'view_item'             => __( 'View News Item', 'teatatu-news' ),
		'view_items'            => __( 'View News', 'teatatu-news' ),
		'search_items'          => __( 'Search News', 'teatatu-news' ),
		'not_found'             => __( 'No News items found', 'teatatu-news' ),
		'not_found_in_trash'    => __( 'No News items found in Trash', 'teatatu-news' ),
		'all_items'             => __( 'All News', 'teatatu-news' ),
		'archives'              => __( 'News Archives', 'teatatu-news' ),
	);

	register_post_type(
		'teatatu_news',
		array(
			'labels'             => $labels,
			'public'             => true,
			'show_in_rest'       => true,
			'rest_base'          => 'news',
			'supports'           => array( 'title', 'excerpt' ),
			'menu_icon'          => 'dashicons-media-document',
			'has_archive'        => true,
			'rewrite'            => array( 'slug' => 'news' ),
			'capability_type'    => array( 'teatatu_news', 'teatatu_news_items' ),
			'map_meta_cap'       => true,
			'show_ui'            => true,
			'show_in_menu'       => true,
			'menu_position'      => 20,
			'hierarchical'       => false,
			'exclude_from_search' => false,
		)
	);
}

add_filter( 'the_content', 'teatatu_news_filter_the_content' );

/**
 * Since News items have no editor/body content, render the excerpt plus a
 * "Read More" link in the theme's main content area for singular views.
 *
 * @param string $content Original post content.
 * @return string
 */
function teatatu_news_filter_the_content( $content ) {
	if ( ! is_singular( 'teatatu_news' ) || ! in_the_loop() || ! is_main_query() ) {
		return $content;
	}

	$post_id      = get_the_ID();
	$read_more_url = get_post_meta( $post_id, '_teatatu_news_read_more_url', true );
	$excerpt      = get_the_excerpt( $post_id );

	$output = '';
	if ( $excerpt ) {
		$output .= '<div class="teatatu-news-excerpt">' . wp_kses_post( wpautop( $excerpt ) ) . '</div>';
	}
	if ( $read_more_url ) {
		$output .= sprintf(
			'<p class="teatatu-news-read-more"><a class="teatatu-news-read-more-link" href="%1$s" target="_blank" rel="noopener noreferrer">%2$s</a></p>',
			esc_url( $read_more_url ),
			esc_html__( 'Read More', 'teatatu-news' )
		);
	}

	return $output;
}
