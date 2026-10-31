<?php
/**
 * Wintracker → Links: one screen per kind (casinos, providers, games) to see and change which page each
 * Wintracker item links to. Search, status filter, paging; each row saves on its own (AJAX), bulk actions for
 * "automatic" and "don't link". For editors (edit_others_posts).
 */

defined( 'ABSPATH' ) || exit;

final class Wintracker_Links_Editor {

	const PAGE     = 'wintracker-links';
	const CAP      = 'edit_others_posts';
	const PER_PAGE = 50;

	public static function init() {
		add_action( 'admin_menu', array( __CLASS__, 'menu' ), 10 );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'assets' ) );
		add_action( 'wp_ajax_wintracker_link_posts', array( __CLASS__, 'ajax_posts' ) );
		add_action( 'wp_ajax_wintracker_link_set', array( __CLASS__, 'ajax_set' ) );
	}

	public static function url( $kind = '' ) {
		return admin_url( 'admin.php?page=' . self::PAGE . ( $kind ? '&tab=' . rawurlencode( $kind ) : '' ) );
	}

	public static function menu() {
		add_submenu_page( Wintracker_Builder::PAGE, __( 'Wintracker links', 'wintracker' ), __( 'Links', 'wintracker' ), self::CAP, self::PAGE, array( __CLASS__, 'page' ) );
	}

	public static function assets( $hook ) {
		if ( false === strpos( (string) $hook, self::PAGE ) ) {
			return;
		}
		wp_enqueue_style( 'wintracker-admin', plugins_url( 'assets/admin.css', WINTRACKER_FILE ), array(), WINTRACKER_VERSION );
		wp_enqueue_script( 'wintracker-links', plugins_url( 'assets/links.js', WINTRACKER_FILE ), array(), WINTRACKER_VERSION, true );
		wp_localize_script(
			'wintracker-links',
			'wintrackerLinks',
			array(
				'ajax'  => admin_url( 'admin-ajax.php' ),
				'nonce' => wp_create_nonce( 'wintracker_links_editor' ),
				'kind'  => self::tab(),
				'i18n'  => array(
					'search'    => __( 'Type to search pages…', 'wintracker' ),
					'none'      => __( 'No pages found.', 'wintracker' ),
					'saving'    => __( 'Saving…', 'wintracker' ),
					'failed'    => __( 'Could not save. Reload the page and try again.', 'wintracker' ),
					'pickRows'  => __( 'Tick some rows first.', 'wintracker' ),
					'pickBulk'  => __( 'Choose a bulk action first.', 'wintracker' ),
				),
			)
		);
	}

	/** The kinds that are linked to a post type, in display order. */
	private static function kinds() {
		return array_keys( array_filter( Wintracker_Links::types() ) );
	}

	private static function tab() {
		$kinds = self::kinds();
		$tab   = isset( $_GET['tab'] ) ? sanitize_key( wp_unslash( $_GET['tab'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification
		return in_array( $tab, $kinds, true ) ? $tab : (string) ( $kinds[0] ?? '' );
	}

	public static function labels() {
		return array(
			'casino'   => __( 'Casinos', 'wintracker' ),
			'provider' => __( 'Providers', 'wintracker' ),
			'game'     => __( 'Games', 'wintracker' ),
		);
	}

	public static function state_labels() {
		return array(
			'manual'    => __( 'Set by hand', 'wintracker' ),
			'auto'      => __( 'Automatic', 'wintracker' ),
			'blocked'   => __( 'Never linked', 'wintracker' ),
			'ambiguous' => __( 'Several matches', 'wintracker' ),
			'none'      => __( 'No match', 'wintracker' ),
		);
	}

	public static function page() {
		if ( ! current_user_can( self::CAP ) ) {
			return;
		}
		$kinds = self::kinds();
		echo '<div class="wrap wtl"><h1>' . esc_html__( 'Wintracker links', 'wintracker' ) . '</h1>';
		if ( ! $kinds ) {
			echo '<div class="notice notice-info inline"><p>' . esc_html__( 'Links are not set up yet: choose the post types for casinos, providers and games first.', 'wintracker' );
			if ( current_user_can( 'manage_options' ) ) {
				echo ' <a href="' . esc_url( Wintracker_Settings::url() . '#links' ) . '">' . esc_html__( 'Wintracker → Settings → Links to your pages', 'wintracker' ) . '</a>';
			}
			echo '</p></div></div>';
			return;
		}
		$kind   = self::tab();
		$labels = self::labels();
		// phpcs:disable WordPress.Security.NonceVerification -- read-only list filters
		$search = isset( $_GET['s'] ) ? sanitize_text_field( wp_unslash( $_GET['s'] ) ) : '';
		$filter = isset( $_GET['status'] ) ? sanitize_key( wp_unslash( $_GET['status'] ) ) : '';
		$paged  = isset( $_GET['paged'] ) ? max( 1, absint( $_GET['paged'] ) ) : 1;
		// phpcs:enable

		echo '<p class="wtl-intro">' . esc_html__( 'Which of your pages each Wintracker name links to in the widgets. Changes save immediately. "Automatic" matches by name and slug; "Don\'t link" keeps the name as plain text.', 'wintracker' ) . '</p>';
		echo '<nav class="nav-tab-wrapper">';
		foreach ( $kinds as $k ) {
			echo '<a class="nav-tab' . ( $k === $kind ? ' nav-tab-active' : '' ) . '" href="' . esc_url( self::url( $k ) ) . '">' . esc_html( $labels[ $k ] ) . '</a>';
		}
		echo '</nav>';

		$list = self::rows( $kind, $search, $filter, $paged );
		self::toolbar( $kind, $search, $filter, $list );
		if ( null === $list ) {
			echo '<div class="notice notice-warning inline"><p>' . esc_html__( 'The Wintracker API did not answer. Try again in a minute.', 'wintracker' ) . '</p></div></div>';
			return;
		}
		self::table( $kind, $list['rows'] );
		self::pagination( $list['total'], $paged );
		echo '</div>';
	}

	/**
	 * The page of Wintracker items to show, with their status.
	 *
	 * Casinos and providers: the whole cached list, filtered and paged here. Games: the API's name search and
	 * paging (most wins first); the status filter then applies to that page of games.
	 *
	 * @return array{rows: array<int, array<string, mixed>>, total: int}|null
	 */
	private static function rows( $kind, $search, $filter, $paged ) {
		if ( 'game' === $kind ) {
			$r = Wintracker_Client::get(
				'games',
				array(
					'q'      => '' !== $search ? $search : null,
					'limit'  => self::PER_PAGE,
					'offset' => ( $paged - 1 ) * self::PER_PAGE,
				)
			);
			if ( null === $r ) {
				return null;
			}
			$rows = array();
			foreach ( $r['data'] as $g ) {
				$rows[] = array(
					'id'    => (int) $g['id'],
					'name'  => (string) $g['name'],
					'extra' => trim( ( $g['provider']['name'] ?? '' ) . ' · ' . sprintf( /* translators: %s: number of wins */ _n( '%s win', '%s wins', (int) ( $g['wins'] ?? 0 ), 'wintracker' ), number_format_i18n( (int) ( $g['wins'] ?? 0 ) ) ), ' ·' ),
				);
			}
			$rows = self::with_status( $kind, $rows, $filter );
			return array(
				'rows'  => $rows,
				'total' => (int) ( $r['meta']['total'] ?? count( $rows ) ),
			);
		}
		$r = Wintracker_Client::get( 'casino' === $kind ? 'casinos' : 'providers' );
		if ( null === $r ) {
			return null;
		}
		$rows = array();
		foreach ( $r['data'] as $e ) {
			if ( '' !== $search && false === mb_stripos( (string) $e['name'], $search ) && false === mb_stripos( (string) ( $e['slug'] ?? '' ), $search ) ) {
				continue;
			}
			$rows[] = array(
				'id'    => (int) $e['id'],
				'name'  => (string) $e['name'],
				'extra' => (string) ( $e['slug'] ?? '' ) . ( isset( $e['wins'] ) ? ' · ' . sprintf( /* translators: %s: number of wins */ _n( '%s win', '%s wins', (int) $e['wins'], 'wintracker' ), number_format_i18n( (int) $e['wins'] ) ) : '' ),
			);
		}
		usort(
			$rows,
			static function ( $a, $b ) {
				return strcasecmp( $a['name'], $b['name'] );
			}
		);
		$rows  = self::with_status( $kind, $rows, $filter );
		$total = count( $rows );
		return array(
			'rows'  => array_slice( $rows, ( $paged - 1 ) * self::PER_PAGE, self::PER_PAGE ),
			'total' => $total,
		);
	}

	private static function with_status( $kind, array $rows, $filter ) {
		$out = array();
		foreach ( $rows as $row ) {
			$row += Wintracker_Links::status( $kind, $row['id'] );
			$keep = '' === $filter
				|| ( 'linked' === $filter && $row['post'] > 0 && 'blocked' !== $row['state'] )
				|| ( 'unlinked' === $filter && in_array( $row['state'], array( 'none', 'ambiguous' ), true ) )
				|| $filter === $row['state'];
			if ( $keep ) {
				$out[] = $row;
			}
		}
		return $out;
	}

	private static function toolbar( $kind, $search, $filter, $list ) {
		$filters = array(
			''          => __( 'All', 'wintracker' ),
			'linked'    => __( 'Linked', 'wintracker' ),
			'unlinked'  => __( 'Not linked', 'wintracker' ),
			'manual'    => __( 'Set by hand', 'wintracker' ),
			'ambiguous' => __( 'Several matches', 'wintracker' ),
			'blocked'   => __( 'Never linked', 'wintracker' ),
		);
		?>
		<form class="wtl-toolbar" method="get">
			<input type="hidden" name="page" value="<?php echo esc_attr( self::PAGE ); ?>">
			<input type="hidden" name="tab" value="<?php echo esc_attr( $kind ); ?>">
			<label class="screen-reader-text" for="wtl-s"><?php esc_html_e( 'Search', 'wintracker' ); ?></label>
			<input type="search" id="wtl-s" name="s" value="<?php echo esc_attr( $search ); ?>" placeholder="<?php esc_attr_e( 'Search by name…', 'wintracker' ); ?>">
			<select name="status" aria-label="<?php esc_attr_e( 'Status', 'wintracker' ); ?>">
				<?php foreach ( $filters as $value => $label ) : ?>
					<option value="<?php echo esc_attr( $value ); ?>" <?php selected( $filter, $value ); ?>><?php echo esc_html( $label ); ?></option>
				<?php endforeach; ?>
			</select>
			<button class="button"><?php esc_html_e( 'Filter', 'wintracker' ); ?></button>
			<span class="wtl-bulk">
				<select id="wtl-bulk" aria-label="<?php esc_attr_e( 'Bulk action', 'wintracker' ); ?>">
					<option value=""><?php esc_html_e( 'Bulk action', 'wintracker' ); ?></option>
					<option value="auto"><?php esc_html_e( 'Set to automatic', 'wintracker' ); ?></option>
					<option value="none"><?php esc_html_e( 'Don\'t link', 'wintracker' ); ?></option>
				</select>
				<button type="button" class="button" id="wtl-bulk-apply"><?php esc_html_e( 'Apply', 'wintracker' ); ?></button>
			</span>
			<?php if ( 'game' === $kind && '' !== $filter ) : ?>
				<p class="description"><?php esc_html_e( 'Games are paged by the API (most wins first); the status filter applies to the games on this page. Search by name to find a specific game.', 'wintracker' ); ?></p>
			<?php endif; ?>
		</form>
		<?php
	}

	private static function table( $kind, array $rows ) {
		$ids = array_values( array_filter( array_column( $rows, 'post' ) ) );
		if ( $ids ) {
			get_posts(
				array(
					'post__in'               => $ids,
					'post_type'              => 'any',
					'post_status'            => 'any',
					'posts_per_page'         => count( $ids ),
					'no_found_rows'          => true,
					'update_post_meta_cache' => false,
				)
			);
		}
		?>
		<table class="widefat striped wtl-table">
			<thead>
				<tr>
					<td class="check-column"><input type="checkbox" id="wtl-all" aria-label="<?php esc_attr_e( 'Select all', 'wintracker' ); ?>"></td>
					<th><?php echo esc_html( self::labels()[ $kind ] ); ?></th>
					<th><?php esc_html_e( 'Status', 'wintracker' ); ?></th>
					<th><?php esc_html_e( 'Links to', 'wintracker' ); ?></th>
					<th><?php esc_html_e( 'Change', 'wintracker' ); ?></th>
				</tr>
			</thead>
			<tbody>
				<?php if ( ! $rows ) : ?>
					<tr><td colspan="5"><?php esc_html_e( 'Nothing found.', 'wintracker' ); ?></td></tr>
				<?php endif; ?>
				<?php foreach ( $rows as $row ) : ?>
					<tr data-id="<?php echo esc_attr( (string) $row['id'] ); ?>">
						<th scope="row" class="check-column"><input type="checkbox" class="wtl-check" value="<?php echo esc_attr( (string) $row['id'] ); ?>" aria-label="<?php echo esc_attr( $row['name'] ); ?>"></th>
						<td><strong><?php echo esc_html( $row['name'] ); ?></strong><br><small class="wtl-muted"><?php echo esc_html( '#' . $row['id'] . ( '' !== $row['extra'] ? ' · ' . $row['extra'] : '' ) ); ?></small></td>
						<td class="wtl-status"><?php echo self::status_html( $row['state'] ); // phpcs:ignore WordPress.Security.EscapeOutput ?></td>
						<td class="wtl-page"><?php echo self::page_html( $row['post'], $row['state'] ); // phpcs:ignore WordPress.Security.EscapeOutput ?></td>
						<td class="wtl-actions">
							<div class="wtl-pick">
								<input type="search" class="wtl-q" placeholder="<?php esc_attr_e( 'Link to page…', 'wintracker' ); ?>" aria-label="<?php esc_attr_e( 'Search your pages', 'wintracker' ); ?>">
								<ul class="wtl-results" hidden></ul>
							</div>
							<button type="button" class="button-link wtl-auto"><?php esc_html_e( 'Automatic', 'wintracker' ); ?></button>
							<button type="button" class="button-link wtl-none"><?php esc_html_e( 'Don\'t link', 'wintracker' ); ?></button>
						</td>
					</tr>
				<?php endforeach; ?>
			</tbody>
		</table>
		<?php
	}

	public static function status_html( $state ) {
		$labels = self::state_labels();
		return '<span class="wtl-badge wtl-badge-' . esc_attr( $state ) . '">' . esc_html( $labels[ $state ] ?? $state ) . '</span>';
	}

	public static function page_html( $post_id, $state ) {
		$post = $post_id > 0 ? get_post( $post_id ) : null;
		if ( ! $post || 'blocked' === $state ) {
			return '<span class="wtl-muted">—</span>';
		}
		$html = '<a href="' . esc_url( get_permalink( $post ) ) . '" target="_blank">' . esc_html( get_the_title( $post ) ) . '</a>';
		if ( 'publish' !== $post->post_status ) {
			/* translators: %s: post status, e.g. draft */
			$html .= ' <span class="wtl-muted">' . esc_html( sprintf( __( '(%s: not linked until published)', 'wintracker' ), $post->post_status ) ) . '</span>';
		}
		$edit = get_edit_post_link( $post, 'raw' );
		if ( $edit ) {
			$html .= ' <a class="wtl-edit" href="' . esc_url( $edit ) . '">' . esc_html__( 'Edit', 'wintracker' ) . '</a>';
		}
		return $html;
	}

	private static function pagination( $total, $paged ) {
		$pages = (int) ceil( $total / self::PER_PAGE );
		if ( $pages < 2 ) {
			/* translators: %s: number of items */
			echo '<p class="wtl-muted">' . esc_html( sprintf( _n( '%s item', '%s items', $total, 'wintracker' ), number_format_i18n( $total ) ) ) . '</p>';
			return;
		}
		echo '<div class="tablenav"><div class="tablenav-pages">' . paginate_links( // phpcs:ignore WordPress.Security.EscapeOutput
			array(
				'base'    => add_query_arg( 'paged', '%#%' ),
				'format'  => '',
				'current' => $paged,
				'total'   => $pages,
			)
		) . '</div></div>';
	}

	/* ------------------------------------------------------------------ AJAX */

	private static function guard() {
		check_ajax_referer( 'wintracker_links_editor' );
		if ( ! current_user_can( self::CAP ) ) {
			wp_send_json_error( null, 403 );
		}
	}

	/** Pages of the kind's post type whose title contains the search (any status but trash). */
	public static function ajax_posts() {
		self::guard();
		$kind = isset( $_GET['kind'] ) ? sanitize_key( wp_unslash( $_GET['kind'] ) ) : '';
		$type = Wintracker_Links::types()[ $kind ] ?? '';
		$q    = isset( $_GET['q'] ) ? sanitize_text_field( wp_unslash( $_GET['q'] ) ) : '';
		if ( '' === $type || mb_strlen( $q ) < 2 ) {
			wp_send_json_success( array() );
		}
		global $wpdb;
		$rows = $wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->prepare(
				"SELECT ID, post_title, post_status FROM {$wpdb->posts}
				 WHERE post_type = %s AND post_status IN ('publish', 'draft', 'pending', 'future', 'private') AND post_title LIKE %s
				 ORDER BY post_status = 'publish' DESC, CHAR_LENGTH(post_title), post_title LIMIT 20",
				$type,
				'%' . $wpdb->esc_like( $q ) . '%'
			),
			ARRAY_A
		);
		wp_send_json_success(
			array_map(
				static function ( $r ) {
					return array(
						'id'     => (int) $r['ID'],
						'title'  => html_entity_decode( (string) $r['post_title'], ENT_QUOTES, 'UTF-8' ),
						'status' => 'publish' === $r['post_status'] ? '' : (string) $r['post_status'],
					);
				},
				(array) $rows
			)
		);
	}

	/** Sets one or more items: action "post" (with post_id), "auto" or "none". Returns each row's new cells. */
	public static function ajax_set() {
		self::guard();
		$kind = isset( $_POST['kind'] ) ? sanitize_key( wp_unslash( $_POST['kind'] ) ) : '';
		$type = Wintracker_Links::types()[ $kind ] ?? '';
		$ids  = isset( $_POST['ids'] ) ? array_filter( array_map( 'absint', (array) wp_unslash( $_POST['ids'] ) ) ) : array(); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
		$act  = isset( $_POST['act'] ) ? sanitize_key( wp_unslash( $_POST['act'] ) ) : '';
		$post = isset( $_POST['post_id'] ) ? absint( $_POST['post_id'] ) : 0;
		if ( '' === $type || ! $ids || ! in_array( $act, array( 'post', 'auto', 'none' ), true ) ) {
			wp_send_json_error( null, 400 );
		}
		if ( 'post' === $act ) {
			$target = get_post( $post );
			if ( ! $target || $target->post_type !== $type || count( $ids ) !== 1 ) {
				wp_send_json_error( null, 400 );
			}
		}
		foreach ( array_slice( $ids, 0, 200 ) as $id ) {
			Wintracker_Links::set_manual( $kind, $id, 'post' === $act ? $post : ( 'none' === $act ? 0 : null ) );
		}
		// Return every row on the page that may have changed (a page can move from one item to another).
		$shown = isset( $_POST['shown'] ) ? array_filter( array_map( 'absint', (array) wp_unslash( $_POST['shown'] ) ) ) : $ids; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
		$rows  = array();
		foreach ( array_slice( $shown, 0, 200 ) as $id ) {
			$status      = Wintracker_Links::status( $kind, $id );
			$rows[ $id ] = array(
				'status' => self::status_html( $status['state'] ),
				'page'   => self::page_html( $status['post'], $status['state'] ),
			);
		}
		wp_send_json_success( $rows );
	}
}
