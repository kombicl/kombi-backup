<?php
/**
 * Pantalla Herramientas → Kombi Backup y sus endpoints AJAX.
 *
 * @package Kombi_Backup
 */

defined( 'ABSPATH' ) || exit;

class Kombi_Backup_Admin {

	const CAP  = 'manage_options';
	const SLUG = 'kombi-backup';

	public static function init() {
		add_action( 'admin_menu', array( __CLASS__, 'menu' ) );
		foreach ( array( 'start', 'step', 'delete', 'upload', 'restore', 'settings' ) as $action ) {
			add_action( 'wp_ajax_kombi_backup_' . $action, array( __CLASS__, 'ajax_' . $action ) );
		}
		add_action( 'admin_post_kombi_backup_download', array( __CLASS__, 'download' ) );
		add_filter( 'plugin_action_links_' . plugin_basename( KOMBI_BACKUP_DIR . 'kombi-backup.php' ), array( __CLASS__, 'action_links' ) );
	}

	public static function menu() {
		$hook = add_management_page( 'Kombi Backup', 'Kombi Backup', self::CAP, self::SLUG, array( __CLASS__, 'render' ) );
		add_action( 'load-' . $hook, array( __CLASS__, 'load' ) );
	}

	public static function action_links( $links ) {
		array_unshift( $links, '<a href="' . esc_url( admin_url( 'tools.php?page=' . self::SLUG ) ) . '">Respaldos</a>' );
		return $links;
	}

	public static function load() {
		Kombi_Backup_Restore::cleanup_stale();
		wp_enqueue_style( 'kombi-backup', KOMBI_BACKUP_URL . 'assets/admin.css', array(), KOMBI_BACKUP_VERSION );
		wp_enqueue_script( 'kombi-backup', KOMBI_BACKUP_URL . 'assets/admin.js', array(), KOMBI_BACKUP_VERSION, true );
		$max = wp_max_upload_size();
		wp_localize_script(
			'kombi-backup',
			'KombiBackup',
			array(
				'ajax'  => admin_url( 'admin-ajax.php' ),
				'nonce' => wp_create_nonce( 'kombi_backup' ),
				// Trozos bajo el límite de subida de PHP: así se pueden subir respaldos de varios GB.
				'chunk' => (int) max( 262144, min( 8388608, $max ? floor( $max * 0.8 ) : 1048576 ) ),
			)
		);
	}

	/* ---------------------------------------------------------------------
	 * Pantalla
	 * ------------------------------------------------------------------ */

