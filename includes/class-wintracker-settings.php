<?php
/**
 * Settings → Wintracker: API key, connection test, cache, version.
 *
 * The key is saved in the wintracker_api_key option (not autoloaded). WINTRACKER_API_KEY in wp-config.php, when
 * defined, takes precedence and the field is locked. The saved key is never printed back into the page.
 */

defined( 'ABSPATH' ) || exit;

final class Wintracker_Settings {

	const PAGE   = 'wintracker';
	const OPTION = 'wintracker_api_key';

	public static function init() {
		add_action( 'admin_menu', array( __CLASS__, 'menu' ) );
		add_action( 'admin_post_wintracker_save', array( __CLASS__, 'save' ) );
		add_action( 'admin_post_wintracker_test', array( __CLASS__, 'test' ) );
		add_action( 'admin_post_wintracker_flush', array( __CLASS__, 'flush' ) );
		add_filter( 'plugin_action_links_' . plugin_basename( WINTRACKER_FILE ), array( __CLASS__, 'action_link' ) );
	}

	public static function url() {
		return admin_url( 'options-general.php?page=' . self::PAGE );
	}

	public static function menu() {
		add_options_page( 'Wintracker', 'Wintracker', 'manage_options', self::PAGE, array( __CLASS__, 'page' ) );
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
			<h1>Wintracker</h1>
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
