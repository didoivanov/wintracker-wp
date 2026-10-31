<?php
/**
 * Stats as label/value rows (fact boxes, sidebars).
 * Override: copy to <theme>/wintracker/stats-list.php.
 *
 * @var array<string, mixed> $stats
 * @var string               $type
 * @var string[]             $fields
 */

defined( 'ABSPATH' ) || exit;
?>
<dl class="wintracker__facts">
	<?php foreach ( $fields as $field ) : ?>
		<div class="wintracker__fact wintracker__stat-<?php echo esc_attr( $field ); ?>">
			<dt><?php echo Wintracker_Format::label( $field ); // phpcs:ignore WordPress.Security.EscapeOutput ?></dt>
			<dd><?php echo Wintracker_Format::stat( $stats, $field ); // phpcs:ignore WordPress.Security.EscapeOutput ?></dd>
		</div>
	<?php endforeach; ?>
</dl>
