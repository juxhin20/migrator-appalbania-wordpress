<?php
/**
 * Job state machine. One job = one export or one import, living in
 * wp-content/xhin-backups/.jobs-<random>/<id>/ (NOT in the database — the database is
 * what we replace during a restore).
 *
 * Every stage is a resumable slice. step() runs slices until its time budget
 * is spent, saves a cursor and returns; any runner (browser loop, loopback
 * background chain, WP-Cron watchdog, WP-CLI) can call step() again. A flock
 * guarantees only one runner works at a time.
 *
 * @package XhinMigration
 */

defined( 'ABSPATH' ) || exit;

// phpcs:disable WordPress.WP.AlternativeFunctions -- Backup archives (often many GB) are streamed with fopen/fread/fwrite,
// fseek, ftruncate and flock, and folders are swapped with rename(). WP_Filesystem offers no streaming, seeking,
// appending or locking. Every path used is inside this site or its own backup folder.

final class AppAlbania_Xhin_Job {

	const EXPORT = array(
		'init'     => array( 1, 'Preparing' ),
		'database' => array( 14, 'Database' ),
		'scan'     => array( 5, 'Scanning files' ),
		'pack'     => array( 70, 'Packing archive' ),
		'finish'   => array( 1, 'Sealing' ),
		'check'    => array( 9, 'Verifying' ),
	);

	const IMPORT = array(
		'receive'    => array( 20, 'Receiving archive' ),
		'verify'     => array( 1, 'Verifying' ),
		'extract'    => array( 50, 'Restoring files' ),
		'db_import'  => array( 18, 'Importing database' ),
		'db_replace' => array( 8, 'Replacing URLs & paths' ),
		'db_prepare' => array( 1, 'Preparing tables' ),
		'db_swap'    => array( 1, 'Activating database' ),
		'finalize'   => array( 1, 'Finalizing' ),
		'upgrade'    => array( 1, 'Updating database' ),
	);

	/** Top-level wp-content folders never worth backing up. */
	const SKIP_CONTENT = array( 'cache', 'upgrade', 'upgrade-temp-backup', 'ai1wm-backups', 'updraft', 'backups-dup-lite', 'backups-dup-pro', 'duplicator-backups', 'wpvividbackups', 'backuply', 'backups-backuply', 'wp-staging', 'et-cache', 'litespeed', 'debug.log' );

	/** Other backup plugins' archives inside uploads (BackWPup, WP Migrate…): never worth copying. */
	const SKIP_UPLOADS = array( 'uploads/backwpup-*', 'uploads/wp-migrate-db', 'uploads/wpvivid_uploads', 'uploads/wp-staging' );

	public $id;
	public $dir;
	private $slice = 20.0;
	public $s = array();

	private $lock;
	private $deadline = 0.0;
	private $in_step  = false;
	private $last_save = 0.0;

	/* ============================================================ lifecycle */

	public static function current_id() {
		$f = AppAlbania_Xhin_Storage::jobs_dir() . '/current';
		return is_file( $f ) ? trim( (string) file_get_contents( $f ), " \n\r\t\v\0" ) : '';
	}

	public static function current() {
		$id = self::current_id();
		return $id ? self::load( $id ) : null;
	}

	public static function load( $id ) {
		if ( ! preg_match( '/^[a-z0-9-]{10,40}$/', $id ) ) {
			return null;
		}
		$j      = new self();
		$j->id  = $id;
		$j->dir = AppAlbania_Xhin_Storage::jobs_dir() . '/' . $id;
		return $j->reload() ? $j : null;
	}

	public static function by_token( $token ) {
		$j = self::current();
		return ( $j && is_string( $token ) && '' !== $token && hash_equals( $j->s['token'], $token ) ) ? $j : null;
	}

	public static function last( $token = null ) {
		$f = AppAlbania_Xhin_Storage::jobs_dir() . '/last.json';
		if ( ! is_file( $f ) ) {
			return null;
		}
		$l = json_decode( (string) file_get_contents( $f ), true );
		if ( ! is_array( $l ) ) {
			return null;
		}
		if ( null !== $token && ( ! isset( $l['token_hash'] ) || ! hash_equals( $l['token_hash'], hash( 'sha256', (string) $token ) ) ) ) {
			return null;
		}
		unset( $l['token_hash'] );
		return $l;
	}

	public static function create( $type, array $opts ) {
		AppAlbania_Xhin_Storage::ensure();
		$cur = self::current();
		if ( $cur && in_array( $cur->s['status'], array( 'running', 'error' ), true ) ) {
			throw new AppAlbania_Xhin_Exception( esc_html( 'Another backup or restore is still active. Finish or cancel it first.' ) );
		}
		self::sweep_orphans();
		$j      = new self();
		$j->id  = gmdate( 'Ymd-His' ) . '-' . strtolower( wp_generate_password( 6, false ) );
		$j->dir = AppAlbania_Xhin_Storage::jobs_dir() . '/' . $j->id;
		if ( ! wp_mkdir_p( $j->dir ) ) {
			throw new AppAlbania_Xhin_Exception( esc_html( 'Cannot create job folder in ' . AppAlbania_Xhin_Storage::jobs_dir() ) );
		}
		$stages = 'export' === $type ? self::EXPORT : self::IMPORT;
		$j->s   = array(
			'id'        => $j->id,
			'type'      => $type,
			'status'    => 'running',
			'stage'     => key( $stages ),
			'token'     => bin2hex( random_bytes( 16 ) ),
			'created'   => time(),
			'updated'   => time(),
			'opts'      => $opts,
			'msg'       => 'Starting…',
			'pct'       => 0,
			'stage_pct' => 0,
			'stats'     => array(),
		);
		if ( 'import' === $type ) {
			global $wpdb;
			$up         = wp_upload_dir( null, false );
			$j->s['dest'] = array(
				'home'        => (string) get_option( 'home' ),
				'siteurl'     => (string) get_option( 'siteurl' ),
				'abspath'     => untrailingslashit( str_replace( '\\', '/', ABSPATH ) ),
				'content_dir' => str_replace( '\\', '/', WP_CONTENT_DIR ),
				'content_url' => WP_CONTENT_URL,
				'uploads_dir' => str_replace( '\\', '/', $up['basedir'] ),
				'uploads_url' => $up['baseurl'],
				'prefix'      => $wpdb->prefix,
			);
			$j->import_bootstrap();
		}
		$j->save( true );
		file_put_contents( AppAlbania_Xhin_Storage::jobs_dir() . '/current', $j->id );
		$j->log( ( 'export' === $type ? 'Backup' : 'Restore' ) . ' job created (Migration by AppAlbania ' . APPALBANIA_XHIN_VERSION . ', PHP ' . PHP_VERSION . ')' );
		return $j;
	}

	/** Remembers which temp-table prefixes a job created (stored on disk: survives the DB swap). */
	private static function registry( $job_id = null, array $prefixes = array() ) {
		$f   = AppAlbania_Xhin_Storage::jobs_dir() . '/temp-tables.json';
		$reg = is_file( $f ) ? (array) json_decode( (string) file_get_contents( $f ), true ) : array();
		if ( null !== $job_id ) {
			$reg[ $job_id ] = $prefixes;
			@file_put_contents( $f, wp_json_encode( $reg ) );
		}
		return $reg;
	}

	/** Drops temp tables of restores that no longer exist (e.g. a job folder deleted by hand). */
	private static function sweep_orphans() {
		$reg = self::registry();
		if ( ! $reg ) {
			return;
		}
		$cur = self::current_id();
		foreach ( $reg as $id => $prefixes ) {
			if ( $id === $cur && is_dir( AppAlbania_Xhin_Storage::jobs_dir() . '/' . $id ) ) {
				continue;
			}
			foreach ( (array) $prefixes as $px ) {
				if ( preg_match( '/^[xy][0-9a-f]{4}_$/', $px ) ) {
					try {
						AppAlbania_Xhin_DB::drop_prefixed( $px );
					} catch ( Throwable $e ) { // phpcs:ignore
					}
				}
			}
			unset( $reg[ $id ] );
		}
		@file_put_contents( AppAlbania_Xhin_Storage::jobs_dir() . '/temp-tables.json', wp_json_encode( $reg ) );
	}

	public function reload() {
		$raw = @file_get_contents( $this->dir . '/state.json' );
		$s   = $raw ? json_decode( $raw, true ) : null;
		if ( ! is_array( $s ) ) {
			return false;
		}
		$this->s = $s;
		return true;
	}

	public function save( $force = false ) {
		$this->s['updated'] = time();
		$this->live();
		$json = wp_json_encode( $this->s, JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE );
		$tmp  = $this->dir . '/state.json.tmp';
		if ( false === @file_put_contents( $tmp, $json ) || ! @rename( $tmp, $this->dir . '/state.json' ) ) {
			throw new AppAlbania_Xhin_Exception( esc_html( 'Cannot save job state — disk full?' ) );
		}
		$this->last_save = microtime( true );
	}

	public function log( $msg ) {
		@file_put_contents( $this->dir . '/log.txt', '[' . gmdate( 'H:i:s' ) . '] ' . $msg . "\n", FILE_APPEND );
	}

	public function log_tail( $lines = 40 ) {
		$f = $this->dir . '/log.txt';
		if ( ! is_file( $f ) ) {
			return array();
		}
		$fh   = fopen( $f, 'rb' );
		$size = filesize( $f );
		fseek( $fh, max( 0, $size - 12000 ) );
		$txt = (string) fread( $fh, 12000 );
		fclose( $fh );
		$all = array_filter( explode( "\n", $txt ), 'strlen' );
		return array_values( array_slice( $all, -$lines ) );
	}

	private function stages() {
		return 'export' === $this->s['type'] ? self::EXPORT : self::IMPORT;
	}

	private function key() {
		return empty( $this->s['key'] ) ? null : hex2bin( $this->s['key'] );
	}

	private function settings() {
		return AppAlbania_Xhin_Plugin::settings();
	}

	/* ================================================================= runner */

	/**
	 * Runs work for at most $budget seconds. Safe to call from any number of
	 * concurrent runners: only the one holding the lock works.
	 */
	public function step( $budget ) {
		$this->lock = @fopen( $this->dir . '/lock', 'c' );
		if ( ! $this->lock ) {
			throw new AppAlbania_Xhin_Exception( esc_html( self::not_writable( $this->dir ) ) );
		}
		if ( ! flock( $this->lock, LOCK_EX | LOCK_NB ) ) {
			fclose( $this->lock );
			$this->lock = null;
			return array( 'busy' => true ) + $this->public_state();
		}
		$this->reload();
		if ( 'running' !== $this->s['status'] ) {
			$this->unlock();
			return $this->public_state();
		}
		ignore_user_abort( true );
		@set_time_limit( (int) max( 120, $budget * 4 ) ); // phpcs:ignore Squiz.PHP.DiscouragedFunctions.Discouraged -- only for this plugin's own backup/restore step request.
		if ( function_exists( 'wp_raise_memory_limit' ) ) {
			wp_raise_memory_limit( 'admin' );
		}
		$this->deadline = microtime( true ) + $budget;
		$this->slice    = (float) $budget;
		$this->in_step  = true;
		register_shutdown_function( array( $this, 'on_shutdown' ) );

		try {
			while ( 'running' === $this->s['status'] && microtime( true ) < $this->deadline ) {
				if ( file_exists( $this->dir . '/cancel' ) && $this->cancellable() ) {
					$this->do_cancel();
					break;
				}
				$fn   = 'stage_' . $this->s['stage'];
				$done = $this->$fn();
				if ( 'wait' === $done ) {
					break;
				}
				if ( true === $done ) {
					$this->advance();
				}
			}
		} catch ( Throwable $e ) {
			$this->s['status'] = 'error';
			$this->s['msg']    = AppAlbania_Xhin_Exception::text( $e );
			$this->log( 'ERROR: ' . AppAlbania_Xhin_Exception::text( $e ) );
		}
		$this->in_step = false;
		$this->compute_pct();

		if ( in_array( $this->s['status'], array( 'done', 'cancelled' ), true ) ) {
			$state = $this->public_state();
			$this->unlock();
			$this->close_out( $state );
			return $state;
		}
		$this->save();
		$state = $this->public_state();
		$this->unlock();
		if ( 'running' === $this->s['status'] && ! empty( $this->s['opts']['background'] ) && ! ( defined( 'WP_CLI' ) && WP_CLI ) ) {
			AppAlbania_Xhin_Plugin::kick( $this->s['token'] );
		}
		return $state;
	}

