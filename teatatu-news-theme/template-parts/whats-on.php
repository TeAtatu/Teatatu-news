<?php
/**
 * The "What's on" strip: upcoming events from the Teatatu Events plugin,
 * shown as date-led cards (variant 'band', home page) or compact rows
 * (variant 'panel', story sidebar). Prints nothing unless events are
 * available, so the theme works the same without the Events plugin.
 *
 * $args['variant'] 'band' | 'panel'; $args['count'] number of events.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
$variant = isset( $args['variant'] ) ? $args['variant'] : 'band';
$count   = isset( $args['count'] ) ? (int) $args['count'] : 3;
if ( ! ttn_events_ready() || ! get_theme_mod( 'ttn_show_events', true ) ) {
	return;
}
$events = ttn_upcoming_events( $count );

if ( 'panel' === $variant ) :
	if ( ! $events ) {
		return;
	}
	?>
	<section class="ttn-panel" data-site="events" aria-labelledby="ttn-whatson-panel">
		<h2 id="ttn-whatson-panel"><?php esc_html_e( 'What’s on', 'teatatu-news-theme' ); ?></h2>
		<div>
			<?php foreach ( $events as $event ) : ?>
				<?php ttn_event( $event, 'row' ); ?>
			<?php endforeach; ?>
		</div>
		<a class="ttn-arrow-link" href="<?php echo esc_url( ttn_events_url() ); ?>"><?php esc_html_e( 'See all events', 'teatatu-news-theme' ); ?><?php echo ttn_icon( 'arrow', 18 ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- static SVG. ?></a>
	</section>
	<?php
	return;
endif;
?>
<section class="ttn-wrap ttn-section" aria-labelledby="ttn-whatson-heading">
	<div class="tal-band ttn-whatson" data-site="events">
		<div class="ttn-section-head">
			<div>
				<p class="ttn-eyebrow"><?php esc_html_e( 'Coming up', 'teatatu-news-theme' ); ?></p>
				<h2 id="ttn-whatson-heading"><?php esc_html_e( 'What’s on', 'teatatu-news-theme' ); ?></h2>
				<p><?php esc_html_e( 'Events on the Peninsula over the next few weeks.', 'teatatu-news-theme' ); ?></p>
			</div>
			<a class="tal-btn tal-btn-primary" href="<?php echo esc_url( ttn_events_url() ); ?>"><?php echo ttn_icon( 'calendar', 18 ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- static SVG. ?><?php esc_html_e( 'See all events', 'teatatu-news-theme' ); ?></a>
		</div>
		<?php if ( $events ) : ?>
			<div class="ttn-card-grid ttn-card-grid--events">
				<?php foreach ( $events as $event ) : ?>
					<?php ttn_event( $event, 'card' ); ?>
				<?php endforeach; ?>
			</div>
		<?php else : ?>
			<p class="ttn-muted"><?php esc_html_e( 'No events are listed right now — check back soon.', 'teatatu-news-theme' ); ?></p>
		<?php endif; ?>
	</div>
</section>
