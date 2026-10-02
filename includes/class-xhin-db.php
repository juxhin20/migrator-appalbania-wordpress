<?php
/**
 * Database engine: chunked dump, chunked import into temporary tables,
 * serialization-safe search/replace and an atomic RENAME swap.
 *
 * Every routine is a resumable "slice": it works until $deadline, records its
 * cursor in the state array passed by reference, and can be called again.
 *
 * @package XhinMigration
 */

defined( 'ABSPATH' ) || exit;

// phpcs:disable WordPress.DB.RestrictedFunctions -- Tables are read with unbuffered queries (MYSQLI_USE_RESULT) on
// $wpdb's own mysqli connection, and dumps are imported statement by statement. $wpdb cannot stream results,
// which would exhaust PHP memory on large sites.
// phpcs:disable WordPress.WP.AlternativeFunctions -- Backup archives (often many GB) are streamed with fopen/fread/fwrite,
// fseek, ftruncate and flock, and folders are swapped with rename(). WP_Filesystem offers no streaming, seeking,
// appending or locking. Every path used is inside this site or its own backup folder.

final class AppAlbania_Xhin_DB {

	/** Table names inside a .xhin dump are prefix-neutral: XHIN_PFX_posts, XHIN_PFX_options ... */
	const PFX = 'XHIN_PFX_';

	const STMT_BYTES = 524288; // one INSERT line stays well below any max_allowed_packet.

	public static function link() {
		global $wpdb;
		$link = $wpdb->dbh;
		if ( ! ( $link instanceof mysqli ) ) {
			throw new AppAlbania_Xhin_Exception( esc_html( 'Migration by AppAlbania requires the mysqli database driver.' ) );
		}
		return $link;
	}

	public static function q( $sql ) {
		$r = mysqli_query( self::link(), $sql );
		if ( false === $r ) {
			throw new AppAlbania_Xhin_Exception( esc_html( sprintf( 'MySQL error %d: %s — %s', mysqli_errno( self::link() ), mysqli_error( self::link() ), substr( $sql, 0, 180 ) ) ) );
		}
		return $r;
	}

	/** Streams rows one by one (constant memory, whatever the row sizes). No other query may run until it is freed. */
	public static function q_stream( $sql ) {
		$r = mysqli_query( self::link(), $sql, MYSQLI_USE_RESULT );
		if ( false === $r ) {
			throw new AppAlbania_Xhin_Exception( esc_html( sprintf( 'MySQL error %d: %s — %s', mysqli_errno( self::link() ), mysqli_error( self::link() ), substr( $sql, 0, 180 ) ) ) );
		}
		return $r;
	}

	public static function esc( $v ) {
		return mysqli_real_escape_string( self::link(), (string) $v );
	}

	public static function id( $name ) {
		return '`' . str_replace( '`', '``', $name ) . '`';
	}

	public static function like( $s ) {
		return addcslashes( $s, '_%\\' );
	}

	/** Per-request session setup: utf8mb4, no strict mode (old zero-dates), no FK checks. */
	public static function session() {
		$link = self::link();
		if ( ! @mysqli_set_charset( $link, 'utf8mb4' ) ) {
			@mysqli_set_charset( $link, 'utf8' );
		}
		@mysqli_query( $link, "SET SESSION sql_mode = 'NO_AUTO_VALUE_ON_ZERO'" );
		@mysqli_query( $link, 'SET SESSION foreign_key_checks = 0' );
		@mysqli_query( $link, 'SET SESSION unique_checks = 0' );
	}

	/** Base tables starting with $prefix, minus anything starting with one of $skip. */
	public static function tables( $prefix, array $skip = array() ) {
		$r   = self::q( "SHOW FULL TABLES LIKE '" . self::esc( self::like( $prefix ) ) . "%'" );
		$out = array();
		while ( $row = mysqli_fetch_row( $r ) ) {
			if ( isset( $row[1] ) && 'VIEW' === strtoupper( $row[1] ) ) {
				continue;
			}
			foreach ( $skip as $sp ) {
				if ( '' !== $sp && 0 === strpos( $row[0], $sp ) ) {
					continue 2;
				}
			}
			$out[] = $row[0];
		}
		mysqli_free_result( $r );
		sort( $out, SORT_STRING );
		return $out;
	}