	/** Records PHP fatals (memory, max_execution_time) instead of dying silently. */
	public function on_shutdown() {
		if ( ! $this->in_step ) {
			return;
		}
		$e = error_get_last();
		if ( $e && in_array( $e['type'], array( E_ERROR, E_CORE_ERROR, E_COMPILE_ERROR, E_USER_ERROR ), true ) ) {
			$this->s['fatal'] = (int) ( $this->s['fatal'] ?? 0 ) + 1;
			$this->log( 'PHP fatal (' . $this->s['fatal'] . '): ' . $e['message'] . ' in ' . basename( $e['file'] ) . ':' . $e['line'] );
			if ( $this->s['fatal'] >= 4 ) {
				$this->s['status'] = 'error';
				$this->s['msg']    = 'PHP fatal error: ' . $e['message'] . '. Lower "Slice time" in Settings, raise memory_limit, then click Retry.';
			}
			try {
				$this->save();
			} catch ( Throwable $ex ) { // phpcs:ignore
			}
		}
	}

	/** Message for a folder this PHP user cannot write (usually: created by root via WP-CLI). */
	public static function not_writable( $dir ) {
		$who = function_exists( 'posix_geteuid' ) && function_exists( 'posix_getpwuid' ) ? ( posix_getpwuid( posix_geteuid() )['name'] ?? '' ) : '';
		return 'Cannot write to ' . $dir . ' — it belongs to another system user (for example WP-CLI run as root). '
			. 'Give the folder back to the web server user' . ( $who ? ' (' . $who . ')' : '' ) . ', e.g.: chown -R ' . ( $who ? $who : 'www-data' ) . ' wp-content/xhin-backups';
	}

	private function unlock() {
		if ( $this->lock ) {
			flock( $this->lock, LOCK_UN );
			fclose( $this->lock );
			$this->lock = null;
		}
	}

	private function advance() {
		$keys = array_keys( $this->stages() );
		$i    = array_search( $this->s['stage'], $keys, true );
		$this->s['stage_pct'] = 0;
		if ( false === $i || ! isset( $keys[ $i + 1 ] ) ) {
			$this->s['status'] = 'done';
			$this->s['pct']    = 100;
			$this->s['finished'] = time();
			$this->log( 'Completed in ' . human_time_diff( $this->s['created'], time() ) . '.' );
			return;
		}
		$this->s['stage'] = $keys[ $i + 1 ];
		$this->save();
	}

	private function compute_pct() {
		if ( 'done' === $this->s['status'] ) {
			$this->s['pct'] = 100;
			return;
		}
		$total = 0;
		$done  = 0;
		$past  = true;
		foreach ( $this->stages() as $k => $info ) {
			$w = $info[0];
			if ( 'receive' === $k && 'local' === ( $this->s['opts']['source'] ?? '' ) ) {
				$w = 0;
			}
			$total += $w;
			if ( $k === $this->s['stage'] ) {
				$done += $w * min( 1, max( 0, (float) $this->s['stage_pct'] ) );
				$past  = false;
			} elseif ( $past ) {
				$done += $w;
			}
		}
		$this->s['pct'] = $total ? round( 100 * $done / $total, 1 ) : 0;
	}

	private function cancellable() {
		return ! in_array( $this->s['stage'], array( 'db_swap', 'finalize' ), true ) || ! empty( $this->s['skip_db'] ) && 'finalize' !== $this->s['stage'];
	}

	public function request_cancel() {
		$this->lock = @fopen( $this->dir . '/lock', 'c' );
		if ( $this->lock && flock( $this->lock, LOCK_EX | LOCK_NB ) ) {
			$this->reload();
			if ( ! $this->cancellable() && 'error' !== $this->s['status'] ) {
				$this->unlock();
				return $this->public_state();
			}
			$this->do_cancel();
			$state = $this->public_state();
			$this->unlock();
			$this->close_out( $state );
			return $state;
		}
		@touch( $this->dir . '/cancel' );
		return array( 'cancelling' => true ) + $this->public_state();
	}

	private function do_cancel() {
		$this->log( 'Cancelled — cleaning up.' );
		try {
			if ( 'export' === $this->s['type'] && ! empty( $this->s['archive'] ) ) {
				wp_delete_file( AppAlbania_Xhin_Storage::dir() . '/' . $this->s['archive'] . '.part' );
			}
			if ( 'import' === $this->s['type'] ) {
				if ( ! empty( $this->s['tmp'] ) && empty( $this->s['swapped'] ) && empty( $this->s['swapping'] ) ) {
					AppAlbania_Xhin_DB::drop_prefixed( $this->s['tmp'] );
				}
				if ( ! empty( $this->s['extract']['cur']['tmp'] ) ) {
					wp_delete_file( base64_decode( $this->s['extract']['cur']['tmp'] ) );
				}
				if ( 'upload' === ( $this->s['opts']['source'] ?? '' ) || 'url' === ( $this->s['opts']['source'] ?? '' ) ) {
					if ( ! empty( $this->s['part'] ) && empty( $this->s['received'] ) ) {
						wp_delete_file( AppAlbania_Xhin_Storage::dir() . '/' . $this->s['part'] );
					}
				}
			}
		} catch ( Throwable $e ) {
			$this->log( 'Cleanup warning: ' . AppAlbania_Xhin_Exception::text( $e ) );
		}
		$this->s['status'] = 'cancelled';
		$this->s['msg']    = 'Cancelled.';
	}

	public function retry() {
		if ( 'error' === $this->s['status'] ) {
			$this->s['status'] = 'running';
			$this->s['fatal']  = 0;
			$this->s['msg']    = 'Retrying…';
			$this->log( 'Retry requested.' );
			$this->save();
		}
	}

	/** Job finished: keep a summary + log for the UI, remove the working folder. */
	private function close_out( array $state ) {
		$state['token_hash'] = hash( 'sha256', $this->s['token'] );
		$state['log']        = $this->log_tail( 60 );
		@file_put_contents( AppAlbania_Xhin_Storage::jobs_dir() . '/last.json', wp_json_encode( $state, JSON_INVALID_UTF8_SUBSTITUTE ) );
		if ( self::current_id() === $this->id ) {
			wp_delete_file( AppAlbania_Xhin_Storage::jobs_dir() . '/current' );
		}
		AppAlbania_Xhin_Storage::rrmdir( $this->dir );
	}

	public function public_state() {
		$stages = array();
		$past   = true;
		foreach ( $this->stages() as $k => $info ) {
			if ( 'receive' === $k && 'local' === ( $this->s['opts']['source'] ?? '' ) ) {
				continue;
			}
			if ( $k === $this->s['stage'] ) {
				$past  = false;
				$state = 'done' === $this->s['status'] ? 'done' : 'active';
			} else {
				$state = $past ? 'done' : 'todo';
			}
			$stages[] = array( 'key' => $k, 'label' => $info[1], 'state' => $state );
		}
		$out = array(
			'id'        => $this->id,
			'type'      => $this->s['type'],
			'status'    => $this->s['status'],
			'stage'     => $this->s['stage'],
			'stages'    => $stages,
			'pct'       => $this->s['pct'],
			'msg'       => $this->s['msg'],
			'stats'     => $this->s['stats'],
			'now'       => (string) ( $this->s['now'] ?? '' ),
			'archive'   => $this->s['archive'] ?? '',
			'created'   => $this->s['created'],
			'finished'  => (int) ( $this->s['finished'] ?? 0 ),
			'started'   => AppAlbania_Xhin_Plugin::when( $this->s['created'] ),
			'updated'   => $this->s['updated'],
			'log'       => $this->log_tail(),
			'cancellable' => $this->cancellable(),
		);
		if ( 'import' === $this->s['type'] && 'upload' === ( $this->s['opts']['source'] ?? '' ) ) {
			$out['upload'] = array(
				'size'     => (int) $this->s['opts']['size'],
				'received' => isset( $this->s['part'] ) ? AppAlbania_Xhin_Storage::filesize( AppAlbania_Xhin_Storage::dir() . '/' . $this->s['part'] ) : 0,
				'done'     => ! empty( $this->s['received'] ),
			);
		}
		return $out;
	}

	/** Effective compression codec for new archives. */
	private function codec() {
		$set = $this->settings();
		$c   = isset( $set['codec'] ) ? $set['codec'] : ( empty( $set['compress'] ) ? 'none' : 'deflate' );
		if ( 'zstd' === $c && ! AppAlbania_Xhin_Archive::zstd_available() ) {
			$c = 'deflate';
		}
		return in_array( $c, array( 'none', 'deflate', 'zstd' ), true ) ? $c : 'deflate';
	}

	/**
	 * Refreshes progress (stage %, stats, message) from the live cursors. Runs on every
	 * checkpoint, so status polls show real-time movement even mid-slice.
	 */
	private function live() {
		if ( 'running' !== ( $this->s['status'] ?? '' ) ) {
			return;
		}
		$s   = &$this->s;
		$now = '';
		switch ( $s['stage'] ) {
			case 'database':
				if ( ! empty( $s['db']['tables'] ) ) {
					$d                 = $s['db'];
					$n                 = count( $d['tables'] );
					$s['stage_pct']    = min( 1, $d['ti'] / max( 1, $n ) );
					$s['stats']['rows'] = $d['rows'];
					$s['msg']          = sprintf( 'Exporting database · table %d of %d', min( $d['ti'] + 1, $n ), $n );
					$now               = $d['tables'][ min( $d['ti'], $n - 1 ) ];
				}
				break;
			case 'scan':
				$sc                 = $s['scan'];
				$s['stage_pct']     = $sc['qsize'] ? min( 1, $sc['qoff'] / $sc['qsize'] ) : 0;
				$s['stats']['files'] = $sc['files'];
				$s['stats']['bytes'] = $sc['bytes'];
				$s['msg']           = sprintf( 'Scanning files · %s found', number_format( $sc['files'] ) );
				break;
			case 'pack':
				$p                       = $s['pack'];
				$total                   = max( 1, (int) ( $s['total'] ?? 1 ) );
				$s['stage_pct']          = min( 1, $p['raw'] / $total );
				$s['stats']['done']      = $p['raw'];
				$s['stats']['total']     = $total;
				$s['stats']['archive_size'] = $s['asize'];
				$s['msg']                = 'Packing files';
				$now                     = $p['cur'] ? base64_decode( $p['cur']['rel'] ) : '';
				break;
			case 'check':
				if ( ! empty( $s['check'] ) ) {
					$s['stage_pct']      = min( 1, $s['check']['off'] / max( 1, (int) $s['asize'] ) );
					$s['stats']['done']  = $s['check']['bytes'];
					$s['stats']['total'] = $s['pack']['raw'];
					$s['msg']            = 'Verifying every block of the archive';
				}
				break;
			case 'receive':
				if ( isset( $s['dl'] ) ) {
					$size                = isset( $s['part'] ) ? AppAlbania_Xhin_Storage::filesize( AppAlbania_Xhin_Storage::dir() . '/' . $s['part'] ) : 0;
					$s['stage_pct']      = $s['dl']['total'] ? min( 1, $size / $s['dl']['total'] ) : 0;
					$s['stats']['done']  = $size;
					$s['stats']['total'] = $s['dl']['total'];
					$s['msg']            = 'Downloading archive from the old site';
				}
				break;
			case 'extract':
				if ( ! empty( $s['extract'] ) ) {
					$x                   = $s['extract'];
					$total               = max( 1, (int) $s['total'] );
					$s['stage_pct']      = min( 1, $x['bytes'] / $total );
					$s['stats']['done']  = $x['bytes'];
					$s['stats']['total'] = $total;
					$s['stats']['files'] = $x['files'];
					$s['msg']            = 'Restoring files';
					$now                 = $x['cur'] ? base64_decode( $x['cur']['rel'] ) : '';
				}
				break;
			case 'db_import':
				if ( ! empty( $s['dbi'] ) ) {
					$s['stage_pct']      = $s['dbi']['size'] ? min( 1, $s['dbi']['off'] / $s['dbi']['size'] ) : 1;
					$s['stats']['done']  = $s['dbi']['off'];
					$s['stats']['total'] = $s['dbi']['size'];
					$s['msg']            = 'Importing database';
				}
				break;
			case 'db_replace':
				if ( ! empty( $s['rep'] ) ) {
					$r                      = $s['rep'];
					$n                      = max( 1, count( $r['tables'] ) );
					$s['stage_pct']         = min( 1, $r['ti'] / $n );
					$s['stats']['replaced'] = $r['updated'];
					$s['msg']               = 'Updating URLs & paths';
					$now                    = $r['tables'][ min( $r['ti'], $n - 1 ) ] ?? '';
				}
				break;
		}
		$s['now'] = $now;
		$this->compute_pct();
	}

