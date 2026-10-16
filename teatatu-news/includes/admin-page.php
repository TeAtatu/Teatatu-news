<?php
/**
 * Top-level "Teatatu News" maintenance admin page: a non-technical editor's
 * one-stop screen for News items, Sources, and Categories, separate from
 * the standard post-list UI.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

add_action( 'admin_menu', 'teatatu_news_register_admin_menu' );

/**
 * Registers the top-level "Teatatu News" admin menu.
 */
function teatatu_news_register_admin_menu() {
	add_menu_page(
		__( 'Teatatu News', 'teatatu-news' ),
		__( 'Teatatu News', 'teatatu-news' ),
		'edit_teatatu_news_items',
		'teatatu-news',
		'teatatu_news_render_admin_page',
		'dashicons-media-document',
		21
	);
}

/**
 * Renders the maintenance page, dispatching to the active tab.
 */
function teatatu_news_render_admin_page() {
	if ( ! current_user_can( 'edit_teatatu_news_items' ) ) {
		wp_die( esc_html__( 'You do not have permission to access this page.', 'teatatu-news' ) );
	}

	$tab = isset( $_GET['tab'] ) ? sanitize_key( wp_unslash( $_GET['tab'] ) ) : 'news';
	if ( ! in_array( $tab, array( 'news', 'sources', 'categories', 'rss', 'html', 'shortcodes' ), true ) ) {
		$tab = 'news';
	}
	?>
	<div class="wrap">
		<h1><?php esc_html_e( 'Teatatu News', 'teatatu-news' ); ?></h1>
		<h2 class="nav-tab-wrapper">
			<a href="<?php echo esc_url( add_query_arg( array( 'page' => 'teatatu-news', 'tab' => 'news' ), admin_url( 'admin.php' ) ) ); ?>" class="nav-tab <?php echo 'news' === $tab ? 'nav-tab-active' : ''; ?>"><?php esc_html_e( 'News Items', 'teatatu-news' ); ?></a>
			<a href="<?php echo esc_url( add_query_arg( array( 'page' => 'teatatu-news', 'tab' => 'sources' ), admin_url( 'admin.php' ) ) ); ?>" class="nav-tab <?php echo 'sources' === $tab ? 'nav-tab-active' : ''; ?>"><?php esc_html_e( 'Manage Sources', 'teatatu-news' ); ?></a>
			<a href="<?php echo esc_url( add_query_arg( array( 'page' => 'teatatu-news', 'tab' => 'categories' ), admin_url( 'admin.php' ) ) ); ?>" class="nav-tab <?php echo 'categories' === $tab ? 'nav-tab-active' : ''; ?>"><?php esc_html_e( 'Manage Categories', 'teatatu-news' ); ?></a>
			<?php if ( current_user_can( 'manage_teatatu_news_rss_feeds' ) ) : ?>
				<a href="<?php echo esc_url( add_query_arg( array( 'page' => 'teatatu-news', 'tab' => 'rss' ), admin_url( 'admin.php' ) ) ); ?>" class="nav-tab <?php echo 'rss' === $tab ? 'nav-tab-active' : ''; ?>"><?php esc_html_e( 'RSS Feeds', 'teatatu-news' ); ?></a>
			<?php endif; ?>
			<?php if ( current_user_can( 'manage_teatatu_news_html_feeds' ) ) : ?>
				<a href="<?php echo esc_url( add_query_arg( array( 'page' => 'teatatu-news', 'tab' => 'html' ), admin_url( 'admin.php' ) ) ); ?>" class="nav-tab <?php echo 'html' === $tab ? 'nav-tab-active' : ''; ?>"><?php esc_html_e( 'HTML Feeds', 'teatatu-news' ); ?></a>
			<?php endif; ?>
			<a href="<?php echo esc_url( add_query_arg( array( 'page' => 'teatatu-news', 'tab' => 'shortcodes' ), admin_url( 'admin.php' ) ) ); ?>" class="nav-tab <?php echo 'shortcodes' === $tab ? 'nav-tab-active' : ''; ?>"><?php esc_html_e( 'Shortcodes', 'teatatu-news' ); ?></a>
		</h2>
		<?php teatatu_news_render_admin_notices(); ?>
		<?php
		if ( 'news' === $tab ) {
			teatatu_news_render_news_tab();
		} elseif ( 'sources' === $tab ) {
			if ( ! teatatu_news_render_managed_list( 'teatatu_news_source' ) ) {
				teatatu_news_render_term_tab( 'teatatu_news_source' );
			}
		} elseif ( 'categories' === $tab ) {
			if ( ! teatatu_news_render_managed_list( 'teatatu_news_category' ) ) {
				teatatu_news_render_term_tab( 'teatatu_news_category' );
			}
		} elseif ( 'rss' === $tab ) {
			teatatu_news_render_rss_feeds_tab();
		} elseif ( 'html' === $tab ) {
			teatatu_news_render_html_feeds_tab();
		} else {
			teatatu_news_render_shortcodes_tab();
		}
		?>
	</div>
	<?php
}

/**
 * Gets the ID of the first term assigned to a post for a taxonomy, safely
 * handling the WP_Error case.
 *
 * @param int    $post_id  Post ID.
 * @param string $taxonomy Taxonomy slug.
 * @return int 0 if none assigned.
 */
function teatatu_news_first_term_id( $post_id, $taxonomy ) {
	$terms = wp_get_post_terms( $post_id, $taxonomy, array( 'fields' => 'ids' ) );
	if ( empty( $terms ) || is_wp_error( $terms ) ) {
		return 0;
	}
	return (int) $terms[0];
}

/**
 * Prints success/error notices stashed by the admin-post.php handlers.
 */
