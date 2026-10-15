<?php
/**
 * ZIP de solo-anexar.
 *
 * Escribe cada entrada al final del archivo y guarda el directorio central en
 * un archivo aparte hasta finalize(). Así el respaldo avanza en pasos AJAX
 * cortos sin reescribir lo ya comprimido (ZipArchive sí lo reescribe al
 * cerrar, y no siempre está instalado). Solo necesita zlib. Soporta ZIP64
 * (más de 65.535 entradas o más de 4 GB) y cualquier unzip lo abre.
 *
 * @package Kombi_Backup
 */

defined( 'ABSPATH' ) || exit;

class Kombi_Backup_Zip_Writer {

	const CHUNK       = 1048576;
	const DEFLATE_MAX = 67108864; // Archivos más grandes se guardan sin comprimir (el paso no se alarga).
	const MAX32       = 0xFFFFFFFF;
	const MADE_BY     = 0x032D;   // Unix, ZIP 4.5.

	/** Formatos ya comprimidos: deflate no gana nada y gasta CPU. */
	const STORE_EXT = array( 'jpg', 'jpeg', 'png', 'gif', 'webp', 'avif', 'heic', 'ico', 'mp3', 'm4a', 'ogg', 'mp4', 'm4v', 'mov', 'webm', 'avi', 'zip', 'gz', 'tgz', 'bz2', 'xz', '7z', 'rar', 'jpa', 'wpress', 'woff', 'woff2', 'pdf' );

	private $zip;
	private $cd;
	public $zip_size;
	public $cd_size;
	public $count;

	/**
	 * Reabre (o crea) el ZIP en curso. Los tamaños vienen del último estado
	 * guardado: si un paso murió a medias, lo escrito después se descarta.
	 */
	public function __construct( $zip_path, $cd_path, $zip_size = 0, $cd_size = 0, $count = 0 ) {
		$this->zip = @fopen( $zip_path, 'c+b' );
		$this->cd  = @fopen( $cd_path, 'c+b' );
		if ( ! $this->zip || ! $this->cd ) {
			throw new RuntimeException( 'No se pudo abrir el archivo de respaldo para escribir.' );
		}
		ftruncate( $this->zip, $zip_size );
		fseek( $this->zip, $zip_size );
		ftruncate( $this->cd, $cd_size );
		fseek( $this->cd, $cd_size );
		$this->zip_size = $zip_size;
		$this->cd_size  = $cd_size;
		$this->count    = $count;
	}

	public function __destruct() {
		$this->close();
	}

	public function flush() {
		fflush( $this->zip );
		fflush( $this->cd );
	}

	public function close() {
		if ( $this->zip ) {
			fclose( $this->zip );
			$this->zip = null;
		}
		if ( $this->cd ) {
			fclose( $this->cd );
			$this->cd = null;
		}
	}

	/**
	 * Agrega un archivo del disco. Devuelve los bytes leídos o false si no se pudo abrir.
	 */
	public function add_file( $path, $name ) {
		$in = @fopen( $path, 'rb' );
		if ( ! $in ) {
			return false;
		}
		$size   = (int) @filesize( $path );
		$mtime  = (int) @filemtime( $path );
		$ext    = strtolower( pathinfo( $name, PATHINFO_EXTENSION ) );
		$method = ( $size > 0 && $size <= self::DEFLATE_MAX && ! in_array( $ext, self::STORE_EXT, true ) ) ? 8 : 0;
		$zip64  = $size >= self::MAX32;
		$offset = $this->zip_size;

		$this->write( $this->local_header( $name, $method, $mtime, $zip64 ) );
		$start = $this->zip_size;
		$crc   = hash_init( 'crc32b' );
		$read  = 0;
		$z     = 8 === $method ? deflate_init( ZLIB_ENCODING_RAW, array( 'level' => 6 ) ) : null;
		while ( ! feof( $in ) ) {
			$buf = fread( $in, self::CHUNK );
			if ( false === $buf || '' === $buf ) {
				break;
			}
			$read += strlen( $buf );
			hash_update( $crc, $buf );
			$this->write( $z ? deflate_add( $z, $buf, ZLIB_NO_FLUSH ) : $buf );
		}
		if ( $z ) {
			$this->write( deflate_add( $z, '', ZLIB_FINISH ) );
		}
		fclose( $in );

		$crc = hexdec( hash_final( $crc ) );
		$this->patch( $offset, strlen( $name ), $crc, $this->zip_size - $start, $read, $zip64 );
		$this->central( $name, $method, $mtime, $crc, $this->zip_size - $start, $read, $offset, false );
		return $read;
	}

	/** Agrega contenido en memoria (manifiesto). */
	public function add_string( $name, $data ) {
		$offset = $this->zip_size;
		$packed = gzdeflate( $data, 6 );
		$crc    = crc32( $data );
		$this->write( $this->local_header( $name, 8, time(), false ) );
		$this->write( $packed );
		$this->patch( $offset, strlen( $name ), $crc, strlen( $packed ), strlen( $data ), false );
		$this->central( $name, 8, time(), $crc, strlen( $packed ), strlen( $data ), $offset, false );
	}

