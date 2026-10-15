<?php
/**
 * Actualizaciones desde GitHub Releases.
 *
 * El plugin declara "Update URI: https://github.com/kombicl/kombi-backup", así
 * WordPress no lo busca en wordpress.org y pregunta con el filtro
 * update_plugins_github.com. La versión nueva se lee de info.json, adjunto en
 * cada release que publica la GitHub Action (.github/workflows/release.yml):
 * es una descarga normal, sin el límite de 60 consultas/hora de la API de
 * GitHub (muchos sitios en un mismo hosting comparten IP).
 *
 * Las actualizaciones automáticas vienen activadas. Para desactivarlas:
 * define( 'KOMBI_BACKUP_AUTO_UPDATE', false ); en wp-config.php.
 *
 * @package Kombi_Backup
 */

defined( 'ABSPATH' ) || exit;

class Kombi_Backup_Updater {

	const REPO      = 'kombicl/kombi-backup';
	const INFO_URL  = 'https://github.com/kombicl/kombi-backup/releases/latest/download/info.json';
	const CACHE     = 'kombi_backup_update_info';
	const CACHE_TTL = 6 * HOUR_IN_SECONDS;

	public static function init() {
		// Una copia de desarrollo (con .git) nunca se actualiza sola: WordPress borraría el repositorio.
		if ( is_dir( KOMBI_BACKUP_DIR . '.git' ) ) {
			return;
		}
		add_filter( 'update_plugins_github.com', array( __CLASS__, 'check' ), 10, 3 );
		add_filter( 'plugins_api', array( __CLASS__, 'details' ), 10, 3 );
		add_filter( 'auto_update_plugin', array( __CLASS__, 'auto_update' ), 10, 2 );
		add_filter( 'upgrader_source_selection', array( __CLASS__, 'fix_folder' ), 10, 4 );
		add_action( 'upgrader_process_complete', array( __CLASS__, 'flush' ) );
		add_action( 'load-update-core.php', array( __CLASS__, 'flush_on_force_check' ) );
	}

	private static function basename() {
		return plugin_basename( KOMBI_BACKUP_DIR . 'kombi-backup.php' );
	}

	/** info.json del último release (en caché; si GitHub falla, se reintenta en 1 hora). */
	private static function info() {
		$info = get_site_transient( self::CACHE );
		if ( is_array( $info ) ) {
			return $info;
		}
		$res  = wp_remote_get( self::INFO_URL, array( 'timeout' => 10, 'headers' => array( 'Accept' => 'application/json' ) ) );
		$info = json_decode( (string) wp_remote_retrieve_body( $res ), true );
		if ( is_wp_error( $res ) || 200 !== wp_remote_retrieve_response_code( $res ) || empty( $info['version'] ) || empty( $info['package'] ) ) {
			set_site_transient( self::CACHE, array(), HOUR_IN_SECONDS );
			return array();
		}
		set_site_transient( self::CACHE, $info, self::CACHE_TTL );
		return $info;
	}

	/** Respuesta para WordPress: si la versión es mayor, aparece como actualización. */
	public static function check( $update, $plugin_data, $plugin_file ) {
		if ( self::basename() !== $plugin_file ) {
			return $update;
		}
		$info = self::info();
		if ( ! $info ) {
			return $update;
		}
		return array(
			'id'           => 'github.com/' . self::REPO,
			'slug'         => 'kombi-backup',
			'version'      => $info['version'],
			'url'          => 'https://github.com/' . self::REPO,
			'package'      => $info['package'],
			'requires'     => isset( $info['requires'] ) ? $info['requires'] : '6.0',
			'requires_php' => isset( $info['requires_php'] ) ? $info['requires_php'] : '7.4',
			'tested'       => isset( $info['tested'] ) ? $info['tested'] : get_bloginfo( 'version' ),
			'icons'        => array( 'svg' => 'https://raw.githubusercontent.com/' . self::REPO . '/main/assets/kombi-logo.svg' ),
		);
	}

	/** Ventana "Ver detalles" de la actualización. */
	public static function details( $result, $action, $args ) {
		if ( 'plugin_information' !== $action || empty( $args->slug ) || 'kombi-backup' !== $args->slug ) {
			return $result;
		}
		$info = self::info();
		if ( ! $info ) {
			return $result;
		}
		return (object) array(
			'name'          => 'Kombi Backup',
			'slug'          => 'kombi-backup',
			'version'       => $info['version'],
			'author'        => '<a href="https://kombi.cl">Kombi Consultoría TI</a>',
			'homepage'      => 'https://github.com/' . self::REPO,
			'download_link' => $info['package'],
			'requires'      => isset( $info['requires'] ) ? $info['requires'] : '6.0',
			'requires_php'  => isset( $info['requires_php'] ) ? $info['requires_php'] : '7.4',
			'last_updated'  => isset( $info['date'] ) ? $info['date'] : '',
			'sections'      => array(
				'description' => 'Respaldo completo (archivos + base de datos) en un .zip, instalador para levantarlo en otro servidor y restauración sobre este sitio.',
				'changelog'   => isset( $info['changelog'] ) ? wpautop( esc_html( $info['changelog'] ) ) : '',
			),
		);
	}

	/** Actualización automática activada por defecto (KOMBI_BACKUP_AUTO_UPDATE = false la apaga). */
	public static function auto_update( $update, $item ) {
		if ( isset( $item->plugin ) && self::basename() === $item->plugin ) {
			return ! ( defined( 'KOMBI_BACKUP_AUTO_UPDATE' ) && ! KOMBI_BACKUP_AUTO_UPDATE );
		}
		return $update;
	}

	/** El .zip trae la carpeta kombi-backup/; si llegara con otro nombre, se corrige. */
	public static function fix_folder( $source, $remote_source, $upgrader, $hook_extra = array() ) {
		global $wp_filesystem;
		if ( empty( $hook_extra['plugin'] ) || self::basename() !== $hook_extra['plugin'] ) {
			return $source;
		}
		$wanted = trailingslashit( $remote_source ) . 'kombi-backup/';
		if ( untrailingslashit( $source ) === untrailingslashit( $wanted ) ) {
			return $source;
		}
		return $wp_filesystem && $wp_filesystem->move( $source, $wanted, true ) ? $wanted : $source;
	}

	public static function flush() {
		delete_site_transient( self::CACHE );
	}

	/** "Comprobar de nuevo" en Escritorio → Actualizaciones consulta GitHub al momento. */
	public static function flush_on_force_check() {
		if ( isset( $_GET['force-check'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- solo limpia caché.
			self::flush();
		}
	}
}
