<?php
/**
 * Registers the `teatatu_news_rss_feed` post type — one post per configured
 * RSS import source — and the "RSS Feeds" tab on the maintenance page where
 * an editor manages the list, cadence, and per-field content mapping.
 *
 * This post type has no native wp-admin UI; it's only ever read/written by
 * this plugin's own capability-gated admin-post.php handlers, never exposed
 * over REST (RSS import is a site-configuration concern, not something the
 * `teatatu_news_agent` role should be able to touch).
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

add_action( 'init', 'teatatu_news_register_rss_feed_post_type' );

/**
 * Registers the RSS Feed configuration post type.
 */
function teatatu_news_register_rss_feed_post_type() {
	register_post_type(
		'teatatu_news_rssfeed',
		array(
			'labels'       => array(
				'name'          => __( 'RSS Feeds', 'teatatu-news' ),
				'singular_name' => __( 'RSS Feed', 'teatatu-news' ),
			),
			'public'       => false,
			'show_ui'      => false,
			'show_in_rest' => false,
			'supports'     => array( 'title' ),
			'rewrite'      => false,
			'query_var'    => false,
		)
	);
}

/**
 * Default (empty) per-field content mapping.
 *
 * @return array
 */
function teatatu_news_rss_default_field_map() {
	$blank = array(
		'source'     => 'rss_title',
		'tag'        => '',
		'attr_type'  => 'class',
		'attr_value' => '',
	);
	return array(
		'title'   => array_merge( $blank, array( 'source' => 'rss_title' ) ),
		'excerpt' => array_merge( $blank, array( 'source' => 'rss_description' ) ),
		'image'   => array_merge( $blank, array( 'source' => 'rss_image' ) ),
	);
}

/**
 * The selectable content sources for a given News field.
 *
 * @param string $field 'title', 'excerpt', or 'image'.
 * @return array<string,string> value => label
 */
function teatatu_news_rss_field_source_options( $field ) {
	$options = array(
		'rss_title'       => __( 'RSS: Title', 'teatatu-news' ),
		'rss_description' => __( 'RSS: Description', 'teatatu-news' ),
		'rss_content'     => __( 'RSS: Full Content (content:encoded)', 'teatatu-news' ),
		'page'            => __( 'Article Page (fetch the linked URL and scrape it)', 'teatatu-news' ),
	);
	if ( 'image' === $field ) {
		$options = array( 'rss_image' => __( 'RSS: Enclosure / Media Image', 'teatatu-news' ) ) + $options;
	}
	return $options;
}

/**
 * Reads and normalizes a feed's stored field-mapping config.
 *
 * @param int $feed_id RSS Feed post ID.
 * @return array
 */
function teatatu_news_rss_get_field_map( $feed_id ) {
	$stored  = get_post_meta( $feed_id, '_teatatu_news_rss_field_map', true );
	$default = teatatu_news_rss_default_field_map();
	if ( ! is_array( $stored ) ) {
		return $default;
	}
	foreach ( $default as $field => $blank ) {
		if ( isset( $stored[ $field ] ) && is_array( $stored[ $field ] ) ) {
			$default[ $field ] = array_merge( $blank, $stored[ $field ] );
		}
	}
	return $default;
}

// -----------------------------------------------------------------------
// Admin UI: "RSS Feeds" tab on the maintenance page
// -----------------------------------------------------------------------

/**
 * Renders the RSS Feeds list + Add/Edit form.
 */
