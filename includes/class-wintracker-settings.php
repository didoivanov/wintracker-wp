<?php
/**
 * Wintracker → Settings: API key, connection test, cache, version (administrators only).
 *
 * The key is saved in the wintracker_api_key option (not autoloaded). WINTRACKER_API_KEY in wp-config.php, when
 * defined, takes precedence and the field is locked. The saved key is never printed back into the page.
 */

defined( 'ABSPATH' ) || exit;

final class Wintracker_Settings {

	const PAGE   = 'wintracker-settings';
	const OPTION = 'wintracker_api_key';

	public static function init() {
		add_action( 'admin_menu', array( __CLASS__, 'menu' ), 11 ); // after the Wintracker menu (Wintracker_Builder)
		add_action( 'admin_init', array( __CLASS__, 'old_url' ) );
		add_action( 'admin_post_wintracker_save', array( __CLASS__, 'save' ) );
		add_action( 'admin_post_wintracker_test', array( __CLASS__, 'test' ) );
		add_action( 'admin_post_wintracker_flush', array( __CLASS__, 'flush' ) );
		add_action( 'admin_post_wintracker_refresh', array( __CLASS__, 'save_refresh' ) );
		add_action( 'admin_post_wintracker_display', array( __CLASS__, 'save_display' ) );
		add_action( 'admin_post_wintracker_links', array( __CLASS__, 'save_links' ) );
		add_action( 'admin_post_wintracker_links_rebuild', array( __CLASS__, 'rebuild_links' ) );
		add_filter( 'plugin_action_links_' . plugin_basename( WINTRACKER_FILE ), array( __CLASS__, 'action_link' ) );
	}

	public static function url() {
		return admin_url( 'admin.php?page=' . self::PAGE );
	}

	public static function menu() {
		add_submenu_page( Wintracker_Builder::PAGE, __( 'Wintracker settings', 'wintracker' ), __( 'Settings', 'wintracker' ), 'manage_options', self::PAGE, array( __CLASS__, 'page' ) );
	}

	/** Settings → Wintracker (1.2.x) moved to Wintracker → Settings. */
	public static function old_url() {
		global $pagenow;
		if ( 'options-general.php' === $pagenow && isset( $_GET['page'] ) && 'wintracker' === $_GET['page'] ) { // phpcs:ignore WordPress.Security.NonceVerification
			wp_safe_redirect( self::url() );
			exit;
		}
	}

	public static function action_link( $links ) {
		array_unshift( $links, '<a href="' . esc_url( self::url() ) . '">' . esc_html__( 'Settings', 'wintracker' ) . '</a>' );
		return $links;
	}