	/* ================================================================ EXPORT */

	private function stage_init() {
		global $wpdb;
		$o    = $this->s['opts'];
		$host = (string) wp_parse_url( get_option( 'home' ), PHP_URL_HOST ) . (string) wp_parse_url( get_option( 'home' ), PHP_URL_PATH );
		$slug = trim( preg_replace( '/[^a-z0-9]+/', '-', strtolower( $host ) ), '-' );
		$name = AppAlbania_Xhin_Storage::unique_name( ( $slug ? $slug : 'site' ) . ( ! empty( $o['auto'] ) ? '-auto' : '' ) . '-' . gmdate( 'Ymd-His' ) . '-' . strtolower( wp_generate_password( 16, false ) ) ); // unguessable even where .htaccess is ignored (nginx).

		$this->s['archive'] = $name;
		$flags              = 0;
		$salt               = '';
		$check              = '';
		if ( ! empty( $o['password'] ) ) {
			if ( ! function_exists( 'openssl_encrypt' ) ) {
				throw new AppAlbania_Xhin_Exception( esc_html( 'Encryption needs the PHP OpenSSL extension.' ) );
			}
			$salt            = random_bytes( 16 );
			$key             = AppAlbania_Xhin_Archive::derive_key( $o['password'], $salt );
			$check           = AppAlbania_Xhin_Archive::key_check( $key );
			$this->s['key']  = bin2hex( $key );
			$flags          |= AppAlbania_Xhin_Archive::FLAG_ENCRYPTED;
		}
		unset( $this->s['opts']['password'] );
		$this->s['codec'] = $this->codec();
		if ( 'zstd' === $this->s['codec'] ) {
			$flags |= AppAlbania_Xhin_Archive::FLAG_ZSTD;
		}

		$fh = fopen( AppAlbania_Xhin_Storage::dir() . '/' . $name . '.part', 'wb' );
		if ( ! $fh ) {
			throw new AppAlbania_Xhin_Exception( esc_html( 'Cannot create the archive in ' . AppAlbania_Xhin_Storage::dir() ) );
		}
		AppAlbania_Xhin_Archive::write_all( $fh, AppAlbania_Xhin_Archive::header( $flags, $salt, $check ) );
		fclose( $fh );
		$this->s['asize'] = AppAlbania_Xhin_Archive::HEADER_LEN;

		$tables = ! empty( $o['db'] ) ? AppAlbania_Xhin_DB::tables( $wpdb->prefix ) : array();
		$up     = wp_upload_dir( null, false );
		$theme  = wp_get_theme();
		$manifest = array(
			'format'         => 'xhin',
			'format_version' => AppAlbania_Xhin_Archive::VERSION,
			'generator'      => 'Migration by AppAlbania ' . APPALBANIA_XHIN_VERSION,
			'created'        => gmdate( 'c' ),
			'site'           => array(
				'name'        => get_option( 'blogname' ),
				'home'        => (string) get_option( 'home' ),
				'siteurl'     => (string) get_option( 'siteurl' ),
				'abspath'     => untrailingslashit( str_replace( '\\', '/', ABSPATH ) ),
				'content_dir' => str_replace( '\\', '/', WP_CONTENT_DIR ),
				'content_url' => WP_CONTENT_URL,
				'uploads_dir' => str_replace( '\\', '/', $up['basedir'] ),
				'uploads_url' => $up['baseurl'],
				'multisite'   => is_multisite(),
			),
			'env'            => array(
				'wp'      => get_bloginfo( 'version' ),
				'db_ver'  => get_option( 'db_version' ),
				'php'     => PHP_VERSION,
				'mysql'   => $wpdb->db_server_info(),
				'theme'   => $theme->get( 'Name' ),
				'plugins' => (array) get_option( 'active_plugins', array() ),
			),
			'db'             => array(
				'included' => ! empty( $o['db'] ),
				'prefix'   => $wpdb->prefix,
				'charset'  => $wpdb->charset,
				'tables'   => $tables,
			),
			'contents'       => array_intersect_key( $o, array_flip( array( 'db', 'media', 'plugins', 'themes', 'muplugins', 'other', 'core' ) ) ),
			'encrypted'      => (bool) ( $flags & AppAlbania_Xhin_Archive::FLAG_ENCRYPTED ),
			'codec'          => $this->s['codec'],
		);
		file_put_contents( $this->dir . '/manifest.json', wp_json_encode( $manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE ) );

		$this->s['db'] = array(
			'tables' => $tables,
			'ti'     => 0,
			'phase'  => 'create',
			'rows'   => 0,
			'offset' => 0,
			'last'   => null,
			'size'   => 0,
		);
		$roots = '';
		if ( array_filter( array_intersect_key( $o, array_flip( array( 'media', 'plugins', 'themes', 'muplugins', 'other' ) ) ) ) ) {
			$roots .= AppAlbania_Xhin_Archive::S_CONTENT . "\t\n";
		}
		if ( ! empty( $o['core'] ) ) {
			$roots .= AppAlbania_Xhin_Archive::S_ROOT . "\t\n";
		}
		file_put_contents( $this->dir . '/dirs.queue', $roots );
		file_put_contents( $this->dir . '/files.list', '' );
		$this->s['scan'] = array( 'qoff' => 0, 'qsize' => strlen( $roots ), 'lsize' => 0, 'files' => 0, 'dirs' => 0, 'bytes' => 0, 'skipped' => 0 );
		$meta            = array( 'manifest.json' );
		if ( $tables ) {
			$meta[] = 'database.sql';
		}
		$this->s['pack'] = array( 'meta' => $meta, 'mi' => 0, 'loff' => 0, 'cur' => null, 'entries' => 0, 'raw' => 0, 'skipped' => 0 );
		$this->s['stats'] = array( 'tables' => count( $tables ) );
		$this->s['msg']   = 'Backup started';
		$this->log( 'Archive: ' . $name . ' · compression ' . $this->s['codec'] . ( $flags & AppAlbania_Xhin_Archive::FLAG_ENCRYPTED ? ' · AES-256 encrypted' : '' ) );
		$this->log( 'Contents: ' . implode( ', ', array_keys( array_filter( $manifest['contents'] ) ) ) );
		return true;
	}

	private function stage_database() {
		global $wpdb;
		$d = &$this->s['db'];
		if ( ! $d['tables'] ) {
			return true;
		}
		$fh   = AppAlbania_Xhin_Archive::open_at( $this->dir . '/database.sql', $d['size'] );
		$self = $this;
		$done = AppAlbania_Xhin_DB::dump(
			$d,
			$fh,
			$wpdb->prefix,
			$this->deadline,
			function () use ( $self, $fh, &$d ) {
				if ( $self->due_public() ) {
					fflush( $fh );
					$d['size'] = ftell( $fh );
					$self->save();
				}
			}
		);
		fflush( $fh );
		$d['size'] = ftell( $fh );
		fclose( $fh );
		$n                         = count( $d['tables'] );
		$this->s['stage_pct']      = $n ? $d['ti'] / $n : 1;
		$this->s['stats']['rows']  = $d['rows'];
		$this->s['stats']['db_size'] = $d['size'];
		$this->s['msg']            = $done ? 'Database exported' : sprintf( 'Exporting table %s (%d/%d) — %s rows', $d['tables'][ min( $d['ti'], $n - 1 ) ], min( $d['ti'] + 1, $n ), $n, number_format( $d['rows'] ) );
		if ( $done ) {
			$this->log( sprintf( 'Database: %d tables, %s rows, %s SQL', $n, number_format( $d['rows'] ), AppAlbania_Xhin_Storage::human( $d['size'] ) ) );
		}
		return $done;
	}

	private function stage_scan() {
		$sc = &$this->s['scan'];
		$lh = AppAlbania_Xhin_Archive::open_at( $this->dir . '/files.list', $sc['lsize'] );
		$qa = AppAlbania_Xhin_Archive::open_at( $this->dir . '/dirs.queue', $sc['qsize'] );
		$qr = fopen( $this->dir . '/dirs.queue', 'rb' );
		fseek( $qr, $sc['qoff'] );
		$done = false;
		while ( true ) {
			if ( microtime( true ) >= $this->deadline ) {
				break;
			}
			$line = fgets( $qr );
			if ( false === $line ) {
				$done = true;
				break;
			}
			$line = rtrim( $line, "\n" );
			if ( '' !== $line ) {
				list( $scope, $rel ) = explode( "\t", $line, 2 ) + array( '', '' );
				$this->scan_dir( (int) $scope, AppAlbania_Xhin_Archive::dec( $rel ), $lh, $qa );
				fflush( $qa );
			}
			$sc['qoff'] = ftell( $qr );
			if ( $this->due() ) {
				fflush( $lh );
				$sc['lsize'] = ftell( $lh );
				$sc['qsize'] = ftell( $qa );
				$this->save();
			}
		}
		fflush( $lh );
		$sc['lsize'] = ftell( $lh );
		$sc['qsize'] = ftell( $qa );
		fclose( $lh );
		fclose( $qa );
		fclose( $qr );
		$this->s['stats']['files'] = $sc['files'];
		$this->s['stats']['dirs']  = $sc['dirs'];
		$this->s['stats']['bytes'] = $sc['bytes'];
		$this->s['stage_pct']      = $sc['qsize'] ? $sc['qoff'] / $sc['qsize'] : 1;
		$this->s['msg']            = sprintf( 'Found %s files (%s)', number_format( $sc['files'] ), AppAlbania_Xhin_Storage::human( $sc['bytes'] ) );
		if ( $done ) {
			$this->s['total'] = $sc['bytes'] + ( $this->s['db']['size'] ?? 0 );
			$this->log( sprintf( 'Scan: %s files, %s folders, %s', number_format( $sc['files'] ), number_format( $sc['dirs'] ), AppAlbania_Xhin_Storage::human( $sc['bytes'] ) ) );
		}
		return $done;
	}

