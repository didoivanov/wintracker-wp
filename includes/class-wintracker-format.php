<?php
/**
 * Formatting helpers for templates. Every method returns escaped HTML.
 */

defined( 'ABSPATH' ) || exit;

final class Wintracker_Format {

	/** Columns a wins widget can show, in display order. */
	const WIN_COLUMNS = array( 'game', 'casino', 'provider', 'player', 'bet', 'win', 'multiplier', 'time' );

	/** Fields a stats card can show. */
	const STAT_FIELDS = array( 'wins', 'total_won', 'biggest_win', 'top_multiplier', 'last_win', 'games', 'rtp', 'volatility', 'provider' );

	public static function label( $key ) {
		$labels = array(
			'game'           => __( 'Game', 'wintracker' ),
			'casino'         => __( 'Casino', 'wintracker' ),
			'provider'       => __( 'Provider', 'wintracker' ),
			'player'         => __( 'Player', 'wintracker' ),
			'bet'            => __( 'Bet', 'wintracker' ),
			'win'            => __( 'Win', 'wintracker' ),
			'multiplier'     => __( 'Multiplier', 'wintracker' ),
			'time'           => __( 'When', 'wintracker' ),
			'wins'           => __( 'Wins tracked', 'wintracker' ),
			'total_won'      => __( 'Total won', 'wintracker' ),
			'biggest_win'    => __( 'Biggest win', 'wintracker' ),
			'top_multiplier' => __( 'Top multiplier', 'wintracker' ),
			'last_win'       => __( 'Last win', 'wintracker' ),
			'games'          => __( 'Games with wins', 'wintracker' ),
			'rtp'            => __( 'RTP', 'wintracker' ),
			'volatility'     => __( 'Volatility', 'wintracker' ),
		);
		return esc_html( apply_filters( 'wintracker_label', $labels[ $key ] ?? $key, $key ) );
	}

	/**
	 * One cell of a win row.
	 *
	 * @param array<string, mixed> $win      a win from the API
	 * @param string               $column   one of WIN_COLUMNS
	 * @param string               $currency 'original' or 'eur'
	 */
	public static function win_cell( array $win, $column, $currency = 'original', $images = true ) {
		switch ( $column ) {
			case 'game':
				if ( empty( $win['game']['name'] ) ) {
					return self::none();
				}
				$img = $images && ! empty( $win['game']['image_url'] )
					? '<img class="wintracker__img" src="' . esc_url( $win['game']['image_url'] ) . '" alt="" width="40" height="40" loading="lazy" decoding="async"> '
					: '';
				return $img . '<span class="wintracker__game">' . esc_html( $win['game']['name'] ) . '</span>';
			case 'casino':
			case 'provider':
				return ! empty( $win[ $column ]['name'] ) ? esc_html( $win[ $column ]['name'] ) : self::none();
			case 'player':
				return null !== ( $win['player'] ?? null ) && '' !== $win['player'] ? esc_html( $win['player'] ) : self::none();
			case 'bet':
				return ! empty( $win['bet'] ) ? self::money( $win['bet']['amount'], $win['bet']['currency'] ) : self::none();
			case 'win':
				if ( 'eur' === $currency && null !== ( $win['win_eur'] ?? null ) ) {
					return '<strong>' . self::money( $win['win_eur'], 'EUR' ) . '</strong>';
				}
				return ! empty( $win['win'] ) ? '<strong>' . self::money( $win['win']['amount'], $win['win']['currency'] ) . '</strong>' : self::none();
			case 'multiplier':
				return self::multiplier( $win['multiplier'] ?? null );
			case 'time':
				return self::time_ago( $win['won_at'] ?? null );
		}
		return '';
	}

