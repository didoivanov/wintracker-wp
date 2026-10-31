<?php
/**
 * Optional links from Wintracker names (casinos, providers, games) to the site's own pages.
 *
 * Settings (Wintracker → Settings → Links to your pages) pick one post type per kind. A background job matches
 * the Wintracker lists against those posts by name/slug and stores only the matches, in one option:
 *
 *     wintracker_links = [ 'casino' => [ wintracker id => post id ], 'provider' => [...], 'game' => [...] ]
 *
 * Front-end cost per widget: that option (one read, cached for the request) plus one batched posts query for the
 * linked posts' permalinks. Nothing is matched while a visitor waits.
 *
 * The map is rebuilt: when the settings are saved or "Rebuild links" is clicked, once a day, and ~2 minutes after a
 * post of a linked type is published, renamed or trashed (that post is also matched on the spot). Manual choices
 * (Wintracker → Links, or the Wintracker box on the edit screen) apply immediately and are kept in
 * wintracker_links_manual (per Wintracker item: a post id, or 0 = never link); a page that must never be linked
 * has the _wintracker_link post meta "none".
 */

defined( 'ABSPATH' ) || exit;

final class Wintracker_Links {

	const TYPES_OPTION  = 'wintracker_link_types';  // [ casino|provider|game => post type ]
	const MAP_OPTION    = 'wintracker_links';       // the matches (front end)
	const REPORT_OPTION = 'wintracker_links_report'; // counts, unlinked and ambiguous names (admin only)
	const MANUAL_OPTION = 'wintracker_links_manual'; // [ kind => [ wintracker id => post id, or 0 = never link ] ]
	const META          = '_wintracker_link';        // "none" on a page that must never be linked
	const CRON_DAILY    = 'wintracker_links_daily';
	const CRON_SOON     = 'wintracker_links_rebuild';
	const KINDS         = array( 'casino', 'provider', 'game' );

	/** @var array<string, array<int, int>>|null */
	private static $map = null;

	public static function init() {
		add_action( self::CRON_DAILY, array( __CLASS__, 'rebuild' ) );
		add_action( self::CRON_SOON, array( __CLASS__, 'rebuild' ) );
		add_action( 'init', array( __CLASS__, 'schedule_daily' ) );
		add_action( 'transition_post_status', array( __CLASS__, 'post_changed' ), 10, 3 );
		add_action( 'post_updated', array( __CLASS__, 'post_renamed' ), 10, 3 );
		if ( is_admin() ) {
			add_action( 'admin_init', array( __CLASS__, 'maybe_migrate' ) );
			add_action( 'add_meta_boxes', array( __CLASS__, 'add_meta_box' ) );
			add_action( 'save_post', array( __CLASS__, 'save_meta_box' ), 10, 2 );
		}
	}

	/** @return array<string, string> kind => post type ('' = not linked) */
	public static function types() {
		$saved = get_option( self::TYPES_OPTION, array() );
		$types = array();
		foreach ( self::KINDS as $kind ) {
			$type           = is_array( $saved ) && isset( $saved[ $kind ] ) ? (string) $saved[ $kind ] : '';
			$types[ $kind ] = '' !== $type && post_type_exists( $type ) ? $type : '';
		}
		return $types;
	}

	public static function enabled() {
		return (bool) array_filter( self::types() );
	}

	public static function save_types( array $types ) {
		$clean = array();
		foreach ( self::KINDS as $kind ) {
			$type           = isset( $types[ $kind ] ) ? sanitize_key( $types[ $kind ] ) : '';
			$clean[ $kind ] = '' !== $type && post_type_exists( $type ) ? $type : '';
		}
		update_option( self::TYPES_OPTION, $clean, false );
		if ( ! array_filter( $clean ) ) {
			wp_clear_scheduled_hook( self::CRON_DAILY );
			update_option( self::MAP_OPTION, array(), false );
			delete_option( self::REPORT_OPTION );
			self::$map = null;
		}
		return $clean;
	}

	/* ------------------------------------------------------------------ front end */

