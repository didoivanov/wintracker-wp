<?php
/**
 * Plugin Name:       Wintracker
 * Description:       Shows live casino wins and stats from the Wintracker API (latest wins, biggest wins, top multipliers, stats cards) via shortcodes and PHP functions.
 * Version:           1.5.1
 * Requires at least: 5.5
 * Requires PHP:      7.4
 * Author:            Wintracker
 * License:           GPL-2.0-or-later
 * Text Domain:       wintracker
 * Domain Path:       /languages
 * Update URI:        https://github.com/didoivanov/wintracker-wp
 *
 * API key: Wintracker → Settings, or in wp-config.php (takes precedence):
 *     define( 'WINTRACKER_API_KEY', 'wt_...' );
 */

defined( 'ABSPATH' ) || exit;

define( 'WINTRACKER_VERSION', '1.5.1' );
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
require_once WINTRACKER_DIR . 'includes/class-wintracker-settings.php';
require_once WINTRACKER_DIR . 'includes/class-wintracker-builder.php';
require_once WINTRACKER_DIR . 'includes/class-wintracker-links.php';

// Translations: languages/wintracker-<locale>.mo in the plugin, or wp-content/languages/plugins/ (Loco Translate,
// Poedit, translate.wordpress.org-style files), which takes precedence. Template: languages/wintracker.pot.
add_action(
	'init',
	static function () {
		load_plugin_textdomain( 'wintracker', false, dirname( plugin_basename( WINTRACKER_FILE ) ) . '/languages' );
	}
);

Wintracker_Assets::init();
Wintracker_Shortcodes::init();
Wintracker_Updater::init();
Wintracker_Links::init();
if ( is_admin() ) {
	Wintracker_Builder::init();
	Wintracker_Settings::init();
}

add_action(
	'admin_notices',
	static function () {
		if ( '' !== Wintracker_Client::api_key() || ! current_user_can( 'manage_options' ) ) {
			return;
		}
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		if ( $screen && ! in_array( $screen->id, array( 'dashboard', 'plugins' ), true ) ) {
			return;
		}
		printf(
			'<div class="notice notice-warning"><p><strong>Wintracker:</strong> %s <a href="%s">%s</a></p></div>',
			esc_html__( 'add your API key to show live wins.', 'wintracker' ),
			esc_url( Wintracker_Settings::url() ),
			esc_html__( 'Wintracker → Settings', 'wintracker' )
		);
	}
);