function teatatu_news_render_admin_notices() {
	$uid = get_current_user_id();

	$notice = get_transient( 'teatatu_news_admin_notice_' . $uid );
	if ( $notice ) {
		delete_transient( 'teatatu_news_admin_notice_' . $uid );
		printf( '<div class="notice notice-success is-dismissible"><p>%s</p></div>', esc_html( $notice ) );
	}

	$errors = get_transient( 'teatatu_news_admin_errors_' . $uid );
	if ( $errors ) {
		delete_transient( 'teatatu_news_admin_errors_' . $uid );
		echo '<div class="notice notice-error"><p>' . esc_html( implode( ' ', (array) $errors ) ) . '</p></div>';
	}
}

/**
 * Builds a display string for a WP_Error, appending any extra error data
 * when present. wp_insert_post()/wp_insert_term() and friends often wrap a
 * real underlying cause (e.g. the raw $wpdb error behind WordPress core's
 * generic "Could not insert post into the database.") in that data slot —
 * get_error_message() alone would silently discard it.
 *
 * @param WP_Error $error Error to format.
 * @return string
 */
function teatatu_news_format_wp_error( $error ) {
	$message = $error->get_error_message();
	$data    = $error->get_error_data();
	if ( is_string( $data ) && '' !== trim( $data ) && false === strpos( $message, $data ) ) {
		$message .= ' (' . $data . ')';
	}
	return $message;
}

/**
 * Retrieves and clears any stashed form values for refilling a form after a
 * validation error.
 *
 * @return array
 */
function teatatu_news_get_stashed_formdata() {
	$uid  = get_current_user_id();
	$data = get_transient( 'teatatu_news_admin_formdata_' . $uid );
	if ( $data ) {
		delete_transient( 'teatatu_news_admin_formdata_' . $uid );
		return (array) $data;
	}
	return array();
}

// -----------------------------------------------------------------------
// News Items tab
// -----------------------------------------------------------------------

/**
 * Renders the News Items list + Add/Edit form.
 */
