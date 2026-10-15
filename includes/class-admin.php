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
				'page'  => admin_url( 'tools.php?page=' . self::SLUG ),
				// Trozos bajo el límite de subida de PHP: así se pueden subir respaldos de varios GB.
				'chunk' => (int) max( 262144, min( 8388608, $max ? floor( $max * 0.8 ) : 1048576 ) ),
			)
		);
	}

	/* ---------------------------------------------------------------------
	 * Pantalla
	 * ------------------------------------------------------------------ */

	public static function render() {
		$jobs   = Kombi_Backup_Job::all();
		$done   = array_values(
			array_filter(
				$jobs,
				function ( $j ) {
					return 'done' === $j->meta['status'];
				}
			)
		);
		$last   = $done ? $done[0] : null;
		$dl     = wp_nonce_url( admin_url( 'admin-post.php?action=kombi_backup_download' ), 'kombi_backup_download' );
		$new_id = isset( $_GET['kb_new'] ) ? sanitize_text_field( wp_unslash( $_GET['kb_new'] ) ) : '';
		?>
		<div class="wrap kb-wrap">
			<h1 class="screen-reader-text">Kombi Backup</h1>

			<header class="kb-hero">
				<div class="kb-hero__brand">
					<span class="kb-logo"><?php echo self::logo(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- SVG propio del plugin. ?></span>
					<span class="kb-hero__name">Backup</span>
				</div>
				<div class="kb-hero__body">
					<p class="kb-hero__title">
						<?php if ( $last ) : ?>
							<span class="kb-dot" aria-hidden="true"></span> Último respaldo hace <?php echo esc_html( human_time_diff( $last->meta['created'] ) ); ?>
						<?php else : ?>
							<span class="kb-dot kb-dot--warn" aria-hidden="true"></span> Este sitio todavía no tiene respaldos
						<?php endif; ?>
					</p>
					<p class="kb-hero__text">Copia completa del sitio: archivos y base de datos en un solo archivo. Sirve para recuperarlo si algo falla o para llevarlo a otro servidor.</p>
				</div>
				<div class="kb-hero__cta">
					<button type="button" class="kb-btn kb-btn--primary kb-btn--big" id="kb-start" <?php disabled( is_multisite() ); ?>>
						<span class="dashicons dashicons-backup" aria-hidden="true"></span> Crear respaldo
					</button>
					<span class="kb-hero__hint">Tarda unos minutos. Puedes seguir usando el sitio.</span>
				</div>
			</header>

			<?php if ( is_multisite() ) : ?>
				<div class="notice notice-error inline"><p>Multisitio todavía no está soportado.</p></div>
			<?php endif; ?>

			<div class="kb-progress" id="kb-progress" hidden role="status" aria-live="polite">
				<div class="kb-progress__head"><strong id="kb-progress-title">Creando respaldo…</strong> <span id="kb-progress-pct">0%</span></div>
				<div class="kb-bar"><span id="kb-progress-bar"></span></div>
				<p id="kb-progress-label" class="kb-muted"></p>
				<ul id="kb-progress-log" class="kb-log"></ul>
			</div>

			<div class="kb-layout">
				<div class="kb-main">
					<h2 class="kb-section-title">Tus respaldos<?php if ( $jobs ) : ?> <span class="kb-count"><?php echo (int) count( $jobs ); ?></span><?php endif; ?></h2>

					<?php if ( ! $jobs ) : ?>
						<div class="kb-empty">
							<span class="dashicons dashicons-cloud-saved" aria-hidden="true"></span>
							<p><strong>Aún no hay respaldos.</strong><br>Crea el primero con el botón de arriba.</p>
						</div>
					<?php endif; ?>

					<ul class="kb-list">
					<?php foreach ( $jobs as $job ) : ?>
						<?php
						$m     = $job->meta;
						$link  = add_query_arg( 'id', $job->id, $dl );
						$host  = preg_replace( '#^https?://#', '', $m['home'] );
						$class = 'kb-item' . ( $job->id === $new_id ? ' is-new' : '' ) . ( 'done' !== $m['status'] ? ' is-' . $m['status'] : '' );
						$icon  = 'done' === $m['status'] ? 'dashicons-archive' : ( 'error' === $m['status'] ? 'dashicons-warning' : 'dashicons-update' );
						?>
						<li class="<?php echo esc_attr( $class ); ?>" data-id="<?php echo esc_attr( $job->id ); ?>">
							<div class="kb-item__icon" aria-hidden="true"><span class="dashicons <?php echo esc_attr( $icon ); ?>"></span></div>
							<div class="kb-item__info">
								<p class="kb-item__date">
									<?php echo esc_html( wp_date( 'j \d\e F Y · H:i', $m['created'] ) ); ?>
									<?php if ( $job->id === $new_id ) : ?><span class="kb-tag kb-tag--ok">Nuevo</span><?php endif; ?>
									<?php if ( 'upload' === $m['origin'] ) : ?><span class="kb-tag">Subido</span><?php endif; ?>
								</p>
								<p class="kb-item__meta">
									<?php echo esc_html( $host ); ?>
									<?php if ( 'done' === $m['status'] ) : ?> · <?php echo esc_html( size_format( $m['size'], 1 ) ); ?><?php endif; ?>
									<?php if ( ! empty( $m['wp'] ) ) : ?> · WordPress <?php echo esc_html( $m['wp'] ); ?><?php endif; ?>
								</p>
								<?php if ( 'done' === $m['status'] ) : ?>
									<p class="kb-item__key">
										<?php if ( $m['key'] ) : ?>
											Clave del instalador <code><?php echo esc_html( $m['key'] ); ?></code>
											<button type="button" class="kb-link kb-copy" data-copy="<?php echo esc_attr( $m['key'] ); ?>">Copiar</button>
										<?php else : ?>
											<span class="kb-muted">Clave del instalador: la del sitio de origen.</span>
										<?php endif; ?>
									</p>
									<?php if ( ! empty( $m['log'] ) ) : ?>
										<details class="kb-item__notes">
											<summary><?php echo esc_html( sprintf( 1 === count( $m['log'] ) ? '%d aviso' : '%d avisos', count( $m['log'] ) ) ); ?></summary>
											<ul class="kb-log"><?php foreach ( $m['log'] as $line ) : ?><li><?php echo esc_html( $line ); ?></li><?php endforeach; ?></ul>
										</details>
									<?php endif; ?>
								<?php elseif ( 'error' === $m['status'] ) : ?>
									<p class="kb-error">No se completó: <?php echo esc_html( $m['message'] ); ?></p>
								<?php else : ?>
									<p class="kb-muted">Sin terminar: se interrumpió o sigue en curso.</p>
								<?php endif; ?>
							</div>
							<div class="kb-item__actions">
								<?php if ( 'done' === $m['status'] ) : ?>
									<a class="kb-btn kb-btn--primary" href="<?php echo esc_url( add_query_arg( 'what', 'zip', $link ) ); ?>"><span class="dashicons dashicons-download" aria-hidden="true"></span> Descargar</a>
									<a class="kb-btn" href="<?php echo esc_url( add_query_arg( 'what', 'installer', $link ) ); ?>" title="kombi-installer.php: súbelo junto al respaldo para instalarlo en otro servidor">Instalador</a>
								<?php elseif ( 'running' === $m['status'] ) : ?>
									<button type="button" class="kb-btn kb-btn--primary kb-resume">Continuar</button>
								<?php endif; ?>
								<details class="kb-menu">
									<summary class="kb-btn kb-btn--icon" aria-label="Más acciones" title="Más acciones"><span class="dashicons dashicons-ellipsis" aria-hidden="true"></span></summary>
									<div class="kb-menu__panel">
										<?php if ( 'done' === $m['status'] ) : ?>
											<button type="button" class="kb-restore"><span class="dashicons dashicons-image-rotate" aria-hidden="true"></span> Restaurar en este sitio</button>
										<?php endif; ?>
										<button type="button" class="kb-delete"><span class="dashicons dashicons-trash" aria-hidden="true"></span> Eliminar</button>
									</div>
								</details>
							</div>
						</li>
					<?php endforeach; ?>
					</ul>
				</div>

				<aside class="kb-side">
					<section class="kb-panel">
						<h2>Llevar el sitio a otro servidor</h2>
						<ol class="kb-steps">
							<li><strong>Descarga</strong> el respaldo y el instalador.</li>
							<li><strong>Súbelos</strong> a la carpeta vacía del nuevo hosting y crea una base de datos.</li>
							<li><strong>Abre</strong> <code>tu-dominio/kombi-installer.php</code> y escribe la clave. El resto es automático.</li>
						</ol>
					</section>

					<section class="kb-panel">
						<h2>Restaurar un respaldo de otro sitio</h2>
						<p class="kb-muted">Por ejemplo, la versión nueva que preparaste en local. Al subirlo aparece en la lista: ahí eliges <em>Restaurar en este sitio</em>.</p>
						<label class="kb-drop" id="kb-drop">
							<input type="file" id="kb-file" accept=".zip,application/zip">
							<span class="dashicons dashicons-upload" aria-hidden="true"></span>
							<span><strong>Arrastra el .zip aquí</strong><br><span class="kb-muted">o haz clic para elegirlo</span></span>
						</label>
					</section>

					<details class="kb-panel kb-advanced">
						<summary>Opciones avanzadas</summary>
						<label for="kb-excludes" class="kb-label">Carpetas y archivos que no se respaldan</label>
						<p class="kb-muted">Una ruta por línea, relativa a la carpeta de WordPress. Siempre se omiten <code>.git</code>, <code>node_modules</code> y los propios respaldos.</p>
						<textarea id="kb-excludes" rows="8" class="large-text code"><?php echo esc_textarea( implode( "\n", Kombi_Backup_Job::excludes() ) ); ?></textarea>
						<p><button type="button" class="kb-btn" id="kb-save-excludes">Guardar</button> <span id="kb-excludes-msg" class="kb-muted" role="status"></span></p>
					</details>

					<p class="kb-foot">Kombi Backup <?php echo esc_html( KOMBI_BACKUP_VERSION ); ?> · <a href="https://kombi.cl/ayuda/" target="_blank" rel="noopener">Ayuda de Kombi</a></p>
				</aside>
			</div>

			<dialog id="kb-restore-dialog" class="kb-dialog" aria-labelledby="kb-restore-title">
				<form method="dialog">
					<h2 id="kb-restore-title"><span class="dashicons dashicons-warning" aria-hidden="true"></span> ¿Restaurar este respaldo?</h2>
					<p>Todo el contenido de <strong><?php echo esc_html( preg_replace( '#^https?://#', '', home_url() ) ); ?></strong> (archivos y base de datos) se reemplazará por el del respaldo.</p>
					<ul class="kb-dialog__list">
						<li>Se mantienen la dirección del sitio y la configuración del servidor.</li>
						<li>Los plugins, temas y archivos que no estén en el respaldo se eliminan.</li>
						<li>Primero se verifica que el respaldo esté completo; luego, mientras dura, los visitantes ven una página de mantenimiento.</li>
						<li>Al terminar, inicia sesión con un usuario del respaldo.</li>
					</ul>
					<label class="kb-check"><input type="checkbox" id="kb-safety" checked> Crear antes un respaldo del sitio actual (recomendado)</label>
					<div class="kb-dialog__actions">
						<button value="cancel" class="kb-btn">Cancelar</button>
						<button value="ok" class="kb-btn kb-btn--danger">Sí, restaurar</button>
					</div>
				</form>
			</dialog>
		</div>
		<?php
	}

	/** Logo de Kombi en línea (toma el color del texto). */
	private static function logo() {
		return (string) file_get_contents( KOMBI_BACKUP_DIR . 'assets/kombi-logo.svg' );
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
			$progress = Kombi_Backup_Job::create()->run();
			if ( 'error' === $progress['status'] ) {
				wp_send_json_error( array( 'message' => $progress['message'] ) );
			}
			wp_send_json_success( $progress );
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
		$size  = isset( $_POST['size'] ) ? (int) $_POST['size'] : 0;
		$chunk = isset( $_POST['chunk_size'] ) ? (int) $_POST['chunk_size'] : 0;
		if ( $size <= 0 || $chunk < 65536 || (int) ceil( $size / $chunk ) !== $total ) {
			wp_send_json_error( array( 'message' => 'Datos de subida inválidos.' ), 400 );
		}
		$part = kombi_backup_storage_dir() . '/upload-' . $upload . '.part';
		if ( 0 === $index ) {
			@unlink( $part );
		} elseif ( ! is_file( $part ) ) {
			wp_send_json_error( array( 'message' => 'La subida se perdió; vuelve a intentarlo.' ), 400 );
		}
		// Cada trozo va a su posición: si el navegador reintenta uno, se sobrescribe en vez de duplicarse.
		$in  = fopen( $_FILES['chunk']['tmp_name'], 'rb' );
		$out = fopen( $part, 'c+b' );
		fseek( $out, $index * $chunk );
		stream_copy_to_stream( $in, $out );
		fclose( $in );
		fclose( $out );
		if ( $index < $total - 1 ) {
			wp_send_json_success( array( 'received' => $index ) );
		}
		clearstatcache( true, $part );
		if ( filesize( $part ) !== $size ) {
			@unlink( $part );
			wp_send_json_error( array( 'message' => 'La subida quedó incompleta. Vuelve a intentarlo.' ) );
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
