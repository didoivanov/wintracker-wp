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
 * post of a linked type is published, renamed or trashed. Manual choices (the Wintracker box on the edit screen,
 * stored as the _wintracker_link post meta: a Wintracker id, "none", or empty for automatic) apply immediately.
 */

defined( 'ABSPATH' ) || exit;

final class Wintracker_Links {

	const TYPES_OPTION  = 'wintracker_link_types';  // [ casino|provider|game => post type ]
	const MAP_OPTION    = 'wintracker_links';       // the matches (front end)
	const REPORT_OPTION = 'wintracker_links_report'; // counts, unlinked and ambiguous names (admin only)
	const META          = '_wintracker_link';
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
	 * ambiguous and never used. Manual choices win; posts marked "none" are never matched.
	 *
	 * @param array<int, array{id: int, name: string, slug?: string|null}> $entities
	 * @param array<int, array{ID: int, post_title: string, post_name: string}> $posts
	 * @param array<int, string> $manual post id => meta value (entity id or "none")
	 * @return array{map: array<int, int>, ambiguous: string[], manual: int}
	 */
	public static function match( $kind, array $entities, array $posts, array $manual ) {
		$index = array( 'exact' => array(), 'loose' => array() );
		foreach ( $posts as $post ) {
			$pid = (int) $post['ID'];
			if ( isset( $manual[ $pid ] ) && '' !== (string) $manual[ $pid ] ) {
				continue; // manually set (to an entity or to "none"): not part of automatic matching
			}
			foreach ( array( $post['post_title'], str_replace( '-', ' ', urldecode( (string) $post['post_name'] ) ) ) as $text ) {
				$index['exact'][ self::key( $text ) ][ $pid ]       = true;
				$index['loose'][ self::key( $text, $kind ) ][ $pid ] = true;
			}
		}

		$map       = array();
		$ambiguous = array();
		foreach ( $entities as $entity ) {
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
				$ambiguous[] = (string) $entity['name'];
			}
		}

