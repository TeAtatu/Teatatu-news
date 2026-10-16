<?php
/**
 * Customizer: presentation settings only (hero photo, links, the "What's
 * on" strip, footer line). News data lives in the Teatatu News plugin.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

add_action( 'customize_register', 'ttn_customize_register' );

/**
 * Registers the "Te Atatū News" Customizer section.
 *
 * @param WP_Customize_Manager $wp_customize Manager.
 */
function ttn_customize_register( $wp_customize ) {
	$wp_customize->add_section(
		'ttn_theme',
		array(
			'title'    => __( 'Te Atatū News', 'teatatu-news-theme' ),
			'priority' => 30,
		)
	);

	$settings = array(
		'ttn_hero_image'     => array( __( 'Home page photo URL (leave blank to feature the latest story)', 'teatatu-news-theme' ), 'url', 'esc_url_raw' ),
		'ttn_hero_image_alt' => array( __( 'Home page photo description (alt text)', 'teatatu-news-theme' ), 'text', 'sanitize_text_field' ),
		'ttn_share_url'      => array( __( '"Share a story" link', 'teatatu-news-theme' ), 'url', 'esc_url_raw' ),
		'ttn_events_url'     => array( __( '"See all events" link (blank: this site’s Events archive, else events.teatatu.nz)', 'teatatu-news-theme' ), 'url', 'esc_url_raw' ),
		'ttn_footer_text'    => array( __( 'Footer line', 'teatatu-news-theme' ), 'text', 'sanitize_text_field' ),
	);
	foreach ( $settings as $id => $def ) {
		$wp_customize->add_setting( $id, array( 'default' => '', 'sanitize_callback' => $def[2] ) );
		$wp_customize->add_control( $id, array( 'label' => $def[0], 'section' => 'ttn_theme', 'type' => $def[1] ) );
	}

	$wp_customize->add_setting(
		'ttn_show_events',
		array(
			'default'           => true,
			'sanitize_callback' => 'rest_sanitize_boolean',
		)
	);
	$wp_customize->add_control(
		'ttn_show_events',
		array(
			'label'       => __( 'Show a “What’s on” events strip', 'teatatu-news-theme' ),
			'description' => __( 'Appears only when the Teatatu Events plugin is active.', 'teatatu-news-theme' ),
			'section'     => 'ttn_theme',
			'type'        => 'checkbox',
		)
	);
}