	/** The post id linked to a Wintracker casino/provider/game id, or 0. */
	public static function post_id( $kind, $id ) {
		if ( null === self::$map ) {
			$map       = get_option( self::MAP_OPTION, array() );
			self::$map = is_array( $map ) ? $map : array();
		}
		return (int) ( self::$map[ $kind ][ (int) $id ] ?? 0 );
	}

	/** The URL to link a name to, or '' (no match, or the page being viewed). */
	public static function url( $kind, $id ) {
		$post_id = self::post_id( $kind, $id );
		if ( ! $post_id || ( is_singular() && get_queried_object_id() === $post_id ) ) {
			return '';
		}
		$post = get_post( $post_id );
		$url  = $post && 'publish' === $post->post_status ? get_permalink( $post ) : '';
		return (string) apply_filters( 'wintracker_link_url', $url ? $url : '', $kind, (int) $id, $post_id );
	}

	/** Wraps already-escaped HTML in a link when the name has a page on the site. */
	public static function wrap( $kind, $id, $html ) {
		$url = $id ? self::url( $kind, $id ) : '';
		return '' !== $url ? '<a class="wintracker__link" href="' . esc_url( $url ) . '">' . $html . '</a>' : $html;
	}

	/**
	 * Loads the posts the given wins link to in one query, so building their permalinks costs nothing more.
	 *
	 * @param array<int, array<string, mixed>> $wins
	 */
	public static function prime( array $wins ) {
		$ids = array();
		foreach ( $wins as $win ) {
			foreach ( self::KINDS as $kind ) {
				if ( ! empty( $win[ $kind ]['id'] ) ) {
					$ids[] = self::post_id( $kind, $win[ $kind ]['id'] );
				}
			}
		}
		$ids = array_values(
			array_filter(
				array_unique( $ids ),
				static function ( $id ) {
					return $id && false === wp_cache_get( $id, 'posts' ); // already loaded by an earlier widget or the page
				}
			)
		);
		if ( $ids ) {
			get_posts(
				array(
					'post__in'               => $ids,
					'post_type'              => 'any',
					'post_status'            => 'publish',
					'posts_per_page'         => count( $ids ),
					'no_found_rows'          => true,
					'ignore_sticky_posts'    => true,
					'update_post_meta_cache' => false,
					'suppress_filters'       => false,
				)
			);
		}
	}

	/* ------------------------------------------------------------------ matching */

	/** Words left out when comparing names loosely, per kind (filter: wintracker_link_stopwords). */
	public static function stopwords( $kind ) {
		$words = array(
			'casino'   => array( 'casino', 'casinos', 'review', 'reviews', 'online', 'bonus', 'bonuses', 'login', 'the' ),
			'provider' => array( 'slots', 'slot', 'games', 'gaming', 'review', 'reviews', 'provider', 'software', 'studios', 'studio', 'the' ),
			'game'     => array( 'slot', 'slots', 'review', 'reviews', 'online', 'demo', 'free', 'game', 'the' ),
		);
		return (array) apply_filters( 'wintracker_link_stopwords', $words[ $kind ] ?? array(), $kind );
	}

	/** Comparable form of a name or slug: lowercase ASCII letters/digits only, optionally without stopwords. */
	public static function key( $text, $kind = null ) {
		$text  = html_entity_decode( (string) $text, ENT_QUOTES, 'UTF-8' );
		$text  = strtolower( remove_accents( $text ) );
		$text  = str_replace( array( '&', '+' ), array( ' and ', ' plus ' ), $text );
		$words = preg_split( '/[^a-z0-9]+/', $text, -1, PREG_SPLIT_NO_EMPTY );
		if ( null !== $kind ) {
			$loose = array_values( array_diff( $words, self::stopwords( $kind ) ) );
			$words = $loose ? $loose : $words; // never strip a name down to nothing
		}
		return implode( '', $words );
	}

