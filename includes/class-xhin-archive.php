<?php
/**
 * The .xhin archive protocol (v1).
 *
 * Layout (all integers big-endian):
 *
 *   HEADER (64 bytes)
 *     magic     8   "XHIN\x1A\r\n\0"   (PNG-style: catches text-mode / FTP ASCII corruption)
 *     version   u16
 *     flags     u16 bit0 = encrypted, bit1 = contains zstd chunks
 *     created   u64 unix time
 *     salt      16  PBKDF2 salt (zero when not encrypted)
 *     keycheck  16  HMAC(key, "xhin-key-check")[0..16] (zero when not encrypted)
 *     reserved  12
 *
 *   ENTRY (repeated)
 *     "XE"  type u8 (1 file, 2 dir)  scope u8 (0 meta, 1 wp-content, 2 site root)
 *     path_len u16  path  size u64  mtime u64  mode u32
 *     CHUNK* (files only), then a terminator chunk (all zero)
 *
 *   CHUNK
 *     raw_len u32  stored_len u32  crc32(raw) u32  flags u8 (bit0 deflate, bit1 AES-256-GCM, bit2 zstd)
 *     data[stored_len]   (when encrypted: iv[12] + tag[16] + ciphertext)
 *
 *   TRAILER (26 bytes)
 *     "XZ"  entries u64  total_raw_bytes u64  "XHINEND!"
 *
 * Chunks make the format streamable, resumable at any chunk boundary, verifiable
 * (CRC per chunk) and size-unlimited (u64 everywhere).
 *
 * @package XhinMigration
 */

defined( 'ABSPATH' ) || exit;

// phpcs:disable WordPress.WP.AlternativeFunctions -- Backup archives (often many GB) are streamed with fopen/fread/fwrite,
// fseek, ftruncate and flock, and folders are swapped with rename(). WP_Filesystem offers no streaming, seeking,
// appending or locking. Every path used is inside this site or its own backup folder.

class AppAlbania_Xhin_Exception extends Exception {

	/**
	 * Messages are HTML-escaped where they are thrown; this returns plain text for places that
	 * are not HTML (the job log, JSON read by the browser via textContent, WP-CLI).
	 */
	public static function text( $e ) {
		return html_entity_decode( (string) $e->getMessage(), ENT_QUOTES | ENT_HTML5, 'UTF-8' );
	}
}

final class AppAlbania_Xhin_Archive {

	const MAGIC       = "XHIN\x1A\r\n\0";
	const VERSION     = 1;
	const HEADER_LEN  = 64;
	const TRAILER_LEN = 26;
	const CHUNK_HDR   = 13;
	const MAX_CHUNK   = 67108864; // 64 MB sanity cap against corrupt headers.

	const FLAG_ENCRYPTED = 1;
	const FLAG_ZSTD      = 2;

	const C_DEFLATE = 1;
	const C_AES     = 2;
	const C_ZSTD    = 4;

	/** stored/raw ratio of the last chunk() call — used to stop compressing incompressible files. */
	public static $last_ratio = 1.0;

	const T_FILE = 1;
	const T_DIR  = 2;

	const S_META    = 0;
	const S_CONTENT = 1;
	const S_ROOT    = 2;

	/** Extensions that are already compressed: never waste CPU deflating them. */
	const NO_COMPRESS = 'jpg|jpeg|png|gif|webp|avif|heic|mp4|m4v|mov|webm|mkv|avi|mp3|m4a|ogg|wav|flac|zip|gz|tgz|bz2|xz|7z|rar|xhin|wpress|pdf|woff|woff2|docx|xlsx|pptx|jar|apk';

	public static function header( $flags, $salt = '', $check = '' ) {
		$salt  = str_pad( substr( (string) $salt, 0, 16 ), 16, "\0" );
		$check = str_pad( substr( (string) $check, 0, 16 ), 16, "\0" );
		return self::MAGIC . pack( 'nnJ', self::VERSION, $flags, time() ) . $salt . $check . str_repeat( "\0", 12 );
	}

