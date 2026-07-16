<?php
/**
 * Custom REST routes. The `teatatu_news` post type and both taxonomies
 * already get their standard collection/item routes for free from
 * register_post_type()/register_taxonomy() (see includes/post-type.php and
 * includes/taxonomies.php). This file adds the one custom route: the
 * network aggregate feed, which only responds on the Master Site.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

add_action( 'rest_api_init', 'teatatu_news_register_rest_routes' );

/**
 * Registers `GET /wp-json/teatatu-news/v1/network-feed`.
 */
function teatatu_news_register_rest_routes() {
	register_rest_route(
		'teatatu-news/v1',
		'/network-feed',
		array(
			'methods'             => WP_REST_Server::READABLE,
			'callback'            => 'teatatu_news_rest_network_feed',
			'permission_callback' => '__return_true',
			'args'                => array(
				'count'         => array( 'type' => 'integer' ),
				'columns'       => array( 'type' => 'integer' ),
				'source'        => array( 'type' => 'string' ),
				'category'      => array( 'type' => 'string' ),
				'orderby'       => array( 'type' => 'string' ),
				'order'         => array( 'type' => 'string' ),
				'exclude_sites' => array( 'type' => 'string' ),
			),
		)
	);
}

/**
 * Returns the same cross-site aggregated data the network shortcodes
 * render, as JSON — reusing the identical aggregation + caching function.
 *
 * @param WP_REST_Request $request Incoming request.
 * @return WP_REST_Response|WP_Error
 */
function teatatu_news_rest_network_feed( WP_REST_Request $request ) {
	if ( ! is_multisite() ) {
		return new WP_Error(
			'teatatu_news_not_multisite',
			__( 'This endpoint is only available on a multisite network.', 'teatatu-news' ),
			array( 'status' => 404 )
		);
	}

	if ( ! teatatu_news_is_master_site() ) {
		return new WP_Error(
			'teatatu_news_not_master_site',
			__( "This endpoint only responds on the network's configured Master Site.", 'teatatu-news' ),
			array( 'status' => 404 )
		);
	}

	$atts  = teatatu_news_shortcode_atts( $request->get_params() );
	$items = teatatu_news_get_network_feed_items( $atts );

	return rest_ensure_response( $items );
}
