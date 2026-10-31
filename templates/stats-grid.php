<?php
/**
 * Stats as a row of tiles (value large, label small, which win it was underneath).
 * Override: copy to <theme>/wintracker/stats-grid.php.
 *
 * @var array<string, mixed>  $stats   the API row (casinos.php / providers.php / games.php)
 * @var string                $type    casino | provider | game
 * @var string[]              $fields
 * @var array<string, string> $details field => HTML line about the win behind it (biggest_win, top_multiplier, last_win)
 * @var bool                  $tooltip show the details on hover/focus instead of under the value
 */

defined( 'ABSPATH' ) || exit;
?>
<dl class="wintracker__stats">
	<?php foreach ( $fields as $field ) : ?>
		<?php $wt_detail = $details[ $field ] ?? ''; ?>
		<div class="wintracker__stat wintracker__stat-<?php echo esc_attr( $field ); ?><?php echo '' !== $wt_detail && $tooltip ? ' wintracker__has-tip' : ''; ?>"<?php echo '' !== $wt_detail && $tooltip ? ' tabindex="0"' : ''; ?>>
			<dt><?php echo Wintracker_Format::label( $field ); // phpcs:ignore WordPress.Security.EscapeOutput ?></dt>
			<dd><?php echo Wintracker_Format::stat( $stats, $field ); // phpcs:ignore WordPress.Security.EscapeOutput ?></dd>
			<?php if ( '' !== $wt_detail ) : ?>
				<dd class="wintracker__detail"<?php echo $tooltip ? ' role="tooltip"' : ''; ?>><?php echo $wt_detail; // phpcs:ignore WordPress.Security.EscapeOutput ?></dd>
			<?php endif; ?>
		</div>
	<?php endforeach; ?>
</dl>