function teatatu_news_render_news_tab() {
	$editing_id = isset( $_GET['edit'] ) ? absint( $_GET['edit'] ) : 0;
	$editing    = $editing_id ? get_post( $editing_id ) : null;
	$stashed    = teatatu_news_get_stashed_formdata();
	?>
	<h2><?php echo $editing ? esc_html__( 'Edit News Item', 'teatatu-news' ) : esc_html__( 'Add News Item', 'teatatu-news' ); ?></h2>
	<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
		<?php wp_nonce_field( 'teatatu_news_save_item', 'teatatu_news_item_nonce' ); ?>
		<input type="hidden" name="action" value="teatatu_news_save_item" />
		<input type="hidden" name="post_id" value="<?php echo esc_attr( $editing_id ); ?>" />
		<table class="form-table">
			<tr>
				<th><label for="tn_title"><?php esc_html_e( 'Title', 'teatatu-news' ); ?> *</label></th>
				<td><input type="text" id="tn_title" name="title" class="regular-text" value="<?php echo esc_attr( $stashed['title'] ?? ( $editing ? $editing->post_title : '' ) ); ?>" required /></td>
			</tr>
			<tr>
				<th><label for="tn_excerpt"><?php esc_html_e( 'Excerpt', 'teatatu-news' ); ?></label></th>
				<td><textarea id="tn_excerpt" name="excerpt" class="large-text" rows="3"><?php echo esc_textarea( $stashed['excerpt'] ?? ( $editing ? $editing->post_excerpt : '' ) ); ?></textarea></td>
			</tr>
			<tr>
				<th><label for="tn_source"><?php esc_html_e( 'Source', 'teatatu-news' ); ?></label></th>
				<td>
					<?php
					wp_dropdown_categories(
						array(
							'taxonomy'         => 'teatatu_news_source',
							'name'             => 'source_id',
							'id'               => 'tn_source',
							'hide_empty'       => false,
							'show_option_none' => __( '— None —', 'teatatu-news' ),
							'option_none_value' => 0,
							'selected'         => isset( $stashed['source_id'] ) ? (int) $stashed['source_id'] : ( $editing ? teatatu_news_first_term_id( $editing_id, 'teatatu_news_source' ) : 0 ),
						)
					);
					?>
				</td>
			</tr>
			<tr>
				<th><label for="tn_category"><?php esc_html_e( 'Category', 'teatatu-news' ); ?></label></th>
				<td>
					<?php
					wp_dropdown_categories(
						array(
							'taxonomy'         => 'teatatu_news_category',
							'name'             => 'category_id',
							'id'               => 'tn_category',
							'hide_empty'       => false,
							'show_option_none' => __( '— None —', 'teatatu-news' ),
							'option_none_value' => 0,
							'selected'         => isset( $stashed['category_id'] ) ? (int) $stashed['category_id'] : ( $editing ? teatatu_news_first_term_id( $editing_id, 'teatatu_news_category' ) : 0 ),
						)
					);
					?>
				</td>
			</tr>
			<tr>
				<th><label for="tn_read_more"><?php esc_html_e( 'Read More URL', 'teatatu-news' ); ?> *</label></th>
				<td><input type="url" id="tn_read_more" name="read_more_url" class="large-text" value="<?php echo esc_attr( $stashed['read_more_url'] ?? ( $editing ? get_post_meta( $editing_id, '_teatatu_news_read_more_url', true ) : '' ) ); ?>" required /></td>
			</tr>
			<tr>
				<th><label for="tn_image"><?php esc_html_e( 'Image URL', 'teatatu-news' ); ?></label></th>
				<td><input type="url" id="tn_image" name="image_url" class="large-text" value="<?php echo esc_attr( $stashed['image_url'] ?? ( $editing ? get_post_meta( $editing_id, '_teatatu_news_image_url', true ) : '' ) ); ?>" placeholder="<?php esc_attr_e( "Leave blank to use the Source's default image", 'teatatu-news' ); ?>" /></td>
			</tr>
			<tr>
				<th><label for="tn_status"><?php esc_html_e( 'Status', 'teatatu-news' ); ?></label></th>
				<td>
					<select id="tn_status" name="status">
						<?php $current_status = $stashed['status'] ?? ( $editing ? $editing->post_status : 'draft' ); ?>
						<option value="draft" <?php selected( $current_status, 'draft' ); ?>><?php esc_html_e( 'Draft', 'teatatu-news' ); ?></option>
						<option value="pending" <?php selected( $current_status, 'pending' ); ?>><?php esc_html_e( 'Pending Review', 'teatatu-news' ); ?></option>
						<?php if ( current_user_can( 'publish_teatatu_news_items' ) ) : ?>
							<option value="publish" <?php selected( $current_status, 'publish' ); ?>><?php esc_html_e( 'Published', 'teatatu-news' ); ?></option>
						<?php endif; ?>
					</select>
					<p class="description"><?php esc_html_e( 'New items always start as Draft. Publishing is a separate, explicit action.', 'teatatu-news' ); ?></p>
				</td>
			</tr>
		</table>
		<?php submit_button( $editing ? __( 'Update News Item', 'teatatu-news' ) : __( 'Add News Item', 'teatatu-news' ) ); ?>
	</form>

	<hr />
	<h2><?php esc_html_e( 'All News Items', 'teatatu-news' ); ?></h2>
	<?php
	$paged = isset( $_GET['paged'] ) ? max( 1, absint( $_GET['paged'] ) ) : 1;
	$query = new WP_Query(
		array(
			'post_type'      => 'teatatu_news',
			'post_status'    => 'any',
			'posts_per_page' => 20,
			'paged'          => $paged,
			'orderby'        => 'date',
			'order'          => 'DESC',
		)
	);
	?>
	<table class="wp-list-table widefat fixed striped">
		<thead>
			<tr>
				<th><?php esc_html_e( 'Image', 'teatatu-news' ); ?></th>
				<th><?php esc_html_e( 'Title', 'teatatu-news' ); ?></th>
				<th><?php esc_html_e( 'Source', 'teatatu-news' ); ?></th>
				<th><?php esc_html_e( 'Category', 'teatatu-news' ); ?></th>
				<th><?php esc_html_e( 'Status', 'teatatu-news' ); ?></th>
				<th><?php esc_html_e( 'Date', 'teatatu-news' ); ?></th>
				<th><?php esc_html_e( 'Read More', 'teatatu-news' ); ?></th>
				<th><?php esc_html_e( 'Actions', 'teatatu-news' ); ?></th>
			</tr>
		</thead>
		<tbody>
		<?php if ( $query->have_posts() ) : ?>
			<?php
			while ( $query->have_posts() ) :
				$query->the_post();
				$id            = get_the_ID();
				$image_url     = teatatu_news_get_image_url( $id );
				$read_more_url = get_post_meta( $id, '_teatatu_news_read_more_url', true );
				$edit_url      = add_query_arg( array( 'page' => 'teatatu-news', 'tab' => 'news', 'edit' => $id ), admin_url( 'admin.php' ) );
				$delete_url    = wp_nonce_url( add_query_arg( array( 'action' => 'teatatu_news_delete_item', 'id' => $id ), admin_url( 'admin-post.php' ) ), 'teatatu_news_delete_item_' . $id );
				?>
				<tr>
					<td><?php if ( $image_url ) : ?><img src="<?php echo esc_url( $image_url ); ?>" alt="" style="width:50px;height:auto;" /><?php else : ?>&#8212;<?php endif; ?></td>
					<td><?php echo esc_html( get_the_title() ); ?></td>
					<td><?php echo esc_html( teatatu_news_term_names( $id, 'teatatu_news_source' ) ); ?></td>
					<td><?php echo esc_html( teatatu_news_term_names( $id, 'teatatu_news_category' ) ); ?></td>
					<td>
						<?php echo esc_html( get_post_status( $id ) ); ?>
						<?php $rss_pending = get_post_meta( $id, '_teatatu_news_rss_pending_update', true ); ?>
						<?php if ( is_array( $rss_pending ) && current_user_can( 'edit_post', $id ) ) : ?>
							<br /><span style="color:#d63638;"><?php esc_html_e( 'Feed update available', 'teatatu-news' ); ?></span><br />
							<a href="<?php echo esc_url( wp_nonce_url( add_query_arg( array( 'action' => 'teatatu_news_apply_rss_update', 'post_id' => $id ), admin_url( 'admin-post.php' ) ), 'teatatu_news_apply_rss_update_' . $id ) ); ?>"><?php esc_html_e( 'Apply', 'teatatu-news' ); ?></a>
							 | <a href="<?php echo esc_url( wp_nonce_url( add_query_arg( array( 'action' => 'teatatu_news_dismiss_rss_update', 'post_id' => $id ), admin_url( 'admin-post.php' ) ), 'teatatu_news_dismiss_rss_update_' . $id ) ); ?>"><?php esc_html_e( 'Dismiss', 'teatatu-news' ); ?></a>
						<?php endif; ?>
					</td>
					<td><?php echo esc_html( get_the_date() ); ?></td>
					<td><?php if ( $read_more_url ) : ?><a href="<?php echo esc_url( $read_more_url ); ?>" target="_blank" rel="noopener noreferrer"><?php esc_html_e( 'View', 'teatatu-news' ); ?></a><?php else : ?>&#8212;<?php endif; ?></td>
					<td>
						<?php if ( current_user_can( 'edit_post', $id ) ) : ?>
							<a href="<?php echo esc_url( $edit_url ); ?>"><?php esc_html_e( 'Edit', 'teatatu-news' ); ?></a>
						<?php endif; ?>
						<?php if ( current_user_can( 'delete_post', $id ) ) : ?>
							 | <a href="<?php echo esc_url( $delete_url ); ?>" onclick="return confirm('<?php echo esc_js( __( 'Delete this News item permanently?', 'teatatu-news' ) ); ?>');"><?php esc_html_e( 'Delete', 'teatatu-news' ); ?></a>
						<?php endif; ?>
					</td>
				</tr>
			<?php endwhile; ?>
		<?php else : ?>
			<tr><td colspan="8"><?php esc_html_e( 'No News items yet.', 'teatatu-news' ); ?></td></tr>
		<?php endif; ?>
		</tbody>
	</table>
	<?php
	wp_reset_postdata();

	if ( $query->max_num_pages > 1 ) {
		echo '<div class="tablenav"><div class="tablenav-pages">';
		echo wp_kses_post(
			paginate_links(
				array(
					'base'      => add_query_arg( 'paged', '%#%' ),
					'format'    => '',
					'current'   => $paged,
					'total'     => $query->max_num_pages,
				)
			)
		);
		echo '</div></div>';
	}
}