	private function scan_dir( $scope, $rel, $lh, $qa ) {
		$base = AppAlbania_Xhin_Archive::S_CONTENT === $scope ? str_replace( '\\', '/', WP_CONTENT_DIR ) : untrailingslashit( str_replace( '\\', '/', ABSPATH ) );
		$abs  = '' === $rel ? $base : $base . '/' . $rel;
		$dh   = @opendir( $abs );
		if ( ! $dh ) {
			$this->s['scan']['skipped']++;
			$this->log( 'Skipped unreadable folder: ' . $abs );
			return;
		}
		$names = array();
		while ( false !== ( $n = readdir( $dh ) ) ) {
			if ( '.' !== $n && '..' !== $n ) {
				$names[] = $n;
			}
		}
		closedir( $dh );
		sort( $names, SORT_STRING );
		foreach ( $names as $n ) {
			$r = '' === $rel ? $n : $rel . '/' . $n;
			$p = $abs . '/' . $n;
			if ( $this->excluded( $scope, $r, $p, '' === $rel ) ) {
				continue;
			}
			if ( is_dir( $p ) ) {
				if ( is_link( $p ) ) {
					$real   = realpath( $p );
					$parent = realpath( $abs );
					$links  = $this->s['scan']['links'] ?? array();
					if ( ! $real || ! $parent || $real === $parent || 0 === strpos( $parent . '/', $real . '/' ) || in_array( $real, $links, true ) ) {
						$this->log( 'Skipped symlinked folder (would loop): ' . $r );
						continue;
					}
					$this->s['scan']['links'][] = $real;
					$this->log( 'Following symlinked folder: ' . $r . ' → ' . $real );
				}
				fwrite( $qa, $scope . "\t" . AppAlbania_Xhin_Archive::enc( $r ) . "\n" );
				fwrite( $lh, "D\t{$scope}\t0\t" . (int) @filemtime( $p ) . "\t" . AppAlbania_Xhin_Archive::enc( $r ) . "\n" );
				$this->s['scan']['dirs']++;
			} elseif ( is_file( $p ) ) {
				if ( ! is_readable( $p ) ) {
					$this->s['scan']['skipped']++;
					$this->log( 'Skipped unreadable file: ' . $r );
					continue;
				}
				$size = (int) @filesize( $p );
				fwrite( $lh, "F\t{$scope}\t{$size}\t" . (int) @filemtime( $p ) . "\t" . AppAlbania_Xhin_Archive::enc( $r ) . "\n" );
				$this->s['scan']['files']++;
				$this->s['scan']['bytes'] += $size;
			}
		}
	}

	private function excluded( $scope, $rel, $abs, $top ) {
		static $storage = null, $content = null, $patterns = null;
		if ( null === $storage ) {
			$storage  = AppAlbania_Xhin_Storage::dir();
			$content  = str_replace( '\\', '/', WP_CONTENT_DIR );
			$patterns = array_filter( array_map( 'trim', preg_split( '/[\r\n,]+/', (string) ( $this->s['opts']['excludes'] ?? '' ) . "\n" . (string) $this->settings()['excludes'] ) ) );
		}
		if ( $abs === $storage ) {
			return true;
		}
		$o = $this->s['opts'];
		if ( AppAlbania_Xhin_Archive::S_ROOT === $scope ) {
			if ( $abs === $content ) {
				return true;
			}
			$display = $rel;
		} else {
			if ( $top ) {
				$map = array( 'uploads' => 'media', 'plugins' => 'plugins', 'themes' => 'themes', 'mu-plugins' => 'muplugins' );
				$opt = $map[ $rel ] ?? 'other';
				if ( empty( $o[ $opt ] ) || in_array( $rel, self::SKIP_CONTENT, true ) ) {
					return true;
				}
			}
			if ( 'mu-plugins/0-xhin-guard.php' === $rel ) {
				return true;
			}
			if ( 0 === strpos( $rel, 'uploads/' ) && 1 === substr_count( $rel, '/' ) ) {
				foreach ( self::SKIP_UPLOADS as $pat ) {
					if ( fnmatch( $pat, $rel ) ) {
						return true;
					}
				}
			}
			$display = 'wp-content/' . $rel;
		}
		foreach ( $patterns as $pat ) {
			$pat = trim( str_replace( '\\', '/', $pat ), '/' );
			if ( false !== strpos( $pat, '/' ) ) {
				if ( $display === $pat || 0 === strpos( $display, $pat . '/' ) || fnmatch( $pat, $display ) ) {
					return true;
				}
			} elseif ( fnmatch( $pat, basename( $rel ) ) ) {
				return true;
			}
		}
		return false;
	}

	private function stage_pack() {
		$p     = &$this->s['pack'];
		$fh    = AppAlbania_Xhin_Archive::open_at( AppAlbania_Xhin_Storage::dir() . '/' . $this->s['archive'] . '.part', $this->s['asize'] );
		$done  = false;
		$lh    = null;
		try {
			while ( true ) {
				if ( $p['cur'] ) {
					if ( ! $this->pack_cur( $fh ) ) {
						break; // out of time mid-file.
					}
					continue;
				}
				if ( microtime( true ) >= $this->deadline ) {
					break;
				}
				if ( $this->due() ) {
					$this->commit_archive( $fh );
				}
				if ( $p['mi'] < count( $p['meta'] ) ) {
					$name      = $p['meta'][ $p['mi'] ];
					$src       = $this->dir . '/' . $name;
					$p['cur']  = array( 't' => 'F', 'scope' => 0, 'rel' => base64_encode( $name ), 'src' => base64_encode( $src ), 'size' => AppAlbania_Xhin_Storage::filesize( $src ), 'mtime' => time(), 'foff' => 0, 'hdr' => false );
					$p['mi']++;
					continue;
				}
				if ( ! $lh ) {
					$lh = fopen( $this->dir . '/files.list', 'rb' );
					fseek( $lh, $p['loff'] );
				}
				$line = fgets( $lh );
				if ( false === $line ) {
					$done = true;
					break;
				}
				$p['loff'] = ftell( $lh );
				$parts     = explode( "\t", rtrim( $line, "\n" ), 5 );
				if ( 5 !== count( $parts ) ) {
					continue;
				}
				list( $t, $scope, $size, $mtime, $rel ) = $parts;
				$rel  = AppAlbania_Xhin_Archive::dec( $rel );
				$base = AppAlbania_Xhin_Archive::S_CONTENT === (int) $scope ? str_replace( '\\', '/', WP_CONTENT_DIR ) : untrailingslashit( str_replace( '\\', '/', ABSPATH ) );
				if ( 'D' === $t ) {
					AppAlbania_Xhin_Archive::write_all( $fh, AppAlbania_Xhin_Archive::entry_header( AppAlbania_Xhin_Archive::T_DIR, (int) $scope, $rel, 0, (int) $mtime, 0755 ) );
					$p['entries']++;
					continue;
				}
				$p['cur'] = array( 't' => 'F', 'scope' => (int) $scope, 'rel' => base64_encode( $rel ), 'src' => base64_encode( $base . '/' . $rel ), 'size' => (int) $size, 'mtime' => (int) $mtime, 'foff' => 0, 'hdr' => false );
			}
		} finally {
			fflush( $fh );
			$this->s['asize'] = ftell( $fh );
			fclose( $fh );
			if ( $lh ) {
				fclose( $lh );
			}
		}
		$total                         = max( 1, (int) ( $this->s['total'] ?? 1 ) );
		$this->s['stage_pct']          = min( 1, $p['raw'] / $total );
		$this->s['stats']['packed']    = $p['raw'];
		$this->s['stats']['total']     = $total;
		$this->s['stats']['archive_size'] = $this->s['asize'];
		$cur                           = $p['cur'] ? base64_decode( $p['cur']['rel'] ) : '';
		$this->s['msg']                = $done ? 'Archive packed' : sprintf( 'Packing %s / %s%s', AppAlbania_Xhin_Storage::human( $p['raw'] ), AppAlbania_Xhin_Storage::human( $total ), $cur ? ' — ' . $cur : '' );
		return $done;
	}

	private function commit_archive( $fh ) {
		fflush( $fh );
		$this->s['asize'] = ftell( $fh );
		$this->save();
	}

	/** Streams the current file into chunks. Returns false when the slice ran out mid-file. */
	private function pack_cur( $fh ) {
		$p     = &$this->s['pack'];
		$c     = &$p['cur'];
		$src   = base64_decode( $c['src'] );
		$rel   = base64_decode( $c['rel'] );
		$key   = $this->key();
		$set   = $this->settings();
		$chunk = max( 262144, (int) $set['chunk_mb'] * 1048576 );
		$in    = @fopen( $src, 'rb' );
		if ( ! $c['hdr'] ) {
			if ( ! $in ) {
				$p['skipped']++;
				$this->log( 'Skipped (vanished/unreadable): ' . $rel );
				$p['cur'] = null;
				return true;
			}
			AppAlbania_Xhin_Archive::write_all( $fh, AppAlbania_Xhin_Archive::entry_header( AppAlbania_Xhin_Archive::T_FILE, $c['scope'], $rel, $c['size'], $c['mtime'], (int) @fileperms( $src ) & 0777 ) );
			$c['hdr'] = true;
			$p['entries']++;
		} elseif ( ! $in ) {
			AppAlbania_Xhin_Archive::write_all( $fh, AppAlbania_Xhin_Archive::end_chunks() );
			$this->log( 'File disappeared while packing (kept partial): ' . $rel );
			$p['cur'] = null;
			return true;
		} else {
			fseek( $in, $c['foff'] );
		}
		$codec = $this->s['codec'] ?? 'deflate';
		if ( 0 === $c['scope'] && 'manifest.json' === $rel ) {
			$codec = 'deflate'; // the manifest must be readable on any server.
		}
		if ( ! empty( $c['nz'] ) || ! AppAlbania_Xhin_Archive::compressible( $rel ) ) {
			$codec = 'none';
		}
		while ( true ) {
			$buf = fread( $in, $chunk );
			if ( false === $buf || '' === $buf ) {
				break;
			}
			AppAlbania_Xhin_Archive::write_all( $fh, AppAlbania_Xhin_Archive::chunk( $buf, $codec, $key ) );
			if ( 'none' !== $codec && 0 === $c['foff'] && AppAlbania_Xhin_Archive::$last_ratio > 0.92 && strlen( $buf ) >= 65536 ) {
				$c['nz'] = true; // first block barely compresses: store the rest raw, save CPU.
				$codec   = 'none';
			}
			$len        = strlen( $buf );
			$c['foff'] += $len;
			$p['raw']  += $len;
			if ( $this->due() ) {
				$this->commit_archive( $fh );
			}
			if ( microtime( true ) >= $this->deadline ) {
				fclose( $in );
				return false;
			}
		}
		fclose( $in );
		AppAlbania_Xhin_Archive::write_all( $fh, AppAlbania_Xhin_Archive::end_chunks() );
		$p['cur'] = null;
		return true;
	}

	private function stage_finish() {
		$part  = AppAlbania_Xhin_Storage::dir() . '/' . $this->s['archive'] . '.part';
		$final = AppAlbania_Xhin_Storage::dir() . '/' . $this->s['archive'];
		if ( ! file_exists( $part ) && file_exists( $final ) ) {
			return true;
		}
		$p       = &$this->s['pack'];
		$summary = wp_json_encode(
			array(
				'entries'  => $p['entries'] + 1,
				'bytes'    => $p['raw'],
				'files'    => $this->s['scan']['files'],
				'dirs'     => $this->s['scan']['dirs'],
				'db_rows'  => $this->s['db']['rows'],
				'skipped'  => $p['skipped'] + $this->s['scan']['skipped'],
				'seconds'  => time() - $this->s['created'],
				'finished' => gmdate( 'c' ),
			)
		);
		// Re-running after a kill is safe: open_at() truncates back to the pre-trailer size.
		$fh = AppAlbania_Xhin_Archive::open_at( $part, $this->s['asize'] );
		AppAlbania_Xhin_Archive::write_all( $fh, AppAlbania_Xhin_Archive::entry_header( AppAlbania_Xhin_Archive::T_FILE, AppAlbania_Xhin_Archive::S_META, 'summary.json', strlen( $summary ), time(), 0644 ) );
		AppAlbania_Xhin_Archive::write_all( $fh, AppAlbania_Xhin_Archive::chunk( $summary, 'none', $this->key() ) . AppAlbania_Xhin_Archive::end_chunks() );
		AppAlbania_Xhin_Archive::write_all( $fh, AppAlbania_Xhin_Archive::trailer( $p['entries'] + 1, $p['raw'] + strlen( $summary ) ) );
		fflush( $fh );
		$sealed = ftell( $fh );
		fclose( $fh );
		$p['entries']++;
		$p['raw']        += strlen( $summary );
		$this->s['asize'] = $sealed;
		return true;
	}

