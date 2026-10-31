<?php
/**
 * Wintracker → Shortcode builder: pick a widget and its options, see a live preview, copy the shortcode or the
 * PHP call. Below it, a reference of every shortcode and attribute. Available to anyone who can edit posts.
 */

defined( 'ABSPATH' ) || exit;

final class Wintracker_Builder {

	const PAGE = 'wintracker';

	public static function init() {
		add_action( 'admin_menu', array( __CLASS__, 'menu' ), 9 );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'assets' ) );
		add_action( 'wp_ajax_wintracker_preview', array( __CLASS__, 'ajax_preview' ) );
		add_action( 'wp_ajax_wintracker_games', array( __CLASS__, 'ajax_games' ) );
		add_filter( 'plugin_action_links_' . plugin_basename( WINTRACKER_FILE ), array( __CLASS__, 'action_link' ) );
	}

	public static function url() {
		return admin_url( 'admin.php?page=' . self::PAGE );
	}

	public static function menu() {
		$icon = 'data:image/svg+xml;base64,' . base64_encode( '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20"><path fill="#a7aaad" d="M3 3h3v14H3zm5.5 5h3v9h-3zM14 6h3v11h-3z"/></svg>' ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions
		add_menu_page( 'Wintracker', 'Wintracker', 'edit_posts', self::PAGE, array( __CLASS__, 'page' ), $icon, 81 );
		add_submenu_page( self::PAGE, __( 'Shortcode builder', 'wintracker' ), __( 'Shortcode builder', 'wintracker' ), 'edit_posts', self::PAGE, array( __CLASS__, 'page' ) );
	}

	public static function action_link( $links ) {
		$links[] = '<a href="' . esc_url( self::url() ) . '">' . esc_html__( 'Shortcode builder', 'wintracker' ) . '</a>';
		return $links;
	}

	public static function assets( $hook ) {
		if ( 'toplevel_page_' . self::PAGE !== $hook ) {
			return;
		}
		wp_enqueue_style( 'wintracker', plugins_url( 'assets/wintracker.css', WINTRACKER_FILE ), array(), WINTRACKER_VERSION );
		wp_enqueue_style( 'wintracker-admin', plugins_url( 'assets/admin.css', WINTRACKER_FILE ), array(), WINTRACKER_VERSION );
		wp_enqueue_script( 'wintracker-admin', plugins_url( 'assets/admin.js', WINTRACKER_FILE ), array(), WINTRACKER_VERSION, true );
		wp_localize_script(
			'wintracker-admin',
			'wintrackerBuilder',
			array(
				'ajax'  => admin_url( 'admin-ajax.php' ),
				'nonce' => wp_create_nonce( 'wintracker_builder' ),
				'i18n'  => array(
					'copied'    => __( 'Copied', 'wintracker' ),
					'copy'      => __( 'Copy', 'wintracker' ),
					'loading'   => __( 'Loading preview…', 'wintracker' ),
					'searching' => __( 'Searching…', 'wintracker' ),
					'noGames'   => __( 'No games found.', 'wintracker' ),
					'pickGame'  => __( 'Pick a game for a game stats card.', 'wintracker' ),
					'pickOne'   => __( 'Pick the casino, provider or game to show stats for.', 'wintracker' ),
					'defaultAll' => __( 'Default (all time)', 'wintracker' ),
					'defaultDay' => __( 'Default (last 24 hours)', 'wintracker' ),
				),
			)
		);
	}

	public static function page() {
		if ( ! current_user_can( 'edit_posts' ) ) {
			return;
		}
		$has_key   = '' !== Wintracker_Client::api_key();
		$casinos   = $has_key ? Wintracker_Client::get( 'casinos' ) : null;
		$providers = $has_key ? Wintracker_Client::get( 'providers' ) : null;
		$casinos   = $casinos ? $casinos['data'] : array();
		$providers = $providers ? $providers['data'] : array();
		usort(
			$providers,
			static function ( $a, $b ) {
				return strcasecmp( (string) $a['name'], (string) $b['name'] );
			}
		);
		?>
		<div class="wrap wtb">
			<h1><?php esc_html_e( 'Wintracker shortcode builder', 'wintracker' ); ?></h1>
			<p class="wtb-intro"><?php esc_html_e( 'Choose a widget and its options, check the preview, then copy the shortcode into a page, post or widget, or the PHP call into a theme template.', 'wintracker' ); ?></p>

			<?php if ( ! $has_key ) : ?>
				<div class="notice notice-warning inline"><p>
					<?php esc_html_e( 'No API key yet, so the casino and provider lists and the preview are empty.', 'wintracker' ); ?>
					<?php if ( current_user_can( 'manage_options' ) ) : ?>
						<a href="<?php echo esc_url( Wintracker_Settings::url() ); ?>"><?php esc_html_e( 'Add the key in Wintracker → Settings', 'wintracker' ); ?></a>
					<?php endif; ?>
				</p></div>
			<?php elseif ( ! $casinos ) : ?>
				<div class="notice notice-warning inline"><p><?php esc_html_e( 'The Wintracker API did not answer, so the casino and provider lists are empty. You can still type slugs into the shortcode by hand.', 'wintracker' ); ?></p></div>
			<?php endif; ?>

			<div class="wtb-grid">
				<form class="wtb-form" id="wtb-form" autocomplete="off" onsubmit="return false">
					<fieldset class="wtb-widgets">
						<legend><?php esc_html_e( 'Widget', 'wintracker' ); ?></legend>
						<?php
						$widgets = array(
							'latest'      => array( __( 'Latest wins', 'wintracker' ), __( 'The most recent wins', 'wintracker' ) ),
							'biggest'     => array( __( 'Biggest wins', 'wintracker' ), __( 'Top wins by EUR value', 'wintracker' ) ),
							'multipliers' => array( __( 'Top multipliers', 'wintracker' ), __( 'Highest win ÷ bet', 'wintracker' ) ),
							'stats'       => array( __( 'Stats card', 'wintracker' ), __( 'All-time figures of a casino, provider or game', 'wintracker' ) ),
						);
						foreach ( $widgets as $value => $label ) :
							?>
							<label class="wtb-widget">
								<input type="radio" name="widget" value="<?php echo esc_attr( $value ); ?>" <?php checked( 'latest', $value ); ?>>
								<span><strong><?php echo esc_html( $label[0] ); ?></strong><small><?php echo esc_html( $label[1] ); ?></small></span>
							</label>
						<?php endforeach; ?>
					</fieldset>

					<fieldset data-show="stats">
						<legend><?php esc_html_e( 'Stats for', 'wintracker' ); ?></legend>
						<label><input type="radio" name="stats_type" value="casino" checked> <?php esc_html_e( 'Casino', 'wintracker' ); ?></label>
						<label><input type="radio" name="stats_type" value="provider"> <?php esc_html_e( 'Provider', 'wintracker' ); ?></label>
						<label><input type="radio" name="stats_type" value="game"> <?php esc_html_e( 'Game', 'wintracker' ); ?></label>
					</fieldset>

					<fieldset>
						<legend data-show="wins"><?php esc_html_e( 'Filter (optional, combine as you like)', 'wintracker' ); ?></legend>
						<p class="wtb-field" data-for="casino">
							<label for="wtb-casino"><?php esc_html_e( 'Casino', 'wintracker' ); ?></label>
							<select id="wtb-casino" name="casino">
								<option value=""><?php esc_html_e( 'All casinos', 'wintracker' ); ?></option>
								<?php foreach ( $casinos as $c ) : ?>
									<option value="<?php echo esc_attr( $c['slug'] ); ?>"><?php echo esc_html( $c['name'] . ( empty( $c['active'] ) ? ' (' . __( 'inactive', 'wintracker' ) . ')' : '' ) ); ?></option>
								<?php endforeach; ?>
							</select>
						</p>
						<p class="wtb-field" data-for="provider">
							<label for="wtb-provider"><?php esc_html_e( 'Provider', 'wintracker' ); ?></label>
							<select id="wtb-provider" name="provider">
								<option value=""><?php esc_html_e( 'All providers', 'wintracker' ); ?></option>
								<?php foreach ( $providers as $p ) : ?>
									<?php if ( ! empty( $p['slug'] ) ) : ?>
										<option value="<?php echo esc_attr( $p['slug'] ); ?>"><?php echo esc_html( $p['name'] ); ?></option>
									<?php endif; ?>
								<?php endforeach; ?>
							</select>
						</p>
						<div class="wtb-field" data-for="game">
							<label for="wtb-game-q"><?php esc_html_e( 'Game', 'wintracker' ); ?></label>
							<div class="wtb-game">
								<input type="search" id="wtb-game-q" placeholder="<?php esc_attr_e( 'Type part of the name…', 'wintracker' ); ?>">
								<input type="hidden" name="game" id="wtb-game">
								<ul class="wtb-game-results" id="wtb-game-results" hidden></ul>
								<p class="wtb-game-picked" id="wtb-game-picked" hidden><span></span> <button type="button" class="button-link" id="wtb-game-clear"><?php esc_html_e( 'Remove', 'wintracker' ); ?></button></p>
							</div>
						</div>
					</fieldset>

					<fieldset data-show="wins">
						<legend><?php esc_html_e( 'Wins', 'wintracker' ); ?></legend>
						<p class="wtb-field">
							<label for="wtb-period"><?php esc_html_e( 'Period', 'wintracker' ); ?></label>
							<select id="wtb-period" name="period">
								<option value=""><?php esc_html_e( 'Default', 'wintracker' ); ?></option>
								<option value="day"><?php esc_html_e( 'Last 24 hours', 'wintracker' ); ?></option>
								<option value="week"><?php esc_html_e( 'Last 7 days', 'wintracker' ); ?></option>
								<option value="month"><?php esc_html_e( 'Last 30 days', 'wintracker' ); ?></option>
								<option value="all"><?php esc_html_e( 'All time', 'wintracker' ); ?></option>
							</select>
						</p>
						<p class="wtb-field">
							<label for="wtb-limit"><?php esc_html_e( 'Number of wins', 'wintracker' ); ?></label>
							<input type="number" id="wtb-limit" name="limit" min="1" max="100" value="10">
						</p>
						<p class="wtb-field">
							<label for="wtb-currency"><?php esc_html_e( 'Amounts in', 'wintracker' ); ?></label>
							<select id="wtb-currency" name="currency">
								<option value="original"><?php esc_html_e( 'The casino\'s currency', 'wintracker' ); ?></option>
								<option value="eur"><?php esc_html_e( 'Euro', 'wintracker' ); ?></option>
							</select>
						</p>
					</fieldset>

					<fieldset>
						<legend><?php esc_html_e( 'Look', 'wintracker' ); ?></legend>
						<p class="wtb-field" data-show="wins">
							<label for="wtb-layout"><?php esc_html_e( 'Layout', 'wintracker' ); ?></label>
							<select id="wtb-layout" name="layout">
								<option value="table"><?php esc_html_e( 'Table', 'wintracker' ); ?></option>
								<option value="list"><?php esc_html_e( 'Compact list (sidebars)', 'wintracker' ); ?></option>
								<option value="cards"><?php esc_html_e( 'Cards with game images', 'wintracker' ); ?></option>
							</select>
						</p>
						<p class="wtb-field" data-show="stats">
							<label for="wtb-stats-layout"><?php esc_html_e( 'Layout', 'wintracker' ); ?></label>
							<select id="wtb-stats-layout" name="stats_layout">
								<option value="grid"><?php esc_html_e( 'Tiles', 'wintracker' ); ?></option>
								<option value="list"><?php esc_html_e( 'Label / value rows', 'wintracker' ); ?></option>
							</select>
						</p>
						<div class="wtb-field" data-show="wins">
							<span class="wtb-label"><?php esc_html_e( 'Columns', 'wintracker' ); ?></span>
							<div>
								<label><input type="checkbox" id="wtb-columns-auto" checked> <?php esc_html_e( 'Automatic (leaves out what the widget is filtered by)', 'wintracker' ); ?></label>
								<div class="wtb-checks" id="wtb-columns" hidden>
									<?php foreach ( Wintracker_Format::WIN_COLUMNS as $col ) : ?>
										<label><input type="checkbox" name="columns[]" value="<?php echo esc_attr( $col ); ?>" <?php checked( 'provider' !== $col ); ?>> <?php echo Wintracker_Format::label( $col ); // phpcs:ignore WordPress.Security.EscapeOutput ?></label>
									<?php endforeach; ?>
								</div>
							</div>
						</div>
						<p class="wtb-field" data-show="stats">
							<label for="wtb-details"><?php esc_html_e( 'Win details', 'wintracker' ); ?></label>
							<select id="wtb-details" name="details">
								<option value="inline"><?php esc_html_e( 'Under the value (game, provider, when)', 'wintracker' ); ?></option>
								<option value="tooltip"><?php esc_html_e( 'As a tooltip on hover / tap', 'wintracker' ); ?></option>
								<option value="none"><?php esc_html_e( 'None', 'wintracker' ); ?></option>
							</select>
						</p>
						<div class="wtb-field" data-show="stats">
							<span class="wtb-label"><?php esc_html_e( 'Figures', 'wintracker' ); ?></span>
							<div>
								<label><input type="checkbox" id="wtb-fields-auto" checked> <?php esc_html_e( 'Automatic (the usual figures for that type)', 'wintracker' ); ?></label>
								<div class="wtb-checks" id="wtb-fields" hidden>
									<?php foreach ( Wintracker_Format::STAT_FIELDS as $field ) : ?>
										<label><input type="checkbox" name="fields[]" value="<?php echo esc_attr( $field ); ?>" <?php checked( in_array( $field, array( 'wins', 'total_won', 'biggest_win', 'top_multiplier' ), true ) ); ?>> <?php echo Wintracker_Format::label( $field ); // phpcs:ignore WordPress.Security.EscapeOutput ?></label>
									<?php endforeach; ?>
								</div>
							</div>
						</div>
						<p class="wtb-field" data-show="wins">
							<label><input type="checkbox" name="images" value="no"> <?php esc_html_e( 'Hide game images', 'wintracker' ); ?></label>
						</p>
						<p class="wtb-field">
							<label for="wtb-title"><?php esc_html_e( 'Heading', 'wintracker' ); ?></label>
							<input type="text" id="wtb-title" name="title" placeholder="<?php esc_attr_e( 'Optional, e.g. Biggest wins this week', 'wintracker' ); ?>">
						</p>
						<p class="wtb-field" data-show="wins">
							<label><input type="checkbox" name="hide_empty" value="1"> <?php esc_html_e( 'Hide the widget when there are no wins yet (instead of “No wins tracked yet.”)', 'wintracker' ); ?></label>
						</p>
					</fieldset>
				</form>

				<div class="wtb-out">
					<div class="wtb-code">
						<div class="wtb-code-head"><strong><?php esc_html_e( 'Shortcode', 'wintracker' ); ?></strong><button type="button" class="button button-primary" data-copy="wtb-shortcode"><?php esc_html_e( 'Copy', 'wintracker' ); ?></button></div>
						<code id="wtb-shortcode" class="wtb-snippet"></code>
					</div>
					<details class="wtb-code">
						<summary><strong><?php esc_html_e( 'PHP for theme templates', 'wintracker' ); ?></strong></summary>
						<div class="wtb-code-head"><span></span><button type="button" class="button" data-copy="wtb-php"><?php esc_html_e( 'Copy', 'wintracker' ); ?></button></div>
						<code id="wtb-php" class="wtb-snippet"></code>
					</details>
					<div class="wtb-preview">
						<div class="wtb-preview-head"><strong><?php esc_html_e( 'Preview', 'wintracker' ); ?></strong> <small><?php esc_html_e( 'live data; your theme\'s fonts and colours will apply on the site', 'wintracker' ); ?></small></div>
						<div id="wtb-preview" class="wtb-preview-body" aria-live="polite"></div>
					</div>
				</div>
			</div>

			<?php self::reference(); ?>
		</div>
		<?php
	}

	/** The reference tables under the builder. */
	private static function reference() {
		$shortcodes = array(
			array( '[wintracker_latest_wins]', __( 'The most recent wins.', 'wintracker' ) ),
			array( '[wintracker_biggest_wins]', __( 'Biggest wins by EUR value; the last 24 hours unless period is set.', 'wintracker' ) ),
			array( '[wintracker_top_multipliers]', __( 'Highest win ÷ bet; the last 24 hours unless period is set.', 'wintracker' ) ),
			array( '[wintracker_wins sort="…"]', __( 'Any of the three above: sort = latest, amount or multiplier.', 'wintracker' ) ),
			array( '[wintracker_stats]', __( 'All-time figures of one casino, provider or game.', 'wintracker' ) ),
		);
		$wins = array(
			array( 'casino', __( 'Casino slug, e.g. bizzo (see the Casino list above).', 'wintracker' ), __( 'all casinos', 'wintracker' ) ),
			array( 'provider', __( 'Provider slug, e.g. pragmaticplay.', 'wintracker' ), __( 'all providers', 'wintracker' ) ),
			array( 'game', __( 'Game id (number). Find it with the Game search above.', 'wintracker' ), __( 'all games', 'wintracker' ) ),
			array( 'period', 'day, week, month, all', __( 'all for latest wins, else day', 'wintracker' ) ),
			array( 'limit', '1–100', '10' ),
			array( 'layout', 'table, list, cards', 'table' ),
			array( 'columns', 'game, casino, provider, player, bet, win, multiplier, time — ' . __( 'in the order to show', 'wintracker' ), __( 'automatic', 'wintracker' ) ),
			array( 'currency', 'original, eur', 'original' ),
			array( 'images', 'yes, no', 'yes' ),
			array( 'title', __( 'Heading above the widget.', 'wintracker' ), '' ),
			array( 'heading', 'h2, h3, h4, h5, h6, p, div', 'h3' ),
			array( 'empty', __( 'Text when there are no wins yet; empty="" hides the widget.', 'wintracker' ), __( 'No wins tracked yet.', 'wintracker' ) ),
			array( 'class', __( 'Extra CSS classes.', 'wintracker' ), '' ),
		);
		$stats = array(
			array( 'casino / provider / game', __( 'What to show stats for: casino or provider slug, or game id. Use one.', 'wintracker' ), '' ),
			array( 'layout', 'grid, list', 'grid' ),
			array( 'fields', 'wins, total_won, biggest_win, top_multiplier, last_win, games, rtp, volatility, provider', __( 'automatic', 'wintracker' ) ),
			array( 'details', 'inline, tooltip, none — ' . __( 'which win was the biggest win / top multiplier / last win (game, provider, amount, when)', 'wintracker' ), 'inline' ),
			array( 'title, heading, class', __( 'As for wins.', 'wintracker' ), '' ),
		);
		$table = static function ( $rows, $head ) {
			echo '<table class="widefat striped wtb-ref"><thead><tr>';
			foreach ( $head as $h ) {
				echo '<th>' . esc_html( $h ) . '</th>';
			}
			echo '</tr></thead><tbody>';
			foreach ( $rows as $row ) {
				echo '<tr><td><code>' . esc_html( $row[0] ) . '</code></td>';
				foreach ( array_slice( $row, 1 ) as $cell ) {
					echo '<td>' . esc_html( $cell ) . '</td>';
				}
				echo '</tr>';
			}
			echo '</tbody></table>';
		};
		?>
		<h2 class="wtb-ref-title"><?php esc_html_e( 'Reference', 'wintracker' ); ?></h2>
		<?php $table( $shortcodes, array( __( 'Shortcode', 'wintracker' ), __( 'Shows', 'wintracker' ) ) ); ?>
		<h3><?php esc_html_e( 'Wins attributes', 'wintracker' ); ?></h3>
		<?php $table( $wins, array( __( 'Attribute', 'wintracker' ), __( 'Values', 'wintracker' ), __( 'Default', 'wintracker' ) ) ); ?>
		<h3><?php esc_html_e( 'Stats attributes', 'wintracker' ); ?></h3>
		<?php $table( $stats, array( __( 'Attribute', 'wintracker' ), __( 'Values', 'wintracker' ), __( 'Default', 'wintracker' ) ) ); ?>
		<h3><?php esc_html_e( 'Good to know', 'wintracker' ); ?></h3>
		<ul class="wtb-notes">
			<li><?php esc_html_e( 'Data refreshes every minute. While the Wintracker API is down, widgets show their last good data; if they have none, they show nothing.', 'wintracker' ); ?></li>
			<li><?php esc_html_e( 'A widget that shows nothing on the site: view the page source while logged in and search for “Wintracker:” — an HTML comment says why (for example an unknown casino slug).', 'wintracker' ); ?></li>
			<li><?php esc_html_e( 'Filters combine: casino + game shows one game at one casino.', 'wintracker' ); ?></li>
		</ul>
		<?php
	}

	/** Renders a widget for the preview. Visible reasons instead of HTML comments when there is nothing to show. */
	public static function ajax_preview() {
		check_ajax_referer( 'wintracker_builder' );
		if ( ! current_user_can( 'edit_posts' ) ) {
			wp_send_json_error( null, 403 );
		}
		$raw  = isset( $_POST['atts'] ) && is_array( $_POST['atts'] ) ? wp_unslash( $_POST['atts'] ) : array(); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
		$atts = array();
		foreach ( $raw as $k => $v ) {
			if ( is_string( $k ) && is_scalar( $v ) ) {
				$atts[ sanitize_key( $k ) ] = sanitize_text_field( (string) $v );
			}
		}
		$tag   = isset( $_POST['tag'] ) ? sanitize_key( wp_unslash( $_POST['tag'] ) ) : '';
		$html  = in_array( $tag, Wintracker_Shortcodes::TAGS, true ) ? Wintracker_Shortcodes::render( $atts, '', $tag ) : '';
		$shown = trim( preg_replace( '/<!--.*?-->/s', '', $html ) );
		if ( '' === $shown ) {
			$error = Wintracker_Client::last_error();
			$why   = $error ? $error['message'] : __( 'Nothing found for these options.', 'wintracker' );
			$html  = '<p class="wtb-preview-empty">' . esc_html__( 'On the site this widget would show nothing:', 'wintracker' ) . ' ' . esc_html( $why ) . '</p>';
		}
		wp_send_json_success( array( 'html' => $html ) );
	}

	/** Game search for the builder: id, name, provider. */
	public static function ajax_games() {
		check_ajax_referer( 'wintracker_builder' );
		if ( ! current_user_can( 'edit_posts' ) ) {
			wp_send_json_error( null, 403 );
		}
		$q = isset( $_GET['q'] ) ? sanitize_text_field( wp_unslash( $_GET['q'] ) ) : '';
		if ( mb_strlen( $q ) < 2 ) {
			wp_send_json_success( array() );
		}
		$response = Wintracker_Client::get(
			'games',
			array(
				'q'     => mb_substr( $q, 0, 100 ),
				'limit' => 20,
			)
		);
		$games = array();
		foreach ( $response ? $response['data'] : array() as $g ) {
			$games[] = array(
				'id'       => (int) $g['id'],
				'name'     => (string) $g['name'],
				'provider' => isset( $g['provider']['name'] ) ? (string) $g['provider']['name'] : '',
				'wins'     => (int) ( $g['wins'] ?? 0 ),
			);
		}
		wp_send_json_success( $games );
	}
}
