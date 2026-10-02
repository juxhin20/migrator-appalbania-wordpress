<?php
/**
 * Backup storage: protected folder, listing, streaming downloads with HTTP
 * Range (resumable 150 GB downloads) and HMAC-signed server-to-server pull links.
 *
 * @package XhinMigration
 */

defined( 'ABSPATH' ) || exit;

// phpcs:disable WordPress.WP.AlternativeFunctions -- Backup archives (often many GB) are streamed with fopen/fread/fwrite,
// fseek, ftruncate and flock, and folders are swapped with rename(). WP_Filesystem offers no streaming, seeking,
// appending or locking. Every path used is inside this site or its own backup folder.

final class AppAlbania_Xhin_Storage {

	public static function dir() {
		$dir = defined( 'XHIN_STORAGE_DIR' ) ? XHIN_STORAGE_DIR : WP_CONTENT_DIR . '/xhin-backups';
		return rtrim( str_replace( '\\', '/', $dir ), '/' );
	}

	/**
	 * Job state lives in a folder with an unguessable name (.jobs-<128-bit random>).
	 * Apache/IIS also get deny rules, but nginx, Caddy & co ignore .htaccess — the
	 * random name is what keeps state.json (job token, encryption key) and the
	 * temporary SQL dump unreachable from the web on every server.
	 * Found on disk, never stored in the database: a restore replaces the database.
	 */
	public static function jobs_dir() {
		static $cached = null;
		if ( $cached && is_dir( $cached ) ) {
			return $cached;
		}
		$base  = self::dir();
		$found = self::find_jobs_dirs( $base );
		if ( ! $found ) {
			if ( ! is_dir( $base ) ) {
				wp_mkdir_p( $base );
			}
			$new = $base . '/.jobs-' . bin2hex( random_bytes( 16 ) );
			if ( is_dir( $base . '/.jobs' ) ) { // upgrade from 1.0/1.1: move the old, guessable folder.
				@rename( $base . '/.jobs', $new );
			}
			if ( ! is_dir( $new ) && ! wp_mkdir_p( $new ) && ! is_dir( $new ) ) {
				return $new; // ensure() reports the permission problem.
			}
			$found = self::find_jobs_dirs( $base ); // two first requests racing: everyone agrees on the first.
			foreach ( array_slice( $found, 1 ) as $extra ) {
				@rmdir( $extra ); // only removes it if empty.
			}
		}
		return $cached = $found ? $found[0] : $base . '/.jobs';
	}

	private static function find_jobs_dirs( $base ) {
		$found = glob( $base . '/.jobs-*', GLOB_ONLYDIR | GLOB_NOSORT );
		$found = array_values( array_filter( (array) $found, function ( $d ) { return (bool) preg_match( '#/\.jobs-[0-9a-f]{32}$#', $d ); } ) );
		sort( $found );
		return array_map( function ( $d ) { return str_replace( '\\', '/', $d ); }, $found );
	}

	public static function ensure() {
		$dir = self::dir();
		foreach ( array( $dir, self::jobs_dir() ) as $d ) {
			if ( ! is_dir( $d ) && ! wp_mkdir_p( $d ) ) {
				throw new AppAlbania_Xhin_Exception( esc_html( 'Cannot create storage folder ' . $d . ' — check permissions.' ) );
			}
		}
		$guards = array(
			'.htaccess'  => "# Xhin Migration — deny all web access\n<IfModule mod_authz_core.c>\nRequire all denied\n</IfModule>\n<IfModule !mod_authz_core.c>\nOrder allow,deny\nDeny from all\n</IfModule>\nOptions -Indexes\n",
			'web.config' => "<?xml version=\"1.0\"?>\n<configuration><system.webServer><authorization><deny users=\"*\" /></authorization></system.webServer></configuration>\n",
			'index.php'  => "<?php // Silence.\n",
			'index.html' => '',
		);
		foreach ( $guards as $file => $body ) {
			if ( ! file_exists( "$dir/$file" ) ) {
				@file_put_contents( "$dir/$file", $body );
			}
		}
		foreach ( array( 'index.php' => "<?php // Silence.\n", 'index.html' => '', '.htaccess' => $guards['.htaccess'] ) as $file => $body ) {
			if ( ! file_exists( self::jobs_dir() . "/$file" ) ) {
				@file_put_contents( self::jobs_dir() . "/$file", $body );
			}
		}
		return $dir;
	}

