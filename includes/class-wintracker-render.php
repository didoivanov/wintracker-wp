<?php
/**
 * Turns widget arguments (from a shortcode or a PHP call) into HTML.
 *
 * Templates live in templates/ and can be overridden by copying them to <theme>/wintracker/<name>.php.
 */

defined( 'ABSPATH' ) || exit;

final class Wintracker_Render {

	const WIN_LAYOUTS  = array( 'table', 'list', 'cards' );
	const STAT_LAYOUTS = array( 'grid', 'list' );

	/** Default stats fields per type. */
	const STAT_DEFAULTS = array(
		'casino'   => 'wins,total_won,biggest_win,top_multiplier,last_win',
		'provider' => 'wins,games,total_won,biggest_win,top_multiplier',
		'game'     => 'wins,biggest_win,top_multiplier,total_won,rtp,volatility,last_win',
	);

	public static function wins_defaults() {
		return array(
			'casino'   => '',
			'provider' => '',
			'game'     => '',
			'sort'     => 'latest',      // latest | amount | multiplier
			'period'   => '',            // day | week | month | all (default: all for latest, else day)
			'limit'    => 10,            // 1-100
			'layout'   => 'table',       // table | list | cards
			'columns'  => '',            // e.g. "game,player,win,time"; default: the site default (Wintracker → Settings) minus the filters
			'currency' => 'original',    // original | eur
			'images'   => '',            // game thumbnails: yes | no; default: the site default (yes)
			'title'    => '',
			'heading'  => 'h3',
			'empty'    => __( 'No wins tracked yet.', 'wintracker' ), // shown when the API has no wins; "" hides the widget
			'class'    => '',
		);
	}

	public static function stats_defaults() {
		return array(
			'type'     => '',            // casino | provider | game (or use casino= / provider= / game= instead)
			'id'       => '',            // casino/provider slug or id, game id
			'casino'   => '',
			'provider' => '',
			'game'     => '',
			'layout'   => 'grid',        // grid | list
			'fields'   => '',            // e.g. "wins,biggest_win,top_multiplier"; default depends on the type
			'details'  => 'inline',      // which win was the biggest / top multiplier / last: inline | tooltip | none
			'title'    => '',
			'heading'  => 'h3',
			'class'    => '',
		);
	}

	public static function multipliers_defaults() {
		return array(
			'type'      => '',
			'id'        => '',
			'casino'    => '',
			'provider'  => '',
			'game'      => '',
			'layout'    => 'columns',     // columns (vertical bars) | rows (horizontal bars, for narrow spaces)
			'groups'    => '2,10,50,100,500', // where the bars split; any of 1, 2, 5, 10, 25, 50, 100, 250, 500, 1000
			'highlight' => 100,           // bars from this multiplier up are highlighted and summed in the note
			'note'      => 'yes',         // the line under the chart: yes | no
			'counts'    => 'yes',         // number of wins under each range label: yes | no
			'collapse'  => 'yes',         // merge empty ranges at the top end into one ("50×+"): yes | no
			'title'     => '',
			'heading'   => 'h3',
			'empty'     => __( 'No multipliers recorded yet.', 'wintracker' ), // "" hides the widget
			'class'     => '',
		);
	}

	/** Band edges the API counts by (WinStats::MULTIPLIER_BANDS on the Wintracker side). */
	const MULTIPLIER_EDGES = array( 1, 2, 5, 10, 25, 50, 100, 250, 500, 1000 );

