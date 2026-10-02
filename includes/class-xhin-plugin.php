<?php
/**
 * Hooks, AJAX endpoints, background runner, cron and the isolation guard.
 *
 * @package XhinMigration
 */

defined( 'ABSPATH' ) || exit;

final class AppAlbania_Xhin_Plugin {

	const TOKEN_ACTIONS = array( 'xhin_step', 'xhin_status', 'xhin_cancel', 'xhin_upload', 'xhin_retry' );

	public static function boot() {
		register_activation_hook( APPALBANIA_XHIN_FILE, array( __CLASS__, 'activate' ) );
		register_deactivation_hook( APPALBANIA_XHIN_FILE, array( __CLASS__, 'deactivate' ) );

		add_action( 'wp_ajax_xhin_export', array( __CLASS__, 'ajax_export' ) );
		add_action( 'wp_ajax_xhin_import', array( __CLASS__, 'ajax_import' ) );
		add_action( 'wp_ajax_xhin_list', array( __CLASS__, 'ajax_list' ) );
		add_action( 'wp_ajax_xhin_inspect', array( __CLASS__, 'ajax_inspect' ) );
		add_action( 'wp_ajax_xhin_delete', array( __CLASS__, 'ajax_delete' ) );
		add_action( 'wp_ajax_xhin_link', array( __CLASS__, 'ajax_link' ) );
		add_action( 'wp_ajax_xhin_download', array( __CLASS__, 'ajax_download' ) );
		add_action( 'wp_ajax_xhin_settings', array( __CLASS__, 'ajax_settings' ) );
		add_action( 'wp_ajax_xhin_dismiss', array( __CLASS__, 'ajax_dismiss' ) );
		foreach ( self::TOKEN_ACTIONS as $a ) {
			add_action( 'wp_ajax_' . $a, array( __CLASS__, 'ajax_token' ) );
			add_action( 'wp_ajax_nopriv_' . $a, array( __CLASS__, 'ajax_token' ) );
		}
		add_action( 'wp_ajax_xhin_pull', array( __CLASS__, 'ajax_pull' ) );
		add_action( 'wp_ajax_nopriv_xhin_pull', array( __CLASS__, 'ajax_pull' ) );

		self::clean_ajax_output();
		add_action( 'init', array( __CLASS__, 'maintain' ) );

		add_filter( 'cron_schedules', array( __CLASS__, 'cron_schedules' ) );
		add_action( 'xhin_watchdog', array( __CLASS__, 'watchdog' ) );
		add_action( 'xhin_scheduled_backup', array( __CLASS__, 'scheduled_backup' ) );

		if ( is_admin() && ! defined( 'APPALBANIA_XHIN_ISOLATED' ) ) {
			AppAlbania_Xhin_Admin::boot();
		}
		if ( defined( 'WP_CLI' ) && WP_CLI ) {
			WP_CLI::add_command( 'xhin', 'AppAlbania_Xhin_CLI' );
			if ( AppAlbania_Xhin_Storage::owner() ) {
				register_shutdown_function(
					function () {
						AppAlbania_Xhin_Storage::own( AppAlbania_Xhin_Storage::dir(), true );
						AppAlbania_Xhin_Storage::own( WPMU_PLUGIN_DIR . '/0-xhin-guard.php' );
					}
				);
			}
		}
	}

	/**
	 * Other plugins (or WP_DEBUG notices) sometimes print text into AJAX responses,
	 * which breaks JSON. For our JSON endpoints: hide PHP notices from the output
	 * and drop anything printed before our JSON. File downloads are left alone.
	 */
	private static function clean_ajax_output() {
		if ( ! wp_doing_ajax() || ! isset( $_REQUEST['action'] ) || ! is_string( $_REQUEST['action'] ) ) { // phpcs:ignore
			return;
		}
		$action = (string) $_REQUEST['action']; // phpcs:ignore
		if ( 0 !== strpos( $action, 'xhin_' ) || in_array( $action, array( 'xhin_download', 'xhin_pull' ), true ) ) {
			return;
		}
		@ini_set( 'display_errors', '0' ); // phpcs:ignore -- errors still reach the log.
		ob_start(
			function ( $buf ) {
				$p = strpos( $buf, '{"success":' );
				return ( false === $p || 0 === $p ) ? $buf : substr( $buf, $p );
			}
		);
	}

