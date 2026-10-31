<?php
/**
 * PHP functions for theme templates. The HTML functions return a string: echo it.
 *
 *     echo wintracker_biggest_wins( array( 'casino' => 'bizzo', 'period' => 'week' ) );
 *     echo wintracker_stats( array( 'game' => 685 ) );
 *     $data = wintracker_get_wins( array( 'provider' => 'pragmaticplay', 'sort' => 'multiplier' ) );
 */

defined( 'ABSPATH' ) || exit;

/** Wins widget HTML. Same arguments as [wintracker_wins]. */
function wintracker_wins( array $args = array() ) {
	return Wintracker_Render::wins( $args );
}

/** Latest wins widget HTML. */
function wintracker_latest_wins( array $args = array() ) {
	return Wintracker_Render::wins( array( 'sort' => 'latest' ) + $args );
}

/** Biggest wins (by EUR value) widget HTML. Period defaults to the last 24 h. */
function wintracker_biggest_wins( array $args = array() ) {
	return Wintracker_Render::wins( array( 'sort' => 'amount' ) + $args );
}

/** Top multipliers widget HTML. Period defaults to the last 24 h. */
function wintracker_top_multipliers( array $args = array() ) {
	return Wintracker_Render::wins( array( 'sort' => 'multiplier' ) + $args );
}

/** Stats card HTML. Same arguments as [wintracker_stats], e.g. array( 'casino' => 'bizzo' ). */
function wintracker_stats( array $args = array() ) {
	return Wintracker_Render::stats( $args );
}

/**
 * Raw wins for custom output: array( 'wins' => [...], 'meta' => [...] ), or null when unavailable.
 * Arguments: casino, provider, game, sort, period, limit.
 */
function wintracker_get_wins( array $args = array() ) {
	return Wintracker_Data::wins( $args );
}

/** Raw all-time figures of a casino/provider (slug or id) or game (id), or null. */
function wintracker_get_stats( $type, $id ) {
	return Wintracker_Data::stats( (string) $type, (string) $id );
}

/** Enqueue the stylesheet early, e.g. on wp_enqueue_scripts in a template that calls the functions above. */
function wintracker_enqueue_style() {
	Wintracker_Assets::enqueue();
}
