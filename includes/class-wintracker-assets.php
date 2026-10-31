<?php
/**
 * The stylesheet loads only on pages that show a Wintracker widget.
 *
 * - Singular posts whose content has a Wintracker shortcode get it in <head>.
 * - Anywhere else (PHP function in a template, shortcode in a widget) it is enqueued while the widget renders
 *   and WordPress prints it in the footer. To get it in <head> there too, call wintracker_enqueue_style()
 *   from the template before get_header(), or hook it to wp_enqueue_scripts on those pages.
 * - add_filter( 'wintracker_load_css', '__return_false' ) turns it off to style the widgets in the theme.
 */

defined( 'ABSPATH' ) || exit;

final class Wintracker_Assets {

	const HANDLE = 'wintracker';

	public static function init() {
		add_action( 'wp_enqueue_scripts', array( __CLASS__, 'maybe_enqueue' ) );
	}

	public static function maybe_enqueue() {
		if ( ! is_singular() ) {
			return;
		}
		$post = get_post();
		if ( ! $post ) {
			return;
		}
		foreach ( Wintracker_Shortcodes::TAGS as $tag ) {
			if ( has_shortcode( $post->post_content, $tag ) ) {
				self::enqueue();
				return;
			}
		}
	}

	public static function enqueue() {
		if ( ! apply_filters( 'wintracker_load_css', true ) || wp_style_is( self::HANDLE, 'enqueued' ) ) {
			return;
		}
		wp_enqueue_style( self::HANDLE, plugins_url( 'assets/wintracker.css', WINTRACKER_FILE ), array(), WINTRACKER_VERSION );
	}
}