	/**
	 * Matches entities to posts. Exact name/slug first, then loose (no stopwords); a key shared by several posts is
	 * ambiguous and never used. Manual choices win; excluded posts and posts chosen by hand are left out of
	 * automatic matching.
	 *
	 * @param array<int, array{id: int, name: string, slug?: string|null}>          $entities
	 * @param array<int, array{ID: int, post_title: string, post_name: string}>  $posts    published posts
	 * @param array<int, bool>                                                     $excluded post id => true ("don't link this page")
	 * @param array<int, int>                                                      $manual   wintracker id => post id, or 0 = never link
	 * @return array{map: array<int, int>, ambiguous: string[], ambiguous_ids: int[], manual: int}
	 */
	public static function match( $kind, array $entities, array $posts, array $excluded = array(), array $manual = array() ) {
		$taken     = array_flip( array_filter( array_map( 'intval', $manual ) ) );
		$published = array();
		$index     = array(
			'exact' => array(),
			'loose' => array(),
		);
		foreach ( $posts as $post ) {
			$pid               = (int) $post['ID'];
			$published[ $pid ] = true;
			if ( isset( $excluded[ $pid ] ) || isset( $taken[ $pid ] ) ) {
				continue;
			}
			foreach ( array( $post['post_title'], str_replace( '-', ' ', urldecode( (string) $post['post_name'] ) ) ) as $text ) {
				$index['exact'][ self::key( $text ) ][ $pid ]        = true;
				$index['loose'][ self::key( $text, $kind ) ][ $pid ] = true;
			}
		}

		$map           = array();
		$ambiguous     = array();
		$ambiguous_ids = array();
		foreach ( $entities as $entity ) {
			if ( array_key_exists( (int) $entity['id'], $manual ) ) {
				continue;
			}
			$texts = array_filter( array( $entity['name'] ?? '', isset( $entity['slug'] ) ? str_replace( '-', ' ', (string) $entity['slug'] ) : '' ) );
			$found = 0;
			$clash = false;
			foreach ( array( 'exact', 'loose' ) as $pass ) {
				foreach ( $texts as $text ) {
					$key = 'exact' === $pass ? self::key( $text ) : self::key( $text, $kind );
					$ids = '' !== $key && isset( $index[ $pass ][ $key ] ) ? array_keys( $index[ $pass ][ $key ] ) : array();
					if ( 1 === count( $ids ) ) {
						$found = $ids[0];
						break 2;
					}
					$clash = $clash || count( $ids ) > 1;
				}
			}
			if ( $found ) {
				$map[ (int) $entity['id'] ] = $found;
			} elseif ( $clash ) {
				$ambiguous[]     = (string) $entity['name'];
				$ambiguous_ids[] = (int) $entity['id'];
			}
		}

		$count = 0;
		foreach ( $manual as $id => $pid ) {
			if ( (int) $pid > 0 ) {
				$count++;
				if ( isset( $published[ (int) $pid ] ) ) {
					$map[ (int) $id ] = (int) $pid;
				}
			}
		}
		return array(
			'map'           => $map,
			'ambiguous'     => $ambiguous,
			'ambiguous_ids' => $ambiguous_ids,
			'manual'        => $count,
		);
	}