	/**
	 * Read-back verification: walks the finished archive and decodes every block
	 * (CRC32 + AES-GCM tag), so a bad disk write is caught now — not on restore day.
	 */
	private function stage_check() {
		$part  = AppAlbania_Xhin_Storage::dir() . '/' . $this->s['archive'] . '.part';
		$final = AppAlbania_Xhin_Storage::dir() . '/' . $this->s['archive'];
		if ( ! file_exists( $part ) && file_exists( $final ) ) {
			return true;
		}
		$done = false;
		if ( empty( $this->settings()['verify'] ) ) {
			$done = true;
		} else {
			if ( empty( $this->s['check'] ) ) {
				$this->s['check'] = array( 'off' => AppAlbania_Xhin_Archive::HEADER_LEN, 'in' => false, 'entries' => 0, 'bytes' => 0 );
			}
			$k   = &$this->s['check'];
			$key = $this->key();
			$fh  = fopen( $part, 'rb' );
			if ( AppAlbania_Xhin_Archive::HEADER_LEN === $k['off'] ) {
				AppAlbania_Xhin_Archive::read_header( $fh );
			}
			fseek( $fh, $k['off'] );
			try {
				while ( microtime( true ) < $this->deadline ) {
					if ( $this->due() ) {
						$this->save();
					}
					if ( $k['in'] ) {
						$h = AppAlbania_Xhin_Archive::read_chunk_header( $fh );
						if ( null === $h ) {
							$k['in']  = false;
							$k['off'] = ftell( $fh );
							continue;
						}
						AppAlbania_Xhin_Archive::decode_chunk( $h, AppAlbania_Xhin_Archive::read_exact( $fh, $h['stored'] ), $key, 'in ' . $k['path'] );
						$k['bytes'] += $h['raw'];
						$k['off']    = ftell( $fh );
						continue;
					}
					$e = AppAlbania_Xhin_Archive::read_entry( $fh );
					if ( $e['trailer'] ) {
						if ( (int) $e['entries'] !== $k['entries'] || (int) $e['bytes'] !== $k['bytes'] || ftell( $fh ) !== AppAlbania_Xhin_Storage::filesize( $part ) ) {
							throw new AppAlbania_Xhin_Exception( esc_html( 'Verification failed: archive totals do not match. Run the backup again.' ) );
						}
						$done = true;
						break;
					}
					$k['entries']++;
					if ( AppAlbania_Xhin_Archive::T_FILE === $e['type'] ) {
						$k['in']   = true;
						$k['path'] = $e['path'];
					}
					$k['off'] = ftell( $fh );
				}
			} catch ( AppAlbania_Xhin_Exception $ex ) {
				fclose( $fh );
				throw new AppAlbania_Xhin_Exception( esc_html( 'Archive verification failed (' . AppAlbania_Xhin_Exception::text( $ex ) . '). The disk may be faulty or full — run the backup again.' ) );
			}
			fclose( $fh );
		}
		if ( ! $done ) {
			return false;
		}
		if ( ! @rename( $part, $final ) ) {
			throw new AppAlbania_Xhin_Exception( esc_html( 'Could not finalize archive file name.' ) );
		}
		$size                             = AppAlbania_Xhin_Storage::filesize( $final );
		$this->s['stats']['archive_size'] = $size;
		$this->s['result']                = array( 'name' => $this->s['archive'], 'size' => $size );
		$this->s['msg']                   = 'Backup ready';
		$ratio                            = $this->s['pack']['raw'] ? round( 100 - 100 * $size / $this->s['pack']['raw'] ) : 0;
		$this->log( sprintf( 'Backup ready: %s — %s (%s of data, %d%% smaller)%s', $this->s['archive'], AppAlbania_Xhin_Storage::human( $size ), AppAlbania_Xhin_Storage::human( $this->s['pack']['raw'] ), max( 0, $ratio ), empty( $this->settings()['verify'] ) ? '' : ' · every block verified' ) );
		if ( ! empty( $this->s['opts']['auto'] ) ) {
			AppAlbania_Xhin_Plugin::apply_retention();
		}
		return true;
	}

	/* ================================================================ IMPORT */

	private function import_bootstrap() {
		$o = $this->s['opts'];
		switch ( $o['source'] ) {
			case 'local':
				if ( ! AppAlbania_Xhin_Storage::path( $o['name'] ) ) {
					throw new AppAlbania_Xhin_Exception( esc_html( 'Backup file not found in storage.' ) );
				}
				$this->s['archive']  = basename( $o['name'] );
				$this->s['received'] = true;
				break;
			case 'upload':
				$this->s['archive'] = AppAlbania_Xhin_Storage::unique_name( $o['filename'] );
				$this->s['part']    = $this->s['archive'] . '.part';
				if ( (int) $o['size'] < AppAlbania_Xhin_Archive::HEADER_LEN ) {
					throw new AppAlbania_Xhin_Exception( esc_html( 'That file is too small to be a .xhin archive.' ) );
				}
				$free = AppAlbania_Xhin_Storage::free_space();
				if ( null !== $free && $free < (int) $o['size'] * 1.05 ) {
					throw new AppAlbania_Xhin_Exception( esc_html( sprintf( 'Not enough disk space: %s free, archive is %s (files are extracted from it, so you need room for both).', AppAlbania_Xhin_Storage::human( $free ), AppAlbania_Xhin_Storage::human( $o['size'] ) ) ) );
				}
				break;
			case 'url':
				if ( ! preg_match( '#^https?://#i', $o['url'] ) ) {
					throw new AppAlbania_Xhin_Exception( esc_html( 'Enter a valid http(s) URL.' ) );
				}
				$q    = array();
				wp_parse_str( (string) wp_parse_url( $o['url'], PHP_URL_QUERY ), $q );
				$guess = ! empty( $q['f'] ) ? rawurldecode( $q['f'] ) : basename( (string) wp_parse_url( $o['url'], PHP_URL_PATH ) );
				$this->s['archive'] = AppAlbania_Xhin_Storage::unique_name( preg_match( '/\.xhin$/i', $guess ) ? $guess : 'migration-' . gmdate( 'Ymd-His' ) );
				$this->s['part']    = $this->s['archive'] . '.part';
				$this->s['dl']      = array( 'total' => 0, 'errors' => 0 );
				break;
			default:
				throw new AppAlbania_Xhin_Exception( esc_html( 'Unknown import source.' ) );
		}
	}

	/** Receives one raw upload chunk (php://input). Returns the new byte count. */
	public function receive_chunk( $offset, $input ) {
		$this->lock = @fopen( $this->dir . '/lock', 'c' );
		if ( ! $this->lock || ! flock( $this->lock, LOCK_EX | LOCK_NB ) ) {
			throw new AppAlbania_Xhin_Exception( esc_html( 'busy' ) );
		}
		try {
			$this->reload();
			if ( 'upload' !== $this->s['opts']['source'] || ! empty( $this->s['received'] ) || 'running' !== $this->s['status'] ) {
				throw new AppAlbania_Xhin_Exception( esc_html( 'Upload is not expected for this job.' ) );
			}
			$part = AppAlbania_Xhin_Storage::dir() . '/' . $this->s['part'];
			$have = file_exists( $part ) ? AppAlbania_Xhin_Storage::filesize( $part ) : 0;
			$offset = (int) $offset;
			if ( $offset > $have ) {
				return $have; // client is ahead of us: resync.
			}
			if ( 0 === $offset ) {
				$magic = fread( $input, 8 );
				if ( AppAlbania_Xhin_Archive::MAGIC !== $magic ) {
					throw new AppAlbania_Xhin_Exception( esc_html( 'This file is not a .xhin archive.' ) );
				}
			}
			$out = fopen( $part, 'c+b' );
			ftruncate( $out, $offset );
			fseek( $out, $offset );
			if ( 0 === $offset ) {
				fwrite( $out, $magic );
			}
			stream_copy_to_stream( $input, $out );
			fflush( $out );
			$size = ftell( $out );
			fclose( $out );
			if ( $size > (int) $this->s['opts']['size'] ) {
				throw new AppAlbania_Xhin_Exception( esc_html( 'Received more data than the file size — upload corrupted.' ) );
			}
			if ( $size === (int) $this->s['opts']['size'] ) {
				if ( ! @rename( $part, AppAlbania_Xhin_Storage::dir() . '/' . $this->s['archive'] ) ) {
					throw new AppAlbania_Xhin_Exception( esc_html( 'Could not finalize uploaded file.' ) );
				}
				$this->s['received'] = true;
				$this->log( 'Upload complete: ' . AppAlbania_Xhin_Storage::human( $size ) );
			}
			$this->s['stage_pct'] = $size / max( 1, (int) $this->s['opts']['size'] );
			$this->s['msg']       = sprintf( 'Uploading %s / %s', AppAlbania_Xhin_Storage::human( $size ), AppAlbania_Xhin_Storage::human( $this->s['opts']['size'] ) );
			$this->compute_pct();
			$this->save();
			return $size;
		} finally {
			$this->unlock();
		}
	}

	private function stage_receive() {
		if ( ! empty( $this->s['received'] ) ) {
			return true;
		}
		if ( 'upload' === $this->s['opts']['source'] ) {
			return 'wait';
		}
		return $this->download_slice();
	}

	/**
	 * Resumable server-to-server pull with the WordPress HTTP API: the archive is fetched in byte
	 * ranges ("pieces"), each streamed to a temporary file and appended once complete — so a
	 * timeout or a killed request costs at most one piece, and memory use stays constant.
	 */
	private function download_slice() {
		$dl    = &$this->s['dl'];
		$part  = AppAlbania_Xhin_Storage::dir() . '/' . $this->s['part'];
		$tmp   = $part . '.piece';
		$piece = (int) ( $dl['piece'] ?? 16777216 );
		while ( microtime( true ) < $this->deadline - 1 ) {
			$have = file_exists( $part ) ? AppAlbania_Xhin_Storage::filesize( $part ) : 0;
			if ( ! empty( $dl['total'] ) && $have >= $dl['total'] ) {
				break;
			}
			$end = $have + $piece - 1;
			if ( ! empty( $dl['total'] ) ) {
				$end = min( $end, (int) $dl['total'] - 1 );
			}
			wp_delete_file( $tmp );
			$r   = wp_remote_get(
				$this->s['opts']['url'],
				array(
					'timeout'     => max( 10, min( 120, (int) floor( $this->deadline - microtime( true ) ) + 10 ) ),
					'redirection' => 5,
					'stream'      => true,
					'filename'    => $tmp,
					'user-agent'  => 'MigrationByAppAlbania/' . APPALBANIA_XHIN_VERSION,
					'headers'     => array( 'Range' => 'bytes=' . $have . '-' . $end ),
				)
			);
			$got = file_exists( $tmp ) ? AppAlbania_Xhin_Storage::filesize( $tmp ) : 0;
			if ( is_wp_error( $r ) ) {
				wp_delete_file( $tmp ); // status unknown: never keep a partial piece.
				$dl['errors']  = (int) ( $dl['errors'] ?? 0 ) + 1;
				$dl['piece']   = $piece = max( 1048576, (int) ( $piece / 2 ) );
				$this->log( 'Network hiccup (' . $r->get_error_message() . ') — resuming with smaller pieces.' );
				if ( $dl['errors'] > 40 ) {
					throw new AppAlbania_Xhin_Exception( esc_html( 'Download keeps failing: ' . $r->get_error_message() ) );
				}
				return 'wait';
			}
			$code = (int) wp_remote_retrieve_response_code( $r );
			if ( 416 === $code && $have > 0 ) {
				$dl['total'] = $have; // nothing left: already complete.
				wp_delete_file( $tmp );
				break;
			}
			if ( 206 !== $code && 200 !== $code ) {
				wp_delete_file( $tmp );
				throw new AppAlbania_Xhin_Exception( esc_html( 'Download failed: HTTP ' . $code . ( 403 === $code ? ' (link expired or invalid signature)' : '' ) ) );
			}
			if ( 200 === $code ) { // the server ignored Range and sent the whole file.
				if ( ! @rename( $tmp, $part ) ) {
					throw new AppAlbania_Xhin_Exception( esc_html( 'Cannot write ' . $part ) );
				}
				$dl['total'] = $got;
				break;
			}
			if ( preg_match( '#/(\d+)\s*$#', (string) wp_remote_retrieve_header( $r, 'content-range' ), $m ) ) {
				$dl['total'] = (int) $m[1];
			}
			$in  = fopen( $tmp, 'rb' );
			$out = fopen( $part, 'ab' );
			if ( ! $in || ! $out ) {
				throw new AppAlbania_Xhin_Exception( esc_html( 'Cannot write ' . $part ) );
			}
			stream_copy_to_stream( $in, $out );
			fclose( $in );
			fflush( $out );
			fclose( $out );
			wp_delete_file( $tmp );
			$dl['errors'] = 0;
			if ( empty( $dl['total'] ) && $got < $end - $have + 1 ) {
				$dl['total'] = $have + $got; // no total announced: a short piece is the end.
			}
			if ( $got <= 0 ) {
				break;
			}
		}
		$size                           = file_exists( $part ) ? AppAlbania_Xhin_Storage::filesize( $part ) : 0;
		$this->s['stage_pct']           = ! empty( $dl['total'] ) ? $size / $dl['total'] : 0;
		$this->s['msg']                 = sprintf( 'Downloading %s%s', AppAlbania_Xhin_Storage::human( $size ), ! empty( $dl['total'] ) ? ' / ' . AppAlbania_Xhin_Storage::human( $dl['total'] ) : '' );
		$this->s['stats']['downloaded'] = $size;

		if ( ! empty( $dl['total'] ) && $size >= $dl['total'] ) {
			$fh    = fopen( $part, 'rb' );
			$magic = fread( $fh, 8 );
			fclose( $fh );
			if ( AppAlbania_Xhin_Archive::MAGIC !== $magic ) {
				wp_delete_file( $part );
				throw new AppAlbania_Xhin_Exception( esc_html( 'The URL did not return a .xhin archive.' ) );
			}
			if ( ! @rename( $part, AppAlbania_Xhin_Storage::dir() . '/' . $this->s['archive'] ) ) {
				throw new AppAlbania_Xhin_Exception( esc_html( 'Could not finalize downloaded file.' ) );
			}
			$this->s['received'] = true;
			$this->log( 'Download complete: ' . AppAlbania_Xhin_Storage::human( $size ) );
			return true;
		}
		return false;
	}

