<?php
/**
 * Registers the News post meta fields (REST-exposed) and the "News Details"
 * meta box used on the post edit screen.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

add_action( 'init', 'teatatu_news_register_post_meta' );

/**
 * Registers the two News post meta fields with REST support.
 */
function teatatu_news_register_post_meta() {
	register_post_meta(
		'teatatu_news',
		'_teatatu_news_read_more_url',
		array(
			'type'              => 'string',
			'single'            => true,
			'sanitize_callback' => 'esc_url_raw',
			'show_in_rest'      => true,
			'auth_callback'     => function ( $allowed, $meta_key, $post_id ) {
				return current_user_can( 'edit_post', $post_id );
			},
		)
	);

	register_post_meta(
		'teatatu_news',
		'_teatatu_news_image_url',
		array(
			'type'              => 'string',
			'single'            => true,
			'sanitize_callback' => 'esc_url_raw',
			'show_in_rest'      => true,
			'auth_callback'     => function ( $allowed, $meta_key, $post_id ) {
				return current_user_can( 'edit_post', $post_id );
			},
		)
	);
}

add_action( 'add_meta_boxes', 'teatatu_news_add_meta_box' );

/**
 * Registers the "News Details" meta box on the News edit screen.
 */
function teatatu_news_add_meta_box() {
	add_meta_box(
		'teatatu_news_details',
		__( 'News Details', 'teatatu-news' ),
		'teatatu_news_render_meta_box',
		'teatatu_news',
		'normal',
		'high'
	);
}

/**
 * Renders the "News Details" meta box fields.
 *
 * @param WP_Post $post Current post.
 */
function teatatu_news_render_meta_box( $post ) {
	wp_nonce_field( 'teatatu_news_save_meta_box', 'teatatu_news_meta_box_nonce' );

	$read_more_url = get_post_meta( $post->ID, '_teatatu_news_read_more_url', true );
	$image_url     = get_post_meta( $post->ID, '_teatatu_news_image_url', true );
	$preview_url   = teatatu_news_get_image_url( $post->ID );
	?>
	<p>
		<label for="teatatu_news_read_more_url"><strong><?php esc_html_e( 'Read More URL', 'teatatu-news' ); ?></strong> <span style="color:#d63638;">*</span></label><br />
		<input type="url" id="teatatu_news_read_more_url" name="teatatu_news_read_more_url" class="large-text" value="<?php echo esc_attr( $read_more_url ); ?>" placeholder="https://example.com/original-article" />
	</p>
	<p>
		<label for="teatatu_news_image_url"><strong><?php esc_html_e( 'Post Image URL', 'teatatu-news' ); ?></strong></label><br />
		<input type="url" id="teatatu_news_image_url" name="teatatu_news_image_url" class="large-text" value="<?php echo esc_attr( $image_url ); ?>" placeholder="<?php esc_attr_e( 'Leave blank to use the Source\'s default image', 'teatatu-news' ); ?>" />
	</p>
	<p>
		<img id="teatatu_news_image_preview" src="<?php echo esc_url( $preview_url ); ?>" alt="" style="max-width:200px;height:auto;<?php echo $preview_url ? '' : 'display:none;'; ?>" />
	</p>
	<script>
	( function () {
		var input = document.getElementById( 'teatatu_news_image_url' );
		var preview = document.getElementById( 'teatatu_news_image_preview' );
		if ( ! input || ! preview ) {
			return;
		}
		input.addEventListener( 'input', function () {
			var val = input.value.trim();
			if ( val ) {
				preview.src = val;
				preview.style.display = '';
			} else {
				preview.style.display = 'none';
			}
		} );
	} )();
	</script>
	<?php
}

add_action( 'save_post_teatatu_news', 'teatatu_news_save_meta_box', 10, 2 );

/**
 * Saves the "News Details" meta box fields.
 *
 * @param int     $post_id Post ID.
 * @param WP_Post $post    Post object.
 */
function teatatu_news_save_meta_box( $post_id, $post ) {
	if ( ! isset( $_POST['teatatu_news_meta_box_nonce'] ) ||
		! wp_verify_nonce( wp_unslash( $_POST['teatatu_news_meta_box_nonce'] ), 'teatatu_news_save_meta_box' ) ) {
		return;
	}
	if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
		return;
	}
	if ( ! current_user_can( 'edit_post', $post_id ) ) {
		return;
	}

	if ( isset( $_POST['teatatu_news_read_more_url'] ) ) {
		update_post_meta( $post_id, '_teatatu_news_read_more_url', esc_url_raw( wp_unslash( $_POST['teatatu_news_read_more_url'] ) ) );
	}
	if ( isset( $_POST['teatatu_news_image_url'] ) ) {
		update_post_meta( $post_id, '_teatatu_news_image_url', esc_url_raw( wp_unslash( $_POST['teatatu_news_image_url'] ) ) );
	}

	if ( 'auto-draft' === get_post_status( $post_id ) ) {
		return;
	}

	$read_more_url = get_post_meta( $post_id, '_teatatu_news_read_more_url', true );
	$missing       = array();
	if ( empty( trim( $post->post_title ) ) ) {
		$missing[] = __( 'Title', 'teatatu-news' );
	}
	if ( empty( $read_more_url ) ) {
		$missing[] = __( 'Read More URL', 'teatatu-news' );
	}
	if ( ! empty( $missing ) ) {
		/* translators: %s: comma-separated list of missing required fields. */
		$message = sprintf( __( 'This News Item is missing required field(s): %s.', 'teatatu-news' ), implode( ', ', $missing ) );
		set_transient( 'teatatu_news_notice_' . get_current_user_id(), $message, 45 );
	}
}

add_action( 'admin_notices', 'teatatu_news_show_meta_box_notice' );

/**
 * Displays the missing-Read-More-URL warning stashed by the meta box saver.
 */
function teatatu_news_show_meta_box_notice() {
	$key    = 'teatatu_news_notice_' . get_current_user_id();
	$notice = get_transient( $key );
	if ( ! $notice ) {
		return;
	}
	delete_transient( $key );
	printf( '<div class="notice notice-warning is-dismissible"><p>%s</p></div>', esc_html( $notice ) );
}