	public static function render() {
		$jobs     = Kombi_Backup_Job::all();
		$excludes = implode( "\n", Kombi_Backup_Job::excludes() );
		$dl       = wp_nonce_url( admin_url( 'admin-post.php?action=kombi_backup_download' ), 'kombi_backup_download' );
		?>
		<div class="wrap kb-wrap">
			<h1>Kombi Backup</h1>
			<p class="kb-lead">Respaldo completo de este WordPress (archivos y base de datos) en un solo <code>.zip</code>. Con el instalador se levanta en otro servidor; desde aquí también puedes restaurar un respaldo sobre este sitio.</p>

			<?php if ( is_multisite() ) : ?>
				<div class="notice notice-error"><p>Multisitio todavía no está soportado.</p></div>
			<?php endif; ?>

			<div class="kb-cards">
				<section class="kb-card">
					<h2>Crear respaldo</h2>
					<p>Incluye todo lo que hay en la carpeta de WordPress y las tablas con prefijo <code><?php echo esc_html( $GLOBALS['wpdb']->prefix ); ?></code>. Puedes seguir usando el sitio mientras se crea.</p>
					<p><button type="button" class="button button-primary button-hero" id="kb-start">Crear respaldo ahora</button></p>
				</section>
				<section class="kb-card">
					<h2>Subir un respaldo</h2>
					<p>Sube un <code>.zip</code> creado con Kombi Backup en otro sitio (por ejemplo, la versión nueva preparada en local) para restaurarlo aquí.</p>
					<p><input type="file" id="kb-file" accept=".zip,application/zip"> <button type="button" class="button" id="kb-upload">Subir</button></p>
				</section>
			</div>

			<div class="kb-progress" id="kb-progress" hidden>
				<div class="kb-progress__head"><strong id="kb-progress-title">Creando respaldo…</strong> <span id="kb-progress-pct">0%</span></div>
				<progress id="kb-progress-bar" max="100" value="0"></progress>
				<p id="kb-progress-label" class="kb-muted"></p>
				<ul id="kb-progress-log" class="kb-log"></ul>
			</div>

			<h2 class="kb-h2">Respaldos</h2>
			<?php if ( ! $jobs ) : ?>
				<p class="kb-muted">Todavía no hay respaldos.</p>
			<?php else : ?>
				<table class="widefat striped kb-table">
					<thead><tr><th>Fecha</th><th>Origen</th><th>Tamaño</th><th>Clave del instalador</th><th>Acciones</th></tr></thead>
					<tbody>
					<?php foreach ( $jobs as $job ) : ?>
						<?php
						$m    = $job->meta;
						$link = add_query_arg( 'id', $job->id, $dl );
						?>
						<tr data-id="<?php echo esc_attr( $job->id ); ?>">
							<td><?php echo esc_html( wp_date( 'j M Y, H:i', $m['created'] ) ); ?></td>
							<td>
								<?php echo esc_html( preg_replace( '#^https?://#', '', $m['home'] ) ); ?>
								<?php if ( 'upload' === $m['origin'] ) : ?>
									<span class="kb-tag">subido</span>
								<?php endif; ?>
								<?php if ( ! empty( $m['wp'] ) ) : ?>
									<br><span class="kb-muted">WordPress <?php echo esc_html( $m['wp'] ); ?></span>
								<?php endif; ?>
							</td>
							<?php if ( 'done' === $m['status'] ) : ?>
								<td><?php echo esc_html( size_format( $m['size'], 1 ) ); ?></td>
								<td>
									<?php if ( $m['key'] ) : ?>
										<code class="kb-key"><?php echo esc_html( $m['key'] ); ?></code>
										<button type="button" class="button-link kb-copy" data-copy="<?php echo esc_attr( $m['key'] ); ?>">Copiar</button>
									<?php else : ?>
										<span class="kb-muted">La del sitio de origen</span>
									<?php endif; ?>
								</td>
								<td class="kb-actions">
									<a class="button button-small" href="<?php echo esc_url( add_query_arg( 'what', 'zip', $link ) ); ?>">Descargar respaldo</a>
									<a class="button button-small" href="<?php echo esc_url( add_query_arg( 'what', 'installer', $link ) ); ?>">Descargar instalador</a>
									<button type="button" class="button button-small kb-restore">Restaurar aquí</button>
									<button type="button" class="button-link kb-delete">Eliminar</button>
								</td>
							<?php else : ?>
								<td colspan="2">
									<?php if ( 'error' === $m['status'] ) : ?>
										<span class="kb-error">Error: <?php echo esc_html( $m['message'] ); ?></span>
									<?php else : ?>
										<span class="kb-muted">Sin terminar (se interrumpió o sigue en curso)</span>
									<?php endif; ?>
								</td>
								<td class="kb-actions">
									<?php if ( 'running' === $m['status'] ) : ?>
										<button type="button" class="button button-small kb-resume">Continuar</button>
									<?php endif; ?>
									<button type="button" class="button-link kb-delete">Eliminar</button>
								</td>
							<?php endif; ?>
						</tr>
						<?php if ( 'done' === $m['status'] && ! empty( $m['log'] ) ) : ?>
							<tr class="kb-notes"><td colspan="5"><details><summary><?php echo esc_html( sprintf( '%d avisos', count( $m['log'] ) ) ); ?></summary><ul class="kb-log"><?php foreach ( $m['log'] as $line ) : ?><li><?php echo esc_html( $line ); ?></li><?php endforeach; ?></ul></details></td></tr>
						<?php endif; ?>
					<?php endforeach; ?>
					</tbody>
				</table>
			<?php endif; ?>

			<div class="kb-cards">
				<section class="kb-card">
					<h2>Llevar el sitio a otro servidor</h2>
					<ol>
						<li>Descarga el <strong>respaldo</strong> y el <strong>instalador</strong> (<code>kombi-installer.php</code>).</li>
						<li>Súbelos a la carpeta vacía donde irá el sitio (por ejemplo <code>public_html</code>).</li>
						<li>Crea una base de datos vacía en el hosting.</li>
						<li>Abre <code>https://tu-dominio/kombi-installer.php</code>, escribe la clave del respaldo y los datos de la base de datos.</li>
						<li>El instalador extrae todo, importa la base, cambia la URL antigua por la nueva y se borra solo al terminar.</li>
					</ol>
				</section>
				<section class="kb-card">
					<h2>Excluir del respaldo</h2>
					<p class="kb-muted">Una ruta por línea, relativa a la carpeta de WordPress. Siempre se omiten <code>.git</code>, <code>node_modules</code> y los propios respaldos.</p>
					<textarea id="kb-excludes" rows="8" class="large-text code"><?php echo esc_textarea( $excludes ); ?></textarea>
					<p><button type="button" class="button" id="kb-save-excludes">Guardar</button> <span id="kb-excludes-msg" class="kb-muted"></span></p>
				</section>
			</div>

			<dialog id="kb-restore-dialog" class="kb-dialog">
				<form method="dialog">
					<h2>¿Restaurar este respaldo aquí?</h2>
					<p>Se reemplazarán <strong>todos los archivos y la base de datos</strong> de <code><?php echo esc_html( home_url() ); ?></code> por los del respaldo. Se conservan <code>wp-config.php</code>, <code>.htaccess</code> y la URL de este sitio.</p>
					<p>Mientras dura, el sitio muestra la página de mantenimiento. Al terminar tendrás que iniciar sesión con un usuario del respaldo.</p>
					<p><label><input type="checkbox" id="kb-safety" checked> Crear antes un respaldo de seguridad del sitio actual (recomendado)</label></p>
					<div class="kb-dialog__actions">
						<button value="cancel" class="button">Cancelar</button>
						<button value="ok" class="button button-primary kb-danger">Sí, restaurar</button>
					</div>
				</form>
			</dialog>
		</div>
		<?php
	}