	/** Absolute path of an archive in storage, or false (blocks traversal). */
	public static function path( $name ) {
		$name = basename( (string) $name );
		if ( ! preg_match( '/^[A-Za-z0-9._-]+\.xhin$/', $name ) ) {
			return false;
		}
		$p = self::dir() . '/' . $name;
		return is_file( $p ) ? $p : false;
	}

	public static function safe_name( $name ) {
		$name = preg_replace( '/[^A-Za-z0-9._-]+/', '-', basename( (string) $name ) );
		$name = trim( preg_replace( '/\.xhin$/i', '', $name ), '.-' );
		return ( '' === $name ? 'import' : substr( $name, 0, 120 ) ) . '.xhin';
	}

	public static function unique_name( $name ) {
		$name = self::safe_name( $name );
		$base = substr( $name, 0, -5 );
		$i    = 1;
		while ( file_exists( self::dir() . '/' . $name ) ) {
			$name = $base . '-' . ( ++$i ) . '.xhin';
		}
		return $name;
	}

	public static function archives() {
		$out = array();
		foreach ( (array) glob( self::dir() . '/*.xhin' ) as $f ) {
			if ( ! is_file( $f ) ) {
				continue;
			}
			$enc = false;
			$ok  = false;
			if ( $fh = @fopen( $f, 'rb' ) ) {
				try {
					$h   = AppAlbania_Xhin_Archive::read_header( $fh );
					$enc = $h['encrypted'];
					$ok  = true;
				} catch ( Throwable $e ) {
					$ok = false;
				}
				fclose( $fh );
			}
			$out[] = array(
				'name'      => basename( $f ),
				'size'      => self::filesize( $f ),
				'time'      => filemtime( $f ),
				'encrypted' => $enc,
				'valid'     => $ok && false !== AppAlbania_Xhin_Archive::read_trailer( $f ),
			);
		}
		usort( $out, function ( $a, $b ) { return $b['time'] - $a['time']; } );
		return $out;
	}

	public static function filesize( $f ) {
		clearstatcache( true, $f );
		return (int) @filesize( $f );
	}

	public static function free_space() {
		$f = function_exists( 'disk_free_space' ) ? @disk_free_space( self::dir() ) : false;
		return false === $f ? null : (int) $f;
	}

	/**
	 * When WP-CLI runs as root, everything it creates would belong to root and the web
	 * server could no longer continue the job (or update restored plugins later).
	 * Returns the owner of wp-content in that case, so new files can be handed to it.
	 */
	public static function owner() {
		static $o = false;
		if ( false !== $o ) {
			return $o;
		}
		$o = null;
		if ( function_exists( 'posix_geteuid' ) && 0 === @posix_geteuid() ) {
			$st = @stat( WP_CONTENT_DIR );
			if ( $st && $st['uid'] > 0 ) {
				$o = array( (int) $st['uid'], (int) $st['gid'] );
			}
		}
		return $o;
	}