	public static function read_header( $fh ) {
		$bin = self::read_exact( $fh, self::HEADER_LEN );
		if ( substr( $bin, 0, 8 ) !== self::MAGIC ) {
			throw new AppAlbania_Xhin_Exception( esc_html( 'This is not a .xhin archive (magic bytes do not match).' ) );
		}
		$h = unpack( 'nversion/nflags/Jcreated', substr( $bin, 8, 12 ) );
		if ( $h['version'] > self::VERSION ) {
			throw new AppAlbania_Xhin_Exception( esc_html( sprintf( 'Archive format v%d is newer than this plugin supports (v%d). Update Migration by AppAlbania to the latest version.', $h['version'], self::VERSION ) ) );
		}
		$h['encrypted'] = (bool) ( $h['flags'] & self::FLAG_ENCRYPTED );
		$h['zstd']      = (bool) ( $h['flags'] & self::FLAG_ZSTD );
		if ( $h['zstd'] && ! self::zstd_available() ) {
			throw new AppAlbania_Xhin_Exception( esc_html( 'This archive was made with zstd compression, but this server has no php-zstd extension. Ask your host to enable "zstd", or re-export the source site with Compression = Standard.' ) );
		}
		$h['salt']      = substr( $bin, 20, 16 );
		$h['check']     = substr( $bin, 36, 16 );
		return $h;
	}

	/** Cheap truncation check: a complete archive always ends with the trailer. */
	public static function read_trailer( $file ) {
		$size = filesize( $file );
		if ( $size < self::HEADER_LEN + self::TRAILER_LEN ) {
			return false;
		}
		$fh = fopen( $file, 'rb' );
		fseek( $fh, -self::TRAILER_LEN, SEEK_END );
		$t = fread( $fh, self::TRAILER_LEN );
		fclose( $fh );
		if ( strlen( $t ) !== self::TRAILER_LEN || 'XZ' !== substr( $t, 0, 2 ) || 'XHINEND!' !== substr( $t, 18 ) ) {
			return false;
		}
		return unpack( 'Jentries/Jbytes', substr( $t, 2, 16 ) );
	}

	public static function entry_header( $type, $scope, $path, $size, $mtime, $mode ) {
		$path = (string) $path;
		if ( strlen( $path ) > 65535 ) {
			throw new AppAlbania_Xhin_Exception( esc_html( 'Path too long: ' . substr( $path, 0, 120 ) ) );
		}
		return 'XE' . pack( 'CCn', $type, $scope, strlen( $path ) ) . $path . pack( 'JJN', max( 0, (int) $size ), max( 0, (int) $mtime ), (int) $mode & 0xFFFFFFFF );
	}

	public static function trailer( $entries, $bytes ) {
		return 'XZ' . pack( 'JJ', $entries, $bytes ) . 'XHINEND!';
	}

	/**
	 * Reads the next record header. Returns an entry array or array( 'trailer' => true, ... ).
	 */
	public static function read_entry( $fh ) {
		$marker = self::read_exact( $fh, 2 );
		if ( 'XZ' === $marker ) {
			$t = self::read_exact( $fh, 24 );
			if ( 'XHINEND!' !== substr( $t, 16 ) ) {
				throw new AppAlbania_Xhin_Exception( esc_html( 'Corrupt archive trailer.' ) );
			}
			$u            = unpack( 'Jentries/Jbytes', substr( $t, 0, 16 ) );
			$u['trailer'] = true;
			return $u;
		}
		if ( 'XE' !== $marker ) {
			throw new AppAlbania_Xhin_Exception( esc_html( sprintf( 'Corrupt archive: expected entry marker at byte %s.', number_format( ftell( $fh ) - 2 ) ) ) );
		}
		$a          = unpack( 'Ctype/Cscope/nlen', self::read_exact( $fh, 4 ) );
		$a['path']  = $a['len'] ? self::read_exact( $fh, $a['len'] ) : '';
		$a         += unpack( 'Jsize/Jmtime/Nmode', self::read_exact( $fh, 20 ) );
		$a['trailer'] = false;
		unset( $a['len'] );
		return $a;
	}