	public static function exists( $table ) {
		$r  = self::q( "SHOW TABLES LIKE '" . self::esc( self::like( $table ) ) . "'" );
		$ok = mysqli_num_rows( $r ) > 0;
		mysqli_free_result( $r );
		return $ok;
	}

	/** @return array name => array(type, generated) */
	public static function columns( $table ) {
		$r   = self::q( 'SHOW COLUMNS FROM ' . self::id( $table ) );
		$out = array();
		while ( $c = mysqli_fetch_assoc( $r ) ) {
			$out[ $c['Field'] ] = array(
				'type'      => strtolower( $c['Type'] ),
				'generated' => (bool) preg_match( '/generated/i', (string) $c['Extra'] ),
			);
		}
		mysqli_free_result( $r );
		return $out;
	}

	/** Primary key columns in index order. */
	public static function pk( $table ) {
		$r   = self::q( "SHOW KEYS FROM " . self::id( $table ) . " WHERE Key_name = 'PRIMARY'" );
		$out = array();
		while ( $k = mysqli_fetch_assoc( $r ) ) {
			$out[ (int) $k['Seq_in_index'] ] = $k['Column_name'];
		}
		mysqli_free_result( $r );
		ksort( $out );
		return array_values( $out );
	}

	/** Single integer PK => fast keyset pagination (WHERE id > last), O(1) per batch at any table size. */
	private static function keyset( array $pk, array $cols ) {
		if ( 1 === count( $pk ) && isset( $cols[ $pk[0] ] ) && preg_match( '/^(tiny|small|medium|big)?int\b/', $cols[ $pk[0] ]['type'] ) ) {
			return $pk[0];
		}
		return null;
	}

	private static function value( $v, $f ) {
		if ( null === $v ) {
			return 'NULL';
		}
		static $numeric = array( 0, 1, 2, 3, 4, 5, 8, 9, 13, 246 );
		static $bytes   = array( 15, 16, 249, 250, 251, 252, 253, 254, 255 );
		if ( in_array( $f->type, $numeric, true ) && is_numeric( $v ) ) {
			return $v;
		}
		if ( 63 === (int) $f->charsetnr && in_array( $f->type, $bytes, true ) ) {
			return '' === $v ? "''" : '0x' . bin2hex( $v ); // binary-safe (BLOB, BINARY, BIT, GEOMETRY).
		}
		return "'" . mysqli_real_escape_string( self::link(), $v ) . "'";
	}

	/* --------------------------------------------------------------- dump */

