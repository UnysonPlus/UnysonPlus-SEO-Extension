/**
 * Drives the SEO import, one batch at a time.
 *
 * Batched deliberately: a site with ten thousand posts would otherwise need the
 * whole import to outrun PHP's time limit, and a timeout halfway through is the
 * worst outcome available — partly imported, with nothing saying how far it got.
 * Each batch reports its own offset, so a failure stops with a count rather than
 * a mystery.
 */
( function () {
	'use strict';

	var wrap = document.querySelector( '.fw-seo-import' );

	if ( ! wrap || typeof window.fwSeoImport === 'undefined' ) {
		return;
	}

	var progress = wrap.querySelector( '.fw-seo-import-progress' );
	var bar      = wrap.querySelector( '.fw-seo-import-bar span' );
	var status   = wrap.querySelector( '.fw-seo-import-status' );
	var report   = wrap.querySelector( '.fw-seo-import-report' );

	function setBusy( busy ) {
		Array.prototype.forEach.call( wrap.querySelectorAll( '.fw-seo-import-run' ), function ( b ) {
			b.disabled = busy;
		} );
	}

	function batch( source, offset, overwrite, totals ) {
		var body = new FormData();

		body.append( 'action', 'fw_seo_import' );
		body.append( 'nonce', window.fwSeoImport.nonce );
		body.append( 'source', source );
		body.append( 'offset', offset );

		if ( overwrite ) {
			body.append( 'overwrite', '1' );
		}

		fetch( window.fwSeoImport.ajaxUrl, { method: 'POST', credentials: 'same-origin', body: body } )
			.then( function ( r ) { return r.json(); } )
			.then( function ( res ) {
				if ( ! res || ! res.success ) {
					finish( totals, ( res && res.data && res.data.message ) || window.fwSeoImport.i18n.failed );
					return;
				}

				var data = res.data;

				totals.imported += data.imported;
				totals.skipped  += data.skipped;
				totals.fields   += data.fields;
				totals.seen     += data.processed;

				Object.keys( data.unknown || {} ).forEach( function ( tag ) {
					totals.unknown[ tag ] = ( totals.unknown[ tag ] || 0 ) + data.unknown[ tag ];
				} );

				if ( data.total > 0 ) {
					bar.style.width = Math.min( 100, Math.round( ( totals.seen / data.total ) * 100 ) ) + '%';
				}

				status.textContent = window.fwSeoImport.i18n.working
					.replace( '%1$s', totals.seen )
					.replace( '%2$s', data.total );

				if ( data.done ) {
					finish( totals, '' );
					return;
				}

				batch( source, data.offset, overwrite, totals );
			} )
			.catch( function () {
				finish( totals, window.fwSeoImport.i18n.failed );
			} );
	}

	function finish( totals, error ) {
		setBusy( false );
		bar.style.width = '100%';

		status.textContent = error
			? error
			: window.fwSeoImport.i18n.done
				.replace( '%1$s', totals.imported )
				.replace( '%2$s', totals.fields );

		var tags = Object.keys( totals.unknown );

		if ( ! tags.length ) {
			return;
		}

		// Surfaced rather than swallowed: these are tags with no counterpart
		// here, left in the imported text exactly as they were. Saying nothing
		// would leave a title that reads fine and is quietly missing a word.
		var list = tags.map( function ( t ) {
			return '<code>' + t.replace( /</g, '&lt;' ) + '</code> (' + totals.unknown[ t ] + ')';
		} ).join( ', ' );

		report.style.display = '';
		report.innerHTML = '<div class="notice notice-warning inline" style="margin:1em 0 0"><p><strong>'
			+ window.fwSeoImport.i18n.unknownTitle + '</strong></p><p>'
			+ window.fwSeoImport.i18n.unknownBody + '</p><p>' + list + '</p></div>';
	}

	Array.prototype.forEach.call( wrap.querySelectorAll( '.fw-seo-import-run' ), function ( button ) {
		button.addEventListener( 'click', function () {
			var overwrite = !! ( document.getElementById( 'fw-seo-import-overwrite' ) || {} ).checked;

			if ( overwrite && ! window.confirm( window.fwSeoImport.i18n.confirmOverwrite ) ) {
				return;
			}

			setBusy( true );
			report.style.display = 'none';
			report.innerHTML     = '';
			progress.style.display = '';
			bar.style.width      = '0%';
			status.textContent   = '';

			batch( button.getAttribute( 'data-source' ), 0, overwrite, {
				imported: 0, skipped: 0, fields: 0, seen: 0, unknown: {}
			} );
		} );
	} );
}() );