	private function manifest() {
		static $m = null;
		if ( null === $m ) {
			$m = json_decode( (string) @file_get_contents( $this->dir . '/manifest.json' ), true );
		}
		return is_array( $m ) ? $m : array();
	}

	private function stage_verify() {
		global $wpdb;
		$file = AppAlbania_Xhin_Storage::dir() . '/' . $this->s['archive'];
		$fh   = fopen( $file, 'rb' );
		if ( ! $fh ) {
			throw new AppAlbania_Xhin_Exception( esc_html( 'Archive not found: ' . $this->s['archive'] ) );
		}
		$h   = AppAlbania_Xhin_Archive::read_header( $fh );
		$key = null;
		if ( $h['encrypted'] ) {
			if ( empty( $this->s['opts']['password'] ) ) {
				throw new AppAlbania_Xhin_Exception( esc_html( 'This archive is password-protected. Cancel, then start the restore again with the password.' ) );
			}
			$key = AppAlbania_Xhin_Archive::derive_key( $this->s['opts']['password'], $h['salt'] );
			if ( ! hash_equals( $h['check'], AppAlbania_Xhin_Archive::key_check( $key ) ) ) {
				throw new AppAlbania_Xhin_Exception( esc_html( 'Wrong password for this archive.' ) );
			}
			$this->s['key'] = bin2hex( $key );
		}
		unset( $this->s['opts']['password'] );
		$trailer = AppAlbania_Xhin_Archive::read_trailer( $file );
		if ( ! $trailer ) {
			throw new AppAlbania_Xhin_Exception( esc_html( 'Archive is incomplete (end marker missing) — the upload or download was cut off.' ) );
		}
		$e = AppAlbania_Xhin_Archive::read_entry( $fh );
		if ( $e['trailer'] || AppAlbania_Xhin_Archive::S_META !== $e['scope'] || 'manifest.json' !== $e['path'] ) {
			throw new AppAlbania_Xhin_Exception( esc_html( 'Archive has no manifest.' ) );
		}
		$json = '';
		while ( $ch = AppAlbania_Xhin_Archive::read_chunk_header( $fh ) ) {
			$json .= AppAlbania_Xhin_Archive::decode_chunk( $ch, AppAlbania_Xhin_Archive::read_exact( $fh, $ch['stored'] ), $key, '(manifest)' );
		}
		fclose( $fh );
		$m = json_decode( $json, true );
		if ( ! is_array( $m ) || 'xhin' !== ( $m['format'] ?? '' ) ) {
			throw new AppAlbania_Xhin_Exception( esc_html( 'Manifest is unreadable.' ) );
		}
		file_put_contents( $this->dir . '/manifest.json', $json );
		if ( ! empty( $m['site']['multisite'] ) || is_multisite() ) {
			throw new AppAlbania_Xhin_Exception( esc_html( 'Multisite networks are not supported yet.' ) );
		}
		if ( PHP_INT_SIZE < 8 && AppAlbania_Xhin_Storage::filesize( $file ) > 2147483000 ) {
			throw new AppAlbania_Xhin_Exception( esc_html( 'Archives over 2 GB need 64-bit PHP.' ) );
		}
		// Unique temp/old prefixes that cannot collide with live tables.
		$dst = $wpdb->prefix;
		do {
			$tmp = 'x' . bin2hex( random_bytes( 2 ) ) . '_';
			$old = 'y' . substr( $tmp, 1 );
		} while ( 0 === strpos( $dst, $tmp ) || 0 === strpos( $dst, $old ) || AppAlbania_Xhin_DB::tables( $tmp ) || AppAlbania_Xhin_DB::tables( $old ) );
		$this->s['tmp']     = $tmp;
		$this->s['old']     = $old;
		self::registry( $this->id, array( $tmp, $old ) );
		$this->s['extract'] = array( 'aoff' => AppAlbania_Xhin_Archive::HEADER_LEN, 'cur' => null, 'files' => 0, 'bytes' => 0, 'skipped' => 0, 'dsize' => 0 );
		$this->s['total']   = (int) $trailer['bytes'];
		file_put_contents( $this->dir . '/deferred.list', '' );
		$this->s['stats']   = array( 'source' => $m['site']['home'], 'created' => $m['created'], 'wp' => $m['env']['wp'] ?? '' );
		$this->log( sprintf( 'Archive OK: %s from %s (WordPress %s, created %s)%s', AppAlbania_Xhin_Storage::human( AppAlbania_Xhin_Storage::filesize( $file ) ), $m['site']['home'], $m['env']['wp'] ?? '?', $m['created'], $key ? ', encrypted' : '' ) );
		$this->log( 'Restoring into ' . $this->s['dest']['home'] . ' (table prefix ' . $dst . ')' );
		$this->guard_wp_versions( $m );
		if ( is_array( $this->s['opts']['parts'] ?? null ) ) {
			$this->log( 'Selected: ' . implode( ', ', $this->s['opts']['parts'] ) );
		}
		return true;
	}

	/**
	 * WordPress does not support running a database from a newer release under older core files
	 * (e.g. 6.8+ password hashes cannot be checked by older WordPress: nobody could log in).
	 * Before anything is touched: restore the backup's core files too, or stop with a clear message.
	 */
	private function guard_wp_versions( array $m ) {
		$from = (string) ( $m['env']['wp'] ?? '' );
		$here = (string) get_bloginfo( 'version' );
		if ( '' === $from || '' === $here || ! $this->wants( 'db' ) || empty( $m['db']['included'] ) ) {
			return;
		}
		$branch = function ( $v ) { return implode( '.', array_slice( explode( '.', preg_replace( '/[^0-9.].*$/', '', $v ) ), 0, 2 ) ); };
		if ( version_compare( $branch( $from ), $branch( $here ), '<=' ) || $this->wants( 'core' ) ) {
			return;
		}
		if ( $this->part_in_archive( 'core' ) ) {
			$this->s['opts']['parts'][] = 'core';
			$this->log( sprintf( 'The backup is from WordPress %s, newer than this site (%s): its WordPress core files are restored too, so logins and the database keep working.', $from, $here ) );
			return;
		}
		throw new AppAlbania_Xhin_Exception( esc_html( sprintf( 'This backup is from WordPress %s, but this site runs WordPress %s and the backup has no core files. Update WordPress on this site to %s or newer first (Dashboard → Updates), then restore again. Nothing was changed.', $from, $here, $branch( $from ) ) ) );
	}

	/** Which restorable part a path belongs to: db, media, plugins, themes, muplugins, other, core. */
	public static function part_of( $scope, $rel ) {
		if ( AppAlbania_Xhin_Archive::S_ROOT === (int) $scope ) {
			return 'core';
		}
		$top = strtok( $rel, '/' );
		$map = array( 'uploads' => 'media', 'plugins' => 'plugins', 'themes' => 'themes', 'mu-plugins' => 'muplugins' );
		return $map[ $top ] ?? 'other';
	}

	/** Whether the archive being restored contains this part (from its manifest). */
	private function part_in_archive( $part ) {
		$m = $this->manifest();
		return ! empty( $m['contents'][ $part ] );
	}

	private function wants( $part ) {
		$parts = $this->s['opts']['parts'] ?? null;
		return ! is_array( $parts ) || in_array( $part, $parts, true );
	}

	/** Reads the manifest of an archive in storage (for the restore dialog). */
	public static function inspect( $file, $password = '' ) {
		$fh = fopen( $file, 'rb' );
		if ( ! $fh ) {
			throw new AppAlbania_Xhin_Exception( esc_html( 'Cannot open archive.' ) );
		}
		try {
			$h   = AppAlbania_Xhin_Archive::read_header( $fh );
			$out = array(
				'encrypted' => $h['encrypted'],
				'created'   => (int) $h['created'],
				'complete'  => false !== AppAlbania_Xhin_Archive::read_trailer( $file ),
			);
			$key = null;
			if ( $h['encrypted'] ) {
				if ( '' === $password ) {
					$out['locked'] = true;
					return $out;
				}
				$key = AppAlbania_Xhin_Archive::derive_key( $password, $h['salt'] );
				if ( ! hash_equals( $h['check'], AppAlbania_Xhin_Archive::key_check( $key ) ) ) {
					$out['locked'] = true;
					$out['wrong']  = true;
					return $out;
				}
			}
			$e = AppAlbania_Xhin_Archive::read_entry( $fh );
			if ( $e['trailer'] || 'manifest.json' !== $e['path'] ) {
				throw new AppAlbania_Xhin_Exception( esc_html( 'Archive has no manifest.' ) );
			}
			$json = '';
			while ( $c = AppAlbania_Xhin_Archive::read_chunk_header( $fh ) ) {
				$json .= AppAlbania_Xhin_Archive::decode_chunk( $c, AppAlbania_Xhin_Archive::read_exact( $fh, $c['stored'] ), $key );
			}
			$m             = json_decode( $json, true );
			$out['site']   = $m['site']['home'] ?? '';
			$out['wp']     = $m['env']['wp'] ?? '';
			$out['codec']  = $m['codec'] ?? 'deflate';
			$out['parts']  = array_keys( array_filter( (array) ( $m['contents'] ?? array() ) ) );
			$out['tables'] = count( (array) ( $m['db']['tables'] ?? array() ) );
			return $out;
		} finally {
			fclose( $fh );
		}
	}

	private static function safe_rel( $rel ) {
		return '' !== $rel && false === strpos( $rel, "\0" ) && '/' !== $rel[0] && false === strpos( $rel, '\\' )
			&& ! preg_match( '#(^|/)\.\.(/|$)#', $rel ) && ! preg_match( '#^[A-Za-z]:#', $rel );
	}