	public static function zstd_available() {
		return function_exists( 'zstd_compress' ) && function_exists( 'zstd_uncompress' ) && ! defined( 'XHIN_NO_ZSTD' );
	}

	/**
	 * Builds one chunk record.
	 *
	 * @param string      $raw   Plain bytes.
	 * @param string      $codec 'none' | 'deflate' | 'zstd'.
	 * @param string|null $key   Raw 32-byte AES key or null.
	 */
	public static function chunk( $raw, $codec, $key ) {
		$flags            = 0;
		$data             = $raw;
		self::$last_ratio = 1.0;
		if ( true === $codec ) {
			$codec = 'deflate';
		}
		if ( $codec && 'none' !== $codec && strlen( $raw ) > 256 ) {
			$z = false;
			$f = 0;
			if ( 'zstd' === $codec && self::zstd_available() ) {
				$z = zstd_compress( $raw, 3 );
				$f = self::C_ZSTD;
			} elseif ( function_exists( 'gzdeflate' ) ) {
				$z = gzdeflate( $raw, 6 );
				$f = self::C_DEFLATE;
			}
			if ( false !== $z && '' !== $z ) {
				self::$last_ratio = strlen( $z ) / strlen( $raw );
				if ( self::$last_ratio < 0.97 ) { // only keep it when it actually saves space.
					$data   = $z;
					$flags |= $f;
				}
			}
		}
		if ( $key ) {
			$iv  = random_bytes( 12 );
			$tag = '';
			$ct  = openssl_encrypt( $data, 'aes-256-gcm', $key, OPENSSL_RAW_DATA, $iv, $tag, '', 16 );
			if ( false === $ct ) {
				throw new AppAlbania_Xhin_Exception( esc_html( 'Encryption failed (OpenSSL).' ) );
			}
			$data   = $iv . $tag . $ct;
			$flags |= self::C_AES;
		}
		return pack( 'NNNC', strlen( $raw ), strlen( $data ), crc32( $raw ), $flags ) . $data;
	}

	public static function end_chunks() {
		return pack( 'NNNC', 0, 0, 0, 0 );
	}

	/** Returns null at the terminator, otherwise array(raw_len, stored_len, crc, flags). */
	public static function read_chunk_header( $fh ) {
		$h = unpack( 'Nraw/Nstored/Ncrc/Cflags', self::read_exact( $fh, self::CHUNK_HDR ) );
		if ( 0 === $h['raw'] && 0 === $h['stored'] ) {
			return null;
		}
		if ( $h['raw'] > self::MAX_CHUNK || $h['stored'] > self::MAX_CHUNK + 64 ) {
			throw new AppAlbania_Xhin_Exception( esc_html( 'Corrupt chunk header (size out of range).' ) );
		}
		return $h;
	}

	public static function decode_chunk( array $h, $data, $key, $where = '' ) {
		if ( $h['flags'] & self::C_AES ) {
			if ( ! $key ) {
				throw new AppAlbania_Xhin_Exception( esc_html( 'Archive is encrypted — a password is required.' ) );
			}
			$plain = openssl_decrypt( substr( $data, 28 ), 'aes-256-gcm', $key, OPENSSL_RAW_DATA, substr( $data, 0, 12 ), substr( $data, 12, 16 ) );
			if ( false === $plain ) {
				throw new AppAlbania_Xhin_Exception( esc_html( 'Decryption failed (wrong password or tampered data) ' . $where ) );
			}
			$data = $plain;
		}
		if ( $h['flags'] & self::C_ZSTD ) {
			if ( ! self::zstd_available() ) {
				throw new AppAlbania_Xhin_Exception( esc_html( 'This archive needs the php-zstd extension to restore.' ) );
			}
			$data = zstd_uncompress( $data );
			if ( false === $data ) {
				throw new AppAlbania_Xhin_Exception( esc_html( 'zstd decompression failed ' . $where ) );
			}
		} elseif ( $h['flags'] & self::C_DEFLATE ) {
			$data = gzinflate( $data, self::MAX_CHUNK + 1 );
			if ( false === $data ) {
				throw new AppAlbania_Xhin_Exception( esc_html( 'Decompression failed ' . $where ) );
			}
		}
		if ( strlen( $data ) !== $h['raw'] || ( crc32( $data ) & 0xFFFFFFFF ) !== $h['crc'] ) {
			throw new AppAlbania_Xhin_Exception( esc_html( 'CRC mismatch — archive is damaged ' . $where ) );
		}
		return $data;
	}