	public static function settings( $fresh = false ) {
		static $cache = null; // under wp_installing() every get_option() is a query — cache per request.
		if ( null !== $cache && ! $fresh ) {
			return $cache;
		}
		$d = array(
			'slice'      => 20,
			'chunk_mb'   => 2,
			'codec'      => 'deflate',
			'verify'     => 1,
			'background' => 1,
			'schedule'   => 'off',
			'keep'       => 5,
			'sched_core' => 0,
			'excludes'   => '',
		);
		$cache = wp_parse_args( (array) get_option( 'xhin_settings', array() ), $d );
		return $cache;
	}

	/** Seconds per slice, kept well under the host's max_execution_time. */
	public static function budget() {
		$s   = max( 5, min( 120, (int) self::settings()['slice'] ) );
		$max = (int) ini_get( 'max_execution_time' );
		if ( $max > 0 ) {
			$s = min( $s, max( 5, (int) floor( $max * 0.6 ) ) );
		}
		return $s;
	}

	public static function chunk_upload_size() {
		$post = wp_convert_hr_to_bytes( (string) ini_get( 'post_max_size' ) );
		$max  = 32 * 1048576; // big pieces = fewer requests; the browser halves it on HTTP 413.
		if ( $post > 0 ) {
			$max = min( $max, (int) ( $post * 0.9 ) );
		}
		return max( 262144, $max );
	}

	/* ------------------------------------------------------------ lifecycle */

	public static function activate() {
		try {
			AppAlbania_Xhin_Storage::ensure();
		} catch ( Throwable $e ) { // phpcs:ignore
		}
		self::install_guard();
		self::reschedule();
	}

	public static function deactivate() {
		wp_delete_file( WPMU_PLUGIN_DIR . '/0-xhin-guard.php' );
		wp_clear_scheduled_hook( 'xhin_watchdog' );
		wp_clear_scheduled_hook( 'xhin_scheduled_backup' );
	}

	/**
	 * Tiny must-use loader: for Xhin runner requests it calls wp_installing(true)
	 * BEFORE regular plugins load, so WordPress boots with no plugins and no theme
	 * — only Xhin. A half-restored or broken plugin can never kill the migration.
	 */
	public static function install_guard() {
		$dir = WPMU_PLUGIN_DIR;
		if ( ! is_dir( $dir ) ) {
			wp_mkdir_p( $dir );
		}
		if ( ! is_dir( $dir ) || ! wp_is_writable( $dir ) ) {
			return false;
		}
		$actions = "'xhin_step', 'xhin_status', 'xhin_upload', 'xhin_cancel', 'xhin_retry', 'xhin_pull'";
		$code    = "<?php\n/**\n * Plugin Name: Migration by AppAlbania — Runner Guard\n * Description: Runs Xhin backup/restore requests in isolation (no other plugins or theme) so nothing can interrupt a migration. Installed by Migration by AppAlbania, removed when it is deactivated.\n * Version: " . APPALBANIA_XHIN_VERSION . "\n */\n\n"
			. "defined( 'ABSPATH' ) || exit;\n\n"
			. "if ( ! defined( 'XHIN_NO_ISOLATION' ) && isset( \$_REQUEST['action'] ) && is_string( \$_REQUEST['action'] ) && in_array( \$_REQUEST['action'], array( {$actions} ), true ) ) {\n"
			. "\t\$xhin_main = WP_PLUGIN_DIR . '/" . APPALBANIA_XHIN_BASENAME . "';\n"
			. "\tif ( is_file( \$xhin_main ) ) {\n"
			. "\t\tdefine( 'APPALBANIA_XHIN_ISOLATED', true );\n"
			. "\t\twp_installing( true ); // skips all regular plugins and the theme for this request.\n"
			. "\t\trequire_once \$xhin_main;\n"
			. "\t}\n}\n";
		$file = $dir . '/0-xhin-guard.php';
		if ( ! file_exists( $file ) || file_get_contents( $file ) !== $code ) {
			return false !== @file_put_contents( $file, $code );
		}
		return true;
	}

	/** After an update (any request, not only an admin page view): refresh the guard file. */
	public static function maintain() {
		if ( get_option( 'xhin_guard_v' ) !== APPALBANIA_XHIN_VERSION ) {
			update_option( 'xhin_guard_v', APPALBANIA_XHIN_VERSION, true ); // set first: never retried in a loop.
			self::install_guard();
		}
	}

	public static function guard_active() {
		return file_exists( WPMU_PLUGIN_DIR . '/0-xhin-guard.php' );
	}

	/* ------------------------------------------------------------ runners */