	/** Hands a path (and any parent folders we just created as root) to the site owner. */
	public static function own( $path, $recursive = false ) {
		$o = self::owner();
		if ( ! $o || ! file_exists( $path ) ) {
			return;
		}
		$top = untrailingslashit( str_replace( '\\', '/', ABSPATH ) );
		$p   = rtrim( str_replace( '\\', '/', $path ), '/' );
		while ( strlen( $p ) > strlen( $top ) && 0 === strpos( $p, $top . '/' ) ) {
			$st = @lstat( $p );
			if ( ! $st || 0 !== $st['uid'] ) {
				break;
			}
			@lchown( $p, $o[0] );
			@lchgrp( $p, $o[1] );
			$p = dirname( $p );
		}
		if ( $recursive && is_dir( $path ) && ! is_link( $path ) ) {
			foreach ( new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $path, FilesystemIterator::SKIP_DOTS ), RecursiveIteratorIterator::SELF_FIRST ) as $f ) {
				if ( 0 === @fileowner( $f->getPathname() ) ) {
					@lchown( $f->getPathname(), $o[0] );
					@lchgrp( $f->getPathname(), $o[1] );
				}
			}
		}
	}

	public static function secret() {
		$s = get_option( 'xhin_secret' );
		if ( ! $s ) {
			$s = bin2hex( random_bytes( 24 ) );
			update_option( 'xhin_secret', $s, false );
		}
		return $s;
	}

	public static function pull_url( $name, $ttl = DAY_IN_SECONDS ) {
		$exp = time() + (int) $ttl;
		$sig = hash_hmac( 'sha256', $name . '|' . $exp, self::secret() );
		return add_query_arg(
			array(
				'action' => 'xhin_pull',
				'f'      => rawurlencode( $name ),
				'e'      => $exp,
				's'      => $sig,
			),
			admin_url( 'admin-ajax.php' )
		);
	}

	public static function verify_pull( $name, $exp, $sig ) {
		if ( (int) $exp < time() ) {
			return false;
		}
		return hash_equals( hash_hmac( 'sha256', $name . '|' . (int) $exp, self::secret() ), (string) $sig );
	}

	/** Streams a file with Range support; exits. */
	public static function stream( $file ) {
		// Only for this download request: a multi-GB file must not be cut off or gzipped in memory.
		@set_time_limit( 0 ); // phpcs:ignore Squiz.PHP.DiscouragedFunctions.Discouraged
		@ini_set( 'zlib.output_compression', 'Off' ); // phpcs:ignore WordPress.PHP.IniSet.Risky,Squiz.PHP.DiscouragedFunctions.Discouraged
		while ( ob_get_level() ) {
			@ob_end_clean();
		}
		$size  = self::filesize( $file );
		$start = 0;
		$end   = $size - 1;
		$code  = 200;
		$range = isset( $_SERVER['HTTP_RANGE'] ) ? sanitize_text_field( wp_unslash( $_SERVER['HTTP_RANGE'] ) ) : '';
		if ( '' !== $range && preg_match( '/bytes=(\d*)-(\d*)/', $range, $m ) ) {
			if ( '' === $m[1] && '' !== $m[2] ) {
				$start = max( 0, $size - (int) $m[2] );
			} else {
				$start = (int) $m[1];
				$end   = '' !== $m[2] ? min( (int) $m[2], $size - 1 ) : $size - 1;
			}
			if ( $start > $end || $start >= $size ) {
				status_header( 416 );
				header( "Content-Range: bytes */$size" );
				exit;
			}
			$code = 206;
		}
		status_header( $code );
		nocache_headers();
		header( 'Content-Type: application/octet-stream' );
		header( 'Content-Disposition: attachment; filename="' . basename( $file ) . '"' );
		header( 'Accept-Ranges: bytes' );
		header( 'Content-Length: ' . ( $end - $start + 1 ) );
		header( 'X-Accel-Buffering: no' );
		if ( 206 === $code ) {
			header( "Content-Range: bytes $start-$end/$size" );
		}
		if ( isset( $_SERVER['REQUEST_METHOD'] ) && 'HEAD' === $_SERVER['REQUEST_METHOD'] ) {
			exit;
		}
		$fh = fopen( $file, 'rb' );
		fseek( $fh, $start );
		$left = $end - $start + 1;
		while ( $left > 0 && ! connection_aborted() ) {
			$buf = fread( $fh, (int) min( 1048576, $left ) );
			if ( false === $buf || '' === $buf ) {
				break;
			}
			echo $buf; // phpcs:ignore WordPress.Security.EscapeOutput
			$left -= strlen( $buf );
			flush();
		}
		fclose( $fh );
		exit;
	}

	public static function rrmdir( $dir ) {
		if ( ! is_dir( $dir ) || is_link( $dir ) ) {
			wp_delete_file( $dir );
			return ! file_exists( $dir );
		}
		foreach ( (array) scandir( $dir ) as $f ) {
			if ( '.' !== $f && '..' !== $f ) {
				self::rrmdir( "$dir/$f" );
			}
		}
		return @rmdir( $dir );
	}

	public static function human( $bytes ) {
		$u = array( 'B', 'KB', 'MB', 'GB', 'TB' );
		$i = 0;
		$b = (float) $bytes;
		while ( $b >= 1024 && $i < 4 ) {
			$b /= 1024;
			$i++;
		}
		return round( $b, $i ? 1 : 0 ) . ' ' . $u[ $i ];
	}
}
