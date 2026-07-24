<?php
/**
 * Registers the `teatatu_news_webfeed` post type — one post per configured
 * HTML page import source — and the "HTML Feeds" tab on the maintenance
 * page where an editor manages the list, cadence, repeating-item selector,
 * and per-field content mapping.
 *
 * This is the HTML-page counterpart to includes/rss-feeds.php, for sites
 * that publish a news listing page but no RSS/Atom feed. There is no
 * SimplePie item to lean on here, so the config has one extra piece RSS
 * feeds don't need: a "Repeating Item" selector (tag + class/id) that finds
 * every individual news card/row on the page before any per-field mapping
 * can run. Everything else — cadence, Active toggle, Auto-Publish/
 * Auto-Apply, Default Source/Category, the five-field Content Mapping
 * table, and the Preview Example Values button — deliberately mirrors
 * rss-feeds.php's shape so the two tabs feel like the same feature.
 *
 * This post type has no native wp-admin UI; it's only ever read/written by
 * this plugin's own capability-gated admin-post.php handlers, never exposed
 * over REST (like RSS Feeds, HTML Feeds is a site-configuration concern,
 * not something the `teatatu_news_agent` role should be able to touch).
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

add_action( 'init', 'teatatu_news_register_html_feed_post_type' );

/**
 * Registers the HTML Feed configuration post type.
 */
