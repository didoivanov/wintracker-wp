<?php
/**
 * Updates the plugin from GitHub releases of didoivanov/wintracker-wp, through the normal wp-admin
 * update screens (update notice, "View details", one-click update, the auto-update toggle).
 *
 * - The plugin header's "Update URI: https://github.com/didoivanov/wintracker-wp" makes WordPress ask the
 *   update_plugins_github.com filter (WP 5.8+) instead of wordpress.org.
 * - The latest release is fetched from the GitHub API and re-checked every 3 hours ("Check again" on
 *   Dashboard → Updates refreshes it). Releases are created by .github/workflows/release.yml when the
 *   version in wintracker.php changes.
 * - The package is the release's wintracker-wp.zip asset (built by the workflow), else the source zipball.
 *   Whatever the zip's folder is called, it is installed into the plugin's current folder.
 *
 * Optional wp-config.php constants:
 *   WINTRACKER_GITHUB_TOKEN  a read-only token: avoids GitHub's 60 requests/hour limit per server IP, and is
 *                            required if the repository becomes private
 *   WINTRACKER_AUTO_UPDATE   true: always install new versions automatically (false: never); unset: the
 *                            per-site "Enable auto-updates" link on the Plugins screen decides
 */

defined( 'ABSPATH' ) || exit;

final class Wintracker_Updater {

	const REPO      = 'didoivanov/wintracker-wp';
	const CACHE     = 'wintracker_update_release';
	const CACHE_TTL = 3 * HOUR_IN_SECONDS;

	public static function init() {
		add_filter( 'update_plugins_github.com', array( __CLASS__, 'check' ), 10, 3 );
		add_filter( 'plugins_api', array( __CLASS__, 'details' ), 10, 3 );
		add_filter( 'upgrader_source_selection', array( __CLASS__, 'fix_folder' ), 10, 4 );
		add_filter( 'http_request_args', array( __CLASS__, 'auth' ), 10, 2 );
		add_filter( 'auto_update_plugin', array( __CLASS__, 'auto_update' ), 10, 2 );
		add_action( 'upgrader_process_complete', array( __CLASS__, 'clear' ) );
		add_filter( 'plugin_row_meta', array( __CLASS__, 'row_link' ), 10, 2 );
		add_action( 'admin_post_wintracker_check_updates', array( __CLASS__, 'check_now' ) );
		add_action( 'admin_notices', array( __CLASS__, 'check_notice' ) );
	}

	private static function basename() {
		return plugin_basename( WINTRACKER_FILE );
	}

	private static function slug() {
		return dirname( self::basename() );
	}

	/**
	 * update_plugins_{hostname}: WordPress puts the result into the update_plugins transient, as an
	 * available update when its version is newer, else as "no update" (which still enables the auto-update toggle).
	 */
	public static function check( $update, $plugin_data, $plugin_file ) {
		if ( self::basename() !== $plugin_file ) {
			return $update;
		}
		$release = self::release();
		if ( ! $release ) {
			return $update;
		}
		return array(
			'id'           => 'github.com/' . self::REPO,
			'slug'         => self::slug(),
			'plugin'       => $plugin_file,
			'version'      => $release['version'],
			'url'          => $release['url'],
			'package'      => $release['package'],
			'requires'     => $plugin_data['RequiresWP'] ?? '',
			'requires_php' => $plugin_data['RequiresPHP'] ?? '',
			'tested'       => get_bloginfo( 'version' ),
			'icons'        => array(),
		);
	}