	/** One stats field of a casino, provider or game row. */
	public static function stat( array $row, $field ) {
		switch ( $field ) {
			case 'wins':
				return esc_html( number_format_i18n( (int) ( $row['wins'] ?? 0 ) ) );
			case 'total_won':
				return self::money( $row['total_won_eur'] ?? 0, 'EUR', 0 );
			case 'biggest_win':
				return isset( $row['biggest_win_eur'] ) ? self::money( $row['biggest_win_eur'], 'EUR' ) : self::none();
			case 'top_multiplier':
				return self::multiplier( $row['top_multiplier'] ?? null );
			case 'last_win':
				return self::time_ago( $row['last_win_at'] ?? null );
			case 'games':
				return isset( $row['games'] ) ? esc_html( number_format_i18n( (int) $row['games'] ) ) : self::none();
			case 'rtp':
				return isset( $row['rtp'] ) ? esc_html( number_format_i18n( (float) $row['rtp'], 2 ) . '%' ) : self::none();
			case 'volatility':
				return ! empty( $row['volatility'] ) ? esc_html( ucfirst( (string) $row['volatility'] ) ) : self::none();
			case 'provider':
				return ! empty( $row['provider']['name'] ) ? esc_html( $row['provider']['name'] ) : self::none();
		}
		return '';
	}

	/** Has the row a value for this stats field? (Templates skip empty ones.) */
	public static function has_stat( array $row, $field ) {
		$source = array(
			'wins'           => 'wins',
			'total_won'      => 'total_won_eur',
			'biggest_win'    => 'biggest_win_eur',
			'top_multiplier' => 'top_multiplier',
			'last_win'       => 'last_win_at',
		);
		$key = $source[ $field ] ?? $field;
		return isset( $row[ $key ] ) && '' !== $row[ $key ] && array() !== $row[ $key ];
	}

	/**
	 * An amount in its currency, in the site's locale ("€1,907.12", "1 907,12 €", "0.00042 BTC").
	 * Small amounts (crypto) keep enough decimals to be meaningful.
	 */
	public static function money( $amount, $currency, $decimals = null ) {
		$amount   = (float) $amount;
		$currency = strtoupper( (string) $currency );
		if ( null === $decimals ) {
			$abs      = abs( $amount );
			$decimals = $abs > 0 && $abs < 1 ? min( 8, (int) ceil( -log10( $abs ) ) + 2 ) : 2;
		}
		$text = null;
		if ( class_exists( 'NumberFormatter' ) && preg_match( '/^[A-Z]{3}$/', $currency ) ) {
			$fmt = new NumberFormatter( str_replace( '-', '_', determine_locale() ), NumberFormatter::CURRENCY );
			$fmt->setAttribute( NumberFormatter::MIN_FRACTION_DIGITS, $decimals );
			$fmt->setAttribute( NumberFormatter::MAX_FRACTION_DIGITS, $decimals );
			$formatted = $fmt->formatCurrency( $amount, $currency );
			$text      = false !== $formatted ? $formatted : null;
		}
		if ( null === $text ) {
			$text = number_format_i18n( $amount, $decimals ) . ( '' !== $currency ? ' ' . $currency : '' );
		}
		return esc_html( apply_filters( 'wintracker_money', $text, $amount, $currency ) );
	}

	public static function multiplier( $value ) {
		if ( null === $value ) {
			return self::none();
		}
		$value = (float) $value;
		return esc_html( number_format_i18n( $value, $value < 100 ? 2 : 0 ) . '×' );
	}

	/** "5 mins ago" in a <time> element with the exact UTC time. */
	public static function time_ago( $iso ) {
		$ts = $iso ? strtotime( (string) $iso ) : false;
		if ( ! $ts ) {
			return self::none();
		}
		/* translators: %s: human-readable time difference, e.g. "5 mins" */
		$text = sprintf( __( '%s ago', 'wintracker' ), human_time_diff( $ts, time() ) );
		$full = wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $ts );
		return '<time datetime="' . esc_attr( gmdate( 'c', $ts ) ) . '" title="' . esc_attr( (string) $full ) . '">' . esc_html( $text ) . '</time>';
	}

	private static function none() {
		return '<span class="wintracker__none">–</span>';
	}
}