add_action( 'admin_post_teatatu_news_save_item', 'teatatu_news_handle_save_item' );

/**
 * Handles the News Item Add/Edit form submission.
 */
function teatatu_news_handle_save_item() {
	if ( ! isset( $_POST['teatatu_news_item_nonce'] ) || ! wp_verify_nonce( wp_unslash( $_POST['teatatu_news_item_nonce'] ), 'teatatu_news_save_item' ) ) {
		wp_die( esc_html__( 'Security check failed.', 'teatatu-news' ) );
	}
	if ( ! current_user_can( 'edit_teatatu_news_items' ) ) {
		wp_die( esc_html__( 'You do not have permission to do that.', 'teatatu-news' ), 403 );
	}

	$post_id       = isset( $_POST['post_id'] ) ? absint( $_POST['post_id'] ) : 0;
	$title         = isset( $_POST['title'] ) ? sanitize_text_field( wp_unslash( $_POST['title'] ) ) : '';
	$excerpt       = isset( $_POST['excerpt'] ) ? sanitize_textarea_field( wp_unslash( $_POST['excerpt'] ) ) : '';
	$read_more_url = isset( $_POST['read_more_url'] ) ? esc_url_raw( wp_unslash( $_POST['read_more_url'] ) ) : '';
	$image_url     = isset( $_POST['image_url'] ) ? esc_url_raw( wp_unslash( $_POST['image_url'] ) ) : '';
	$source_id     = isset( $_POST['source_id'] ) ? absint( $_POST['source_id'] ) : 0;
	$category_id   = isset( $_POST['category_id'] ) ? absint( $_POST['category_id'] ) : 0;
	$status        = isset( $_POST['status'] ) ? sanitize_key( wp_unslash( $_POST['status'] ) ) : 'draft';

	$errors = array();
	if ( '' === trim( $title ) ) {
		$errors[] = __( 'Title is required.', 'teatatu-news' );
	}
	if ( '' === $read_more_url ) {
		$errors[] = __( 'Read More URL is required.', 'teatatu-news' );
	}
	if ( $post_id && 'teatatu_news' !== get_post_type( $post_id ) ) {
		$errors[] = __( 'That item is not a News item.', 'teatatu-news' );
	} elseif ( $post_id && ! current_user_can( 'edit_post', $post_id ) ) {
		$errors[] = __( 'You are not allowed to edit this item.', 'teatatu-news' );
	}

	if ( ! empty( $errors ) ) {
		$uid = get_current_user_id();
		set_transient( 'teatatu_news_admin_errors_' . $uid, $errors, 45 );
		set_transient( 'teatatu_news_admin_formdata_' . $uid, wp_unslash( $_POST ), 45 );
		$redirect = array( 'page' => 'teatatu-news', 'tab' => 'news' );
		if ( $post_id ) {
			$redirect['edit'] = $post_id;
		}
		wp_safe_redirect( add_query_arg( $redirect, admin_url( 'admin.php' ) ) );
		exit;
	}

	$allowed_statuses = array( 'draft', 'pending' );
	if ( current_user_can( 'publish_teatatu_news_items' ) ) {
		$allowed_statuses[] = 'publish';
	}
	if ( ! in_array( $status, $allowed_statuses, true ) ) {
		$status = 'draft';
	}

	$postarr = array(
		'post_title'   => $title,
		'post_excerpt' => $excerpt,
		'post_status'  => $status,
	);

	if ( $post_id ) {
		// post_type is deliberately omitted here — already verified above to be
		// 'teatatu_news', and must never be used to retype an unrelated post.
		$postarr['ID'] = $post_id;
		$result        = wp_update_post( $postarr, true );
	} else {
		$postarr['post_type'] = 'teatatu_news';
		$result               = wp_insert_post( $postarr, true );
	}

	if ( is_wp_error( $result ) ) {
		set_transient( 'teatatu_news_admin_errors_' . get_current_user_id(), array( teatatu_news_format_wp_error( $result ) ), 45 );
		wp_safe_redirect( add_query_arg( array( 'page' => 'teatatu-news', 'tab' => 'news' ), admin_url( 'admin.php' ) ) );
		exit;
	}

	$post_id = $result;
	update_post_meta( $post_id, '_teatatu_news_read_more_url', $read_more_url );
	update_post_meta( $post_id, '_teatatu_news_image_url', $image_url );
	wp_set_post_terms( $post_id, $source_id ? array( $source_id ) : array(), 'teatatu_news_source' );
	wp_set_post_terms( $post_id, $category_id ? array( $category_id ) : array(), 'teatatu_news_category' );

	set_transient( 'teatatu_news_admin_notice_' . get_current_user_id(), __( 'News item saved.', 'teatatu-news' ), 45 );
	wp_safe_redirect( add_query_arg( array( 'page' => 'teatatu-news', 'tab' => 'news' ), admin_url( 'admin.php' ) ) );
	exit;
}