	/** The latest release: version, url, package, notes, date. Null when GitHub can't be reached. */
	public static function release() {
		$force  = is_admin() && isset( $_GET['force-check'] ); // phpcs:ignore WordPress.Security.NonceVerification -- Dashboard → Updates "Check again"
		$cached = get_site_transient( self::CACHE );
		if ( is_array( $cached ) ) {
			$age = time() - (int) ( $cached['checked'] ?? 0 );
			if ( $age < ( $force ? MINUTE_IN_SECONDS : (int) ( $cached['ttl'] ?? self::CACHE_TTL ) ) ) {
				return $cached['release'];
			}
		}

		$response = wp_remote_get(
			'https://api.github.com/repos/' . self::REPO . '/releases/latest',
			array(
				'timeout' => 10,
				'headers' => array( 'Accept' => 'application/vnd.github+json' ),
			)
		);
		$status = is_wp_error( $response ) ? 0 : (int) wp_remote_retrieve_response_code( $response );
		$body   = 200 === $status ? json_decode( (string) wp_remote_retrieve_body( $response ), true ) : null;

		$release = null;
		if ( is_array( $body ) && ! empty( $body['tag_name'] ) && empty( $body['draft'] ) && empty( $body['prerelease'] ) ) {
			$package = (string) ( $body['zipball_url'] ?? '' );
			if ( ! self::token() ) {
				// Public download of the clean zip built by the release workflow (a token would break its signed redirect).
				foreach ( (array) ( $body['assets'] ?? array() ) as $asset ) {
					if ( 'wintracker-wp.zip' === ( $asset['name'] ?? '' ) && ! empty( $asset['browser_download_url'] ) ) {
						$package = (string) $asset['browser_download_url'];
					}
				}
			}
			$release = array(
				'version' => ltrim( (string) $body['tag_name'], 'vV' ),
				'url'     => (string) ( $body['html_url'] ?? 'https://github.com/' . self::REPO ),
				'package' => $package,
				'notes'   => (string) ( $body['body'] ?? '' ),
				'date'    => (string) ( $body['published_at'] ?? '' ),
			);
		} elseif ( 404 !== $status ) {
			// GitHub unreachable or rate-limited: keep the last known release and try again in an hour.
			$release = is_array( $cached ) ? $cached['release'] : null;
		}

		// Kept for a week so a GitHub outage doesn't hide a known update; 'ttl' says when to check again.
		set_site_transient(
			self::CACHE,
			array(
				'release' => $release,
				'checked' => time(),
				'ttl'     => 200 === $status || 404 === $status ? self::CACHE_TTL : HOUR_IN_SECONDS,
			),
			WEEK_IN_SECONDS
		);
		return $release;
	}

	/** "View details" on the Plugins and Updates screens. */
	public static function details( $result, $action, $args ) {
		if ( 'plugin_information' !== $action || ! isset( $args->slug ) || self::slug() !== $args->slug ) {
			return $result;
		}
		$release = self::release();
		$data    = get_plugin_data( WINTRACKER_FILE, false, false );
		$readme  = 'https://github.com/' . self::REPO . '#readme';

		return (object) array(
			'name'          => $data['Name'],
			'slug'          => self::slug(),
			'version'       => $release ? $release['version'] : WINTRACKER_VERSION,
			'author'        => $data['Author'],
			'homepage'      => 'https://github.com/' . self::REPO,
			'requires'      => $data['RequiresWP'],
			'requires_php'  => $data['RequiresPHP'],
			'last_updated'  => $release ? $release['date'] : '',
			'download_link' => $release ? $release['package'] : '',
			'sections'      => array(
				'description' => '<p>' . esc_html( $data['Description'] ) . '</p><p><a href="' . esc_url( $readme ) . '" target="_blank" rel="noopener">' . esc_html__( 'Documentation (README)', 'wintracker' ) . '</a></p>',
				'changelog'   => $release && '' !== trim( $release['notes'] )
					? wpautop( make_clickable( esc_html( $release['notes'] ) ) )
					: '<p><a href="' . esc_url( 'https://github.com/' . self::REPO . '/releases' ) . '" target="_blank" rel="noopener">' . esc_html__( 'Releases on GitHub', 'wintracker' ) . '</a></p>',
			),
		);
	}

	/** GitHub zipballs unpack to "didoivanov-wintracker-wp-<sha>/": install into the plugin's current folder instead. */
	public static function fix_folder( $source, $remote_source, $upgrader, $hook_extra = array() ) {
		if ( ( $hook_extra['plugin'] ?? '' ) !== self::basename() || is_wp_error( $source ) ) {
			return $source;
		}
		global $wp_filesystem;
		$target = trailingslashit( $remote_source ) . self::slug() . '/';
		if ( untrailingslashit( $source ) === untrailingslashit( $target ) ) {
			return $source;
		}
		if ( ! $wp_filesystem->exists( trailingslashit( $source ) . 'wintracker.php' ) ) {
			return new WP_Error( 'wintracker_bad_package', __( 'The downloaded Wintracker package does not contain wintracker.php.', 'wintracker' ) );
		}
		if ( $wp_filesystem->exists( $target ) ) {
			$wp_filesystem->delete( $target, true );
		}
		return $wp_filesystem->move( $source, $target ) ? $target : new WP_Error( 'wintracker_rename', __( 'Could not rename the Wintracker update folder.', 'wintracker' ) );
	}

