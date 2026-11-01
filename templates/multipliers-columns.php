<?php
/**
 * Multiplier distribution as vertical bars (share of wins per multiplier range), like the chart on wintracker.io.
 * Override: copy to <theme>/wintracker/multipliers-columns.php.
 *
 * @var array<int, array{label: string, wins: int, hot: bool, zero: bool, share: string, count: string, height: float}> $bars
 * @var int    $total      wins with a known bet
 * @var string $hot_share  share of the highlighted bars, e.g. "0.1%"
 * @var int    $hot_wins
 * @var float  $highlight  multiplier the highlighted bars start at
 * @var bool   $note
 * @var bool   $counts     number of wins under each label
 * @var string $empty
 */

defined( 'ABSPATH' ) || exit;

if ( ! $total ) {
	echo '<p class="wintracker__empty">' . esc_html( $empty ) . '</p>';
	return;
}
?>
<div class="wintracker__mult" style="--wt-cols: <?php echo (int) count( $bars ); ?>">
	<ul class="wintracker__mult-plot">
		<?php foreach ( $bars as $bar ) : ?>
			<?php
			/* translators: 1: multiplier range, 2: number of wins, 3: share, e.g. "2–10×: 1,234 wins (53%)" */
			$wt_tip = sprintf( _n( '%1$s: %2$s win (%3$s)', '%1$s: %2$s wins (%3$s)', $bar['wins'], 'wintracker' ), $bar['label'], number_format_i18n( $bar['wins'] ), wp_strip_all_tags( $bar['share'] ) );
			?>
			<li class="wintracker__mult-col<?php echo $bar['hot'] ? ' is-hot' : ''; ?><?php echo $bar['zero'] ? ' is-zero' : ''; ?>" title="<?php echo esc_attr( $wt_tip ); ?>">
				<span class="wintracker__mult-bar" style="--wt-h: <?php echo esc_attr( (string) $bar['height'] ); ?>%">
					<span class="wintracker__mult-share" aria-hidden="true"><?php echo $bar['zero'] ? '–' : $bar['share']; // phpcs:ignore WordPress.Security.EscapeOutput -- escaped ?></span>
				</span>
				<span class="wintracker__sr"><?php echo esc_html( $wt_tip ); ?></span>
			</li>
		<?php endforeach; ?>
	</ul>
	<div class="wintracker__mult-labels" aria-hidden="true">
		<?php foreach ( $bars as $bar ) : ?>
			<span class="<?php echo $bar['hot'] ? 'is-hot' : ''; ?>">
				<span class="wintracker__mult-range"><?php echo esc_html( $bar['label'] ); ?></span>
				<?php if ( $counts ) : ?>
					<span class="wintracker__mult-count"><?php echo esc_html( $bar['count'] ); ?></span>
				<?php endif; ?>
			</span>
		<?php endforeach; ?>
	</div>
	<?php
	if ( $note ) {
		Wintracker_Render::partial( 'multipliers-note', compact( 'hot_share', 'hot_wins', 'highlight', 'total' ) );
	}
	?>
</div>
