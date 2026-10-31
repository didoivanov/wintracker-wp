<?php
/**
 * Wins as a compact list, one line per win (sidebars, narrow columns).
 * Override: copy to <theme>/wintracker/wins-list.php.
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
$wt_main = array_values( array_intersect( array( 'game', 'casino', 'provider' ), $columns ) );
$wt_side = array_values( array_diff( $columns, $wt_main ) );
?>
<ol class="wintracker__list">
	<?php foreach ( $wins as $win ) : ?>
		<li class="wintracker__item">
			<span class="wintracker__main">
				<?php
				foreach ( $wt_main as $column ) {
					echo '<span class="wintracker__col-' . esc_attr( $column ) . '">' . Wintracker_Format::win_cell( $win, $column, $currency, $images ) . '</span>'; // phpcs:ignore WordPress.Security.EscapeOutput
				}
				?>
			</span>
			<span class="wintracker__side">
				<?php
				foreach ( $wt_side as $column ) {
					echo '<span class="wintracker__col-' . esc_attr( $column ) . '" title="' . esc_attr( wp_strip_all_tags( Wintracker_Format::label( $column ) ) ) . '">' . Wintracker_Format::win_cell( $win, $column, $currency, $images ) . '</span>'; // phpcs:ignore WordPress.Security.EscapeOutput
				}
				?>
			</span>
		</li>
	<?php endforeach; ?>
</ol>
