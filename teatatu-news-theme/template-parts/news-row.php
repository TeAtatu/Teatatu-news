<?php
/**
 * Story row for results lists. Expects $args['item'].
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
$item   = $args['item'];
$teaser = ttn_teaser( $item, 40 );
?>
<a class="ttn-card ttn-row" href="<?php echo esc_url( $item['permalink'] ); ?>">
	<div class="ttn-card__media">
		<?php ttn_image( $item['image_url'] ); ?>
	</div>
	<div class="ttn-card__body">
		<p class="ttn-card__meta">
			<?php if ( $item['category'] ) : ?>
				<span class="ttn-badge ttn-badge--accent"><?php echo esc_html( $item['category'] ); ?></span>
			<?php endif; ?>
			<?php if ( $item['source'] ) : ?>
				<span class="ttn-card__source"><?php echo esc_html( $item['source'] ); ?></span>
			<?php endif; ?>
			<?php ttn_time( $item ); ?>
		</p>
		<h3 class="ttn-card__title"><?php echo esc_html( $item['title'] ); ?></h3>
		<?php if ( $teaser ) : ?>
			<p class="ttn-card__teaser"><?php echo esc_html( $teaser ); ?></p>
		<?php endif; ?>
	</div>
</a>
