<?php
/**
 * Plugin Name:       CodeChaff
 * Description:       Separate the wheat from the chaff. This says you are stripping away the garbage from the update.
 * Plugin URI:        https://github.com/dimikjones/code-chaff
 * Version:           0.1.0
 * Requires at least: 7.0
 * Requires PHP:      7.4
 * Author:            Marko Dimitrijević
 * Author URI:        https://markocodes.com/
 * License:           GPL-3.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-3.0.html
 * Text Domain:       code-chaff
 *
 * @package CodeChaff
 */

namespace CodeChaff;

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

	// --- CONSTANTS References ---
	define( 'CODE_CHAFF_SETUP_DIR', __DIR__ );
	define( 'CODE_CHAFF_SETUP_ROOT', __FILE__ );
	define( 'CODE_CHAFF_SETUP_URL', plugin_dir_url( __FILE__ ) );
	define( 'CODE_CHAFF_SETUP_CACHE_TIME_DAY', DAY_IN_SECONDS );
	define( 'CODE_CHAFF_SETUP_VERSION', '0.1.0' );

/**
 * Main plugin class (all static per coding standards).
 */
class CodeChaff {

	// --- CONSTANTS ---
	const SETUP_DIR      = \CODE_CHAFF_SETUP_DIR;
	const SETUP_ROOT     = \CODE_CHAFF_SETUP_ROOT;
	const SETUP_URL      = \CODE_CHAFF_SETUP_URL;
	const CACHE_TIME_DAY = \CODE_CHAFF_SETUP_CACHE_TIME_DAY;
	const SETUP_VERSION  = \CODE_CHAFF_SETUP_VERSION;

	/**
	 * Plugin activation handler.
	 *
	 * @return void
	 */
	public static function activate() {
		self::create_tables();
	}

	/**
	 * Create custom audit results table.
	 *
	 * @return void
	 */
	public static function create_tables() {
		global $wpdb;

		$table_name      = $wpdb->prefix . 'code_chaff_audits';
		$charset_collate = $wpdb->get_charset_collate();

		$sql = "CREATE TABLE {$table_name} (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			slug VARCHAR(255) NOT NULL,
			item_type ENUM('plugin','theme') NOT NULL,
			old_version VARCHAR(50) NOT NULL,
			new_version VARCHAR(50) NOT NULL,
			risk_level ENUM('secure','warning','critical') NOT NULL DEFAULT 'secure',
			report LONGTEXT NULL,
			completed_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
			PRIMARY KEY (id),
			KEY slug_type (slug, item_type),
			KEY completed_at (completed_at)
		) {$charset_collate};";

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		\dbDelta( $sql );
	}

	/**
	 * Register DeepSeek connector on Connectors screen.
	 *
	 * @return void
	 */
	public static function register_connector() {
		if ( ! \function_exists( 'wp_register_connector' ) ) {
			return;
		}

		\wp_register_connector(
			'deepseek',
			array(
				'name'        => 'DeepSeek AI',
				'description' => 'Provider for CodeChaff AI audits.',
			)
		);
	}

	/**
	 * Register the audit ability for MCP/Abilities API.
	 *
	 * @return void
	 */
	public static function register_ability() {
		if ( ! \function_exists( 'wp_register_ability' ) ) {
			return;
		}

		\wp_register_ability(
			'code-chaff/audit-update',
			array(
				'label'       => 'Audit plugin/theme update',
				'description' => 'Runs security and performance audit on a pending update.',
				'execute'     => array( __CLASS__, 'run_audit_ability' ),
				'meta'        => array(
					'mcp.public' => true,
					'mcp.type'   => 'action',
				),
			)
		);
	}

	/**
	 * Stub for the actual audit execution (Abilities API).
	 *
	 * @param array $args Input arguments.
	 * @return array Structured audit result.
	 */
	public static function run_audit_ability( $args ) {
		// Placeholder - will be implemented in later phases.
		return array(
			'status'  => 'queued',
			'message' => 'Audit job queued for background processing.',
		);
	}

	/**
	 * Check if Action Scheduler is available.
	 *
	 * @return bool True if Action Scheduler is active.
	 */
	public static function has_action_scheduler() {
		return \function_exists( 'as_enqueue_async_action' );
	}

	/**
	 * Queue an audit job for background processing.
	 *
	 * @param string $slug      Plugin or theme slug.
	 * @param string $item_type 'plugin' or 'theme'.
	 * @param string $old_ver   Currently installed version.
	 * @param string $new_ver   Target update version.
	 * @return int|bool Action ID or false on failure.
	 */
	public static function queue_audit_job( $slug, $item_type, $old_ver, $new_ver ) {
		$args = array(
			'slug'      => $slug,
			'item_type' => $item_type,
			'old_ver'   => $old_ver,
			'new_ver'   => $new_ver,
		);

		if ( self::has_action_scheduler() ) {
			return \as_enqueue_async_action( 'code_chaff_run_audit', $args );
		}

		// Fallback to WP-Cron.
		return \wp_schedule_single_event( time() + 5, 'code_chaff_run_audit', array( $args ) );
	}

	/**
	 * Fetch changed files manifest from WordPress.org Trac.
	 *
	 * @param string $slug      Item slug.
	 * @param string $item_type 'plugin' or 'theme'.
	 * @param string $old_ver   Old version.
	 * @param string $new_ver   New version.
	 * @return array List of changed .php and .js files.
	 */
	public static function fetch_changed_files( $slug, $item_type, $old_ver, $new_ver ) {
		$base = ( 'plugin' === $item_type )
			? 'https://plugins.trac.wordpress.org/log/'
			: 'https://themes.trac.wordpress.org/log/';

		$url = $base . $slug . '/?rev=' . $new_ver . '&mode=stop_on_copy&format=rss';

		$response = \wp_remote_get( $url, array( 'timeout' => 15 ) );
		if ( \is_wp_error( $response ) ) {
			return array();
		}

		$body = \wp_remote_retrieve_body( $response );
		// Very lightweight RSS parsing for file paths (real implementation would use XML parser).
		preg_match_all( '/<title>([^<]+)<\/title>/', $body, $matches );

		$files = array();
		foreach ( $matches[1] as $title ) {
			if ( preg_match( '/\.(php|js)$/', $title ) ) {
				$files[] = $title;
			}
		}

		return \array_unique( $files );
	}

	/**
	 * Fetch raw file content from SVN tag.
	 *
	 * @param string $slug     Item slug.
	 * @param string $version  Version tag.
	 * @param string $file     Relative file path.
	 * @return string Raw file contents or empty string on failure.
	 */
	public static function fetch_svn_file( $slug, $version, $file ) {
		$url      = 'https://plugins.svn.wordpress.org/' . $slug . '/tags/' . $version . '/' . ltrim( $file, '/' );
		$response = \wp_remote_get( $url, array( 'timeout' => 20 ) );

		if ( \is_wp_error( $response ) ) {
			return '';
		}

		return \wp_remote_retrieve_body( $response );
	}
}

// Bootstrap hooks (outside class per WP standards).
add_action( 'wp_connectors_init', array( 'CodeChaff\CodeChaff', 'register_connector' ) );
add_action( 'init', array( 'CodeChaff\CodeChaff', 'register_ability' ) );
register_activation_hook( \CODE_CHAFF_SETUP_ROOT, array( 'CodeChaff\CodeChaff', 'activate' ) );