add_action( 'admin_post_teatatu_news_delete_item', 'teatatu_news_handle_delete_item' );

/**
 * Handles News item deletion from the maintenance list table.
 */
function teatatu_news_handle_delete_item() {
	$id = isset( $_GET['id'] ) ? absint( $_GET['id'] ) : 0;
	check_admin_referer( 'teatatu_news_delete_item_' . $id );

	if ( ! $id || 'teatatu_news' !== get_post_type( $id ) || ! current_user_can( 'delete_post', $id ) ) {
		wp_die( esc_html__( 'You do not have permission to do that.', 'teatatu-news' ), 403 );
	}

	wp_delete_post( $id, true );
	set_transient( 'teatatu_news_admin_notice_' . get_current_user_id(), __( 'News item deleted.', 'teatatu-news' ), 45 );
	wp_safe_redirect( add_query_arg( array( 'page' => 'teatatu-news', 'tab' => 'news' ), admin_url( 'admin.php' ) ) );
	exit;
}

// -----------------------------------------------------------------------
// Sources / Categories tabs (shared renderer)
// -----------------------------------------------------------------------

/**
 * Renders the Manage Sources / Manage Categories tab for a given taxonomy.
 *
 * @param string $taxonomy 'teatatu_news_source' or 'teatatu_news_category'.
 */
function teatatu_news_render_term_tab( $taxonomy ) {
	$is_source  = 'teatatu_news_source' === $taxonomy;
	$manage_cap = $is_source ? 'manage_teatatu_news_sources' : 'manage_teatatu_news_categories';
	$tab_slug   = $is_source ? 'sources' : 'categories';
	$label      = $is_source ? __( 'Source', 'teatatu-news' ) : __( 'Category', 'teatatu-news' );

	if ( ! current_user_can( $manage_cap ) ) {
		echo '<p>' . esc_html__( 'You do not have permission to manage this.', 'teatatu-news' ) . '</p>';
		return;
	}

	$editing_id = isset( $_GET['edit'] ) ? absint( $_GET['edit'] ) : 0;
	$editing    = $editing_id ? get_term( $editing_id, $taxonomy ) : null;
	if ( is_wp_error( $editing ) ) {
		$editing = null;
	}
	$stashed = teatatu_news_get_stashed_formdata();
	?>
	<h2>
		<?php
		/* translators: %s: "Source" or "Category". */
		echo esc_html( $editing ? sprintf( __( 'Edit %s', 'teatatu-news' ), $label ) : sprintf( __( 'Add %s', 'teatatu-news' ), $label ) );
		?>
	</h2>
	<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
		<?php wp_nonce_field( 'teatatu_news_save_term', 'teatatu_news_term_nonce' ); ?>
		<input type="hidden" name="action" value="teatatu_news_save_term" />
		<input type="hidden" name="taxonomy" value="<?php echo esc_attr( $taxonomy ); ?>" />
		<input type="hidden" name="term_id" value="<?php echo esc_attr( $editing_id ); ?>" />
		<table class="form-table">
			<tr>
				<th><label for="tn_term_name"><?php esc_html_e( 'Name', 'teatatu-news' ); ?> *</label></th>
				<td><input type="text" id="tn_term_name" name="name" class="regular-text" value="<?php echo esc_attr( $stashed['name'] ?? ( $editing ? $editing->name : '' ) ); ?>" required /></td>
			</tr>
			<tr>
				<th><label for="tn_term_parent"><?php esc_html_e( 'Parent', 'teatatu-news' ); ?></label></th>
				<td>
					<?php
					wp_dropdown_categories(
						array(
							'taxonomy'         => $taxonomy,
							'name'             => 'parent',
							'id'               => 'tn_term_parent',
							'hide_empty'       => false,
							'show_option_none' => __( '— None —', 'teatatu-news' ),
							'option_none_value' => 0,
							'exclude_tree'     => $editing_id,
							'selected'         => isset( $stashed['parent'] ) ? (int) $stashed['parent'] : ( $editing ? $editing->parent : 0 ),
						)
					);
					?>
				</td>
			</tr>
			<tr>
				<th><label for="tn_term_description"><?php esc_html_e( 'Description', 'teatatu-news' ); ?></label></th>
				<td><textarea id="tn_term_description" name="description" class="large-text" rows="3"><?php echo esc_textarea( $stashed['description'] ?? ( $editing ? $editing->description : '' ) ); ?></textarea></td>
			</tr>
			<?php if ( $is_source ) : ?>
				<tr>
					<th><label for="tn_term_default_image"><?php esc_html_e( 'Default Image URL', 'teatatu-news' ); ?></label></th>
					<td><input type="url" id="tn_term_default_image" name="default_image" class="large-text" value="<?php echo esc_attr( $stashed['default_image'] ?? ( $editing ? get_term_meta( $editing_id, 'teatatu_news_source_default_image', true ) : '' ) ); ?>" /></td>
				</tr>
			<?php endif; ?>
		</table>
		<?php submit_button( $editing ? __( 'Update', 'teatatu-news' ) : __( 'Add New', 'teatatu-news' ) ); ?>
	</form>

	<hr />
	<h2><?php echo esc_html( $is_source ? __( 'All Sources', 'teatatu-news' ) : __( 'All Categories', 'teatatu-news' ) ); ?></h2>
	<?php
	$terms = get_terms( array( 'taxonomy' => $taxonomy, 'hide_empty' => false ) );
	?>
	<table class="wp-list-table widefat fixed striped">
		<thead>
			<tr>
				<th><?php esc_html_e( 'Name', 'teatatu-news' ); ?></th>
				<th><?php esc_html_e( 'Description', 'teatatu-news' ); ?></th>
				<?php if ( $is_source ) : ?><th><?php esc_html_e( 'Default Image', 'teatatu-news' ); ?></th><?php endif; ?>
				<th><?php esc_html_e( 'Count', 'teatatu-news' ); ?></th>
				<th><?php esc_html_e( 'Actions', 'teatatu-news' ); ?></th>
			</tr>
		</thead>
		<tbody>
		<?php if ( ! empty( $terms ) && ! is_wp_error( $terms ) ) : ?>
			<?php foreach ( $terms as $term ) : ?>
				<?php
				$edit_url   = add_query_arg( array( 'page' => 'teatatu-news', 'tab' => $tab_slug, 'edit' => $term->term_id ), admin_url( 'admin.php' ) );
				$delete_url = wp_nonce_url( add_query_arg( array( 'action' => 'teatatu_news_delete_term', 'taxonomy' => $taxonomy, 'term_id' => $term->term_id ), admin_url( 'admin-post.php' ) ), 'teatatu_news_delete_term_' . $term->term_id );
				?>
				<tr>
					<td><?php echo esc_html( $term->name ); ?></td>
					<td><?php echo esc_html( $term->description ); ?></td>
					<?php if ( $is_source ) : ?>
						<td>
							<?php $img = get_term_meta( $term->term_id, 'teatatu_news_source_default_image', true ); ?>
							<?php if ( $img ) : ?><img src="<?php echo esc_url( $img ); ?>" alt="" style="width:40px;height:auto;" /><?php else : ?>&#8212;<?php endif; ?>
						</td>
					<?php endif; ?>
					<td><?php echo esc_html( $term->count ); ?></td>
					<td>
						<a href="<?php echo esc_url( $edit_url ); ?>"><?php esc_html_e( 'Edit', 'teatatu-news' ); ?></a>
						 | <a href="<?php echo esc_url( $delete_url ); ?>" onclick="return confirm('<?php echo esc_js( __( 'Delete this term?', 'teatatu-news' ) ); ?>');"><?php esc_html_e( 'Delete', 'teatatu-news' ); ?></a>
					</td>
				</tr>
			<?php endforeach; ?>
		<?php else : ?>
			<tr><td colspan="5"><?php esc_html_e( 'None yet.', 'teatatu-news' ); ?></td></tr>
		<?php endif; ?>
		</tbody>
	</table>
	<?php
}

