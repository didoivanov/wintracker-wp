<?php
/**
 * Wins as a grid of cards (big game image, win amount in front). Good for "biggest wins" sections.
 * Shows the columns it is given: win and game on top, the rest as label / value rows.
 * Override: copy to <theme>/wintracker/wins-cards.php.
 *
 * @var array<int, array<string, mixed>> $wins
 * @var string[]                         $columns
 * @var string                           $currency
 * @var bool                             $images
 * @var string                           $empty
 */

defined( 'ABSPATH' ) || exit;

if ( ! $wins ) {
	echo '<p class="wintracker__empty">' . esc_html( $empty ) . '</p>';
	return;
}
$wt_details = array_values( array_diff( $columns, array( 'game', 'win' ) ) );
?>
<ul class="wintracker__cards">
	<?php foreach ( $wins as $win ) : ?>
		<li class="wintracker__card">
			<?php if ( $images && ! empty( $win['game']['image_url'] ) ) : ?>
				<img class="wintracker__card-img" src="<?php echo esc_url( $win['game']['image_url'] ); ?>" alt="" width="160" height="160" loading="lazy" decoding="async">
			<?php endif; ?>
			<?php if ( in_array( 'win', $columns, true ) ) : ?>
				<span class="wintracker__card-win"><?php echo Wintracker_Format::win_cell( $win, 'win', $currency ); // phpcs:ignore WordPress.Security.EscapeOutput ?></span>
			<?php endif; ?>
			<?php if ( in_array( 'game', $columns, true ) ) : ?>
				<span class="wintracker__card-game"><?php echo Wintracker_Format::win_cell( $win, 'game', $currency, false ); // phpcs:ignore WordPress.Security.EscapeOutput ?></span>
			<?php endif; ?>
			<?php if ( $wt_details ) : ?>
				<dl class="wintracker__card-details">
					<?php foreach ( $wt_details as $column ) : ?>
						<div>
							<dt><?php echo Wintracker_Format::label( $column ); // phpcs:ignore WordPress.Security.EscapeOutput ?></dt>
							<dd><?php echo Wintracker_Format::win_cell( $win, $column, $currency ); // phpcs:ignore WordPress.Security.EscapeOutput ?></dd>
						</div>
					<?php endforeach; ?>
				</dl>
			<?php endif; ?>
		</li>
	<?php endforeach; ?>
</ul>
