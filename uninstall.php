<?php
/**
 * Removes the plugin's cached responses and snapshots.
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

global $wpdb;
$wpdb->query(
	$wpdb->prepare(
		"DELETE FROM {$wpdb->options} WHERE option_name LIKE %s OR option_name LIKE %s",
		$wpdb->esc_like( '_transient_wintracker_' ) . '%',
		$wpdb->esc_like( '_transient_timeout_wintracker_' ) . '%'
	)
);
