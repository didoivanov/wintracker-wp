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
delete_option( 'wintracker_api_key' );
delete_option( 'wintracker_cache_gen' );
delete_option( 'wintracker_display' );
delete_option( 'wintracker_link_types' );
delete_option( 'wintracker_links' );
delete_option( 'wintracker_links_report' );
delete_post_meta_by_key( '_wintracker_link' );
wp_clear_scheduled_hook( 'wintracker_links_daily' );
wp_clear_scheduled_hook( 'wintracker_links_rebuild' );
delete_site_transient( 'wintracker_update_release' );