add_action( 'admin_post_teatatu_news_save_term', 'teatatu_news_handle_save_term' );

/**
 * Handles the Manage Sources / Manage Categories Add/Edit form submission.
 */
function teatatu_news_handle_save_term() {
	if ( ! isset( $_POST['teatatu_news_term_nonce'] ) || ! wp_verify_nonce( wp_unslash( $_POST['teatatu_news_term_nonce'] ), 'teatatu_news_save_term' ) ) {
		wp_die( esc_html__( 'Security check failed.', 'teatatu-news' ) );
	}

	$taxonomy = isset( $_POST['taxonomy'] ) ? sanitize_key( wp_unslash( $_POST['taxonomy'] ) ) : '';
	if ( ! in_array( $taxonomy, array( 'teatatu_news_source', 'teatatu_news_category' ), true ) ) {
		wp_die( esc_html__( 'Invalid taxonomy.', 'teatatu-news' ) );
	}
	$is_source = 'teatatu_news_source' === $taxonomy;
	$tab_slug  = $is_source ? 'sources' : 'categories';
	$cap       = $is_source ? 'manage_teatatu_news_sources' : 'manage_teatatu_news_categories';
	if ( ! current_user_can( $cap ) ) {
		wp_die( esc_html__( 'You do not have permission to do that.', 'teatatu-news' ), 403 );
	}

	$term_id       = isset( $_POST['term_id'] ) ? absint( $_POST['term_id'] ) : 0;
	$name          = isset( $_POST['name'] ) ? sanitize_text_field( wp_unslash( $_POST['name'] ) ) : '';
	$description   = isset( $_POST['description'] ) ? sanitize_textarea_field( wp_unslash( $_POST['description'] ) ) : '';
	$parent        = isset( $_POST['parent'] ) ? absint( $_POST['parent'] ) : 0;
	$default_image = ( $is_source && isset( $_POST['default_image'] ) ) ? esc_url_raw( wp_unslash( $_POST['default_image'] ) ) : '';

	if ( '' === trim( $name ) ) {
		$uid = get_current_user_id();
		set_transient( 'teatatu_news_admin_errors_' . $uid, array( __( 'Name is required.', 'teatatu-news' ) ), 45 );
		set_transient( 'teatatu_news_admin_formdata_' . $uid, wp_unslash( $_POST ), 45 );
		$redirect = array( 'page' => 'teatatu-news', 'tab' => $tab_slug );
		if ( $term_id ) {
			$redirect['edit'] = $term_id;
		}
		wp_safe_redirect( add_query_arg( $redirect, admin_url( 'admin.php' ) ) );
		exit;
	}

	$args = array(
		'description' => $description,
		'parent'      => $parent,
	);

	if ( $term_id ) {
		$args['name'] = $name;
		$result       = wp_update_term( $term_id, $taxonomy, $args );
	} else {
		$result = wp_insert_term( $name, $taxonomy, $args );
	}

	if ( is_wp_error( $result ) ) {
		set_transient( 'teatatu_news_admin_errors_' . get_current_user_id(), array( teatatu_news_format_wp_error( $result ) ), 45 );
		wp_safe_redirect( add_query_arg( array( 'page' => 'teatatu-news', 'tab' => $tab_slug ), admin_url( 'admin.php' ) ) );
		exit;
	}

	$term_id = $result['term_id'];
	if ( $is_source ) {
		update_term_meta( $term_id, 'teatatu_news_source_default_image', $default_image );
	}

	set_transient( 'teatatu_news_admin_notice_' . get_current_user_id(), __( 'Saved.', 'teatatu-news' ), 45 );
	wp_safe_redirect( add_query_arg( array( 'page' => 'teatatu-news', 'tab' => $tab_slug ), admin_url( 'admin.php' ) ) );
	exit;
}