	public static function page() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		$notice    = get_transient( self::notice_key() );
		$in_config = Wintracker_Client::key_in_config();
		$key       = Wintracker_Client::api_key();
		$masked    = '' !== $key ? substr( $key, 0, 3 ) . str_repeat( '•', 8 ) . substr( $key, -4 ) : '';
		$release   = get_site_transient( Wintracker_Updater::CACHE );
		$latest    = is_array( $release ) && ! empty( $release['release']['version'] ) ? $release['release']['version'] : null;
		delete_transient( self::notice_key() );
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'Wintracker settings', 'wintracker' ); ?></h1>
			<?php if ( is_array( $notice ) ) : ?>
				<div class="notice notice-<?php echo esc_attr( $notice['type'] ); ?> is-dismissible"><p><?php echo esc_html( $notice['message'] ); ?></p></div>
			<?php endif; ?>

			<h2><?php esc_html_e( 'API key', 'wintracker' ); ?></h2>
			<?php if ( $in_config ) : ?>
				<p>
					<?php
					/* translators: %s: masked API key */
					printf( esc_html__( 'The key is set in wp-config.php (%s), which overrides this page. Remove WINTRACKER_API_KEY from wp-config.php to manage it here.', 'wintracker' ), '<code>' . esc_html( $masked ) . '</code>' );
					?>
				</p>
			<?php else : ?>
				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
					<input type="hidden" name="action" value="wintracker_save">
					<?php wp_nonce_field( 'wintracker_save' ); ?>
					<table class="form-table" role="presentation">
						<tr>
							<th scope="row"><label for="wintracker-key"><?php esc_html_e( 'API key', 'wintracker' ); ?></label></th>
							<td>
								<input type="password" id="wintracker-key" name="wintracker_api_key" class="regular-text code" autocomplete="off" spellcheck="false"
									placeholder="<?php echo esc_attr( '' !== $key ? $masked : 'wt_…' ); ?>">
								<p class="description">
									<?php
									echo '' !== $key
										? esc_html__( 'A key is saved. Leave empty to keep it, or paste a new one to replace it.', 'wintracker' )
										: esc_html__( 'Paste the key you received from the Wintracker admin (starts with wt_).', 'wintracker' );
									?>
								</p>
								<?php if ( '' !== $key ) : ?>
									<p><label><input type="checkbox" name="wintracker_remove" value="1"> <?php esc_html_e( 'Remove the saved key', 'wintracker' ); ?></label></p>
								<?php endif; ?>
							</td>
						</tr>
					</table>
					<?php submit_button( __( 'Save key', 'wintracker' ) ); ?>
				</form>
			<?php endif; ?>

			<h2><?php esc_html_e( 'Display defaults', 'wintracker' ); ?></h2>
			<p><?php esc_html_e( 'What latest wins, biggest wins and top multipliers show (tables, lists and cards) unless a shortcode sets its own columns="…" or images="…". The casino column is left out on widgets filtered by casino, the game column on widgets filtered by game.', 'wintracker' ); ?></p>
			<?php $display = Wintracker_Render::display(); ?>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<input type="hidden" name="action" value="wintracker_display">
				<?php wp_nonce_field( 'wintracker_display' ); ?>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><?php esc_html_e( 'Columns', 'wintracker' ); ?></th>
						<td>
							<fieldset>
								<legend class="screen-reader-text"><?php esc_html_e( 'Columns', 'wintracker' ); ?></legend>
								<?php foreach ( Wintracker_Format::WIN_COLUMNS as $column ) : ?>
									<label style="display:block;margin-bottom:4px">
										<input type="checkbox" name="columns[]" value="<?php echo esc_attr( $column ); ?>" <?php checked( in_array( $column, $display['columns'], true ) ); ?>>
										<?php echo Wintracker_Format::label( $column ); // phpcs:ignore WordPress.Security.EscapeOutput ?>
									</label>
								<?php endforeach; ?>
							</fieldset>
						</td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Game images', 'wintracker' ); ?></th>
						<td><label><input type="checkbox" name="images" value="1" <?php checked( $display['images'] ); ?>> <?php esc_html_e( 'Show game thumbnails', 'wintracker' ); ?></label></td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Theme styles', 'wintracker' ); ?></th>
						<td>
							<label><input type="checkbox" name="isolate" value="1" <?php checked( $display['isolate'] ); ?>> <?php esc_html_e( 'Protect widgets from theme styles', 'wintracker' ); ?></label>
							<p class="description"><?php esc_html_e( 'Turn on when the theme\'s table, list or link styles make the widgets look wrong (coloured rows, centred cells, odd borders). Everything inside a widget is reset to plain browser styles and then gets the plugin\'s own; the theme\'s font and text colour are kept. Only theme rules marked !important still get through.', 'wintracker' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="wintracker-css"><?php esc_html_e( 'Custom CSS', 'wintracker' ); ?></label></th>
						<td>
							<textarea id="wintracker-css" name="css" rows="8" class="large-text code" spellcheck="false" placeholder=".wintracker { --wt-accent: #0a7d3b; }&#10;.wintracker__table th { letter-spacing: 0; }"><?php echo esc_textarea( $display['css'] ); ?></textarea>
							<p class="description"><?php esc_html_e( 'Your own rules for the widgets, loaded after the plugin\'s stylesheet and only on pages that show a widget. With theme protection on, put your rules inside .wintracker:is(#wt-iso, .wintracker--isolated):is(#wt-iso, .wintracker--isolated) { … } (CSS nesting, with & .wintracker__table td { … } etc.) or add !important, so they outweigh the protected styles. Colours: --wt-accent, --wt-border, --wt-muted, --wt-surface, --wt-radius.', 'wintracker' ); ?></p>
						</td>
					</tr>
				</table>
				<?php submit_button( __( 'Save display defaults', 'wintracker' ) ); ?>
			</form>

			<?php self::links_section(); ?>

			<h2><?php esc_html_e( 'Connection and cache', 'wintracker' ); ?></h2>
			<p><?php esc_html_e( 'Visitors never wait for the Wintracker API once a widget has data: when its data is older than the refresh time, the page shows the saved copy right away and the plugin fetches the new data after the page has been sent. Only a widget\'s very first view calls the API directly (3 seconds at most). The saved copy is kept for 7 days and also shown while the API is down.', 'wintracker' ); ?></p>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<input type="hidden" name="action" value="wintracker_refresh">
				<?php wp_nonce_field( 'wintracker_refresh' ); ?>
				<p>
					<label for="wintracker-refresh"><strong><?php esc_html_e( 'Refresh data every', 'wintracker' ); ?></strong></label>
					<select id="wintracker-refresh" name="refresh">
						<?php foreach ( array( 1, 5, 15, 30 ) as $wt_m ) : ?>
							<option value="<?php echo (int) $wt_m; ?>" <?php selected( Wintracker_Client::refresh_minutes(), $wt_m ); ?>>
								<?php
								/* translators: %d: number of minutes */
								echo esc_html( sprintf( _n( '%d minute', '%d minutes', $wt_m, 'wintracker' ), $wt_m ) );
								?>
							</option>
						<?php endforeach; ?>
					</select>
					<?php submit_button( __( 'Save', 'wintracker' ), 'secondary', 'submit', false ); ?>
				</p>
				<p class="description"><?php esc_html_e( 'Longer means fewer requests to wintracker.io and less work for your server; the multiplier charts and the slots grid refresh every 10 minutes at the most often. Logged-in admins see in the page source (search "Wintracker:" near the end) how many API calls a page made.', 'wintracker' ); ?></p>
			</form>
			<p>
				<?php self::button( 'wintracker_test', __( 'Test connection', 'wintracker' ), '' === $key ); ?>
				<?php self::button( 'wintracker_flush', __( 'Clear cache', 'wintracker' ) ); ?>
			</p>
			<p class="description"><?php esc_html_e( 'Clear cache also drops the outage snapshots: widgets reload from the API on their next view.', 'wintracker' ); ?></p>

			<h2><?php esc_html_e( 'Version', 'wintracker' ); ?></h2>
			<p>
				<?php
				/* translators: 1: installed version, 2: latest release */
				printf( esc_html__( 'Installed: %1$s. Latest release on GitHub: %2$s.', 'wintracker' ), esc_html( WINTRACKER_VERSION ), esc_html( $latest ? $latest : __( 'not checked yet', 'wintracker' ) ) );
				?>
				<a href="<?php echo esc_url( admin_url( 'update-core.php?force-check=1' ) ); ?>"><?php esc_html_e( 'Check for updates', 'wintracker' ); ?></a>
				· <a href="https://github.com/didoivanov/wintracker-wp#readme" target="_blank" rel="noopener"><?php esc_html_e( 'Shortcodes and options (README)', 'wintracker' ); ?></a>
			</p>
		</div>
		<?php
	}

	private static function button( $action, $label, $disabled = false ) {
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" style="display:inline-block;margin-right:8px">';
		echo '<input type="hidden" name="action" value="' . esc_attr( $action ) . '">';
		wp_nonce_field( $action );
		submit_button( $label, 'secondary', 'submit', false, $disabled ? array( 'disabled' => 'disabled' ) : array() );
		echo '</form>';
	}

	public static function save() {
		self::guard( 'wintracker_save' );
		if ( Wintracker_Client::key_in_config() ) {
			self::done( 'error', __( 'The key is set in wp-config.php; nothing was changed.', 'wintracker' ) );
		}
		if ( ! empty( $_POST['wintracker_remove'] ) ) {
			delete_option( self::OPTION );
			Wintracker_Client::flush();
			self::done( 'success', __( 'The API key was removed. Widgets show nothing until a key is saved.', 'wintracker' ) );
		}
		$key = isset( $_POST['wintracker_api_key'] ) ? trim( sanitize_text_field( wp_unslash( $_POST['wintracker_api_key'] ) ) ) : '';
		if ( '' === $key ) {
			self::done( 'info', __( 'No new key entered; the saved key is unchanged.', 'wintracker' ) );
		}
		if ( ! preg_match( '/^wt_[A-Za-z0-9_-]{16,128}$/', $key ) ) {
			self::done( 'error', __( 'That doesn\'t look like a Wintracker key (wt_ followed by letters and digits). Nothing was saved.', 'wintracker' ) );
		}
		update_option( self::OPTION, $key, false );
		Wintracker_Client::flush();
		$test = Wintracker_Client::test( $key );
		self::done(
			$test['ok'] ? 'success' : 'warning',
			$test['ok']
				? __( 'Key saved.', 'wintracker' ) . ' ' . $test['message']
				: __( 'Key saved, but the test call failed:', 'wintracker' ) . ' ' . $test['message']
		);
	}

	public static function test() {
		self::guard( 'wintracker_test' );
		$test = Wintracker_Client::test( Wintracker_Client::api_key() );
		self::done( $test['ok'] ? 'success' : 'error', $test['message'] );
	}

	private static function links_section() {
		$types  = Wintracker_Links::types();
		$report = get_option( Wintracker_Links::REPORT_OPTION, array() );
		$labels = array(
			'casino'   => __( 'Link casinos to', 'wintracker' ),
			'provider' => __( 'Link providers to', 'wintracker' ),
			'game'     => __( 'Link games to', 'wintracker' ),
		);
		$post_types = get_post_types( array( 'public' => true ), 'objects' );
		unset( $post_types['attachment'] );
		?>
		<h2 id="links"><?php esc_html_e( 'Links to your pages', 'wintracker' ); ?></h2>
		<p><?php esc_html_e( 'Optional. Game, casino and provider names in the widgets link to your own review pages when one matches by name or slug (for example "Bizzo Casino Review" for Bizzo Casino). Matching runs when you save here or click "Rebuild links now", daily, and shortly after a page of that type is published or renamed. To review, fix or force matches, use Wintracker → Links (or the Wintracker box on a page\'s edit screen).', 'wintracker' ); ?></p>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
			<input type="hidden" name="action" value="wintracker_links">
			<?php wp_nonce_field( 'wintracker_links' ); ?>
			<table class="form-table" role="presentation">
				<?php foreach ( Wintracker_Links::KINDS as $kind ) : ?>
					<tr>
						<th scope="row"><label for="wintracker-link-<?php echo esc_attr( $kind ); ?>"><?php echo esc_html( $labels[ $kind ] ); ?></label></th>
						<td>
							<select id="wintracker-link-<?php echo esc_attr( $kind ); ?>" name="link_types[<?php echo esc_attr( $kind ); ?>]">
								<option value=""><?php esc_html_e( '— Don\'t link —', 'wintracker' ); ?></option>
								<?php foreach ( $post_types as $pt ) : ?>
									<option value="<?php echo esc_attr( $pt->name ); ?>" <?php selected( $types[ $kind ], $pt->name ); ?>><?php echo esc_html( $pt->labels->name . ' (' . $pt->name . ')' ); ?></option>
								<?php endforeach; ?>
							</select>
							<?php
							$r = $report['kinds'][ $kind ] ?? null;
							if ( $types[ $kind ] && $r && ( $r['post_type'] ?? '' ) === $types[ $kind ] ) :
								?>
								<p class="description">
									<?php
									/* translators: 1: linked count, 2: total count, 3: manual matches */
									printf( esc_html__( '%1$s of %2$s linked (%3$s set by hand).', 'wintracker' ), esc_html( number_format_i18n( $r['linked'] ) ), esc_html( number_format_i18n( $r['entities'] ) ), esc_html( number_format_i18n( $r['manual'] ) ) );
									?>
								</p>
								<?php if ( ! empty( $r['ambiguous'] ) ) : ?>
									<p class="description"><?php esc_html_e( 'Several pages match, so not linked (pick one in its edit screen):', 'wintracker' ); ?> <?php echo esc_html( implode( ', ', $r['ambiguous'] ) ); ?></p>
								<?php endif; ?>
								<?php if ( ! empty( $r['unlinked'] ) ) : ?>
									<details><summary><?php esc_html_e( 'Not linked', 'wintracker' ); ?> (<?php echo esc_html( number_format_i18n( count( $r['unlinked'] ) ) ); ?>)</summary><p><?php echo esc_html( implode( ', ', $r['unlinked'] ) ); ?></p></details>
								<?php endif; ?>
							<?php elseif ( $types[ $kind ] && $r && ! empty( $r['error'] ) ) : ?>
								<p class="description"><?php esc_html_e( 'The Wintracker list could not be loaded at the last run; the previous links are kept.', 'wintracker' ); ?></p>
							<?php endif; ?>
						</td>
					</tr>
				<?php endforeach; ?>
			</table>
			<p>
				<?php submit_button( __( 'Save links', 'wintracker' ), 'primary', 'submit', false ); ?>
				<?php if ( Wintracker_Links::enabled() ) : ?>
					<span class="description" style="margin-left:8px">
						<?php
						if ( ! empty( $report['built'] ) ) {
							/* translators: %s: time ago, e.g. "5 mins" */
							printf( esc_html__( 'Last matched %s ago.', 'wintracker' ), esc_html( human_time_diff( (int) $report['built'], time() ) ) );
						} else {
							esc_html_e( 'Not matched yet.', 'wintracker' );
						}
						$due = wp_next_scheduled( Wintracker_Links::CRON_SOON );
						if ( $due && $due < time() - 10 * MINUTE_IN_SECONDS ) {
							echo ' <strong>' . esc_html__( 'WordPress\'s scheduled tasks (WP-Cron) don\'t seem to run on this site, so links aren\'t updated automatically after page edits. Click "Rebuild links now" after adding pages, or ask your host for a server cron job that calls wp-cron.php.', 'wintracker' ) . '</strong>';
						} elseif ( $due ) {
							echo ' ' . esc_html__( 'An update is scheduled and runs within a few minutes.', 'wintracker' );
						}
						?>
					</span>
				<?php endif; ?>
			</p>
		</form>
		<?php if ( Wintracker_Links::enabled() ) : ?>
			<p>
				<?php self::button( 'wintracker_links_rebuild', __( 'Rebuild links now', 'wintracker' ) ); ?>
				<a class="button" href="<?php echo esc_url( Wintracker_Links_Editor::url() ); ?>"><?php esc_html_e( 'Review and edit links', 'wintracker' ); ?></a>
			</p>
		<?php endif; ?>
		<?php
	}

	public static function save_links() {
		self::guard( 'wintracker_links' );
		$posted = isset( $_POST['link_types'] ) && is_array( $_POST['link_types'] ) ? wp_unslash( $_POST['link_types'] ) : array(); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput -- sanitized in save_types()
		$types  = Wintracker_Links::save_types( array_map( 'strval', $posted ) );
		if ( ! array_filter( $types ) ) {
			self::done( 'success', __( 'Links turned off.', 'wintracker' ) );
		}
		$summary = self::start_rebuild();
		self::done( 'success', __( 'Saved and matched.', 'wintracker' ) . ' ' . $summary . '.' );
	}

	public static function rebuild_links() {
		self::guard( 'wintracker_links_rebuild' );
		$summary = self::start_rebuild();
		self::done( 'success', __( 'Links rebuilt.', 'wintracker' ) . ' ' . $summary . '.' );
	}

	/** Matches right away in this request (WP-Cron is often blocked by Cloudflare/hosts), then reports the counts. */
	private static function start_rebuild() {
		wp_clear_scheduled_hook( Wintracker_Links::CRON_SOON );
		Wintracker_Links::schedule_daily();
		Wintracker_Links::rebuild();
		$report = get_option( Wintracker_Links::REPORT_OPTION, array() );
		$parts  = array();
		$names  = array(
			'casino'   => __( 'casinos', 'wintracker' ),
			'provider' => __( 'providers', 'wintracker' ),
			'game'     => __( 'games', 'wintracker' ),
		);
		foreach ( (array) ( $report['kinds'] ?? array() ) as $kind => $r ) {
			$parts[] = ! empty( $r['error'] )
				/* translators: %s: casinos, providers or games */
				? sprintf( __( '%s: the Wintracker list could not be loaded', 'wintracker' ), $names[ $kind ] )
				/* translators: 1: casinos, providers or games, 2: linked count, 3: total count */
				: sprintf( __( '%1$s: %2$s of %3$s linked', 'wintracker' ), $names[ $kind ], number_format_i18n( $r['linked'] ), number_format_i18n( $r['entities'] ) );
		}
		return implode( '; ', $parts );
	}

	/** Plain CSS only: no tags (a "</style>" would end the inline style block), at most 20 KB. */
	private static function clean_css( $css ) {
		$css = wp_strip_all_tags( $css );
		$css = str_ireplace( array( '</style', '<style' ), '', $css );
		return substr( trim( $css ), 0, 20000 );
	}

	public static function save_display() {
		self::guard( 'wintracker_display' );
		$posted  = isset( $_POST['columns'] ) && is_array( $_POST['columns'] ) ? array_map( 'sanitize_key', wp_unslash( $_POST['columns'] ) ) : array();
		$columns = array_values( array_intersect( Wintracker_Format::WIN_COLUMNS, $posted ) );
		if ( ! $columns ) {
			self::done( 'error', __( 'Keep at least one column. Nothing was saved.', 'wintracker' ) );
		}
		update_option(
			'wintracker_display',
			array(
				'columns' => $columns,
				'images'  => ! empty( $_POST['images'] ),
				'isolate' => ! empty( $_POST['isolate'] ),
				'css'     => self::clean_css( isset( $_POST['css'] ) ? (string) wp_unslash( $_POST['css'] ) : '' ), // phpcs:ignore WordPress.Security.ValidatedSanitizedInput -- clean_css()
			),
			true
		);
		self::done( 'success', __( 'Display defaults saved. Pages with a page cache show them after the cache is cleared.', 'wintracker' ) );
	}

	public static function save_refresh() {
		self::guard( 'wintracker_refresh' );
		$minutes = isset( $_POST['refresh'] ) ? absint( $_POST['refresh'] ) : 5;
		update_option( 'wintracker_refresh', in_array( $minutes, array( 1, 5, 15, 30 ), true ) ? $minutes : 5, true );
		self::done( 'success', __( 'Refresh time saved.', 'wintracker' ) );
	}

	public static function flush() {
		self::guard( 'wintracker_flush' );
		Wintracker_Client::flush();
		self::done( 'success', __( 'Cache cleared.', 'wintracker' ) );
	}

	private static function guard( $action ) {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You are not allowed to change Wintracker settings.', 'wintracker' ), 403 );
		}
		check_admin_referer( $action );
	}

	private static function done( $type, $message ) {
		set_transient( self::notice_key(), compact( 'type', 'message' ), MINUTE_IN_SECONDS );
		wp_safe_redirect( self::url() );
		exit;
	}

	private static function notice_key() {
		return 'wintracker_notice_' . get_current_user_id();
	}
}
