<?php
/**
 * Almacenamiento de respaldos.
 *
 * @package Kombi_Backup
 */

defined( 'ABSPATH' ) || exit;

/**
 * wp-content/kombi-backups/, protegida contra acceso web (Apache/LiteSpeed/IIS).
 * En nginx no aplica .htaccess: por eso los nombres llevan un sufijo aleatorio
 * y los metadatos son .php que terminan en exit.
 */
function kombi_backup_storage_dir() {
	$dir = WP_CONTENT_DIR . '/kombi-backups';
	if ( ! is_dir( $dir ) ) {
		wp_mkdir_p( $dir );
	}
	$guards = array(
		'.htaccess'  => "<IfModule mod_authz_core.c>\nRequire all denied\n</IfModule>\n<IfModule !mod_authz_core.c>\nDeny from all\n</IfModule>\n",
		'index.php'  => "<?php\n// Silencio.\n",
		'web.config' => "<?xml version=\"1.0\"?>\n<configuration><system.webServer><authorization><deny users=\"*\" /></authorization></system.webServer></configuration>\n",
	);
	foreach ( $guards as $name => $content ) {
		if ( ! file_exists( "$dir/$name" ) ) {
			@file_put_contents( "$dir/$name", $content );
		}
	}
	return $dir;
}

/** JSON dentro de un .php que no imprime nada si se pide por web. */
function kombi_backup_write_php_json( $file, $data ) {
	$tmp = $file . '.tmp';
	if ( false === file_put_contents( $tmp, "<?php exit; ?>\n" . wp_json_encode( $data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ) ) || ! @rename( $tmp, $file ) ) {
		@unlink( $tmp );
		throw new RuntimeException( 'No se pudo guardar el estado del respaldo en ' . dirname( $file ) . '.' );
	}
}

function kombi_backup_read_php_json( $file ) {
	if ( ! is_file( $file ) ) {
		return null;
	}
	$raw = (string) file_get_contents( $file );
	$pos = strpos( $raw, "\n" );
	$out = false === $pos ? null : json_decode( substr( $raw, $pos + 1 ), true );
	return is_array( $out ) ? $out : null;
}