add_action( 'admin_post_teatatu_news_delete_term', 'teatatu_news_handle_delete_term' );

/**
 * Handles Source/Category deletion from the maintenance list table.
 */
function teatatu_news_handle_delete_term() {
	$term_id  = isset( $_GET['term_id'] ) ? absint( $_GET['term_id'] ) : 0;
	$taxonomy = isset( $_GET['taxonomy'] ) ? sanitize_key( wp_unslash( $_GET['taxonomy'] ) ) : '';
	check_admin_referer( 'teatatu_news_delete_term_' . $term_id );

	if ( ! in_array( $taxonomy, array( 'teatatu_news_source', 'teatatu_news_category' ), true ) ) {
		wp_die( esc_html__( 'Invalid taxonomy.', 'teatatu-news' ) );
	}
	$tab_slug = 'teatatu_news_source' === $taxonomy ? 'sources' : 'categories';
	$cap      = 'teatatu_news_source' === $taxonomy ? 'manage_teatatu_news_sources' : 'manage_teatatu_news_categories';
	if ( ! $term_id || ! current_user_can( $cap ) ) {
		wp_die( esc_html__( 'You do not have permission to do that.', 'teatatu-news' ), 403 );
	}

	wp_delete_term( $term_id, $taxonomy );
	set_transient( 'teatatu_news_admin_notice_' . get_current_user_id(), __( 'Deleted.', 'teatatu-news' ), 45 );
	wp_safe_redirect( add_query_arg( array( 'page' => 'teatatu-news', 'tab' => $tab_slug ), admin_url( 'admin.php' ) ) );
	exit;
}

// -----------------------------------------------------------------------
// Shortcodes tab (reference only — no data mutation, no extra capability)
// -----------------------------------------------------------------------

/**
 * Renders the "Shortcodes" reference tab: a quick, in-admin explanation of
 * every shortcode and attribute so editors don't need to consult readme.txt.
 */
