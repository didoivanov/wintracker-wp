<?php
/**
 * Server-side client for the Wintracker API (docs/API.md in didoivanov/wintracker).
 *
 * Caching, per request (endpoint + parameters):
 * - "fresh" transient, 60 s minimum (the API refreshes its figures at most once a minute);
 * - "snapshot" transient: the last good response, kept for 7 days by default and overwritten on every
 *   successful call, so there is one small entry per widget configuration and nothing piles up.
 *   It is served while the API is down; when there is no snapshot either, the widget renders nothing.
 *
 * When the API fails (network error, 5xx, 401, 429, bad JSON) all calls pause for a minute and serve
 * snapshots, so an outage costs one slow request per minute instead of one per page view.
 */

defined( 'ABSPATH' ) || exit;

final class Wintracker_Client {

	const BASE_URL  = 'https://wintracker.io/api/v1/';
	const ENDPOINTS = array( 'wins', 'casinos', 'providers', 'games' );
	const PREFIX    = 'wintracker_';

	/** @var array{code: string, message: string}|null the last error, for an HTML comment shown to editors */
	private static $last_error = null;

	/**
	 * Returns the decoded response ({data, meta}) of an endpoint, from cache when possible,
	 * or the last good snapshot when the API is unavailable. Null when there is nothing to show.
	 *
	 * @param string               $endpoint wins | casinos | providers | games
	 * @param array<string, mixed> $params   query parameters
	 * @return array<string, mixed>|null
	 */
	public static function get( $endpoint, array $params = array() ) {
		self::$last_error = null;
		if ( ! in_array( $endpoint, self::ENDPOINTS, true ) ) {
			return self::fail( 'bad_endpoint', "Unknown endpoint {$endpoint}." );
		}
		$params = array_filter(
			$params,
			static function ( $v ) {
				return null !== $v && '' !== $v;
			}
		);
		ksort( $params );
		// The cache generation changes on "Clear cache", so every old entry is skipped and simply expires.
		$key = md5( (int) get_option( 'wintracker_cache_gen', 0 ) . '|' . $endpoint . '?' . http_build_query( $params ) );

		$fresh = get_transient( self::PREFIX . 'f_' . $key );
		if ( is_array( $fresh ) ) {
			// A cached client error (e.g. unknown casino slug) stays cached for the same minute.
			return isset( $fresh['error'] ) ? self::fail( $fresh['error']['code'], $fresh['error']['message'] ) : $fresh;
		}
		$snapshot = get_transient( self::PREFIX . 's_' . $key );
		$snapshot = is_array( $snapshot ) ? $snapshot : null;

		$api_key = self::api_key();
		if ( '' === $api_key ) {
			return self::fail( 'missing_key', 'No API key: add it under Settings → Wintracker (or define WINTRACKER_API_KEY in wp-config.php).', $snapshot );
		}
		if ( get_transient( self::PREFIX . 'paused' ) ) {
			return self::fail( 'paused', 'The API failed recently; calls are paused for a minute and the last snapshot (if any) is shown.', $snapshot );
		}
		// Another request is refreshing this entry right now: serve the snapshot instead of piling on.
		$lock = self::PREFIX . 'l_' . $key;
		if ( null !== $snapshot && get_transient( $lock ) ) {
			return $snapshot;
		}
		set_transient( $lock, 1, 30 );

		$response = wp_remote_get(
			self::BASE_URL . $endpoint . '.php' . ( $params ? '?' . http_build_query( $params ) : '' ),
			array(
				'timeout'    => (int) apply_filters( 'wintracker_timeout', 5 ),
				'headers'    => array(
					'X-Api-Key' => $api_key,
					'Accept'    => 'application/json',
				),
				'user-agent' => 'Wintracker-WP/' . WINTRACKER_VERSION . '; ' . home_url( '/' ),
			)
		);
		delete_transient( $lock );

		if ( is_wp_error( $response ) ) {
			return self::outage( 'http_error', $response->get_error_message(), $snapshot );
		}
		$status = (int) wp_remote_retrieve_response_code( $response );
		$body   = json_decode( (string) wp_remote_retrieve_body( $response ), true );

		if ( 200 === $status && is_array( $body ) && isset( $body['data'] ) && is_array( $body['data'] ) ) {
			$body = array(
				'data' => $body['data'],
				'meta' => isset( $body['meta'] ) && is_array( $body['meta'] ) ? $body['meta'] : array(),
			);
			set_transient( self::PREFIX . 'f_' . $key, $body, self::ttl() );
			set_transient( self::PREFIX . 's_' . $key, $body, (int) apply_filters( 'wintracker_snapshot_ttl', WEEK_IN_SECONDS ) );
			return $body;
		}

		$code    = isset( $body['error']['code'] ) ? (string) $body['error']['code'] : 'http_' . $status;
		$message = isset( $body['error']['message'] ) ? (string) $body['error']['message'] : 'Unexpected response from the Wintracker API.';

		// The request itself is wrong (bad slug, game id, parameter): cache the error, don't fall back.
		if ( in_array( $status, array( 400, 404 ), true ) ) {
			set_transient( self::PREFIX . 'f_' . $key, array( 'error' => compact( 'code', 'message' ) ), self::ttl() );
			return self::fail( $code, $message );
		}

		// Anything else (401, 429, 5xx, garbage) is the API's or the key's problem: pause and use the snapshot.
		$retry = (int) wp_remote_retrieve_header( $response, 'retry-after' );
		return self::outage( $code, $message, $snapshot, $retry );
	}

