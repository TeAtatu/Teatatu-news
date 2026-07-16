<?php
/**
 * Custom role, capability provisioning, and taxonomy-capability mapping for
 * the `teatatu_news_agent` role plus human editor roles.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Capabilities granted to the restricted AI-agent role.
 *
 * @return string[]
 */
function teatatu_news_agent_capabilities() {
	return array(
		'read'                             => true,
		'edit_teatatu_news_items'          => true,
		'delete_teatatu_news_items'        => true,
		'assign_teatatu_news_sources'      => true,
		'assign_teatatu_news_categories'   => true,
		'manage_teatatu_news_sources'      => true,
		'manage_teatatu_news_categories'   => true,
	);
}

/**
 * Full set of News capabilities granted to Administrator and Editor so they
 * can manage News items, Sources, and Categories through wp-admin normally.
 *
 * @return string[]
 */
function teatatu_news_editor_capabilities() {
	return array(
		'edit_teatatu_news'                  => true,
		'read_teatatu_news'                  => true,
		'delete_teatatu_news'                => true,
		'edit_teatatu_news_items'            => true,
		'edit_others_teatatu_news_items'     => true,
		'publish_teatatu_news_items'         => true,
		'read_private_teatatu_news_items'    => true,
		'delete_teatatu_news_items'          => true,
		'delete_private_teatatu_news_items'  => true,
		'delete_published_teatatu_news_items' => true,
		'delete_others_teatatu_news_items'   => true,
		'edit_private_teatatu_news_items'    => true,
		'edit_published_teatatu_news_items'  => true,
		'manage_teatatu_news_sources'        => true,
		'edit_teatatu_news_sources'          => true,
		'delete_teatatu_news_sources'        => true,
		'assign_teatatu_news_sources'        => true,
		'manage_teatatu_news_categories'     => true,
		'edit_teatatu_news_categories'       => true,
		'delete_teatatu_news_categories'     => true,
		'assign_teatatu_news_categories'     => true,
		'manage_teatatu_news_rss_feeds'      => true,
	);
}

/**
 * Adds the `teatatu_news_agent` role and grants News capabilities to
 * Administrator/Editor on the current site. Safe to call repeatedly.
 */
function teatatu_news_add_role_and_caps() {
	add_role(
		'teatatu_news_agent',
		__( 'News Agent', 'teatatu-news' ),
		teatatu_news_agent_capabilities()
	);

	// Re-adding an existing role with add_role() is a no-op, so refresh caps explicitly.
	$agent_role = get_role( 'teatatu_news_agent' );
	if ( $agent_role ) {
		foreach ( teatatu_news_agent_capabilities() as $cap => $grant ) {
			$agent_role->add_cap( $cap, $grant );
		}
	}

	foreach ( array( 'administrator', 'editor' ) as $role_name ) {
		$role = get_role( $role_name );
		if ( ! $role ) {
			continue;
		}
		foreach ( teatatu_news_editor_capabilities() as $cap => $grant ) {
			$role->add_cap( $cap, $grant );
		}
	}
}

/**
 * Re-syncs roles/capabilities whenever the plugin's version has changed
 * since they were last provisioned on this site. Capabilities are only
 * ever written to the database when add_cap()/add_role() are explicitly
 * called (normally just on activation) — so a capability added to the code
 * in a later release never reaches a site whose copy of the plugin was
 * updated in place (files replaced) rather than deactivated and
 * reactivated. This is the same self-healing pattern used for the RSS
 * cron schedule in includes/rss-importer.php, applied here for the same
 * reason: don't require a manual reactivation after every code update.
 */
function teatatu_news_maybe_upgrade_caps() {
	if ( get_option( 'teatatu_news_caps_version' ) !== TEATATU_NEWS_VERSION ) {
		teatatu_news_add_role_and_caps();
		update_option( 'teatatu_news_caps_version', TEATATU_NEWS_VERSION );
	}
}
add_action( 'admin_init', 'teatatu_news_maybe_upgrade_caps' );

/**
 * Removes the `teatatu_news_agent` role and strips News capabilities from
 * Administrator/Editor on the current site.
 */
function teatatu_news_remove_role_and_caps() {
	remove_role( 'teatatu_news_agent' );

	foreach ( array( 'administrator', 'editor' ) as $role_name ) {
		$role = get_role( $role_name );
		if ( ! $role ) {
			continue;
		}
		foreach ( array_keys( teatatu_news_editor_capabilities() ) as $cap ) {
			$role->remove_cap( $cap );
		}
	}
}
