<?php
/**
 * WP-CLI: the fastest way to move very large sites (no HTTP time limits).
 *
 *   wp xhin export [--core] [--password=<p>]
 *   wp xhin import <file|name|url> [--password=<p>] --yes
 *   wp xhin list | status | cancel
 *
 * @package XhinMigration
 */

defined( 'ABSPATH' ) || exit;

// phpcs:disable WordPress.WP.AlternativeFunctions -- Backup archives (often many GB) are streamed with fopen/fread/fwrite,
// fseek, ftruncate and flock, and folders are swapped with rename(). WP_Filesystem offers no streaming, seeking,
// appending or locking. Every path used is inside this site or its own backup folder.

class AppAlbania_Xhin_CLI {

	/**
	 * Create a .xhin backup of this site.
	 *
	 * ## OPTIONS
	 *
	 * [--without=<parts>]
	 * : Comma-separated parts to leave out: db, media, plugins, themes, muplugins, other.
	 *
	 * [--core]
	 * : Also include WordPress core and all files in the site root.
	 *
	 * [--password=<password>]
	 * : Encrypt the archive with AES-256-GCM.
	 *
	 * [--exclude=<patterns>]
	 * : Comma-separated paths or wildcards (e.g. "wp-content/uploads/2019,*.log").
	 *
	 * ## EXAMPLES
	 *
	 *     wp xhin export
	 *     wp xhin export --core --password=secret
	 *     wp xhin export --without=media,plugins
	 */
	public function export( $args, $assoc ) {
		$without = array_filter( array_map( 'trim', explode( ',', strtolower( (string) ( $assoc['without'] ?? '' ) ) ) ) );
		$parts   = array();
		foreach ( array( 'db', 'media', 'plugins', 'themes', 'muplugins', 'other' ) as $k ) {
			$parts[ $k ] = in_array( $k, $without, true ) ? 0 : 1;
		}
		$opts = AppAlbania_Xhin_Plugin::export_defaults(
			$parts + array(
				'core'       => empty( $assoc['core'] ) ? 0 : 1,
				'password'   => (string) ( $assoc['password'] ?? '' ),
				'excludes'   => (string) ( $assoc['exclude'] ?? '' ),
				'background' => 0,
			)
		);
		$this->run( $this->create( 'export', $opts ) );
	}

	/**
	 * Restore a .xhin archive into this site (database + files, URLs rewritten).
	 *
	 * ## OPTIONS
	 *
	 * <source>
	 * : Path to a .xhin file, a file name in the storage folder, or an http(s) migration link.
	 *
	 * [--password=<password>]
	 * : Password for encrypted archives.
	 *
	 * [--keep-dropins]
	 * : Also restore object-cache.php / advanced-cache.php / db.php drop-ins.
	 *
	 * [--yes]
	 * : Do not ask for confirmation.
	 *
	 * ## EXAMPLES
	 *
	 *     wp xhin import /home/me/site.xhin --yes
	 *     wp xhin import "https://old.example.com/wp-admin/admin-ajax.php?action=xhin_pull&f=..." --yes
	 */
	public function import( $args, $assoc ) {
		$src  = $args[0];
		$opts = array(
			'source'       => 'local',
			'password'     => (string) ( $assoc['password'] ?? '' ),
			'skip_dropins' => empty( $assoc['keep-dropins'] ) ? 1 : 0,
			'background'   => 0,
		);
		if ( preg_match( '#^https?://#i', $src ) ) {
			$opts['source'] = 'url';
			$opts['url']    = $src;
		} elseif ( AppAlbania_Xhin_Storage::path( $src ) ) {
			$opts['name'] = basename( $src );
		} elseif ( is_file( $src ) ) {
			AppAlbania_Xhin_Storage::ensure();
			$name   = AppAlbania_Xhin_Storage::unique_name( basename( $src ) );
			$target = AppAlbania_Xhin_Storage::dir() . '/' . $name;
			if ( ! @link( $src, $target ) ) {
				WP_CLI::log( 'Copying archive into storage…' );
				if ( ! copy( $src, $target ) ) {
					WP_CLI::error( 'Could not copy archive to ' . $target );
				}
			}
			$opts['name'] = $name;
		} else {
			WP_CLI::error( 'Archive not found: ' . $src );
		}
		WP_CLI::confirm( 'This REPLACES the database and files of ' . get_option( 'home' ) . '. Continue?', $assoc );
		$this->run( $this->create( 'import', $opts ) );
	}

	/**
	 * List archives in storage.
	 *
	 * @subcommand list
	 */
	public function list_( $args, $assoc ) {
		$rows = array_map(
			function ( $a ) {
				return array(
					'name'      => $a['name'],
					'size'      => AppAlbania_Xhin_Storage::human( $a['size'] ),
					'date'      => wp_date( 'Y-m-d H:i', $a['time'] ),
					'encrypted' => $a['encrypted'] ? 'yes' : 'no',
					'complete'  => $a['valid'] ? 'yes' : 'NO',
				);
			},
			AppAlbania_Xhin_Storage::archives()
		);
		WP_CLI\Utils\format_items( 'table', $rows, array( 'name', 'size', 'date', 'encrypted', 'complete' ) );
		WP_CLI::log( 'Storage: ' . AppAlbania_Xhin_Storage::dir() );
	}