	/** @param array<string, mixed> $args see multipliers_defaults() */
	public static function multipliers( array $args ) {
		$args = array_merge( self::multipliers_defaults(), array_change_key_case( $args, CASE_LOWER ) );
		$type = $args['type'];
		$id   = $args['id'];
		foreach ( Wintracker_Data::TYPES as $t ) {
			if ( '' === (string) $type && '' !== (string) $args[ $t ] ) {
				$type = $t;
				$id   = $args[ $t ];
			}
		}
		if ( ! in_array( $type, Wintracker_Data::TYPES, true ) ) {
			return self::comment( __( 'Wintracker multipliers: set casino="…", provider="…" or game="…".', 'wintracker' ) );
		}
		$data = Wintracker_Data::multipliers( $type, $id );
		if ( null === $data ) {
			return self::unavailable();
		}
		if ( 0 === $data['total'] && '' === (string) $args['empty'] ) {
			return '';
		}

		// Group the bands into bars at the requested edges.
		$edges = array_values( array_intersect( self::MULTIPLIER_EDGES, array_map( 'floatval', preg_split( '/[\s,]+/', (string) $args['groups'], -1, PREG_SPLIT_NO_EMPTY ) ) ) );
		$edges = $edges ? $edges : array( 2, 10, 50, 100, 500 );
		$hot   = (float) $args['highlight'];
		$bars  = array();
		$from  = null;
		foreach ( array_merge( $edges, array( null ) ) as $to ) {
			$wins = 0;
			foreach ( $data['bands'] as $band ) {
				$lo = null === $band['from'] ? 0.0 : $band['from'];
				if ( ( null === $from || $lo >= $from ) && ( null === $to || ( null !== $band['to'] && $band['to'] <= $to ) ) ) {
					$wins += $band['wins'];
				}
			}
			$bars[] = array(
				'from'  => $from,
				'wins'  => $wins,
				'hot'   => null !== $from && $from >= $hot,
			);
			$from = $to;
		}
		// Empty ranges at the top end say the same thing three times: merge them into one ("50×+", 0 wins).
		if ( ! in_array( strtolower( (string) $args['collapse'] ), array( 'no', 'false', '0', 'off' ), true ) ) {
			while ( count( $bars ) > 2 && 0 === $bars[ count( $bars ) - 1 ]['wins'] && 0 === $bars[ count( $bars ) - 2 ]['wins'] ) {
				array_pop( $bars );
			}
		}
		foreach ( $bars as $i => &$bar ) {
			$next         = isset( $bars[ $i + 1 ] ) ? $bars[ $i + 1 ]['from'] : null;
			$bar['label'] = self::multiplier_label( $bar['from'], $next );
		}
		unset( $bar );
		$max     = max( 1, max( array_column( $bars, 'wins' ) ) );
		$hot_sum = array_sum( array_column( array_filter( $bars, static function ( $b ) { return $b['hot']; } ), 'wins' ) );
		foreach ( $bars as &$bar ) {
			$bar['share']  = Wintracker_Format::share( $bar['wins'], $data['total'] );
			$bar['zero']   = 0 === $bar['wins'];
			/* translators: %s: number of wins */
			$bar['count']  = sprintf( _n( '%s win', '%s wins', $bar['wins'], 'wintracker' ), number_format_i18n( $bar['wins'] ) );
			$bar['height'] = round( $bar['wins'] / $max * 100, 2 );
		}
		unset( $bar );
		$layout = in_array( $args['layout'], array( 'columns', 'rows' ), true ) ? $args['layout'] : 'columns';

		return self::template(
			'multipliers-' . $layout,
			array(
				'bars'      => $bars,
				'total'     => $data['total'],
				'hot_share' => Wintracker_Format::share( $hot_sum, $data['total'] ),
				'hot_wins'  => $hot_sum,
				'counts'    => ! in_array( strtolower( (string) $args['counts'] ), array( 'no', 'false', '0', 'off' ), true ),
				'highlight' => $hot,
				'note'      => ! in_array( strtolower( (string) $args['note'] ), array( 'no', 'false', '0', 'off' ), true ),
				'empty'     => (string) $args['empty'],
				'type'      => $type,
				'meta'      => $data['meta'],
				'args'      => $args,
			),
			array( 'wintracker--multipliers', 'wintracker--' . $layout, 'wintracker--' . $type ),
			$args
		);
	}

	/** "under 2×", "2–10×", "500×+" */
	private static function multiplier_label( $from, $to ) {
		$num = static function ( $n ) {
			return number_format_i18n( $n, floor( $n ) == $n ? 0 : 1 ); // phpcs:ignore Universal.Operators.StrictComparisons
		};
		if ( null === $from ) {
			/* translators: %s: multiplier, e.g. 2 */
			return sprintf( __( 'under %s×', 'wintracker' ), $num( $to ) );
		}
		if ( null === $to ) {
			/* translators: %s: multiplier, e.g. 500 */
			return sprintf( __( '%s×+', 'wintracker' ), $num( $from ) );
		}
		/* translators: 1: lower multiplier, 2: upper multiplier, e.g. 2–10× */
		return sprintf( __( '%1$s–%2$s×', 'wintracker' ), $num( $from ), $num( $to ) );
	}

