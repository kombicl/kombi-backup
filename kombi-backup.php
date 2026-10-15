<?php
/**
 * Plugin Name:       Kombi Backup
 * Plugin URI:        https://github.com/kombicl/kombi-backup
 * Description:       Respaldo completo (archivos + base de datos) en un .zip, instalador para levantarlo en otro servidor con la URL nueva, y restauración de un respaldo sobre este sitio.
 * Version:           0.1.0
 * Requires at least: 6.0
 * Requires PHP:      7.4
 * Author:            Kombi Consultoría TI
 * Author URI:        https://kombi.cl
 * License:           GPL-2.0-or-later
 * Text Domain:       kombi-backup
 * Update URI:        https://github.com/kombicl/kombi-backup
 *
 * @package Kombi_Backup
 */

defined( 'ABSPATH' ) || exit;

define( 'KOMBI_BACKUP_VERSION', '0.1.0' );
define( 'KOMBI_BACKUP_DIR', plugin_dir_path( __FILE__ ) );
define( 'KOMBI_BACKUP_URL', plugin_dir_url( __FILE__ ) );

require KOMBI_BACKUP_DIR . 'includes/functions.php';
require KOMBI_BACKUP_DIR . 'includes/class-zip-writer.php';
require KOMBI_BACKUP_DIR . 'includes/class-db-dumper.php';
require KOMBI_BACKUP_DIR . 'includes/class-job.php';
require KOMBI_BACKUP_DIR . 'includes/class-restore.php';
require KOMBI_BACKUP_DIR . 'includes/class-updater.php';

Kombi_Backup_Updater::init();

if ( is_admin() ) {
	require KOMBI_BACKUP_DIR . 'includes/class-admin.php';
	Kombi_Backup_Admin::init();
}
