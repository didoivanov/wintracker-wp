<?php
/**
 * Validates widget arguments and turns API responses into the data the templates show.
 */

defined( 'ABSPATH' ) || exit;

final class Wintracker_Data {

	const SORTS   = array( 'latest', 'amount', 'multiplier' );
	const PERIODS = array( 'day', 'week', 'month', 'all' );
	const TYPES   = array( 'casino', 'provider', 'game' );

	/**
	 * Wins of a casino, provider and/or game (or all casinos).
	 *
	 * @param array<string, mixed> $args casino, provider, game, sort, period, limit
	 * @return array{wins: array<int, array<string, mixed>>, meta: array<string, mixed>}|null null when unavailable
	 */
	public static function wins( array $args ) {
		$sort   = in_array( $args['sort'] ?? '', self::SORTS, true ) ? $args['sort'] : 'latest';
		$period = in_array( $args['period'] ?? '', self::PERIODS, true ) ? $args['period'] : null; // null: the API's default.
		$params = array(
			'casino'   => self::slug( $args['casino'] ?? '' ),
			'provider' => self::slug( $args['provider'] ?? '' ),
			'game'     => self::game_id( $args['game'] ?? '' ),
			'sort'     => $sort,
			'period'   => $period,
			'limit'    => max( 1, min( 100, (int) ( $args['limit'] ?? 10 ) ?: 10 ) ),
		);
		$response = Wintracker_Client::get( 'wins', $params );
		if ( null === $response ) {
			return null;
		}
		return array(
			'wins' => array_values( array_filter( $response['data'], 'is_array' ) ),
			'meta' => $response['meta'],
		);
	}

	/**
	 * All-time figures of one casino, provider or game.
	 *
	 * @param string $type casino | provider | game
	 * @param string $id   casino/provider slug or id, game id
	 * @return array<string, mixed>|null the API's row (wins, total_won_eur, biggest_win_eur, top_multiplier,
	 *                                   last_win_at, plus name, and for games rtp, volatility, provider, image_url)
	 */
	public static function stats( $type, $id ) {
		if ( 'game' === $type ) {
			return self::game_stats( self::game_id( $id ) );
		}
		if ( ! in_array( $type, array( 'casino', 'provider' ), true ) || '' === ( $id = self::slug( $id ) ) ) {
			return null;
		}
		// Both lists are short (tens to a few hundred rows) and shared by every casino/provider page.
		$response = Wintracker_Client::get( 'casino' === $type ? 'casinos' : 'providers' );
		if ( null === $response ) {
			return null;
		}
		foreach ( $response['data'] as $row ) {
			if ( isset( $row['id'] ) && ( (string) $row['id'] === $id || ( $row['slug'] ?? null ) === $id ) ) {
				return $row;
			}
		}
		return null;
	}

	/**
	 * The games endpoint has no id filter, so: the game's name from wins.php?game=ID (which also follows
	 * merged games), then the row with that id from games.php?q=NAME. Both responses are cached.
	 */
	private static function game_stats( $game_id ) {
		if ( '' === $game_id ) {
			return null;
		}
		$wins = Wintracker_Client::get(
			'wins',
			array(
				'game'  => $game_id,
				'limit' => 1,
			)
		);
		if ( null === $wins || empty( $wins['meta']['game']['name'] ) ) {
			return null;
		}
		$game  = $wins['meta']['game'];
		$games = Wintracker_Client::get(
			'games',
			array(
				'q'     => $game['name'],
				'limit' => 100,
			)
		);
		foreach ( null !== $games ? $games['data'] : array() as $row ) {
			if ( isset( $row['id'] ) && (int) $row['id'] === (int) $game['id'] ) {
				return $row;
			}
		}
		return null;
	}

	/** A casino/provider slug or numeric id, or ''. */
	public static function slug( $value ) {
		$value = strtolower( trim( (string) $value ) );
		return preg_match( '/^[a-z0-9][a-z0-9_-]{0,99}$/', $value ) ? $value : '';
	}

	/** A game id, or ''. */
	public static function game_id( $value ) {
		$value = trim( (string) $value );
		return ctype_digit( $value ) && (int) $value > 0 ? (string) (int) $value : '';
	}
}
