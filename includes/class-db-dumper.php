<?php
/**
 * Volcado SQL por pasos.
 *
 * Una sentencia por línea (los valores se escapan, así que no hay saltos de
 * línea crudos): el instalador puede importar leyendo línea a línea y retomar
 * por posición de byte. Divide la salida en partes de 32 MB.
 *
 * @package Kombi_Backup
 */

defined( 'ABSPATH' ) || exit;

class Kombi_Backup_Db_Dumper {

	const ROWS     = 500;
	const LINE_MAX = 1048576;
	const PART_MAX = 33554432;

	private $s;
	private $pattern;
	private $fh;
	private $dbh;

	/** Estado inicial: solo las tablas con el prefijo de este WordPress. */
	public static function init_state() {
		global $wpdb;
		$tables = array();
		$views  = array();
		foreach ( (array) $wpdb->get_results( 'SHOW FULL TABLES', ARRAY_N ) as $row ) {
			if ( 0 !== strpos( $row[0], $wpdb->prefix ) ) {
				continue;
			}
			if ( 'VIEW' === strtoupper( $row[1] ) ) {
				$views[] = $row[0];
			} else {
				$tables[] = $row[0];
			}
		}
		return array(
			'tables'    => $tables,
			'views'     => $views,
			't'         => 0,
			'table'     => null,
			'part'      => 1,
			'part_size' => 0,
			'rows'      => 0,
			'done'      => false,
		);
	}

	/**
	 * @param array  $state   Estado guardado (se modifica).
	 * @param string $pattern Ruta sprintf de las partes, p. ej. ".../kb-x-db-%03d.sql".
	 */
	public function __construct( array $state, $pattern ) {
		global $wpdb;
		$this->s       = $state;
		$this->pattern = $pattern;
		$this->dbh     = $wpdb->dbh;
		if ( ! $this->dbh instanceof mysqli ) {
			throw new RuntimeException( 'Se necesita la extensión mysqli.' );
		}
	}

	public function state() {
		return $this->s;
	}

	/** Avanza hasta $deadline. Devuelve true cuando terminó todas las tablas. */
	public function run( $deadline ) {
		$this->open_part();
		if ( 1 === $this->s['part'] && 0 === $this->s['part_size'] ) {
			$this->line( '-- Kombi Backup ' . KOMBI_BACKUP_VERSION . ' — ' . gmdate( 'c' ) );
			$this->line( 'SET NAMES utf8mb4;' );
			$this->line( 'SET foreign_key_checks = 0;' );
			$this->line( "SET sql_mode = 'NO_AUTO_VALUE_ON_ZERO';" );
		}
		while ( ! $this->s['done'] && microtime( true ) < $deadline ) {
			if ( $this->s['t'] < count( $this->s['tables'] ) ) {
				if ( null === $this->s['table'] ) {
					$this->s['table'] = $this->table_info( $this->s['tables'][ $this->s['t'] ] );
					$q                = self::q( $this->s['table']['name'] );
					$this->line( "DROP TABLE IF EXISTS $q;" );
					$this->line( $this->create_sql( $this->s['table']['name'] ) . ';' );
				}
				if ( ! $this->rows() ) {
					$this->s['table'] = null;
					++$this->s['t'];
				}
			} else {
				foreach ( $this->s['views'] as $view ) {
					$row = mysqli_fetch_row( $this->query( 'SHOW CREATE VIEW ' . self::q( $view ) ) );
					$sql = preg_replace( '/\sDEFINER=`[^`]*`@`[^`]*`/', '', $row[1] );
					$this->line( 'DROP VIEW IF EXISTS ' . self::q( $view ) . ';' );
					$this->line( self::one_line( $sql ) . ';' );
				}
				$this->s['done'] = true;
			}
		}
		fclose( $this->fh );
		return $this->s['done'];
	}

	private function table_info( $name ) {
		$cols = array();
		$bin  = array();
		$res  = $this->query( 'SHOW COLUMNS FROM ' . self::q( $name ) );
		while ( $c = mysqli_fetch_assoc( $res ) ) {
			$cols[ $c['Field'] ] = $c['Type'];
			$bin[]               = (bool) preg_match( '/blob|binary|bit/i', $c['Type'] );
		}
		$pk  = array();
		$res = $this->query( 'SHOW KEYS FROM ' . self::q( $name ) . " WHERE Key_name = 'PRIMARY'" );
		while ( $k = mysqli_fetch_assoc( $res ) ) {
			$pk[] = $k['Column_name'];
		}
		// Cursor por PK entera única (rápido); si no, LIMIT/OFFSET.
		$use_pk = 1 === count( $pk ) && preg_match( '/int/i', $cols[ $pk[0] ] );
		return array(
			'name'   => $name,
			'cols'   => array_keys( $cols ),
			'bin'    => $bin,
			'pk'     => $use_pk ? $pk[0] : null,
			'pk_i'   => $use_pk ? array_search( $pk[0], array_keys( $cols ), true ) : null,
			'cursor' => null,
			'offset' => 0,
		);
	}