	/** Rebuilds the whole map. Keeps the previous map for a kind whose Wintracker list can't be fetched. */
	public static function rebuild() {
		$types = self::types();
		if ( ! array_filter( $types ) ) {
			return;
		}
		if ( function_exists( 'set_time_limit' ) ) {
			@set_time_limit( 300 ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
		}
		$old    = self::raw_map();
		$manual = self::manual();
		$map    = array();
		$report = array(
			'built' => time(),
			'kinds' => array(),
		);
		foreach ( $types as $kind => $post_type ) {
			if ( '' === $post_type ) {
				continue;
			}
			$entities = self::entities( $kind );
			if ( null === $entities ) {
				$map[ $kind ]             = $old[ $kind ] ?? array();
				$report['kinds'][ $kind ] = array( 'error' => true );
				continue;
			}
			$result       = self::match( $kind, $entities, self::posts( $post_type ), self::excluded( $post_type ), $manual[ $kind ] ?? array() );
			$map[ $kind ] = $result['map'];
			$unlinked     = array();
			foreach ( $entities as $e ) {
				if ( ! isset( $result['map'][ (int) $e['id'] ] ) && 'game' !== $kind ) {
					$unlinked[] = (string) $e['name']; // games: only the count (there are thousands)
				}
			}
			$report['kinds'][ $kind ] = array(
				'post_type'     => $post_type,
				'entities'      => count( $entities ),
				'linked'        => count( $result['map'] ),
				'manual'        => $result['manual'],
				'ambiguous'     => array_slice( $result['ambiguous'], 0, 50 ),
				'ambiguous_ids' => array_slice( $result['ambiguous_ids'], 0, 1000 ),
				'unlinked'      => array_slice( $unlinked, 0, 200 ),
			);
		}
		self::save_map( $map );
		update_option( self::REPORT_OPTION, $report, false );
	}

	/** Every Wintracker casino, provider or game: id, name, slug. Null when the API can't be reached. */
	private static function entities( $kind ) {
		if ( 'game' !== $kind ) {
			$response = Wintracker_Client::fetch( 'casino' === $kind ? 'casinos' : 'providers' );
			if ( null === $response ) {
				return null;
			}
			return array_map( array( __CLASS__, 'entity_row' ), $response['data'] );
		}
		$games = array();
		for ( $offset = 0; $offset < 20000; $offset += 100 ) {
			$response = Wintracker_Client::fetch(
				'games',
				array(
					'limit'  => 100,
					'offset' => $offset,
				)
			);
			if ( null === $response ) {
				return null;
			}
			foreach ( $response['data'] as $g ) {
				$games[] = self::entity_row( $g );
			}
			if ( count( $response['data'] ) < 100 || count( $games ) >= (int) ( $response['meta']['total'] ?? 0 ) ) {
				break;
			}
		}
		return $games;
	}

	/** @param array<string, mixed> $r an API row */
	private static function entity_row( $r ) {
		return array(
			'id'   => (int) $r['id'],
			'name' => (string) $r['name'],
			'slug' => isset( $r['slug'] ) ? (string) $r['slug'] : null,
		);
	}

	/** One Wintracker item by id (from cached API responses), or null. */
	public static function entity( $kind, $id ) {
		$id = (int) $id;
		if ( 'game' === $kind ) {
			$r = Wintracker_Client::get(
				'wins',
				array(
					'game'  => $id,
					'limit' => 1,
				)
			);
			return ! empty( $r['meta']['game']['name'] ) ? array(
				'id'   => $id,
				'name' => (string) $r['meta']['game']['name'],
				'slug' => null,
			) : null;
		}
		$r = Wintracker_Client::get( 'casino' === $kind ? 'casinos' : 'providers' );
		foreach ( $r ? $r['data'] : array() as $row ) {
			if ( (int) $row['id'] === $id ) {
				return self::entity_row( $row );
			}
		}
		return null;
	}

	/** Published posts of a type: ID, title, slug only. */
	private static function posts( $post_type ) {
		global $wpdb;
		return $wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->prepare( "SELECT ID, post_title, post_name FROM {$wpdb->posts} WHERE post_type = %s AND post_status = 'publish'", $post_type ),
			ARRAY_A
		);
	}

	/** @return array<int, bool> published posts of a type marked "don't link this page" */
	private static function excluded( $post_type ) {
		global $wpdb;
		$ids = $wpdb->get_col( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->prepare(
				"SELECT p.ID FROM {$wpdb->postmeta} m JOIN {$wpdb->posts} p ON p.ID = m.post_id
				 WHERE m.meta_key = %s AND m.meta_value = 'none' AND p.post_type = %s AND p.post_status = 'publish'",
				self::META,
				$post_type
			)
		);
		return array_fill_keys( array_map( 'intval', $ids ), true );
	}

	/* ------------------------------------------------------------------ manual choices */

	/** @return array<string, array<int, int>> kind => [ wintracker id => post id, or 0 = never link ] */
	public static function manual() {
		$manual = get_option( self::MANUAL_OPTION, array() );
		return is_array( $manual ) ? $manual : array();
	}

	/** @return array<string, array<int, int>> */
	public static function raw_map() {
		$map = get_option( self::MAP_OPTION, array() );
		return is_array( $map ) ? $map : array();
	}

	private static function save_map( array $map ) {
		update_option( self::MAP_OPTION, $map, false );
		self::$map = null;
	}

	private static function is_published( $post_id ) {
		$post = get_post( (int) $post_id );
		return $post && 'publish' === $post->post_status;
	}