		$count = 0;
		foreach ( $manual as $pid => $value ) {
			if ( ctype_digit( (string) $value ) && (int) $value > 0 ) {
				$map[ (int) $value ] = (int) $pid;
				$count++;
			}
		}
		return array(
			'map'       => $map,
			'ambiguous' => $ambiguous,
			'manual'    => $count,
		);
	}

	/** Rebuilds the whole map (cron). Keeps the previous map for a kind whose Wintracker list can't be fetched. */
	public static function rebuild() {
		$types = self::types();
		if ( ! array_filter( $types ) ) {
			return;
		}
		if ( function_exists( 'set_time_limit' ) ) {
			@set_time_limit( 300 ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
		}
		$old    = get_option( self::MAP_OPTION, array() );
		$old    = is_array( $old ) ? $old : array();
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
			$result       = self::match( $kind, $entities, self::posts( $post_type ), self::manual( $post_type ) );
			$map[ $kind ] = $result['map'];
			$linked       = array_flip( array_keys( $result['map'] ) );
			$unlinked     = array();
			foreach ( $entities as $e ) {
				if ( ! isset( $linked[ (int) $e['id'] ] ) && 'game' !== $kind ) {
					$unlinked[] = (string) $e['name']; // games: only the count (there are thousands)
				}
			}
			$report['kinds'][ $kind ] = array(
				'post_type' => $post_type,
				'entities'  => count( $entities ),
				'linked'    => count( $result['map'] ),
				'manual'    => $result['manual'],
				'ambiguous' => array_slice( $result['ambiguous'], 0, 50 ),
				'unlinked'  => array_slice( $unlinked, 0, 200 ),
			);
		}
		update_option( self::MAP_OPTION, $map, false );
		update_option( self::REPORT_OPTION, $report, false );
		self::$map = null;
	}

	/** Every Wintracker casino, provider or game: id, name, slug. Null when the API can't be reached. */
	private static function entities( $kind ) {
		if ( 'game' !== $kind ) {
			$response = Wintracker_Client::fetch( 'casino' === $kind ? 'casinos' : 'providers' );
			if ( null === $response ) {
				return null;
			}
			return array_map(
				static function ( $r ) {
					return array(
						'id'   => (int) $r['id'],
						'name' => (string) $r['name'],
						'slug' => $r['slug'] ?? null,
					);
				},
				$response['data']
			);
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
				$games[] = array(
					'id'   => (int) $g['id'],
					'name' => (string) $g['name'],
				);
			}
			if ( count( $response['data'] ) < 100 || count( $games ) >= (int) ( $response['meta']['total'] ?? 0 ) ) {
				break;
			}
		}
		return $games;
	}

	/** Published posts of a type: ID, title, slug only. */
	private static function posts( $post_type ) {
		global $wpdb;
		return $wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->prepare( "SELECT ID, post_title, post_name FROM {$wpdb->posts} WHERE post_type = %s AND post_status = 'publish'", $post_type ),
			ARRAY_A
		);
	}

	/** @return array<int, string> post id => manual choice, for posts of a type that have one */
	private static function manual( $post_type ) {
		global $wpdb;
		$rows = $wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->prepare(
				"SELECT p.ID, m.meta_value FROM {$wpdb->postmeta} m JOIN {$wpdb->posts} p ON p.ID = m.post_id
				 WHERE m.meta_key = %s AND m.meta_value <> '' AND p.post_type = %s AND p.post_status = 'publish'",
				self::META,
				$post_type
			),
			ARRAY_A
		);
		$out = array();
		foreach ( $rows as $row ) {
			$out[ (int) $row['ID'] ] = (string) $row['meta_value'];
		}
		return $out;
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

	/** A post of a linked type was published, unpublished or trashed. */
	public static function post_changed( $new, $old, $post ) {
		if ( $new !== $old && ( 'publish' === $new || 'publish' === $old ) && self::kind_of( $post->post_type ) ) {
			self::rebuild_soon();
		}
	}

	public static function post_renamed( $post_id, $after, $before ) {
		if ( 'publish' === $after->post_status && self::kind_of( $after->post_type )
			&& ( $after->post_title !== $before->post_title || $after->post_name !== $before->post_name ) ) {
			self::rebuild_soon();
		}
	}

	/* ------------------------------------------------------------------ manual matching (edit screen) */

	public static function add_meta_box( $post_type ) {
		$kind = self::kind_of( $post_type );
		if ( $kind ) {
			add_meta_box( 'wintracker-link', 'Wintracker', array( __CLASS__, 'meta_box' ), $post_type, 'side', 'default', array( 'kind' => $kind ) );
		}
	}

	public static function meta_box( $post, $box ) {
		$kind    = $box['args']['kind'];
		$value   = (string) get_post_meta( $post->ID, self::META, true );
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
			echo '<p class="description">' . wp_kses_post(
				sprintf(
					/* translators: %s: link to the shortcode builder */
					__( 'Find a game id with the game search in the %s.', 'wintracker' ),
					'<a href="' . esc_url( Wintracker_Builder::url() ) . '" target="_blank">' . esc_html__( 'shortcode builder', 'wintracker' ) . '</a>'
				)
			) . '</p>';
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
	}

	/** The Wintracker id currently linked to a post (reverse lookup in the map), or 0. */
	public static function linked_to( $kind, $post_id ) {
		$map = get_option( self::MAP_OPTION, array() );
		$id  = is_array( $map ) && isset( $map[ $kind ] ) ? array_search( (int) $post_id, $map[ $kind ], true ) : false;
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
		$old = (string) get_post_meta( $post_id, self::META, true );
		if ( $value === $old ) {
			return;
		}
		if ( '' === $value ) {
			delete_post_meta( $post_id, self::META );
		} else {
			update_post_meta( $post_id, self::META, $value );
		}
		self::apply_manual( $kind, (int) $post_id, $value, 'publish' === $post->post_status );
	}

	/** Puts a manual choice into the map right away; "automatic" waits for the next rebuild. */
	private static function apply_manual( $kind, $post_id, $value, $published ) {
		$map = get_option( self::MAP_OPTION, array() );
		$map = is_array( $map ) ? $map : array();
		$map[ $kind ] = array_filter(
			$map[ $kind ] ?? array(),
			static function ( $pid ) use ( $post_id ) {
				return (int) $pid !== $post_id;
			}
		);
		if ( $published && ctype_digit( $value ) ) {
			$map[ $kind ][ (int) $value ] = $post_id;
		}
		update_option( self::MAP_OPTION, $map, false );
		self::$map = null;
		if ( '' === $value ) {
			self::rebuild_soon( 10 );
		}
	}
}
