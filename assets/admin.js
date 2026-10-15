/* Kombi Backup — pantalla de administración. */
( function () {
	'use strict';

	var cfg = window.KombiBackup;
	var $ = function ( sel, root ) { return ( root || document ).querySelector( sel ); };
	var sleep = function ( ms ) { return new Promise( function ( r ) { setTimeout( r, ms ); } ); };

	/** POST a admin-ajax. Los errores del servidor (success:false) no se reintentan. */
	function post( action, data ) {
		var fd = data instanceof FormData ? data : new FormData();
		if ( ! ( data instanceof FormData ) ) {
			Object.keys( data || {} ).forEach( function ( k ) { fd.append( k, data[ k ] ); } );
		}
		fd.append( 'action', 'kombi_backup_' + action );
		fd.append( 'nonce', cfg.nonce );
		return fetch( cfg.ajax, { method: 'POST', body: fd, credentials: 'same-origin' } ).then( function ( r ) {
			return r.json().catch( function () {
				var e = new Error( 'Respuesta inválida del servidor (' + r.status + ')' );
				e.retry = true;
				throw e;
			} );
		}, function () {
			var e = new Error( 'Sin conexión con el servidor' );
			e.retry = true;
			throw e;
		} ).then( function ( j ) {
			if ( ! j.success ) {
				var e = new Error( ( j.data && j.data.message ) || 'Error' );
				e.retry = false;
				throw e;
			}
			return j.data;
		} );
	}

	/* Progreso ---------------------------------------------------------- */

	var box = $( '#kb-progress' );
	function show( title ) {
		box.hidden = false;
		box.classList.remove( 'is-error', 'is-done' );
		$( '#kb-progress-title' ).textContent = title;
		$( '#kb-progress-log' ).innerHTML = '';
		render( { percent: 0, label: '' } );
		box.scrollIntoView( { behavior: 'smooth', block: 'center' } );
	}
	function render( p ) {
		$( '#kb-progress-bar' ).style.width = p.percent + '%';
		$( '#kb-progress-pct' ).textContent = Math.round( p.percent ) + '%';
		$( '#kb-progress-label' ).textContent = p.label || '';
		if ( p.log && p.log.length ) {
			$( '#kb-progress-log' ).innerHTML = p.log.map( function ( l ) {
				var li = document.createElement( 'li' );
				li.textContent = l;
				return li.outerHTML;
			} ).join( '' );
		}
	}
	function fail( err ) {
		box.hidden = false;
		box.classList.add( 'is-error' );
		$( '#kb-progress-label' ).textContent = err.message;
		busy( false );
	}
	function busy( on ) {
		document.querySelectorAll( '.kb-wrap button, #kb-file' ).forEach( function ( b ) { b.disabled = on; } );
	}

	/** Llama "step" hasta terminar; reintenta cortes de red/timeouts (el servidor retoma). */
	function drive( p ) {
		render( p );
		if ( p.done ) {
			return Promise.resolve( p );
		}
		var fails = 0;
		var loop = function () {
			return post( 'step', { id: p.id } ).then( function ( np ) {
				fails = 0;
				p = np;
				render( p );
				return p.done ? p : sleep( p.busy ? 1500 : 50 ).then( loop );
			}, function ( e ) {
				if ( ! e.retry || ++fails > 6 ) {
					throw e;
				}
				$( '#kb-progress-label' ).textContent = e.message + ' — reintentando…';
				return sleep( 2000 * fails ).then( loop );
			} );
		};
		return loop();
	}

	function backup( title ) {
		show( title );
		busy( true );
		return post( 'start' ).then( drive );
	}

	/* Acciones ---------------------------------------------------------- */

	$( '#kb-start' ).addEventListener( 'click', function () {
		backup( 'Creando respaldo…' ).then( function ( p ) {
			box.classList.add( 'is-done' );
			$( '#kb-progress-title' ).textContent = 'Respaldo listo.';
			location.href = cfg.page + '&kb_new=' + encodeURIComponent( p.id );
		} ).catch( fail );
	} );

	document.addEventListener( 'click', function ( ev ) {
		var t = ev.target;
		var row = t.closest( '[data-id]' );
		t = t.closest( 'button' ) || t;
		document.querySelectorAll( '.kb-menu[open]' ).forEach( function ( m ) {
			if ( ! m.contains( ev.target ) || t.closest( '.kb-menu__panel' ) ) {
				m.open = false;
			}
		} );

		if ( t.classList.contains( 'kb-copy' ) ) {
			navigator.clipboard.writeText( t.dataset.copy ).then( function () {
				t.textContent = 'Copiada';
				setTimeout( function () { t.textContent = 'Copiar'; }, 1500 );
			} );
		}

		if ( t.classList.contains( 'kb-delete' ) && row ) {
			if ( ! confirm( '¿Eliminar este respaldo? No se puede deshacer.' ) ) {
				return;
			}
			post( 'delete', { id: row.dataset.id } ).then( function () {
				row.remove();
			} ).catch( fail );
		}

		if ( t.classList.contains( 'kb-resume' ) && row ) {
			show( 'Continuando respaldo…' );
			busy( true );
			drive( { id: row.dataset.id, percent: 0, done: false } ).then( function () { location.reload(); } ).catch( fail );
		}

		if ( t.classList.contains( 'kb-restore' ) && row ) {
			restore( row.dataset.id );
		}
	} );

	function restore( id ) {
		var dlg = $( '#kb-restore-dialog' );
		dlg.returnValue = '';
		dlg.showModal();
		dlg.addEventListener( 'close', function onClose() {
			dlg.removeEventListener( 'close', onClose );
			if ( 'ok' !== dlg.returnValue ) {
				return;
			}
			var before = $( '#kb-safety' ).checked ? backup( 'Respaldo de seguridad antes de restaurar…' ) : Promise.resolve();
			before.then( function () {
				show( 'Preparando restauración…' );
				busy( true );
				return post( 'restore', { id: id } );
			} ).then( function ( r ) {
				location.href = r.url;
			} ).catch( fail );
		} );
	}

	/* Subida por trozos (al elegir o soltar el archivo) ------------------ */

	function upload( file ) {
		if ( ! /\.zip$/i.test( file.name ) ) {
			fail( new Error( 'Elige un archivo .zip creado con Kombi Backup.' ) );
			return;
		}
		var id = Array.from( crypto.getRandomValues( new Uint8Array( 12 ) ), function ( b ) { return b.toString( 16 ).padStart( 2, '0' ); } ).join( '' );
		var total = Math.max( 1, Math.ceil( file.size / cfg.chunk ) );
		var mb = function ( b ) { return ( b / 1048576 ).toFixed( b < 104857600 ? 1 : 0 ) + ' MB'; };
		var i = 0;
		var fails = 0;
		show( 'Subiendo ' + file.name + '…' );
		busy( true );
		var next = function () {
			var fd = new FormData();
			fd.append( 'upload', id );
			fd.append( 'index', i );
			fd.append( 'total', total );
			fd.append( 'chunk', file.slice( i * cfg.chunk, ( i + 1 ) * cfg.chunk ), 'chunk' );
			return post( 'upload', fd ).then( function ( r ) {
				fails = 0;
				i++;
				render( { percent: 100 * i / total, label: mb( Math.min( file.size, i * cfg.chunk ) ) + ' de ' + mb( file.size ) } );
				return i < total ? next() : r;
			}, function ( e ) {
				if ( ! e.retry || ++fails > 4 ) {
					throw e;
				}
				return sleep( 2000 * fails ).then( next );
			} );
		};
		next().then( function ( r ) {
			box.classList.add( 'is-done' );
			$( '#kb-progress-title' ).textContent = 'Respaldo subido.';
			location.href = cfg.page + '&kb_new=' + encodeURIComponent( r.id );
		} ).catch( fail );
	}

	var drop = $( '#kb-drop' );
	$( '#kb-file' ).addEventListener( 'change', function () {
		if ( this.files[ 0 ] ) {
			upload( this.files[ 0 ] );
		}
	} );
	[ 'dragenter', 'dragover' ].forEach( function ( type ) {
		drop.addEventListener( type, function ( ev ) {
			ev.preventDefault();
			drop.classList.add( 'is-over' );
		} );
	} );
	[ 'dragleave', 'drop' ].forEach( function ( type ) {
		drop.addEventListener( type, function () { drop.classList.remove( 'is-over' ); } );
	} );
	drop.addEventListener( 'drop', function ( ev ) {
		ev.preventDefault();
		if ( ev.dataTransfer.files[ 0 ] ) {
			upload( ev.dataTransfer.files[ 0 ] );
		}
	} );

	/* Exclusiones ------------------------------------------------------- */

	$( '#kb-save-excludes' ).addEventListener( 'click', function () {
		post( 'settings', { excludes: $( '#kb-excludes' ).value } ).then( function ( r ) {
			$( '#kb-excludes' ).value = r.excludes;
			$( '#kb-excludes-msg' ).textContent = 'Guardado.';
		} ).catch( function ( e ) {
			$( '#kb-excludes-msg' ).textContent = e.message;
		} );
	} );
}() );