	/**
	 * Where an item stands: state manual (linked by hand), blocked (never link), auto, ambiguous or none, and the post.
	 *
	 * @return array{state: string, post: int}
	 */
	public static function status( $kind, $id ) {
		$id     = (int) $id;
		$manual = self::manual()[ $kind ] ?? array();
		$map    = self::raw_map()[ $kind ] ?? array();
		if ( array_key_exists( $id, $manual ) ) {
			return array(
				'state' => (int) $manual[ $id ] > 0 ? 'manual' : 'blocked',
				'post'  => (int) $manual[ $id ],
			);
		}
		if ( isset( $map[ $id ] ) ) {
			return array(
				'state' => 'auto',
				'post'  => (int) $map[ $id ],
			);
		}
		$report = get_option( self::REPORT_OPTION, array() );
		$amb    = (array) ( $report['kinds'][ $kind ]['ambiguous_ids'] ?? array() );
		return array(
			'state' => in_array( $id, array_map( 'intval', $amb ), true ) ? 'ambiguous' : 'none',
			'post'  => 0,
		);
	}

	/**
	 * Sets an item by hand: a post id (link to it), 0 (never link) or null (back to automatic). One page belongs to
	 * one item: an item that had this page by hand loses it and goes back to automatic. Applies immediately.
	 */
	public static function set_manual( $kind, $id, $value ) {
		$id    = (int) $id;
		$all   = self::manual();
		$list  = $all[ $kind ] ?? array();
		$freed = array();
		if ( null === $value ) {
			unset( $list[ $id ] );
		} else {
			$value = max( 0, (int) $value );
			if ( $value > 0 ) {
				foreach ( $list as $eid => $pid ) {
					if ( (int) $pid === $value && (int) $eid !== $id ) {
						unset( $list[ $eid ] );
						$freed[] = (int) $eid;
					}
				}
				delete_post_meta( $value, self::META );
			}
			$list[ $id ] = $value;
		}
		$all[ $kind ] = $list;
		update_option( self::MANUAL_OPTION, $all, false );

		$map = self::raw_map();
		$m   = $map[ $kind ] ?? array();
		unset( $m[ $id ] );
		if ( null !== $value && $value > 0 ) {
			foreach ( $m as $eid => $pid ) {
				if ( (int) $pid === $value ) {
					unset( $m[ $eid ] ); // the page now belongs to this item only
				}
			}
			if ( self::is_published( $value ) ) {
				$m[ $id ] = $value;
			}
		}
		$map[ $kind ] = $m;
		self::save_map( $map );

		foreach ( null === $value ? array_merge( array( $id ), $freed ) : $freed as $eid ) {
			self::auto_one( $kind, $eid );
		}
	}

	/** Re-runs automatic matching for one item (all published posts of the type, one query). */
	public static function auto_one( $kind, $id ) {
		$type   = self::types()[ $kind ] ?? '';
		$entity = '' !== $type ? self::entity( $kind, $id ) : null;
		$map    = self::raw_map();
		unset( $map[ $kind ][ (int) $id ] );
		if ( $entity ) {
			$manual = self::manual()[ $kind ] ?? array();
			unset( $manual[ (int) $id ] );
			$result = self::match( $kind, array( $entity ), self::posts( $type ), self::excluded( $type ), $manual );
			if ( isset( $result['map'][ (int) $id ] ) ) {
				$map[ $kind ][ (int) $id ] = $result['map'][ (int) $id ];
			}
		}
		self::save_map( $map );
	}

	/** "Don't link this page" on or off. */
	public static function exclude_post( $kind, $post_id, $on ) {
		$post_id = (int) $post_id;
		if ( $on ) {
			update_post_meta( $post_id, self::META, 'none' );
			$all = self::manual();
			foreach ( $all[ $kind ] ?? array() as $eid => $pid ) {
				if ( (int) $pid === $post_id ) {
					unset( $all[ $kind ][ $eid ] );
				}
			}
			update_option( self::MANUAL_OPTION, $all, false );
			self::unlink_post( $kind, $post_id );
		} else {
			delete_post_meta( $post_id, self::META );
			$post = get_post( $post_id );
			if ( $post ) {
				self::match_post( $post );
			}
		}
	}

