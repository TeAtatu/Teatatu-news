<?php
/**
 * Resolves the display image URL for a News post. Images are never
 * downloaded or stored locally — the plugin only ever stores and serves
 * the remote URL an editor or agent submitted (post-level, falling back to
 * the assigned Source's default), so display always hotlinks the original.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Resolves the image URL for a News post: the post's own submitted URL,
 * falling back to its assigned Source's default image URL.
 *
 * @param int $post_id News post ID.
 * @return string Image URL, or an empty string if none is available.
 */
function teatatu_news_get_image_url( $post_id ) {
	$raw = get_post_meta( $post_id, '_teatatu_news_image_url', true );
	if ( $raw ) {
		return $raw;
	}

	$terms = wp_get_post_terms( $post_id, 'teatatu_news_source', array( 'fields' => 'ids' ) );
	if ( ! empty( $terms ) && ! is_wp_error( $terms ) ) {
		$source_raw = get_term_meta( $terms[0], 'teatatu_news_source_default_image', true );
		if ( $source_raw ) {
			return $source_raw;
		}
	}

	return '';
}
