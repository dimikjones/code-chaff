/**
 * CodeChaff Admin JavaScript
 * Injects AI Audit buttons into plugin/theme update rows and extracts real version data.
 */

( function( $, wp ) {
	'use strict';

	$( function() {
		/**
		 * Extract the new version from WordPress update notice text.
		 * Matches "version 5.7" or "Version 5.7".
		 *
		 * @param {string} text The update notice text.
		 * @return {string} The version number, or empty string.
		 */
		function extractNewVersion( text ) {
			const match = text.match( /version\s+([\d.]+)/i );
			return match ? match[1] : '';
		}

		/**
		 * Get the currently installed version of a plugin.
		 *
		 * @param {string} slug The plugin slug.
		 * @return {string} The installed version, or empty string.
		 */
		function getInstalledVersion( slug ) {
			if ( CodeChaffAdmin.pluginVersions && CodeChaffAdmin.pluginVersions[ slug ] ) {
				return CodeChaffAdmin.pluginVersions[ slug ];
			}
			return '';
		}

		/**
		 * Find the new version available for a plugin by checking the
		 * accompanying plugin-update-tr row that WordPress inserts below
		 * the main plugin row.
		 *
		 * @param {Object} $row The plugin's <tr> row element.
		 * @param {string} slug The plugin slug.
		 * @return {string} The new version, or empty string.
		 */
		function findNewVersion( $row, slug ) {
			// 1. Check the companion update row (tr.plugin-update-tr).
			const pluginFile  = $row.data( 'plugin' );
			const $updateRows = $( '.plugin-update-tr' );

			for ( let i = 0; i < $updateRows.length; i++ ) {
				const $updateRow = $updateRows.eq( i );
				if ( $updateRow.data( 'plugin' ) === pluginFile ) {
					const ver = extractNewVersion( $updateRow.text() );
					if ( ver ) {
						return ver;
					}
				}
			}

			// 2. Fallback: check any element containing "version" text in context
			// of this plugin slug.
			const $allUpdateMsgs = $( '.update-message, .plugin-update-message, .notice' );
			for ( let i = 0; i < $allUpdateMsgs.length; i++ ) {
				const $el = $allUpdateMsgs.eq( i );
				const text = $el.text();
				if ( text.toLowerCase().indexOf( slug.toLowerCase() ) !== -1 ) {
					const ver = extractNewVersion( text );
					if ( ver ) {
						return ver;
					}
				}
			}

			return '';
		}

		// --- Plugin rows ---
		// Target any tr with data-plugin AND the 'update' class (both active and inactive).
		$( '.plugins tr[data-plugin].update' ).each( function() {
			const $row       = $( this );
			const pluginFile = $row.data( 'plugin' );
			const slug       = $row.data( 'slug' )
				|| ( CodeChaffAdmin.pluginSlugs && CodeChaffAdmin.pluginSlugs[ pluginFile ] )
				|| pluginFile;
			const newVer     = findNewVersion( $row, slug );
			const oldVer     = getInstalledVersion( slug );

			if ( ! slug || ! newVer ) {
				return;
			}

			// Determine the best element to append the button to.
			let $target = $row.find( '.plugin-update-message, .update-message' ).first();
			if ( ! $target.length ) {
				$target = $row.find( '.plugin-version-author-uri' ).first();
			}
			if ( ! $target.length ) {
				$target = $row.find( '.column-description' ).first();
			}

			const $btn = $( '<button>', {
				text: 'AI Audit',
				class: 'button button-small code-chaff-audit-btn',
				'data-slug': slug,
				'data-item-type': 'plugin',
				'data-old-ver': oldVer,
				'data-new-ver': newVer
			} );

			$target.append( ' &nbsp; ' ).append( $btn );
		} );

		// --- Theme rows ---
		$( '.themes .update-message, .theme-browser .theme .update-message, #update-themes-table .update-message' ).each( function() {
			const $msg     = $( this );
			const $themeEl = $msg.closest( '.theme, tr' );
			let slug       = $themeEl.data( 'slug' ) || $themeEl.attr( 'id' );

			if ( ! slug ) {
				const idAttr = $themeEl.attr( 'id' );
				if ( idAttr ) {
					slug = idAttr.replace( /^([^-]+)-.*$/, '$1' );
				}
			}

			const newVer = extractNewVersion( $msg.text() );
			const oldVer = CodeChaffAdmin.themeVersions && CodeChaffAdmin.themeVersions[ slug ]
				? CodeChaffAdmin.themeVersions[ slug ]
				: '';

			if ( ! slug || ! newVer ) {
				return;
			}

			const $btn = $( '<button>', {
				text: 'AI Audit',
				class: 'button button-small code-chaff-audit-btn',
				'data-slug': slug,
				'data-item-type': 'theme',
				'data-old-ver': oldVer,
				'data-new-ver': newVer
			} );

			$msg.append( ' &nbsp; ' ).append( $btn );
		} );

		// Handle click on AI Audit button.
		$( document ).on( 'click', '.code-chaff-audit-btn', function( e ) {
			e.preventDefault();

			const $btn = $( this );
			const data = {
				action: 'code_chaff_queue_audit',
				nonce: CodeChaffAdmin.nonce,
				slug: $btn.data( 'slug' ),
				item_type: $btn.data( 'item-type' ),
				old_ver: $btn.data( 'old-ver' ) || '',
				new_ver: $btn.data( 'new-ver' ) || ''
			};

			if ( ! data.slug || ! data.new_ver ) {
				$btn.text( 'No version data' ).css( 'color', '#a00' );
				return;
			}

			$btn.prop( 'disabled', true ).text( 'Queuing\u2026' );

			$.post( CodeChaffAdmin.ajaxUrl, data, function( response ) {
				if ( response.success ) {
					$btn.text( 'Queued \u2713' ).css( 'color', '#4a8' );
				} else {
					$btn.text( 'Error' ).css( 'color', '#a00' );
				}
			} ).fail( function() {
				$btn.text( 'Error' ).css( 'color', '#a00' );
			} );
		} );
	} );
}( jQuery, window.wp || {} ) );