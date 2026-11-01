<?php
/**
 * Multiplier distribution as horizontal bars, one row per multiplier range (sidebars, narrow columns).
 * Override: copy to <theme>/wintracker/multipliers-rows.php. Variables: see multipliers-columns.php.
 *
 * @var array<int, array{label: string, wins: int, hot: bool, zero: bool, share: string, count: string, height: float}> $bars
 * @var int    $total
 * @var string $hot_share
 * @var int    $hot_wins
 * @var float  $highlight
 * @var bool   $note
 * @var bool   $counts
 * @var string $empty
 */

defined( 'ABSPATH' ) || exit;

if ( ! $total ) {
	echo '<p class="wintracker__empty">' . esc_html( $empty ) . '</p>';
	return;
}
?>
<div class="wintracker__mult">
	<ul class="wintracker__mult-rows">
		<?php foreach ( $bars as $bar ) : ?>
			<?php
			/* translators: 1: multiplier range, 2: number of wins, 3: share, e.g. "2–10×: 1,234 wins (53%)" */
			$wt_tip = sprintf( _n( '%1$s: %2$s win (%3$s)', '%1$s: %2$s wins (%3$s)', $bar['wins'], 'wintracker' ), $bar['label'], number_format_i18n( $bar['wins'] ), wp_strip_all_tags( $bar['share'] ) );
			?>
			<li class="wintracker__mult-row<?php echo $bar['hot'] ? ' is-hot' : ''; ?><?php echo $bar['zero'] ? ' is-zero' : ''; ?>" title="<?php echo esc_attr( $wt_tip ); ?>">
				<span class="wintracker__mult-label">
					<span class="wintracker__mult-range"><?php echo esc_html( $bar['label'] ); ?></span>
					<?php if ( $counts ) : ?>
						<span class="wintracker__mult-count"><?php echo esc_html( $bar['count'] ); ?></span>
					<?php endif; ?>
				</span>
				<span class="wintracker__mult-track"><span class="wintracker__mult-bar" style="--wt-h: <?php echo esc_attr( (string) $bar['height'] ); ?>%"></span></span>
				<span class="wintracker__mult-share"><?php echo $bar['zero'] ? '–' : $bar['share']; // phpcs:ignore WordPress.Security.EscapeOutput -- escaped ?></span>
			</li>
		<?php endforeach; ?>
	</ul>
	<?php
	if ( $note ) {
		Wintracker_Render::partial( 'multipliers-note', compact( 'hot_share', 'hot_wins', 'highlight', 'total' ) );
	}
	?>
</div>