	/**
	 * @param array    $st       tables, ti, phase, last, offset, rows
	 * @param resource $fh       database.sql opened at its committed size
	 * @return bool              true when every table is dumped
	 */
	public static function dump( array &$st, $fh, $src_prefix, $deadline, $checkpoint = null ) {
		self::session();
		$total = count( $st['tables'] );
		while ( $st['ti'] < $total ) {
			$table  = $st['tables'][ $st['ti'] ];
			$target = self::id( self::PFX . substr( $table, strlen( $src_prefix ) ) );

			if ( 'create' === $st['phase'] ) {
				$r      = self::q( 'SHOW CREATE TABLE ' . self::id( $table ) );
				$row    = mysqli_fetch_row( $r );
				mysqli_free_result( $r );
				$create = preg_replace( '/[\r\n]+\s*/', ' ', $row[1] );
				$create = str_replace( '`' . $src_prefix, '`' . self::PFX, $create );
				AppAlbania_Xhin_Archive::write_all( $fh, "DROP TABLE IF EXISTS {$target};\n{$create};\n" );

				$cols = self::columns( $table );
				$pk   = self::pk( $table );
				$plain = array();
				foreach ( $cols as $name => $c ) {
					if ( ! $c['generated'] ) {
						$plain[] = $name;
					}
				}
				$avg = 0;
				$sr  = self::q( "SHOW TABLE STATUS LIKE '" . self::esc( self::like( $table ) ) . "'" );
				if ( $s = mysqli_fetch_assoc( $sr ) ) {
					$avg = (int) $s['Avg_row_length'];
				}
				mysqli_free_result( $sr );

				$st['phase']  = 'rows';
				$st['cols']   = $plain;
				$st['pk']     = $pk;
				$st['key']    = self::keyset( $pk, $cols );
				// Avg_row_length ignores off-page TEXT/BLOB data, so it is only a first guess; batches adapt to real sizes below.
				$st['batch']  = max( 20, min( 500, (int) floor( 4194304 / max( 1, $avg ) ) ) );
				$st['last']   = null;
				$st['offset'] = 0;
			}

			$sel  = implode( ',', array_map( array( __CLASS__, 'id' ), $st['cols'] ) );
			$head = "INSERT INTO {$target} ({$sel}) VALUES ";
			$kidx = $st['key'] ? array_search( $st['key'], $st['cols'], true ) : false;

			while ( true ) {
				if ( microtime( true ) >= $deadline ) {
					return false;
				}
				if ( $st['key'] ) {
					$where = null === $st['last'] ? '' : ' WHERE ' . self::id( $st['key'] ) . ' > ' . $st['last'];
					$sql   = "SELECT {$sel} FROM " . self::id( $table ) . $where . ' ORDER BY ' . self::id( $st['key'] ) . ' LIMIT ' . (int) $st['batch'];
				} else {
					$order = $st['pk'] ? ' ORDER BY ' . implode( ',', array_map( array( __CLASS__, 'id' ), $st['pk'] ) ) : '';
					$sql   = "SELECT {$sel} FROM " . self::id( $table ) . $order . ' LIMIT ' . (int) $st['offset'] . ', ' . (int) $st['batch'];
				}
				$asked  = (int) $st['batch'];
				$r      = self::q_stream( $sql );
				$fields = mysqli_fetch_fields( $r );
				$n      = 0;
				$bytes  = 0;
				$early  = false;
				$buf    = '';
				while ( $row = mysqli_fetch_row( $r ) ) {
					$vals = array();
					foreach ( $row as $i => $v ) {
						$vals[] = self::value( $v, $fields[ $i ] );
					}
					$tuple = '(' . implode( ',', $vals ) . ')';
					if ( false !== $kidx ) {
						$st['last'] = $row[ $kidx ];
					}
					$row  = null;
					$vals = null;
					if ( '' !== $buf && strlen( $buf ) + strlen( $tuple ) > self::STMT_BYTES ) {
						AppAlbania_Xhin_Archive::write_all( $fh, $head . $buf . ";\n" );
						$buf = '';
					}
					$bytes += strlen( $tuple );
					$buf   .= ( '' === $buf ? '' : ',' ) . $tuple;
					$n++;
					// Big rows or out of time: stop here; the next query continues right after this row.
					if ( $n < $asked && ( $bytes > 8388608 || microtime( true ) >= $deadline ) ) {
						$early = true;
						break;
					}
				}
				mysqli_free_result( $r ); // also discards rows we did not read.
				if ( '' !== $buf ) {
					AppAlbania_Xhin_Archive::write_all( $fh, $head . $buf . ";\n" );
				}
				$st['rows']   += $n;
				$st['offset'] += $n;
				if ( $n > 0 ) { // size the next batch from what rows really weigh (~8 MB per query).
					$st['batch'] = max( 10, min( 5000, (int) floor( 8388608 / max( 1, $bytes / $n ) ) ) );
				}
				if ( $checkpoint ) {
					call_user_func( $checkpoint );
				}
				if ( ! $early && $n < $asked ) {
					break;
				}
			}
			$st['ti']++;
			$st['phase'] = 'create';
		}
		return true;
	}

	/* ------------------------------------------------------------- import */

	private static function retarget( $stmt, $tmp ) {
		static $ins = 'INSERT INTO `' . self::PFX;
		static $drp = 'DROP TABLE IF EXISTS `' . self::PFX;
		if ( 0 === strncmp( $stmt, $ins, strlen( $ins ) ) ) {
			return 'INSERT INTO `' . $tmp . substr( $stmt, strlen( $ins ) );
		}
		if ( 0 === strncmp( $stmt, $drp, strlen( $drp ) ) ) {
			return 'DROP TABLE IF EXISTS `' . $tmp . substr( $stmt, strlen( $drp ) );
		}
		if ( 0 === strncmp( $stmt, 'CREATE TABLE', 12 ) ) {
			$stmt = str_replace( '`' . self::PFX, '`' . $tmp, $stmt );
			// Named FK constraints must be unique per database (MySQL 8): let the server name them.
			return preg_replace( '/CONSTRAINT `[^`]+` FOREIGN KEY/', 'FOREIGN KEY', $stmt );
		}
		return $stmt;
	}

