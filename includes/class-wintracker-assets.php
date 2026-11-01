<?php
/**
 * The stylesheet loads only on pages that show a Wintracker widget.
 *
 * - Singular posts whose content has a Wintracker shortcode get it in <head>.
 * - Anywhere else (PHP function in a template, shortcode in a widget) it is enqueued while the widget renders
 *   and WordPress prints it in the footer. To get it in <head> there too, call wintracker_enqueue_style()
 *   from the template before get_header(), or hook it to wp_enqueue_scripts on those pages.
 * - add_filter( 'wintracker_load_css', '__return_false' ) turns it off to style the widgets in the theme.
 * - "Protect widgets from theme styles" (Display defaults) loads wintracker-isolated.css instead (built from
 *   wintracker.css by bin/build-css.php), and the site's custom CSS is added after it.
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
		$display = Wintracker_Render::display();
		$file    = $display['isolate'] ? 'assets/wintracker-isolated.css' : 'assets/wintracker.css';
		wp_enqueue_style( self::HANDLE, plugins_url( $file, WINTRACKER_FILE ), array(), WINTRACKER_VERSION );
		if ( '' !== trim( $display['css'] ) ) {
			wp_add_inline_style( self::HANDLE, $display['css'] );
		}
	}
}
