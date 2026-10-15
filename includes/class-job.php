<?php
/**
 * Un respaldo: base de datos → lista de archivos → ZIP, en pasos cortos.
 *
 * El estado vive en {id}.meta.php dentro de wp-content/kombi-backups/ y se
 * guarda junto con los tamaños de los archivos temporales, así un paso que
 * muere por timeout se retoma desde el último punto consistente.
 *
 * @package Kombi_Backup
 */

defined( 'ABSPATH' ) || exit;

class Kombi_Backup_Job {

	const ID_RE       = '/^kb-\d{8}-\d{6}-[a-f0-9]{10}$/';
	const SKIP_NAMES  = array( '.git', '.svn', 'node_modules' );
	const ALWAYS_SKIP = array( 'kombi-installer.php', 'kombi-restore.php', 'kombi-installer-tmp', '.maintenance' );
	const SAVE_EVERY  = 2;

	public $id;
	public $meta = array();
	private $lock;

	private function __construct( $id ) {
		$this->id = $id;
	}

	/* ---------------------------------------------------------------------
	 * Ciclo de vida
	 * ------------------------------------------------------------------ */

	public static function create() {
		if ( is_multisite() ) {
			throw new RuntimeException( 'Multisitio todavía no está soportado.' );
		}
		if ( ! function_exists( 'deflate_init' ) ) {
			throw new RuntimeException( 'PHP no tiene zlib; no se puede comprimir.' );
		}
		$job       = new self( 'kb-' . wp_date( 'Ymd-His' ) . '-' . bin2hex( random_bytes( 5 ) ) );
		$job->meta = array(
			'id'       => $job->id,
			'origin'   => 'local',
			'status'   => 'running',
			'stage'    => 'db',
			'created'  => time(),
			'key'      => self::random_key(),
			'home'     => home_url(),
			'wp'       => get_bloginfo( 'version' ),
			'excludes' => self::excludes(),
			'size'     => 0,
			'files'    => 0,
			'bytes'    => 0,
			'done_files' => 0,
			'done_bytes' => 0,
			'message'  => '',
			'log'      => array(),
			'db'       => Kombi_Backup_Db_Dumper::init_state(),
			'scan'     => array( 'stack' => array( '' ), 'list_size' => 0 ),
			'zip'      => array( 'list_offset' => 0, 'zip_size' => 0, 'cd_size' => 0, 'count' => 0, 'header' => false, 'db_next' => 1 ),
		);
		$job->save();
		return $job;
	}

	public static function load( $id ) {
		if ( ! is_string( $id ) || ! preg_match( self::ID_RE, $id ) ) {
			return null;
		}
		$job  = new self( $id );
		$meta = kombi_backup_read_php_json( $job->path( '.meta.php' ) );
		if ( ! $meta ) {
			return null;
		}
		$job->meta = $meta;
		return $job;
	}

	/** Todos los respaldos, el más nuevo primero. */
	public static function all() {
		$jobs = array();
		foreach ( (array) glob( kombi_backup_storage_dir() . '/kb-*.meta.php' ) as $file ) {
			$job = self::load( basename( $file, '.meta.php' ) );
			if ( $job ) {
				$jobs[] = $job;
			}
		}
		usort(
			$jobs,
			function ( $a, $b ) {
				return $b->meta['created'] - $a->meta['created'];
			}
		);
		return $jobs;
	}

	/** Registra un .zip subido desde otro sitio (para restaurarlo aquí). */
	public static function from_upload( $file ) {
		$manifest = self::read_manifest( $file );
		if ( ! $manifest ) {
			@unlink( $file );
			throw new RuntimeException( 'El archivo no es un respaldo de Kombi Backup.' );
		}
		$job = new self( 'kb-' . wp_date( 'Ymd-His' ) . '-' . bin2hex( random_bytes( 5 ) ) );
		if ( ! @rename( $file, $job->zip_path() ) ) {
			throw new RuntimeException( 'No se pudo guardar el archivo subido.' );
		}
		$job->meta = array(
			'id'      => $job->id,
			'origin'  => 'upload',
			'status'  => 'done',
			'stage'   => 'done',
			'created' => time(),
			'source_created' => isset( $manifest['created'] ) ? $manifest['created'] : '',
			'key'     => '',
			'home'    => isset( $manifest['home'] ) ? $manifest['home'] : '',
			'wp'      => isset( $manifest['wp_version'] ) ? $manifest['wp_version'] : '',
			'size'    => filesize( $job->zip_path() ),
			'files'   => isset( $manifest['files'] ) ? $manifest['files'] : 0,
			'message' => '',
			'log'     => array(),
		);
		$job->save();
		return $job;
	}

