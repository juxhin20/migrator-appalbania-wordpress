<?php
/**
 * Serialization-safe search & replace.
 *
 * Instead of unserialize() (unsafe on foreign data, and breaks on classes that
 * do not exist on the new site) this walks the PHP serialize grammar as a
 * token stream and rewrites every s:N:"..." with a corrected length. Objects of
 * unknown classes, enums and nested (double) serialized strings all survive.
 *
 * @package XhinMigration
 */

defined( 'ABSPATH' ) || exit;

final class AppAlbania_Xhin_Replace {

	/** @var array from => to (strtr: longest match first, never re-replaces) */
	private $map;

	/** @var string[] */
	private $needles;

	public function __construct( array $map ) {
		$clean = array();
		foreach ( $map as $from => $to ) {
			$from = (string) $from;
			if ( '' !== $from && $from !== (string) $to ) {
				$clean[ $from ] = (string) $to;
			}
		}
		$this->map     = $clean;
		$this->needles = array_keys( $clean );
	}

	public function is_empty() {
		return empty( $this->map );
	}

	public function run( $value ) {
		if ( ! is_string( $value ) || '' === $value || ! $this->has_needle( $value ) ) {
			return $value;
		}
		return $this->any( $value, 0 );
	}

	private function has_needle( $s ) {
		foreach ( $this->needles as $n ) {
			if ( false !== strpos( $s, $n ) ) {
				return true;
			}
		}
		return false;
	}

	private function any( $s, $depth ) {
		if ( $depth < 16 && self::looks_serialized( $s ) ) {
			$p   = 0;
			$out = $this->walk( $s, $p, $depth );
			if ( null !== $out && strlen( $s ) === $p ) {
				return $out;
			}
		}
		return strtr( $s, $this->map );
	}

	public static function looks_serialized( $s ) {
		return isset( $s[3] ) && (bool) preg_match( '/^(?:N;$|b:[01];$|i:-?\d+;$|d:[^;]+;$|s:\d+:"|a:\d+:\{|O:\d+:"|C:\d+:"|E:\d+:")/', $s );
	}

	/** Returns the rewritten token at $p (advancing $p), or null when the input is not valid. */
	private function walk( $s, &$p, $depth ) {
		if ( $depth > 512 || ! isset( $s[ $p ] ) ) {
			return null;
		}
		switch ( $s[ $p ] ) {
			case 'N':
				if ( 'N;' !== substr( $s, $p, 2 ) ) {
					return null;
				}
				$p += 2;
				return 'N;';

			case 'b':
			case 'i':
			case 'd':
			case 'r':
			case 'R':
				if ( ! isset( $s[ $p + 1 ] ) || ':' !== $s[ $p + 1 ] ) {
					return null;
				}
				$e = strpos( $s, ';', $p );
				if ( false === $e ) {
					return null;
				}
				$tok = substr( $s, $p, $e - $p + 1 );
				$p   = $e + 1;
				return $tok;

			case 's':
			case 'E':
				$t = $s[ $p ];
				if ( ! preg_match( '/\G' . $t . ':(\d+):"/', $s, $m, 0, $p ) ) {
					return null;
				}
				$len   = (int) $m[1];
				$start = $p + strlen( $m[0] );
				if ( '";' !== substr( $s, $start + $len, 2 ) ) {
					return null;
				}
				$str = substr( $s, $start, $len );
				$p   = $start + $len + 2;
				if ( 'E' === $t ) {
					return 'E:' . $len . ':"' . $str . '";';
				}
				$new = $this->has_needle( $str ) ? $this->any( $str, $depth + 1 ) : $str;
				return 's:' . strlen( $new ) . ':"' . $new . '";';

			case 'a':
				if ( ! preg_match( '/\Ga:(\d+):\{/', $s, $m, 0, $p ) ) {
					return null;
				}
				$p  += strlen( $m[0] );
				$out = $m[0];
				return $this->members( $s, $p, (int) $m[1], $out, $depth );

			case 'O':
				if ( ! preg_match( '/\GO:(\d+):"/', $s, $m, 0, $p ) ) {
					return null;
				}
				$cstart = $p + strlen( $m[0] );
				$q      = $cstart + (int) $m[1];
				if ( '":' !== substr( $s, $q, 2 ) || ! preg_match( '/\G(\d+):\{/', $s, $m2, 0, $q + 2 ) ) {
					return null;
				}
				$out = substr( $s, $p, $q + 2 - $p ) . $m2[0];
				$p   = $q + 2 + strlen( $m2[0] );
				return $this->members( $s, $p, (int) $m2[1], $out, $depth );

			case 'C':
				// Custom serialized payload: opaque, copy byte-for-byte.
				if ( ! preg_match( '/\GC:(\d+):"/', $s, $m, 0, $p ) ) {
					return null;
				}
				$q = $p + strlen( $m[0] ) + (int) $m[1];
				if ( '":' !== substr( $s, $q, 2 ) || ! preg_match( '/\G(\d+):\{/', $s, $m2, 0, $q + 2 ) ) {
					return null;
				}
				$end = $q + 2 + strlen( $m2[0] ) + (int) $m2[1];
				if ( ! isset( $s[ $end ] ) || '}' !== $s[ $end ] ) {
					return null;
				}
				$tok = substr( $s, $p, $end + 1 - $p );
				$p   = $end + 1;
				return $tok;
		}
		return null;
	}

