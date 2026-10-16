<?php
/**
 * Compact event row (design system .tal-event-row) for the story page's
 * sidebar. Expects $args['item'] — a normalised Teatatu Events item.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
$item  = $args['item'];
$place = ! empty( $item['place']['location'] ) ? $item['place']['location'] : '';
?>
<a class="tal-event-row ttn-event-row" href="<?php echo esc_url( ttn_event_url( $item ) ); ?>">
	<?php ttn_event_date_block( $item ); ?>
	<span class="ttn-event-row__body">
		<strong class="ttn-event-row__title"><?php echo esc_html( $item['title'] ); ?></strong>
		<span class="ttn-event-row__meta"><?php echo esc_html( $item['display_when'] . ( $place ? ' · ' . $place : '' ) ); ?></span>
	</span>
	<?php if ( 'scheduled' !== $item['status'] ) : ?>
		<span class="tal-badge tal-badge-local"><?php echo esc_html( $item['status_label'] ); ?></span>
	<?php endif; ?>
</a>