	/** Where an entry goes: array(final, tmp, defer) or null to skip. */
	private function dest_for( array $e ) {
		$rel = $e['path'];
		if ( ! self::safe_rel( $rel ) ) {
			$this->log( 'Rejected unsafe path in archive: ' . $rel );
			return null;
		}
		if ( AppAlbania_Xhin_Archive::S_META === $e['scope'] ) {
			$f = $this->dir . '/' . basename( $rel );
			return array( 'final' => $f, 'tmp' => $f . '.part', 'defer' => false );
		}
		if ( ! $this->wants( self::part_of( $e['scope'], $rel ) ) ) {
			return null; // not selected for this restore.
		}
		if ( AppAlbania_Xhin_Archive::S_CONTENT === $e['scope'] ) {
			$self    = dirname( APPALBANIA_XHIN_BASENAME );
			$content = str_replace( '\\', '/', WP_CONTENT_DIR );
			$storage = AppAlbania_Xhin_Storage::dir();
			$srel    = 0 === strpos( $storage, $content . '/' ) ? substr( $storage, strlen( $content ) + 1 ) : null;
			if ( 'plugins/' . $self === $rel || 0 === strpos( $rel, 'plugins/' . $self . '/' )
				|| ( $srel && ( $rel === $srel || 0 === strpos( $rel, $srel . '/' ) ) )
				|| 'mu-plugins/0-xhin-guard.php' === $rel
				|| ( ! empty( $this->s['opts']['skip_dropins'] ) && in_array( $rel, array( 'object-cache.php', 'advanced-cache.php', 'db.php' ), true ) ) ) {
				return null;
			}
			$defer = 'mu-plugins' === $rel || 0 === strpos( $rel, 'mu-plugins/' );
			$final = $content . '/' . $rel;
		} elseif ( AppAlbania_Xhin_Archive::S_ROOT === $e['scope'] ) {
			if ( 'wp-config.php' === $rel ) {
				return null; // never overwrite the destination's DB credentials.
			}
			$defer = true; // core files are swapped in at the very end, never under a running request.
			$final = untrailingslashit( str_replace( '\\', '/', ABSPATH ) ) . '/' . $rel;
		} else {
			return null;
		}
		$tmp = $defer ? $this->dir . '/defer/' . $e['scope'] . '/' . $rel : $final . '.xhinpart';
		return array( 'final' => $final, 'tmp' => $tmp, 'defer' => $defer );
	}

	private function stage_extract() {
		$x    = &$this->s['extract'];
		$file = AppAlbania_Xhin_Storage::dir() . '/' . $this->s['archive'];
		$fh   = fopen( $file, 'rb' );
		fseek( $fh, $x['aoff'] );
		$done = false;
		$dl   = null;
		try {
			while ( true ) {
				if ( $x['cur'] ) {
					if ( ! $this->extract_cur( $fh ) ) {
						break;
					}
					continue;
				}
				if ( microtime( true ) >= $this->deadline ) {
					break;
				}
				if ( $this->due() ) {
					if ( $dl ) {
						fflush( $dl );
					}
					$this->save();
				}
				$e = AppAlbania_Xhin_Archive::read_entry( $fh );
				if ( $e['trailer'] ) {
					$x['aoff'] = ftell( $fh );
					$done      = true;
					break;
				}
				$d = $this->dest_for( $e );
				if ( AppAlbania_Xhin_Archive::T_DIR === $e['type'] ) {
					if ( $d ) {
						if ( $d['defer'] ) {
							$dl = $dl ? $dl : fopen( $this->dir . '/deferred.list', 'ab' );
							fwrite( $dl, 'D' . "\t" . $e['scope'] . "\t" . AppAlbania_Xhin_Archive::enc( $e['path'] ) . "\n" );
						} else {
							wp_mkdir_p( $d['final'] );
							AppAlbania_Xhin_Storage::own( $d['final'] );
						}
					}
					$x['aoff'] = ftell( $fh );
					continue;
				}
				$x['cur']  = array(
					'skip'    => null === $d,
					'tmp'     => $d ? base64_encode( $d['tmp'] ) : '',
					'final'   => $d ? base64_encode( $d['final'] ) : '',
					'defer'   => $d ? $d['defer'] : false,
					'scope'   => $e['scope'],
					'rel'     => base64_encode( $e['path'] ),
					'mtime'   => $e['mtime'],
					'written' => 0,
				);
				$x['aoff'] = ftell( $fh );
			}
		} finally {
			fclose( $fh );
			if ( $dl ) {
				fclose( $dl );
			}
		}
		$total                       = max( 1, (int) $this->s['total'] );
		$this->s['stage_pct']        = min( 1, $x['bytes'] / $total );
		$this->s['stats']['restored'] = $x['bytes'];
		$this->s['stats']['total']    = $total;
		$this->s['stats']['files']    = $x['files'];
		$this->s['msg']              = $done ? 'Files restored' : sprintf( 'Restoring %s / %s — %s files', AppAlbania_Xhin_Storage::human( $x['bytes'] ), AppAlbania_Xhin_Storage::human( $total ), number_format( $x['files'] ) );
		if ( $done ) {
			$this->log( sprintf( 'Files: %s restored, %s skipped (%s)', number_format( $x['files'] ), number_format( $x['skipped'] ), AppAlbania_Xhin_Storage::human( $x['bytes'] ) ) );
		}
		return $done;
	}

	private function extract_cur( $fh ) {
		$x = &$this->s['extract'];
		$c = &$x['cur'];
		if ( ! empty( $c['commit'] ) ) { // data fully written & state saved: only the rename is left.
			return $this->commit_cur();
		}
		if ( $c['skip'] ) {
			while ( $h = AppAlbania_Xhin_Archive::read_chunk_header( $fh ) ) {
				fseek( $fh, $h['stored'], SEEK_CUR ); // skip without reading: fast even for huge entries.
				$x['bytes'] += $h['raw'];
				$x['aoff']   = ftell( $fh );
				if ( microtime( true ) >= $this->deadline ) {
					return false;
				}
			}
			$x['aoff'] = ftell( $fh );
			$x['skipped']++;
			$x['cur'] = null;
			return true;
		}
		$tmp = base64_decode( $c['tmp'] );
		$dir = dirname( $tmp );
		if ( ! is_dir( $dir ) && ! wp_mkdir_p( $dir ) ) {
			throw new AppAlbania_Xhin_Exception( esc_html( 'Cannot create folder ' . $dir . ' — check permissions.' ) );
		}
		if ( $c['written'] > 0 && ! file_exists( $tmp ) ) {
			throw new AppAlbania_Xhin_Exception( esc_html( 'Partial file vanished: ' . $tmp ) );
		}
		$out = AppAlbania_Xhin_Archive::open_at( $tmp, $c['written'] );
		$key = $this->key();
		$rel = base64_decode( $c['rel'] );
		while ( true ) {
			$h = AppAlbania_Xhin_Archive::read_chunk_header( $fh );
			if ( null === $h ) {
				break;
			}
			$raw = AppAlbania_Xhin_Archive::decode_chunk( $h, AppAlbania_Xhin_Archive::read_exact( $fh, $h['stored'] ), $key, 'in ' . $rel );
			AppAlbania_Xhin_Archive::write_all( $out, $raw );
			$c['written'] += $h['raw'];
			$x['bytes']   += $h['raw'];
			$x['aoff']     = ftell( $fh );
			if ( $this->due() ) {
				fflush( $out );
				$this->save();
			}
			if ( microtime( true ) >= $this->deadline ) {
				fclose( $out );
				return false;
			}
		}
		fflush( $out );
		fclose( $out );
		// Two-phase: persist "fully written" before touching the live file.
		$x['aoff']   = ftell( $fh );
		$c['commit'] = true;
		$this->save();
		return $this->commit_cur();
	}

	private function commit_cur() {
		$x   = &$this->s['extract'];
		$c   = &$x['cur'];
		$tmp = base64_decode( $c['tmp'] );
		$rel = base64_decode( $c['rel'] );
		if ( $c['defer'] ) {
			@file_put_contents( $this->dir . '/deferred.list', 'F' . "\t" . $c['scope'] . "\t" . AppAlbania_Xhin_Archive::enc( $rel ) . "\n", FILE_APPEND );
		} else {
			$final = base64_decode( $c['final'] );
			if ( file_exists( $tmp ) ) {
				if ( ! @rename( $tmp, $final ) ) { // Windows will not rename over an existing file.
					wp_delete_file( $final );
					if ( ! @rename( $tmp, $final ) ) {
						throw new AppAlbania_Xhin_Exception( esc_html( 'Cannot write ' . $final . ' — check permissions.' ) );
					}
				}
			}
			if ( $c['mtime'] && file_exists( $final ) ) {
				@touch( $final, (int) $c['mtime'] );
			}
			AppAlbania_Xhin_Storage::own( $final );
		}
		$x['files']++;
		$x['cur'] = null;
		return true;
	}

	private function db_wanted() {
		$m = $this->manifest();
		return ! empty( $m['db']['included'] ) && $this->wants( 'db' ) && file_exists( $this->dir . '/database.sql' );
	}

	private function stage_db_import() {
		if ( ! $this->db_wanted() ) {
			$this->s['skip_db'] = true;
			$this->log( $this->wants( 'db' ) ? 'Archive contains no database — keeping the current one.' : 'Database not selected — keeping the current one.' );
			return true;
		}
		if ( empty( $this->s['dbi'] ) ) {
			AppAlbania_Xhin_DB::drop_prefixed( $this->s['tmp'] );
			$this->s['dbi'] = array( 'off' => 0, 'stmts' => 0, 'size' => AppAlbania_Xhin_Storage::filesize( $this->dir . '/database.sql' ) );
			$this->log( 'Importing database into temporary tables (' . $this->s['tmp'] . '*) — your live site keeps running.' );
		}
		$self = $this;
		$done = AppAlbania_Xhin_DB::import(
			$this->s['dbi'],
			$this->dir . '/database.sql',
			$this->s['tmp'],
			$this->deadline,
			function () use ( $self ) {
				$self->checkpoint();
			}
		);
		$d                    = $this->s['dbi'];
		$this->s['stage_pct'] = $d['size'] ? $d['off'] / $d['size'] : 1;
		$this->s['stats']['sql'] = $d['off'];
		$this->s['msg']       = sprintf( 'Importing database %s / %s', AppAlbania_Xhin_Storage::human( $d['off'] ), AppAlbania_Xhin_Storage::human( $d['size'] ) );
		if ( $done ) {
			$this->log( sprintf( 'Database imported: %s statements', number_format( $d['stmts'] ) ) );
		}
		return $done;
	}

	/** Saves the import cursor after every SQL statement (replays at most one). */
	public function checkpoint() {
		$this->save();
	}

	/**
	 * Mid-slice checkpoints: hosts and proxies sometimes kill a request long before
	 * max_execution_time. Committing every ~1.5 s means a kill loses seconds, not the slice.
	 */
	private function due() {
		return microtime( true ) - $this->last_save >= 0.75;
	}

	public function due_public() {
		return $this->due();
	}