	/** Fire-and-forget loopback so the job continues with no browser open. */
	public static function kick( $token ) {
		wp_remote_post(
			admin_url( 'admin-ajax.php?action=xhin_step' ),
			array(
				'blocking'  => false,
				'timeout'   => 1,
				'sslverify' => false,
				'body'      => array( 'token' => $token, 'bg' => 1 ),
				'headers'   => array( 'Cache-Control' => 'no-cache' ),
			)
		);
	}

	public static function cron_schedules( $s ) {
		$s['xhin_minute'] = array( 'interval' => 60, 'display' => 'Every minute (Xhin watchdog)' );
		$s['xhin_weekly'] = array( 'interval' => WEEK_IN_SECONDS, 'display' => 'Weekly (Xhin)' );
		return $s;
	}

	/** If a background job has gone quiet (host killed the chain), restart it. */
	public static function watchdog() {
		$j = AppAlbania_Xhin_Job::current();
		if ( ! $j || 'running' !== $j->s['status'] ) {
			if ( ! $j ) {
				wp_clear_scheduled_hook( 'xhin_watchdog' );
			}
			return;
		}
		if ( ! empty( $j->s['opts']['background'] ) && time() - (int) $j->s['updated'] > 45 ) {
			self::kick( $j->s['token'] );
		}
	}

	public static function ensure_watchdog() {
		if ( ! wp_next_scheduled( 'xhin_watchdog' ) ) {
			wp_schedule_event( time() + 60, 'xhin_minute', 'xhin_watchdog' );
		}
	}

	public static function reschedule() {
		wp_clear_scheduled_hook( 'xhin_scheduled_backup' );
		$s = self::settings();
		if ( in_array( $s['schedule'], array( 'daily', 'weekly' ), true ) ) {
			wp_schedule_event( time() + HOUR_IN_SECONDS, 'daily' === $s['schedule'] ? 'daily' : 'xhin_weekly', 'xhin_scheduled_backup' );
		}
	}

	public static function scheduled_backup() {
		$cur = AppAlbania_Xhin_Job::current();
		if ( $cur && 'running' === $cur->s['status'] ) {
			return;
		}
		try {
			$j = AppAlbania_Xhin_Job::create( 'export', self::export_defaults( array( 'core' => (int) self::settings()['sched_core'], 'auto' => 1, 'background' => 1 ) ) );
			self::ensure_watchdog();
			self::kick( $j->s['token'] );
		} catch ( Throwable $e ) { // phpcs:ignore
		}
	}

	public static function apply_retention() {
		$keep = max( 1, (int) self::settings()['keep'] );
		$auto = array_values( array_filter( AppAlbania_Xhin_Storage::archives(), function ( $a ) { return false !== strpos( $a['name'], '-auto-' ); } ) );
		foreach ( array_slice( $auto, $keep ) as $a ) {
			wp_delete_file( AppAlbania_Xhin_Storage::dir() . '/' . $a['name'] );
		}
	}

	public static function export_defaults( array $over = array() ) {
		return array_merge(
			array(
				'db'         => 1,
				'media'      => 1,
				'plugins'    => 1,
				'themes'     => 1,
				'muplugins'  => 1,
				'other'      => 1,
				'core'       => 0,
				'password'   => '',
				'excludes'   => '',
				'background' => (int) self::settings()['background'],
			),
			$over
		);
	}

	/* --------------------------------------------------------------- AJAX */

	private static function admin_check() {
		if ( ! current_user_can( 'manage_options' ) || ! check_ajax_referer( 'xhin', 'nonce', false ) ) {
			wp_send_json_error( array( 'message' => 'Permission denied — reload the page.' ), 403 );
		}
	}

	private static function fail( Exception $e ) {
		wp_send_json_error( array( 'message' => AppAlbania_Xhin_Exception::text( $e ) ), 400 );
	}

	public static function ajax_export() {
		self::admin_check(); // capability + nonce.
		$p    = wp_unslash( $_POST ); // phpcs:ignore WordPress.Security.NonceVerification.Missing -- nonce verified in admin_check(); every field is sanitized below.
		$opts = self::export_defaults();
		foreach ( array( 'db', 'media', 'plugins', 'themes', 'muplugins', 'other', 'core' ) as $k ) {
			$opts[ $k ] = empty( $p[ $k ] ) ? 0 : 1;
		}
		$opts['password'] = (string) ( $p['password'] ?? '' );
		$opts['excludes'] = (string) ( $p['excludes'] ?? '' );
		if ( ! array_filter( array_intersect_key( $opts, array_flip( array( 'db', 'media', 'plugins', 'themes', 'muplugins', 'other', 'core' ) ) ) ) ) {
			wp_send_json_error( array( 'message' => 'Select at least one thing to back up.' ), 400 );
		}
		try {
			self::install_guard();
			$j = AppAlbania_Xhin_Job::create( 'export', $opts );
			if ( $opts['background'] ) {
				self::ensure_watchdog();
			}
			wp_send_json_success( array( 'token' => $j->s['token'] ) + $j->public_state() );
		} catch ( Throwable $e ) {
			self::fail( $e );
		}
	}

