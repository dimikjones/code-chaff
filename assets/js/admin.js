/**
 * CodeChaff Admin JavaScript
 * Minimal UI for triggering AI audits from the updates screen.
 */

( function( $ ) {
	'use strict';

	$( function() {
		// Add AI Audit button to plugin/theme update rows (simple proof-of-concept).
		$( '.plugins .update-message, .themes .update-message' ).each( function() {
			const $row = $( this ).closest( 'tr' );
			const slug = $row.data( 'slug' ) || $row.attr( 'id' ).replace( /^([^-]+)-/, '$1' );

			const $btn = $( '<button>', {
				text: 'AI Audit',
				class: 'button button-small code-chaff-audit-btn',
				'data-slug': slug,
				'data-item-type': $row.closest( '.plugins' ).length ? 'plugin' : 'theme'
			} );

			$( this ).append( $btn );
		} );

		// Handle click.
		$( document ).on( 'click', '.code-chaff-audit-btn', function( e ) {
			e.preventDefault();

			const $btn = $( this );
			const data = {
				action: 'code_chaff_queue_audit',
				nonce: CodeChaffAdmin.nonce,
				slug: $btn.data( 'slug' ),
				item_type: $btn.data( 'item-type' ),
				old_ver: 'current',
				new_ver: 'latest'
			};

			$btn.prop( 'disabled', true ).text( 'Queuing…' );

			$.post( CodeChaffAdmin.ajaxUrl, data, function( response ) {
				if ( response.success ) {
					$btn.text( 'Queued ✓' );
				} else {
					$btn.text( 'Error' );
				}
			} );
		} );
	} );
}( jQuery ) );