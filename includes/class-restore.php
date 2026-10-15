<?php
/**
 * Restaurar un respaldo sobre este mismo sitio.
 *
 * WordPress no puede reemplazarse a sí mismo mientras corre: se copia el
 * instalador a la raíz con un token de un solo uso y la restauración ocurre
 * fuera de WordPress. Se conservan wp-config.php y .htaccess de este servidor
 * y la URL de este sitio.
 *
 * @package Kombi_Backup
 */

defined( 'ABSPATH' ) || exit;

class Kombi_Backup_Restore {

	const INSTALLER = 'kombi-installer.php';
	const CONFIG    = 'kombi-restore.php';
	const SIGNATURE = 'KOMBI_BACKUP_INSTALLER';

	/** Prepara la restauración y devuelve la URL del instalador. */
	public static function prepare( Kombi_Backup_Job $job ) {
		if ( 'done' !== $job->meta['status'] || ! is_file( $job->zip_path() ) ) {
			throw new RuntimeException( 'Ese respaldo no está completo.' );
		}
		$token = bin2hex( random_bytes( 16 ) );
		kombi_backup_write_php_json(
			ABSPATH . self::CONFIG,
			array(
				'mode'       => 'restore',
				'token_hash' => hash( 'sha256', $token ),
				'expires'    => time() + HOUR_IN_SECONDS,
				'archive'    => wp_normalize_path( $job->zip_path() ),
				'home'       => home_url(),
				'siteurl'    => site_url(),
				'user'       => wp_get_current_user()->user_login,
			)
		);
		if ( ! @copy( KOMBI_BACKUP_DIR . 'installer/kombi-installer.php', ABSPATH . self::INSTALLER ) ) {
			@unlink( ABSPATH . self::CONFIG );
			throw new RuntimeException( 'No se pudo copiar el instalador a la raíz del sitio (revisa permisos).' );
		}
		return site_url( '/' . self::INSTALLER . '?restore=' . $token );
	}

	/**
	 * Borra un instalador que quedó abandonado en la raíz (restauración que no
	 * se completó). Solo toca archivos con la firma de este plugin.
	 */
	public static function cleanup_stale() {
		$file = ABSPATH . self::INSTALLER;
		if ( ! is_file( $file ) || false === strpos( (string) file_get_contents( $file, false, null, 0, 2048 ), self::SIGNATURE ) ) {
			return;
		}
		$cfg = kombi_backup_read_php_json( ABSPATH . self::CONFIG );
		if ( $cfg && $cfg['expires'] > time() - DAY_IN_SECONDS ) {
			return;
		}
		@unlink( $file );
		@unlink( ABSPATH . self::CONFIG );
	}
}
