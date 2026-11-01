<?php
/**
 * [wintracker_wins], its presets [wintracker_latest_wins], [wintracker_biggest_wins],
 * [wintracker_top_multipliers], [wintracker_stats] and [wintracker_multipliers] (multiplier distribution chart).
 * Attributes: see README.md.
 */

defined( 'ABSPATH' ) || exit;

final class Wintracker_Shortcodes {

	/** Shortcode => preset sort (null: stats). */
	const TAGS = array( 'wintracker_wins', 'wintracker_latest_wins', 'wintracker_biggest_wins', 'wintracker_top_multipliers', 'wintracker_stats', 'wintracker_multipliers', 'wintracker_games' );

	const PRESETS = array(
		'wintracker_latest_wins'     => 'latest',
		'wintracker_biggest_wins'    => 'amount',
		'wintracker_top_multipliers' => 'multiplier',
	);

	public static function init() {
		foreach ( self::TAGS as $tag ) {
			add_shortcode( $tag, array( __CLASS__, 'render' ) );
		}
	}

	public static function render( $atts, $content, $tag ) {
		$atts = is_array( $atts ) ? $atts : array();
		if ( 'wintracker_games' === $tag ) {
			return Wintracker_Render::games_grid( shortcode_atts( Wintracker_Render::games_defaults(), $atts, $tag ) );
		}
		if ( 'wintracker_multipliers' === $tag ) {
			return Wintracker_Render::multipliers( shortcode_atts( Wintracker_Render::multipliers_defaults(), $atts, $tag ) );
		}
		if ( 'wintracker_stats' === $tag ) {
			return Wintracker_Render::stats( shortcode_atts( Wintracker_Render::stats_defaults(), $atts, $tag ) );
		}
		$atts = shortcode_atts( Wintracker_Render::wins_defaults(), $atts, $tag );
		if ( isset( self::PRESETS[ $tag ] ) ) {
			$atts['sort'] = self::PRESETS[ $tag ];
		}
		return Wintracker_Render::wins( $atts );
	}
}
