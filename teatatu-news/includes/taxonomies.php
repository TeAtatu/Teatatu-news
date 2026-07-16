<?php
/**
 * Registers the `teatatu_news_source` and `teatatu_news_category`
 * taxonomies, their term meta, and the add/edit term screen UI for the
 * Source default image.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

add_action( 'init', 'teatatu_news_register_taxonomies' );

/**
 * Registers both News taxonomies with explicit, narrowly-scoped capabilities.
 */
function teatatu_news_register_taxonomies() {
	register_taxonomy(
		'teatatu_news_source',
		array( 'teatatu_news' ),
		array(
			'labels'            => array(
				'name'          => __( 'Sources', 'teatatu-news' ),
				'singular_name' => __( 'Source', 'teatatu-news' ),
				'menu_name'     => __( 'Sources', 'teatatu-news' ),
				'add_new_item'  => __( 'Add New Source', 'teatatu-news' ),
				'edit_item'     => __( 'Edit Source', 'teatatu-news' ),
				'search_items'  => __( 'Search Sources', 'teatatu-news' ),
			),
			'hierarchical'      => true,
			'public'            => true,
			'show_in_rest'      => true,
			'rest_base'         => 'news-sources',
			'show_admin_column' => true,
			'capabilities'      => array(
				'manage_terms' => 'manage_teatatu_news_sources',
				'edit_terms'   => 'edit_teatatu_news_sources',
				'delete_terms' => 'delete_teatatu_news_sources',
				'assign_terms' => 'assign_teatatu_news_sources',
			),
		)
	);

	register_taxonomy(
		'teatatu_news_category',
		array( 'teatatu_news' ),
		array(
			'labels'            => array(
				'name'          => __( 'Categories', 'teatatu-news' ),
				'singular_name' => __( 'Category', 'teatatu-news' ),
				'menu_name'     => __( 'Categories', 'teatatu-news' ),
				'add_new_item'  => __( 'Add New Category', 'teatatu-news' ),
				'edit_item'     => __( 'Edit Category', 'teatatu-news' ),
				'search_items'  => __( 'Search Categories', 'teatatu-news' ),
			),
			'hierarchical'      => true,
			'public'            => true,
			'show_in_rest'      => true,
			'rest_base'         => 'news-categories',
			'show_admin_column' => true,
			'capabilities'      => array(
				'manage_terms' => 'manage_teatatu_news_categories',
				'edit_terms'   => 'edit_teatatu_news_categories',
				'delete_terms' => 'delete_teatatu_news_categories',
				'assign_terms' => 'assign_teatatu_news_categories',
			),
		)
	);
}

add_action( 'init', 'teatatu_news_register_term_meta' );

/**
 * Registers the Source term meta field (the default image URL — link-only,
 * never downloaded).
 */
function teatatu_news_register_term_meta() {
	register_term_meta(
		'teatatu_news_source',
		'teatatu_news_source_default_image',
		array(
			'type'              => 'string',
			'single'            => true,
			'sanitize_callback' => 'esc_url_raw',
			'show_in_rest'      => true,
			'auth_callback'     => function () {
				return current_user_can( 'manage_teatatu_news_sources' );
			},
		)
	);
}

add_action( 'teatatu_news_source_add_form_fields', 'teatatu_news_source_add_form_field' );
add_action( 'teatatu_news_source_edit_form_fields', 'teatatu_news_source_edit_form_field' );

/**
 * Default Image URL field on the "Add New Source" screen.
 */
function teatatu_news_source_add_form_field() {
	?>
	<div class="form-field">
		<label for="teatatu_news_source_default_image"><?php esc_html_e( 'Default Image URL', 'teatatu-news' ); ?></label>
		<input type="url" name="teatatu_news_source_default_image" id="teatatu_news_source_default_image" value="" class="regular-text" placeholder="https://example.com/logo.jpg" />
		<p><?php esc_html_e( 'Used as the fallback image for News items from this Source when no item-specific image is set.', 'teatatu-news' ); ?></p>
	</div>
	<?php
}

/**
 * Default Image URL field on the "Edit Source" screen.
 *
 * @param WP_Term $term Term being edited.
 */
function teatatu_news_source_edit_form_field( $term ) {
	$value = get_term_meta( $term->term_id, 'teatatu_news_source_default_image', true );
	?>
	<tr class="form-field">
		<th scope="row"><label for="teatatu_news_source_default_image"><?php esc_html_e( 'Default Image URL', 'teatatu-news' ); ?></label></th>
		<td>
			<input type="url" name="teatatu_news_source_default_image" id="teatatu_news_source_default_image" value="<?php echo esc_attr( $value ); ?>" class="regular-text" placeholder="https://example.com/logo.jpg" />
			<p class="description"><?php esc_html_e( 'Used as the fallback image for News items from this Source when no item-specific image is set.', 'teatatu-news' ); ?></p>
			<?php if ( $value ) : ?>
				<p><img src="<?php echo esc_url( $value ); ?>" alt="" style="max-width:150px;height:auto;" /></p>
			<?php endif; ?>
		</td>
	</tr>
	<?php
}

add_action( 'saved_teatatu_news_source', 'teatatu_news_save_source_default_image' );

/**
 * Saves the Default Image URL field from either term screen.
 *
 * @param int $term_id Term ID.
 */
function teatatu_news_save_source_default_image( $term_id ) {
	if ( ! isset( $_POST['teatatu_news_source_default_image'] ) ) {
		return;
	}

	$nonce_ok = false;
	if ( isset( $_POST['_wpnonce'] ) && wp_verify_nonce( wp_unslash( $_POST['_wpnonce'] ), 'update-tag_' . $term_id ) ) {
		$nonce_ok = true;
	} elseif ( isset( $_POST['_wpnonce_add-tag'] ) && wp_verify_nonce( wp_unslash( $_POST['_wpnonce_add-tag'] ), 'add-tag' ) ) {
		$nonce_ok = true;
	}
	if ( ! $nonce_ok ) {
		return;
	}
	if ( ! current_user_can( 'manage_teatatu_news_sources' ) ) {
		return;
	}
	$url = esc_url_raw( wp_unslash( $_POST['teatatu_news_source_default_image'] ) );
	update_term_meta( $term_id, 'teatatu_news_source_default_image', $url );
}
