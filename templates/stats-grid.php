<?php
/**
 * Stats as a row of tiles (value large, label small).
 * Override: copy to <theme>/wintracker/stats-grid.php.
 *
 * @var array<string, mixed> $stats  the API row (casinos.php / providers.php / games.php)
 * @var string               $type   casino | provider | game
 * @var string[]             $fields
 */

defined( 'ABSPATH' ) || exit;
?>
<dl class="wintracker__stats">
	<?php foreach ( $fields as $field ) : ?>
		<div class="wintracker__stat wintracker__stat-<?php echo esc_attr( $field ); ?>">
			<dt><?php echo Wintracker_Format::label( $field ); // phpcs:ignore WordPress.Security.EscapeOutput ?></dt>
			<dd><?php echo Wintracker_Format::stat( $stats, $field ); // phpcs:ignore WordPress.Security.EscapeOutput ?></dd>
		</div>
	<?php endforeach; ?>
</dl>