	/** Columns shown when a widget has no columns attribute, in this order. */
	const DEFAULT_COLUMNS = array( 'game', 'casino', 'player', 'bet', 'win', 'multiplier', 'time' );

	/**
	 * Site-wide display defaults from Wintracker → Settings.
	 *
	 * @return array{columns: string[], images: bool, isolate: bool, css: string}
	 */
	public static function display() {
		$saved   = get_option( 'wintracker_display', array() );
		$saved   = is_array( $saved ) ? $saved : array();
		$columns = isset( $saved['columns'] ) ? array_values( array_intersect( Wintracker_Format::WIN_COLUMNS, (array) $saved['columns'] ) ) : array();
		return array(
			'columns' => $columns ? $columns : self::DEFAULT_COLUMNS,
			'images'  => ! isset( $saved['images'] ) || (bool) $saved['images'],
			'isolate' => ! empty( $saved['isolate'] ),
			'css'     => isset( $saved['css'] ) ? (string) $saved['css'] : '',
		);
	}

	/** @param array<string, mixed> $args see wins_defaults() */
	public static function wins( array $args ) {
		$args = array_merge( self::wins_defaults(), array_change_key_case( $args, CASE_LOWER ) );
		$data = Wintracker_Data::wins( $args );
		if ( null === $data ) {
			return self::unavailable();
		}
		if ( ! $data['wins'] && '' === (string) $args['empty'] ) {
			return '';
		}

		$layout  = in_array( $args['layout'], self::WIN_LAYOUTS, true ) ? $args['layout'] : 'table';
		$display = self::display();
		$columns = self::pick( $args['columns'], Wintracker_Format::WIN_COLUMNS );
		if ( ! $columns ) {
			// The site default, leaving out what every row has in common: no casino column on a casino page,
			// no game column on a game page (unless that leaves nothing).
			$columns = array_values(
				array_diff(
					$display['columns'],
					'' !== (string) $args['casino'] ? array( 'casino' ) : array(),
					'' !== (string) $args['game'] ? array( 'game' ) : array()
				)
			);
			$columns = $columns ? $columns : $display['columns'];
		}
		Wintracker_Links::prime( $data['wins'] );
		$images = strtolower( trim( (string) $args['images'] ) );
		$images = '' === $images ? $display['images'] : ! in_array( $images, array( 'no', 'false', '0', 'off' ), true );

		return self::template(
			'wins-' . $layout,
			array(
				'wins'     => $data['wins'],
				'meta'     => $data['meta'],
				'columns'  => $columns,
				'currency' => 'eur' === $args['currency'] ? 'eur' : 'original',
				'images'   => $images,
				'empty'    => (string) $args['empty'],
				'args'     => $args,
			),
			array( 'wintracker--wins', 'wintracker--' . $layout, 'wintracker--sort-' . ( $data['meta']['sort'] ?? 'latest' ) ),
			$args
		);
	}

	/** @param array<string, mixed> $args see stats_defaults() */
	public static function stats( array $args ) {
		$args = array_merge( self::stats_defaults(), array_change_key_case( $args, CASE_LOWER ) );
		$type = $args['type'];
		$id   = $args['id'];
		foreach ( Wintracker_Data::TYPES as $t ) {
			if ( '' === (string) $type && '' !== (string) $args[ $t ] ) {
				$type = $t;
				$id   = $args[ $t ];
			}
		}
		if ( ! in_array( $type, Wintracker_Data::TYPES, true ) ) {
			return self::comment( __( 'Wintracker stats: set casino="…", provider="…" or game="…".', 'wintracker' ) );
		}

		$row = Wintracker_Data::stats( $type, $id );
		if ( null === $row ) {
			/* translators: 1: casino, provider or game, 2: its slug or id */
			return self::unavailable( sprintf( __( 'No %1$s "%2$s" found.', 'wintracker' ), $type, $id ) );
		}
		$fields = self::pick( $args['fields'], Wintracker_Format::STAT_FIELDS ) ?: explode( ',', self::STAT_DEFAULTS[ $type ] );
		$fields = array_values(
			array_filter(
				$fields,
				static function ( $f ) use ( $row ) {
					return Wintracker_Format::has_stat( $row, $f );
				}
			)
		);
		$layout  = in_array( $args['layout'], self::STAT_LAYOUTS, true ) ? $args['layout'] : 'grid';
		$mode    = in_array( $args['details'], array( 'inline', 'tooltip', 'none' ), true ) ? $args['details'] : 'inline';
		$details = array();
		if ( 'none' !== $mode ) {
			$wins = Wintracker_Data::stat_details( $type, $row, $fields );
			Wintracker_Links::prime( array_values( $wins ) );
			foreach ( $wins as $field => $win ) {
				$html = Wintracker_Format::stat_detail( $field, $win, $type );
				if ( '' !== $html ) {
					$details[ $field ] = $html;
				}
			}
		}

		return self::template(
			'stats-' . $layout,
			array(
				'stats'   => $row,
				'type'    => $type,
				'fields'  => $fields,
				'details' => $details,
				'tooltip' => 'tooltip' === $mode,
				'args'    => $args,
			),
			array( 'wintracker--stats', 'wintracker--' . $layout, 'wintracker--' . $type, 'wintracker--details-' . $mode ),
			$args
		);
	}