function teatatu_news_render_rss_feeds_tab() {
	if ( ! current_user_can( 'manage_teatatu_news_rss_feeds' ) ) {
		echo '<p>' . esc_html__( 'You do not have permission to manage RSS feeds.', 'teatatu-news' ) . '</p>';
		return;
	}

	$editing_id = isset( $_GET['edit'] ) ? absint( $_GET['edit'] ) : 0;
	$editing    = ( $editing_id && 'teatatu_news_rssfeed' === get_post_type( $editing_id ) ) ? get_post( $editing_id ) : null;
	$stashed    = teatatu_news_get_stashed_formdata();
	$field_map  = $editing ? teatatu_news_rss_get_field_map( $editing_id ) : teatatu_news_rss_default_field_map();
	?>
	<h2><?php echo $editing ? esc_html__( 'Edit RSS Feed', 'teatatu-news' ) : esc_html__( 'Add RSS Feed', 'teatatu-news' ); ?></h2>
	<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
		<?php wp_nonce_field( 'teatatu_news_save_rss_feed', 'teatatu_news_rss_feed_nonce' ); ?>
		<input type="hidden" name="action" value="teatatu_news_save_rss_feed" />
		<input type="hidden" name="feed_id" value="<?php echo esc_attr( $editing_id ); ?>" />
		<table class="form-table">
			<tr>
				<th><label for="tn_rss_label"><?php esc_html_e( 'Label', 'teatatu-news' ); ?></label></th>
				<td><input type="text" id="tn_rss_label" name="label" class="regular-text" value="<?php echo esc_attr( $stashed['label'] ?? ( $editing ? $editing->post_title : '' ) ); ?>" placeholder="<?php esc_attr_e( 'e.g. Regional Council Newsroom', 'teatatu-news' ); ?>" /></td>
			</tr>
			<tr>
				<th><label for="tn_rss_url"><?php esc_html_e( 'Feed URL', 'teatatu-news' ); ?> *</label></th>
				<td><input type="url" id="tn_rss_url" name="feed_url" class="large-text" value="<?php echo esc_attr( $stashed['feed_url'] ?? ( $editing ? get_post_meta( $editing_id, '_teatatu_news_rss_url', true ) : '' ) ); ?>" placeholder="https://example.com/feed/" required /></td>
			</tr>
			<tr>
				<th><label for="tn_rss_cadence"><?php esc_html_e( 'Check Every (minutes)', 'teatatu-news' ); ?></label></th>
				<td>
					<input type="number" id="tn_rss_cadence" name="cadence" class="small-text" min="5" step="1" value="<?php echo esc_attr( $stashed['cadence'] ?? ( $editing ? (int) get_post_meta( $editing_id, '_teatatu_news_rss_cadence', true ) : 60 ) ); ?>" />
					<p class="description"><?php esc_html_e( 'Minimum 5 minutes. WP-Cron only fires on site traffic, so actual timing is best-effort.', 'teatatu-news' ); ?></p>
				</td>
			</tr>
			<tr>
				<th><?php esc_html_e( 'Active', 'teatatu-news' ); ?></th>
				<td>
					<?php $active = isset( $stashed['active'] ) ? (bool) $stashed['active'] : ( $editing ? (bool) get_post_meta( $editing_id, '_teatatu_news_rss_active', true ) : true ); ?>
					<label><input type="checkbox" name="active" value="1" <?php checked( $active ); ?> /> <?php esc_html_e( 'Check this feed on its cadence', 'teatatu-news' ); ?></label>
					<p class="description"><?php esc_html_e( 'Uncheck to pause this feed without deleting its configuration.', 'teatatu-news' ); ?></p>
				</td>
			</tr>
			<?php if ( current_user_can( 'publish_teatatu_news_items' ) || current_user_can( 'edit_published_teatatu_news_items' ) ) : ?>
				<tr>
					<th><?php esc_html_e( 'Publishing Behavior', 'teatatu-news' ); ?></th>
					<td>
						<?php if ( current_user_can( 'publish_teatatu_news_items' ) ) : ?>
							<?php $auto_publish = isset( $stashed['auto_publish'] ) ? (bool) $stashed['auto_publish'] : ( $editing ? (bool) get_post_meta( $editing_id, '_teatatu_news_rss_auto_publish', true ) : false ); ?>
							<label><input type="checkbox" name="auto_publish" value="1" <?php checked( $auto_publish ); ?> /> <?php esc_html_e( 'Auto-publish new items', 'teatatu-news' ); ?></label>
							<p class="description"><?php esc_html_e( 'Off by default: new items are saved as Draft for review, same as everywhere else in this plugin. Turning this on publishes items from this feed immediately, with no human review — only enable it for a feed you fully trust.', 'teatatu-news' ); ?></p>
						<?php endif; ?>
						<?php if ( current_user_can( 'edit_published_teatatu_news_items' ) ) : ?>
							<?php $auto_apply = isset( $stashed['auto_apply_updates'] ) ? (bool) $stashed['auto_apply_updates'] : ( $editing ? (bool) get_post_meta( $editing_id, '_teatatu_news_rss_auto_apply_updates', true ) : false ); ?>
							<p style="margin-top:12px;">
								<label><input type="checkbox" name="auto_apply_updates" value="1" <?php checked( $auto_apply ); ?> /> <?php esc_html_e( 'Auto-apply updates to published items', 'teatatu-news' ); ?></label>
							</p>
							<p class="description"><?php esc_html_e( 'Off by default: if this feed later shows changed content for an item that has already been published, the change is held as a pending update for a human to review and apply. Turning this on applies changes to published items immediately instead.', 'teatatu-news' ); ?></p>
						<?php endif; ?>
					</td>
				</tr>
			<?php endif; ?>
			<tr>
				<th><label for="tn_rss_default_source"><?php esc_html_e( 'Default Source', 'teatatu-news' ); ?></label></th>
				<td>
					<?php
					wp_dropdown_categories(
						array(
							'taxonomy'          => 'teatatu_news_source',
							'name'              => 'default_source',
							'id'                => 'tn_rss_default_source',
							'hide_empty'        => false,
							'show_option_none'  => __( '— None —', 'teatatu-news' ),
							'option_none_value' => 0,
							'selected'          => isset( $stashed['default_source'] ) ? (int) $stashed['default_source'] : ( $editing ? (int) get_post_meta( $editing_id, '_teatatu_news_rss_default_source', true ) : 0 ),
						)
					);
					?>
					<p class="description"><?php esc_html_e( 'Assigned to every News item imported from this feed.', 'teatatu-news' ); ?></p>
				</td>
			</tr>
			<tr>
				<th><label for="tn_rss_default_category"><?php esc_html_e( 'Default Category', 'teatatu-news' ); ?></label></th>
				<td>
					<?php
					wp_dropdown_categories(
						array(
							'taxonomy'          => 'teatatu_news_category',
							'name'              => 'default_category',
							'id'                => 'tn_rss_default_category',
							'hide_empty'        => false,
							'show_option_none'  => __( '— None —', 'teatatu-news' ),
							'option_none_value' => 0,
							'selected'          => isset( $stashed['default_category'] ) ? (int) $stashed['default_category'] : ( $editing ? (int) get_post_meta( $editing_id, '_teatatu_news_rss_default_category', true ) : 0 ),
						)
					);
					?>
				</td>
			</tr>
		</table>

		<h3><?php esc_html_e( 'Content Mapping', 'teatatu-news' ); ?></h3>
		<p class="description"><?php esc_html_e( 'For each News field, choose where its content comes from. Optionally narrow to one HTML element by tag and class/id — useful when a source field contains more than you want. All formatting except links is stripped from mapped Title/Excerpt content. "Article Page" requires a tag and/or class/id — without one, that field is left empty rather than filling it with the entire page.', 'teatatu-news' ); ?></p>
		<table class="wp-list-table widefat fixed striped">
			<thead>
				<tr>
					<th><?php esc_html_e( 'News Field', 'teatatu-news' ); ?></th>
					<th><?php esc_html_e( 'Source', 'teatatu-news' ); ?></th>
					<th><?php esc_html_e( 'Element (optional)', 'teatatu-news' ); ?></th>
					<th><?php esc_html_e( 'Class or ID (optional)', 'teatatu-news' ); ?></th>
				</tr>
			</thead>
			<tbody>
				<?php
				$field_labels = array(
					'title'   => __( 'Title', 'teatatu-news' ),
					'excerpt' => __( 'Excerpt', 'teatatu-news' ),
					'image'   => __( 'Image URL', 'teatatu-news' ),
				);
				foreach ( $field_labels as $field_key => $field_label ) :
					$fm = isset( $stashed[ 'map_' . $field_key . '_source' ] )
						? array(
							'source'     => $stashed[ 'map_' . $field_key . '_source' ],
							'tag'        => $stashed[ 'map_' . $field_key . '_tag' ] ?? '',
							'attr_type'  => $stashed[ 'map_' . $field_key . '_attr_type' ] ?? 'class',
							'attr_value' => $stashed[ 'map_' . $field_key . '_attr_value' ] ?? '',
						)
						: $field_map[ $field_key ];
					?>
					<tr>
						<td><strong><?php echo esc_html( $field_label ); ?></strong></td>
						<td>
							<select name="map_<?php echo esc_attr( $field_key ); ?>_source">
								<?php foreach ( teatatu_news_rss_field_source_options( $field_key ) as $value => $label ) : ?>
									<option value="<?php echo esc_attr( $value ); ?>" <?php selected( $fm['source'], $value ); ?>><?php echo esc_html( $label ); ?></option>
								<?php endforeach; ?>
							</select>
						</td>
						<td><input type="text" name="map_<?php echo esc_attr( $field_key ); ?>_tag" value="<?php echo esc_attr( $fm['tag'] ); ?>" placeholder="div" class="small-text" /></td>
						<td>
							<select name="map_<?php echo esc_attr( $field_key ); ?>_attr_type" class="small-text">
								<option value="class" <?php selected( $fm['attr_type'], 'class' ); ?>><?php esc_html_e( 'Class', 'teatatu-news' ); ?></option>
								<option value="id" <?php selected( $fm['attr_type'], 'id' ); ?>><?php esc_html_e( 'ID', 'teatatu-news' ); ?></option>
							</select>
							<input type="text" name="map_<?php echo esc_attr( $field_key ); ?>_attr_value" value="<?php echo esc_attr( $fm['attr_value'] ); ?>" placeholder="<?php esc_attr_e( 'e.g. article-body', 'teatatu-news' ); ?>" class="regular-text" />
						</td>
					</tr>
				<?php endforeach; ?>
			</tbody>
		</table>

		<?php submit_button( $editing ? __( 'Update RSS Feed', 'teatatu-news' ) : __( 'Add RSS Feed', 'teatatu-news' ) ); ?>
	</form>

	<hr />
	<h2><?php esc_html_e( 'All RSS Feeds', 'teatatu-news' ); ?></h2>
	<?php
	$feeds = get_posts(
		array(
			'post_type'      => 'teatatu_news_rssfeed',
			'post_status'    => 'publish',
			'posts_per_page' => -1,
			'orderby'        => 'title',
			'order'          => 'ASC',
		)
	);
	?>
	<table class="wp-list-table widefat fixed striped">
		<thead>
			<tr>
				<th><?php esc_html_e( 'Label', 'teatatu-news' ); ?></th>
				<th><?php esc_html_e( 'Feed URL', 'teatatu-news' ); ?></th>
				<th><?php esc_html_e( 'Cadence', 'teatatu-news' ); ?></th>
				<th><?php esc_html_e( 'Status', 'teatatu-news' ); ?></th>
				<th><?php esc_html_e( 'Last Checked', 'teatatu-news' ); ?></th>
				<th><?php esc_html_e( 'Actions', 'teatatu-news' ); ?></th>
			</tr>
		</thead>
		<tbody>
		<?php if ( ! empty( $feeds ) ) : ?>
			<?php foreach ( $feeds as $feed ) : ?>
				<?php
				$active       = (bool) get_post_meta( $feed->ID, '_teatatu_news_rss_active', true );
				$cadence      = (int) get_post_meta( $feed->ID, '_teatatu_news_rss_cadence', true );
				$last_checked = (int) get_post_meta( $feed->ID, '_teatatu_news_rss_last_checked', true );
				$last_error   = get_post_meta( $feed->ID, '_teatatu_news_rss_last_error', true );
				$auto_publish = (bool) get_post_meta( $feed->ID, '_teatatu_news_rss_auto_publish', true );
				$auto_apply   = (bool) get_post_meta( $feed->ID, '_teatatu_news_rss_auto_apply_updates', true );
				$edit_url     = add_query_arg( array( 'page' => 'teatatu-news', 'tab' => 'rss', 'edit' => $feed->ID ), admin_url( 'admin.php' ) );
				$toggle_url   = wp_nonce_url( add_query_arg( array( 'action' => 'teatatu_news_toggle_rss_feed', 'feed_id' => $feed->ID ), admin_url( 'admin-post.php' ) ), 'teatatu_news_toggle_rss_feed_' . $feed->ID );
				$check_url    = wp_nonce_url( add_query_arg( array( 'action' => 'teatatu_news_check_rss_feed_now', 'feed_id' => $feed->ID ), admin_url( 'admin-post.php' ) ), 'teatatu_news_check_rss_feed_now_' . $feed->ID );
				$delete_url   = wp_nonce_url( add_query_arg( array( 'action' => 'teatatu_news_delete_rss_feed', 'feed_id' => $feed->ID ), admin_url( 'admin-post.php' ) ), 'teatatu_news_delete_rss_feed_' . $feed->ID );
				?>
				<tr>
					<td><?php echo esc_html( $feed->post_title ? $feed->post_title : $feed->ID ); ?></td>
					<td><?php echo esc_html( get_post_meta( $feed->ID, '_teatatu_news_rss_url', true ) ); ?></td>
					<td>
						<?php
						/* translators: %d: number of minutes. */
						printf( esc_html__( 'Every %d min', 'teatatu-news' ), (int) $cadence );
						?>
					</td>
					<td>
						<?php echo $active ? esc_html__( 'Active', 'teatatu-news' ) : esc_html__( 'Paused', 'teatatu-news' ); ?>
						<?php if ( $auto_publish ) : ?>
							<br /><span style="color:#d63638;"><?php esc_html_e( 'Auto-publish on', 'teatatu-news' ); ?></span>
						<?php endif; ?>
						<?php if ( $auto_apply ) : ?>
							<br /><span style="color:#d63638;"><?php esc_html_e( 'Auto-apply updates on', 'teatatu-news' ); ?></span>
						<?php endif; ?>
						<?php if ( $last_error ) : ?>
							<br /><span style="color:#d63638;"><?php echo esc_html( $last_error ); ?></span>
						<?php endif; ?>
					</td>
					<td><?php echo $last_checked ? esc_html( human_time_diff( $last_checked ) . ' ' . __( 'ago', 'teatatu-news' ) ) : esc_html__( 'Never', 'teatatu-news' ); ?></td>
					<td>
						<a href="<?php echo esc_url( $edit_url ); ?>"><?php esc_html_e( 'Edit', 'teatatu-news' ); ?></a>
						 | <a href="<?php echo esc_url( $check_url ); ?>"><?php esc_html_e( 'Check Now', 'teatatu-news' ); ?></a>
						 | <a href="<?php echo esc_url( $toggle_url ); ?>"><?php echo $active ? esc_html__( 'Pause', 'teatatu-news' ) : esc_html__( 'Activate', 'teatatu-news' ); ?></a>
						 | <a href="<?php echo esc_url( $delete_url ); ?>" onclick="return confirm('<?php echo esc_js( __( 'Delete this RSS feed configuration? Previously imported News items are not affected.', 'teatatu-news' ) ); ?>');"><?php esc_html_e( 'Delete', 'teatatu-news' ); ?></a>
					</td>
				</tr>
			<?php endforeach; ?>
		<?php else : ?>
			<tr><td colspan="6"><?php esc_html_e( 'No RSS feeds configured yet.', 'teatatu-news' ); ?></td></tr>
		<?php endif; ?>
		</tbody>
	</table>
	<?php
}

