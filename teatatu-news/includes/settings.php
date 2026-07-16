<?php
/**
 * Settings page: Network Admin > Settings > Teatatu News on a multisite
 * install (Master Site, GitHub repo/token, data-retention), or
 * Settings > Teatatu News on a single-site install (same fields minus
 * Master Site, which doesn't apply outside multisite). One shared render
 * function targets get/update_site_option() or get/update_option()
 * depending on is_multisite().
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Reads a plugin option, using the network options table on multisite.
 *
 * @param string $key     Option name.
 * @param mixed  $default Default value.
 * @return mixed
 */
function teatatu_news_get_option( $key, $default = false ) {
	return is_multisite() ? get_site_option( $key, $default ) : get_option( $key, $default );
}

/**
 * Writes a plugin option, using the network options table on multisite.
 *
 * @param string $key   Option name.
 * @param mixed  $value New value.
 * @return bool
 */
function teatatu_news_update_option( $key, $value ) {
	return is_multisite() ? update_site_option( $key, $value ) : update_option( $key, $value );
}

/**
 * Deletes a plugin option, using the network options table on multisite.
 *
 * @param string $key Option name.
 * @return bool
 */
function teatatu_news_delete_option( $key ) {
	return is_multisite() ? delete_site_option( $key ) : delete_option( $key );
}

add_action( 'network_admin_menu', 'teatatu_news_register_network_settings_menu' );

/**
 * Adds the settings page under Network Admin > Settings on multisite.
 */
function teatatu_news_register_network_settings_menu() {
	add_submenu_page(
		'settings.php',
		__( 'Teatatu News', 'teatatu-news' ),
		__( 'Teatatu News', 'teatatu-news' ),
		'manage_network_options',
		'teatatu-news-settings',
		'teatatu_news_render_settings_page'
	);
}

add_action( 'admin_menu', 'teatatu_news_register_site_settings_menu' );

/**
 * Adds the settings page under Settings on a single-site install.
 */
function teatatu_news_register_site_settings_menu() {
	if ( is_multisite() ) {
		return;
	}
	add_options_page(
		__( 'Teatatu News', 'teatatu-news' ),
		__( 'Teatatu News', 'teatatu-news' ),
		'manage_options',
		'teatatu-news-settings',
		'teatatu_news_render_settings_page'
	);
}

/**
 * Renders the settings form. Same markup on both single-site and
 * multisite; only the storage backend and the presence of the Master Site
 * field differ.
 */
