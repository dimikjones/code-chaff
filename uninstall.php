<?php
/**
 * CodeChaff Uninstall — Cleanup on full plugin deletion.
 *
 * Only runs when the plugin is fully deleted (not just deactivated).
 *
 * @package CodeChaff
 */

// Exit if not called during WordPress uninstall.
if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

global $wpdb;

// Drop the custom audit results table.
$table_name = $wpdb->prefix . 'code_chaff_audits';
$wpdb->query( "DROP TABLE IF EXISTS {$table_name}" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared

// Remove plugin options.
delete_option( 'code_chaff_ai_provider' );
delete_transient( 'code_chaff_activated' );

// Clear any cached SVN file lists.
wp_cache_flush_group( 'code_chaff' );