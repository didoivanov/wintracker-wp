<?php
/**
 * The line under the multiplier charts. Override: copy to <theme>/wintracker/multipliers-note.php.
 *
 * @var string $hot_share  share of the highlighted ranges, e.g. "0.1%"
 * @var int    $hot_wins   wins in the highlighted ranges
 * @var float  $highlight  multiplier the highlighted ranges start at
 * @var int    $total      wins with a known bet
 */

defined( 'ABSPATH' ) || exit;

$wt_about = sprintf(
	/* translators: %s: number of wins */
	__( 'Multiplier = win ÷ bet, over %s wins with a known bet. Casinos only publish wins, so small and losing spins are not in here.', 'wintracker' ),
	number_format_i18n( $total )
);
?>
<p class="wintracker__mult-note">
	<?php if ( $hot_wins > 0 ) : ?>
		<?php
		echo wp_kses(
			sprintf(
				/* translators: 1: share of wins (bold), 2: multiplier, e.g. 100 */
				__( '%1$s of wins hit %2$s× or more.', 'wintracker' ),
				'<strong>' . $hot_share . '</strong>',
				number_format_i18n( $highlight )
			),
			array( 'strong' => array() )
		);
		?>
	<?php else : ?>
		<?php
		/* translators: %s: multiplier, e.g. 100 */
		echo esc_html( sprintf( __( 'No wins have reached %s× yet.', 'wintracker' ), number_format_i18n( $highlight ) ) );
		?>
	<?php endif; ?>
	<?php echo esc_html( $wt_about ); ?>
</p>