	/** Downgrades collations / engines the destination server does not know. */
	private static function compat( $stmt ) {
		$stmt = preg_replace( '/utf8mb4_(?:0900|uca1400)_\w+/', 'utf8mb4_unicode_520_ci', $stmt );
		$stmt = preg_replace( '/utf8mb3_(?:uca1400_)?\w*_ci/', 'utf8_general_ci', $stmt );
		$stmt = str_replace( 'utf8mb3', 'utf8', $stmt );
		$stmt = preg_replace( '/ENGINE=(?:Aria|TokuDB|RocksDB|MyRocks)/i', 'ENGINE=InnoDB', $stmt );
		$stmt = preg_replace( '/ PAGE_CHECKSUM=\d| TRANSACTIONAL=\d/', '', $stmt );
		return $stmt;
	}

	/**
	 * Replays database.sql into $tmp-prefixed tables (live tables untouched).
	 * $checkpoint is called after each statement so a killed request replays at most one.
	 */
	public static function import( array &$st, $file, $tmp, $deadline, $checkpoint ) {
		self::session();
		$link = self::link();
		$fh   = fopen( $file, 'rb' );
		if ( ! $fh ) {
			throw new AppAlbania_Xhin_Exception( esc_html( 'Cannot read database.sql' ) );
		}
		fseek( $fh, $st['off'] );
		while ( false !== ( $line = fgets( $fh ) ) ) {
			$stmt = rtrim( $line, "\r\n" );
			if ( '' !== $stmt ) {
				$stmt = self::retarget( $stmt, $tmp );
				if ( ! mysqli_query( $link, $stmt ) ) {
					$errno = mysqli_errno( $link );
					$retry = null;
					if ( in_array( $errno, array( 1273, 1115, 1286, 1064 ), true ) ) {
						$retry = self::compat( $stmt );
					} elseif ( 1062 === $errno && 0 === strncmp( $stmt, 'INSERT INTO', 11 ) ) {
						$retry = 'INSERT IGNORE INTO' . substr( $stmt, 11 ); // replay after a killed request.
					} elseif ( 1050 === $errno && preg_match( '/^CREATE TABLE (`[^`]+`)/', $stmt, $cm ) ) {
						// Replay after a kill between CREATE and its checkpoint: no row of it was committed yet.
						mysqli_query( $link, 'DROP TABLE IF EXISTS ' . $cm[1] );
						$retry = $stmt . ' ';
					}
					if ( null === $retry || $retry === $stmt || ! mysqli_query( $link, $retry ) ) {
						throw new AppAlbania_Xhin_Exception( esc_html( sprintf( 'Database import error %d: %s — %s', mysqli_errno( $link ), mysqli_error( $link ), substr( $stmt, 0, 160 ) ) ) );
					}
				}
				$st['stmts']++;
			}
			$st['off'] = ftell( $fh );
			call_user_func( $checkpoint );
			if ( microtime( true ) >= $deadline ) {
				fclose( $fh );
				return false;
			}
		}
		fclose( $fh );
		return true;
	}

	/* ------------------------------------------------------------ replace */

