<?php
/**
 * Plugin Name:       Wintracker
 * Description:       Shows live casino wins and stats from the Wintracker API (latest wins, biggest wins, top multipliers, stats cards) via shortcodes and PHP functions.
 * Version:           1.1.0
 * Requires at least: 5.8
 * Requires PHP:      7.4
 * Author:            Wintracker
 * License:           GPL-2.0-or-later
 * Text Domain:       wintracker
 * Update URI:        https://github.com/didoivanov/wintracker-wp
 *
 * Configure the API key in wp-config.php:
 *     define( 'WINTRACKER_API_KEY', 'wt_...' );
 */

defined( 'ABSPATH' ) || exit;

define( 'WINTRACKER_VERSION', '1.1.0' );
define( 'WINTRACKER_FILE', __FILE__ );
define( 'WINTRACKER_DIR', plugin_dir_path( __FILE__ ) );

require_once WINTRACKER_DIR . 'includes/class-wintracker-client.php';
require_once WINTRACKER_DIR . 'includes/class-wintracker-data.php';
require_once WINTRACKER_DIR . 'includes/class-wintracker-format.php';
require_once WINTRACKER_DIR . 'includes/class-wintracker-render.php';
require_once WINTRACKER_DIR . 'includes/class-wintracker-assets.php';
require_once WINTRACKER_DIR . 'includes/class-wintracker-shortcodes.php';
require_once WINTRACKER_DIR . 'includes/functions.php';
require_once WINTRACKER_DIR . 'includes/class-wintracker-updater.php';

Wintracker_Assets::init();
Wintracker_Shortcodes::init();
Wintracker_Updater::init();

add_action(
	'admin_notices',
	static function () {
		if ( defined( 'WINTRACKER_API_KEY' ) && WINTRACKER_API_KEY || ! current_user_can( 'manage_options' ) ) {
			return;
		}
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		if ( $screen && ! in_array( $screen->id, array( 'dashboard', 'plugins' ), true ) ) {
			return;
		}
		echo '<div class="notice notice-warning"><p>'
			. wp_kses_post( __( '<strong>Wintracker:</strong> add <code>define( \'WINTRACKER_API_KEY\', \'wt_…\' );</code> to wp-config.php. Until then the Wintracker widgets show nothing.', 'wintracker' ) )
			. '</p></div>';
	}
);
