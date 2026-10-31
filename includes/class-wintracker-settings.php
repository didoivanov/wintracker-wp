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
				</table>
				<?php submit_button( __( 'Save display defaults', 'wintracker' ) ); ?>
			</form>

			<?php self::links_section(); ?>

			<h2><?php esc_html_e( 'Connection and cache', 'wintracker' ); ?></h2>
			<p><?php esc_html_e( 'Responses are cached for 60 seconds. The last good response of each widget is kept for 7 days and shown while the API is down.', 'wintracker' ); ?></p>
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
		<p><?php esc_html_e( 'Optional. Game, casino and provider names in the widgets link to your own review pages when one matches by name or slug (for example "Bizzo Casino Review" for Bizzo Casino). Matching runs in the background after saving, daily, and shortly after a page of that type is published or renamed. To fix or force a match, use the Wintracker box on the page\'s edit screen.', 'wintracker' ); ?></p>
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
						if ( wp_next_scheduled( Wintracker_Links::CRON_SOON ) ) {
							echo ' ' . esc_html__( 'Matching is scheduled and runs within a few minutes.', 'wintracker' );
						}
						?>
					</span>
				<?php endif; ?>
			</p>
		</form>
		<?php if ( Wintracker_Links::enabled() ) : ?>
			<p><?php self::button( 'wintracker_links_rebuild', __( 'Rebuild links now', 'wintracker' ) ); ?></p>
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
		self::start_rebuild();
		self::done( 'success', __( 'Saved. Matching runs in the background; reload this page in a minute to see the results.', 'wintracker' ) );
	}

	public static function rebuild_links() {
		self::guard( 'wintracker_links_rebuild' );
		self::start_rebuild();
		self::done( 'success', __( 'Matching started in the background; reload this page in a minute to see the results.', 'wintracker' ) );
	}

	private static function start_rebuild() {
		wp_clear_scheduled_hook( Wintracker_Links::CRON_SOON );
		Wintracker_Links::rebuild_soon( 0 );
		Wintracker_Links::schedule_daily();
		spawn_cron();
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
			),
			true
		);
		self::done( 'success', __( 'Display defaults saved. Pages with a page cache show them after the cache is cleared.', 'wintracker' ) );
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