	private function create_sql( $name ) {
		$row = mysqli_fetch_row( $this->query( 'SHOW CREATE TABLE ' . self::q( $name ) ) );
		return self::one_line( $row[1] );
	}

	/** Vuelca un bloque de filas. Devuelve true si quedan más. */
	private function rows() {
		$t   = &$this->s['table'];
		$tq  = self::q( $t['name'] );
		$sql = "SELECT * FROM $tq";
		if ( $t['pk'] ) {
			$pk = self::q( $t['pk'] );
			if ( null !== $t['cursor'] ) {
				$sql .= " WHERE $pk > " . (int) $t['cursor'];
			}
			$sql .= " ORDER BY $pk LIMIT " . self::ROWS;
		} else {
			$sql .= ' LIMIT ' . (int) $t['offset'] . ', ' . self::ROWS;
		}

		$head = "INSERT INTO $tq (" . implode( ',', array_map( array( __CLASS__, 'q' ), $t['cols'] ) ) . ') VALUES ';
		$buf  = '';
		$n    = 0;
		$res  = mysqli_query( $this->dbh, $sql, MYSQLI_USE_RESULT ); // Sin búfer: no carga el bloque entero en memoria.
		if ( ! $res ) {
			throw new RuntimeException( 'Error leyendo ' . $t['name'] . ': ' . mysqli_error( $this->dbh ) );
		}
		while ( $row = mysqli_fetch_row( $res ) ) {
			$vals = array();
			foreach ( $row as $i => $v ) {
				if ( null === $v ) {
					$vals[] = 'NULL';
				} elseif ( $t['bin'][ $i ] ) {
					$vals[] = '' === $v ? "''" : '0x' . bin2hex( $v );
				} else {
					$vals[] = "'" . mysqli_real_escape_string( $this->dbh, $v ) . "'";
				}
			}
			$tuple = '(' . implode( ',', $vals ) . ')';
			if ( '' !== $buf && strlen( $buf ) + strlen( $tuple ) > self::LINE_MAX ) {
				$this->line( $head . $buf . ';' );
				$buf = '';
			}
			$buf .= ( '' === $buf ? '' : ',' ) . $tuple;
			++$n;
			if ( $t['pk'] ) {
				$t['cursor'] = $row[ $t['pk_i'] ];
			}
		}
		mysqli_free_result( $res );
		if ( '' !== $buf ) {
			$this->line( $head . $buf . ';' );
		}
		$t['offset']     += $n;
		$this->s['rows'] += $n;
		return self::ROWS === $n;
	}

	private function query( $sql ) {
		$res = mysqli_query( $this->dbh, $sql );
		if ( ! $res ) {
			throw new RuntimeException( 'Error de base de datos: ' . mysqli_error( $this->dbh ) );
		}
		return $res;
	}

	private function open_part() {
		$this->fh = @fopen( sprintf( $this->pattern, $this->s['part'] ), 'c+b' );
		if ( ! $this->fh ) {
			throw new RuntimeException( 'No se pudo escribir el volcado de la base de datos.' );
		}
		// Descarta lo escrito tras el último estado guardado (paso interrumpido).
		ftruncate( $this->fh, $this->s['part_size'] );
		fseek( $this->fh, $this->s['part_size'] );
	}

	private function line( $sql ) {
		$sql .= "\n";
		if ( $this->s['part_size'] > 0 && $this->s['part_size'] + strlen( $sql ) > self::PART_MAX ) {
			fclose( $this->fh );
			++$this->s['part'];
			$this->s['part_size'] = 0;
			$this->open_part();
		}
		if ( fwrite( $this->fh, $sql ) !== strlen( $sql ) ) {
			throw new RuntimeException( 'No se pudo escribir el volcado (¿disco lleno?).' );
		}
		$this->s['part_size'] += strlen( $sql );
	}

	private static function one_line( $sql ) {
		return str_replace( array( "\r\n", "\n", "\r" ), ' ', $sql );
	}

	public static function q( $name ) {
		return '`' . str_replace( '`', '``', $name ) . '`';
	}
}
