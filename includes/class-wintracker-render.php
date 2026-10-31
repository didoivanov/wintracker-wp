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

	/** Columns shown when a widget has no columns attribute, in this order. */
	const DEFAULT_COLUMNS = array( 'game', 'casino', 'player', 'bet', 'win', 'multiplier', 'time' );

	/**
	 * Site-wide display defaults from Wintracker → Settings.
	 *
	 * @return array{columns: string[], images: bool}
	 */
	public static function display() {
		$saved   = get_option( 'wintracker_display', array() );
		$saved   = is_array( $saved ) ? $saved : array();
		$columns = isset( $saved['columns'] ) ? array_values( array_intersect( Wintracker_Format::WIN_COLUMNS, (array) $saved['columns'] ) ) : array();
		return array(
			'columns' => $columns ? $columns : self::DEFAULT_COLUMNS,
			'images'  => ! isset( $saved['images'] ) || (bool) $saved['images'],
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
			foreach ( Wintracker_Data::stat_details( $type, $row, $fields ) as $field => $win ) {
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

	private static function template( $name, array $vars, array $classes, array $args ) {
		$file = locate_template( array( 'wintracker/' . $name . '.php' ) );
		$file = $file ? $file : WINTRACKER_DIR . 'templates/' . $name . '.php';
		$file = apply_filters( 'wintracker_template', $file, $name, $vars );
		if ( ! is_readable( $file ) ) {
			return '';
		}
		Wintracker_Assets::enqueue();

		$extra   = array_filter( array_map( 'sanitize_html_class', preg_split( '/\s+/', (string) $args['class'] ) ) );
		$classes = implode( ' ', array_map( 'sanitize_html_class', array_merge( array( 'wintracker' ), $classes, $extra ) ) );
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