	/**
	 * Lee el manifiesto: debe ser la primera entrada del ZIP. Sirve para
	 * validar subidas sin depender de ZipArchive.
	 */
	public static function read_manifest( $file ) {
		$fh = @fopen( $file, 'rb' );
		if ( ! $fh ) {
			return null;
		}
		$h = fread( $fh, 30 );
		if ( strlen( $h ) < 30 ) {
			fclose( $fh );
			return null;
		}
		$h    = unpack( 'Vsig/vver/vflags/vmethod/vtime/vdate/Vcrc/Vcomp/Vsize/vnlen/velen', $h );
		$name = fread( $fh, $h['nlen'] );
		if ( 0x04034b50 !== $h['sig'] || 'kombi-backup/manifest.json' !== $name || $h['comp'] > 1048576 ) {
			fclose( $fh );
			return null;
		}
		fseek( $fh, $h['elen'], SEEK_CUR );
		$data = fread( $fh, $h['comp'] );
		fclose( $fh );
		$json = 8 === $h['method'] ? @gzinflate( $data ) : $data;
		$m    = json_decode( (string) $json, true );
		return is_array( $m ) && isset( $m['format'] ) ? $m : null;
	}

	public function path( $suffix ) {
		return kombi_backup_storage_dir() . '/' . $this->id . $suffix;
	}

	public function zip_path() {
		return $this->path( '.zip' );
	}

	public function save() {
		kombi_backup_write_php_json( $this->path( '.meta.php' ), $this->meta );
	}

	public function delete() {
		foreach ( (array) glob( kombi_backup_storage_dir() . '/' . $this->id . '*' ) as $file ) {
			@unlink( $file );
		}
	}

	/** Nombre de descarga legible: backup-dominio-fecha.zip */
	public function download_name() {
		$host = wp_parse_url( $this->meta['home'], PHP_URL_HOST );
		return sanitize_file_name( 'backup-' . ( $host ? $host : 'wordpress' ) . '-' . wp_date( 'Y-m-d-Hi', $this->meta['created'] ) . '.zip' );
	}

	/* ---------------------------------------------------------------------
	 * Ejecución por pasos
	 * ------------------------------------------------------------------ */

	/** Avanza el respaldo durante un paso. Devuelve el progreso. */
	public function run( $budget = 10 ) {
		if ( 'running' !== $this->meta['status'] ) {
			return $this->progress();
		}
		$this->lock = @fopen( $this->path( '.lock' ), 'c' );
		if ( ! $this->lock || ! flock( $this->lock, LOCK_EX | LOCK_NB ) ) {
			return $this->progress() + array( 'busy' => true );
		}
		@set_time_limit( max( 60, $budget * 4 ) );
		wp_raise_memory_limit( 'admin' );
		$deadline = microtime( true ) + $budget;
		try {
			if ( 'db' === $this->meta['stage'] ) {
				$this->stage_db( $deadline );
			}
			if ( 'scan' === $this->meta['stage'] && microtime( true ) < $deadline ) {
				$this->stage_scan( $deadline );
			}
			if ( 'zip' === $this->meta['stage'] && microtime( true ) < $deadline ) {
				$this->stage_zip( $deadline );
			}
		} catch ( Throwable $e ) {
			$this->meta['status']  = 'error';
			$this->meta['message'] = $e->getMessage();
			$this->cleanup_temp();
		}
		$this->save();
		flock( $this->lock, LOCK_UN );
		fclose( $this->lock );
		@unlink( $this->path( '.lock' ) );
		return $this->progress();
	}

	public function progress() {
		$m     = $this->meta;
		$pct   = 0;
		$label = '';
		switch ( 'running' === $m['status'] ? $m['stage'] : 'done' ) {
			case 'db':
				$total = max( 1, count( $m['db']['tables'] ) );
				$pct   = 15 * $m['db']['t'] / $total;
				$label = sprintf( 'Base de datos: tabla %d de %d (%s filas)', min( $m['db']['t'] + 1, $total ), $total, number_format_i18n( $m['db']['rows'] ) );
				break;
			case 'scan':
				$pct   = 17;
				$label = sprintf( 'Buscando archivos: %s encontrados', number_format_i18n( $m['files'] ) );
				break;
			case 'zip':
				$pct   = 20 + 80 * ( $m['bytes'] ? $m['done_bytes'] / $m['bytes'] : 1 );
				$label = sprintf( 'Comprimiendo: %s de %s archivos (%s de %s)', number_format_i18n( $m['done_files'] ), number_format_i18n( $m['files'] ), size_format( $m['done_bytes'] ), size_format( $m['bytes'] ) );
				break;
			default:
				$pct   = 100;
				$label = 'error' === $m['status'] ? 'Error: ' . $m['message'] : sprintf( 'Listo: %s', size_format( $m['size'] ) );
		}
		return array(
			'id'      => $this->id,
			'status'  => $m['status'],
			'done'    => 'running' !== $m['status'],
			'percent' => round( min( 100, $pct ), 1 ),
			'label'   => $label,
			'key'     => $m['key'],
			'log'     => array_slice( $m['log'], -20 ),
			'message' => $m['message'],
		);
	}

