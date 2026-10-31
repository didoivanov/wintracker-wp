<?php
/**
 * Stats as label/value rows (fact boxes, sidebars), with which win it was under the value.
 * Override: copy to <theme>/wintracker/stats-list.php.
 *
 * @var array<string, mixed>  $stats
 * @var string                $type
 * @var string[]              $fields
 * @var array<string, string> $details
 * @var bool                  $tooltip
 */

defined( 'ABSPATH' ) || exit;
?>
<dl class="wintracker__facts">
	<?php foreach ( $fields as $field ) : ?>
		<?php $wt_detail = $details[ $field ] ?? ''; ?>
		<div class="wintracker__fact wintracker__stat-<?php echo esc_attr( $field ); ?><?php echo '' !== $wt_detail && $tooltip ? ' wintracker__has-tip' : ''; ?>"<?php echo '' !== $wt_detail && $tooltip ? ' tabindex="0"' : ''; ?>>
			<dt><?php echo Wintracker_Format::label( $field ); // phpcs:ignore WordPress.Security.EscapeOutput ?></dt>
			<dd>
				<?php echo Wintracker_Format::stat( $stats, $field ); // phpcs:ignore WordPress.Security.EscapeOutput ?>
				<?php if ( '' !== $wt_detail ) : ?>
					<span class="wintracker__detail"<?php echo $tooltip ? ' role="tooltip"' : ''; ?>><?php echo $wt_detail; // phpcs:ignore WordPress.Security.EscapeOutput ?></span>
				<?php endif; ?>
			</dd>
		</div>
	<?php endforeach; ?>
</dl>