	public static function ajax_import() {
		self::admin_check(); // capability + nonce.
		$p    = wp_unslash( $_POST ); // phpcs:ignore WordPress.Security.NonceVerification.Missing -- nonce verified in admin_check(); every field is sanitized below.
		$opts = array(
			'source'       => sanitize_key( $p['source'] ?? '' ),
			'name'         => (string) ( $p['name'] ?? '' ),
			'url'          => esc_url_raw( trim( (string) ( $p['url'] ?? '' ), " \n\r\t\v\0" ) ),
			'filename'     => (string) ( $p['filename'] ?? '' ),
			'size'         => (int) ( $p['size'] ?? 0 ),
			'password'     => (string) ( $p['password'] ?? '' ),
			'skip_dropins' => empty( $p['skip_dropins'] ) ? 0 : 1,
			'background'   => 'upload' === ( $p['source'] ?? '' ) ? 0 : (int) self::settings()['background'],
		);
		$all  = array( 'db', 'media', 'plugins', 'themes', 'muplugins', 'other', 'core' );
		if ( isset( $p['parts'] ) ) {
			$opts['parts'] = array_values( array_intersect( $all, explode( ',', (string) $p['parts'] ) ) );
			if ( ! $opts['parts'] ) {
				wp_send_json_error( array( 'message' => 'Choose at least one thing to restore.' ), 400 );
			}
		}
		try {
			self::install_guard();
			$j = AppAlbania_Xhin_Job::create( 'import', $opts );
			if ( $opts['background'] ) {
				self::ensure_watchdog();
			}
			wp_send_json_success( array( 'token' => $j->s['token'], 'chunk' => self::chunk_upload_size() ) + $j->public_state() );
		} catch ( Throwable $e ) {
			self::fail( $e );
		}
	}

	/** Token-authorised runner endpoints (they keep working after the DB swap logs everyone out). */
	public static function ajax_token() {
		$action = current_action();
		$action = substr( $action, strpos( $action, 'xhin_' ) );
		$token  = (string) ( $_REQUEST['token'] ?? '' ); // phpcs:ignore
		$j      = AppAlbania_Xhin_Job::by_token( $token );
		if ( ! $j ) {
			$last = AppAlbania_Xhin_Job::last( $token );
			if ( $last ) {
				wp_send_json_success( $last );
			}
			wp_send_json_error( array( 'message' => 'Job not found (finished or cancelled).', 'gone' => true ), 404 );
		}
		try {
			switch ( $action ) {
				case 'xhin_step':
					if ( ! empty( $_REQUEST['bg'] ) && empty( $j->s['opts']['background'] ) ) { // phpcs:ignore
						wp_send_json_success( $j->public_state() );
					}
					wp_send_json_success( $j->step( self::budget() ) );
					break;
				case 'xhin_status':
					wp_send_json_success( $j->public_state() );
					break;
				case 'xhin_cancel':
					wp_send_json_success( $j->request_cancel() );
					break;
				case 'xhin_retry':
					$j->retry();
					wp_send_json_success( $j->public_state() );
					break;
				case 'xhin_upload':
					$in = fopen( 'php://input', 'rb' );
					try {
						$got = $j->receive_chunk( (int) ( $_GET['offset'] ?? 0 ), $in ); // phpcs:ignore
					} catch ( AppAlbania_Xhin_Exception $e ) {
						if ( 'busy' === AppAlbania_Xhin_Exception::text( $e ) ) {
							wp_send_json_error( array( 'busy' => true, 'message' => 'busy' ), 409 );
						}
						throw $e;
					}
					wp_send_json_success( array( 'received' => $got ) + $j->public_state() );
					break;
			}
		} catch ( Throwable $e ) {
			self::fail( $e );
		}
	}

	public static function ajax_list() {
		self::admin_check();
		wp_send_json_success( self::archives_for_ui() );
	}

	public static function archives_for_ui() {
		return array_map(
			function ( $a ) {
				$a['human'] = AppAlbania_Xhin_Storage::human( $a['size'] );
				$a['date']  = self::when( $a['time'] );
				return $a;
			},
			AppAlbania_Xhin_Storage::archives()
		);
	}