	private function stage_db( $deadline ) {
		$dumper = new Kombi_Backup_Db_Dumper( $this->meta['db'], $this->path( '-db-%03d.sql' ) );
		$done   = $dumper->run( $deadline );
		$this->meta['db'] = $dumper->state();
		if ( $done ) {
			$this->meta['stage'] = 'scan';
		}
	}

	/** Recorre ABSPATH y escribe la lista de archivos ("F\truta" / "D\truta"). */
	private function stage_scan( $deadline ) {
		$s    = &$this->meta['scan'];
		$list = @fopen( $this->path( '.list' ), 'c+b' );
		if ( ! $list ) {
			throw new RuntimeException( 'No se pudo crear la lista de archivos.' );
		}
		ftruncate( $list, $s['list_size'] );
		fseek( $list, $s['list_size'] );

		$root     = self::root();
		$excludes = array_merge( $this->meta['excludes'], self::ALWAYS_SKIP, array( self::storage_rel() ) );
		$last     = microtime( true );
		while ( $s['stack'] && microtime( true ) < $deadline ) {
			$rel   = array_pop( $s['stack'] );
			$items = @scandir( $root . $rel );
			if ( false === $items ) {
				$this->log( 'No se pudo leer la carpeta: ' . $rel );
				continue;
			}
			if ( '' !== $rel ) {
				fwrite( $list, "D\t$rel\n" );
			}
			foreach ( $items as $name ) {
				if ( '.' === $name || '..' === $name ) {
					continue;
				}
				$r = '' === $rel ? $name : "$rel/$name";
				$a = $root . $r;
				if ( in_array( $name, self::SKIP_NAMES, true ) || in_array( $r, $excludes, true ) ) {
					continue;
				}
				if ( false !== strpbrk( $name, "\n\r\t" ) ) {
					$this->log( 'Nombre de archivo no soportado, omitido: ' . $r );
					continue;
				}
				if ( is_link( $a ) ) {
					$this->log( 'Enlace simbólico omitido: ' . $r );
				} elseif ( is_dir( $a ) ) {
					$s['stack'][] = $r;
				} elseif ( is_file( $a ) ) {
					if ( ! is_readable( $a ) ) {
						$this->log( 'Sin permiso de lectura, omitido: ' . $r );
						continue;
					}
					fwrite( $list, "F\t$r\n" );
					++$this->meta['files'];
					$this->meta['bytes'] += (int) @filesize( $a );
				}
			}
		}
		fflush( $list );
		$s['list_size'] = ftell( $list );
		fclose( $list );
		if ( ! $s['stack'] ) {
			$this->meta['stage'] = 'zip';
		}
	}

	private function stage_zip( $deadline ) {
		$z      = &$this->meta['zip'];
		$writer = new Kombi_Backup_Zip_Writer( $this->path( '.zip.part' ), $this->path( '.cd' ), $z['zip_size'], $z['cd_size'], $z['count'] );
		$root   = self::root();
		$sync   = function () use ( &$z, $writer ) {
			$z['zip_size'] = $writer->zip_size;
			$z['cd_size']  = $writer->cd_size;
			$z['count']    = $writer->count;
			$writer->flush();
		};

		if ( ! $z['header'] ) {
			$writer->add_string( 'kombi-backup/manifest.json', wp_json_encode( $this->manifest(), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ) );
			// wp-config.php puede estar un nivel arriba de ABSPATH: se guarda en la raíz del ZIP.
			if ( ! is_file( $root . 'wp-config.php' ) && is_file( dirname( $root ) . '/wp-config.php' ) ) {
				$writer->add_file( dirname( $root ) . '/wp-config.php', 'wp-config.php' );
			}
			$z['header'] = true;
		}
		while ( $z['db_next'] <= $this->meta['db']['part'] && microtime( true ) < $deadline ) {
			$part = sprintf( $this->path( '-db-%03d.sql' ), $z['db_next'] );
			if ( false === $writer->add_file( $part, sprintf( 'kombi-backup/database-%03d.sql', $z['db_next'] ) ) ) {
				throw new RuntimeException( 'No se pudo leer el volcado de la base de datos.' );
			}
			++$z['db_next'];
		}
		$sync();

		$list = fopen( $this->path( '.list' ), 'rb' );
		fseek( $list, $z['list_offset'] );
		$saved = microtime( true );
		while ( $z['db_next'] > $this->meta['db']['part'] && microtime( true ) < $deadline ) {
			$line = fgets( $list );
			if ( false === $line ) {
				break;
			}
			list( $type, $rel ) = explode( "\t", rtrim( $line, "\n" ), 2 );
			if ( 'D' === $type ) {
				$writer->add_dir( $rel, (int) @filemtime( $root . $rel ) );
			} else {
				$read = $writer->add_file( $root . $rel, $rel );
				if ( false === $read ) {
					$this->log( 'No se pudo leer (¿borrado durante el respaldo?): ' . $rel );
				} else {
					++$this->meta['done_files'];
					$this->meta['done_bytes'] += $read;
				}
			}
			$z['list_offset'] = ftell( $list );
			$sync();
			if ( microtime( true ) - $saved > self::SAVE_EVERY ) {
				$this->save();
				$saved = microtime( true );
			}
		}
		$eof = feof( $list ) || false === fgets( $list );
		fclose( $list );

		if ( $eof && $z['db_next'] > $this->meta['db']['part'] ) {
			$writer->finalize();
			$writer->close();
			if ( ! @rename( $this->path( '.zip.part' ), $this->zip_path() ) ) {
				throw new RuntimeException( 'No se pudo cerrar el archivo de respaldo.' );
			}
			$this->cleanup_temp();
			$this->meta['size']   = filesize( $this->zip_path() );
			$this->meta['stage']  = 'done';
			$this->meta['status'] = 'done';
			unset( $this->meta['db'], $this->meta['scan'], $this->meta['zip'] );
		}
	}

