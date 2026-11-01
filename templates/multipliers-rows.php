<?php
/**
 * Multiplier distribution as horizontal bars, one row per multiplier range (sidebars, narrow columns).
 * Override: copy to <theme>/wintracker/multipliers-rows.php. Variables: see multipliers-columns.php.
 *
 * @var array<int, array{label: string, wins: int, hot: bool, share: string, height: float}> $bars
 * @var int    $total
 * @var string $hot_share
 * @var float  $highlight
 * @var bool   $note
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
			<li class="wintracker__mult-row<?php echo $bar['hot'] ? ' is-hot' : ''; ?>" title="<?php echo esc_attr( $wt_tip ); ?>">
				<span class="wintracker__mult-label"><?php echo esc_html( $bar['label'] ); ?></span>
				<span class="wintracker__mult-track"><span class="wintracker__mult-bar" style="--wt-h: <?php echo esc_attr( (string) $bar['height'] ); ?>%"></span></span>
				<span class="wintracker__mult-share"><?php echo $bar['share']; // phpcs:ignore WordPress.Security.EscapeOutput -- escaped ?></span>
			</li>
		<?php endforeach; ?>
	</ul>
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