	/* ---------------------------------------------------------------------
	 * AJAX
	 * ------------------------------------------------------------------ */

	private static function guard() {
		if ( ! check_ajax_referer( 'kombi_backup', 'nonce', false ) || ! current_user_can( self::CAP ) ) {
			wp_send_json_error( array( 'message' => 'Sesión vencida. Recarga la página.' ), 403 );
		}
	}

	private static function job_or_die() {
		$job = Kombi_Backup_Job::load( isset( $_POST['id'] ) ? sanitize_text_field( wp_unslash( $_POST['id'] ) ) : '' );
		if ( ! $job ) {
			wp_send_json_error( array( 'message' => 'Respaldo no encontrado.' ), 404 );
		}
		return $job;
	}

	public static function ajax_start() {
		self::guard();
		try {
			$job = Kombi_Backup_Job::create();
			wp_send_json_success( $job->run() );
		} catch ( Throwable $e ) {
			wp_send_json_error( array( 'message' => $e->getMessage() ) );
		}
	}

	public static function ajax_step() {
		self::guard();
		$progress = self::job_or_die()->run();
		if ( 'error' === $progress['status'] ) {
			wp_send_json_error( array( 'message' => $progress['message'] ) );
		}
		wp_send_json_success( $progress );
	}

	public static function ajax_delete() {
		self::guard();
		self::job_or_die()->delete();
		wp_send_json_success();
	}

	public static function ajax_restore() {
		self::guard();
		try {
			wp_send_json_success( array( 'url' => Kombi_Backup_Restore::prepare( self::job_or_die() ) ) );
		} catch ( Throwable $e ) {
			wp_send_json_error( array( 'message' => $e->getMessage() ) );
		}
	}

