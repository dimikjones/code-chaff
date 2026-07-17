/**
 * CodeChaff Admin JavaScript
 * Injects AI Audit buttons into plugin/theme update rows and extracts real version data.
 */

( function( $, wp ) {
	'use strict';

	$( function() {
		/**
		 * Extract the new version from WordPress update notice text.
		 * The update notice typically contains text like:
		 * "There is a new version of Akismet available. View version 5.3.1 details."
		 *
		 * @param {string} text The update notice text.
		 * @return {string} The version number, or empty string.
		 */
		function extractNewVersion( text ) {
			const match = text.match( /version\s+([\d.]+)/i );
			return match ? match[1] : '';
		}

		/**
		 * Get the currently installed version of a plugin or theme.
		 * Uses wp.updates if available, otherwise falls back to DOM parsing.
		 *
		 * @param {Object} $row The jQuery row element.
		 * @param {string} slug The plugin/theme slug.
		 * @param {string} itemType 'plugin' or 'theme'.
		 * @return {string} The installed version, or empty string.
		 */
		function getInstalledVersion( $row, slug, itemType ) {
			// Try wp.updates API for plugins (built into WordPress core).
			if ( 'plugin' === itemType && wp.updates && wp.updates.plugins && wp.updates.plugins[ slug ] ) {
				return wp.updates.plugins[ slug ].version || '';
			}

			// Try wp.updates for themes.
			if ( 'theme' === itemType && wp.updates && wp.updates.themes && wp.updates.themes[ slug ] ) {
				return wp.updates.themes[ slug ].version || '';
			}

			// Fallback: parse from DOM — plugin version is in the plugin description column.
			const $versionCol = $row.find( 'td.plugin-version, td.column-version' );
			if ( $versionCol.length ) {
				const versionText = $versionCol.text().trim();
				const match = versionText.match( /([\d.]+)/ );
				if ( match ) {
					return match[1];
				}
			}

			// Fallback: use data injected via wp_localize_script (if available).
			if ( CodeChaffAdmin.pluginVersions && CodeChaffAdmin.pluginVersions[ slug ] ) {
				return CodeChaffAdmin.pluginVersions[ slug ];
			}
			if ( CodeChaffAdmin.themeVersions && CodeChaffAdmin.themeVersions[ slug ] ) {
				return CodeChaffAdmin.themeVersions[ slug ];
			}

			return '';
		}

		// Add AI Audit button to plugin update rows.
		$( '.plugins tr.active[data-plugin] .plugin-update-message, ' +
		   '.plugins tr.active[data-plugin] .update-message' ).each( function() {
			const $row    = $( this ).closest( 'tr' );
			const $msg    = $( this );
			const slug    = CodeChaffAdmin.pluginSlugs && CodeChaffAdmin.pluginSlugs[ $row.data( 'plugin' ) ]
				? CodeChaffAdmin.pluginSlugs[ $row.data( 'plugin' ) ]
				: $row.data( 'plugin' );
			const newVer  = extractNewVersion( $msg.text() );
			const oldVer  = getInstalledVersion( $row, slug, 'plugin' );

			if ( ! newVer ) {
				return;
			}

			const $btn = $( '<button>', {
				text: 'AI Audit',
				class: 'button button-small code-chaff-audit-btn',
				'data-slug': slug,
				'data-item-type': 'plugin',
				'data-old-ver': oldVer,
				'data-new-ver': newVer
			} );

			$msg.append( ' &nbsp; ' ).append( $btn );
		} );

		// Add AI Audit button to theme update rows on themes.php and update-core.php.
		$( '.themes .update-message, .theme-browser .theme .update-message, #update-themes-table .update-message' ).each( function() {
			const $msg     = $( this );
			const $themeEl = $msg.closest( '.theme, tr' );
			let slug       = $themeEl.data( 'slug' ) || $themeEl.attr( 'id' );

			// Try to extract slug from theme name or update notice.
			if ( ! slug ) {
				const idAttr = $themeEl.attr( 'id' );
				if ( idAttr ) {
					slug = idAttr.replace( /^([^-]+)-.*$/, '$1' );
				}
			}

			const newVer = extractNewVersion( $msg.text() );
			const oldVer = getInstalledVersion( $themeEl, slug, 'theme' );

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