	public static function replace( array &$st, AppAlbania_Xhin_Replace $rep, array $needles, $deadline, $checkpoint = null ) {
		self::session();
		$total = count( $st['tables'] );
		$id    = array( __CLASS__, 'id' );
		while ( $st['ti'] < $total ) {
			$table = $st['tables'][ $st['ti'] ];
			if ( empty( $st['meta'] ) ) {
				$cols = self::columns( $table );
				$text = array();
				foreach ( $cols as $name => $c ) {
					if ( ! $c['generated'] && preg_match( '/char|text|json/', $c['type'] ) && false === strpos( $c['type'], 'binary' ) ) {
						$text[] = $name;
					}
				}
				if ( ! $text ) {
					$st['ti']++;
					continue;
				}
				$avg = 0;
				$sr  = self::q( "SHOW TABLE STATUS LIKE '" . self::esc( self::like( $table ) ) . "'" );
				if ( $row = mysqli_fetch_assoc( $sr ) ) {
					$avg = (int) $row['Avg_row_length'];
				}
				mysqli_free_result( $sr );
				$pk         = self::pk( $table );
				$st['meta'] = array(
					'text'  => $text,
					'pk'    => $pk,
					'key'   => self::keyset( $pk, $cols ),
					'all'   => array_keys( array_filter( $cols, function ( $c ) { return ! $c['generated']; } ) ),
					// First guess only (Avg_row_length ignores off-page TEXT); adapted to real sizes after each read.
					'batch' => max( 10, min( 200, (int) floor( 2097152 / max( 1, $avg ) ) ) ),
				);
				$st['last']   = null;
				$st['offset'] = 0;
			}
			$m     = $st['meta'];
			$batch = (int) ( $m['batch'] ?? 500 );

			while ( true ) {
				if ( microtime( true ) >= $deadline ) {
					return false;
				}
				if ( $m['key'] ) {
					$filter = array();
					foreach ( $m['text'] as $c ) {
						foreach ( $needles as $n ) {
							$filter[] = self::id( $c ) . " LIKE '%" . self::esc( self::like( $n ) ) . "%'";
						}
					}
					$where = ( null === $st['last'] ? '' : self::id( $m['key'] ) . ' > ' . $st['last'] . ' AND ' ) . '(' . implode( ' OR ', $filter ) . ')';
					$cols  = array_merge( array( $m['key'] ), $m['text'] );
					$sql   = 'SELECT ' . implode( ',', array_map( $id, $cols ) ) . ' FROM ' . self::id( $table ) . ' WHERE ' . $where . ' ORDER BY ' . self::id( $m['key'] ) . " LIMIT {$batch}";
				} elseif ( $m['pk'] ) {
					$cols = array_values( array_unique( array_merge( $m['pk'], $m['text'] ) ) );
					$sql  = 'SELECT ' . implode( ',', array_map( $id, $cols ) ) . ' FROM ' . self::id( $table ) . ' ORDER BY ' . implode( ',', array_map( $id, $m['pk'] ) ) . ' LIMIT ' . (int) $st['offset'] . ", {$batch}";
				} else {
					$cols = $m['all'];
					$sql  = 'SELECT ' . implode( ',', array_map( $id, $cols ) ) . ' FROM ' . self::id( $table ) . ' LIMIT ' . (int) $st['offset'] . ", {$batch}";
				}
				// Streamed and capped at ~4 MB of text, so huge rows can never exhaust PHP memory.
				$r      = self::q_stream( $sql );
				$rows   = array();
				$got    = 0;
				$capped = false;
				while ( $row = mysqli_fetch_assoc( $r ) ) {
					$rows[] = $row;
					foreach ( $m['text'] as $c ) {
						$got += strlen( (string) $row[ $c ] );
					}
					if ( $got > 4194304 && count( $rows ) < $batch ) {
						$capped = true;
						break;
					}
				}
				mysqli_free_result( $r );
				$asked = $batch;
				if ( $rows ) {
					$batch                = max( 10, min( 1000, (int) floor( 4194304 / max( 1, $got / count( $rows ) ) ) ) );
					$st['meta']['batch'] = $batch;
				}

				$tx    = false;
				$cases = array(); // integer-PK tables: column => array( id => new value )
				$bytes = 0;
				$flush = function () use ( &$cases, &$bytes, &$tx, $table, $m ) {
					if ( $cases ) {
						self::case_update( $table, $m['key'], $cases );
					}
					if ( $tx ) {
						self::q( 'COMMIT' );
					}
					$cases = array();
					$bytes = 0;
					$tx    = false;
				};
				$done = 0;
				$stop = false;
				$tick = microtime( true );
				foreach ( $rows as $row ) {
					$set = array();
					foreach ( $m['text'] as $c ) {
						$new = $rep->run( $row[ $c ] );
						if ( $new !== $row[ $c ] ) {
							$set[ $c ] = $new;
						}
					}
					if ( $set ) {
						$st['updated']++;
						if ( $m['key'] ) {
							foreach ( $set as $c => $v ) {
								$cases[ $c ][ $row[ $m['key'] ] ] = $v;
								$bytes += strlen( $v );
							}
							if ( $bytes > self::update_budget() ) { // keep statements far below max_allowed_packet.
								$flush();
							}
						} else {
							$match = array();
							foreach ( ( $m['pk'] ? $m['pk'] : $m['all'] ) as $c ) {
								$match[] = self::id( $c ) . ' <=> ' . ( null === $row[ $c ] ? 'NULL' : "'" . self::esc( $row[ $c ] ) . "'" );
							}
							$sets = array();
							foreach ( $set as $c => $v ) {
								$sets[] = self::id( $c ) . " = '" . self::esc( $v ) . "'";
							}
							if ( ! $tx ) { // one commit per batch instead of one fsync per row.
								self::q( 'START TRANSACTION' );
								$tx = true;
							}
							self::q( 'UPDATE ' . self::id( $table ) . ' SET ' . implode( ', ', $sets ) . ' WHERE ' . implode( ' AND ', $match ) . ' LIMIT 1' );
						}
					}
					if ( $m['key'] ) {
						$st['last'] = $row[ $m['key'] ];
					}
					$done++;
					// Out of time mid-batch: write what is done, remember exactly where we are.
					if ( microtime( true ) >= $deadline ) {
						$stop = true;
						break;
					}
					if ( $checkpoint && microtime( true ) - $tick > 0.7 ) { // commit progress even mid-batch.
						$flush();
						$st['offset'] += $done;
						$done          = 0;
						call_user_func( $checkpoint );
						$tick = microtime( true );
					}
				}
				$flush();
				$st['offset'] += $done;
				if ( $checkpoint ) {
					call_user_func( $checkpoint );
				}
				if ( $stop ) {
					return false;
				}
				if ( ! $capped && count( $rows ) < $asked ) {
					break;
				}
			}
			$st['ti']++;
			$st['meta'] = null;
		}
		return true;
	}