	public static function derive_key( $password, $salt ) {
		return hash_pbkdf2( 'sha256', (string) $password, $salt, 200000, 32, true );
	}

	public static function key_check( $key ) {
		return substr( hash_hmac( 'sha256', 'xhin-key-check', $key, true ), 0, 16 );
	}

	public static function compressible( $path ) {
		$ext = strtolower( pathinfo( $path, PATHINFO_EXTENSION ) );
		return '' === $ext || ! preg_match( '/^(?:' . self::NO_COMPRESS . ')$/', $ext );
	}

	public static function read_exact( $fh, $len ) {
		$buf = '';
		while ( strlen( $buf ) < $len ) {
			$part = fread( $fh, $len - strlen( $buf ) );
			if ( false === $part || '' === $part ) {
				throw new AppAlbania_Xhin_Exception( esc_html( 'Unexpected end of archive (file is truncated or still uploading).' ) );
			}
			$buf .= $part;
		}
		return $buf;
	}

	public static function write_all( $fh, $data ) {
		$len = strlen( $data );
		$off = 0;
		while ( $off < $len ) {
			$w = fwrite( $fh, 0 === $off ? $data : substr( $data, $off ) );
			if ( false === $w || 0 === $w ) {
				throw new AppAlbania_Xhin_Exception( esc_html( 'Write failed — disk full or no permission.' ) );
			}
			$off += $w;
		}
		return $len;
	}

	/**
	 * Opens a file for appending at a committed size. Anything past $committed
	 * (a half-written slice from a killed request) is discarded first.
	 */
	public static function open_at( $file, $committed ) {
		$fh = fopen( $file, 'c+b' );
		if ( ! $fh ) {
			$dir   = dirname( $file );
			$owner = '';
			if ( function_exists( 'posix_getpwuid' ) && false !== ( $uid = @fileowner( $dir ) ) ) {
				$pw    = @posix_getpwuid( $uid );
				$owner = $pw ? $pw['name'] : (string) $uid;
			}
			$me = function_exists( 'posix_geteuid' ) && function_exists( 'posix_getpwuid' ) ? ( @posix_getpwuid( posix_geteuid() )['name'] ?? '' ) : '';
			throw new AppAlbania_Xhin_Exception( esc_html( 'Cannot write to ' . $dir . ( $owner && $me && $owner !== $me ? ' — the folder belongs to "' . $owner . '" but PHP runs as "' . $me . '". Fix: chown -R ' . $me . ' ' . $dir : ' — check the folder permissions' ) . '. Then press Continue.' ) );
		}
		ftruncate( $fh, (int) $committed );
		fseek( $fh, 0, SEEK_END );
		return $fh;
	}

	/** Path-safe encoding for tab/newline separated list files. */
	public static function enc( $p ) {
		return strtr( $p, array( '%' => '%25', "\n" => '%0A', "\r" => '%0D', "\t" => '%09' ) );
	}

	public static function dec( $p ) {
		return strtr( $p, array( '%25' => '%', '%0A' => "\n", '%0D' => "\r", '%09' => "\t" ) );
	}
}
