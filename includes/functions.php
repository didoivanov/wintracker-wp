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

/** Multiplier distribution chart HTML. Same arguments as [wintracker_multipliers], e.g. array( 'game' => 717 ). */
function wintracker_multipliers( array $args = array() ) {
	return Wintracker_Render::multipliers( $args );
}

/**
 * Raw multiplier distribution of a casino/provider (slug or id) or game (id): array( 'total' => wins with a known
 * bet, 'bands' => [ [ 'from' => 1, 'to' => 2, 'wins' => 15328 ], … ], 'meta' => … ), or null.
 */
function wintracker_get_multipliers( $type, $id ) {
	return Wintracker_Data::multipliers( (string) $type, (string) $id );
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

/**
 * The Wintracker id a page is linked to (Wintracker → Links), or 0.
 *
 *     $game_id = wintracker_linked_id();            // the current post, kind from its post type
 *     $game_id = wintracker_linked_id( 'game', 42 ); // a given post
 *
 * @param string               $kind casino | provider | game; '' = the kind linked to the post's type
 * @param int|WP_Post|null     $post defaults to the current post
 */
function wintracker_linked_id( $kind = '', $post = null ) {
	$post = get_post( $post );
	if ( ! $post || 'publish' !== $post->post_status ) {
		return 0;
	}
	$kind = '' !== $kind ? $kind : (string) Wintracker_Links::kind_of( $post->post_type );
	if ( ! in_array( $kind, Wintracker_Links::KINDS, true ) ) {
		return 0;
	}
	return Wintracker_Links::linked_to( $kind, $post->ID );
}

/**
 * True when widget HTML shows nothing to visitors: empty, or only the HTML comment editors get when there is no
 * data (API down with no saved copy, unknown id). Use it to leave out a whole section around a widget.
 */
function wintracker_is_empty( $html ) {
	return '' === trim( (string) preg_replace( '/<!--.*?-->/s', '', (string) $html ) );
}