	/** Removes a post from the map (unpublished, trashed, renamed or excluded). Manual choices stay. */
	private static function unlink_post( $kind, $post_id ) {
		$map = self::raw_map();
		foreach ( $map[ $kind ] ?? array() as $eid => $pid ) {
			if ( (int) $pid === (int) $post_id ) {
				unset( $map[ $kind ][ $eid ] );
			}
		}
		self::save_map( $map );
	}

	/**
	 * Matches one newly published or renamed post right away, against cached Wintracker lists (games: a name
	 * search), so links appear without waiting for a rebuild. Items already linked elsewhere are left alone.
	 */
	public static function match_post( $post ) {
		$kind = self::kind_of( $post->post_type );
		if ( ! $kind || 'publish' !== $post->post_status || 'none' === get_post_meta( $post->ID, self::META, true ) ) {
			return;
		}
		$manual = self::manual()[ $kind ] ?? array();
		$chosen = array_keys( array_map( 'intval', $manual ), (int) $post->ID, true );
		if ( $chosen ) {
			// Chosen by hand (perhaps while still a draft): link it now that it is published.
			$map = self::raw_map();
			foreach ( $chosen as $eid ) {
				$map[ $kind ][ (int) $eid ] = (int) $post->ID;
			}
			self::save_map( $map );
			return;
		}
		if ( 'game' === $kind ) {
			$words = preg_split( '/[^a-z0-9]+/', strtolower( remove_accents( wp_strip_all_tags( $post->post_title ) ) ), -1, PREG_SPLIT_NO_EMPTY );
			$words = array_diff( $words, self::stopwords( 'game' ) );
			$found = $words ? Wintracker_Client::get(
				'games',
				array(
					'q'     => implode( ' ', $words ),
					'limit' => 20,
				)
			) : null;
		} else {
			$found = Wintracker_Client::get( 'casino' === $kind ? 'casinos' : 'providers' );
		}
		$entities = array_map( array( __CLASS__, 'entity_row' ), $found ? $found['data'] : array() );
		$result   = self::match(
			$kind,
			$entities,
			array(
				array(
					'ID'         => $post->ID,
					'post_title' => $post->post_title,
					'post_name'  => $post->post_name,
				),
			),
			array(),
			$manual
		);
		$map = self::raw_map();
		foreach ( $result['map'] as $eid => $pid ) {
			if ( ! array_key_exists( $eid, $manual ) && ! isset( $map[ $kind ][ $eid ] ) ) {
				$map[ $kind ][ $eid ] = (int) $pid;
			}
		}
		self::save_map( $map );
	}