function teatatu_news_render_settings_page() {
	$cap = is_multisite() ? 'manage_network_options' : 'manage_options';
	if ( ! current_user_can( $cap ) ) {
		wp_die( esc_html__( 'You do not have permission to access this page.', 'teatatu-news' ) );
	}

	$github_repo         = teatatu_news_get_option( 'teatatu_news_github_repo', 'TeAtatu/Teatatu-news' );
	$github_token_is_set = (bool) teatatu_news_get_option( 'teatatu_news_github_token', '' );
	$delete_on_uninstall = (bool) teatatu_news_get_option( 'teatatu_news_delete_data_on_uninstall', false );
	$master_site_id      = teatatu_news_get_master_site_id();
	$post_url            = is_multisite() ? network_admin_url( 'admin-post.php' ) : admin_url( 'admin-post.php' );

	if ( isset( $_GET['teatatu_news_settings_saved'] ) ) {
		echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__( 'Settings saved.', 'teatatu-news' ) . '</p></div>';
	}
	?>
	<div class="wrap">
		<h1><?php esc_html_e( 'Teatatu News Settings', 'teatatu-news' ); ?></h1>
		<form method="post" action="<?php echo esc_url( $post_url ); ?>">
			<?php wp_nonce_field( 'teatatu_news_save_settings', 'teatatu_news_settings_nonce' ); ?>
			<input type="hidden" name="action" value="teatatu_news_save_settings" />
			<table class="form-table">
				<?php if ( is_multisite() ) : ?>
					<tr>
						<th><label for="tn_master_site"><?php esc_html_e( 'Master Site', 'teatatu-news' ); ?></label></th>
						<td>
							<select id="tn_master_site" name="master_site_id">
								<option value="0"><?php esc_html_e( '— None —', 'teatatu-news' ); ?></option>
								<?php foreach ( get_sites() as $site ) : ?>
									<option value="<?php echo esc_attr( $site->blog_id ); ?>" <?php selected( $master_site_id, (int) $site->blog_id ); ?>>
										<?php echo esc_html( $site->blogname ? $site->blogname : $site->domain . $site->path ); ?> (<?php echo esc_html( $site->domain . $site->path ); ?>)
									</option>
								<?php endforeach; ?>
							</select>
							<p class="description"><?php esc_html_e( 'The site where the network-wide [teatatu_news_network_*] shortcodes and the network-feed REST endpoint produce output.', 'teatatu-news' ); ?></p>
						</td>
					</tr>
				<?php endif; ?>
				<tr>
					<th><label for="tn_github_repo"><?php esc_html_e( 'GitHub Repository', 'teatatu-news' ); ?></label></th>
					<td>
						<input type="text" id="tn_github_repo" name="github_repo" class="regular-text" value="<?php echo esc_attr( $github_repo ); ?>" placeholder="owner/repo" />
						<p class="description"><?php esc_html_e( 'Used to check for plugin updates via GitHub Releases.', 'teatatu-news' ); ?></p>
					</td>
				</tr>
				<tr>
					<th><label for="tn_github_token"><?php esc_html_e( 'GitHub Access Token', 'teatatu-news' ); ?></label></th>
					<td>
						<input type="password" id="tn_github_token" name="github_token" class="regular-text" value="" autocomplete="new-password" placeholder="<?php echo $github_token_is_set ? esc_attr__( 'Token is set — leave blank to keep it', 'teatatu-news' ) : esc_attr__( 'Optional, only needed for a private repo', 'teatatu-news' ); ?>" />
						<?php if ( $github_token_is_set ) : ?>
							<label style="display:block;margin-top:6px;"><input type="checkbox" name="github_token_clear" value="1" /> <?php esc_html_e( 'Clear the saved token', 'teatatu-news' ); ?></label>
						<?php endif; ?>
					</td>
				</tr>
				<tr>
					<th><?php esc_html_e( 'Data on Uninstall', 'teatatu-news' ); ?></th>
					<td>
						<label><input type="checkbox" name="delete_data_on_uninstall" value="1" <?php checked( $delete_on_uninstall ); ?> /> <?php esc_html_e( 'Delete all Teatatu News data when this plugin is uninstalled', 'teatatu-news' ); ?></label>
						<p class="description">
							<?php
							if ( is_multisite() ) {
								esc_html_e( 'Unchecked by default. When checked, uninstalling permanently deletes every News post, RSS feed configuration, and Source/Category term on every site in the network.', 'teatatu-news' );
							} else {
								esc_html_e( 'Unchecked by default. When checked, uninstalling permanently deletes every News post, RSS feed configuration, and Source/Category term on this site.', 'teatatu-news' );
							}
							?>
						</p>
					</td>
				</tr>
			</table>
			<?php submit_button( __( 'Save Settings', 'teatatu-news' ) ); ?>
		</form>

		<hr />
		<h2><?php esc_html_e( 'Updates', 'teatatu-news' ); ?></h2>
		<p><?php esc_html_e( 'Current version:', 'teatatu-news' ); ?> <strong><?php echo esc_html( TEATATU_NEWS_VERSION ); ?></strong></p>
		<form method="post" action="<?php echo esc_url( $post_url ); ?>">
			<?php wp_nonce_field( 'teatatu_news_check_updates', 'teatatu_news_check_updates_nonce' ); ?>
			<input type="hidden" name="action" value="teatatu_news_check_updates" />
			<?php submit_button( __( 'Check for Updates Now', 'teatatu-news' ), 'secondary' ); ?>
		</form>
	</div>
	<?php
}

add_action( 'admin_post_teatatu_news_save_settings', 'teatatu_news_handle_save_settings' );

/**
 * Handles the settings form submission.
 */
function teatatu_news_handle_save_settings() {
	if ( ! isset( $_POST['teatatu_news_settings_nonce'] ) || ! wp_verify_nonce( wp_unslash( $_POST['teatatu_news_settings_nonce'] ), 'teatatu_news_save_settings' ) ) {
		wp_die( esc_html__( 'Security check failed.', 'teatatu-news' ) );
	}
	$cap = is_multisite() ? 'manage_network_options' : 'manage_options';
	if ( ! current_user_can( $cap ) ) {
		wp_die( esc_html__( 'You do not have permission to do that.', 'teatatu-news' ), 403 );
	}

	if ( is_multisite() && isset( $_POST['master_site_id'] ) ) {
		$master_site_id = absint( $_POST['master_site_id'] );
		update_site_option( 'teatatu_news_master_site_id', $master_site_id );
	}

	if ( isset( $_POST['github_repo'] ) ) {
		$repo = sanitize_text_field( wp_unslash( $_POST['github_repo'] ) );
		teatatu_news_update_option( 'teatatu_news_github_repo', $repo ? $repo : 'TeAtatu/Teatatu-news' );
	}

	if ( ! empty( $_POST['github_token_clear'] ) ) {
		teatatu_news_delete_option( 'teatatu_news_github_token' );
	} elseif ( ! empty( $_POST['github_token'] ) ) {
		teatatu_news_update_option( 'teatatu_news_github_token', sanitize_text_field( wp_unslash( $_POST['github_token'] ) ) );
	}

	teatatu_news_update_option( 'teatatu_news_delete_data_on_uninstall', ! empty( $_POST['delete_data_on_uninstall'] ) );

	$redirect_base = is_multisite() ? network_admin_url( 'settings.php' ) : admin_url( 'options-general.php' );
	wp_safe_redirect( add_query_arg( array( 'page' => 'teatatu-news-settings', 'teatatu_news_settings_saved' => '1' ), $redirect_base ) );
	exit;
}
