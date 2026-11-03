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
	const ENDPOINTS = array( 'wins', 'casinos', 'providers', 'games', 'multipliers' );

	/** Seconds a fresh response is kept, per endpoint (all-time figures change slowly). */
	const TTL = array(
		'multipliers' => 600,
		'games'       => 600,
	);
	const PREFIX    = 'wintracker_';

	/** @var array{code: string, message: string}|null the last error, for an HTML comment shown to editors */
	private static $last_error = null;

	/** @var array<string, array{0: string, 1: array<string, mixed>}> responses to refresh after the page is sent */
	private static $queue = array();

	/** @var array{cached: int, stale: int, live: int, live_time: float, background: int} what this request did, for the admin note */
	public static $stats = array(
		'cached'     => 0,
		'stale'      => 0,
		'live'       => 0,
		'live_time'  => 0.0,
		'background' => 0,
	);

	/**
	 * Returns the decoded response ({data, meta}) of an endpoint. Never makes a visitor wait for the API when there
	 * is something to show:
	 *
	 * - fresh cache (default 5 minutes, Wintracker → Settings) → that;
	 * - expired, but a saved copy exists → the saved copy right away, and the response is refreshed after the page
	 *   has been sent to the visitor (stale-while-revalidate);
	 * - nothing saved yet (a widget's very first view) → one API call, at most 3 seconds.
	 *
	 * Null when there is nothing to show.
	 *
	 * @param string               $endpoint wins | casinos | providers | games | multipliers
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
		$key = self::key( $endpoint, $params );

		$fresh = get_transient( self::PREFIX . 'f_' . $key );
		if ( is_array( $fresh ) ) {
			self::$stats['cached']++;
			// A cached client error (e.g. unknown casino slug) stays cached for a minute.
			return isset( $fresh['error'] ) ? self::fail( $fresh['error']['code'], $fresh['error']['message'] ) : $fresh;
		}
		$snapshot = get_transient( self::PREFIX . 's_' . $key );
		$snapshot = is_array( $snapshot ) ? $snapshot : null;

		if ( '' === self::api_key() ) {
			return self::fail( 'missing_key', __( 'No API key: add it under Wintracker → Settings (or define WINTRACKER_API_KEY in wp-config.php).', 'wintracker' ), $snapshot );
		}
		if ( null !== $snapshot ) {
			// Show the saved copy now; fetch the new one once the visitor has the page.
			self::$stats['stale']++;
			self::enqueue( $key, $endpoint, $params );
			return $snapshot;
		}
		if ( get_transient( self::PREFIX . 'paused' ) ) {
			return self::fail( 'paused', __( 'The Wintracker API failed recently; calls are paused for a minute and the last good data (if any) is shown.', 'wintracker' ) );
		}
		// Nothing saved yet: this visitor waits once, briefly.
		return self::request( $endpoint, $params, $key, (int) apply_filters( 'wintracker_timeout', 3 ) );
	}

	/** The cache key of a request (the generation changes on "Clear cache", so old entries are skipped and expire). */
	private static function key( $endpoint, array $params ) {
		return md5( (int) get_option( 'wintracker_cache_gen', 0 ) . '|' . $endpoint . '?' . http_build_query( $params ) );
	}

	/** Queues a refresh for after the response (once per entry, not while another request refreshes it). */
	private static function enqueue( $key, $endpoint, array $params ) {
		if ( isset( self::$queue[ $key ] ) || get_transient( self::PREFIX . 'l_' . $key ) || get_transient( self::PREFIX . 'paused' ) ) {
			return;
		}
		if ( ! self::$queue ) {
			if ( function_exists( 'fastcgi_finish_request' ) || function_exists( 'litespeed_finish_request' ) ) {
				register_shutdown_function( array( __CLASS__, 'run_queue' ) );
			} else {
				// No way to finish the response early (e.g. mod_php): refresh in a WP-Cron request instead.
				add_action( 'shutdown', array( __CLASS__, 'defer_queue' ) );
			}
		}
		self::$queue[ $key ] = array( $endpoint, $params );
		set_transient( self::PREFIX . 'l_' . $key, 1, 60 ); // others serve the saved copy meanwhile
	}

	/** At shutdown: send the page to the visitor, then refresh the queued responses (at most 10, about 20 s). */
	public static function run_queue() {
		if ( ! self::$queue ) {
			return;
		}
		if ( function_exists( 'fastcgi_finish_request' ) ) {
			fastcgi_finish_request();
		} elseif ( function_exists( 'litespeed_finish_request' ) ) {
			litespeed_finish_request();
		}
		ignore_user_abort( true );
		self::refresh_all( self::$queue );
		self::$queue = array();
	}

	/** Without fastcgi/litespeed_finish_request: hand the queue to a WP-Cron event (a separate request). */
	public static function defer_queue() {
		if ( self::$queue ) {
			wp_schedule_single_event( time(), 'wintracker_refresh', array( array_values( self::$queue ) ) );
			if ( function_exists( 'spawn_cron' ) ) {
				spawn_cron();
			}
			self::$queue = array();
		}
	}

	/**
	 * Refreshes queued responses (shutdown or the wintracker_refresh cron event).
	 *
	 * @param array<int|string, array{0: string, 1: array<string, mixed>}> $items
	 */
	public static function refresh_all( $items ) {
		$deadline = microtime( true ) + 20;
		foreach ( array_slice( (array) $items, 0, 10 ) as $item ) {
			if ( microtime( true ) > $deadline || get_transient( self::PREFIX . 'paused' ) ) {
				break;
			}
			list( $endpoint, $params ) = $item;
			if ( in_array( $endpoint, self::ENDPOINTS, true ) && is_array( $params ) ) {
				self::$stats['background']++;
				self::request( $endpoint, $params, self::key( $endpoint, $params ), 10 );
			}
		}
	}

	/**
	 * One API call; stores the response (fresh + saved copy) or the error. Returns the response, the saved copy on
	 * an outage, or null.
	 */
	private static function request( $endpoint, array $params, $key, $timeout ) {
		$lock = self::PREFIX . 'l_' . $key;
		set_transient( $lock, 1, 60 );
		$started  = microtime( true );
		$response = wp_remote_get(
			self::BASE_URL . $endpoint . '.php' . ( $params ? '?' . http_build_query( $params ) : '' ),
			array(
				'timeout'    => max( 1, (int) $timeout ),
				'headers'    => array(
					'X-Api-Key' => self::api_key(),
					'Accept'    => 'application/json',
				),
				'user-agent' => 'Wintracker-WP/' . WINTRACKER_VERSION . '; ' . home_url( '/' ),
			)
		);
		self::$stats['live']++;
		self::$stats['live_time'] += microtime( true ) - $started;
		delete_transient( $lock );
		$snapshot = get_transient( self::PREFIX . 's_' . $key );
		$snapshot = is_array( $snapshot ) ? $snapshot : null;

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
			set_transient( self::PREFIX . 'f_' . $key, $body, self::ttl( $endpoint ) );
			set_transient( self::PREFIX . 's_' . $key, $body, (int) apply_filters( 'wintracker_snapshot_ttl', WEEK_IN_SECONDS ) );
			return $body;
		}

		$code    = isset( $body['error']['code'] ) ? (string) $body['error']['code'] : 'http_' . $status;
		$message = isset( $body['error']['message'] ) ? (string) $body['error']['message'] : __( 'Unexpected response from the Wintracker API.', 'wintracker' );

		// The request itself is wrong (bad slug, game id, parameter): cache the error, don't fall back.
		if ( in_array( $status, array( 400, 404 ), true ) ) {
			set_transient( self::PREFIX . 'f_' . $key, array( 'error' => compact( 'code', 'message' ) ), MINUTE_IN_SECONDS );
			delete_transient( self::PREFIX . 's_' . $key );
			return self::fail( $code, $message );
		}

		// Anything else (401, 429, 5xx, garbage) is the API's or the key's problem: pause and use the saved copy.
		$retry = (int) wp_remote_retrieve_header( $response, 'retry-after' );
		return self::outage( $code, $message, $snapshot, $retry );
	}

	/** Minutes between refreshes (Wintracker → Settings; 1, 5, 15 or 30; default 5). */
	public static function refresh_minutes() {
		$minutes = (int) get_option( 'wintracker_refresh', 5 );
		return in_array( $minutes, array( 1, 5, 15, 30 ), true ) ? $minutes : 5;
	}

	/** For logged-in admins, at the end of the page: what the widgets on it cost. */
	public static function timing_note() {
		$s = self::$stats;
		if ( ! ( $s['cached'] + $s['stale'] + $s['live'] ) || ! is_user_logged_in() || ! current_user_can( 'manage_options' ) ) {
			return;
		}
		printf(
			"\n<!-- Wintracker: %d from cache, %d saved copies (refreshed after the page), %d live API calls during the page (%.2f s) -->\n",
			(int) $s['cached'],
			(int) $s['stale'],
			(int) $s['live'],
			(float) $s['live_time']
		);
	}

	/** @return array{code: string, message: string}|null */
	public static function last_error() {
		return self::$last_error;
	}

	/** The key from wp-config.php (WINTRACKER_API_KEY) if defined, else the one saved under Wintracker → Settings. */
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
	 * One API call without the transient cache, for background jobs that page through whole lists
	 * (the link builder) and must not fill the cache with pages nobody shows.
	 *
	 * @return array<string, mixed>|null the decoded response, or null on any failure
	 */
	public static function fetch( $endpoint, array $params = array() ) {
		$api_key = self::api_key();
		if ( '' === $api_key || ! in_array( $endpoint, self::ENDPOINTS, true ) ) {
			return null;
		}
		$response = wp_remote_get(
			self::BASE_URL . $endpoint . '.php' . ( $params ? '?' . http_build_query( $params ) : '' ),
			array(
				'timeout'    => 20,
				'headers'    => array(
					'X-Api-Key' => $api_key,
					'Accept'    => 'application/json',
				),
				'user-agent' => 'Wintracker-WP/' . WINTRACKER_VERSION . '; ' . home_url( '/' ),
			)
		);
		if ( is_wp_error( $response ) || 200 !== (int) wp_remote_retrieve_response_code( $response ) ) {
			return null;
		}
		$body = json_decode( (string) wp_remote_retrieve_body( $response ), true );
		return is_array( $body ) && isset( $body['data'] ) && is_array( $body['data'] ) ? $body : null;
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
	private static function ttl( $endpoint = '' ) {
		$base    = self::refresh_minutes() * MINUTE_IN_SECONDS;
		$default = max( $base, self::TTL[ $endpoint ] ?? 0 );
		return max( MINUTE_IN_SECONDS, (int) apply_filters( 'wintracker_cache_ttl', $default, $endpoint ) );
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