	private function cleanup_temp() {
		foreach ( array( '.list', '.cd', '.zip.part', '.lock' ) as $suffix ) {
			@unlink( $this->path( $suffix ) );
		}
		foreach ( (array) glob( $this->path( '-db-*.sql' ) ) as $file ) {
			@unlink( $file );
		}
	}

	/** Lo que el instalador necesita saber del sitio de origen. */
	private function manifest() {
		global $wpdb;
		return array(
			'format'       => 1,
			'plugin'       => KOMBI_BACKUP_VERSION,
			'created'      => wp_date( 'c', $this->meta['created'] ),
			'home'         => home_url(),
			'siteurl'      => site_url(),
			'abspath'      => self::root(),
			'table_prefix' => $wpdb->prefix,
			'db_parts'     => $this->meta['db']['part'],
			'db_charset'   => defined( 'DB_CHARSET' ) ? DB_CHARSET : 'utf8mb4',
			'wp_version'   => get_bloginfo( 'version' ),
			'php_version'  => PHP_VERSION,
			'mysql'        => $wpdb->db_server_info(),
			'files'        => $this->meta['files'],
			'bytes'        => $this->meta['bytes'],
			'key_hash'     => password_hash( $this->meta['key'], PASSWORD_DEFAULT ),
		);
	}

	private function log( $msg ) {
		$this->meta['log'][] = $msg;
		if ( count( $this->meta['log'] ) > 200 ) {
			$this->meta['log'] = array_slice( $this->meta['log'], -200 );
		}
	}

	/* ---------------------------------------------------------------------
	 * Configuración
	 * ------------------------------------------------------------------ */

	/** ABSPATH con barras normales. */
	public static function root() {
		return trailingslashit( wp_normalize_path( ABSPATH ) );
	}

	private static function storage_rel() {
		return ltrim( substr( wp_normalize_path( kombi_backup_storage_dir() ), strlen( self::root() ) ), '/' );
	}

	/** Carpetas de caché y de otros plugins de respaldo: no aportan y pesan. */
	public static function default_excludes() {
		return array(
			'wp-content/cache',
			'wp-content/upgrade',
			'wp-content/upgrade-temp-backup',
			'wp-content/backups',
			'wp-content/backup-db',
			'wp-content/updraft',
			'wp-content/ai1wm-backups',
			'wp-content/litespeed',
			'wp-content/et-cache',
			'wp-content/plugins/akeebabackupwp/app/backups',
		);
	}

	public static function excludes() {
		$opt = get_option( 'kombi_backup_excludes', null );
		if ( null === $opt ) {
			return self::default_excludes();
		}
		return self::parse_excludes( $opt );
	}

	public static function parse_excludes( $text ) {
		$out = array();
		foreach ( preg_split( '/\R/', (string) $text ) as $line ) {
			$line = trim( str_replace( '\\', '/', $line ), "/ \t" );
			if ( '' !== $line && false === strpos( $line, '..' ) ) {
				$out[] = $line;
			}
		}
		return array_values( array_unique( $out ) );
	}

	/** Clave del instalador: 12 caracteres sin ambiguos (0/O, 1/l). */
	private static function random_key() {
		$chars = 'abcdefghjkmnpqrstuvwxyz23456789';
		$key   = '';
		for ( $i = 0; $i < 12; $i++ ) {
			$key .= $chars[ random_int( 0, strlen( $chars ) - 1 ) ];
			if ( 3 === $i % 4 && $i < 11 ) {
				$key .= '-';
			}
		}
		return $key;
	}
}
