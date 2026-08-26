/**
 * Populate WordPress's Quick Edit form with the SEO overrides for a row.
 *
 * Core's inline editor copies the built-in fields out of the row for us and
 * knows nothing about ours, so this hooks the same open event and fills the
 * rest in from the JSON the column printed.
 *
 * The values read here are the STORED overrides — never the resolved text shown
 * in the cell. Copying the resolved text into the form would mean every
 * quick-edited row silently acquired a frozen override of its own template, and
 * a later template change would then update nothing.
 */
( function ( $ ) {
	'use strict';

	if ( ! window.inlineEditPost ) {
		return;
	}

	var original = window.inlineEditPost.edit;

	window.inlineEditPost.edit = function ( id ) {
		var result = original.apply( this, arguments );

		var postId = typeof id === 'object' ? this.getId( id ) : id;

		if ( ! postId ) {
			return result;
		}

		var $row  = $( '#post-' + postId );
		var $form = $( '#edit-' + postId );
		var $data = $row.find( '.fw-seo-inline-data' );

		if ( ! $data.length || ! $form.length ) {
			return result;
		}

		var stored;

		try {
			stored = JSON.parse( $data.text() );
		} catch ( e ) {
			// A malformed blob must not take the whole inline editor down with
			// it — the built-in fields still work without us.
			return result;
		}

		$form.find( 'input[name="fw_seo_title"]' ).val( stored.title || '' );
		$form.find( 'textarea[name="fw_seo_description"]' ).val( stored.description || '' );
		$form.find( 'input[name="fw_seo_noindex"]' ).prop( 'checked', !! stored.noindex );

		return result;
	};
}( jQuery ) );