	/**
	 * Show the active job, or resume it in this terminal with --resume.
	 *
	 * [--resume]
	 * : Continue running the active job here.
	 */
	public function status( $args, $assoc ) {
		$j = AppAlbania_Xhin_Job::current();
		if ( ! $j ) {
			WP_CLI::log( 'No active job.' );
			return;
		}
		$s = $j->public_state();
		WP_CLI::log( sprintf( '%s job %s — %s, %s%% — %s', $s['type'], $s['id'], $s['status'], $s['pct'], $s['msg'] ) );
		if ( ! empty( $assoc['resume'] ) ) {
			$j->retry();
			$this->run( $j );
		}
	}

	/**
	 * Cancel the active job and clean up.
	 */
	public function cancel() {
		$j = AppAlbania_Xhin_Job::current();
		if ( ! $j ) {
			WP_CLI::log( 'No active job.' );
			return;
		}
		$s = $j->request_cancel();
		WP_CLI::success( $s['msg'] ?? 'Cancelling…' );
	}

	/**
	 * Verify an archive: walks every entry and checks every chunk's CRC (and GCM tag when encrypted).
	 *
	 * ## OPTIONS
	 *
	 * <file>
	 * : Path or name in storage.
	 *
	 * [--password=<password>]
	 * : Password for encrypted archives.
	 */
	public function verify( $args, $assoc ) {
		$file = AppAlbania_Xhin_Storage::path( $args[0] ) ? AppAlbania_Xhin_Storage::path( $args[0] ) : $args[0];
		$fh   = @fopen( $file, 'rb' );
		if ( ! $fh ) {
			WP_CLI::error( 'Cannot open ' . $file );
		}
		try {
			$h   = AppAlbania_Xhin_Archive::read_header( $fh );
			$key = null;
			if ( $h['encrypted'] ) {
				$key = AppAlbania_Xhin_Archive::derive_key( (string) ( $assoc['password'] ?? '' ), $h['salt'] );
				if ( ! hash_equals( $h['check'], AppAlbania_Xhin_Archive::key_check( $key ) ) ) {
					WP_CLI::error( 'Wrong or missing --password.' );
				}
			}
			$files = 0;
			$dirs  = 0;
			$bytes = 0;
			while ( true ) {
				$e = AppAlbania_Xhin_Archive::read_entry( $fh );
				if ( $e['trailer'] ) {
					break;
				}
				if ( AppAlbania_Xhin_Archive::T_DIR === $e['type'] ) {
					$dirs++;
					continue;
				}
				$files++;
				while ( $c = AppAlbania_Xhin_Archive::read_chunk_header( $fh ) ) {
					AppAlbania_Xhin_Archive::decode_chunk( $c, AppAlbania_Xhin_Archive::read_exact( $fh, $c['stored'] ), $key, 'in ' . $e['path'] );
					$bytes += $c['raw'];
				}
			}
			if ( (int) $e['bytes'] !== $bytes || (int) $e['entries'] !== $files + $dirs ) {
				WP_CLI::error( sprintf( 'Trailer mismatch: %d entries / %d bytes recorded, %d / %d found.', $e['entries'], $e['bytes'], $files + $dirs, $bytes ) );
			}
			if ( false !== fread( $fh, 1 ) && ! feof( $fh ) ) {
				WP_CLI::warning( 'Extra data after trailer.' );
			}
			WP_CLI::success( sprintf( 'Archive OK — %s files, %s folders, %s, all CRCs valid%s.', number_format( $files ), number_format( $dirs ), AppAlbania_Xhin_Storage::human( $bytes ), $key ? ', decrypted & authenticated' : '' ) );
		} catch ( AppAlbania_Xhin_Exception $ex ) {
			WP_CLI::error( AppAlbania_Xhin_Exception::text( $ex ) );
		}
	}

	private function create( $type, $opts ) {
		try {
			return AppAlbania_Xhin_Job::create( $type, $opts );
		} catch ( Throwable $e ) {
			WP_CLI::error( AppAlbania_Xhin_Exception::text( $e ) );
		}
	}

	private function run( AppAlbania_Xhin_Job $j ) {
		$last = '';
		while ( true ) {
			$s = $j->step( 60 );
			if ( ! empty( $s['busy'] ) ) {
				sleep( 1 );
				continue;
			}
			$line = sprintf( '[%5.1f%%] %s', $s['pct'], $s['msg'] );
			if ( $line !== $last ) {
				WP_CLI::log( $line );
				$last = $line;
			}
			if ( 'done' === $s['status'] ) {
				WP_CLI::success( $s['msg'] );
				return;
			}
			if ( in_array( $s['status'], array( 'error', 'cancelled' ), true ) ) {
				WP_CLI::error( $s['msg'] . ( 'error' === $s['status'] ? ' (fix the cause, then: wp xhin status --resume)' : '' ) );
			}
		}
	}
}