function teatatu_news_register_html_feed_post_type() {
	register_post_type(
		'teatatu_news_webfeed',
		array(
			'labels'       => array(
				'name'          => __( 'HTML Feeds', 'teatatu-news' ),
				'singular_name' => __( 'HTML Feed', 'teatatu-news' ),
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
 * Default (empty) per-field content mapping. Unlike RSS mapping — which has
 * several distinct "whole value" sources to choose from (Title,
 * Description, Full Content, ...) — an HTML feed item is just one HTML
 * fragment, so every field's source is simply 'item' (search inside that
 * fragment) or 'page' (fetch the resolved Read More URL and search there
 * instead). See teatatu_news_html_field_source_options().
 *
 * @return array
 */
function teatatu_news_html_default_field_map() {
	$blank = array(
		'source'     => 'item',
		'tag'        => '',
		'attr_type'  => 'class',
		'attr_value' => '',
	);
	return array(
		'title'         => $blank,
		'excerpt'       => $blank,
		'image'         => $blank,
		'read_more_url' => $blank,
		'pub_date'      => $blank,
	);
}

/**
 * The selectable content sources for a given News field. Read More URL is
 * deliberately restricted to 'item' only — it's what tells the importer
 * which page to fetch for a 'page' source on any other field, so "fetch the
 * linked page to find its own link" would be circular.
 *
 * @param string $field 'title', 'excerpt', 'image', 'read_more_url', or 'pub_date'.
 * @return array<string,string> value => label
 */
function teatatu_news_html_field_source_options( $field ) {
	if ( 'read_more_url' === $field ) {
		return array( 'item' => __( 'Within Repeating Item', 'teatatu-news' ) );
	}
	return array(
		'item' => __( 'Within Repeating Item', 'teatatu-news' ),
		'page' => __( 'Linked Article Page (fetch the resolved Read More URL and scrape it)', 'teatatu-news' ),
	);
}

/**
 * Reads and normalizes a feed's stored field-mapping config.
 *
 * @param int $feed_id HTML Feed post ID.
 * @return array
 */
function teatatu_news_html_get_field_map( $feed_id ) {
	$stored  = get_post_meta( $feed_id, '_teatatu_news_html_field_map', true );
	$default = teatatu_news_html_default_field_map();
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

/**
 * Reads and sanitizes a full field-mapping config directly from $_POST, the
 * same way teatatu_news_rss_sanitize_field_map_from_request() does for RSS
 * Feeds. Shared by the Save handler and the mapping preview AJAX handler in
 * includes/html-importer.php.
 *
 * @return array
 */
function teatatu_news_html_sanitize_field_map_from_request() {
	$field_map = teatatu_news_html_default_field_map();
	foreach ( array_keys( $field_map ) as $field_key ) {
		$allowed_sources = array_keys( teatatu_news_html_field_source_options( $field_key ) );
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
	return $field_map;
}

/**
 * Reads and normalizes a feed's stored Repeating Item selector.
 *
 * @param int $feed_id HTML Feed post ID.
 * @return array {tag, attr_type, attr_value}
 */
function teatatu_news_html_get_item_selector( $feed_id ) {
	$default = array(
		'tag'        => '',
		'attr_type'  => 'class',
		'attr_value' => '',
	);
	$stored = get_post_meta( $feed_id, '_teatatu_news_html_item_selector', true );
	if ( is_array( $stored ) ) {
		return array_merge( $default, $stored );
	}
	return $default;
}

/**
 * Reads and sanitizes the Repeating Item selector directly from $_POST.
 *
 * @return array {tag, attr_type, attr_value}
 */
function teatatu_news_html_sanitize_item_selector_from_request() {
	$tag        = isset( $_POST['item_tag'] ) ? preg_replace( '/[^a-z0-9]/', '', strtolower( sanitize_text_field( wp_unslash( $_POST['item_tag'] ) ) ) ) : '';
	$attr_type  = ( isset( $_POST['item_attr_type'] ) && 'id' === $_POST['item_attr_type'] ) ? 'id' : 'class';
	$attr_value = isset( $_POST['item_attr_value'] ) ? sanitize_html_class( wp_unslash( $_POST['item_attr_value'] ) ) : '';
	return array(
		'tag'        => $tag,
		'attr_type'  => $attr_type,
		'attr_value' => $attr_value,
	);
}

// -----------------------------------------------------------------------
// Admin UI: "HTML Feeds" tab on the maintenance page
// -----------------------------------------------------------------------

/**
 * Renders the HTML Feeds list + Add/Edit form.
 */
function teatatu_news_render_html_feeds_tab() {
	if ( ! current_user_can( 'manage_teatatu_news_html_feeds' ) ) {
		echo '<p>' . esc_html__( 'You do not have permission to manage HTML feeds.', 'teatatu-news' ) . '</p>';
		return;
	}

	$editing_id     = isset( $_GET['edit'] ) ? absint( $_GET['edit'] ) : 0;
	$editing        = ( $editing_id && 'teatatu_news_webfeed' === get_post_type( $editing_id ) ) ? get_post( $editing_id ) : null;
	$stashed        = teatatu_news_get_stashed_formdata();
	$field_map      = $editing ? teatatu_news_html_get_field_map( $editing_id ) : teatatu_news_html_default_field_map();
	$item_selector  = $editing ? teatatu_news_html_get_item_selector( $editing_id ) : teatatu_news_html_get_item_selector( 0 );
	$item_selector  = isset( $stashed['item_tag'] )
		? array(
			'tag'        => $stashed['item_tag'],
			'attr_type'  => $stashed['item_attr_type'] ?? 'class',
			'attr_value' => $stashed['item_attr_value'] ?? '',
		)
		: $item_selector;
	?>
	<h2><?php echo $editing ? esc_html__( 'Edit HTML Feed', 'teatatu-news' ) : esc_html__( 'Add HTML Feed', 'teatatu-news' ); ?></h2>
	<p><?php esc_html_e( 'For sites that publish a news/events listing page but no RSS or Atom feed. This fetches that page directly and scrapes it, the same general way "Article Page" sources work in RSS Feeds — except here the whole page is the source, and it repeats: one news card/row becomes one News item.', 'teatatu-news' ); ?></p>
	<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
		<?php wp_nonce_field( 'teatatu_news_save_html_feed', 'teatatu_news_html_feed_nonce' ); ?>
		<input type="hidden" name="action" value="teatatu_news_save_html_feed" />
		<input type="hidden" name="feed_id" value="<?php echo esc_attr( $editing_id ); ?>" />
		<table class="form-table">
			<tr>
				<th><label for="tn_html_label"><?php esc_html_e( 'Label', 'teatatu-news' ); ?></label></th>
				<td><input type="text" id="tn_html_label" name="label" class="regular-text" value="<?php echo esc_attr( $stashed['label'] ?? ( $editing ? $editing->post_title : '' ) ); ?>" placeholder="<?php esc_attr_e( 'e.g. Rutherford College News', 'teatatu-news' ); ?>" /></td>
			</tr>
			<tr>
				<th><label for="tn_html_url"><?php esc_html_e( 'Page URL', 'teatatu-news' ); ?> *</label></th>
				<td>
					<input type="url" id="tn_html_url" name="page_url" class="large-text" value="<?php echo esc_attr( $stashed['page_url'] ?? ( $editing ? get_post_meta( $editing_id, '_teatatu_news_html_url', true ) : '' ) ); ?>" placeholder="https://example.school.nz/news" required />
					<p class="description"><?php esc_html_e( 'The listing page to fetch and scrape — e.g. a school\'s /news page. Not a feed file; a normal page a browser would show.', 'teatatu-news' ); ?></p>
				</td>
			</tr>
			<tr>
				<th><label for="tn_html_cadence"><?php esc_html_e( 'Check Every (minutes)', 'teatatu-news' ); ?></label></th>
				<td>
					<input type="number" id="tn_html_cadence" name="cadence" class="small-text" min="5" step="1" value="<?php echo esc_attr( $stashed['cadence'] ?? ( $editing ? (int) get_post_meta( $editing_id, '_teatatu_news_html_cadence', true ) : 60 ) ); ?>" />
					<p class="description"><?php esc_html_e( 'Minimum 5 minutes. WP-Cron only fires on site traffic, so actual timing is best-effort.', 'teatatu-news' ); ?></p>
				</td>
			</tr>
			<tr>
				<th><?php esc_html_e( 'Active', 'teatatu-news' ); ?></th>
				<td>
					<?php $active = isset( $stashed['active'] ) ? (bool) $stashed['active'] : ( $editing ? (bool) get_post_meta( $editing_id, '_teatatu_news_html_active', true ) : true ); ?>
					<label><input type="checkbox" name="active" value="1" <?php checked( $active ); ?> /> <?php esc_html_e( 'Check this feed on its cadence', 'teatatu-news' ); ?></label>
					<p class="description"><?php esc_html_e( 'Uncheck to pause this feed without deleting its configuration.', 'teatatu-news' ); ?></p>
				</td>
			</tr>
			<?php if ( current_user_can( 'publish_teatatu_news_items' ) || current_user_can( 'edit_published_teatatu_news_items' ) ) : ?>
				<tr>
					<th><?php esc_html_e( 'Publishing Behavior', 'teatatu-news' ); ?></th>
					<td>
						<?php if ( current_user_can( 'publish_teatatu_news_items' ) ) : ?>
							<?php $auto_publish = isset( $stashed['auto_publish'] ) ? (bool) $stashed['auto_publish'] : ( $editing ? (bool) get_post_meta( $editing_id, '_teatatu_news_html_auto_publish', true ) : false ); ?>
							<label><input type="checkbox" name="auto_publish" value="1" <?php checked( $auto_publish ); ?> /> <?php esc_html_e( 'Auto-publish new items', 'teatatu-news' ); ?></label>
							<p class="description"><?php esc_html_e( 'Off by default: new items are saved as Draft for review, same as everywhere else in this plugin. Turning this on publishes items from this feed immediately, with no human review — only enable it for a feed you fully trust.', 'teatatu-news' ); ?></p>
						<?php endif; ?>
						<?php if ( current_user_can( 'edit_published_teatatu_news_items' ) ) : ?>
							<?php $auto_apply = isset( $stashed['auto_apply_updates'] ) ? (bool) $stashed['auto_apply_updates'] : ( $editing ? (bool) get_post_meta( $editing_id, '_teatatu_news_html_auto_apply_updates', true ) : false ); ?>
							<p style="margin-top:12px;">
								<label><input type="checkbox" name="auto_apply_updates" value="1" <?php checked( $auto_apply ); ?> /> <?php esc_html_e( 'Auto-apply updates to published items', 'teatatu-news' ); ?></label>
							</p>
							<p class="description"><?php esc_html_e( 'Off by default: if this feed later shows changed content for an item that has already been published, the change is held as a pending update for a human to review and apply. Turning this on applies changes to published items immediately instead.', 'teatatu-news' ); ?></p>
						<?php endif; ?>
					</td>
				</tr>
			<?php endif; ?>
			<tr>
				<th><label for="tn_html_default_source"><?php esc_html_e( 'Default Source', 'teatatu-news' ); ?></label></th>
				<td>
					<?php
					wp_dropdown_categories(
						array(
							'taxonomy'          => 'teatatu_news_source',
							'name'              => 'default_source',
							'id'                => 'tn_html_default_source',
							'hide_empty'        => false,
							'show_option_none'  => __( '— None —', 'teatatu-news' ),
							'option_none_value' => 0,
							'selected'          => isset( $stashed['default_source'] ) ? (int) $stashed['default_source'] : ( $editing ? (int) get_post_meta( $editing_id, '_teatatu_news_html_default_source', true ) : 0 ),
						)
					);
					?>
					<p class="description"><?php esc_html_e( 'Assigned to every News item imported from this feed.', 'teatatu-news' ); ?></p>
				</td>
			</tr>
			<tr>
				<th><label for="tn_html_default_category"><?php esc_html_e( 'Default Category', 'teatatu-news' ); ?></label></th>
				<td>
					<?php
					wp_dropdown_categories(
						array(
							'taxonomy'          => 'teatatu_news_category',
							'name'              => 'default_category',
							'id'                => 'tn_html_default_category',
							'hide_empty'        => false,
							'show_option_none'  => __( '— None —', 'teatatu-news' ),
							'option_none_value' => 0,
							'selected'          => isset( $stashed['default_category'] ) ? (int) $stashed['default_category'] : ( $editing ? (int) get_post_meta( $editing_id, '_teatatu_news_html_default_category', true ) : 0 ),
						)
					);
					?>
				</td>
			</tr>
		</table>

		<h3><?php esc_html_e( 'Repeating Item', 'teatatu-news' ); ?> *</h3>
		<p><?php esc_html_e( 'The one HTML element that repeats once per news item on the page — e.g. each card in a grid, or each row in a list. Every field below is then found by searching inside each matching element in turn, not the whole page. Required: without this, there is nothing to import.', 'teatatu-news' ); ?></p>
		<table class="form-table">
			<tr>
				<th><label for="tn_html_item_tag"><?php esc_html_e( 'Element', 'teatatu-news' ); ?></label></th>
				<td><input type="text" id="tn_html_item_tag" name="item_tag" value="<?php echo esc_attr( $item_selector['tag'] ); ?>" placeholder="div" size="8" /></td>
			</tr>
			<tr>
				<th><label for="tn_html_item_attr_value"><?php esc_html_e( 'Class or ID', 'teatatu-news' ); ?></label></th>
				<td>
					<select id="tn_html_item_attr_type" name="item_attr_type">
						<option value="class" <?php selected( $item_selector['attr_type'], 'class' ); ?>><?php esc_html_e( 'Class', 'teatatu-news' ); ?></option>
						<option value="id" <?php selected( $item_selector['attr_type'], 'id' ); ?>><?php esc_html_e( 'ID', 'teatatu-news' ); ?></option>
					</select>
					<input type="text" id="tn_html_item_attr_value" name="item_attr_value" value="<?php echo esc_attr( $item_selector['attr_value'] ); ?>" placeholder="<?php esc_attr_e( 'e.g. news-card', 'teatatu-news' ); ?>" size="24" />
					<p class="description"><?php esc_html_e( 'At least one of Element or Class/ID is required. Using both narrows the match; Class/ID alone matches that class/id regardless of tag.', 'teatatu-news' ); ?></p>
				</td>
			</tr>
		</table>

		<h3><?php esc_html_e( 'Content Mapping', 'teatatu-news' ); ?></h3>
		<p><?php esc_html_e( 'For each field below, choose a Source, then narrow it to one HTML element by Element (tag) and/or Class or ID. Selectors are relative to each Repeating Item found above — e.g. a title Class/ID only needs to match the heading inside one card, not the whole page.', 'teatatu-news' ); ?></p>
		<ul style="list-style:disc;margin:0 0 1em 20px;">
			<li><?php esc_html_e( '"Within Repeating Item" searches inside the matched card/row itself. "Linked Article Page" fetches that item\'s own Read More URL and searches the fetched page instead — useful when a listing page only shows a title and the rest lives on the article itself.', 'teatatu-news' ); ?></li>
			<li><?php esc_html_e( 'Title and Excerpt always require an Element and/or Class/ID — unlike RSS mapping, there is no single "whole item" text to fall back to, since one repeating item\'s HTML contains the title, date, excerpt, and links all mixed together.', 'teatatu-news' ); ?></li>
			<li><?php esc_html_e( 'Image and Read More URL can be left with no Element/Class-ID: they then search the whole repeating item for its first image (an <img>, or a CSS background-image — some sites set card images that way) or its first link, respectively.', 'teatatu-news' ); ?></li>
			<li><?php esc_html_e( 'Read More URL is this item\'s identity: it is how re-imports recognise "the same item" on the next check, so it must resolve to something for an item to be imported or updated at all. It can only be found within the Repeating Item, never on the linked page (there would be nothing to fetch yet).', 'teatatu-news' ); ?></li>
			<li><?php esc_html_e( 'Publish Date always requires an Element and/or Class/ID too. Its element\'s datetime="..." attribute is used if present (e.g. a <time datetime="2024-03-01T10:00:00Z"> element), otherwise its visible text is parsed automatically (e.g. "1 March 2024"). If it can\'t be resolved, the item still imports — it just uses the time it was imported as its date instead.', 'teatatu-news' ); ?></li>
			<li><?php esc_html_e( 'If a field can\'t be resolved at all, it fails safe: Title left unresolved means the item is skipped entirely; Excerpt and Image URL are simply left blank. All formatting except links is stripped from mapped Excerpt content; Title is always reduced to plain text.', 'teatatu-news' ); ?></li>
			<li>
				<?php esc_html_e( 'Class or ID only ever takes one class name — an element with several classes still matches on just one of them, so enter whichever single class is shared by that element in every repeating item, never the whole class attribute.', 'teatatu-news' ); ?>
				<?php esc_html_e( 'This matters most on page-builder sites (Elementor, Divi, Avada/Fusion, and similar), which often add a second class to each element with a number that increases once per element on the whole page — not per item.', 'teatatu-news' ); ?>
				<?php
				printf(
					/* translators: 1: example class pair, 2: example class pair, 3: correct class to use, 4: wrong class to avoid. */
					esc_html__( 'For example, an Avada/Fusion "Post Cards" listing might mark up one card\'s text as %1$s and the next card\'s as %2$s — enter %3$s (shared by every card), never %4$s (only that one card).', 'teatatu-news' ),
					'<code>class="fusion-text fusion-text-4"</code>',
					'<code>class="fusion-text fusion-text-5"</code>',
					'<code>fusion-text</code>',
					'<code>fusion-text-4</code>'
				);
				?>
				<?php esc_html_e( 'Because each field is already searched only within the current Repeating Item, the shared class alone is enough to find the right element in every item — it does not need to be more specific than that.', 'teatatu-news' ); ?>
			</li>
		</ul>
		<p>
			<button type="button" class="button" id="tn_html_preview_btn"><?php esc_html_e( 'Preview Example Values', 'teatatu-news' ); ?></button>
			<span id="tn_html_preview_status" style="margin-left:8px;font-style:italic;"></span>
			<br /><span class="description"><?php esc_html_e( 'Fetches this page and shows what the Repeating Item selector and mapping above currently produce for its first matching item — without saving anything.', 'teatatu-news' ); ?></span>
		</p>
		<div style="max-width:100%;overflow-x:auto;">
		<table class="wp-list-table widefat striped" style="table-layout:auto;">
			<thead>
				<tr>
					<th style="width:1%;white-space:nowrap;"><?php esc_html_e( 'News Field', 'teatatu-news' ); ?></th>
					<th style="width:1%;white-space:nowrap;"><?php esc_html_e( 'Source', 'teatatu-news' ); ?></th>
					<th style="width:1%;white-space:nowrap;"><?php esc_html_e( 'Element (optional)', 'teatatu-news' ); ?></th>
					<th style="width:1%;white-space:nowrap;"><?php esc_html_e( 'Class or ID (optional)', 'teatatu-news' ); ?></th>
					<th><?php esc_html_e( 'Example (first item)', 'teatatu-news' ); ?></th>
				</tr>
			</thead>
			<tbody>
				<?php
				$field_labels = array(
					'title'         => __( 'Title', 'teatatu-news' ),
					'excerpt'       => __( 'Excerpt', 'teatatu-news' ),
					'image'         => __( 'Image URL', 'teatatu-news' ),
					'read_more_url' => __( 'Read More URL', 'teatatu-news' ),
					'pub_date'      => __( 'Publish Date', 'teatatu-news' ),
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
						<td style="white-space:nowrap;"><strong><?php echo esc_html( $field_label ); ?></strong></td>
						<td style="white-space:nowrap;">
							<select name="map_<?php echo esc_attr( $field_key ); ?>_source">
								<?php foreach ( teatatu_news_html_field_source_options( $field_key ) as $value => $label ) : ?>
									<option value="<?php echo esc_attr( $value ); ?>" <?php selected( $fm['source'], $value ); ?>><?php echo esc_html( $label ); ?></option>
								<?php endforeach; ?>
							</select>
						</td>
						<td style="white-space:nowrap;"><input type="text" name="map_<?php echo esc_attr( $field_key ); ?>_tag" value="<?php echo esc_attr( $fm['tag'] ); ?>" placeholder="div" size="6" /></td>
						<td style="white-space:nowrap;">
							<select name="map_<?php echo esc_attr( $field_key ); ?>_attr_type">
								<option value="class" <?php selected( $fm['attr_type'], 'class' ); ?>><?php esc_html_e( 'Class', 'teatatu-news' ); ?></option>
								<option value="id" <?php selected( $fm['attr_type'], 'id' ); ?>><?php esc_html_e( 'ID', 'teatatu-news' ); ?></option>
							</select>
							<input type="text" name="map_<?php echo esc_attr( $field_key ); ?>_attr_value" value="<?php echo esc_attr( $fm['attr_value'] ); ?>" placeholder="<?php esc_attr_e( 'e.g. card-title', 'teatatu-news' ); ?>" size="18" />
						</td>
						<td class="tn-html-example" data-tn-example="<?php echo esc_attr( $field_key ); ?>" style="white-space:normal;word-break:break-word;min-width:220px;"><em><?php esc_html_e( 'Not previewed yet', 'teatatu-news' ); ?></em></td>
					</tr>
				<?php endforeach; ?>
			</tbody>
		</table>
		</div>

		<?php submit_button( $editing ? __( 'Update HTML Feed', 'teatatu-news' ) : __( 'Add HTML Feed', 'teatatu-news' ) ); ?>
	</form>
	<script>
	( function () {
		var btn    = document.getElementById( 'tn_html_preview_btn' );
		var status = document.getElementById( 'tn_html_preview_status' );
		if ( ! btn || ! status ) {
			return;
		}
		btn.addEventListener( 'click', function () {
			var urlField = document.getElementById( 'tn_html_url' );
			var pageUrl  = urlField ? urlField.value.trim() : '';
			if ( ! pageUrl ) {
				status.textContent = <?php echo wp_json_encode( __( 'Enter a Page URL first.', 'teatatu-news' ) ); ?>;
				return;
			}

			var data = new FormData();
			data.append( 'action', 'teatatu_news_html_preview_mapping' );
			data.append( 'nonce', <?php echo wp_json_encode( wp_create_nonce( 'teatatu_news_html_preview_mapping' ) ); ?> );
			data.append( 'page_url', pageUrl );
			document.querySelectorAll( '[name^="map_"], [name^="item_"]' ).forEach( function ( field ) {
				data.append( field.name, field.value );
			} );

			btn.disabled = true;
			status.textContent = <?php echo wp_json_encode( __( 'Fetching…', 'teatatu-news' ) ); ?>;
			document.querySelectorAll( '.tn-html-example' ).forEach( function ( cell ) {
				cell.innerHTML = '';
			} );

			fetch( <?php echo wp_json_encode( admin_url( 'admin-ajax.php' ) ); ?>, {
				method: 'POST',
				credentials: 'same-origin',
				body: data,
			} )
				.then( function ( response ) { return response.json(); } )
				.then( function ( json ) {
					btn.disabled = false;
					if ( ! json || ! json.success ) {
						status.textContent = ( json && json.data && json.data.message ) ? json.data.message : <?php echo wp_json_encode( __( 'Preview failed.', 'teatatu-news' ) ); ?>;
						return;
					}
					status.textContent = <?php echo wp_json_encode( __( 'Showing the first matching item.', 'teatatu-news' ) ); ?>;
					Object.keys( json.data ).forEach( function ( field ) {
						var cell = document.querySelector( '[data-tn-example="' + field + '"]' );
						if ( ! cell ) {
							return;
						}
						cell.textContent = json.data[ field ];
						if ( 'image' === field && json.data[ field ] && 0 === json.data[ field ].indexOf( 'http' ) ) {
							var img = document.createElement( 'img' );
							img.src = json.data[ field ];
							img.alt = '';
							img.style.cssText = 'display:block;max-width:120px;height:auto;margin-top:4px;';
							cell.appendChild( img );
						}
					} );
				} )
				.catch( function () {
					btn.disabled = false;
					status.textContent = <?php echo wp_json_encode( __( 'Preview failed.', 'teatatu-news' ) ); ?>;
				} );
		} );
	} )();
	</script>

	<hr />
	<h2><?php esc_html_e( 'All HTML Feeds', 'teatatu-news' ); ?></h2>
	<?php
	$feeds = get_posts(
		array(
			'post_type'      => 'teatatu_news_webfeed',
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
				<th><?php esc_html_e( 'Page URL', 'teatatu-news' ); ?></th>
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
				$active       = (bool) get_post_meta( $feed->ID, '_teatatu_news_html_active', true );
				$cadence      = (int) get_post_meta( $feed->ID, '_teatatu_news_html_cadence', true );
				$last_checked = (int) get_post_meta( $feed->ID, '_teatatu_news_html_last_checked', true );
				$last_error   = get_post_meta( $feed->ID, '_teatatu_news_html_last_error', true );
				$auto_publish = (bool) get_post_meta( $feed->ID, '_teatatu_news_html_auto_publish', true );
				$auto_apply   = (bool) get_post_meta( $feed->ID, '_teatatu_news_html_auto_apply_updates', true );
				$edit_url     = add_query_arg( array( 'page' => 'teatatu-news', 'tab' => 'html', 'edit' => $feed->ID ), admin_url( 'admin.php' ) );
				$toggle_url   = wp_nonce_url( add_query_arg( array( 'action' => 'teatatu_news_toggle_html_feed', 'feed_id' => $feed->ID ), admin_url( 'admin-post.php' ) ), 'teatatu_news_toggle_html_feed_' . $feed->ID );
				$check_url    = wp_nonce_url( add_query_arg( array( 'action' => 'teatatu_news_check_html_feed_now', 'feed_id' => $feed->ID ), admin_url( 'admin-post.php' ) ), 'teatatu_news_check_html_feed_now_' . $feed->ID );
				$delete_url   = wp_nonce_url( add_query_arg( array( 'action' => 'teatatu_news_delete_html_feed', 'feed_id' => $feed->ID ), admin_url( 'admin-post.php' ) ), 'teatatu_news_delete_html_feed_' . $feed->ID );
				?>
				<tr>
					<td><?php echo esc_html( $feed->post_title ? $feed->post_title : $feed->ID ); ?></td>
					<td><?php echo esc_html( get_post_meta( $feed->ID, '_teatatu_news_html_url', true ) ); ?></td>
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
						 | <a href="<?php echo esc_url( $delete_url ); ?>" onclick="return confirm('<?php echo esc_js( __( 'Delete this HTML feed configuration? Previously imported News items are not affected.', 'teatatu-news' ) ); ?>');"><?php esc_html_e( 'Delete', 'teatatu-news' ); ?></a>
					</td>
				</tr>
			<?php endforeach; ?>
		<?php else : ?>
			<tr><td colspan="6"><?php esc_html_e( 'No HTML feeds configured yet.', 'teatatu-news' ); ?></td></tr>
		<?php endif; ?>
		</tbody>
	</table>
	<?php
}

add_action( 'admin_post_teatatu_news_save_html_feed', 'teatatu_news_handle_save_html_feed' );

/**
 * Handles the HTML Feed Add/Edit form submission.
 */
function teatatu_news_handle_save_html_feed() {
	if ( ! isset( $_POST['teatatu_news_html_feed_nonce'] ) || ! wp_verify_nonce( wp_unslash( $_POST['teatatu_news_html_feed_nonce'] ), 'teatatu_news_save_html_feed' ) ) {
		wp_die( esc_html__( 'Security check failed.', 'teatatu-news' ) );
	}
	if ( ! current_user_can( 'manage_teatatu_news_html_feeds' ) ) {
		wp_die( esc_html__( 'You do not have permission to do that.', 'teatatu-news' ), 403 );
	}

	$feed_id          = isset( $_POST['feed_id'] ) ? absint( $_POST['feed_id'] ) : 0;
	$label            = isset( $_POST['label'] ) ? sanitize_text_field( wp_unslash( $_POST['label'] ) ) : '';
	$page_url         = isset( $_POST['page_url'] ) ? esc_url_raw( wp_unslash( $_POST['page_url'] ) ) : '';
	$cadence          = isset( $_POST['cadence'] ) ? max( 5, absint( $_POST['cadence'] ) ) : 60;
	$active           = ! empty( $_POST['active'] );
	$default_source   = isset( $_POST['default_source'] ) ? absint( $_POST['default_source'] ) : 0;
	$default_category = isset( $_POST['default_category'] ) ? absint( $_POST['default_category'] ) : 0;

	if ( $feed_id && 'teatatu_news_webfeed' !== get_post_type( $feed_id ) ) {
		wp_die( esc_html__( 'That item is not an HTML feed.', 'teatatu-news' ) );
	}

	$errors = array();
	if ( '' === $page_url ) {
		$errors[] = __( 'Page URL is required.', 'teatatu-news' );
	}
	$item_selector = teatatu_news_html_sanitize_item_selector_from_request();
	if ( ! $item_selector['tag'] && ! $item_selector['attr_value'] ) {
		$errors[] = __( 'A Repeating Item Element and/or Class/ID is required.', 'teatatu-news' );
	}

	if ( ! empty( $errors ) ) {
		$uid = get_current_user_id();
		set_transient( 'teatatu_news_admin_errors_' . $uid, $errors, 45 );
		set_transient( 'teatatu_news_admin_formdata_' . $uid, wp_unslash( $_POST ), 45 );
		$redirect = array( 'page' => 'teatatu-news', 'tab' => 'html' );
		if ( $feed_id ) {
			$redirect['edit'] = $feed_id;
		}
		wp_safe_redirect( add_query_arg( $redirect, admin_url( 'admin.php' ) ) );
		exit;
	}

	if ( '' === $label ) {
		$label = $page_url;
	}

	$postarr = array(
		'post_title'  => $label,
		'post_status' => 'publish',
	);

	if ( $feed_id ) {
		$postarr['ID'] = $feed_id;
		$result        = wp_update_post( $postarr, true );
	} else {
		$postarr['post_type'] = 'teatatu_news_webfeed';
		$result               = wp_insert_post( $postarr, true );
	}

	if ( is_wp_error( $result ) ) {
		set_transient( 'teatatu_news_admin_errors_' . get_current_user_id(), array( teatatu_news_format_wp_error( $result ) ), 45 );
		wp_safe_redirect( add_query_arg( array( 'page' => 'teatatu-news', 'tab' => 'html' ), admin_url( 'admin.php' ) ) );
		exit;
	}

	$feed_id = $result;

	update_post_meta( $feed_id, '_teatatu_news_html_url', $page_url );
	update_post_meta( $feed_id, '_teatatu_news_html_cadence', $cadence );
	update_post_meta( $feed_id, '_teatatu_news_html_active', $active ? 1 : 0 );
	update_post_meta( $feed_id, '_teatatu_news_html_default_source', $default_source );
	update_post_meta( $feed_id, '_teatatu_news_html_default_category', $default_category );
	update_post_meta( $feed_id, '_teatatu_news_html_item_selector', $item_selector );

	// Same capability posture as RSS Feeds: only a user who already holds
	// the real content capability can turn either toggle on, and an
	// invisible field (because the current user lacks that capability)
	// leaves the existing stored value untouched rather than resetting it.
	if ( current_user_can( 'publish_teatatu_news_items' ) ) {
		update_post_meta( $feed_id, '_teatatu_news_html_auto_publish', ! empty( $_POST['auto_publish'] ) ? 1 : 0 );
	}
	if ( current_user_can( 'edit_published_teatatu_news_items' ) ) {
		update_post_meta( $feed_id, '_teatatu_news_html_auto_apply_updates', ! empty( $_POST['auto_apply_updates'] ) ? 1 : 0 );
	}

	update_post_meta( $feed_id, '_teatatu_news_html_field_map', teatatu_news_html_sanitize_field_map_from_request() );

	set_transient( 'teatatu_news_admin_notice_' . get_current_user_id(), __( 'HTML feed saved.', 'teatatu-news' ), 45 );
	wp_safe_redirect( add_query_arg( array( 'page' => 'teatatu-news', 'tab' => 'html' ), admin_url( 'admin.php' ) ) );
	exit;
}

add_action( 'admin_post_teatatu_news_toggle_html_feed', 'teatatu_news_handle_toggle_html_feed' );

/**
 * Handles the Activate/Pause toggle from the HTML Feeds list.
 */
function teatatu_news_handle_toggle_html_feed() {
	$feed_id = isset( $_GET['feed_id'] ) ? absint( $_GET['feed_id'] ) : 0;
	check_admin_referer( 'teatatu_news_toggle_html_feed_' . $feed_id );

	if ( ! $feed_id || 'teatatu_news_webfeed' !== get_post_type( $feed_id ) || ! current_user_can( 'manage_teatatu_news_html_feeds' ) ) {
		wp_die( esc_html__( 'You do not have permission to do that.', 'teatatu-news' ), 403 );
	}

	$active = (bool) get_post_meta( $feed_id, '_teatatu_news_html_active', true );
	update_post_meta( $feed_id, '_teatatu_news_html_active', $active ? 0 : 1 );

	set_transient( 'teatatu_news_admin_notice_' . get_current_user_id(), __( 'HTML feed updated.', 'teatatu-news' ), 45 );
	wp_safe_redirect( add_query_arg( array( 'page' => 'teatatu-news', 'tab' => 'html' ), admin_url( 'admin.php' ) ) );
	exit;
}

add_action( 'admin_post_teatatu_news_delete_html_feed', 'teatatu_news_handle_delete_html_feed' );

/**
 * Handles HTML feed configuration deletion. Previously imported News items
 * are left untouched — only the feed configuration itself is removed.
 */
function teatatu_news_handle_delete_html_feed() {
	$feed_id = isset( $_GET['feed_id'] ) ? absint( $_GET['feed_id'] ) : 0;
	check_admin_referer( 'teatatu_news_delete_html_feed_' . $feed_id );

	if ( ! $feed_id || 'teatatu_news_webfeed' !== get_post_type( $feed_id ) || ! current_user_can( 'manage_teatatu_news_html_feeds' ) ) {
		wp_die( esc_html__( 'You do not have permission to do that.', 'teatatu-news' ), 403 );
	}

	wp_delete_post( $feed_id, true );

	set_transient( 'teatatu_news_admin_notice_' . get_current_user_id(), __( 'HTML feed deleted.', 'teatatu-news' ), 45 );
	wp_safe_redirect( add_query_arg( array( 'page' => 'teatatu-news', 'tab' => 'html' ), admin_url( 'admin.php' ) ) );
	exit;
}
