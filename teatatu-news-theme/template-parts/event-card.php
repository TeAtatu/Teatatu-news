<?php
/**
 * Event card for the "What's on" strip, built from the design system's
 * EventCard (.tal-event), which takes the Events accent on its own.
 * Expects $args['item'] — a normalised item from the Teatatu Events plugin.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
$item     = $args['item'];
$category = $item['category'] ? $item['category'] : ( ! empty( $item['tags'][0]['name'] ) ? $item['tags'][0]['name'] : '' );
$place    = ! empty( $item['place']['location'] ) ? $item['place']['location'] : ( ! empty( $item['place']['address'] ) ? $item['place']['address'] : '' );
?>
<article class="tal-event ttn-event-card">
	<?php ttn_event_date_block( $item ); ?>
	<div>
		<div class="tal-event-kicker">
			<?php if ( $category ) : ?>
				<span class="tal-event-cat"><?php echo esc_html( $category ); ?></span>
			<?php endif; ?>
			<?php if ( 'scheduled' !== $item['status'] ) : ?>
				<span class="tal-badge tal-badge-local"><?php echo esc_html( $item['status_label'] ); ?></span>
			<?php elseif ( $item['is_free'] ) : ?>
				<span class="tal-badge tal-badge-local"><?php esc_html_e( 'Free', 'teatatu-news-theme' ); ?></span>
			<?php endif; ?>
		</div>
		<h3 class="tal-event-title"><a class="ttn-plain-link" href="<?php echo esc_url( ttn_event_url( $item ) ); ?>"><?php echo esc_html( $item['title'] ); ?></a></h3>
		<ul class="tal-event-facts">
			<li><?php echo ttn_icon( 'clock', 16 ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- static SVG. ?><?php echo esc_html( $item['display_when'] ); ?></li>
			<?php if ( $place ) : ?>
				<li><?php echo ttn_icon( 'pin', 16 ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- static SVG. ?><?php echo esc_html( $place ); ?></li>
			<?php endif; ?>
		</ul>
	</div>
</article>