	/** Bytes of new values per batched UPDATE: a third of the server's max_allowed_packet, at most 2 MB (old hosts use 1 MB). */
	private static function update_budget() {
		static $b = null;
		if ( null === $b ) {
			$p = 0;
			try {
				$r = self::q( 'SELECT @@max_allowed_packet' );
				$row = mysqli_fetch_row( $r );
				mysqli_free_result( $r );
				$p = (int) ( $row[0] ?? 0 );
			} catch ( Throwable $e ) { // phpcs:ignore
			}
			$b = $p > 0 ? max( 65536, min( 2097152, (int) floor( $p / 3 ) ) ) : 1048576;
		}
		return $b;
	}

	/** One UPDATE per batch: SET col = CASE id WHEN 1 THEN '..' ... ELSE col END WHERE id IN (...). */
	private static function case_update( $table, $key, array $cases ) {
		$ids  = array();
		$sets = array();
		foreach ( $cases as $col => $vals ) {
			$when = '';
			foreach ( $vals as $id => $v ) {
				$id = (string) $id; // keep as string: BIGINT UNSIGNED can exceed PHP_INT_MAX.
				if ( ! preg_match( '/^-?\d+$/', $id ) ) {
					throw new AppAlbania_Xhin_Exception( esc_html( 'Unexpected key value in ' . $table ) );
				}
				$when      .= ' WHEN ' . $id . " THEN '" . self::esc( $v ) . "'";
				$ids[ $id ] = $id;
			}
			$sets[] = self::id( $col ) . ' = CASE ' . self::id( $key ) . $when . ' ELSE ' . self::id( $col ) . ' END';
		}
		self::q( 'UPDATE ' . self::id( $table ) . ' SET ' . implode( ', ', $sets ) . ' WHERE ' . self::id( $key ) . ' IN (' . implode( ',', $ids ) . ')' );
	}

	/* -------------------------------------------------------- prepare/swap */

