<?php
/**
 * Wins as a table (stacks into labelled rows on small screens).
 * Override: copy to <theme>/wintracker/wins-table.php.
 *
 * @var array<int, array<string, mixed>> $wins
 * @var array<string, mixed>             $meta
 * @var string[]                         $columns
 * @var string                           $currency original | eur
 * @var bool                             $images
 * @var string                           $empty
 */

defined( 'ABSPATH' ) || exit;

if ( ! $wins ) {
	echo '<p class="wintracker__empty">' . esc_html( $empty ) . '</p>';
	return;
}
?>
<div class="wintracker__scroll">
	<table class="wintracker__table">
		<thead>
			<tr>
				<?php foreach ( $columns as $column ) : ?>
					<th scope="col" class="wintracker__col-<?php echo esc_attr( $column ); ?>"><?php echo Wintracker_Format::label( $column ); // phpcs:ignore WordPress.Security.EscapeOutput ?></th>
				<?php endforeach; ?>
			</tr>
		</thead>
		<tbody>
			<?php foreach ( $wins as $win ) : ?>
				<tr>
					<?php foreach ( $columns as $column ) : ?>
						<td class="wintracker__col-<?php echo esc_attr( $column ); ?>" data-label="<?php echo esc_attr( wp_strip_all_tags( Wintracker_Format::label( $column ) ) ); ?>"><?php echo Wintracker_Format::win_cell( $win, $column, $currency, $images ); // phpcs:ignore WordPress.Security.EscapeOutput ?></td>
					<?php endforeach; ?>
				</tr>
			<?php endforeach; ?>
		</tbody>
	</table>
</div>