	/** 1.5.x stored manual links as post meta (a Wintracker id); move them to wintracker_links_manual. */
	public static function maybe_migrate() {
		if ( (int) get_option( 'wintracker_links_schema', 1 ) >= 2 ) {
			return;
		}
		global $wpdb;
		$rows = $wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->prepare(
				"SELECT p.ID, p.post_type, m.meta_value FROM {$wpdb->postmeta} m JOIN {$wpdb->posts} p ON p.ID = m.post_id WHERE m.meta_key = %s",
				self::META
			),
			ARRAY_A
		);
		$all = self::manual();
		foreach ( (array) $rows as $row ) {
			$kind = self::kind_of( $row['post_type'] );
			if ( $kind && ctype_digit( (string) $row['meta_value'] ) && (int) $row['meta_value'] > 0 ) {
				$all[ $kind ][ (int) $row['meta_value'] ] = (int) $row['ID'];
				delete_post_meta( (int) $row['ID'], self::META );
			}
		}
		update_option( self::MANUAL_OPTION, $all, false );
		update_option( 'wintracker_links_schema', 2 );
	}

	/* ------------------------------------------------------------------ keeping it current */

	public static function schedule_daily() {
		if ( self::enabled() && ! wp_next_scheduled( self::CRON_DAILY ) ) {
			wp_schedule_event( time() + HOUR_IN_SECONDS, 'daily', self::CRON_DAILY );
		}
	}

	/** Rebuild in the background shortly (several changes in a row cause one rebuild). */
	public static function rebuild_soon( $delay = 120 ) {
		if ( self::enabled() && ! wp_next_scheduled( self::CRON_SOON ) ) {
			wp_schedule_single_event( time() + (int) $delay, self::CRON_SOON );
		}
	}

	public static function kind_of( $post_type ) {
		$kind = array_search( $post_type, self::types(), true );
		return false === $kind ? null : $kind;
	}

	/** A post of a linked type was published, unpublished or trashed: update it now, rebuild later. */
	public static function post_changed( $new, $old, $post ) {
		$kind = self::kind_of( $post->post_type );
		if ( ! $kind || $new === $old || ( 'publish' !== $new && 'publish' !== $old ) ) {
			return;
		}
		if ( 'publish' === $new ) {
			self::match_post( $post );
		} else {
			self::unlink_post( $kind, $post->ID );
		}
		self::rebuild_soon();
	}

	public static function post_renamed( $post_id, $after, $before ) {
		$kind = self::kind_of( $after->post_type );
		if ( $kind && 'publish' === $after->post_status
			&& ( $after->post_title !== $before->post_title || $after->post_name !== $before->post_name ) ) {
			$manual = self::manual()[ $kind ] ?? array();
			if ( ! in_array( (int) $post_id, array_map( 'intval', $manual ), true ) ) {
				self::unlink_post( $kind, $post_id );
				self::match_post( $after );
			}
			self::rebuild_soon();
		}
	}

	/* ------------------------------------------------------------------ edit screen box */

	public static function add_meta_box( $post_type ) {
		$kind = self::kind_of( $post_type );
		if ( $kind ) {
			add_meta_box( 'wintracker-link', 'Wintracker', array( __CLASS__, 'meta_box' ), $post_type, 'side', 'default', array( 'kind' => $kind ) );
		}
	}

	/** The item chosen by hand for a post, or 0. */
	private static function manual_for_post( $kind, $post_id ) {
		$id = array_search( (int) $post_id, array_map( 'intval', self::manual()[ $kind ] ?? array() ), true );
		return false === $id ? 0 : (int) $id;
	}

	public static function meta_box( $post, $box ) {
		$kind    = $box['args']['kind'];
		$chosen  = self::manual_for_post( $kind, $post->ID );
		$value   = 'none' === get_post_meta( $post->ID, self::META, true ) ? 'none' : ( $chosen ? (string) $chosen : '' );
		$current = self::linked_to( $kind, $post->ID );
		$name    = $current ? '#' . $current : '';
		wp_nonce_field( 'wintracker_link_' . $post->ID, 'wintracker_link_nonce' );
		$labels = array(
			'casino'   => __( 'Wintracker casino shown as this page', 'wintracker' ),
			'provider' => __( 'Wintracker provider shown as this page', 'wintracker' ),
			'game'     => __( 'Wintracker game shown as this page', 'wintracker' ),
		);
		echo '<p><label for="wintracker-link">' . esc_html( $labels[ $kind ] ) . '</label></p>';

		if ( 'game' === $kind ) {
			$is_id = ctype_digit( $value );
			echo '<select id="wintracker-link-mode" name="wintracker_link_mode" style="width:100%">';
			echo '<option value=""' . selected( '', $is_id ? 'id' : $value, false ) . '>' . esc_html__( 'Automatic (match by name)', 'wintracker' ) . '</option>';
			echo '<option value="id"' . selected( $is_id, true, false ) . '>' . esc_html__( 'This game id:', 'wintracker' ) . '</option>';
			echo '<option value="none"' . selected( 'none', $value, false ) . '>' . esc_html__( 'Don\'t link this page', 'wintracker' ) . '</option>';
			echo '</select>';
			echo '<p><input type="number" min="1" id="wintracker-link" name="wintracker_link_game" value="' . esc_attr( $is_id ? $value : '' ) . '" placeholder="685" style="width:100%"></p>';
		} else {
			$list = Wintracker_Client::get( 'casino' === $kind ? 'casinos' : 'providers' );
			$rows = $list ? $list['data'] : array();
			usort(
				$rows,
				static function ( $a, $b ) {
					return strcasecmp( (string) $a['name'], (string) $b['name'] );
				}
			);
			echo '<select id="wintracker-link" name="wintracker_link" style="width:100%">';
			echo '<option value="">' . esc_html__( 'Automatic (match by name)', 'wintracker' ) . '</option>';
			echo '<option value="none"' . selected( 'none', $value, false ) . '>' . esc_html__( 'Don\'t link this page', 'wintracker' ) . '</option>';
			$found = false;
			foreach ( $rows as $row ) {
				$found = $found || (string) $row['id'] === $value;
				$name  = (int) $row['id'] === $current ? (string) $row['name'] : $name;
				echo '<option value="' . esc_attr( (string) $row['id'] ) . '"' . selected( (string) $row['id'], $value, false ) . '>' . esc_html( $row['name'] ) . '</option>';
			}
			if ( ctype_digit( $value ) && ! $found ) {
				/* translators: %s: Wintracker id */
				echo '<option value="' . esc_attr( $value ) . '" selected>' . esc_html( sprintf( __( 'Id %s (list unavailable)', 'wintracker' ), $value ) ) . '</option>';
			}
			echo '</select>';
		}

		if ( $current ) {
			/* translators: %s: Wintracker casino/provider name, or #game id */
			echo '<p>' . esc_html( sprintf( __( 'Currently linked to %s.', 'wintracker' ), $name ) ) . '</p>';
		} elseif ( 'none' !== $value ) {
			echo '<p>' . esc_html__( 'Not linked at the moment.', 'wintracker' ) . '</p>';
		}
		if ( current_user_can( Wintracker_Links_Editor::CAP ) ) {
			echo '<p><a href="' . esc_url( Wintracker_Links_Editor::url( $kind ) ) . '">' . esc_html__( 'All links in Wintracker → Links', 'wintracker' ) . '</a></p>';
		}
	}

	/** The Wintracker id currently linked to a post (reverse lookup in the map), or 0. */
	public static function linked_to( $kind, $post_id ) {
		$map = self::raw_map();
		$id  = isset( $map[ $kind ] ) ? array_search( (int) $post_id, array_map( 'intval', $map[ $kind ] ), true ) : false;
		return false === $id ? 0 : (int) $id;
	}

	public static function save_meta_box( $post_id, $post ) {
		$kind = self::kind_of( $post->post_type );
		if ( ! $kind || ! isset( $_POST['wintracker_link_nonce'] ) || wp_is_post_revision( $post_id ) || ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE )
			|| ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['wintracker_link_nonce'] ) ), 'wintracker_link_' . $post_id )
			|| ! current_user_can( 'edit_post', $post_id ) ) {
			return;
		}
		if ( 'game' === $kind ) {
			$mode  = isset( $_POST['wintracker_link_mode'] ) ? sanitize_key( wp_unslash( $_POST['wintracker_link_mode'] ) ) : '';
			$game  = isset( $_POST['wintracker_link_game'] ) ? absint( $_POST['wintracker_link_game'] ) : 0;
			$value = 'none' === $mode ? 'none' : ( 'id' === $mode && $game ? (string) $game : '' );
		} else {
			$raw   = isset( $_POST['wintracker_link'] ) ? sanitize_text_field( wp_unslash( $_POST['wintracker_link'] ) ) : '';
			$value = 'none' === $raw ? 'none' : ( ctype_digit( $raw ) && (int) $raw > 0 ? (string) (int) $raw : '' );
		}
		$chosen = self::manual_for_post( $kind, $post_id );
		$was    = 'none' === get_post_meta( $post_id, self::META, true ) ? 'none' : ( $chosen ? (string) $chosen : '' );
		if ( $value === $was ) {
			return;
		}
		if ( 'none' === $value ) {
			self::exclude_post( $kind, $post_id, true );
			foreach ( $chosen ? array( $chosen ) : array() as $eid ) {
				self::auto_one( $kind, $eid );
			}
		} elseif ( '' === $value ) {
			if ( $chosen ) {
				self::set_manual( $kind, $chosen, null );
			}
			self::exclude_post( $kind, $post_id, false );
		} else {
			self::set_manual( $kind, (int) $value, (int) $post_id );
		}
	}
}