	private function stage_db_replace() {
		if ( ! empty( $this->s['skip_db'] ) ) {
			return true;
		}
		$m = $this->manifest();
		if ( empty( $this->s['rep'] ) ) {
			$map     = AppAlbania_Xhin_Replace::migration_map( $m['site'], $this->s['dest'] );
			$needles = array();
			foreach ( array( 'home', 'siteurl' ) as $k ) {
				$h = wp_parse_url( $m['site'][ $k ] ?? '' );
				if ( ! empty( $h['host'] ) ) {
					$needles[] = $h['host'] . ( isset( $h['port'] ) ? ':' . $h['port'] : '' );
				}
			}
			foreach ( array( 'abspath', 'content_dir' ) as $k ) {
				$pth = $m['site'][ $k ] ?? '';
				if ( strlen( $pth ) >= 4 && $pth !== $this->s['dest'][ $k ] ) {
					$needles[] = $pth;
				}
			}
			$this->s['rep'] = array(
				'tables'  => AppAlbania_Xhin_DB::tables( $this->s['tmp'] ),
				'ti'      => 0,
				'meta'    => null,
				'updated' => 0,
				'map'     => $map,
				'needles' => array_values( array_unique( $needles ) ),
			);
			if ( $map ) {
				$this->log( 'Search & replace: ' . ( $m['site']['home'] ?? '' ) . ' → ' . $this->s['dest']['home'] . ' (' . count( $map ) . ' variants incl. JSON-escaped, URL-encoded, paths)' );
			} else {
				$this->log( 'Same URL and paths — no replacement needed.' );
			}
		}
		$r = &$this->s['rep'];
		if ( ! $r['map'] ) {
			return true;
		}
		$self                 = $this;
		$done                 = AppAlbania_Xhin_DB::replace(
			$r,
			new AppAlbania_Xhin_Replace( $r['map'] ),
			$r['needles'],
			$this->deadline,
			function () use ( $self ) {
				if ( $self->due_public() ) {
					$self->save();
				}
			}
		);
		$n                    = count( $r['tables'] );
		$this->s['stage_pct'] = $n ? $r['ti'] / $n : 1;
		$this->s['stats']['replaced'] = $r['updated'];
		$this->s['msg']       = sprintf( 'Updating URLs — table %d/%d, %s rows changed', min( $r['ti'] + 1, $n ), $n, number_format( $r['updated'] ) );
		if ( $done ) {
			$this->log( sprintf( 'Replaced in %s rows (serialized data rewritten safely).', number_format( $r['updated'] ) ) );
		}
		return $done;
	}

	private function stage_db_prepare() {
		if ( ! empty( $this->s['skip_db'] ) ) {
			return true;
		}
		$m = $this->manifest();
		AppAlbania_Xhin_DB::prepare( $this->s['tmp'], $m['db']['prefix'], $this->s['dest']['prefix'], $this->s['dest'] );
		$this->log( 'Prefix ' . $m['db']['prefix'] . ' → ' . $this->s['dest']['prefix'] . ', site URLs pinned, caches cleared, Xhin kept active.' );
		return true;
	}

	private function stage_db_swap() {
		if ( ! empty( $this->s['skip_db'] ) || ! empty( $this->s['swapped'] ) ) {
			return true;
		}
		if ( empty( $this->s['swapping'] ) ) {
			$this->s['swapping'] = true; // persisted BEFORE the rename, so a kill right after it is recognised.
			$this->save();
		}
		if ( AppAlbania_Xhin_DB::tables( $this->s['tmp'] ) ) {
			AppAlbania_Xhin_DB::swap( $this->s['tmp'], $this->s['dest']['prefix'], $this->s['old'] );
		} else {
			// RENAME TABLE is atomic: no temp tables left means it already happened in a killed request.
			AppAlbania_Xhin_DB::drop_prefixed( $this->s['old'] );
			$this->log( 'Database switch had already completed — resuming after it.' );
		}
		$this->s['swapped'] = true;
		$this->save();
		wp_cache_flush();
		$this->log( 'New database activated (atomic RENAME TABLE).' );
		return true;
	}

	/**
	 * A database from an older WordPress restored under a newer core: run WordPress's own database
	 * update (what "Update WordPress Database" does), so the site works right away. Runs in a fresh
	 * request, so the core files placed by the previous step are the ones loaded.
	 */
	private function stage_upgrade() {
		if ( ! empty( $this->s['skip_db'] ) || empty( $this->s['swapped'] ) ) {
			return true; // no database was restored.
		}
		$me = getmypid() . '@' . ( defined( 'WP_START_TIMESTAMP' ) ? WP_START_TIMESTAMP : 0 );
		if ( $this->wants( 'core' ) && $this->part_in_archive( 'core' ) ) {
			// New core files were placed: the update must run in a process that loaded them.
			if ( empty( $this->s['upgrade_from'] ) ) {
				$this->s['upgrade_from'] = $me;
				return 'wait';
			}
			if ( $this->s['upgrade_from'] === $me ) {
				if ( defined( 'WP_CLI' ) && WP_CLI ) {
					$this->log( 'If WordPress asks for a database update, run: wp core update-db' );
					return true;
				}
				return 'wait';
			}
		}
		$wp_db_version = 0;
		include ABSPATH . WPINC . '/version.php'; // the core on disk now, not the one this request started with.
		wp_cache_delete( 'alloptions', 'options' );
		wp_cache_delete( 'db_version', 'options' );
		$have = (int) get_option( 'db_version' );
		if ( ! $wp_db_version || $have === (int) $wp_db_version ) {
			return true;
		}
		// WordPress asks for this whenever the versions differ (older or newer database); same routine as its button.
		$this->log( sprintf( 'Database is from %s WordPress (db version %d, this core %d) — running the WordPress database update.', $have < $wp_db_version ? 'an older' : 'a newer', $have, $wp_db_version ) );
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		wp_cache_flush();
		wp_upgrade();
		wp_cache_flush();
		wp_cache_delete( 'alloptions', 'options' );
		wp_cache_delete( 'db_version', 'options' );
		$now = (int) get_option( 'db_version' );
		$this->log( $now === (int) $wp_db_version ? 'WordPress database updated.' : 'WordPress database update did not complete — WordPress will offer it on the next login.' );
		return true;
	}

	/**
	 * Swaps wp-admin and wp-includes as whole folders: the new folder is complete before two
	 * renames put it live, so WordPress is never left with a mix of old and new core files.
	 * Crash-safe: every state left by a killed request is recognised and finished.
	 */
	private function swap_core_dirs() {
		$root = untrailingslashit( str_replace( '\\', '/', ABSPATH ) );
		foreach ( array( 'wp-admin', 'wp-includes' ) as $d ) {
			$new  = $this->dir . '/defer/' . AppAlbania_Xhin_Archive::S_ROOT . '/' . $d;
			$live = $root . '/' . $d;
			$old  = $live . '.xhinold';
			if ( ! is_dir( $new ) ) {
				if ( ! is_dir( $live ) && is_dir( $old ) ) {
					// phpcs:ignore PluginCheck.CodeAnalysis.WriteFile -- restoring the backed-up site's own WordPress core / must-use files is this plugin's purpose.
					@rename( $old, $live ); // killed between the two renames, new folder gone: put the old one back.
				}
				continue;
			}
			if ( is_dir( $live ) ) {
				if ( is_dir( $old ) ) {
					self::rrmdir( $old, 0 ); // stale leftover of an earlier attempt.
				}
				// phpcs:ignore PluginCheck.CodeAnalysis.WriteFile -- restoring the backed-up site's own WordPress core / must-use files is this plugin's purpose.
				if ( ! @rename( $live, $old ) ) {
					continue; // cannot move it (permissions / other disk): files are placed one by one instead.
				}
			}
			// phpcs:ignore PluginCheck.CodeAnalysis.WriteFile -- restoring the backed-up site's own WordPress core / must-use files is this plugin's purpose.
			if ( ! @rename( $new, $live ) ) {
				// phpcs:ignore PluginCheck.CodeAnalysis.WriteFile -- restoring the backed-up site's own WordPress core / must-use files is this plugin's purpose.
				@rename( $old, $live ); // e.g. job folder on another disk: undo, place file by file.
				continue;
			}
			$this->s['core_old'][] = $old;
			$this->log( 'Swapped in ' . $d . ' in one step.' );
		}
		$this->s['core_swapped'] = true;
		self::flush_opcache();
		$this->save();
	}

	/** Compiled copies of the old core files must not outlive the swap (OPcache revalidates only every few seconds, or never). */
	private static function flush_opcache() {
		if ( function_exists( 'opcache_reset' ) && ! ini_get( 'opcache.restrict_api' ) ) {
			@opcache_reset();
		}
	}

	/** Deletes a folder tree; stops at $deadline (0 = no limit) and returns whether it is gone. */
	private static function rrmdir( $dir, $deadline ) {
		if ( ! is_dir( $dir ) || is_link( $dir ) ) {
			return true;
		}
		$it = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $dir, FilesystemIterator::SKIP_DOTS ), RecursiveIteratorIterator::CHILD_FIRST );
		foreach ( $it as $f ) {
			if ( $deadline && microtime( true ) >= $deadline ) {
				return false;
			}
			if ( $f->isDir() && ! $f->isLink() ) {
				@rmdir( $f->getPathname() );
			} else {
				wp_delete_file( $f->getPathname() );
			}
		}
		return @rmdir( $dir );
	}

	private function stage_finalize() {
		if ( empty( $this->s['core_swapped'] ) ) {
			$this->swap_core_dirs();
		}
		$f  = $this->dir . '/deferred.list';
		$fh = fopen( $f, 'rb' );
		fseek( $fh, (int) ( $this->s['fin'] ?? 0 ) );
		$done = false;
		// Core files are swapped in one go: renames take well under a second, and a request that stopped
		// half-way would leave WordPress with mixed old/new core files. Allowed past the normal deadline,
		// but by at most half a step — so it stays inside the host's max_execution_time.
		$hard = $this->deadline + max( 5.0, 0.5 * $this->slice );
		while ( true ) {
			if ( microtime( true ) >= $hard ) {
				break;
			}
			if ( $this->due() ) {
				$this->save(); // fin points at the next unprocessed line here.
			}
			$line = fgets( $fh );
			if ( false === $line ) {
				$done = true;
				break;
			}
			$this->s['fin'] = ftell( $fh ); // placing a file twice is harmless (missing source = skip).
			$parts          = explode( "\t", rtrim( $line, "\n" ), 3 );
			if ( 3 !== count( $parts ) ) {
				continue;
			}
			list( $t, $scope, $rel ) = $parts;
			$rel   = AppAlbania_Xhin_Archive::dec( $rel );
			$base  = AppAlbania_Xhin_Archive::S_CONTENT === (int) $scope ? str_replace( '\\', '/', WP_CONTENT_DIR ) : untrailingslashit( str_replace( '\\', '/', ABSPATH ) );
			$final = $base . '/' . $rel;
			if ( 'D' === $t ) {
				wp_mkdir_p( $final );
				AppAlbania_Xhin_Storage::own( $final );
				continue;
			}
			$src = $this->dir . '/defer/' . $scope . '/' . $rel;
			if ( ! file_exists( $src ) ) {
				continue;
			}
			wp_mkdir_p( dirname( $final ) );
			// phpcs:ignore PluginCheck.CodeAnalysis.WriteFile -- restoring the backed-up site's own WordPress core / must-use files is this plugin's purpose.
			if ( ! @rename( $src, $final ) ) {
				// phpcs:ignore PluginCheck.CodeAnalysis.WriteFile -- restoring the backed-up site's own WordPress core / must-use files is this plugin's purpose.
				if ( ! @copy( $src, $final ) ) {
					$this->log( 'Could not place ' . $rel );
					continue;
				}
				wp_delete_file( $src );
			}
			AppAlbania_Xhin_Storage::own( $final );
		}
		fclose( $fh );
		if ( ! $done ) {
			$this->s['msg'] = 'Placing core & must-use files…';
			return false;
		}
		if ( empty( $this->s['core_placed'] ) ) {
			$this->s['core_placed'] = true;
			self::flush_opcache();
		}
		foreach ( (array) ( $this->s['core_old'] ?? array() ) as $i => $old ) {
			if ( ! self::rrmdir( $old, $this->deadline ) ) {
				$this->s['msg'] = 'Cleaning up old core files…';
				return false;
			}
			unset( $this->s['core_old'][ $i ] );
		}
		if ( function_exists( 'opcache_reset' ) ) {
			@opcache_reset();
		}
		wp_cache_flush();
		$this->s['result'] = array( 'login' => wp_login_url(), 'home' => $this->s['dest']['home'], 'db' => empty( $this->s['skip_db'] ) );
		$this->s['msg']    = empty( $this->s['skip_db'] )
			? 'Restore complete! Log in with the username & password of the backed-up site.'
			: 'Restore complete!';
		$this->log( $this->s['msg'] );
		return true;
	}
}