	/** Comma-separated keys, keeping the given order, limited to the allowed ones. */
	private static function pick( $list, array $allowed ) {
		$keys = array_map( 'trim', explode( ',', strtolower( (string) $list ) ) );
		return array_values( array_unique( array_intersect( $keys, $allowed ) ) );
	}

	/** Prints a template part (theme override in <theme>/wintracker/<name>.php first) with the given variables. */
	public static function partial( $name, array $vars ) {
		$file = locate_template( array( 'wintracker/' . $name . '.php' ) );
		$file = $file ? $file : WINTRACKER_DIR . 'templates/' . $name . '.php';
		if ( is_readable( $file ) ) {
			( static function ( $__file, $__vars ) {
				extract( $__vars, EXTR_SKIP ); // phpcs:ignore WordPress.PHP.DontExtract
				include $__file;
			} )( $file, $vars );
		}
	}

	private static function template( $name, array $vars, array $classes, array $args ) {
		$file = locate_template( array( 'wintracker/' . $name . '.php' ) );
		$file = $file ? $file : WINTRACKER_DIR . 'templates/' . $name . '.php';
		$file = apply_filters( 'wintracker_template', $file, $name, $vars );
		if ( ! is_readable( $file ) ) {
			return '';
		}
		Wintracker_Assets::enqueue();

		$extra   = array_filter( array_map( 'sanitize_html_class', preg_split( '/\s+/', (string) $args['class'] ) ) );
		$root    = self::display()['isolate'] ? array( 'wintracker', 'wintracker--isolated' ) : array( 'wintracker' );
		$classes = implode( ' ', array_map( 'sanitize_html_class', array_merge( $root, $classes, $extra ) ) );
		$heading = in_array( $args['heading'], array( 'h2', 'h3', 'h4', 'h5', 'h6', 'p', 'div' ), true ) ? $args['heading'] : 'h3';

		ob_start();
		echo '<div class="' . esc_attr( $classes ) . '">';
		if ( '' !== (string) $args['title'] ) {
			echo '<' . $heading . ' class="wintracker__title">' . esc_html( $args['title'] ) . '</' . $heading . '>'; // phpcs:ignore WordPress.Security.EscapeOutput -- whitelisted tag
		}
		( static function ( $__file, $__vars ) {
			extract( $__vars, EXTR_SKIP ); // phpcs:ignore WordPress.PHP.DontExtract
			include $__file;
		} )( $file, $vars );
		echo '</div>';
		return (string) apply_filters( 'wintracker_html', ob_get_clean(), $name, $vars );
	}

	/** Nothing for visitors when there is no data (API down, no snapshot); the reason for editors. */
	private static function unavailable( $fallback = '' ) {
		$error = Wintracker_Client::last_error();
		return self::comment( 'Wintracker: ' . ( $error ? $error['code'] . ': ' . $error['message'] : $fallback ) );
	}

	private static function comment( $text ) {
		if ( ! is_user_logged_in() || ! current_user_can( 'edit_posts' ) ) {
			return '';
		}
		return '<!-- ' . esc_html( str_replace( '--', '- -', $text ) ) . ' -->';
	}
}
