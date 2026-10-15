<?php
/**
 * Kombi Backup — instalador y restaurador.
 *
 * KOMBI_BACKUP_INSTALLER (firma: el plugin la usa para reconocer este archivo)
 *
 * Instalación nueva: sube este archivo y el .zip del respaldo a la carpeta
 * donde irá el sitio y ábrelo en el navegador. Pide la clave del respaldo y
 * los datos de una base de datos vacía; extrae los archivos, importa la base,
 * cambia la URL antigua por la nueva (también en datos serializados), ajusta
 * wp-config.php y .htaccess, y al terminar se borra solo.
 *
 * Restauración: el plugin lo copia a la raíz con un token de un solo uso.
 * Conserva wp-config.php, .htaccess y la URL del sitio actual.
 *
 * Funciona sin WordPress cargado. PHP 7.4+, zlib y mysqli.
 *
 * @package Kombi_Backup
 */

define( 'KBI_VERSION', '0.1.0' );
define( 'KBI_DIR', str_replace( '\\', '/', __DIR__ ) );
define( 'KBI_TMP', KBI_DIR . '/kombi-installer-tmp' );
define( 'KBI_RESTORE_FILE', KBI_DIR . '/kombi-restore.php' );
define( 'KBI_BUDGET', 8 );
define( 'KBI_CHUNK', 1048576 );

error_reporting( E_ALL & ~E_DEPRECATED & ~E_NOTICE );
ini_set( 'display_errors', '0' );
@set_time_limit( 120 );
mysqli_report( MYSQLI_REPORT_OFF );

session_name( 'kombi_installer' );
session_set_cookie_params( array( 'httponly' => true, 'samesite' => 'Strict', 'secure' => kbi_is_https() ) );
session_start();

class KBI_Error extends Exception {}

/* -------------------------------------------------------------------------
 * Enrutado
 * ---------------------------------------------------------------------- */

// Enlace de restauración desde el plugin: valida el token y lo saca de la URL.
if ( isset( $_GET['restore'] ) ) {
	$cfg = kbi_restore_cfg();
	if ( $cfg && time() < $cfg['expires'] && hash_equals( $cfg['token_hash'], hash( 'sha256', (string) $_GET['restore'] ) ) ) {
		session_regenerate_id( true );
		$_SESSION['kbi_auth']    = 'restore';
		$_SESSION['kbi_archive'] = $cfg['archive'];
	}
	header( 'Location: ' . basename( __FILE__ ) );
	exit;
}

