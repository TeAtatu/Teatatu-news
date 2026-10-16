<?php
/**
 * Story card for grids. Expects $args['item'] — a normalised item from
 * teatatu_news_normalize_item().
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
$item   = $args['item'];
$teaser = ttn_teaser( $item );
?>
<a class="ttn-card" href="<?php echo esc_url( $item['permalink'] ); ?>">
	<div class="ttn-card__media">
		<?php ttn_image( $item['image_url'] ); ?>
		<?php if ( $item['category'] ) : ?>
			<span class="ttn-tag"><?php echo esc_html( $item['category'] ); ?></span>
		<?php endif; ?>
	</div>
	<div class="ttn-card__body">
		<p class="ttn-card__meta">
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