	/** Fixes prefix-bound keys, pins URLs to the destination, keeps Xhin active. Runs on temp tables. */
	public static function prepare( $tmp, $src_prefix, $dst_prefix, array $dest ) {
		self::session();
		$opt = $tmp . 'options';
		$um  = $tmp . 'usermeta';
		if ( self::exists( $opt ) ) {
			if ( $src_prefix !== $dst_prefix ) {
				self::q( 'DELETE FROM ' . self::id( $opt ) . " WHERE option_name = '" . self::esc( $dst_prefix . 'user_roles' ) . "' AND EXISTS (SELECT 1 FROM (SELECT option_name FROM " . self::id( $opt ) . " WHERE option_name = '" . self::esc( $src_prefix . 'user_roles' ) . "') x)" );
				self::q( 'UPDATE ' . self::id( $opt ) . " SET option_name = '" . self::esc( $dst_prefix . 'user_roles' ) . "' WHERE option_name = '" . self::esc( $src_prefix . 'user_roles' ) . "'" );
			}
			foreach ( array( 'home', 'siteurl' ) as $k ) {
				if ( ! empty( $dest[ $k ] ) ) {
					self::q( 'UPDATE ' . self::id( $opt ) . " SET option_value = '" . self::esc( $dest[ $k ] ) . "' WHERE option_name = '{$k}'" );
				}
			}
			self::q( 'DELETE FROM ' . self::id( $opt ) . " WHERE option_name LIKE '\\_transient\\_%' OR option_name LIKE '\\_site\\_transient\\_%' OR option_name = 'rewrite_rules'" );

			$r   = self::q( 'SELECT option_value FROM ' . self::id( $opt ) . " WHERE option_name = 'active_plugins' LIMIT 1" );
			$row = mysqli_fetch_row( $r );
			mysqli_free_result( $r );
			$active = $row ? @unserialize( $row[0], array( 'allowed_classes' => false ) ) : array();
			$active = is_array( $active ) ? array_values( $active ) : array();
			$before = $active;
			// Exactly one copy of this plugin stays active: the one running this restore.
			// (An older copy in another folder, e.g. "xhin-migration-1.0", would load first.)
			$active = array_values( array_filter( $active, function ( $p ) { return ! is_string( $p ) || 'xhin-migration.php' !== basename( $p ) || APPALBANIA_XHIN_BASENAME === $p; } ) );
			if ( ! in_array( APPALBANIA_XHIN_BASENAME, $active, true ) ) {
				$active[] = APPALBANIA_XHIN_BASENAME;
			}
			if ( $active !== $before ) {
				sort( $active );
				if ( $row ) {
					self::q( 'UPDATE ' . self::id( $opt ) . " SET option_value = '" . self::esc( serialize( $active ) ) . "' WHERE option_name = 'active_plugins'" );
				} else {
					self::q( 'INSERT INTO ' . self::id( $opt ) . " (option_name, option_value, autoload) VALUES ('active_plugins', '" . self::esc( serialize( $active ) ) . "', 'yes')" );
				}
			}
		}
		if ( $src_prefix !== $dst_prefix && self::exists( $um ) ) {
			self::q( 'UPDATE ' . self::id( $um ) . " SET meta_key = CONCAT('" . self::esc( $dst_prefix ) . "', SUBSTRING(meta_key, " . ( strlen( $src_prefix ) + 1 ) . ")) WHERE meta_key LIKE '" . self::esc( self::like( $src_prefix ) ) . "%' AND meta_key NOT LIKE '" . self::esc( self::like( $dst_prefix ) ) . "%'" ); // idempotent on re-run.
		}
	}

	/** One atomic RENAME TABLE: live -> old, temp -> live. Then drop old. */
	public static function swap( $tmp, $dst, $old ) {
		self::session();
		$new = self::tables( $tmp );
		if ( ! $new ) {
			throw new AppAlbania_Xhin_Exception( esc_html( 'No imported tables found to activate.' ) );
		}
		$live  = self::tables( $dst, array( $tmp, $old ) );
		$pairs = array();
		$long  = false;
		foreach ( $live as $t ) {
			$o = $old . substr( $t, strlen( $dst ) );
			if ( strlen( $o ) > 64 ) {
				$long = true;
			}
			$pairs[] = self::id( $t ) . ' TO ' . self::id( $o );
		}
		if ( $long ) { // cannot park old tables under a longer name: drop them right before the swap.
			foreach ( $live as $t ) {
				self::q( 'DROP TABLE IF EXISTS ' . self::id( $t ) );
			}
			$pairs = array();
		}
		foreach ( $new as $t ) {
			$pairs[] = self::id( $t ) . ' TO ' . self::id( $dst . substr( $t, strlen( $tmp ) ) );
		}
		self::q( 'RENAME TABLE ' . implode( ', ', $pairs ) );
		foreach ( self::tables( $old ) as $t ) {
			self::q( 'DROP TABLE IF EXISTS ' . self::id( $t ) );
		}
	}

	public static function drop_prefixed( $prefix ) {
		if ( strlen( $prefix ) < 3 ) {
			return;
		}
		self::session();
		foreach ( self::tables( $prefix ) as $t ) {
			self::q( 'DROP TABLE IF EXISTS ' . self::id( $t ) );
		}
	}
}
