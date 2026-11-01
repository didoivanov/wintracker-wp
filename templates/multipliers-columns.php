<?php
/**
 * Multiplier distribution as vertical bars (share of wins per multiplier range), like the chart on wintracker.io.
 * Override: copy to <theme>/wintracker/multipliers-columns.php.
 *
 * @var array<int, array{label: string, wins: int, hot: bool, share: string, height: float}> $bars
 * @var int    $total      wins with a known bet
 * @var string $hot_share  share of the highlighted bars, e.g. "0.1%"
 * @var float  $highlight  multiplier the highlighted bars start at
 * @var bool   $note
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
			<li class="wintracker__mult-col<?php echo $bar['hot'] ? ' is-hot' : ''; ?>" title="<?php echo esc_attr( $wt_tip ); ?>">
				<span class="wintracker__mult-bar" style="--wt-h: <?php echo esc_attr( (string) $bar['height'] ); ?>%">
					<span class="wintracker__mult-share" aria-hidden="true"><?php echo $bar['share']; // phpcs:ignore WordPress.Security.EscapeOutput -- escaped ?></span>
				</span>
				<span class="wintracker__sr"><?php echo esc_html( $wt_tip ); ?></span>
			</li>
		<?php endforeach; ?>
	</ul>
	<div class="wintracker__mult-labels" aria-hidden="true">
		<?php foreach ( $bars as $bar ) : ?>
			<span><?php echo esc_html( $bar['label'] ); ?></span>
		<?php endforeach; ?>
	</div>
	<?php if ( $note ) : ?>
		<p class="wintracker__mult-note">
			<?php
			echo wp_kses(
				sprintf(
					/* translators: 1: share of wins (bold), 2: multiplier, e.g. 100, 3: number of wins */
					__( '%1$s of wins hit %2$s× or more · multiplier = win ÷ bet, over %3$s wins with a known bet. Casinos only publish wins, so small and losing spins are not in here.', 'wintracker' ),
					'<strong>' . $hot_share . '</strong>',
					number_format_i18n( $highlight ),
					number_format_i18n( $total )
				),
				array( 'strong' => array() )
			);
			?>
		</p>
	<?php endif; ?>
</div>