function teatatu_news_render_shortcodes_tab() {
	?>
	<h2><?php esc_html_e( 'Shortcodes', 'teatatu-news' ); ?></h2>
	<p><?php esc_html_e( 'Use these shortcodes in any post, page, or widget to display News. Every shortcode accepts the same set of optional attributes, so you can adjust each placement to fit.', 'teatatu-news' ); ?></p>

	<h3><?php esc_html_e( 'Per-Site Shortcodes', 'teatatu-news' ); ?></h3>
	<table class="wp-list-table widefat fixed striped">
		<thead>
			<tr>
				<th style="width:240px;"><?php esc_html_e( 'Shortcode', 'teatatu-news' ); ?></th>
				<th><?php esc_html_e( 'What it shows', 'teatatu-news' ); ?></th>
			</tr>
		</thead>
		<tbody>
			<tr>
				<td><code>[teatatu_news_list]</code></td>
				<td><?php esc_html_e( 'A simple vertical list of News items from this site.', 'teatatu-news' ); ?></td>
			</tr>
			<tr>
				<td><code>[teatatu_news_grid]</code></td>
				<td><?php esc_html_e( 'A card grid of News items from this site, with images. Use the columns attribute to set cards per row.', 'teatatu-news' ); ?></td>
			</tr>
			<tr>
				<td><code>[teatatu_news_latest]</code></td>
				<td><?php esc_html_e( 'The single most recent News item from this site, shown headline-style.', 'teatatu-news' ); ?></td>
			</tr>
		</tbody>
	</table>

	<?php if ( is_multisite() ) : ?>
		<h3><?php esc_html_e( 'Network-Wide Shortcodes (Master Site only)', 'teatatu-news' ); ?></h3>
		<p class="description"><?php esc_html_e( 'These aggregate News from every site in the network, but only produce output on the site currently configured as the Master Site (Network Admin > Settings > Teatatu News). Elsewhere, admins see a reminder notice and everyone else sees nothing.', 'teatatu-news' ); ?></p>
		<table class="wp-list-table widefat fixed striped">
			<thead>
				<tr>
					<th style="width:240px;"><?php esc_html_e( 'Shortcode', 'teatatu-news' ); ?></th>
					<th><?php esc_html_e( 'What it shows', 'teatatu-news' ); ?></th>
				</tr>
			</thead>
			<tbody>
				<tr>
					<td><code>[teatatu_news_network_list]</code></td>
					<td><?php esc_html_e( 'The list view, aggregated across every site in the network.', 'teatatu-news' ); ?></td>
				</tr>
				<tr>
					<td><code>[teatatu_news_network_grid]</code></td>
					<td><?php esc_html_e( 'The grid view, aggregated across every site in the network.', 'teatatu-news' ); ?></td>
				</tr>
				<tr>
					<td><code>[teatatu_news_network_latest]</code></td>
					<td><?php esc_html_e( 'The single most recent item across the whole network.', 'teatatu-news' ); ?></td>
				</tr>
			</tbody>
		</table>
	<?php endif; ?>

	<h3><?php esc_html_e( 'Attributes (all optional, work on every shortcode above)', 'teatatu-news' ); ?></h3>
	<table class="wp-list-table widefat fixed striped">
		<thead>
			<tr>
				<th style="width:160px;"><?php esc_html_e( 'Attribute', 'teatatu-news' ); ?></th>
				<th><?php esc_html_e( 'What it controls', 'teatatu-news' ); ?></th>
				<th style="width:130px;"><?php esc_html_e( 'Default', 'teatatu-news' ); ?></th>
			</tr>
		</thead>
		<tbody>
			<tr><td><code>count</code></td><td><?php esc_html_e( 'How many News items to show.', 'teatatu-news' ); ?></td><td>5</td></tr>
			<tr><td><code>columns</code></td><td><?php esc_html_e( 'Grid shortcodes only: cards per row, from 1 to 6.', 'teatatu-news' ); ?></td><td>3</td></tr>
			<tr><td><code>source</code></td><td><?php esc_html_e( 'Only show items from one Source, by its slug (see the list below).', 'teatatu-news' ); ?></td><td><?php esc_html_e( 'all Sources', 'teatatu-news' ); ?></td></tr>
			<tr><td><code>category</code></td><td><?php esc_html_e( 'Only show items from one Category, by its slug (see the list below).', 'teatatu-news' ); ?></td><td><?php esc_html_e( 'all Categories', 'teatatu-news' ); ?></td></tr>
			<tr><td><code>orderby</code></td><td><?php esc_html_e( 'Sort by date, title, modified, or rand (random order).', 'teatatu-news' ); ?></td><td>date</td></tr>
			<tr><td><code>order</code></td><td><?php esc_html_e( 'Sort direction: ASC (oldest/A-Z first) or DESC.', 'teatatu-news' ); ?></td><td>DESC</td></tr>
			<tr><td><code>display</code></td><td><?php esc_html_e( 'Set to summary for a minimal card — title, excerpt, image, and Read More link only. Source, Category, and Date are hidden no matter what the show_* attributes below say.', 'teatatu-news' ); ?></td><td>full</td></tr>
			<tr><td><code>show_image</code></td><td><?php esc_html_e( 'Show the image (true or false).', 'teatatu-news' ); ?></td><td>true</td></tr>
			<tr><td><code>show_excerpt</code></td><td><?php esc_html_e( 'Show the excerpt (true or false).', 'teatatu-news' ); ?></td><td>true</td></tr>
			<tr><td><code>show_source</code></td><td><?php esc_html_e( 'Show the Source name (true or false).', 'teatatu-news' ); ?></td><td>true</td></tr>
			<tr><td><code>show_category</code></td><td><?php esc_html_e( 'Show the Category name (true or false).', 'teatatu-news' ); ?></td><td>true</td></tr>
			<tr><td><code>show_date</code></td><td><?php esc_html_e( 'Show the publish date (true or false).', 'teatatu-news' ); ?></td><td>true</td></tr>
			<tr><td><code>link_target</code></td><td><?php esc_html_e( 'Open links in the same tab (_self) or a new tab (_blank).', 'teatatu-news' ); ?></td><td>_self</td></tr>
		</tbody>
	</table>

	<?php if ( is_multisite() ) : ?>
		<h3><?php esc_html_e( 'Extra attributes for network-wide shortcodes only', 'teatatu-news' ); ?></h3>
		<table class="wp-list-table widefat fixed striped">
			<thead>
				<tr>
					<th style="width:160px;"><?php esc_html_e( 'Attribute', 'teatatu-news' ); ?></th>
					<th><?php esc_html_e( 'What it controls', 'teatatu-news' ); ?></th>
					<th style="width:130px;"><?php esc_html_e( 'Default', 'teatatu-news' ); ?></th>
				</tr>
			</thead>
			<tbody>
				<tr><td><code>exclude_sites</code></td><td><?php esc_html_e( 'Comma-separated site IDs to leave out of the aggregate.', 'teatatu-news' ); ?></td><td><?php esc_html_e( 'none', 'teatatu-news' ); ?></td></tr>
				<tr><td><code>site_label</code></td><td><?php esc_html_e( 'Show which site each item came from (true or false).', 'teatatu-news' ); ?></td><td>false</td></tr>
			</tbody>
		</table>
	<?php endif; ?>

	<h3><?php esc_html_e( 'Sources and Categories available on this site', 'teatatu-news' ); ?></h3>
	<p class="description"><?php esc_html_e( 'Use these slugs with the source and category attributes above.', 'teatatu-news' ); ?></p>
	<table class="wp-list-table widefat fixed striped">
		<tbody>
			<tr>
				<th style="width:160px;"><?php esc_html_e( 'Sources', 'teatatu-news' ); ?></th>
				<td><?php echo wp_kses_post( teatatu_news_render_term_slug_list( 'teatatu_news_source' ) ); ?></td>
			</tr>
			<tr>
				<th><?php esc_html_e( 'Categories', 'teatatu-news' ); ?></th>
				<td><?php echo wp_kses_post( teatatu_news_render_term_slug_list( 'teatatu_news_category' ) ); ?></td>
			</tr>
		</tbody>
	</table>

	<h3><?php esc_html_e( 'Example', 'teatatu-news' ); ?></h3>
	<p><code>[teatatu_news_grid count="6" columns="3" display="summary"]</code></p>
	<p class="description"><?php esc_html_e( 'A 3-column grid of the 6 most recent News items, showing only the title, excerpt, image, and Read More link.', 'teatatu-news' ); ?></p>
	<?php
}

/**
 * Comma-separated, code-formatted list of a taxonomy's term slugs, for the
 * Shortcodes reference tab.
 *
 * @param string $taxonomy Taxonomy slug.
 * @return string
 */
function teatatu_news_render_term_slug_list( $taxonomy ) {
	$terms = get_terms( array( 'taxonomy' => $taxonomy, 'hide_empty' => false ) );
	if ( empty( $terms ) || is_wp_error( $terms ) ) {
		return esc_html__( 'None yet.', 'teatatu-news' );
	}
	$slugs = wp_list_pluck( $terms, 'slug' );
	return implode( ', ', array_map( 'teatatu_news_wrap_code', $slugs ) );
}

/**
 * Wraps a single string in an escaped <code> tag.
 *
 * @param string $value Value to wrap.
 * @return string
 */
function teatatu_news_wrap_code( $value ) {
	return '<code>' . esc_html( $value ) . '</code>';
}
