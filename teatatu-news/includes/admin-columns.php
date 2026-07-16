<?php
/**
 * Custom columns for the News (edit-teatatu_news) admin list table:
 * Image, Source, Category, and Read More, with best-effort sortability.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

add_filter( 'manage_teatatu_news_posts_columns', 'teatatu_news_admin_columns' );

/**
 * Inserts custom columns after the title column.
 *
 * @param array $columns Existing columns.
 * @return array
 */
function teatatu_news_admin_columns( $columns ) {
	$new = array();
	foreach ( $columns as $key => $label ) {
		$new[ $key ] = $label;
		if ( 'title' === $key ) {
			$new['teatatu_news_image']      = __( 'Image', 'teatatu-news' );
			$new['teatatu_news_source_col']  = __( 'Source', 'teatatu-news' );
			$new['teatatu_news_category_col'] = __( 'Category', 'teatatu-news' );
			$new['teatatu_news_read_more']   = __( 'Read More', 'teatatu-news' );
		}
	}
	return $new;
}

add_action( 'manage_teatatu_news_posts_custom_column', 'teatatu_news_render_admin_column', 10, 2 );

/**
 * Renders each custom column's cell content.
 *
 * @param string $column  Column key.
 * @param int    $post_id Post ID.
 */
function teatatu_news_render_admin_column( $column, $post_id ) {
	switch ( $column ) {
		case 'teatatu_news_image':
			$url = teatatu_news_get_image_url( $post_id );
			if ( $url ) {
				printf( '<img src="%s" alt="" style="width:60px;height:auto;" />', esc_url( $url ) );
			} else {
				echo '&#8212;';
			}
			break;

		case 'teatatu_news_source_col':
			echo esc_html( teatatu_news_term_names( $post_id, 'teatatu_news_source' ) );
			break;

		case 'teatatu_news_category_col':
			echo esc_html( teatatu_news_term_names( $post_id, 'teatatu_news_category' ) );
			break;

		case 'teatatu_news_read_more':
			$url = get_post_meta( $post_id, '_teatatu_news_read_more_url', true );
			if ( $url ) {
				printf( '<a href="%s" target="_blank" rel="noopener noreferrer">%s</a>', esc_url( $url ), esc_html__( 'View', 'teatatu-news' ) );
			} else {
				echo '&#8212;';
			}
			break;
	}
}

/**
 * Comma-separated list of a post's term names for a given taxonomy.
 *
 * @param int    $post_id  Post ID.
 * @param string $taxonomy Taxonomy slug.
 * @return string
 */
function teatatu_news_term_names( $post_id, $taxonomy ) {
	$terms = get_the_terms( $post_id, $taxonomy );
	if ( empty( $terms ) || is_wp_error( $terms ) ) {
		return '—';
	}
	return implode( ', ', wp_list_pluck( $terms, 'name' ) );
}

add_filter( 'manage_edit-teatatu_news_sortable_columns', 'teatatu_news_sortable_columns' );

/**
 * Registers which custom columns are sortable.
 *
 * @param array $columns Existing sortable columns.
 * @return array
 */
function teatatu_news_sortable_columns( $columns ) {
	$columns['teatatu_news_image']       = 'teatatu_news_image';
	$columns['teatatu_news_read_more']   = 'teatatu_news_read_more';
	$columns['teatatu_news_source_col']  = 'teatatu_news_source_col';
	$columns['teatatu_news_category_col'] = 'teatatu_news_category_col';
	return $columns;
}

add_action( 'pre_get_posts', 'teatatu_news_handle_column_sorting' );

/**
 * Applies the actual sort for custom columns on the News list table.
 *
 * @param WP_Query $query Current query.
 */
function teatatu_news_handle_column_sorting( $query ) {
	if ( ! is_admin() || ! $query->is_main_query() || 'teatatu_news' !== $query->get( 'post_type' ) ) {
		return;
	}

	$orderby = $query->get( 'orderby' );

	if ( 'teatatu_news_read_more' === $orderby ) {
		$query->set( 'meta_key', '_teatatu_news_read_more_url' );
		$query->set( 'orderby', 'meta_value' );
	} elseif ( 'teatatu_news_image' === $orderby ) {
		$query->set( 'meta_key', '_teatatu_news_image_url' );
		$query->set( 'orderby', 'meta_value' );
	} elseif ( in_array( $orderby, array( 'teatatu_news_source_col', 'teatatu_news_category_col' ), true ) ) {
		$query->set( 'teatatu_news_tax_orderby', 'teatatu_news_source_col' === $orderby ? 'teatatu_news_source' : 'teatatu_news_category' );
	}
}

add_filter( 'posts_clauses', 'teatatu_news_tax_orderby_clauses', 10, 2 );

/**
 * Joins term relationship/taxonomy/term tables so Source/Category columns
 * can sort alphabetically by term name — still standard WP tables only.
 *
 * @param array    $clauses Query clauses.
 * @param WP_Query $query   Current query.
 * @return array
 */
function teatatu_news_tax_orderby_clauses( $clauses, $query ) {
	$taxonomy = $query->get( 'teatatu_news_tax_orderby' );
	if ( ! $taxonomy || ! is_admin() ) {
		return $clauses;
	}

	global $wpdb;
	$clauses['join'] .= " LEFT JOIN {$wpdb->term_relationships} AS tns_tr ON ({$wpdb->posts}.ID = tns_tr.object_id)"
		. " LEFT JOIN {$wpdb->term_taxonomy} AS tns_tt ON (tns_tr.term_taxonomy_id = tns_tt.term_taxonomy_id AND tns_tt.taxonomy = '" . esc_sql( $taxonomy ) . "')"
		. " LEFT JOIN {$wpdb->terms} AS tns_t ON (tns_tt.term_id = tns_t.term_id)";
	$clauses['orderby'] = 'tns_t.name ' . ( 'ASC' === strtoupper( $query->get( 'order' ) ) ? 'ASC' : 'DESC' );
	$clauses['groupby']  = "{$wpdb->posts}.ID";

	return $clauses;
}