	/** Sends the optional token to GitHub's API and download host for this repository only. */
	public static function auth( $args, $url ) {
		$token = self::token();
		if ( ! $token ) {
			return $args;
		}
		$host = wp_parse_url( $url, PHP_URL_HOST );
		$path = (string) wp_parse_url( $url, PHP_URL_PATH );
		$ours = ( 'api.github.com' === $host && 0 === strpos( $path, '/repos/' . self::REPO . '/' ) )
			|| ( 'codeload.github.com' === $host && 0 === strpos( $path, '/' . self::REPO . '/' ) );
		if ( $ours ) {
			$args['headers']                  = isset( $args['headers'] ) ? (array) $args['headers'] : array();
			$args['headers']['Authorization'] = 'Bearer ' . $token;
		}
		return $args;
	}

	public static function auto_update( $update, $item ) {
		if ( defined( 'WINTRACKER_AUTO_UPDATE' ) && isset( $item->plugin ) && self::basename() === $item->plugin ) {
			return (bool) WINTRACKER_AUTO_UPDATE;
		}
		return $update;
	}

	/** "Check for updates" under the plugin's description on the Plugins screen. */
	public static function row_link( $meta, $plugin_file ) {
		if ( self::basename() === $plugin_file && current_user_can( 'update_plugins' ) ) {
			$url    = wp_nonce_url( admin_url( 'admin-post.php?action=wintracker_check_updates' ), 'wintracker_check_updates' );
			$meta[] = '<a href="' . esc_url( $url ) . '">' . esc_html__( 'Check for updates', 'wintracker' ) . '</a>';
		}
		return $meta;
	}

	/** Asks GitHub and WordPress again right now, then goes back to the Plugins screen. */
	public static function check_now() {
		if ( ! current_user_can( 'update_plugins' ) ) {
			wp_die( esc_html__( 'You are not allowed to update plugins.', 'wintracker' ), 403 );
		}
		check_admin_referer( 'wintracker_check_updates' );
		self::clear();
		delete_site_transient( 'update_plugins' );
		if ( ! function_exists( 'wp_update_plugins' ) ) {
			require_once ABSPATH . WPINC . '/update.php';
		}
		wp_update_plugins();

		$release = self::release();
		if ( ! $release ) {
			$message = array( 'warning', __( 'Wintracker: GitHub could not be reached. Try again in a few minutes.', 'wintracker' ) );
		} elseif ( version_compare( $release['version'], WINTRACKER_VERSION, '>' ) ) {
			/* translators: %s: version number */
			$message = array( 'info', sprintf( __( 'Wintracker %s is available: use "update now" below.', 'wintracker' ), $release['version'] ) );
		} else {
			/* translators: %s: version number */
			$message = array( 'success', sprintf( __( 'Wintracker is up to date (%s).', 'wintracker' ), WINTRACKER_VERSION ) );
		}
		set_transient( 'wintracker_update_notice_' . get_current_user_id(), $message, MINUTE_IN_SECONDS );
		wp_safe_redirect( admin_url( 'plugins.php' ) );
		exit;
	}

	public static function check_notice() {
		$key    = 'wintracker_update_notice_' . get_current_user_id();
		$notice = get_transient( $key );
		if ( is_array( $notice ) ) {
			delete_transient( $key );
			printf( '<div class="notice notice-%s is-dismissible"><p>%s</p></div>', esc_attr( $notice[0] ), esc_html( $notice[1] ) );
		}
	}

	public static function clear() {
		delete_site_transient( self::CACHE );
	}

	private static function token() {
		return defined( 'WINTRACKER_GITHUB_TOKEN' ) ? trim( (string) WINTRACKER_GITHUB_TOKEN ) : '';
	}
}