if ( 'POST' === $_SERVER['REQUEST_METHOD'] && isset( $_POST['action'] ) ) {
	header( 'Content-Type: application/json; charset=utf-8' );
	header( 'Cache-Control: no-store' );
	try {
		$out = kbi_api( (string) $_POST['action'] );
		echo json_encode( array( 'ok' => true ) + $out, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
	} catch ( Throwable $e ) {
		echo json_encode( array( 'ok' => false, 'error' => $e->getMessage() ), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
	}
	exit;
}

kbi_page();
exit;

/* -------------------------------------------------------------------------
 * API
 * ---------------------------------------------------------------------- */

function kbi_api( $action ) {
	if ( 'info' === $action ) {
		return kbi_info();
	}
	if ( 'login' === $action ) {
		return kbi_login();
	}
	if ( empty( $_SESSION['kbi_auth'] ) ) {
		throw new KBI_Error( 'Sesión no válida. Recarga la página.' );
	}
	switch ( $action ) {
		case 'testdb':
			return kbi_test_db( kbi_db_input() );
		case 'start':
			return kbi_start();
		case 'step':
			return kbi_step();
	}
	throw new KBI_Error( 'Acción desconocida.' );
}

function kbi_mode() {
	return isset( $_SESSION['kbi_auth'] ) && 'restore' === $_SESSION['kbi_auth'] ? 'restore' : 'fresh';
}

function kbi_info() {
	$state = kbi_state();
	$out   = array(
		'auth'   => ! empty( $_SESSION['kbi_auth'] ),
		'mode'   => kbi_mode(),
		'checks' => kbi_checks(),
	);
	if ( ! $out['auth'] ) {
		$out['archives'] = array_map( 'basename', kbi_find_archives() );
		$out['restore_pending'] = (bool) kbi_restore_cfg();
		return $out;
	}
	$archive  = $_SESSION['kbi_archive'];
	$manifest = kbi_manifest( $archive );
	$out['backup'] = array(
		'archive' => basename( $archive ),
		'size'    => @filesize( $archive ),
		'home'    => $manifest['home'],
		'created' => $manifest['created'],
		'wp'      => $manifest['wp_version'],
		'files'   => $manifest['files'],
		'prefix'  => $manifest['table_prefix'],
	);
	if ( 'restore' === kbi_mode() ) {
		$cfg        = kbi_restore_cfg();
		$db         = kbi_parse_wpconfig( KBI_DIR . '/wp-config.php' );
		$out['url'] = $cfg ? $cfg['home'] : kbi_detect_url();
		$out['db']  = array( 'host' => $db['host'], 'name' => $db['name'], 'user' => $db['user'], 'prefix' => $manifest['table_prefix'], 'from_config' => (bool) $db['name'] );
	} else {
		$out['url'] = kbi_detect_url();
		$out['db']  = array( 'host' => 'localhost', 'name' => '', 'user' => '', 'prefix' => $manifest['table_prefix'], 'from_config' => false );
	}
	if ( $state && 'setup' !== $state['stage'] ) {
		$out['running'] = kbi_progress( $state );
	}
	return $out;
}

function kbi_login() {
	if ( 'restore' === kbi_mode() ) {
		return array();
	}
	$archives = kbi_find_archives();
	$name     = isset( $_POST['archive'] ) ? basename( (string) $_POST['archive'] ) : '';
	$archive  = null;
	foreach ( $archives as $a ) {
		if ( basename( $a ) === $name || ( '' === $name && 1 === count( $archives ) ) ) {
			$archive = $a;
		}
	}
	if ( ! $archive ) {
		throw new KBI_Error( 'No encontré el archivo de respaldo (.zip) en esta carpeta.' );
	}
	$manifest = kbi_manifest( $archive );
	$key      = strtolower( trim( (string) ( isset( $_POST['key'] ) ? $_POST['key'] : '' ) ) );
	if ( '' === $key || ! password_verify( $key, $manifest['key_hash'] ) ) {
		sleep( 2 );
		throw new KBI_Error( 'La clave no corresponde a este respaldo.' );
	}
	session_regenerate_id( true );
	$_SESSION['kbi_auth']    = 'fresh';
	$_SESSION['kbi_archive'] = $archive;
	return array();
}

function kbi_start() {
	$state = kbi_state();
	if ( $state && ! in_array( $state['stage'], array( 'setup', 'done' ), true ) ) {
		return kbi_progress( $state );
	}
	$archive  = $_SESSION['kbi_archive'];
	$manifest = kbi_manifest( $archive );
	$mode     = kbi_mode();
	foreach ( kbi_checks() as $c ) {
		if ( ! $c['ok'] && ! empty( $c['required'] ) ) {
			throw new KBI_Error( $c['label'] . ': ' . $c['msg'] );
		}
	}

	if ( 'restore' === $mode ) {
		$cfg  = kbi_restore_cfg();
		$db   = kbi_parse_wpconfig( KBI_DIR . '/wp-config.php' );
		$home = $cfg['home'];
		$site = $cfg['siteurl'];
		if ( ! $db['name'] ) {
			$db = kbi_db_input();
		}
	} else {
		$db   = kbi_db_input();
		$home = rtrim( trim( (string) $_POST['url'] ), '/' );
		if ( ! preg_match( '#^https?://[^/\s]+(/\S*)?$#i', $home ) ) {
			throw new KBI_Error( 'La URL nueva no es válida (ejemplo: https://midominio.cl).' );
		}
		// WordPress en subcarpeta (siteurl ≠ home): se conserva la misma relación.
		$old_home = rtrim( $manifest['home'], '/' );
		$old_site = rtrim( $manifest['siteurl'], '/' );
		$site     = 0 === strpos( $old_site, $old_home ) ? $home . substr( $old_site, strlen( $old_home ) ) : $home;
	}
	$db['prefix'] = $manifest['table_prefix'];
	kbi_test_db( $db );

	kbi_ensure_tmp();
	kbi_maintenance( true );
	$state = array(
		'mode'           => $mode,
		'archive'        => $archive,
		'manifest'       => $manifest,
		'db'             => $db,
		'home'           => rtrim( $home, '/' ),
		'siteurl'        => rtrim( $site, '/' ),
		'delete_archive' => 'fresh' === $mode && ! empty( $_POST['delete_archive'] ),
		'stage'          => 'extract',
		'offset'         => 0,
		'files'          => 0,
		'import'         => null,
		'replace'        => null,
		'log'            => array(),
		'started'        => time(),
	);
	kbi_save_state( $state );
	return kbi_progress( $state );
}

function kbi_step() {
	$state = kbi_state();
	if ( ! $state ) {
		throw new KBI_Error( 'No hay una instalación en curso.' );
	}
	$lock = fopen( KBI_TMP . '/lock', 'c' );
	if ( ! flock( $lock, LOCK_EX | LOCK_NB ) ) {
		return kbi_progress( $state ) + array( 'busy' => true );
	}
	$deadline = microtime( true ) + KBI_BUDGET;
	try {
		while ( microtime( true ) < $deadline && 'done' !== $state['stage'] ) {
			$stage = $state['stage'];
			if ( 'extract' === $stage ) {
				kbi_step_extract( $state, $deadline );
			} elseif ( 'import' === $stage ) {
				kbi_step_import( $state, $deadline );
			} elseif ( 'replace' === $stage ) {
				kbi_step_replace( $state, $deadline );
			} elseif ( 'config' === $stage ) {
				kbi_step_config( $state );
			}
			kbi_save_state( $state );
		}
	} catch ( Throwable $e ) {
		kbi_save_state( $state );
		flock( $lock, LOCK_UN );
		fclose( $lock );
		throw $e;
	}
	flock( $lock, LOCK_UN );
	fclose( $lock );

	$progress = kbi_progress( $state );
	if ( 'done' === $state['stage'] ) {
		kbi_finish( $state );
	}
	return $progress;
}

function kbi_progress( array $s ) {
	$weights = array( 'extract' => array( 0, 45 ), 'import' => array( 45, 35 ), 'replace' => array( 80, 17 ), 'config' => array( 97, 3 ), 'done' => array( 100, 0 ) );
	$frac    = 0;
	$label   = '';
	switch ( $s['stage'] ) {
		case 'extract':
			$size  = max( 1, (int) @filesize( $s['archive'] ) );
			$frac  = $s['offset'] / $size;
			$label = sprintf( 'Extrayendo archivos: %s', number_format( $s['files'], 0, ',', '.' ) );
			break;
		case 'import':
			$i     = $s['import'];
			$frac  = $i ? $i['done'] / max( 1, $i['total'] ) : 0;
			$label = sprintf( 'Importando base de datos: %s sentencias', number_format( $i ? $i['queries'] : 0, 0, ',', '.' ) );
			break;
		case 'replace':
			$r     = $s['replace'];
			$frac  = $r && $r['tables'] ? $r['t'] / count( $r['tables'] ) : 0;
			$label = $r && isset( $r['tables'][ $r['t'] ] ) ? 'Actualizando URLs: ' . $r['tables'][ $r['t'] ] : 'Actualizando URLs';
			break;
		case 'config':
			$label = 'Ajustando configuración';
			break;
		case 'done':
			$label = 'Listo';
	}
	list( $base, $span ) = $weights[ $s['stage'] ];
	return array(
		'stage'   => $s['stage'],
		'percent' => round( min( 100, $base + $span * $frac ), 1 ),
		'label'   => $label,
		'done'    => 'done' === $s['stage'],
		'log'     => array_slice( $s['log'], -30 ),
		'home'    => $s['home'],
		'admin'   => $s['siteurl'] . '/wp-admin/',
		'mode'    => $s['mode'],
	);
}

/* -------------------------------------------------------------------------
 * Paso 1: extraer
 * ---------------------------------------------------------------------- */

function kbi_step_extract( array &$s, $deadline ) {
	$fh = @fopen( $s['archive'], 'rb' );
	if ( ! $fh ) {
		throw new KBI_Error( 'No se pudo abrir el respaldo.' );
	}
	$saved = microtime( true );
	while ( microtime( true ) < $deadline ) {
		$e = kbi_zip_entry( $fh, $s['offset'] );
		if ( ! $e ) {
			$s['stage'] = 'import';
			break;
		}
		$target = kbi_target( $s, $e['name'] );
		if ( null !== $target ) {
			kbi_extract_entry( $fh, $e, $target );
		}
		$s['offset'] = $e['next'];
		++$s['files'];
		if ( microtime( true ) - $saved > 2 ) {
			kbi_save_state( $s );
			$saved = microtime( true );
		}
	}
	fclose( $fh );
}

/** Lee la cabecera local en $offset. null al llegar al directorio central. */
function kbi_zip_entry( $fh, $offset ) {
	fseek( $fh, $offset );
	$raw = fread( $fh, 30 );
	if ( false === $raw || strlen( $raw ) < 30 ) {
		return null;
	}
	$h = unpack( 'Vsig/vver/vflags/vmethod/vtime/vdate/Vcrc/Vcomp/Vsize/vnlen/velen', $raw );
	if ( 0x04034b50 !== $h['sig'] ) {
		return null;
	}
	if ( $h['flags'] & 0x08 ) {
		throw new KBI_Error( 'El .zip no fue creado por Kombi Backup (usa descriptores de datos).' );
	}
	$name  = $h['nlen'] ? fread( $fh, $h['nlen'] ) : '';
	$extra = $h['elen'] ? fread( $fh, $h['elen'] ) : '';
	$comp  = $h['comp'];
	$size  = $h['size'];
	// ZIP64: tamaños reales en el campo extra 0x0001.
	for ( $p = 0; $p + 4 <= strlen( $extra ); ) {
		$f = unpack( 'vid/vlen', substr( $extra, $p, 4 ) );
		if ( 1 === $f['id'] ) {
			$z = substr( $extra, $p + 4, $f['len'] );
			$q = 0;
			if ( 0xFFFFFFFF === $size ) {
				$size = unpack( 'P', substr( $z, $q, 8 ) )[1];
				$q   += 8;
			}
			if ( 0xFFFFFFFF === $comp ) {
				$comp = unpack( 'P', substr( $z, $q, 8 ) )[1];
			}
		}
		$p += 4 + $f['len'];
	}
	$data = $offset + 30 + $h['nlen'] + $h['elen'];
	return array(
		'name'   => $name,
		'method' => $h['method'],
		'crc'    => $h['crc'],
		'comp'   => $comp,
		'size'   => $size,
		'data'   => $data,
		'next'   => $data + $comp,
	);
}

/** Dónde extraer cada entrada; null = omitir. */
function kbi_target( array &$s, $name ) {
	$n = str_replace( '\\', '/', $name );
	if ( '' === $n || '/' === $n[0] || preg_match( '#(^|/)\.\.(/|$)#', $n ) || preg_match( '#^[a-z]:#i', $n ) ) {
		kbi_log( $s, 'Ruta no segura, omitida: ' . $n );
		return null;
	}
	if ( 0 === strpos( $n, 'kombi-backup/' ) ) {
		$base = basename( $n );
		return '' === $base || '/' === substr( $n, -1 ) ? null : KBI_TMP . '/' . $base;
	}
	$skip = array( basename( __FILE__ ), 'kombi-installer.php', 'kombi-restore.php', '.maintenance', basename( $s['archive'] ) );
	if ( in_array( $n, $skip, true ) ) {
		return null;
	}
	// Configuración de PHP del servidor de origen (rutas, auto_prepend…): no aplica aquí.
	if ( in_array( $n, array( '.user.ini', 'php.ini' ), true ) ) {
		kbi_log( $s, "$n del servidor de origen no se copió." );
		return null;
	}
	if ( 'restore' === $s['mode'] && ( in_array( $n, array( 'wp-config.php', '.htaccess' ), true ) || 0 === strpos( $n, 'wp-content/kombi-backups/' ) ) ) {
		return null;
	}
	return KBI_DIR . '/' . $n;
}

function kbi_extract_entry( $fh, array $e, $target ) {
	if ( '/' === substr( $e['name'], -1 ) ) {
		if ( ! is_dir( $target ) && ! @mkdir( $target, 0755, true ) && ! is_dir( $target ) ) {
			throw new KBI_Error( 'No se pudo crear la carpeta ' . $e['name'] );
		}
		return;
	}
	$dir = dirname( $target );
	if ( ! is_dir( $dir ) && ! @mkdir( $dir, 0755, true ) && ! is_dir( $dir ) ) {
		throw new KBI_Error( 'No se pudo crear la carpeta de ' . $e['name'] );
	}
	if ( ! in_array( $e['method'], array( 0, 8 ), true ) ) {
		throw new KBI_Error( 'Método de compresión no soportado en ' . $e['name'] );
	}
	$out = @fopen( $target, 'wb' );
	if ( ! $out ) {
		throw new KBI_Error( 'No se pudo escribir ' . $e['name'] . ' (revisa permisos).' );
	}
	fseek( $fh, $e['data'] );
	$left = $e['comp'];
	$crc  = hash_init( 'crc32b' );
	$z    = 8 === $e['method'] ? inflate_init( ZLIB_ENCODING_RAW ) : null;
	while ( $left > 0 ) {
		$buf = fread( $fh, (int) min( KBI_CHUNK, $left ) );
		if ( false === $buf || '' === $buf ) {
			fclose( $out );
			throw new KBI_Error( 'El respaldo está incompleto (se cortó la subida?): ' . $e['name'] );
		}
		$left -= strlen( $buf );
		if ( $z ) {
			$buf = inflate_add( $z, $buf, $left > 0 ? ZLIB_SYNC_FLUSH : ZLIB_FINISH );
			if ( false === $buf ) {
				fclose( $out );
				throw new KBI_Error( 'Datos dañados en ' . $e['name'] );
			}
		}
		hash_update( $crc, $buf );
		fwrite( $out, $buf );
	}
	fclose( $out );
	if ( hexdec( hash_final( $crc ) ) !== $e['crc'] ) {
		throw new KBI_Error( 'El archivo ' . $e['name'] . ' está dañado (CRC). Vuelve a subir el respaldo.' );
	}
}

/* -------------------------------------------------------------------------
 * Paso 2: importar base de datos
 * ---------------------------------------------------------------------- */

function kbi_step_import( array &$s, $deadline ) {
	$db = kbi_db_connect( $s['db'] );
	if ( null === $s['import'] ) {
		$total = 0;
		for ( $i = 1; is_file( kbi_sql_part( $i ) ); $i++ ) {
			$total += filesize( kbi_sql_part( $i ) );
		}
		if ( 1 === $i ) {
			throw new KBI_Error( 'El respaldo no trae la base de datos.' );
		}
		// MariaDB y MySQL 5.7 no conocen las intercalaciones utf8mb4_0900_* de MySQL 8.
		$fix = '';
		$res = mysqli_query( $db, "SHOW COLLATION LIKE 'utf8mb4_0900_ai_ci'" );
		if ( $res && 0 === mysqli_num_rows( $res ) ) {
			$res = mysqli_query( $db, "SHOW COLLATION LIKE 'utf8mb4_unicode_520_ci'" );
			$fix = $res && mysqli_num_rows( $res ) ? 'utf8mb4_unicode_520_ci' : 'utf8mb4_unicode_ci';
		}
		$s['import'] = array( 'part' => 1, 'pos' => 0, 'total' => $total, 'done' => 0, 'base' => 0, 'queries' => 0, 'fix' => $fix );
	}
	$im = &$s['import'];
	while ( microtime( true ) < $deadline ) {
		$file = kbi_sql_part( $im['part'] );
		if ( ! is_file( $file ) ) {
			$s['stage'] = 'replace';
			break;
		}
		$fh = fopen( $file, 'rb' );
		fseek( $fh, $im['pos'] );
		while ( microtime( true ) < $deadline ) {
			$line = fgets( $fh );
			if ( false === $line ) {
				break;
			}
			$sql = rtrim( $line, "\r\n" );
			if ( '' !== $sql && 0 !== strpos( $sql, '--' ) ) {
				if ( $im['fix'] && preg_match( '/^(CREATE|ALTER) /', $sql ) ) {
					$sql = preg_replace( '/utf8mb4_0900_\w+/', $im['fix'], $sql );
				}
				if ( ! mysqli_query( $db, $sql ) ) {
					$errno = mysqli_errno( $db );
					// 1062: fila repetida al retomar un paso cortado; el resto no se puede ignorar.
					if ( 1062 !== $errno ) {
						fclose( $fh );
						throw new KBI_Error( "Error SQL $errno: " . mysqli_error( $db ) . ' — en: ' . substr( $sql, 0, 160 ) );
					}
				}
				++$im['queries'];
			}
			$im['pos']  = ftell( $fh );
			$im['done'] = $im['base'] + $im['pos'];
		}
		$eof = feof( $fh );
		fclose( $fh );
		if ( $eof ) {
			$im['base'] += filesize( $file );
			++$im['part'];
			$im['pos'] = 0;
		}
	}
	mysqli_close( $db );
}

function kbi_sql_part( $n ) {
	return sprintf( KBI_TMP . '/database-%03d.sql', $n );
}

/* -------------------------------------------------------------------------
 * Paso 3: cambiar URL y rutas en la base de datos
 * ---------------------------------------------------------------------- */

function kbi_step_replace( array &$s, $deadline ) {
	$db = kbi_db_connect( $s['db'] );
	$p  = $s['db']['prefix'];
	if ( null === $s['replace'] ) {
		$pairs  = kbi_pairs( $s );
		$tables = array();
		if ( $pairs ) {
			$res = mysqli_query( $db, "SHOW TABLES LIKE '" . mysqli_real_escape_string( $db, addcslashes( $p, '\\%_' ) ) . "%'" );
			while ( $res && ( $row = mysqli_fetch_row( $res ) ) ) {
				$tables[] = $row[0];
			}
		}
		$s['replace'] = array( 'pairs' => $pairs, 'tables' => $tables, 't' => 0, 'info' => null, 'cursor' => null, 'col' => 0, 'changed' => 0 );
		if ( $pairs ) {
			kbi_log( $s, 'Reemplazando: ' . implode( ' · ', array_slice( array_map( function ( $a, $b ) { return "$a → $b"; }, array_keys( $pairs ), $pairs ), 0, 2 ) ) );
		}
	}
	$r = &$s['replace'];
	while ( microtime( true ) < $deadline && $r['t'] < count( $r['tables'] ) ) {
		$table = $r['tables'][ $r['t'] ];
		if ( null === $r['info'] ) {
			$r['info']   = kbi_table_info( $db, $table, $p );
			$r['cursor'] = null;
			$r['col']    = 0;
		}
		$more = $r['info']['cols'] ? kbi_replace_batch( $db, $table, $r ) : false;
		if ( ! $more ) {
			++$r['t'];
			$r['info'] = null;
		}
	}
	if ( $r['t'] >= count( $r['tables'] ) ) {
		// Valores exactos aunque el reemplazo no los encontrara, y reglas de enlaces nuevas.
		$o = kbi_q( $p . 'options' );
		mysqli_query( $db, "UPDATE $o SET option_value = '" . mysqli_real_escape_string( $db, $s['home'] ) . "' WHERE option_name = 'home'" );
		mysqli_query( $db, "UPDATE $o SET option_value = '" . mysqli_real_escape_string( $db, $s['siteurl'] ) . "' WHERE option_name = 'siteurl'" );
		mysqli_query( $db, "DELETE FROM $o WHERE option_name = 'rewrite_rules'" );
		if ( $r['pairs'] ) {
			kbi_log( $s, sprintf( 'URLs actualizadas en %d campos.', $r['changed'] ) );
		}
		$s['stage'] = 'config';
	}
	mysqli_close( $db );
}

/**
 * Pares viejo → nuevo: URL (también http://, //host, JSON \/ y urlencode) y
 * ruta del servidor (con / y \). strtr() elige siempre la coincidencia más
 * larga y no vuelve a reemplazar lo ya reemplazado.
 */
function kbi_pairs( array $s ) {
	$m     = $s['manifest'];
	$pairs = array();
	$add   = function ( $a, $b ) use ( &$pairs ) {
		if ( '' !== $a && $a !== $b ) {
			$pairs[ $a ] = $b;
		}
	};
	$urls = array(
		array( rtrim( $m['siteurl'], '/' ), $s['siteurl'] ),
		array( rtrim( $m['home'], '/' ), $s['home'] ),
	);
	foreach ( $urls as $u ) {
		list( $old, $new ) = $u;
		$old_bare = preg_replace( '#^https?:#i', '', $old ); // //host/path
		$new_bare = preg_replace( '#^https?:#i', '', $new );
		foreach ( array( 'http:', 'https:' ) as $scheme ) {
			$add( $scheme . $old_bare, $new );
			$add( str_replace( '/', '\\/', $scheme . $old_bare ), str_replace( '/', '\\/', $new ) );
			$add( rawurlencode( $scheme . $old_bare ), rawurlencode( $new ) );
		}
		$add( $old_bare, $new_bare );
		$add( str_replace( '/', '\\/', $old_bare ), str_replace( '/', '\\/', $new_bare ) );
	}
	$old_path = rtrim( $m['abspath'], '/\\' );
	$new_path = rtrim( KBI_DIR, '/' );
	if ( $old_path !== $new_path ) {
		$fwd = str_replace( '\\', '/', $old_path );
		$add( $fwd, $new_path );
		$add( str_replace( '/', '\\', $fwd ), $new_path );
		$add( str_replace( '/', '\\/', $fwd ), str_replace( '/', '\\/', $new_path ) );
		$add( str_replace( '/', '\\\\', $fwd ), str_replace( '/', '\\/', $new_path ) );
	}
	return $pairs;
}

function kbi_table_info( $db, $table, $prefix ) {
	$cols = array();
	$res  = mysqli_query( $db, 'SHOW COLUMNS FROM ' . kbi_q( $table ) );
	while ( $res && ( $c = mysqli_fetch_assoc( $res ) ) ) {
		// El GUID de las entradas no se toca: identifica la entrada en los feeds.
		if ( preg_match( '/char|text|blob|json/i', $c['Type'] ) && ! ( 'guid' === $c['Field'] && $table === $prefix . 'posts' ) ) {
			$cols[] = $c['Field'];
		}
	}
	$pk  = array();
	$res = mysqli_query( $db, 'SHOW KEYS FROM ' . kbi_q( $table ) . " WHERE Key_name = 'PRIMARY'" );
	while ( $res && ( $k = mysqli_fetch_assoc( $res ) ) ) {
		$pk[] = $k['Column_name'];
	}
	return array( 'cols' => $cols, 'pk' => 1 === count( $pk ) ? $pk[0] : null );
}

/** Un bloque de filas. Devuelve true si quedan más en esta tabla. */
function kbi_replace_batch( $db, $table, array &$r ) {
	$t     = kbi_q( $table );
	$pairs = $r['pairs'];
	$info  = $r['info'];
	$like  = function ( $col ) use ( $db, $pairs ) {
		$or = array();
		foreach ( kbi_needles( array_keys( $pairs ) ) as $n ) {
			$or[] = kbi_q( $col ) . " LIKE '%" . mysqli_real_escape_string( $db, addcslashes( $n, '\\%_' ) ) . "%'";
		}
		return '(' . implode( ' OR ', $or ) . ')';
	};

	if ( $info['pk'] ) {
		$pk    = kbi_q( $info['pk'] );
		$where = '(' . implode( ' OR ', array_map( $like, $info['cols'] ) ) . ')';
		if ( null !== $r['cursor'] ) {
			$where .= " AND $pk > '" . mysqli_real_escape_string( $db, $r['cursor'] ) . "'";
		}
		$cols = implode( ',', array_map( 'kbi_q', $info['cols'] ) );
		$res  = mysqli_query( $db, "SELECT $pk, $cols FROM $t WHERE $where ORDER BY $pk LIMIT 200" );
		if ( ! $res ) {
			throw new KBI_Error( "Error leyendo $table: " . mysqli_error( $db ) );
		}
		$n = 0;
		while ( $row = mysqli_fetch_assoc( $res ) ) {
			++$n;
			$r['cursor'] = $row[ $info['pk'] ];
			$set         = array();
			foreach ( $info['cols'] as $c ) {
				if ( null === $row[ $c ] ) {
					continue;
				}
				$new = kbi_replace_value( $row[ $c ], $pairs );
				if ( $new !== $row[ $c ] ) {
					$set[] = kbi_q( $c ) . " = '" . mysqli_real_escape_string( $db, $new ) . "'";
				}
			}
			if ( $set ) {
				$r['changed'] += count( $set );
				mysqli_query( $db, "UPDATE $t SET " . implode( ', ', $set ) . " WHERE $pk = '" . mysqli_real_escape_string( $db, $r['cursor'] ) . "'" );
			}
		}
		return 200 === $n;
	}

	// Sin clave primaria simple: por valores distintos, una columna por vez.
	if ( $r['col'] >= count( $info['cols'] ) ) {
		return false;
	}
	$c   = kbi_q( $info['cols'][ $r['col'] ] );
	$res = mysqli_query( $db, "SELECT DISTINCT $c FROM $t WHERE " . $like( $info['cols'][ $r['col'] ] ) );
	while ( $res && ( $row = mysqli_fetch_row( $res ) ) ) {
		$new = kbi_replace_value( $row[0], $pairs );
		if ( $new !== $row[0] ) {
			++$r['changed'];
			mysqli_query( $db, "UPDATE $t SET $c = '" . mysqli_real_escape_string( $db, $new ) . "' WHERE BINARY $c = '" . mysqli_real_escape_string( $db, $row[0] ) . "'" );
		}
	}
	++$r['col'];
	return true;
}

/** Quita los textos que contienen a otro (si "a" está, "xa" también: basta buscar "a"). */
function kbi_needles( array $keys ) {
	$out = array();
	foreach ( $keys as $k ) {
		foreach ( $keys as $o ) {
			if ( $o !== $k && '' !== $o && false !== strpos( $k, $o ) ) {
				continue 2;
			}
		}
		$out[] = $k;
	}
	return $out;
}

function kbi_replace_value( $value, array $pairs ) {
	if ( preg_match( '/^(a|O|s|C):\d+:/', $value ) ) {
		$pos = 0;
		$out = kbi_ser_walk( $value, $pos, $pairs, 0 );
		if ( null !== $out && strlen( $value ) === $pos ) {
			return $out;
		}
	}
	return strtr( $value, $pairs );
}

/**
 * Recorre un valor serializado de PHP reemplazando dentro de los strings y
 * corrigiendo sus longitudes, sin deserializar (no instancia clases).
 * Devuelve null si el formato no es válido.
 */
function kbi_ser_walk( $s, &$i, array $pairs, $depth ) {
	if ( $depth > 64 || ! isset( $s[ $i ] ) ) {
		return null;
	}
	$type = $s[ $i ];
	switch ( $type ) {
		case 'N':
			if ( 'N;' !== substr( $s, $i, 2 ) ) {
				return null;
			}
			$i += 2;
			return 'N;';
		case 'b':
		case 'i':
		case 'd':
		case 'r':
		case 'R':
			$end = strpos( $s, ';', $i );
			if ( false === $end || ':' !== $s[ $i + 1 ] ) {
				return null;
			}
			$tok = substr( $s, $i, $end - $i + 1 );
			$i   = $end + 1;
			return $tok;
		case 's':
			if ( ! preg_match( '/\Gs:(\d+):"/', $s, $m, 0, $i ) ) {
				return null;
			}
			$len   = (int) $m[1];
			$start = $i + strlen( $m[0] );
			if ( '";' !== substr( $s, $start + $len, 2 ) ) {
				return null;
			}
			$str = substr( $s, $start, $len );
			$i   = $start + $len + 2;
			$new = kbi_replace_value( $str, $pairs ); // Strings con datos serializados adentro.
			return 's:' . strlen( $new ) . ':"' . $new . '";';
		case 'a':
		case 'O':
			if ( 'a' === $type ) {
				if ( ! preg_match( '/\Ga:(\d+):\{/', $s, $m, 0, $i ) ) {
					return null;
				}
				$head = $m[0];
				$n    = (int) $m[1];
			} else {
				if ( ! preg_match( '/\GO:(\d+):"/', $s, $m, 0, $i ) ) {
					return null;
				}
				$cls_start = $i + strlen( $m[0] );
				$cls_len   = (int) $m[1];
				if ( ! preg_match( '/\G":(\d+):\{/', $s, $m2, 0, $cls_start + $cls_len ) ) {
					return null;
				}
				$head = substr( $s, $i, $cls_start + $cls_len - $i ) . $m2[0];
				$n    = (int) $m2[1];
			}
			$i  += strlen( $head );
			$out = $head;
			for ( $k = 0; $k < $n * 2; $k++ ) {
				$v = kbi_ser_walk( $s, $i, $pairs, $depth + 1 );
				if ( null === $v ) {
					return null;
				}
				$out .= $v;
			}
			if ( ! isset( $s[ $i ] ) || '}' !== $s[ $i ] ) {
				return null;
			}
			++$i;
			return $out . '}';
		case 'C':
			if ( ! preg_match( '/\GC:(\d+):"/', $s, $m, 0, $i ) ) {
				return null;
			}
			$cls_start = $i + strlen( $m[0] );
			$cls_len   = (int) $m[1];
			if ( ! preg_match( '/\G":(\d+):\{/', $s, $m2, 0, $cls_start + $cls_len ) ) {
				return null;
			}
			$data_start = $cls_start + $cls_len + strlen( $m2[0] );
			$data       = substr( $s, $data_start, (int) $m2[1] );
			if ( '}' !== substr( $s, $data_start + (int) $m2[1], 1 ) ) {
				return null;
			}
			$i   = $data_start + (int) $m2[1] + 1;
			$new = strtr( $data, $pairs );
			return 'C:' . $cls_len . ':"' . substr( $s, $cls_start, $cls_len ) . '":' . strlen( $new ) . ':{' . $new . '}';
		case 'E':
			if ( ! preg_match( '/\GE:(\d+):"/', $s, $m, 0, $i ) ) {
				return null;
			}
			$end = $i + strlen( $m[0] ) + (int) $m[1] + 2;
			$tok = substr( $s, $i, $end - $i );
			$i   = $end;
			return $tok;
	}
	return null;
}

/* -------------------------------------------------------------------------
 * Paso 4: wp-config.php y .htaccess
 * ---------------------------------------------------------------------- */

function kbi_step_config( array &$s ) {
	$config = KBI_DIR . '/wp-config.php';
	$db     = $s['db'];
	if ( 'restore' === $s['mode'] ) {
		// Se conserva el wp-config de este servidor; solo se alinea el prefijo de tablas.
		$php = (string) @file_get_contents( $config );
		if ( '' !== $php && preg_match( '/\$table_prefix\s*=\s*([\'"])(.*?)\1\s*;/', $php, $m ) && $m[2] !== $db['prefix'] ) {
			$php = preg_replace( '/\$table_prefix\s*=\s*([\'"]).*?\1\s*;/', '$table_prefix = ' . var_export( $db['prefix'], true ) . ';', $php, 1 );
			kbi_write( $config, $php );
			kbi_log( $s, 'Prefijo de tablas en wp-config.php cambiado a ' . $db['prefix'] );
		}
	} else {
		if ( is_file( $config ) ) {
			$php = strtr( (string) file_get_contents( $config ), kbi_pairs( $s ) );
		} elseif ( is_file( KBI_DIR . '/wp-config-sample.php' ) ) {
			$php = kbi_config_from_sample( (string) file_get_contents( KBI_DIR . '/wp-config-sample.php' ), $db['prefix'] );
			kbi_log( $s, 'El respaldo no traía wp-config.php: se creó uno nuevo con claves de seguridad nuevas.' );
		} else {
			throw new KBI_Error( 'No hay wp-config.php ni wp-config-sample.php.' );
		}
		$values = array(
			'DB_NAME'     => $db['name'],
			'DB_USER'     => $db['user'],
			'DB_PASSWORD' => $db['pass'],
			'DB_HOST'     => $db['host'],
			'WP_HOME'     => $s['home'],
			'WP_SITEURL'  => $s['siteurl'],
		);
		foreach ( $values as $const => $value ) {
			$re = '/define\s*\(\s*([\'"])' . $const . '\1\s*,[^;]*?\)\s*;/';
			if ( preg_match( $re, $php ) ) {
				// WP_HOME / WP_SITEURL pueden calcularse con lógica del servidor antiguo: se fijan al valor nuevo.
				$php = preg_replace_callback(
					$re,
					function () use ( $const, $value ) {
						return "define( '$const', " . var_export( $value, true ) . ' );';
					},
					$php
				);
			} elseif ( 0 === strpos( $const, 'DB_' ) ) {
				throw new KBI_Error( "wp-config.php no define $const; revísalo a mano." );
			}
		}
		kbi_write( $config, $php );
		kbi_htaccess( $s );
	}
	$s['stage'] = 'done';
}

function kbi_config_from_sample( $php, $prefix ) {
	$php = preg_replace_callback(
		"/define\(\s*'(AUTH_KEY|SECURE_AUTH_KEY|LOGGED_IN_KEY|NONCE_KEY|AUTH_SALT|SECURE_AUTH_SALT|LOGGED_IN_SALT|NONCE_SALT)',\s*'put your unique phrase here'\s*\);/",
		function ( $m ) {
			$chars = 'abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789!@#$%^&*()-_[]{}<>~+=,.;:/?|';
			$salt  = '';
			for ( $i = 0; $i < 64; $i++ ) {
				$salt .= $chars[ random_int( 0, strlen( $chars ) - 1 ) ];
			}
			return "define( '{$m[1]}', " . var_export( $salt, true ) . ' );';
		},
		$php
	);
	return preg_replace( '/\$table_prefix\s*=\s*([\'"]).*?\1\s*;/', '$table_prefix = ' . var_export( $prefix, true ) . ';', $php, 1 );
}

/** Ajusta RewriteBase si el sitio cambió de carpeta; si no hay .htaccess, crea el de WordPress. */
function kbi_htaccess( array &$s ) {
	$file     = KBI_DIR . '/.htaccess';
	$old_base = rtrim( (string) parse_url( $s['manifest']['home'], PHP_URL_PATH ), '/' ) . '/';
	$new_base = rtrim( (string) parse_url( $s['home'], PHP_URL_PATH ), '/' ) . '/';
	if ( ! is_file( $file ) ) {
		kbi_write( $file, "# BEGIN WordPress\n<IfModule mod_rewrite.c>\nRewriteEngine On\nRewriteBase $new_base\nRewriteRule ^index\\.php$ - [L]\nRewriteCond %{REQUEST_FILENAME} !-f\nRewriteCond %{REQUEST_FILENAME} !-d\nRewriteRule . {$new_base}index.php [L]\n</IfModule>\n# END WordPress\n" );
		return;
	}
	if ( $old_base === $new_base ) {
		return;
	}
	$ht = (string) file_get_contents( $file );
	$ht = preg_replace( '/^(\s*RewriteBase\s+)' . preg_quote( $old_base, '/' ) . '\s*$/m', '${1}' . $new_base, $ht );
	$ht = preg_replace( '/^(\s*RewriteRule\s+\.\s+)' . preg_quote( $old_base, '/' ) . 'index\.php/m', '${1}' . $new_base . 'index.php', $ht );
	kbi_write( $file, $ht );
	kbi_log( $s, ".htaccess: carpeta base cambiada de $old_base a $new_base" );
}

/** Al terminar: fuera mantenimiento, temporales, token y el propio instalador. */
function kbi_finish( array $s ) {
	kbi_maintenance( false );
	kbi_rrmdir( KBI_TMP );
	@unlink( KBI_RESTORE_FILE );
	if ( $s['delete_archive'] && 0 === strpos( str_replace( '\\', '/', $s['archive'] ), KBI_DIR . '/' ) ) {
		@unlink( $s['archive'] );
	}
	$_SESSION = array();
	@unlink( __FILE__ );
}

/* -------------------------------------------------------------------------
 * Base de datos
 * ---------------------------------------------------------------------- */

function kbi_db_input() {
	$f = function ( $k ) {
		return isset( $_POST[ $k ] ) ? trim( (string) $_POST[ $k ] ) : '';
	};
	$db = array( 'host' => $f( 'db_host' ), 'name' => $f( 'db_name' ), 'user' => $f( 'db_user' ), 'pass' => isset( $_POST['db_pass'] ) ? (string) $_POST['db_pass'] : '' );
	if ( '' === $db['host'] || '' === $db['name'] || '' === $db['user'] ) {
		throw new KBI_Error( 'Completa servidor, nombre de la base de datos y usuario.' );
	}
	return $db;
}

/** Conecta (crea la base si no existe y el usuario puede). Informa tablas que se van a sobrescribir. */
function kbi_test_db( array $db ) {
	$conn = kbi_db_connect( $db, false );
	if ( ! @mysqli_select_db( $conn, $db['name'] ) ) {
		if ( ! @mysqli_query( $conn, 'CREATE DATABASE ' . kbi_q( $db['name'] ) . ' CHARACTER SET utf8mb4' ) || ! @mysqli_select_db( $conn, $db['name'] ) ) {
			throw new KBI_Error( 'La base de datos "' . $db['name'] . '" no existe y este usuario no puede crearla. Créala en el panel del hosting.' );
		}
	}
	$prefix = isset( $db['prefix'] ) ? $db['prefix'] : ( isset( $_SESSION['kbi_archive'] ) ? kbi_manifest( $_SESSION['kbi_archive'] )['table_prefix'] : 'wp_' );
	$res    = mysqli_query( $conn, "SHOW TABLES LIKE '" . mysqli_real_escape_string( $conn, addcslashes( $prefix, '\\%_' ) ) . "%'" );
	$count  = $res ? mysqli_num_rows( $res ) : 0;
	$out    = array(
		'server'   => mysqli_get_server_info( $conn ),
		'existing' => $count,
		'message'  => 'Conexión correcta (' . mysqli_get_server_info( $conn ) . ').' . ( $count ? " Hay $count tablas con prefijo $prefix: las que vienen en el respaldo se reemplazarán." : ' La base está vacía.' ),
	);
	mysqli_close( $conn );
	return $out;
}

function kbi_db_connect( array $db, $select = true ) {
	$host   = $db['host'];
	$port   = null;
	$socket = null;
	if ( preg_match( '/^(.+):(\d+)$/', $host, $m ) ) {
		$host = $m[1];
		$port = (int) $m[2];
	} elseif ( preg_match( '/^(.+):(\/.+)$/', $host, $m ) ) {
		$host   = $m[1];
		$socket = $m[2];
	}
	$conn = mysqli_init();
	mysqli_options( $conn, MYSQLI_OPT_CONNECT_TIMEOUT, 10 );
	if ( ! @mysqli_real_connect( $conn, $host, $db['user'], $db['pass'], $select ? $db['name'] : null, $port, $socket ) ) {
		throw new KBI_Error( 'No se pudo conectar a la base de datos: ' . mysqli_connect_error() );
	}
	mysqli_set_charset( $conn, 'utf8mb4' );
	mysqli_query( $conn, "SET SESSION sql_mode = 'NO_AUTO_VALUE_ON_ZERO', foreign_key_checks = 0, unique_checks = 0" );
	return $conn;
}

/** Datos de conexión de un wp-config.php existente (defines con valores literales). */
function kbi_parse_wpconfig( $file ) {
	$out = array( 'host' => '', 'name' => '', 'user' => '', 'pass' => '' );
	$php = is_file( $file ) ? (string) file_get_contents( $file ) : '';
	$map = array( 'DB_HOST' => 'host', 'DB_NAME' => 'name', 'DB_USER' => 'user', 'DB_PASSWORD' => 'pass' );
	foreach ( $map as $const => $key ) {
		if ( preg_match( '/define\s*\(\s*([\'"])' . $const . '\1\s*,\s*([\'"])((?:\\\\.|(?!\2).)*)\2\s*\)/s', $php, $m ) ) {
			$out[ $key ] = stripcslashes( $m[3] );
		}
	}
	return $out;
}

function kbi_q( $name ) {
	return '`' . str_replace( '`', '``', $name ) . '`';
}

/* -------------------------------------------------------------------------
 * Utilidades
 * ---------------------------------------------------------------------- */

function kbi_is_https() {
	return ( ! empty( $_SERVER['HTTPS'] ) && 'off' !== strtolower( $_SERVER['HTTPS'] ) )
		|| ( isset( $_SERVER['HTTP_X_FORWARDED_PROTO'] ) && 'https' === strtolower( $_SERVER['HTTP_X_FORWARDED_PROTO'] ) )
		|| ( isset( $_SERVER['SERVER_PORT'] ) && 443 === (int) $_SERVER['SERVER_PORT'] );
}

/** URL de la carpeta donde está este instalador = URL nueva del sitio. */
function kbi_detect_url() {
	$dir = rtrim( str_replace( '\\', '/', dirname( $_SERVER['SCRIPT_NAME'] ) ), '/' );
	return ( kbi_is_https() ? 'https' : 'http' ) . '://' . $_SERVER['HTTP_HOST'] . $dir;
}

function kbi_checks() {
	$free = @disk_free_space( KBI_DIR );
	return array(
		array( 'label' => 'PHP 7.4 o superior', 'ok' => version_compare( PHP_VERSION, '7.4', '>=' ), 'msg' => 'Versión ' . PHP_VERSION, 'required' => true ),
		array( 'label' => 'Extensión zlib', 'ok' => function_exists( 'inflate_init' ), 'msg' => 'Necesaria para descomprimir', 'required' => true ),
		array( 'label' => 'Extensión mysqli', 'ok' => function_exists( 'mysqli_init' ), 'msg' => 'Necesaria para la base de datos', 'required' => true ),
		array( 'label' => 'Carpeta con permiso de escritura', 'ok' => is_writable( KBI_DIR ), 'msg' => KBI_DIR, 'required' => true ),
		array( 'label' => 'Espacio libre', 'ok' => false === $free || $free > 1073741824, 'msg' => false === $free ? 'No se pudo medir' : round( $free / 1073741824, 1 ) . ' GB', 'required' => false ),
	);
}

/** .zip de esta carpeta cuyo primer archivo es el manifiesto de Kombi Backup. */
function kbi_find_archives() {
	$out = array();
	foreach ( (array) glob( KBI_DIR . '/*.zip' ) as $file ) {
		if ( kbi_manifest( $file, false ) ) {
			$out[] = str_replace( '\\', '/', $file );
		}
	}
	return $out;
}

function kbi_manifest( $file, $throw = true ) {
	static $cache = array();
	if ( isset( $cache[ $file ] ) ) {
		return $cache[ $file ];
	}
	$fh = @fopen( $file, 'rb' );
	$e  = $fh ? kbi_zip_entry( $fh, 0 ) : null;
	$m  = null;
	if ( $e && 'kombi-backup/manifest.json' === $e['name'] && $e['comp'] < 1048576 ) {
		fseek( $fh, $e['data'] );
		$raw = fread( $fh, $e['comp'] );
		$m   = json_decode( (string) ( 8 === $e['method'] ? @gzinflate( $raw ) : $raw ), true );
	}
	if ( $fh ) {
		fclose( $fh );
	}
	if ( ! is_array( $m ) || empty( $m['key_hash'] ) ) {
		if ( $throw ) {
			throw new KBI_Error( 'El archivo no es un respaldo de Kombi Backup.' );
		}
		return null;
	}
	return $cache[ $file ] = $m;
}

function kbi_restore_cfg() {
	if ( ! is_file( KBI_RESTORE_FILE ) ) {
		return null;
	}
	$raw = (string) file_get_contents( KBI_RESTORE_FILE );
	$cfg = json_decode( (string) substr( $raw, (int) strpos( $raw, "\n" ) + 1 ), true );
	return is_array( $cfg ) && isset( $cfg['token_hash'], $cfg['archive'] ) ? $cfg : null;
}

function kbi_ensure_tmp() {
	if ( ! is_dir( KBI_TMP ) && ! @mkdir( KBI_TMP, 0755 ) ) {
		throw new KBI_Error( 'No se pudo crear la carpeta temporal (revisa permisos).' );
	}
	@file_put_contents( KBI_TMP . '/.htaccess', "<IfModule mod_authz_core.c>\nRequire all denied\n</IfModule>\n<IfModule !mod_authz_core.c>\nDeny from all\n</IfModule>\n" );
	@file_put_contents( KBI_TMP . '/index.php', "<?php\n" );
}

/** Estado en un .php con exit: incluye la contraseña de la base de datos. */
function kbi_state() {
	$file = KBI_TMP . '/state.php';
	if ( ! is_file( $file ) ) {
		return null;
	}
	$raw = (string) file_get_contents( $file );
	$s   = json_decode( (string) substr( $raw, (int) strpos( $raw, "\n" ) + 1 ), true );
	return is_array( $s ) ? $s : null;
}

function kbi_save_state( array $s ) {
	kbi_write( KBI_TMP . '/state.php', "<?php exit; ?>\n" . json_encode( $s, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ) );
}

function kbi_write( $file, $data ) {
	$tmp = $file . '.kbi-tmp';
	if ( false === @file_put_contents( $tmp, $data ) || ! @rename( $tmp, $file ) ) {
		@unlink( $tmp );
		throw new KBI_Error( 'No se pudo escribir ' . basename( $file ) . ' (revisa permisos).' );
	}
}

function kbi_log( array &$s, $msg ) {
	$s['log'][] = $msg;
}

/** Página de mantenimiento de WordPress mientras dura (vence sola en 1 día). */
function kbi_maintenance( $on ) {
	$file = KBI_DIR . '/.maintenance';
	if ( $on ) {
		@file_put_contents( $file, '<?php $upgrading = ' . ( time() + 86400 ) . ';' );
	} else {
		@unlink( $file );
	}
}

function kbi_rrmdir( $dir ) {
	if ( ! is_dir( $dir ) ) {
		return;
	}
	foreach ( (array) scandir( $dir ) as $f ) {
		if ( '.' !== $f && '..' !== $f ) {
			is_dir( "$dir/$f" ) ? kbi_rrmdir( "$dir/$f" ) : @unlink( "$dir/$f" );
		}
	}
	@rmdir( $dir );
}

/* -------------------------------------------------------------------------
 * Interfaz
 * ---------------------------------------------------------------------- */

function kbi_page() {
	header( 'Content-Type: text/html; charset=utf-8' );
	header( 'Cache-Control: no-store' );
	header( 'X-Robots-Tag: noindex, nofollow' );
	header( 'X-Frame-Options: DENY' );
	?>
<!doctype html>
<html lang="es">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex, nofollow">
<title>Kombi Backup · Instalador</title>
<style>
:root{--bg:#2E3440;--card:#3B4252;--line:#434C5E;--muted:#9AA5B8;--text:#D8DEE9;--head:#ECEFF4;--accent:#88C0D0;--btn:#4C6A8E;--ok:#A3BE8C;--err:#BF616A;--warn:#EBCB8B}
*{box-sizing:border-box}
body{margin:0;background:var(--bg);color:var(--text);font:16px/1.6 system-ui,-apple-system,"Segoe UI",Roboto,sans-serif}
main{max-width:720px;margin:0 auto;padding:40px 16px 64px}
.brand{display:flex;align-items:center;gap:14px;margin:0 0 10px}.logo svg{display:block;width:150px;height:auto;color:var(--head)}.brand-name{padding-left:14px;border-left:1px solid var(--line);color:var(--accent);font-size:14px;font-weight:600;letter-spacing:.08em;text-transform:uppercase}.sr{position:absolute;width:1px;height:1px;overflow:hidden;clip:rect(0 0 0 0)}
h2{color:var(--head);font-size:19px;margin:0 0 12px}
.sub{color:var(--muted);margin:0 0 28px}
.card{background:var(--card);border:1px solid var(--line);border-radius:12px;padding:22px 24px;margin-bottom:18px}
label{display:block;font-size:14px;color:var(--head);margin:14px 0 6px}
input[type=text],input[type=password],input[type=url],select{width:100%;padding:11px 12px;border-radius:8px;border:1px solid var(--line);background:var(--bg);color:var(--head);font:inherit}
input:focus,select:focus,button:focus-visible{outline:2px solid var(--accent);outline-offset:2px}
.row{display:grid;grid-template-columns:1fr 1fr;gap:0 16px}
@media(max-width:560px){.row{grid-template-columns:1fr}}
.check{display:flex;gap:10px;align-items:flex-start;margin-top:16px;font-size:14px}
.check input{margin-top:5px}
button{font:inherit;font-weight:600;border:0;border-radius:8px;padding:12px 20px;min-height:44px;cursor:pointer;background:var(--btn);color:var(--head)}
button.secondary{background:transparent;border:1px solid var(--line);color:var(--text)}
button:disabled{opacity:.5;cursor:wait}
.actions{display:flex;flex-wrap:wrap;gap:10px;margin-top:22px}
.muted{color:var(--muted);font-size:14px}
.msg{margin-top:14px;font-size:14px}.msg:empty{display:none}
.msg.ok{color:var(--ok)}.msg.err{color:var(--err)}
dl{display:grid;grid-template-columns:auto 1fr;gap:4px 16px;margin:0;font-size:14px}
dt{color:var(--muted)}dd{margin:0;color:var(--head);word-break:break-all}
ul.checks{list-style:none;padding:0;margin:0;font-size:14px}
ul.checks li::before{content:"✓ ";color:var(--ok);font-weight:700}
ul.checks li.bad::before{content:"✗ ";color:var(--err)}
ul.checks li.warn::before{content:"! ";color:var(--warn)}
.steps{list-style:none;padding:0;margin:0 0 16px}
.steps li{padding:6px 0 6px 28px;position:relative;color:var(--muted)}
.steps li::before{content:"";position:absolute;left:4px;top:13px;width:10px;height:10px;border-radius:50%;border:2px solid var(--line)}
.steps li.now{color:var(--head)}.steps li.now::before{border-color:var(--accent);background:var(--accent)}
.steps li.ok{color:var(--text)}.steps li.ok::before{border-color:var(--ok);background:var(--ok)}
progress{width:100%;height:10px;accent-color:var(--accent)}
.log{font:12px/1.5 ui-monospace,Consolas,monospace;color:var(--muted);max-height:180px;overflow:auto;padding-left:18px}
.log:empty{display:none}
.notice{border-left:4px solid var(--warn);padding:10px 14px;background:rgba(235,203,139,.08);border-radius:6px;font-size:14px;margin-top:14px}
.big-ok{color:var(--ok);font-size:20px;font-weight:700;margin:0 0 8px}
a{color:var(--accent)}
.links{display:flex;flex-wrap:wrap;gap:10px;margin-top:16px}
.links a{display:inline-block;padding:12px 20px;border-radius:8px;background:var(--btn);color:var(--head);text-decoration:none;font-weight:600}
[hidden]{display:none!important}
</style>
</head>
<body>
<main>
	<header class="brand"><span class="logo"><svg xmlns="http://www.w3.org/2000/svg" role="img" aria-label="Kombi" viewBox="0 0 244.75 50.09"><g><path fill="currentColor" d="M54.74,9.28c-1.41,0-2.56,1.15-2.56,2.56,0,.27.04.53.12.78l-1.3,1.25-2.22-10.05c-.11-.5-.56-.87-1.08-.89h.02S37.98,0,28.65,0,9.57,2.93,9.57,2.93h.12c-.53.02-.97.38-1.08.89l-2.24,10.13-1.38-1.33c.08-.25.12-.51.12-.78,0-1.41-1.15-2.56-2.56-2.56s-2.56,1.15-2.56,2.56,1.15,2.56,2.56,2.56c.3,0,.6-.06.87-.15l1.82,1.76s0,0,0,0l22.62,21.79c.22.21.5.32.78.32s.56-.11.78-.32l22.62-21.79s0,0,0,0l1.82-1.76c.27.1.56.15.87.15,1.41,0,2.56-1.15,2.56-2.56s-1.15-2.56-2.56-2.56ZM28.65,35.41l-15.86-15.28-3.97-3.82h39.65l-14.87,14.33-4.96,4.78ZM29.92,5.86c0-.72.61-1.3,1.35-1.27l14.45.52c.59.02,1.1.43,1.22,1l1.41,6.4c.17.79-.44,1.54-1.27,1.54h-15.86c-.72,0-1.3-.57-1.3-1.27v-6.92ZM9.05,12.51l1.41-6.4c.13-.57.63-.98,1.22-1l14.45-.52c.73-.03,1.35.55,1.35,1.27v6.92c0,.7-.58,1.27-1.3,1.27h-15.86c-.83,0-1.44-.75-1.27-1.54Z"/><path fill="currentColor" d="M51.27,41.68v-21.79l-22.62,21.79h11.92v2.25h-23.84v-2.25h11.92L6.03,19.89v21.79h8.45v2.25h-7.32c-.62,0-1.13.5-1.13,1.13s.5,1.13,1.13,1.13h7.32v2.64c0,.62.5,1.13,1.13,1.13s1.13-.5,1.13-1.13v-2.64h23.84v2.64c0,.62.5,1.13,1.13,1.13s1.13-.5,1.13-1.13v-2.64h7.32c.62,0,1.13-.5,1.13-1.13s-.5-1.13-1.13-1.13h-7.32v-2.25h8.45ZM9.84,27.81c.86,0,1.56.7,1.56,1.56s-.7,1.56-1.56,1.56-1.56-.7-1.56-1.56.7-1.56,1.56-1.56ZM11.4,39.43c-1.72,0-3.12-1.4-3.12-3.12s1.4-3.12,3.12-3.12,3.12,1.4,3.12,3.12-1.4,3.12-3.12,3.12ZM47.45,27.81c.86,0,1.56.7,1.56,1.56s-.7,1.56-1.56,1.56-1.56-.7-1.56-1.56.7-1.56,1.56-1.56ZM45.9,33.19c1.72,0,3.12,1.4,3.12,3.12s-1.4,3.12-3.12,3.12-3.12-1.4-3.12-3.12,1.4-3.12,3.12-3.12Z"/><g><path fill="currentColor" d="M28.65,19.89c-2.78,0-5.04,2.25-5.04,5.04s2.25,5.04,5.04,5.04,5.04-2.25,5.04-5.04-2.25-5.04-5.04-5.04ZM32.03,24.29h-2.03v1.26h2.03v.72h-2.03v2.03h-.72v-2.03h-1.26v2.03h-.72v-2.03h-2.03v-.72h2.03v-1.26h-2.03v-.72h2.03v-2.03h.72v2.03h1.26v-2.03h.72v2.03h2.03v.72Z"/><rect fill="currentColor" x="28.02" y="24.29" width="1.26" height="1.26"/></g></g><g><path fill="currentColor" d="M88.41,49.8c-1.42,0-2.61-.59-3.35-1.67l-15.31-19.96-3.56,3.47v14.29c0,1.04-.41,1.99-1.18,2.73-.77.74-1.75,1.14-2.83,1.14s-2.06-.39-2.84-1.14c-.77-.74-1.18-1.69-1.18-2.73V4.89c0-1.04.41-1.99,1.18-2.73.77-.74,1.75-1.14,2.83-1.14s2.06.39,2.83,1.14c.77.75,1.18,1.69,1.18,2.73v16.01L85.48,2.3c.48-.46.94-.78,1.43-.98.48-.2,1.03-.3,1.62-.3,1.55,0,2.44.67,2.91,1.23.61.72.92,1.62.89,2.61-.03.97-.42,1.88-1.15,2.71l-.04.05-15.65,15.07,15.95,20.97c.61.85.87,1.77.76,2.71-.1.92-.5,1.75-1.17,2.39-.71.69-1.62,1.05-2.62,1.05Z"/><path fill="currentColor" d="M110.34,50.09c-3.51,0-6.7-.78-9.48-2.31-2.79-1.54-5.02-3.68-6.61-6.37-1.59-2.68-2.4-5.76-2.4-9.14s.81-6.51,2.4-9.2c1.6-2.69,3.82-4.83,6.61-6.37,2.79-1.53,5.98-2.31,9.48-2.31s6.69.78,9.46,2.31c2.77,1.54,4.98,3.68,6.58,6.37,1.59,2.68,2.42,5.76,2.46,9.18,0,3.4-.82,6.48-2.43,9.17-1.62,2.69-3.84,4.83-6.6,6.36-2.77,1.54-5.95,2.31-9.46,2.31ZM110.34,21.66c-2.06,0-3.85.44-5.47,1.36-1.61.91-2.85,2.14-3.79,3.75-.93,1.6-1.4,3.45-1.4,5.5s.47,3.89,1.39,5.47c.93,1.59,2.17,2.81,3.79,3.73,1.62.91,3.4,1.36,5.47,1.36s3.87-.46,5.47-1.36c1.61-.91,2.84-2.13,3.75-3.72.91-1.58,1.37-3.43,1.37-5.48s-.46-3.91-1.37-5.51c-.91-1.61-2.14-2.83-3.75-3.74-1.61-.91-3.4-1.36-5.47-1.36Z"/><path fill="currentColor" d="M181.05,49.8c-1.11,0-2.09-.36-2.84-1.05-.77-.71-1.18-1.66-1.18-2.76v-16.81c0-2.38-.6-4.18-1.82-5.51-1.18-1.27-2.66-1.89-4.52-1.89-2.05,0-3.67.63-4.95,1.93-1.3,1.31-1.91,3.06-1.88,5.33l.03,1.62h-.02v15.34c0,1.08-.39,2.02-1.12,2.73-.73.71-1.71,1.08-2.84,1.08s-2.09-.36-2.84-1.05c-.77-.71-1.18-1.66-1.18-2.76v-16.81c0-2.38-.6-4.18-1.82-5.51-1.18-1.27-2.66-1.89-4.52-1.89-2.06,0-3.7.62-5.01,1.89-1.31,1.28-1.95,2.93-1.95,5.04v17.28c0,1.08-.39,2.02-1.12,2.73-.73.71-1.71,1.08-2.83,1.08-1.47,0-2.38-.59-2.89-1.08-.73-.71-1.12-1.65-1.12-2.73v-27.44c0-1.07.38-2.01,1.09-2.73.51-.52,1.44-1.14,2.92-1.14,1.14,0,2.12.39,2.86,1.13.25.26.47.54.63.85.24-.16.49-.32.74-.47,2.11-1.24,4.52-1.87,7.16-1.87s5.1.63,7.2,1.87c1.16.69,2.19,1.54,3.07,2.54.96-1.02,2.08-1.88,3.33-2.57,2.22-1.22,4.76-1.84,7.54-1.84s5.1.63,7.2,1.87c2.1,1.24,3.75,3.01,4.91,5.26,1.14,2.2,1.72,4.79,1.72,7.72v16.81c0,1.08-.39,2.02-1.12,2.73-.73.71-1.71,1.08-2.84,1.08Z"/><path fill="currentColor" d="M210.44,50.09c-3.43,0-6.57-.78-9.34-2.31-2.77-1.53-4.99-3.66-6.6-6.33-1.61-2.66-2.45-5.68-2.5-8.98V4.89c0-1.41.61-2.3,1.12-2.79.51-.49,1.43-1.08,2.89-1.08,1.12,0,2.1.37,2.83,1.08.51.49,1.12,1.38,1.12,2.79v13.16c.88-.68,1.84-1.29,2.88-1.82,2.43-1.22,5.16-1.85,8.13-1.85,3.4,0,6.5.79,9.21,2.36,2.7,1.56,4.87,3.71,6.44,6.39,1.57,2.68,2.36,5.73,2.36,9.07s-.82,6.46-2.43,9.15c-1.62,2.69-3.85,4.83-6.63,6.39-2.79,1.56-5.98,2.35-9.49,2.35ZM210.44,21.66c-2.02,0-3.79.44-5.4,1.36-1.61.91-2.84,2.13-3.75,3.74-.91,1.6-1.37,3.44-1.37,5.45s.46,3.9,1.37,5.48c.92,1.59,2.15,2.82,3.77,3.75,1.61.93,3.38,1.38,5.39,1.38s3.84-.45,5.45-1.38c1.62-.94,2.87-2.17,3.8-3.77.92-1.58,1.39-3.42,1.39-5.47s-.47-3.84-1.4-5.44c-.93-1.61-2.17-2.84-3.79-3.75-1.61-.91-3.4-1.36-5.47-1.36Z"/><path fill="currentColor" d="M239.82,49.8c-1.47,0-2.38-.59-2.89-1.08-.51-.49-1.12-1.38-1.12-2.79v-27.38c0-1.41.61-2.3,1.12-2.79.51-.49,1.43-1.08,2.89-1.08,1.12,0,2.1.37,2.83,1.08.51.49,1.12,1.38,1.12,2.79v27.38c0,1.41-.61,2.3-1.12,2.79-.73.71-1.71,1.08-2.84,1.08ZM239.76,11.79c-1.32,0-2.52-.48-3.47-1.4-.95-.91-1.45-2.09-1.45-3.4,0-1.43.54-2.64,1.56-3.5.97-.83,2.13-1.24,3.42-1.24s2.39.42,3.36,1.24c1.02.87,1.56,2.08,1.56,3.5,0,1.31-.5,2.49-1.45,3.4-.95.92-2.17,1.4-3.53,1.4Z"/></g></svg></span><span class="brand-name">Backup</span></header>
	<h1 class="sr">Kombi Backup</h1>
	<p class="sub" id="subtitle">Instalador de respaldo</p>

	<section class="card" id="s-loading"><p class="muted">Cargando…</p></section>

	<section class="card" id="s-login" hidden>
		<h2>Clave del respaldo</h2>
		<p class="muted">Está en Herramientas → Kombi Backup del sitio de origen, junto al respaldo.</p>
		<form id="f-login">
			<div id="archive-wrap" hidden>
				<label for="archive">Respaldo</label>
				<select id="archive" name="archive"></select>
			</div>
			<label for="key">Clave</label>
			<input type="text" id="key" name="key" autocomplete="off" autocapitalize="off" spellcheck="false" placeholder="xxxx-xxxx-xxxx" required>
			<div class="actions"><button type="submit">Continuar</button></div>
			<p class="msg" id="login-msg"></p>
		</form>
		<p class="msg err" id="no-archive" hidden>No encontré ningún respaldo de Kombi Backup (.zip) en esta carpeta. Súbelo junto a este archivo y recarga.</p>
	</section>

	<section id="s-setup" hidden>
		<div class="card">
			<h2>Respaldo</h2>
			<dl id="backup-info"></dl>
		</div>
		<div class="card">
			<h2>Servidor</h2>
			<ul class="checks" id="checks"></ul>
		</div>
		<form class="card" id="f-setup">
			<h2 id="setup-title">Datos del sitio nuevo</h2>
			<label for="url">URL del sitio</label>
			<input type="url" id="url" name="url" required>
			<p class="muted" id="url-help">Se detectó desde esta dirección. Todas las URLs del sitio antiguo se cambiarán por esta.</p>

			<div id="db-form">
				<div class="row">
					<div><label for="db_host">Servidor de base de datos</label><input type="text" id="db_host" name="db_host" value="localhost" required></div>
					<div><label for="db_name">Nombre de la base de datos</label><input type="text" id="db_name" name="db_name" required></div>
					<div><label for="db_user">Usuario</label><input type="text" id="db_user" name="db_user" autocomplete="off" required></div>
					<div><label for="db_pass">Contraseña</label><input type="password" id="db_pass" name="db_pass" autocomplete="new-password"></div>
				</div>
				<p class="muted">Prefijo de tablas: <code id="prefix"></code> (el mismo del respaldo).</p>
				<label class="check"><input type="checkbox" id="delete_archive" name="delete_archive" value="1" checked> Borrar el .zip del respaldo al terminar</label>
			</div>
			<div id="db-config" hidden>
				<p class="muted">Base de datos: la de <code>wp-config.php</code> de este sitio (<code id="db-config-name"></code>). Se conservan <code>wp-config.php</code>, <code>.htaccess</code> y la URL.</p>
			</div>

			<div class="notice" id="warn" hidden></div>
			<div class="actions">
				<button type="button" class="secondary" id="b-test">Probar conexión</button>
				<button type="submit" id="b-start">Instalar</button>
			</div>
			<p class="msg" id="setup-msg"></p>
		</form>
	</section>

	<section class="card" id="s-run" hidden>
		<h2 id="run-title">Instalando…</h2>
		<ul class="steps" id="steps">
			<li data-stage="extract">Extraer archivos</li>
			<li data-stage="import">Importar base de datos</li>
			<li data-stage="replace">Cambiar URLs y rutas</li>
			<li data-stage="config">Ajustar configuración</li>
		</ul>
		<progress id="bar" max="100" value="0"></progress>
		<p class="muted" id="run-label"></p>
		<ul class="log" id="run-log"></ul>
		<p class="msg err" id="run-msg"></p>
		<div class="actions" id="retry-wrap" hidden><button type="button" id="b-retry">Reintentar</button></div>
	</section>

	<section class="card" id="s-done" hidden>
		<p class="big-ok">Listo: el sitio quedó funcionando.</p>
		<p class="muted">El instalador y los archivos temporales ya se borraron. Inicia sesión con un usuario del respaldo.</p>
		<ul class="log" id="done-log"></ul>
		<div class="links"><a id="l-home" href="#">Ver el sitio</a><a id="l-admin" href="#">Ir al escritorio</a></div>
		<p class="muted" style="margin-top:16px">Si el sitio usa una URL de acceso oculta (WPS Hide Login u otro), entra por esa misma dirección.</p>
	</section>
</main>
<script>
(function () {
	'use strict';
	var $ = function (id) { return document.getElementById(id); };
	var sleep = function (ms) { return new Promise(function (r) { setTimeout(r, ms); }); };
	var info = null;

	function api(action, form) {
		var fd = form instanceof HTMLFormElement ? new FormData(form) : new FormData();
		if (form && !(form instanceof HTMLFormElement)) { Object.keys(form).forEach(function (k) { fd.append(k, form[k]); }); }
		fd.append('action', action);
		return fetch(location.pathname, { method: 'POST', body: fd, credentials: 'same-origin' }).then(function (r) {
			return r.json().catch(function () { var e = new Error('Respuesta inválida del servidor (' + r.status + '). Puede ser un límite de tiempo: se reintentará.'); e.retry = true; throw e; });
		}, function () { var e = new Error('Sin conexión con el servidor'); e.retry = true; throw e; }).then(function (j) {
			if (!j.ok) { var e = new Error(j.error); e.retry = false; throw e; }
			return j;
		});
	}
	function show(id) {
		['s-loading', 's-login', 's-setup', 's-run', 's-done'].forEach(function (s) { $(s).hidden = s !== id; });
	}
	function msg(el, text, ok) { $(el).textContent = text || ''; $(el).className = 'msg ' + (ok ? 'ok' : 'err'); }
	function lines(el, arr) {
		$(el).innerHTML = '';
		(arr || []).forEach(function (l) { var li = document.createElement('li'); li.textContent = l; $(el).appendChild(li); });
	}
	function size(b) { return b > 1073741824 ? (b / 1073741824).toFixed(2) + ' GB' : (b / 1048576).toFixed(1) + ' MB'; }

	function load() {
		return api('info').then(function (r) {
			info = r;
			if (!r.auth) {
				show('s-login');
				if (r.restore_pending) { $('subtitle').textContent = 'Restauración: vuelve a abrir el enlace desde Herramientas → Kombi Backup.'; }
				if (!r.archives.length) { $('f-login').hidden = true; $('no-archive').hidden = false; return; }
				if (r.archives.length > 1) {
					$('archive-wrap').hidden = false;
					r.archives.forEach(function (a) { var o = document.createElement('option'); o.textContent = a; $('archive').appendChild(o); });
				}
				$('key').focus();
				return;
			}
			if (r.running) { return run(r.running); }
			setup(r);
		}).catch(function (e) { show('s-login'); msg('login-msg', e.message); });
	}

	function setup(r) {
		var restore = r.mode === 'restore';
		show('s-setup');
		$('subtitle').textContent = restore ? 'Restaurar respaldo sobre este sitio' : 'Instalar respaldo en este servidor';
		var b = r.backup, dl = $('backup-info');
		dl.innerHTML = '';
		[['Sitio de origen', b.home], ['Creado', new Date(b.created).toLocaleString('es-CL')], ['WordPress', b.wp], ['Archivos', Number(b.files).toLocaleString('es-CL')], ['Tamaño', size(b.size)], ['Archivo', b.archive]].forEach(function (p) {
			var dt = document.createElement('dt'), dd = document.createElement('dd');
			dt.textContent = p[0]; dd.textContent = p[1]; dl.appendChild(dt); dl.appendChild(dd);
		});
		$('checks').innerHTML = '';
		r.checks.forEach(function (c) {
			var li = document.createElement('li');
			li.className = c.ok ? '' : (c.required ? 'bad' : 'warn');
			li.textContent = c.label + ' — ' + c.msg;
			$('checks').appendChild(li);
		});
		$('url').value = r.url;
		$('prefix').textContent = r.db.prefix;
		if (restore) {
			$('setup-title').textContent = 'Restaurar sobre este sitio';
			$('url').readOnly = true;
			$('url-help').textContent = 'Se conserva la URL de este sitio; las del respaldo se cambian por esta.';
			$('b-start').textContent = 'Restaurar';
			$('warn').hidden = false;
			$('warn').textContent = 'Se reemplazarán todos los archivos y la base de datos de este sitio por los del respaldo.';
		}
		if (restore && r.db.from_config) {
			$('db-form').hidden = true;
			$('db-config').hidden = false;
			$('db-config-name').textContent = r.db.name + ' @ ' + r.db.host;
			$('db-form').querySelectorAll('input').forEach(function (i) { i.required = false; });
		} else {
			$('db_host').value = r.db.host || 'localhost';
			$('db_name').value = r.db.name || '';
			$('db_user').value = r.db.user || '';
			if (restore) { $('delete_archive').parentNode.hidden = true; $('delete_archive').checked = false; }
		}
	}

	$('f-login').addEventListener('submit', function (ev) {
		ev.preventDefault();
		msg('login-msg', 'Verificando…', true);
		api('login', $('f-login')).then(load).catch(function (e) { msg('login-msg', e.message); });
	});

	$('b-test').addEventListener('click', function () {
		msg('setup-msg', 'Probando…', true);
		var restoreCfg = info.mode === 'restore' && info.db.from_config;
		if (restoreCfg) { msg('setup-msg', 'Se usará la conexión de wp-config.php.', true); return; }
		api('testdb', $('f-setup')).then(function (r) { msg('setup-msg', r.message, true); }).catch(function (e) { msg('setup-msg', e.message); });
	});

	$('f-setup').addEventListener('submit', function (ev) {
		ev.preventDefault();
		var restore = info.mode === 'restore';
		if (!confirm(restore ? '¿Reemplazar este sitio por el respaldo?' : '¿Instalar el respaldo en ' + $('url').value + '?')) { return; }
		$('b-start').disabled = true;
		msg('setup-msg', 'Comenzando…', true);
		api('start', $('f-setup')).then(run).catch(function (e) { $('b-start').disabled = false; msg('setup-msg', e.message); });
	});

	function paint(p) {
		var order = ['extract', 'import', 'replace', 'config', 'done'], at = order.indexOf(p.stage);
		document.querySelectorAll('#steps li').forEach(function (li) {
			var i = order.indexOf(li.dataset.stage);
			li.className = i < at ? 'ok' : (i === at ? 'now' : '');
		});
		$('bar').value = p.percent;
		$('run-label').textContent = Math.round(p.percent) + '% · ' + p.label;
		lines('run-log', p.log);
	}

	function run(p) {
		show('s-run');
		$('run-title').textContent = p.mode === 'restore' ? 'Restaurando…' : 'Instalando…';
		$('retry-wrap').hidden = true;
		msg('run-msg', '');
		paint(p);
		var fails = 0;
		var loop = function () {
			if (p.done) { return Promise.resolve(p); }
			return api('step').then(function (np) {
				fails = 0; p = np; paint(p);
				return sleep(p.busy ? 1500 : 30).then(loop);
			}, function (e) {
				if (!e.retry || ++fails > 8) { throw e; }
				$('run-label').textContent = e.message;
				return sleep(1500 * fails).then(loop);
			});
		};
		return loop().then(function (p) {
			show('s-done');
			$('l-home').href = p.home;
			$('l-admin').href = p.admin;
			lines('done-log', p.log);
		}).catch(function (e) {
			msg('run-msg', e.message);
			$('retry-wrap').hidden = false;
		});
	}
	$('b-retry').addEventListener('click', function () { api('info').then(function (r) { run(r.running || { stage: 'extract', percent: 0, label: '', log: [] }); }); });

	load();
}());
</script>
</body>
</html>
	<?php
}