	/** @return array{code: string, message: string}|null */
	public static function last_error() {
		return self::$last_error;
	}

	/** The key from wp-config.php (WINTRACKER_API_KEY) if defined, else the one saved under Settings → Wintracker. */
	public static function api_key() {
		if ( self::key_in_config() ) {
			return trim( (string) WINTRACKER_API_KEY );
		}
		return trim( (string) get_option( 'wintracker_api_key', '' ) );
	}

	public static function key_in_config() {
		return defined( 'WINTRACKER_API_KEY' ) && '' !== trim( (string) WINTRACKER_API_KEY );
	}

	/**
	 * Calls the API once without any cache, to check a key.
	 *
	 * @return array{ok: bool, message: string}
	 */
	public static function test( $api_key ) {
		$response = wp_remote_get(
			self::BASE_URL . 'casinos.php',
			array(
				'timeout'    => 10,
				'headers'    => array(
					'X-Api-Key' => (string) $api_key,
					'Accept'    => 'application/json',
				),
				'user-agent' => 'Wintracker-WP/' . WINTRACKER_VERSION . '; ' . home_url( '/' ),
			)
		);
		if ( is_wp_error( $response ) ) {
			return array(
				'ok'      => false,
				'message' => $response->get_error_message(),
			);
		}
		$status = (int) wp_remote_retrieve_response_code( $response );
		$body   = json_decode( (string) wp_remote_retrieve_body( $response ), true );
		if ( 200 === $status && isset( $body['data'] ) && is_array( $body['data'] ) ) {
			return array(
				'ok'      => true,
				/* translators: %d: number of casinos */
				'message' => sprintf( _n( 'Connected: the API tracks %d casino.', 'Connected: the API tracks %d casinos.', count( $body['data'] ), 'wintracker' ), count( $body['data'] ) ),
			);
		}
		return array(
			'ok'      => false,
			'message' => "HTTP {$status}: " . ( $body['error']['message'] ?? __( 'unexpected response', 'wintracker' ) ),
		);
	}

	/** Drops every cached response and snapshot (they expire on their own) and lifts an outage pause. */
	public static function flush() {
		update_option( 'wintracker_cache_gen', (int) get_option( 'wintracker_cache_gen', 0 ) + 1 );
		delete_transient( self::PREFIX . 'paused' );
	}

	/** Seconds a response is considered fresh: 60 minimum, as the API refreshes once a minute. */
	private static function ttl() {
		return max( MINUTE_IN_SECONDS, (int) apply_filters( 'wintracker_cache_ttl', MINUTE_IN_SECONDS ) );
	}

	private static function outage( $code, $message, $snapshot, $retry_after = 0 ) {
		$pause = min( 15 * MINUTE_IN_SECONDS, max( MINUTE_IN_SECONDS, (int) $retry_after ) );
		set_transient( self::PREFIX . 'paused', 1, $pause );
		error_log( "[wintracker] API unavailable ({$code}: {$message}); pausing calls for {$pause}s." ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions
		return self::fail( $code, $message, $snapshot );
	}

	private static function fail( $code, $message, $fallback = null ) {
		self::$last_error = array(
			'code'    => (string) $code,
			'message' => (string) $message,
		);
		return $fallback;
	}

}
