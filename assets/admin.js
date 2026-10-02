/* Migration by AppAlbania — admin. Vanilla JS, no dependencies. */
( function () {
	'use strict';
	var C = window.AppAlbaniaXhin || {};
	var $ = function ( s, r ) { return ( r || document ).querySelector( s ); };
	var $$ = function ( s, r ) { return Array.prototype.slice.call( ( r || document ).querySelectorAll( s ) ); };
	if ( ! $( '#xh-app' ) ) { return; }

	var token = null;
	var job = null;          // latest state from the server
	var driving = false;
	var polling = false;
	var uploading = false;
	var pendingFile = null;
	var uploadResume = null;
	var restoreSource = null;
	var showAll = false;

	/* ------------------------------------------------------------ utils */
	function human( b ) {
		b = Number( b ) || 0;
		var u = [ 'B', 'KB', 'MB', 'GB', 'TB' ], i = 0;
		while ( b >= 1024 && i < 4 ) { b /= 1024; i++; }
		return ( i ? b.toFixed( b < 10 ? 2 : 1 ) : b ) + ' ' + u[ i ];
	}
	function dur( s ) {
		s = Math.max( 0, Math.round( s ) );
		if ( s < 60 ) { return s + 's'; }
		if ( s < 3600 ) { return Math.floor( s / 60 ) + 'm ' + ( s % 60 ) + 's'; }
		return Math.floor( s / 3600 ) + 'h ' + Math.floor( ( s % 3600 ) / 60 ) + 'm';
	}
	function sleep( ms ) { return new Promise( function ( r ) { setTimeout( r, ms ); } ); }
	// File names: allow line breaks after "-" and "." instead of in the middle of a word.
	function escName( s ) { return esc( s ).replace( /([-.])/g, '$1<wbr>' ); }
	function esc( s ) { var d = document.createElement( 'div' ); d.textContent = null == s ? '' : String( s ); return d.innerHTML; }
	function toast( msg ) {
		var t = $( '#xh-toast' );
		t.textContent = msg;
		t.classList.add( 'is-on' );
		clearTimeout( toast.t );
		toast.t = setTimeout( function () { t.classList.remove( 'is-on' ); }, 2800 );
	}
	function post( action, data, tokenOnly ) {
		var body = new URLSearchParams();
		body.append( 'action', action );
		if ( ! tokenOnly ) { body.append( 'nonce', C.nonce ); }
		Object.keys( data || {} ).forEach( function ( k ) { body.append( k, data[ k ] ); } );
		return fetch( C.ajax, { method: 'POST', credentials: 'same-origin', body: body } ).then( parse );
	}
	function parse( r ) {
		return r.text().then( function ( t ) {
			var j = null, err;
			try { j = JSON.parse( t ); } catch ( e ) {
				// Another plugin printed something before our JSON (e.g. a PHP notice): skip it.
				var at = t.indexOf( '{"success":' );
				if ( at > 0 ) { try { j = JSON.parse( t.slice( at ) ); } catch ( e2 ) { /* proxy error page / PHP fatal */ } }
			}
			if ( ! j ) { err = new Error( 'HTTP ' + r.status ); err.status = r.status; err.transient = true; throw err; }
			if ( ! j.success ) { err = new Error( ( j.data && j.data.message ) || 'Request failed' ); err.status = r.status; err.data = j.data || {}; throw err; }
			return j.data;
		} );
	}

	/* ------------------------------------------------- live progress UI */
	var L = {
		box: $( '#xh-live' ), kind: $( '#xh-kind' ), msg: $( '#xh-msg' ), now: $( '#xh-now' ), pct: $( '#xh-pct' ),
		fill: $( '#xh-fill' ), track: $( '#xh-track' ), meta: $( '#xh-meta' ), steps: $( '#xh-steps' ), note: $( '#xh-note' ),
		log: $( '#xh-log' ), logbtn: $( '#xh-logbtn' ), cancel: $( '#xh-cancel' ), retry: $( '#xh-retry' ),
		close: $( '#xh-close' ), login: $( '#xh-login' ), main: $( '#xh-main' )
	};

	// The bar glides toward the server value at 60 fps and keeps creeping at the measured
	// rate between updates — so it is always moving while work happens, and never goes back.
	var anim = { shown: 0, target: 0, at: 0, rate: 0, hist: [] };
	function setTarget( p, status ) {
		var now = performance.now() / 1000;
		if ( 'done' === status ) { p = 100; }
		if ( p < anim.target && 'running' === status ) { p = anim.target; }
		anim.hist.push( { t: now, p: p } );
		anim.hist = anim.hist.filter( function ( h ) { return now - h.t < 20; } );
		var f = anim.hist[ 0 ];
		anim.rate = f && now - f.t > 1.5 ? Math.max( 0, ( p - f.p ) / ( now - f.t ) ) : 0;
		anim.target = p;
		anim.at = now;
	}
	function frame() {
		var now = performance.now() / 1000, goal = anim.target, running = job && 'running' === job.status;
		if ( running && anim.rate > 0 ) {
			goal = Math.min( anim.target + anim.rate * Math.min( now - anim.at, 3 ) * 0.9, anim.target + 1.2, 99.4 );
		}
		if ( running ) { goal = Math.max( goal, anim.shown ); }
		anim.shown += ( goal - anim.shown ) * ( job && 'done' === job.status ? 0.3 : 0.12 );
		if ( Math.abs( goal - anim.shown ) < 0.005 ) { anim.shown = goal; }
		var v = Math.max( 0, Math.min( 100, anim.shown ) );
		L.fill.style.width = v + '%';
		L.pct.textContent = v >= 99.995 ? '100' : v.toFixed( 1 );
		L.track.setAttribute( 'aria-valuenow', Math.round( v ) );
		requestAnimationFrame( frame );
	}
	requestAnimationFrame( frame );
	function resetAnim() { anim = { shown: 0, target: 0, at: 0, rate: 0, hist: [] }; speed = []; }

	var speed = [];
	function render( s, note ) {
		job = s;
		var running = 'running' === s.status;
		L.box.hidden = false;
		L.box.classList.toggle( 'is-done', 'done' === s.status );
		L.box.classList.toggle( 'is-error', 'error' === s.status );
		L.main.classList.toggle( 'is-busy', running && ! ( s.upload && ! s.upload.done && ! uploading ) );
		var what = 'export' === s.type ? 'Backup' : 'Restore';
		L.kind.textContent = { running: what + ' in progress', done: what + ' complete', error: what + ' paused', cancelled: what + ' cancelled' }[ s.status ] || what;
		L.msg.textContent = 'error' === s.status ? 'Stopped safely — nothing is lost' : ( s.msg || '' );
		L.now.textContent = running ? ( s.now || '' ) : '';
		setTarget( Number( s.pct ) || 0, s.status );

		L.steps.innerHTML = ( s.stages || [] ).map( function ( x ) {
			return '<li class="' + x.state + '" title="' + esc( x.label ) + '"><span>' + esc( x.label ) + '</span></li>';
		} ).join( '' );

		var st = s.stats || {}, parts = [], now = Date.now() / 1000;
		var done = s.upload && ! s.upload.done ? s.upload.received : ( st.done || 0 );
		var total = s.upload && ! s.upload.done ? s.upload.size : ( st.total || 0 );
		speed.push( { t: now, b: done } );
		speed = speed.filter( function ( h ) { return now - h.t < 12; } );
		var f = speed[ 0 ], bps = f && now - f.t > 1.5 ? Math.max( 0, ( done - f.b ) / ( now - f.t ) ) : 0;
		if ( running ) {
			if ( total ) { parts.push( '<b>' + human( done ) + '</b> of ' + human( total ) ); }
			if ( bps > 0 ) { parts.push( '<b>' + human( bps ) + '/s</b>' ); }
			if ( anim.rate > 0 && anim.target < 100 ) { parts.push( 'about <b>' + dur( ( 100 - anim.target ) / anim.rate ) + '</b> left' ); }
		}
		if ( st.files ) { parts.push( Number( st.files ).toLocaleString() + ' files' ); }
		if ( st.replaced ) { parts.push( Number( st.replaced ).toLocaleString() + ' rows updated' ); }
		if ( 'export' === s.type && st.archive_size && 'done' === s.status ) { parts.push( 'archive <b>' + human( st.archive_size ) + '</b>' ); }
		if ( s.started ) { parts.push( 'started ' + esc( s.started ) ); }
		if ( s.finished ) { parts.push( 'took ' + dur( s.finished - s.created ) ); }
		L.meta.innerHTML = parts.map( function ( x ) { return '<span>' + x + '</span>'; } ).join( '' );

		var n = note || '', cls = '';
		if ( 'error' === s.status ) { n = s.msg + ' — fix the cause if needed, then press Continue.'; cls = 'err'; }
		if ( 'done' === s.status ) {
			cls = 'ok';
			n = 'export' === s.type ? 'Saved in Backups below — download it, or copy a migration link for the new site.' : s.msg;
		}
		L.note.hidden = ! n;
		L.note.className = 'xh-note ' + cls;
		L.note.textContent = n;
		L.log.textContent = ( s.log || [] ).join( '\n' );

		L.cancel.hidden = ! ( ( running && false !== s.cancellable ) || 'error' === s.status );
		L.cancel.textContent = 'error' === s.status ? 'Discard' : 'Cancel';
		L.retry.hidden = 'error' !== s.status;
		L.close.hidden = running || 'error' === s.status;
		L.login.hidden = ! ( 'done' === s.status && 'import' === s.type );
		L.login.href = C.login;
	}

	L.logbtn.addEventListener( 'click', function () {
		L.log.hidden = ! L.log.hidden;
		L.logbtn.textContent = L.log.hidden ? 'Details' : 'Hide details';
		L.log.scrollTop = L.log.scrollHeight;
	} );
	L.close.addEventListener( 'click', function () {
		L.box.hidden = true;
		L.main.classList.remove( 'is-busy' );
		post( 'xhin_dismiss', {} ).catch( function () {} );
	} );
	L.cancel.addEventListener( 'click', function () {
		if ( ! token || ! window.confirm( job && 'error' === job.status ? 'Discard this job and clean up?' : 'Cancel and clean up?' ) ) { return; }
		uploading = false;
		post( 'xhin_cancel', { token: token }, true ).then( function ( s ) {
			render( s, s.cancelling ? 'Stopping…' : '' );
			refreshList();
		} ).catch( function ( e ) { toast( e.message ); } );
	} );
	L.retry.addEventListener( 'click', function () {
		post( 'xhin_retry', { token: token }, true ).then( function ( s ) { render( s ); run(); } ).catch( function ( e ) { toast( e.message ); } );
	} );

	/* ------------------------------------------------------------ runner */
	function run() { drive(); poll(); }

	// Worker loop: every step works a few seconds server-side. Network errors, 5xx and proxy
	// timeouts are retried forever with backoff — the server resumes from its saved cursor.
	function drive() {
		if ( driving ) { return; }
		driving = true;
		var fails = 0;
		( function loop() {
			post( 'xhin_step', { token: token }, true ).then( function ( s ) {
				fails = 0;
				render( s );
				if ( 'running' !== s.status ) { driving = false; finished( s ); return; }
				sleep( s.busy || ( s.upload && ! s.upload.done ) ? 1200 : 60 ).then( loop );
			} ).catch( function ( e ) {
				if ( e.data && e.data.gone ) { driving = false; return; }
				fails++;
				var wait = Math.min( 30000, 1000 * Math.pow( 1.6, Math.min( fails, 10 ) ) );
				L.note.hidden = false;
				L.note.className = 'xh-note';
				L.note.textContent = 'Connection hiccup (' + e.message + ') — continuing in ' + Math.round( wait / 1000 ) + 's. Nothing is lost.';
				sleep( wait ).then( loop );
			} );
		}() );
	}

	// Live view: reads the progress the worker checkpoints every ~0.75 s.
	function poll() {
		if ( polling ) { return; }
		polling = true;
		( function tick() {
			if ( ! job || 'running' !== job.status ) { polling = false; return; }
			post( 'xhin_status', { token: token }, true ).then( function ( s ) {
				if ( job && 'running' === job.status && 'running' === s.status ) { render( s ); }
			} ).catch( function () {} ).then( function () { setTimeout( tick, 700 ); } );
		}() );
	}

	function finished( s ) {
		refreshList();
		if ( 'done' === s.status ) { toast( 'export' === s.type ? 'Backup ready ✓' : 'Restore complete ✓' ); }
	}

	/* ------------------------------------------------------------ backup */
	$( '#xh-start-export' ).addEventListener( 'click', function () {
		var data = {}, btn = this;
		$$( '#xh-parts input' ).forEach( function ( i ) { data[ i.name ] = i.checked ? 1 : 0; } );
		data.password = $( '#xh-pass' ).value;
		data.excludes = $( '#xh-excl' ).value;
		btn.disabled = true;
		post( 'xhin_export', data ).then( function ( s ) {
			token = s.token;
			resetAnim();
			render( s );
			window.scrollTo( { top: 0, behavior: 'smooth' } );
			run();
		} ).catch( function ( e ) { toast( e.message ); } ).then( function () { btn.disabled = false; } );
	} );

	/* ----------------------------------------------------------- restore */
	var drop = $( '#xh-drop' ), fileInput = $( '#xh-file' ), urlInput = $( '#xh-url' ), startBtn = $( '#xh-start-import' );
	function syncRestoreBtn() { startBtn.disabled = ! ( pendingFile || /^https?:\/\/\S+/i.test( urlInput.value.trim() ) ); }
	function pickFile( f ) {
		if ( ! f ) { return; }
		if ( ! /\.xhin$/i.test( f.name ) ) { toast( 'Please choose a .xhin file' ); return; }
		if ( uploadResume ) { resumeUpload( f ); return; }
		pendingFile = f;
		drop.classList.add( 'has-file' );
		$( '#xh-drop-t' ).textContent = f.name + ' · ' + human( f.size );
		urlInput.value = '';
		syncRestoreBtn();
	}
	fileInput.addEventListener( 'change', function () { pickFile( fileInput.files[ 0 ] ); } );
	[ 'dragenter', 'dragover' ].forEach( function ( ev ) { drop.addEventListener( ev, function ( e ) { e.preventDefault(); drop.classList.add( 'is-over' ); } ); } );
	[ 'dragleave', 'drop' ].forEach( function ( ev ) { drop.addEventListener( ev, function ( e ) { e.preventDefault(); drop.classList.remove( 'is-over' ); } ); } );
	drop.addEventListener( 'drop', function ( e ) { if ( e.dataTransfer.files.length ) { pickFile( e.dataTransfer.files[ 0 ] ); } } );
	urlInput.addEventListener( 'input', function () {
		if ( urlInput.value.trim() && pendingFile ) {
			pendingFile = null;
			drop.classList.remove( 'has-file' );
			$( '#xh-drop-t' ).innerHTML = 'Drop a .xhin file or <u>choose</u> — any size';
		}
		syncRestoreBtn();
	} );
	startBtn.addEventListener( 'click', function () {
		if ( pendingFile ) {
			openConfirm( { source: 'upload', file: pendingFile }, '<b>' + escName( pendingFile.name ) + '</b><span>' + human( pendingFile.size ) + ' · from your computer</span>' );
		} else {
			openConfirm( { source: 'url', url: urlInput.value.trim() }, '<b>Migration link</b><span>' + esc( urlInput.value.trim().slice( 0, 90 ) ) + '…</span>' );
		}
	} );

	var dlg = $( '#xh-confirm' );
	function openConfirm( src, infoHtml ) {
		restoreSource = src;
		$( '#xh-dlg-file' ).innerHTML = infoHtml;
		$$( '#xh-rparts input' ).forEach( function ( i ) { i.disabled = false; i.checked = 'core' !== i.value; } );
		if ( dlg.showModal ) { dlg.showModal(); } else if ( window.confirm( 'Replace this site with the backup?' ) ) { startRestore(); }
	}
	dlg.addEventListener( 'close', function () { if ( 'yes' === dlg.returnValue ) { startRestore(); } } );

	function startRestore() {
		var src = restoreSource;
		var parts = $$( '#xh-rparts input' ).filter( function ( i ) { return i.checked && ! i.disabled; } ).map( function ( i ) { return i.value; } );
		if ( ! parts.length ) { toast( 'Choose at least one thing to restore' ); return; }
		var data = { source: src.source, password: $( '#xh-ipass' ).value, skip_dropins: $( '#xh-dropins' ).checked ? 1 : 0, parts: parts.join( ',' ) };
		if ( 'upload' === src.source ) { data.filename = src.file.name; data.size = src.file.size; }
		if ( 'url' === src.source ) { data.url = src.url; }
		if ( 'local' === src.source ) { data.name = src.name; }
		startBtn.disabled = true;
		post( 'xhin_import', data ).then( function ( s ) {
			token = s.token;
			resetAnim();
			render( s );
			window.scrollTo( { top: 0, behavior: 'smooth' } );
			if ( 'upload' === src.source ) {
				C.chunk = s.chunk || C.chunk;
				upload( src.file, 0 ).then( run );
			} else {
				run();
			}
		} ).catch( function ( e ) { toast( e.message ); } ).then( syncRestoreBtn );
	}

	// Chunked raw upload addressed by byte offset: any piece can be resent, a reload resumes,
	// and the piece size halves automatically if the web server rejects it (HTTP 413).
	function upload( file, offset ) {
		uploading = true;
		var chunk = C.chunk, fails = 0;
		return new Promise( function ( resolve ) {
			( function next() {
				if ( ! uploading ) { return; }
				if ( offset >= file.size ) { uploading = false; resolve(); return; }
				var end = Math.min( file.size, offset + chunk );
				fetch( C.ajax + '?action=xhin_upload&token=' + encodeURIComponent( token ) + '&offset=' + offset, {
					method: 'POST', credentials: 'same-origin', headers: { 'Content-Type': 'application/octet-stream' }, body: file.slice( offset, end )
				} ).then( parse ).then( function ( s ) {
					fails = 0;
					offset = Number( s.received );
					render( s );
					next();
				} ).catch( function ( e ) {
					if ( 413 === e.status && chunk > 262144 ) { chunk = Math.max( 262144, Math.floor( chunk / 2 ) ); next(); return; }
					if ( 409 === e.status ) { sleep( 700 ).then( next ); return; }
					if ( 400 === e.status && ! e.transient ) { uploading = false; toast( e.message ); render( Object.assign( {}, job, { status: 'error', msg: e.message } ) ); return; }
					fails++;
					var wait = Math.min( 30000, 800 * Math.pow( 1.7, Math.min( fails, 10 ) ) );
					L.note.hidden = false;
					L.note.className = 'xh-note';
					L.note.textContent = 'Upload interrupted (' + e.message + ') — resuming in ' + Math.round( wait / 1000 ) + 's.';
					sleep( wait ).then( function () {
						post( 'xhin_status', { token: token }, true ).then( function ( s ) {
							if ( s.upload ) { offset = s.upload.received; }
							next();
						} ).catch( next );
					} );
				} );
			}() );
		} );
	}
	function resumeUpload( f ) {
		if ( f.size !== uploadResume.size ) { toast( 'That is not the same file (size differs)' ); return; }
		var off = uploadResume.received;
		uploadResume = null;
		upload( f, off ).then( run );
	}
	window.addEventListener( 'beforeunload', function ( e ) { if ( uploading ) { e.preventDefault(); e.returnValue = ''; } } );

	/* ----------------------------------------------------------- backups */
	var fileIco = '<svg class="xh-ico" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M14 3H7a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h10a2 2 0 0 0 2-2V8z"/><path d="M14 3v5h5"/></svg>';
	function renderList( list ) {
		C.archives = list || [];
		var last = C.archives[ 0 ], lb = $( '#xh-lastbk' ), more = $( '#xh-showall' );
		lb.classList.toggle( 'none', ! last );
		lb.innerHTML = last ? 'Last backup <b>' + esc( last.date ) + '</b> · ' + esc( last.human ) : 'No backup yet';
		$( '#xh-count' ).textContent = C.archives.length;
		$( '#xh-empty' ).hidden = C.archives.length > 0;
		more.hidden = C.archives.length <= 6 || showAll;
		more.textContent = 'Show all ' + C.archives.length + ' backups';
		$( '#xh-list' ).innerHTML = C.archives.slice( 0, showAll ? C.archives.length : 6 ).map( function ( a ) {
			var dl = C.ajax + '?action=xhin_download&nonce=' + encodeURIComponent( C.nonce ) + '&f=' + encodeURIComponent( a.name );
			return '<li>' + fileIco + '<div class="xh-item"><div class="xh-item-name">' + esc( a.name ) +
				( a.encrypted ? '<span class="xh-tag">🔒 password</span>' : '' ) + ( a.valid ? '' : '<span class="xh-tag bad">incomplete</span>' ) + '</div>' +
				'<div class="xh-item-meta">' + esc( a.date ) + ' · ' + esc( a.human ) + '</div></div>' +
				'<div class="xh-actions">' +
				'<a class="xh-btn xh-btn-quiet xh-btn-sm" href="' + esc( dl ) + '">Download</a>' +
				'<button type="button" class="xh-btn xh-btn-quiet xh-btn-sm" data-link="' + esc( a.name ) + '">Copy link</button>' +
				'<button type="button" class="xh-btn xh-btn-quiet xh-btn-sm" data-restore="' + esc( a.name ) + '">Restore</button>' +
				'<button type="button" class="xh-btn xh-btn-quiet xh-btn-sm" data-del="' + esc( a.name ) + '" aria-label="Delete ' + esc( a.name ) + '">Delete</button>' +
				'</div></li>';
		} ).join( '' );
	}
	function refreshList() { post( 'xhin_list', {} ).then( renderList ).catch( function () {} ); }
	$( '#xh-showall' ).addEventListener( 'click', function () { showAll = true; renderList( C.archives ); } );

	$( '#xh-list' ).addEventListener( 'click', function ( e ) {
		var b = e.target.closest( 'button' );
		if ( ! b ) { return; }
		if ( b.dataset.del ) {
			if ( ! window.confirm( 'Delete ' + b.dataset.del + ' permanently?' ) ) { return; }
			post( 'xhin_delete', { name: b.dataset.del } ).then( function ( l ) { renderList( l ); toast( 'Deleted' ); } ).catch( function ( er ) { toast( er.message ); } );
		} else if ( b.dataset.link ) {
			post( 'xhin_link', { name: b.dataset.link } ).then( function ( r ) {
				var ok = function () { toast( 'Migration link copied — valid for ' + r.expires ); };
				if ( navigator.clipboard && window.isSecureContext ) {
					navigator.clipboard.writeText( r.url ).then( ok, function () { window.prompt( 'Migration link:', r.url ); } );
				} else {
					window.prompt( 'Migration link (paste it on the new site):', r.url );
				}
			} ).catch( function ( er ) { toast( er.message ); } );
		} else if ( b.dataset.restore ) {
			var name = b.dataset.restore;
			openConfirm( { source: 'local', name: name }, '<b>' + escName( name ) + '</b><span>Reading backup…</span>' );
			post( 'xhin_inspect', { name: name, password: $( '#xh-ipass' ).value } ).then( function ( i ) {
				var line = esc( i.date ) + ' · ' + esc( i.size );
				if ( i.site ) { line += ' · from ' + esc( i.site.replace( /^https?:\/\//, '' ) ); }
				if ( i.wp ) { line += ' · WP ' + esc( i.wp ); }
				if ( i.locked ) { line += i.wrong ? ' · <span class="warn">wrong password</span>' : ' · 🔒 enter its password in Restore → Options'; }
				if ( ! i.complete ) { line += ' · <span class="warn">incomplete file</span>'; }
				$( '#xh-dlg-file' ).innerHTML = '<b>' + escName( name ) + '</b><span>' + line + '</span>';
				if ( i.parts ) {
					var br = function ( v ) { return String( v || '' ).split( '.' ).slice( 0, 2 ).map( Number ); };
					var a = br( i.wp ), h = br( C.wp );
					var newer = a.length === 2 && h.length === 2 && ( a[ 0 ] > h[ 0 ] || ( a[ 0 ] === h[ 0 ] && a[ 1 ] > h[ 1 ] ) );
					$$( '#xh-rparts input' ).forEach( function ( inp ) {
						var has = i.parts.indexOf( inp.value ) !== -1;
						inp.disabled = ! has;
						inp.checked = has && ( 'core' !== inp.value || newer );
					} );
					if ( newer ) {
						$( '#xh-dlg-file span' ).innerHTML += '<br><span class="warn">' + ( i.parts.indexOf( 'core' ) !== -1
							? 'Backup is from WordPress ' + esc( i.wp ) + ', newer than this site (' + esc( C.wp ) + ') — WordPress core is restored too.'
							: 'Backup is from WordPress ' + esc( i.wp ) + ' — update WordPress here first (this site runs ' + esc( C.wp ) + ').' ) + '</span>';
					}
				}
			} ).catch( function ( er ) { $( '#xh-dlg-file span' ).innerHTML = '<span class="warn">' + esc( er.message ) + '</span>'; } );
		}
	} );

	/* ---------------------------------------------------------- settings */
	var setBtn = $( '#xh-open-settings' ), setForm = $( '#xh-settings' );
	setBtn.addEventListener( 'click', function () {
		setForm.hidden = ! setForm.hidden;
		setBtn.setAttribute( 'aria-expanded', String( ! setForm.hidden ) );
	} );
	setForm.addEventListener( 'submit', function ( e ) {
		e.preventDefault();
		var data = {};
		$$( '[name]', setForm ).forEach( function ( i ) { data[ i.name ] = 'checkbox' === i.type ? ( i.checked ? 1 : 0 ) : i.value; } );
		post( 'xhin_settings', data ).then( function () {
			var s = $( '#xh-saved' );
			s.hidden = false;
			setTimeout( function () { s.hidden = true; }, 2000 );
		} ).catch( function ( er ) { toast( er.message ); } );
	} );

	/* -------------------------------------------------------------- boot */
	renderList( C.archives );
	if ( C.job ) {
		token = C.job.token;
		anim.shown = Number( C.job.pct ) || 0;
		render( C.job );
		if ( 'running' === C.job.status ) {
			if ( C.job.upload && ! C.job.upload.done ) {
				uploadResume = C.job.upload;
				L.note.hidden = false;
				L.note.className = 'xh-note';
				L.note.textContent = 'Upload paused at ' + human( C.job.upload.received ) + ' of ' + human( C.job.upload.size ) + '. Drop the same file again to continue from there.';
			} else {
				run();
			}
		}
	} else if ( C.last ) {
		anim.shown = 'done' === C.last.status ? 100 : Number( C.last.pct ) || 0;
		render( C.last );
	}
}() );