	/** Agrega una carpeta (así se conservan también las vacías). */
	public function add_dir( $name, $mtime = 0 ) {
		$name   = rtrim( $name, '/' ) . '/';
		$offset = $this->zip_size;
		$this->write( $this->local_header( $name, 0, $mtime, false ) );
		$this->central( $name, 0, $mtime, 0, 0, 0, $offset, true );
	}

	/** Cierra el ZIP: copia el directorio central y escribe el fin de archivo. */
	public function finalize() {
		$cd_start = $this->zip_size;
		fflush( $this->cd );
		rewind( $this->cd );
		while ( ! feof( $this->cd ) ) {
			$buf = fread( $this->cd, self::CHUNK );
			if ( false === $buf || '' === $buf ) {
				break;
			}
			$this->write( $buf );
		}
		$n      = $this->count;
		$cd_len = $this->cd_size;
		if ( $n >= 0xFFFF || $cd_start >= self::MAX32 || $cd_len >= self::MAX32 ) {
			$z64 = $this->zip_size;
			$this->write( pack( 'VPvvVVPPPP', 0x06064b50, 44, self::MADE_BY, 45, 0, 0, $n, $n, $cd_len, $cd_start ) );
			$this->write( pack( 'VVPV', 0x07064b50, 0, $z64, 1 ) );
		}
		$this->write( pack( 'VvvvvVVv', 0x06054b50, 0, 0, min( $n, 0xFFFF ), min( $n, 0xFFFF ), min( $cd_len, self::MAX32 ), min( $cd_start, self::MAX32 ), 0 ) );
		fflush( $this->zip );
	}

	private function write( $data ) {
		if ( '' === $data || false === $data ) {
			return;
		}
		$len = strlen( $data );
		if ( fwrite( $this->zip, $data ) !== $len ) {
			throw new RuntimeException( 'No se pudo escribir el respaldo (¿disco lleno?).' );
		}
		$this->zip_size += $len;
	}

	private function local_header( $name, $method, $mtime, $zip64 ) {
		list( $t, $d ) = self::dos_time( $mtime );
		$extra         = $zip64 ? pack( 'vvPP', 1, 16, 0, 0 ) : '';
		$size          = $zip64 ? self::MAX32 : 0;
		return pack( 'VvvvvvVVVvv', 0x04034b50, $zip64 ? 45 : 20, 0x0800, $method, $t, $d, 0, $size, $size, strlen( $name ), strlen( $extra ) ) . $name . $extra;
	}

	/** Completa CRC y tamaños en la cabecera local, ya conocidos tras escribir los datos. */
	private function patch( $offset, $name_len, $crc, $comp, $uncomp, $zip64 ) {
		fseek( $this->zip, $offset + 14 );
		fwrite( $this->zip, pack( 'VVV', $crc, $zip64 ? self::MAX32 : $comp, $zip64 ? self::MAX32 : $uncomp ) );
		if ( $zip64 ) {
			fseek( $this->zip, $offset + 30 + $name_len + 4 );
			fwrite( $this->zip, pack( 'PP', $uncomp, $comp ) );
		}
		fseek( $this->zip, $this->zip_size );
	}

	private function central( $name, $method, $mtime, $crc, $comp, $uncomp, $offset, $is_dir ) {
		list( $t, $d ) = self::dos_time( $mtime );
		$extra         = '';
		if ( $uncomp >= self::MAX32 ) {
			$extra .= pack( 'P', $uncomp );
			$uncomp = self::MAX32;
		}
		if ( $comp >= self::MAX32 ) {
			$extra .= pack( 'P', $comp );
			$comp   = self::MAX32;
		}
		if ( $offset >= self::MAX32 ) {
			$extra .= pack( 'P', $offset );
			$offset = self::MAX32;
		}
		if ( '' !== $extra ) {
			$extra = pack( 'vv', 1, strlen( $extra ) ) . $extra;
		}
		$attr = $is_dir ? ( ( 040755 << 16 ) | 0x10 ) : ( 0100644 << 16 );
		$rec  = pack( 'VvvvvvvVVVvvvvvVV', 0x02014b50, self::MADE_BY, '' !== $extra ? 45 : 20, 0x0800, $method, $t, $d, $crc, $comp, $uncomp, strlen( $name ), strlen( $extra ), 0, 0, 0, $attr, $offset ) . $name . $extra;
		if ( fwrite( $this->cd, $rec ) !== strlen( $rec ) ) {
			throw new RuntimeException( 'No se pudo escribir el índice del respaldo (¿disco lleno?).' );
		}
		$this->cd_size += strlen( $rec );
		++$this->count;
	}

	private static function dos_time( $ts ) {
		$d = getdate( $ts ? $ts : time() );
		if ( $d['year'] < 1980 ) {
			return array( 0, ( 1 << 5 ) | 1 );
		}
		$year = min( $d['year'], 2107 );
		return array(
			( $d['hours'] << 11 ) | ( $d['minutes'] << 5 ) | ( $d['seconds'] >> 1 ),
			( ( $year - 1980 ) << 9 ) | ( $d['mon'] << 5 ) | $d['mday'],
		);
	}
}