	public static function ajax_settings() {
		self::guard();
		$list = Kombi_Backup_Job::parse_excludes( isset( $_POST['excludes'] ) ? wp_unslash( $_POST['excludes'] ) : '' );
		update_option( 'kombi_backup_excludes', implode( "\n", $list ), false );
		wp_send_json_success( array( 'excludes' => implode( "\n", $list ) ) );
	}

	/** Subida por trozos: cada trozo se anexa; el último valida y registra el respaldo. */
	public static function ajax_upload() {
		self::guard();
		$upload = isset( $_POST['upload'] ) ? preg_replace( '/[^a-z0-9]/', '', strtolower( wp_unslash( $_POST['upload'] ) ) ) : '';
		$index  = isset( $_POST['index'] ) ? (int) $_POST['index'] : -1;
		$total  = isset( $_POST['total'] ) ? (int) $_POST['total'] : 0;
		if ( strlen( $upload ) < 16 || $index < 0 || $index >= $total || empty( $_FILES['chunk']['tmp_name'] ) || UPLOAD_ERR_OK !== $_FILES['chunk']['error'] ) {
			wp_send_json_error( array( 'message' => 'Trozo de subida inválido.' ), 400 );
		}
		$part = kombi_backup_storage_dir() . '/upload-' . $upload . '.part';
		if ( 0 === $index ) {
			@unlink( $part );
		} elseif ( ! is_file( $part ) ) {
			wp_send_json_error( array( 'message' => 'La subida se perdió; vuelve a intentarlo.' ), 400 );
		}
		$in  = fopen( $_FILES['chunk']['tmp_name'], 'rb' );
		$out = fopen( $part, 'ab' );
		stream_copy_to_stream( $in, $out );
		fclose( $in );
		fclose( $out );
		if ( $index < $total - 1 ) {
			wp_send_json_success( array( 'received' => $index ) );
		}
		try {
			$job = Kombi_Backup_Job::from_upload( $part );
			wp_send_json_success( array( 'id' => $job->id ) );
		} catch ( Throwable $e ) {
			wp_send_json_error( array( 'message' => $e->getMessage() ) );
		}
	}

	/* ---------------------------------------------------------------------
	 * Descargas (la carpeta de respaldos no es accesible por web)
	 * ------------------------------------------------------------------ */

	public static function download() {
		check_admin_referer( 'kombi_backup_download' );
		if ( ! current_user_can( self::CAP ) ) {
			wp_die( 'Sin permiso.', 403 );
		}
		$job = Kombi_Backup_Job::load( isset( $_GET['id'] ) ? sanitize_text_field( wp_unslash( $_GET['id'] ) ) : '' );
		if ( ! $job ) {
			wp_die( 'Respaldo no encontrado.', 404 );
		}
		if ( isset( $_GET['what'] ) && 'installer' === $_GET['what'] ) {
			self::send_file( KOMBI_BACKUP_DIR . 'installer/kombi-installer.php', 'kombi-installer.php', 'application/octet-stream' );
		}
		if ( 'done' !== $job->meta['status'] ) {
			wp_die( 'Ese respaldo no está completo.', 404 );
		}
		self::send_file( $job->zip_path(), $job->download_name(), 'application/zip' );
	}

	private static function send_file( $file, $name, $type ) {
		if ( ! is_file( $file ) ) {
			wp_die( 'Archivo no encontrado.', 404 );
		}
		@set_time_limit( 0 );
		while ( ob_get_level() ) {
			ob_end_clean();
		}
		nocache_headers();
		header( 'Content-Type: ' . $type );
		header( 'Content-Disposition: attachment; filename="' . $name . '"' );
		header( 'Content-Length: ' . filesize( $file ) );
		header( 'X-Content-Type-Options: nosniff' );
		$fh = fopen( $file, 'rb' );
		while ( ! feof( $fh ) ) {
			echo fread( $fh, 1048576 ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- binario.
			flush();
		}
		fclose( $fh );
		exit;
	}
}