add_action( 'admin_post_teatatu_news_save_rss_feed', 'teatatu_news_handle_save_rss_feed' );

/**
 * Handles the RSS Feed Add/Edit form submission.
 */
function teatatu_news_handle_save_rss_feed() {
	if ( ! isset( $_POST['teatatu_news_rss_feed_nonce'] ) || ! wp_verify_nonce( wp_unslash( $_POST['teatatu_news_rss_feed_nonce'] ), 'teatatu_news_save_rss_feed' ) ) {
		wp_die( esc_html__( 'Security check failed.', 'teatatu-news' ) );
	}
	if ( ! current_user_can( 'manage_teatatu_news_rss_feeds' ) ) {
		wp_die( esc_html__( 'You do not have permission to do that.', 'teatatu-news' ), 403 );
	}

	$feed_id          = isset( $_POST['feed_id'] ) ? absint( $_POST['feed_id'] ) : 0;
	$label            = isset( $_POST['label'] ) ? sanitize_text_field( wp_unslash( $_POST['label'] ) ) : '';
	$feed_url         = isset( $_POST['feed_url'] ) ? esc_url_raw( wp_unslash( $_POST['feed_url'] ) ) : '';
	$cadence          = isset( $_POST['cadence'] ) ? max( 5, absint( $_POST['cadence'] ) ) : 60;
	$active           = ! empty( $_POST['active'] );
	$default_source   = isset( $_POST['default_source'] ) ? absint( $_POST['default_source'] ) : 0;
	$default_category = isset( $_POST['default_category'] ) ? absint( $_POST['default_category'] ) : 0;

	if ( $feed_id && 'teatatu_news_rssfeed' !== get_post_type( $feed_id ) ) {
		wp_die( esc_html__( 'That item is not an RSS feed.', 'teatatu-news' ) );
	}

	if ( '' === $feed_url ) {
		$uid = get_current_user_id();
		set_transient( 'teatatu_news_admin_errors_' . $uid, array( __( 'Feed URL is required.', 'teatatu-news' ) ), 45 );
		set_transient( 'teatatu_news_admin_formdata_' . $uid, wp_unslash( $_POST ), 45 );
		$redirect = array( 'page' => 'teatatu-news', 'tab' => 'rss' );
		if ( $feed_id ) {
			$redirect['edit'] = $feed_id;
		}
		wp_safe_redirect( add_query_arg( $redirect, admin_url( 'admin.php' ) ) );
		exit;
	}

	if ( '' === $label ) {
		$label = $feed_url;
	}

	$postarr = array(
		'post_title'  => $label,
		'post_status' => 'publish',
	);

	if ( $feed_id ) {
		$postarr['ID'] = $feed_id;
		$result        = wp_update_post( $postarr, true );
	} else {
		$postarr['post_type'] = 'teatatu_news_rssfeed';
		$result               = wp_insert_post( $postarr, true );
	}

	if ( is_wp_error( $result ) ) {
		set_transient( 'teatatu_news_admin_errors_' . get_current_user_id(), array( teatatu_news_format_wp_error( $result ) ), 45 );
		wp_safe_redirect( add_query_arg( array( 'page' => 'teatatu-news', 'tab' => 'rss' ), admin_url( 'admin.php' ) ) );
		exit;
	}

	$feed_id = $result;

	update_post_meta( $feed_id, '_teatatu_news_rss_url', $feed_url );
	update_post_meta( $feed_id, '_teatatu_news_rss_cadence', $cadence );
	update_post_meta( $feed_id, '_teatatu_news_rss_active', $active ? 1 : 0 );
	update_post_meta( $feed_id, '_teatatu_news_rss_default_source', $default_source );
	update_post_meta( $feed_id, '_teatatu_news_rss_default_category', $default_category );

	// These two toggles are only ever written when the *current* user holds
	// the matching real content capability — not just manage_teatatu_news_rss_feeds
	// — so a role that can configure feeds but not publish/edit-published
	// content can never grant itself that ability indirectly through a feed
	// setting. If the field wasn't submitted (because the user can't see
	// it), the existing stored value is left untouched rather than reset.
	if ( current_user_can( 'publish_teatatu_news_items' ) ) {
		update_post_meta( $feed_id, '_teatatu_news_rss_auto_publish', ! empty( $_POST['auto_publish'] ) ? 1 : 0 );
	}
	if ( current_user_can( 'edit_published_teatatu_news_items' ) ) {
		update_post_meta( $feed_id, '_teatatu_news_rss_auto_apply_updates', ! empty( $_POST['auto_apply_updates'] ) ? 1 : 0 );
	}

	$field_map = teatatu_news_rss_default_field_map();
	foreach ( array_keys( $field_map ) as $field_key ) {
		$allowed_sources = array_keys( teatatu_news_rss_field_source_options( $field_key ) );
		$source          = isset( $_POST[ 'map_' . $field_key . '_source' ] ) ? sanitize_key( wp_unslash( $_POST[ 'map_' . $field_key . '_source' ] ) ) : $field_map[ $field_key ]['source'];
		if ( ! in_array( $source, $allowed_sources, true ) ) {
			$source = $field_map[ $field_key ]['source'];
		}
		$tag        = isset( $_POST[ 'map_' . $field_key . '_tag' ] ) ? preg_replace( '/[^a-z0-9]/', '', strtolower( sanitize_text_field( wp_unslash( $_POST[ 'map_' . $field_key . '_tag' ] ) ) ) ) : '';
		$attr_type  = ( isset( $_POST[ 'map_' . $field_key . '_attr_type' ] ) && 'id' === $_POST[ 'map_' . $field_key . '_attr_type' ] ) ? 'id' : 'class';
		$attr_value = isset( $_POST[ 'map_' . $field_key . '_attr_value' ] ) ? sanitize_html_class( wp_unslash( $_POST[ 'map_' . $field_key . '_attr_value' ] ) ) : '';

		$field_map[ $field_key ] = array(
			'source'     => $source,
			'tag'        => $tag,
			'attr_type'  => $attr_type,
			'attr_value' => $attr_value,
		);
	}
	update_post_meta( $feed_id, '_teatatu_news_rss_field_map', $field_map );

	set_transient( 'teatatu_news_admin_notice_' . get_current_user_id(), __( 'RSS feed saved.', 'teatatu-news' ), 45 );
	wp_safe_redirect( add_query_arg( array( 'page' => 'teatatu-news', 'tab' => 'rss' ), admin_url( 'admin.php' ) ) );
	exit;
}

