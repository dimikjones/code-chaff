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

// Load Action Scheduler (safe-loading: only loads if not already present).
require_once __DIR__ . '/vendor/woocommerce/action-scheduler/action-scheduler.php';

// Load required classes.
require_once __DIR__ . '/includes/class-code-chaff-settings.php';
require_once __DIR__ . '/includes/class-code-chaff-scanner.php';

// --- CONSTANTS ---
define( 'CODE_CHAFF_SETUP_DIR', __DIR__ );
define( 'CODE_CHAFF_SETUP_ROOT', __FILE__ );
define( 'CODE_CHAFF_SETUP_URL', plugin_dir_url( __FILE__ ) );
define( 'CODE_CHAFF_SETUP_VERSION', '0.1.0' );

/**
 * Main plugin class (all static per coding standards).
 */
class CodeChaff {

	/**
	 * In-memory cache for is_wordpress_org_plugin() results.
	 *
	 * @var array
	 */
	private static $org_plugin_cache = array();

	/**
	 * Plugin activation handler.
	 *
	 * @return void
	 */
	public static function activate() {
		self::create_tables();

		// Show a one-time admin notice on first activation.
		set_transient( 'code_chaff_activated', true, 30 );
	}

	/**
	 * Plugin deactivation handler.
	 *
	 * @return void
	 */
	public static function deactivate() {
		// Clear activation transient.
		delete_transient( 'code_chaff_activated' );

		// Cancel any pending Action Scheduler audit jobs.
		if ( \function_exists( 'as_unschedule_all_actions' ) ) {
			\as_unschedule_all_actions( 'code_chaff_run_audit' );
		}
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
	 * Register the audit ability for the WordPress 7.0 Abilities API.
	 *
	 * @return void
	 */
	public static function register_ability() {
		if ( ! \function_exists( 'wp_register_ability' ) ) {
			return;
		}

		// Register the CodeChaff ability category.
		if ( \function_exists( 'wp_register_ability_category' ) ) {
			\wp_register_ability_category(
				'code-chaff',
				array(
					'label'       => __( 'CodeChaff', 'code-chaff' ),
					'description' => __( 'Update audit abilities for security and performance analysis.', 'code-chaff' ),
				)
			);
		}

		\wp_register_ability(
			'code-chaff/audit-update',
			array(
				'label'               => __( 'Audit plugin/theme update', 'code-chaff' ),
				'description'         => __( 'Queues a security and performance audit on a pending plugin or theme update using AI.', 'code-chaff' ),
				'category'            => 'code-chaff',
				'execute_callback'    => array( __CLASS__, 'run_audit_ability' ),
				'permission_callback' => function () {
					return current_user_can( 'manage_options' );
				},
				'input_schema'        => array(
					'type'       => 'object',
					'properties' => array(
						'slug'      => array(
							'type'        => 'string',
							'description' => __( 'Plugin or theme slug.', 'code-chaff' ),
						),
						'item_type' => array(
							'type'        => 'string',
							'enum'        => array( 'plugin', 'theme' ),
							'description' => __( 'Whether the item is a plugin or theme.', 'code-chaff' ),
						),
						'old_ver'   => array(
							'type'        => 'string',
							'description' => __( 'Currently installed version.', 'code-chaff' ),
						),
						'new_ver'   => array(
							'type'        => 'string',
							'description' => __( 'Target update version.', 'code-chaff' ),
						),
					),
					'required'   => array( 'slug', 'item_type', 'old_ver', 'new_ver' ),
				),
				'meta'                => array(
					'annotations'  => array(
						'readonly'    => true,
						'destructive' => false,
						'idempotent'  => false,
					),
					'show_in_rest' => true,
				),
			)
		);
	}

	/**
	 * Execute the ability: queue an audit job via the Abilities API.
	 *
	 * @param array $args Input arguments with slug, item_type, old_ver, new_ver.
	 * @return array|\WP_Error Structured audit result or error.
	 */
	public static function run_audit_ability( $args ) {
		if ( empty( $args['slug'] ) || empty( $args['new_ver'] ) ) {
			return new \WP_Error(
				'ability_invalid_input',
				__( 'Missing required audit parameters.', 'code-chaff' )
			);
		}

		$action_id = self::queue_audit_job(
			$args['slug'],
			$args['item_type'] ?? 'plugin',
			$args['old_ver'] ?? '',
			$args['new_ver']
		);

		if ( ! $action_id ) {
			return new \WP_Error(
				'audit_queue_failed',
				__( 'Failed to queue audit job.', 'code-chaff' )
			);
		}

		return array(
			'status'    => 'queued',
			'action_id' => $action_id,
			'message'   => __( 'Audit job queued for background processing.', 'code-chaff' ),
		);
	}

	/**
	 * Get all available AI providers from the WordPress 7.0 Connectors API.
	 *
	 * Uses wp_get_connectors() which returns all registered connectors
	 * including auto-discovered AI providers from the WP AI Client registry.
	 *
	 * @return array Associative array of provider_id => provider_name.
	 */
	public static function get_available_providers() {
		if ( ! \function_exists( 'wp_get_connectors' ) ) {
			return array();
		}

		$connectors = \wp_get_connectors();
		$providers  = array();

		foreach ( $connectors as $id => $connector ) {
			if ( is_array( $connector ) && isset( $connector['type'] ) && 'ai_provider' === $connector['type'] ) {
				$providers[ $id ] = $connector['name'] ?? $id;
			}
		}

		return $providers;
	}

	/**
	 * Get the currently selected AI provider.
	 *
	 * @return string Provider ID or empty string.
	 */
	public static function get_selected_provider() {
		return get_option( CodeChaff_Settings::OPTION_NAME, '' );
	}

	/**
	 * Determine whether a plugin is hosted on WordPress.org.
	 *
	 * Checks three indicators in order (short-circuits on first match):
	 * 1. Absence of an UpdateURI header (third-party plugins define this).
	 * 2. Presence in the update_plugins transient (only .org plugins appear here).
	 * 3. HTTP HEAD check on the plugin's SVN directory on WordPress.org.
	 *
	 * Results are cached per-request in a static array.
	 *
	 * @param string $plugin_file Main plugin file path (e.g. 'akismet/akismet.php').
	 * @return bool True if the plugin appears to be hosted on WordPress.org.
	 */
	public static function is_wordpress_org_plugin( $plugin_file ) {
		if ( isset( self::$org_plugin_cache[ $plugin_file ] ) ) {
			return self::$org_plugin_cache[ $plugin_file ];
		}

		if ( ! function_exists( 'get_plugin_data' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}

		$plugin_data = get_plugin_data( WP_PLUGIN_DIR . '/' . $plugin_file, false, false );

		// Check 1: Third-party plugins define UpdateURI for their own update server.
		if ( ! empty( $plugin_data['UpdateURI'] ) ) {
			self::$org_plugin_cache[ $plugin_file ] = false;
			return false;
		}

		// Check 2: Look in the update_plugins transient for .org update data.
		$updates = get_site_transient( 'update_plugins' );
		if ( is_object( $updates ) && ! empty( $updates->response ) ) {
			if ( isset( $updates->response[ $plugin_file ] ) ) {
				self::$org_plugin_cache[ $plugin_file ] = true;
				return true;
			}
		}

		// Check 3: HTTP HEAD to the plugin's SVN directory on .org.
		$slug       = dirname( $plugin_file );
		$slug       = ( '.' === $slug ) ? basename( $plugin_file, '.php' ) : $slug;
		$svn_url    = 'https://plugins.svn.wordpress.org/' . $slug . '/';
		$response   = \wp_remote_head( $svn_url, array( 'timeout' => 10 ) );
		$is_dot_org = false;

		if ( ! \is_wp_error( $response ) && 200 === \wp_remote_retrieve_response_code( $response ) ) {
			$is_dot_org = true;
		}

		self::$org_plugin_cache[ $plugin_file ] = $is_dot_org;
		return $is_dot_org;
	}

	/**
	 * Determine whether a plugin slug belongs to a WordPress.org hosted plugin.
	 *
	 * Resolves a slug to its main plugin file and delegates to is_wordpress_org_plugin().
	 * Returns false if the slug cannot be resolved to an active plugin.
	 *
	 * @param string $slug Plugin slug.
	 * @return bool True if the plugin is hosted on WordPress.org.
	 */
	public static function is_wordpress_org_plugin_by_slug( $slug ) {
		if ( ! function_exists( 'get_plugins' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}

		$plugins = get_plugins();
		foreach ( $plugins as $plugin_file => $plugin_data ) {
			$plugin_slug = dirname( $plugin_file );
			$plugin_slug = ( '.' === $plugin_slug ) ? basename( $plugin_file, '.php' ) : $plugin_slug;
			if ( $plugin_slug === $slug ) {
				return self::is_wordpress_org_plugin( $plugin_file );
			}
		}

		return false;
	}

	/**
	 * Check if an AI provider is configured and available.
	 *
	 * @return bool
	 */
	public static function is_ai_configured() {
		$selected_provider = self::get_selected_provider();

		// If no provider is selected, check if any provider is available.
		if ( empty( $selected_provider ) ) {
			$available = self::get_available_providers();
			return ! empty( $available );
		}

		// Check if the selected provider is in the available list.
		$available = self::get_available_providers();
		return isset( $available[ $selected_provider ] );
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

		// Action Scheduler flattens associative args into positional arguments.
		// Wrap in a single-element array to preserve the structure.
		return \as_enqueue_async_action( 'code_chaff_run_audit', array( $args ) );
	}

	/**
	 * Download and extract a plugin/theme version ZIP from WordPress.org,
	 * then compute diffs between old and new versions.
	 *
	 * Uses downloads.wordpress.org ZIPs — far more reliable than SVN HTML scraping.
	 * Returns an array of per-file diffs with the same structure as before.
	 *
	 * @param string $slug      Item slug.
	 * @param string $item_type 'plugin' or 'theme'.
	 * @param string $old_ver   Old version.
	 * @param string $new_ver   New version.
	 * @return array|\WP_Error Array of diffs, or WP_Error on failure.
	 */
	public static function fetch_changed_files( $slug, $item_type, $old_ver, $new_ver ) {
		$old_dir = self::download_and_extract( $slug, $item_type, $old_ver );
		if ( \is_wp_error( $old_dir ) && 'code_chaff_version_not_found' !== $old_dir->get_error_code() ) {
			return $old_dir;
		}

		$new_dir = self::download_and_extract( $slug, $item_type, $new_ver );
		if ( \is_wp_error( $new_dir ) ) {
			self::cleanup_temp_dir( $old_dir );
			return $new_dir;
		}

		$results = array();

		// Both versions available — scan all PHP/JS files in the new version.
		$old_files = is_string( $old_dir ) ? self::scan_code_files( $old_dir ) : array();
		$new_files = self::scan_code_files( $new_dir );

		foreach ( $new_files as $rel_path ) {
			$new_file_path = $new_dir . '/' . $rel_path;
			$new_content   = @file_get_contents( $new_file_path );
			if ( false === $new_content ) {
				continue;
			}
			$has_old       = is_string( $old_dir ) && in_array( $rel_path, $old_files, true );
			$old_file_path = $has_old ? $old_dir . '/' . $rel_path : null;
			$old_content   = $old_file_path ? (string) @file_get_contents( $old_file_path ) : '';
			if ( false === $old_content ) {
				$old_content = '';
			}

			if ( ! $has_old ) {
				// New file.
				$results[] = array(
					'file'       => $rel_path,
					'diff'       => (string) $new_content,
					'is_new'     => true,
					'is_deleted' => false,
				);
			} elseif ( $old_content !== $new_content ) {
				// Changed file — compute unified diff.
				$diff      = self::compute_text_diff( $old_content, $new_content, $rel_path );
				$results[] = array(
					'file'       => $rel_path,
					'diff'       => $diff,
					'is_new'     => false,
					'is_deleted' => false,
				);
			}
		}

		// Deleted files (present in old, not in new).
		if ( is_string( $old_dir ) ) {
			foreach ( $old_files as $rel_path ) {
				if ( ! in_array( $rel_path, $new_files, true ) ) {
					$results[] = array(
						'file'       => $rel_path,
						'diff'       => '[File deleted in new version]',
						'is_new'     => false,
						'is_deleted' => true,
					);
				}
			}
		}

		self::cleanup_temp_dir( $old_dir );
		self::cleanup_temp_dir( $new_dir );

		if ( empty( $results ) ) {
			return new \WP_Error(
				'code_chaff_no_changes',
				__( 'No code changes found between the versions.', 'code-chaff' )
			);
		}

		return $results;
	}

	/**
	 * Download a plugin/theme ZIP from WordPress.org and extract it.
	 *
	 * @param string $slug      Item slug.
	 * @param string $item_type 'plugin' or 'theme'.
	 * @param string $version   Version number.
	 * @return string|\WP_Error Path to extracted directory, or WP_Error.
	 */
	private static function download_and_extract( $slug, $item_type, $version ) {
		$base = ( 'theme' === $item_type ) ? 'theme' : 'plugin';
		$url  = "https://downloads.wordpress.org/{$base}/{$slug}.{$version}.zip";

		$tmp_zip = self::get_temp_dir() . '/' . $slug . '-' . $version . '.zip';
		$tmp_ext = self::get_temp_dir() . '/' . $slug . '-' . $version;

		// Check if already extracted (within this request).
		if ( is_dir( $tmp_ext ) ) {
			// Only reuse if a previous extraction completed successfully.
			if ( file_exists( $tmp_ext . '/.extraction_complete' ) ) {
				return $tmp_ext;
			}
			// Incomplete extraction from a previous run — clean up and re-download.
			self::cleanup_temp_dir( $tmp_ext );
		}

		// Download the ZIP.
		$response = \wp_remote_get(
			$url,
			array(
				'timeout'  => 60,
				'stream'   => true,
				'filename' => $tmp_zip,
			)
		);

		if ( \is_wp_error( $response ) ) {
			return new \WP_Error(
				'code_chaff_download_failed',
				sprintf(
					/* translators: 1: slug, 2: error */
					__( 'Failed to download %1$s version %2$s: %3$s', 'code-chaff' ),
					$slug,
					$version,
					$response->get_error_message()
				)
			);
		}

		$code = \wp_remote_retrieve_response_code( $response );
		if ( 404 === $code ) {
			return new \WP_Error(
				'code_chaff_version_not_found',
				sprintf(
					/* translators: 1: slug, 2: version */
					__( 'Version %2$s of %1$s does not exist on WordPress.org.', 'code-chaff' ),
					$slug,
					$version
				)
			);
		}

		if ( 200 !== $code ) {
			@unlink( $tmp_zip );
			return new \WP_Error(
				'code_chaff_download_failed',
				sprintf(
					/* translators: 1: slug, 2: HTTP code */
					__( 'Download failed for %1$s (HTTP %2$d).', 'code-chaff' ),
					$slug,
					$code
				)
			);
		}

		// Some hosting environments don't support stream + filename; retry without.
		if ( ! file_exists( $tmp_zip ) || 0 === filesize( $tmp_zip ) ) {
			$body    = \wp_remote_retrieve_body( $response );
			$written = file_put_contents( $tmp_zip, $body );
			if ( false === $written || 0 === $written ) {
				return new \WP_Error(
					'code_chaff_download_failed',
					__( 'Could not save the downloaded ZIP file.', 'code-chaff' )
				);
			}
		}

		// Extract the ZIP.
		$extracted = self::extract_zip( $tmp_zip, $tmp_ext );
		@unlink( $tmp_zip );

		if ( \is_wp_error( $extracted ) ) {
			return $extracted;
		}

		// Mark extraction as complete so it can be safely reused.
		@file_put_contents( $tmp_ext . '/.extraction_complete', '' );

		return $tmp_ext;
	}

	/**
	 * Extract a ZIP file to a destination directory.
	 *
	 * @param string $zip_path Path to the ZIP file.
	 * @param string $dest_dir Destination directory.
	 * @return true|\WP_Error
	 */
	private static function extract_zip( $zip_path, $dest_dir ) {
		if ( ! class_exists( 'ZipArchive' ) ) {
			// Fallback for hosts without ZipArchive.
			require_once ABSPATH . 'wp-admin/includes/file.php';
			$result = unzip_file( $zip_path, $dest_dir );
			if ( \is_wp_error( $result ) ) {
				return $result;
			}
			self::fix_extracted_permissions( $dest_dir );
			return true;
		}

		$zip = new \ZipArchive();
		if ( true !== $zip->open( $zip_path ) ) {
			return new \WP_Error(
				'code_chaff_unzip_failed',
				__( 'Could not open the downloaded ZIP archive.', 'code-chaff' )
			);
		}

		// WordPress ZIPs have a single root folder (e.g. akismet/).
		// Extract, then move contents up one level if nested.
		$zip->extractTo( $dest_dir );
		$zip->close();

		// Fix permissions inherited from the ZIP archive to ensure
		// all directories are traversable and all files are readable/deletable.
		self::fix_extracted_permissions( $dest_dir );

		// Handle nested WordPress plugin folder.
		$entries = scandir( $dest_dir );
		$entries = array_diff( (array) $entries, array( '.', '..' ) );

		if ( 1 === count( $entries ) ) {
			$inner = $dest_dir . '/' . reset( $entries );
			if ( is_dir( $inner ) ) {
				// Move contents of inner directory up to dest_dir.
				$inner_files = scandir( $inner );
				foreach ( $inner_files as $f ) {
					if ( '.' === $f || '..' === $f ) {
						continue;
					}
					rename( $inner . '/' . $f, $dest_dir . '/' . $f );
				}
				@rmdir( $inner );
			}
		}

		return true;
	}

	/**
	 * Recursively fix permissions on an extracted directory.
	 *
	 * ZIP archives can store restrictive permissions that prevent reading
	 * or deleting extracted files. This ensures all directories are
	 * traversable (0755) and all files are readable (0644).
	 *
	 * @param string $dir Directory path.
	 * @return void
	 */
	private static function fix_extracted_permissions( $dir ) {
		if ( ! is_dir( $dir ) ) {
			return;
		}

		@chmod( $dir, 0755 );

		$entries = @scandir( $dir );
		if ( ! is_array( $entries ) ) {
			return;
		}

		foreach ( $entries as $entry ) {
			if ( '.' === $entry || '..' === $entry ) {
				continue;
			}
			$path = $dir . '/' . $entry;
			if ( is_dir( $path ) ) {
				// chmod BEFORE recursing — so we can enter it.
				self::fix_extracted_permissions( $path );
			} else {
				@chmod( $path, 0644 );
			}
		}
	}

	/**
	 * Recursively scan a directory for .php and .js files.
	 *
	 * @param string $dir Directory path.
	 * @return array List of relative file paths.
	 */
	private static function scan_code_files( $dir ) {
		if ( ! is_dir( $dir ) ) {
			return array();
		}

		$files   = array();
		$dir_len = strlen( rtrim( $dir, '/\\' ) ) + 1;
		$iter    = new \RecursiveIteratorIterator(
			new \RecursiveDirectoryIterator(
				$dir,
				\FilesystemIterator::KEY_AS_PATHNAME | \FilesystemIterator::CURRENT_AS_FILEINFO | \FilesystemIterator::SKIP_DOTS
			),
			\RecursiveIteratorIterator::LEAVES_ONLY,
			\RecursiveIteratorIterator::CATCH_GET_CHILD
		);

		try {
			foreach ( $iter as $item ) {
				if ( ! $item->isFile() ) {
					continue;
				}
				$ext = strtolower( $item->getExtension() );
				if ( 'php' !== $ext && 'js' !== $ext ) {
					continue;
				}
				$rel     = substr( $item->getPathname(), $dir_len );
				$files[] = str_replace( '\\', '/', $rel );
			}
		} catch ( \UnexpectedValueException $e ) {
			// Directory with bad permissions (e.g. fonts/ from a ZIP).
			// Skip it; the iterator will continue from the next valid entry.
			error_log( '[CodeChaff] Skipping unreadable path during scan: ' . $e->getMessage() );
		}

		sort( $files );
		return $files;
	}

	/**
	 * Get or create the CodeChaff temp directory.
	 *
	 * @return string Path to temp directory.
	 */
	private static function get_temp_dir() {
		$upload_dir = wp_upload_dir();
		$tmp_dir    = $upload_dir['basedir'] . '/code-chaff-temp';

		if ( ! is_dir( $tmp_dir ) ) {
			wp_mkdir_p( $tmp_dir );
		}

		return $tmp_dir;
	}

	/**
	 * Clean up a temp extraction directory.
	 *
	 * @param string|\WP_Error $dir Directory path or WP_Error.
	 * @return void
	 */
	private static function cleanup_temp_dir( $dir ) {
		if ( ! is_string( $dir ) || ! is_dir( $dir ) || 0 !== strpos( $dir, self::get_temp_dir() ) ) {
			return;
		}

		// Safety: only delete dirs inside our temp dir.
		// Suppress warnings — "Text file busy" can happen on Linux when
		// directory iterators from scan_code_files() still hold handles.
		try {
			$iter = new \RecursiveIteratorIterator(
				new \RecursiveDirectoryIterator( $dir, \RecursiveDirectoryIterator::SKIP_DOTS ),
				\RecursiveIteratorIterator::CHILD_FIRST
			);

			foreach ( $iter as $item ) {
				if ( $item->isDir() ) {
					@rmdir( $item->getPathname() );
				} else {
					@unlink( $item->getPathname() );
				}
			}

			$iter = null; // Release iterator handles.
			clearstatcache( true, $dir );
			@rmdir( $dir );
		} catch ( \UnexpectedValueException $e ) {
			// Best-effort cleanup; temp dirs will be cleaned on next run.
			error_log( '[CodeChaff] Could not fully clean temp dir: ' . $e->getMessage() );
		}
	}

	/**
	 * Compute a unified diff between two text contents.
	 *
	 * Uses WordPress core WP_Text_Diff_Engine when available,
	 * falls back to a simple line-by-line comparison.
	 *
	 * @param string $old_text Old file content.
	 * @param string $new_text New file content.
	 * @param string $filename File name for the diff header.
	 * @return string Unified diff.
	 */
	private static function compute_text_diff( $old_text, $new_text, $filename ) {
		// Try WordPress core text diff engine.
		if ( class_exists( 'WP_Text_Diff_Engine' ) ) {
			$old_lines = explode( "\n", $old_text );
			$new_lines = explode( "\n", $new_text );

			$engine = new \WP_Text_Diff_Engine();
			$diff   = $engine->diff( $old_lines, $new_lines );

			if ( $diff ) {
				$renderer = new \WP_Text_Diff_Renderer_inline();
				return $renderer->render( $diff );
			}
		}

		// If texts are identical (or diff engine unavailable), return empty.
		if ( $old_text === $new_text ) {
			return '';
		}

		// Simple fallback diff.
		return self::simple_diff( $old_text, $new_text, $filename );
	}

	/**
	 * Simple line-by-line unified diff fallback.
	 *
	 * @param string $old_text Old content.
	 * @param string $new_text New content.
	 * @param string $filename File name.
	 * @return string Unified diff.
	 */
	private static function simple_diff( $old_text, $new_text, $filename ) {
		$old_lines = explode( "\n", $old_text );
		$new_lines = explode( "\n", $new_text );

		$output  = "--- a/{$filename}\n";
		$output .= "+++ b/{$filename}\n";

		// Build a line-keyed map for basic comparison.
		$old_map = array();
		foreach ( $old_lines as $i => $line ) {
			$old_map[ $i ] = trim( $line );
		}
		$new_map = array();
		foreach ( $new_lines as $i => $line ) {
			$new_map[ $i ] = trim( $line );
		}

		$max = max( count( $old_lines ), count( $new_lines ) );

		for ( $i = 0; $i < $max; $i++ ) {
			$old_line = $old_lines[ $i ] ?? null;
			$new_line = $new_lines[ $i ] ?? null;

			if ( ! isset( $old_lines[ $i ] ) ) {
				$output .= "+{$new_line}\n";
			} elseif ( ! isset( $new_lines[ $i ] ) ) {
				$output .= "-{$old_line}\n";
			} elseif ( $old_line !== $new_line ) {
				$output .= "-{$old_line}\n+{$new_line}\n";
			}
		}

		return $output;
	}

	/**
	 * Execute the actual AI audit (called by async action).
	 *
	 * Phase 1: Downloads and extracts both plugin/theme versions.
	 * Phase 2: Runs a lightweight security scanner on changed files.
	 * Phase 3: Sends scanner findings with code windows to the AI for classification.
	 * Phase 4: Stores the triaged report.
	 *
	 * @param array $args Job arguments.
	 * @return void
	 */
	public static function run_audit( $args ) {
		// Action Scheduler wraps args in a numeric array. Unwrap.
		if ( is_array( $args ) && isset( $args[0] ) && is_array( $args[0] ) ) {
			$args = $args[0];
		}

		error_log( print_r( $args, true ) );

		// Allow long-running audits.
		set_time_limit( 300 );

		$slug      = $args['slug'] ?? '';
		$item_type = $args['item_type'] ?? 'plugin';
		$old_ver   = $args['old_ver'] ?? '';
		$new_ver   = $args['new_ver'] ?? '';

		if ( ! $slug || ! $new_ver ) {
			error_log($slug);
			error_log($new_ver);
			error_log('RETURNED');
			return;
		}

		// Guard: WordPress 7 AI Client must be available.
		if ( ! \function_exists( 'wp_ai_client_prompt' ) || ! \function_exists( 'wp_supports_ai' ) || ! \wp_supports_ai() ) {
			error_log( '[CodeChaff] AI client not available. Skipping audit for ' . $slug );
			return;
		}

		// Phase 1: Download and diff.
		error_log( '[CodeChaff Debug] Starting audit: ' . $slug . ' ' . $old_ver . '→' . $new_ver );
		$changed_files = self::fetch_changed_files( $slug, $item_type, $old_ver, $new_ver );

		if ( \is_wp_error( $changed_files ) ) {
			error_log( '[CodeChaff] ' . $changed_files->get_error_message() );
			global $wpdb;
			$table = $wpdb->prefix . 'code_chaff_audits';
			$wpdb->insert(
				$table,
				array(
					'slug'         => $slug,
					'item_type'    => $item_type,
					'old_version'  => $old_ver,
					'new_version'  => $new_ver,
					'risk_level'   => 'secure',
					'report'       => wp_json_encode(
						array(
							'error' => $changed_files->get_error_code(),
							'note'  => $changed_files->get_error_message(),
						)
					),
					'completed_at' => current_time( 'mysql' ),
				),
				array( '%s', '%s', '%s', '%s', '%s', '%s', '%s' )
			);
			return;
		}

		// Limit files per job to prevent timeouts (max 25 changed files).
		$max_files     = 25;
		$changed_files = array_slice( $changed_files, 0, $max_files );

		\wp_suspend_cache_addition( true );

		// Phase 2: Run the lightweight scanner on each changed file.
		$all_findings = array();
		$report_files = array();

		foreach ( $changed_files as $change ) {
			$file       = $change['file'];
			$is_new     = $change['is_new'] ?? false;
			$is_deleted = $change['is_deleted'] ?? false;

			$report_files[ $file ] = array(
				'is_new'     => $is_new,
				'is_deleted' => $is_deleted,
			);

			if ( $is_deleted ) {
				continue;
			}

			// Only scan .php files (JS auditing would need a different scanner).
			if ( '.php' !== strtolower( substr( $file, -4 ) ) ) {
				continue;
			}

			$diff     = $change['diff'];
			$findings = CodeChaff_Scanner::scan( $diff, $file );

			if ( ! empty( $findings ) ) {
				$all_findings = array_merge( $all_findings, $findings );
			}
		}

		$report = array(
			'files'   => $report_files,
			'scanner' => array(
				'total_findings' => count( $all_findings ),
			),
			'triage'  => array(),
		);

		error_log( '[CodeChaff Debug] Scanner completed. Changed files: ' . count( $changed_files ) . ', findings: ' . count( $all_findings ) );

		// Phase 3: AI triage of scanner findings (if any).
		if ( ! empty( $all_findings ) ) {
			// Always include the raw scanner findings for reference.
			$report['scanner']['findings'] = array_slice( $all_findings, 0, 15 );

			try {
				$blocks   = array();
				$max_find = min( 15, count( $all_findings ) );

				for ( $i = 0; $i < $max_find; $i++ ) {
					$f        = $all_findings[ $i ];
					$blocks[] = sprintf(
						"### Finding %d\nFile: %s\nLine: %d\nRule: %s\nMessage: %s\n\nCode:\n%s\n",
						$i + 1,
						$f['file'],
						$f['line'],
						$f['rule'],
						$f['message'],
						$f['code']
					);
				}

				$findings_text = implode( "\n", $blocks );
				$triage_schema = array(
					'type'       => 'object',
					'properties' => array(
						'verdicts' => array(
							'type'  => 'array',
							'items' => array(
								'type'       => 'object',
								'properties' => array(
									'finding_id' => array( 'type' => 'integer' ),
									'verdict'    => array(
										'type' => 'string',
										'enum' => array( 'LIKELY REAL', 'LIKELY FALSE POSITIVE', 'CANNOT TELL' ),
									),
									'reasoning'  => array( 'type' => 'string' ),
								),
								'required'   => array( 'finding_id', 'verdict', 'reasoning' ),
							),
						),
						'summary'  => array( 'type' => 'string' ),
					),
					'required'   => array( 'verdicts', 'summary' ),
				);

				$triage_builder = \wp_ai_client_prompt()
					->with_text( $findings_text )
					->using_system_instruction(
						'You are a senior PHP/WordPress security reviewer triaging automated code-scanner ' .
						'findings from a plugin update diff. The scanner produces many false positives — ' .
						'your job is classification. For EACH finding numbered #1–#N: examine the code ' .
						'window (>> marks the flagged line). State LIKELY REAL if exploitable or bad practice, ' .
						'LIKELY FALSE POSITIVE if the code is safe (escaping happens elsewhere, input validated ' .
						'before this line, nonce checked in a parent function, etc.), or CANNOT TELL if the ' .
						'window is too small to determine. Prefer CANNOT TELL over guessing. Do NOT describe ' .
						'findings not present in the input. Provide one overall summary recommendation after ' .
						'all verdicts. Output valid JSON only.'
					)
					->as_json_response( $triage_schema );

				$triage_result = $triage_builder->generate_text();

				if ( ! \is_wp_error( $triage_result ) ) {
					$decoded          = json_decode( $triage_result, true );
					$report['triage'] = $decoded ? $decoded : array( 'raw' => $triage_result );
				} else {
					$report['triage'] = array( 'error' => $triage_result->get_error_message() );
				}
			} catch ( \Throwable $e ) {
				// Guard against provider bugs (e.g. incorrect constructor arguments).
				// The scanner findings are stored in the report regardless.
				error_log( '[CodeChaff] AI triage failed: ' . $e->getMessage() );
				$report['triage'] = array(
					'error'   => 'ai_triage_exception',
					'message' => $e->getMessage(),
				);
			}
		}

		\wp_suspend_cache_addition( false );

		// Compute risk level from triage verdicts.
		$risk = self::compute_triage_risk_level( $report );

		error_log( '[CodeChaff Debug] Risk computed: ' . $risk . ', report size: ' . strlen( wp_json_encode( $report ) ) . ' bytes' );

		global $wpdb;
		$table = $wpdb->prefix . 'code_chaff_audits';

		$inserted = $wpdb->insert(
			$table,
			array(
				'slug'         => $slug,
				'item_type'    => $item_type,
				'old_version'  => $old_ver,
				'new_version'  => $new_ver,
				'risk_level'   => $risk,
				'report'       => wp_json_encode( $report ),
				'completed_at' => current_time( 'mysql' ),
			),
			array( '%s', '%s', '%s', '%s', '%s', '%s', '%s' )
		);

		if ( false === $inserted ) {
			error_log( '[CodeChaff Debug] DB insert FAILED: ' . $wpdb->last_error );
		} else {
			error_log( '[CodeChaff Debug] DB insert OK, rows: ' . $inserted );
		}
	}

	/**
	 * Compute risk level from AI triage verdicts.
	 *
	 * @param array $report The full audit report.
	 * @return string 'secure', 'warning', or 'critical'.
	 */
	private static function compute_triage_risk_level( $report ) {
		$real_count = 0;
		$total      = 0;

		if ( ! empty( $report['triage']['verdicts'] ) && is_array( $report['triage']['verdicts'] ) ) {
			foreach ( $report['triage']['verdicts'] as $v ) {
				++$total;
				if ( isset( $v['verdict'] ) && 'LIKELY REAL' === strtoupper( $v['verdict'] ) ) {
					++$real_count;
				}
			}
		}

		if ( $real_count >= 3 ) {
			return 'critical';
		}
		if ( $real_count >= 1 ) {
			return 'warning';
		}

		// If no triage but scanner found things, mark as warning.
		if ( ! empty( $report['scanner']['total_findings'] ) && $report['scanner']['total_findings'] > 0 ) {
			return 'warning';
		}

		return 'secure';
	}

	/**
	 * Compute overall risk level from structured audit report.
	 *
	 * @param array $report The audit report with security and performance results.
	 * @return string 'secure', 'warning', or 'critical'.
	 */
	private static function compute_risk_level( $report ) {
		$max_severity = 0; // 0 = none, 1 = low/info, 2 = medium, 3 = high, 4 = critical.

		$severity_map = array(
			'critical' => 4,
			'high'     => 3,
			'medium'   => 2,
			'low'      => 1,
			'info'     => 1,
		);

		foreach ( array( 'security', 'performance' ) as $section ) {
			if ( empty( $report[ $section ] ) ) {
				continue;
			}

			foreach ( $report[ $section ] as $result ) {
				if ( \is_wp_error( $result ) || ! is_string( $result ) ) {
					continue;
				}

				$decoded = json_decode( $result, true );
				if ( ! is_array( $decoded ) || empty( $decoded['issues'] ) ) {
					continue;
				}

				foreach ( $decoded['issues'] as $issue ) {
					$severity = strtolower( $issue['severity'] ?? '' );
					if ( isset( $severity_map[ $severity ] ) ) {
						$max_severity = max( $max_severity, $severity_map[ $severity ] );
					}
				}
			}
		}

		if ( $max_severity >= 4 ) {
			return 'critical';
		}
		if ( $max_severity >= 2 ) {
			return 'warning';
		}
		return 'secure';
	}

	/**
	 * Enqueue admin scripts on plugin/theme update screens.
	 *
	 * @return void
	 */
	public static function enqueue_admin_scripts() {
		$screen = get_current_screen();
		if ( ! $screen || ! in_array( $screen->id, array( 'plugins', 'themes' ), true ) ) {
			return;
		}

		// Only show AI Audit UI when an AI provider is configured.
		if ( ! self::is_ai_configured() ) {
			return;
		}

		$slug_map       = self::get_plugin_slugs_map( true );
		$version_map    = self::get_plugin_versions_map( true );
		$theme_versions = self::get_theme_versions_map();

		wp_enqueue_script(
			'code-chaff-admin',
			CODE_CHAFF_SETUP_URL . 'assets/js/admin.js',
			array( 'jquery' ),
			CODE_CHAFF_SETUP_VERSION,
			true
		);

		wp_localize_script(
			'code-chaff-admin',
			'CodeChaffAdmin',
			array(
				'ajaxUrl'        => admin_url( 'admin-ajax.php' ),
				'nonce'          => wp_create_nonce( 'code_chaff_audit' ),
				'pluginSlugs'    => $slug_map,
				'pluginVersions' => $version_map,
				'themeVersions'  => $theme_versions,
			)
		);
	}

	/**
	 * Build a map of plugin file => slug for the admin JS.
	 *
	 * @param bool $org_only If true, only include WordPress.org hosted plugins.
	 * @return array
	 */
	private static function get_plugin_slugs_map( $org_only = false ) {
		if ( ! function_exists( 'get_plugins' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}

		$plugins = get_plugins();
		$map     = array();

		foreach ( $plugins as $plugin_file => $plugin_data ) {
			if ( $org_only && ! self::is_wordpress_org_plugin( $plugin_file ) ) {
				continue;
			}
			$slug                = dirname( $plugin_file );
			$map[ $plugin_file ] = ( '.' === $slug ) ? basename( $plugin_file, '.php' ) : $slug;
		}

		return $map;
	}

	/**
	 * Build a map of plugin slug => installed version.
	 *
	 * @param bool $org_only If true, only include WordPress.org hosted plugins.
	 * @return array
	 */
	private static function get_plugin_versions_map( $org_only = false ) {
		if ( ! function_exists( 'get_plugins' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}

		$plugins = get_plugins();
		$map     = array();

		foreach ( $plugins as $plugin_file => $plugin_data ) {
			if ( $org_only && ! self::is_wordpress_org_plugin( $plugin_file ) ) {
				continue;
			}
			$slug         = dirname( $plugin_file );
			$slug         = ( '.' === $slug ) ? basename( $plugin_file, '.php' ) : $slug;
			$map[ $slug ] = $plugin_data['Version'] ?? '';
		}

		return $map;
	}

	/**
	 * Build a map of theme slug => installed version.
	 *
	 * @return array
	 */
	private static function get_theme_versions_map() {
		$themes = wp_get_themes();
		$map    = array();

		foreach ( $themes as $slug => $theme ) {
			$map[ $slug ] = $theme->get( 'Version' ) ?? '';
		}

		return $map;
	}

	/**
	 * AJAX handler – queue audit job from update row button.
	 *
	 * @return void
	 */
	public static function ajax_queue_audit() {
		check_ajax_referer( 'code_chaff_audit', 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( __( 'Insufficient permissions.', 'code-chaff' ), 403 );
		}

		$slug      = sanitize_text_field( wp_unslash( $_POST['slug'] ?? '' ) );
		$item_type = sanitize_text_field( wp_unslash( $_POST['item_type'] ?? 'plugin' ) );
		$old_ver   = sanitize_text_field( wp_unslash( $_POST['old_ver'] ?? '' ) );
		$new_ver   = sanitize_text_field( wp_unslash( $_POST['new_ver'] ?? '' ) );

		if ( ! $slug || ! $new_ver ) {
			wp_send_json_error( __( 'Invalid request: missing slug or version.', 'code-chaff' ) );
		}

		// Server-side guard: plugins must be hosted on WordPress.org.
		if ( 'plugin' === $item_type && ! self::is_wordpress_org_plugin_by_slug( $slug ) ) {
			wp_send_json_error(
				__( 'AI Audit is only available for plugins hosted on WordPress.org. Premium and third-party plugins are not supported yet.', 'code-chaff' )
			);
		}

		$action_id = self::queue_audit_job( $slug, $item_type, $old_ver, $new_ver );

		wp_send_json_success( array( 'action_id' => $action_id ) );
	}
}

// Load text domain for translations.
add_action(
	'init',
	function () {
		load_plugin_textdomain(
			'code-chaff',
			false,
			dirname( plugin_basename( \CODE_CHAFF_SETUP_ROOT ) ) . '/languages'
		);
	}
);

// Display admin notice on first activation.
add_action(
	'admin_notices',
	function () {
		if ( ! get_transient( 'code_chaff_activated' ) ) {
			return;
		}
		delete_transient( 'code_chaff_activated' );
		?>
		<div class="notice notice-success is-dismissible">
			<p>
				<?php
				printf(
					/* translators: %s: URL to CodeChaff settings */
					esc_html__(
						'CodeChaff is active. Configure your AI provider in the %s to start auditing plugin and theme updates.',
						'code-chaff'
					),
					'<a href="' . esc_url( admin_url( 'admin.php?page=code-chaff' ) ) . '">' . esc_html__( 'Settings page', 'code-chaff' ) . '</a>'
				);
				?>
			</p>
		</div>
		<?php
	}
);

// Register the async action handler.
add_action( 'code_chaff_run_audit', array( 'CodeChaff\CodeChaff', 'run_audit' ) );

// Admin hooks.
add_action( 'admin_enqueue_scripts', array( 'CodeChaff\CodeChaff', 'enqueue_admin_scripts' ) );
add_action( 'wp_ajax_code_chaff_queue_audit', array( 'CodeChaff\CodeChaff', 'ajax_queue_audit' ) );

// Register settings page.
CodeChaff_Settings::init();

// Bootstrap abilities.
add_action( 'init', array( 'CodeChaff\CodeChaff', 'register_ability' ) );

register_activation_hook( \CODE_CHAFF_SETUP_ROOT, array( 'CodeChaff\CodeChaff', 'activate' ) );
register_deactivation_hook( \CODE_CHAFF_SETUP_ROOT, array( 'CodeChaff\CodeChaff', 'deactivate' ) );