	private function members( $s, &$p, $n, $out, $depth ) {
		for ( $i = 0; $i < $n * 2; $i++ ) {
			$v = $this->walk( $s, $p, $depth + 1 );
			if ( null === $v ) {
				return null;
			}
			$out .= $v;
		}
		if ( ! isset( $s[ $p ] ) || '}' !== $s[ $p ] ) {
			return null;
		}
		$p++;
		return $out . '}';
	}

	/**
	 * Builds the replacement map for a migration: plain, JSON-escaped and
	 * URL-encoded variants, both http/https of the old URL, plus filesystem paths.
	 */
	public static function migration_map( array $old, array $new ) {
		$map  = array();
		$urls = array(
			array( $old['home'] ?? '', $new['home'] ?? '' ),
			array( $old['siteurl'] ?? '', $new['siteurl'] ?? '' ),
			array( $old['uploads_url'] ?? '', $new['uploads_url'] ?? '' ),
			array( $old['content_url'] ?? '', $new['content_url'] ?? '' ),
		);
		foreach ( $urls as $pair ) {
			list( $o, $n ) = array_map( 'untrailingslashit', $pair );
			if ( '' === $o || '' === $n || $o === $n ) {
				continue;
			}
			$bare = preg_replace( '#^https?:#i', '', $o ); // //old.com/path
			foreach ( array( 'http:' . $bare, 'https:' . $bare ) as $variant ) {
				$map[ $variant ]                            = $n;
				$map[ str_replace( '/', '\\/', $variant ) ] = str_replace( '/', '\\/', $n );
				$map[ rawurlencode( $variant ) ]            = rawurlencode( $n );
			}
			$map[ $bare ] = preg_replace( '#^https?:#i', '', $n );
		}
		$paths = array(
			array( $old['abspath'] ?? '', $new['abspath'] ?? '' ),
			array( $old['content_dir'] ?? '', $new['content_dir'] ?? '' ),
			array( $old['uploads_dir'] ?? '', $new['uploads_dir'] ?? '' ),
		);
		foreach ( $paths as $pair ) {
			list( $o, $n ) = array_map( 'untrailingslashit', $pair );
			if ( strlen( $o ) >= 4 && '' !== $n && $o !== $n ) {
				$map[ $o ]                            = $n;
				$map[ str_replace( '/', '\\/', $o ) ] = str_replace( '/', '\\/', $n );
			}
		}
		return $map;
	}
}