add_action( 'admin_post_teatatu_news_toggle_rss_feed', 'teatatu_news_handle_toggle_rss_feed' );

/**
 * Handles the Activate/Pause toggle from the RSS Feeds list.
 */
function teatatu_news_handle_toggle_rss_feed() {
	$feed_id = isset( $_GET['feed_id'] ) ? absint( $_GET['feed_id'] ) : 0;
	check_admin_referer( 'teatatu_news_toggle_rss_feed_' . $feed_id );

	if ( ! $feed_id || 'teatatu_news_rssfeed' !== get_post_type( $feed_id ) || ! current_user_can( 'manage_teatatu_news_rss_feeds' ) ) {
		wp_die( esc_html__( 'You do not have permission to do that.', 'teatatu-news' ), 403 );
	}

	$active = (bool) get_post_meta( $feed_id, '_teatatu_news_rss_active', true );
	update_post_meta( $feed_id, '_teatatu_news_rss_active', $active ? 0 : 1 );

	set_transient( 'teatatu_news_admin_notice_' . get_current_user_id(), __( 'RSS feed updated.', 'teatatu-news' ), 45 );
	wp_safe_redirect( add_query_arg( array( 'page' => 'teatatu-news', 'tab' => 'rss' ), admin_url( 'admin.php' ) ) );
	exit;
}

add_action( 'admin_post_teatatu_news_delete_rss_feed', 'teatatu_news_handle_delete_rss_feed' );

/**
 * Handles RSS feed configuration deletion. Previously imported News items
 * are left untouched — only the feed configuration itself is removed.
 */
function teatatu_news_handle_delete_rss_feed() {
	$feed_id = isset( $_GET['feed_id'] ) ? absint( $_GET['feed_id'] ) : 0;
	check_admin_referer( 'teatatu_news_delete_rss_feed_' . $feed_id );

	if ( ! $feed_id || 'teatatu_news_rssfeed' !== get_post_type( $feed_id ) || ! current_user_can( 'manage_teatatu_news_rss_feeds' ) ) {
		wp_die( esc_html__( 'You do not have permission to do that.', 'teatatu-news' ), 403 );
	}

	wp_delete_post( $feed_id, true );

	set_transient( 'teatatu_news_admin_notice_' . get_current_user_id(), __( 'RSS feed deleted.', 'teatatu-news' ), 45 );
	wp_safe_redirect( add_query_arg( array( 'page' => 'teatatu-news', 'tab' => 'rss' ), admin_url( 'admin.php' ) ) );
	exit;
}