	public static function ajax_inspect() {
		self::admin_check();
		$p = AppAlbania_Xhin_Storage::path( wp_unslash( $_POST['name'] ?? '' ) ); // phpcs:ignore
		if ( ! $p ) {
			wp_send_json_error( array( 'message' => 'File not found.' ), 404 );
		}
		try {
			$i         = AppAlbania_Xhin_Job::inspect( $p, (string) wp_unslash( $_POST['password'] ?? '' ) ); // phpcs:ignore
			$i['date'] = self::when( $i['created'] );
			$i['size'] = AppAlbania_Xhin_Storage::human( AppAlbania_Xhin_Storage::filesize( $p ) );
			wp_send_json_success( $i );
		} catch ( Throwable $e ) {
			self::fail( $e );
		}
	}

	/** "1 Oct 2026, 14:28" in the site's timezone and formats. */
	public static function when( $ts ) {
		return wp_date( get_option( 'date_format', 'j M Y' ) . ', ' . get_option( 'time_format', 'H:i' ), (int) $ts );
	}

	public static function ajax_delete() {
		self::admin_check();
		$p = AppAlbania_Xhin_Storage::path( wp_unslash( $_POST['name'] ?? '' ) ); // phpcs:ignore
		if ( $p ) {
			wp_delete_file( $p );
		}
		wp_send_json_success( self::archives_for_ui() );
	}

	public static function ajax_link() {
		self::admin_check();
		$name = basename( (string) wp_unslash( $_POST['name'] ?? '' ) ); // phpcs:ignore
		if ( ! AppAlbania_Xhin_Storage::path( $name ) ) {
			wp_send_json_error( array( 'message' => 'File not found.' ), 404 );
		}
		wp_send_json_success( array( 'url' => AppAlbania_Xhin_Storage::pull_url( $name ), 'expires' => '24 hours' ) );
	}

	public static function ajax_download() {
		if ( ! current_user_can( 'manage_options' ) || ! wp_verify_nonce( (string) ( $_GET['nonce'] ?? '' ), 'xhin' ) ) { // phpcs:ignore
			wp_die( 'Permission denied.', 403 );
		}
		$p = AppAlbania_Xhin_Storage::path( wp_unslash( $_GET['f'] ?? '' ) ); // phpcs:ignore
		if ( ! $p ) {
			wp_die( 'File not found.', 404 );
		}
		AppAlbania_Xhin_Storage::stream( $p );
	}

	/** Signed, expiring, Range-capable link for server-to-server migration. */
	public static function ajax_pull() {
		$name = basename( rawurldecode( (string) ( $_GET['f'] ?? '' ) ) ); // phpcs:ignore
		$p    = AppAlbania_Xhin_Storage::path( $name );
		if ( ! $p || ! AppAlbania_Xhin_Storage::verify_pull( $name, $_GET['e'] ?? 0, $_GET['s'] ?? '' ) ) { // phpcs:ignore
			status_header( 403 );
			exit( 'Invalid or expired link.' );
		}
		AppAlbania_Xhin_Storage::stream( $p );
	}

	public static function ajax_settings() {
		self::admin_check(); // capability + nonce.
		$p = wp_unslash( $_POST ); // phpcs:ignore WordPress.Security.NonceVerification.Missing -- nonce verified in admin_check(); every field is sanitized below.
		$s = array(
			'slice'      => max( 5, min( 120, (int) ( $p['slice'] ?? 20 ) ) ),
			'chunk_mb'   => max( 1, min( 16, (int) ( $p['chunk_mb'] ?? 2 ) ) ),
			'codec'      => in_array( $p['codec'] ?? '', array( 'none', 'deflate', 'zstd' ), true ) ? $p['codec'] : 'deflate',
			'verify'     => empty( $p['verify'] ) ? 0 : 1,
			'background' => empty( $p['background'] ) ? 0 : 1,
			'schedule'   => in_array( $p['schedule'] ?? '', array( 'off', 'daily', 'weekly' ), true ) ? $p['schedule'] : 'off',
			'keep'       => max( 1, min( 100, (int) ( $p['keep'] ?? 5 ) ) ),
			'sched_core' => empty( $p['sched_core'] ) ? 0 : 1,
			'excludes'   => sanitize_textarea_field( (string) ( $p['excludes'] ?? '' ) ),
		);
		update_option( 'xhin_settings', $s, false );
		self::settings( true );
		self::reschedule();
		wp_send_json_success( $s );
	}

	public static function ajax_dismiss() {
		self::admin_check();
		wp_delete_file( AppAlbania_Xhin_Storage::jobs_dir() . '/last.json' );
		wp_send_json_success();
	}
}
