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

// Load settings class.
require_once __DIR__ . '/includes/class-code-chaff-settings.php';

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

		// Unschedule any pending WP-Cron audit hooks.
		$cron = _get_cron_array();
		if ( is_array( $cron ) ) {
			foreach ( $cron as $timestamp => $hooks ) {
				if ( isset( $hooks['code_chaff_run_audit'] ) ) {
					unset( $cron[ $timestamp ]['code_chaff_run_audit'] );
					if ( empty( $cron[ $timestamp ] ) ) {
						unset( $cron[ $timestamp ] );
					}
				}
			}
			_set_cron_array( $cron );
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
	 * Check if Action Scheduler is available.
	 *
	 * @return bool True if Action Scheduler is active.
	 */
	public static function has_action_scheduler() {
		return \function_exists( 'as_enqueue_async_action' );
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

		if ( self::has_action_scheduler() ) {
			return \as_enqueue_async_action( 'code_chaff_run_audit', $args );
		}

		// Fallback to WP-Cron.
		return \wp_schedule_single_event( time() + 5, 'code_chaff_run_audit', array( $args ) );
	}

	/**
	 * Fetch changed files and their diffs between two versions via Trac/SVN.
	 *
	 * Uses Trac's changeset diff endpoint to get a unified diff between
	 * two version tags. Falls back to fetching individual files from SVN
	 * and computing diffs locally if Trac diff is unavailable.
	 *
	 * @param string $slug      Item slug.
	 * @param string $item_type 'plugin' or 'theme'.
	 * @param string $old_ver   Old version.
	 * @param string $new_ver   New version.
	 * @return array|\WP_Error Array of diffs, or WP_Error on failure.
	 */
	public static function fetch_changed_files( $slug, $item_type, $old_ver, $new_ver ) {
		$base      = ( 'plugin' === $item_type ) ? 'plugins' : 'themes';
		$trac_root = "https://{$base}.trac.wordpress.org";

		// Try Trac changeset diff first (most reliable for getting actual diffs).
		$diff_url = "{$trac_root}/changeset?" . http_build_query(
			array(
				'new'    => "tags/{$new_ver}",
				'old'    => "tags/{$old_ver}",
				'format' => 'diff',
			)
		);

		$response = \wp_remote_get( $diff_url, array( 'timeout' => 30 ) );
		if ( \is_wp_error( $response ) ) {
			error_log(
				'[CodeChaff] Trac changeset request failed: ' . $response->get_error_message()
			);
		} else {
			$body = \wp_remote_retrieve_body( $response );
			$code = \wp_remote_retrieve_response_code( $response );

			if ( 200 === $code && ! empty( $body ) ) {
				$parsed = self::parse_unified_diff( $body );
				if ( ! empty( $parsed ) ) {
					return $parsed;
				}
			}

			if ( 404 === $code ) {
				error_log(
					"[CodeChaff] Trac changeset returned 404 for {$slug}: " .
					"version tags {$old_ver} or {$new_ver} likely do not exist."
				);
			}
		}

		// Fallback: fetch file trees from both SVN tags and compare.
		$fallback = self::fetch_changed_files_fallback( $slug, $item_type, $old_ver, $new_ver );
		if ( empty( $fallback ) ) {
			// Distinguish between "no files changed" and "could not contact SVN at all."
			$check_old = self::fetch_svn_file( $slug, $item_type, $old_ver, 'readme.txt' );
			$check_new = self::fetch_svn_file( $slug, $item_type, $new_ver, 'readme.txt' );
			if ( empty( $check_old ) && empty( $check_new ) ) {
				return new \WP_Error(
					'code_chaff_svn_unreachable',
					sprintf(
						/* translators: %s: plugin/theme slug */
						__( 'Could not contact WordPress.org SVN for %s. Verify the slug and network connectivity.', 'code-chaff' ),
						$slug
					)
				);
			}
		}

		return $fallback;
	}

	/**
	 * Fallback: compare file trees between two SVN tags and compute diffs locally.
	 *
	 * @param string $slug      Item slug.
	 * @param string $item_type 'plugin' or 'theme'.
	 * @param string $old_ver   Old version.
	 * @param string $new_ver   New version.
	 * @return array Array of diff entries.
	 */
	private static function fetch_changed_files_fallback( $slug, $item_type, $old_ver, $new_ver ) {
		$old_files = self::list_svn_files( $slug, $item_type, $old_ver );
		$new_files = self::list_svn_files( $slug, $item_type, $new_ver );

		$results = array();

		// Files present in both — compute diff.
		foreach ( $new_files as $file ) {
			if ( in_array( $file, $old_files, true ) ) {
				$old_content = self::fetch_svn_file( $slug, $item_type, $old_ver, $file );
				$new_content = self::fetch_svn_file( $slug, $item_type, $new_ver, $file );

				if ( $old_content !== $new_content && ! empty( $new_content ) ) {
					$diff      = self::compute_text_diff( $old_content, $new_content, $file );
					$results[] = array(
						'file'       => $file,
						'diff'       => $diff,
						'is_new'     => false,
						'is_deleted' => false,
					);
				}
			} elseif ( ! in_array( $file, $old_files, true ) ) {
				// New file — include full content as the diff.
				$content = self::fetch_svn_file( $slug, $item_type, $new_ver, $file );
				if ( ! empty( $content ) ) {
					$results[] = array(
						'file'       => $file,
						'diff'       => $content,
						'is_new'     => true,
						'is_deleted' => false,
					);
				}
			}
		}

		// Deleted files (present in old, not in new).
		foreach ( $old_files as $file ) {
			if ( ! in_array( $file, $new_files, true ) ) {
				$results[] = array(
					'file'       => $file,
					'diff'       => '[File deleted in new version]',
					'is_new'     => false,
					'is_deleted' => true,
				);
			}
		}

		return $results;
	}

	/**
	 * Parse a unified diff into per-file diff entries.
	 *
	 * Splits a multi-file unified diff string into individual file diffs,
	 * filtering to only .php and .js files.
	 *
	 * @param string $diff_body Full unified diff content.
	 * @return array Array of ['file' => path, 'diff' => file_diff, 'is_new' => bool, 'is_deleted' => bool].
	 */
	private static function parse_unified_diff( $diff_body ) {
		$results  = array();
		$lines    = explode( "\n", $diff_body );
		$buffer   = array();
		$cur_file = '';

		foreach ( $lines as $line ) {
			// Detect file header lines: "Index: path/to/file.php" or "--- path/to/file.php" in unified diff.
			if ( preg_match( '/^(?:Index:\s+|[-]{3}\s+)(.+?)(?:\t|$)/', $line, $m ) ) {
				$candidate = trim( $m[1] );
				// Skip /dev/null references and strip leading a/ or b/ prefixes.
				if ( '/dev/null' !== $candidate && 'a/' !== $candidate && 'b/' !== $candidate ) {
					$candidate = preg_replace( '!^[ab]/!', '', $candidate );
					if ( '.' !== $candidate && preg_match( '/\.(php|js)$/', $candidate ) ) {
						if ( $cur_file && ! empty( $buffer ) ) {
							$results[] = array(
								'file'       => $cur_file,
								'diff'       => implode( "\n", $buffer ),
								'is_new'     => self::is_new_file_diff( $buffer ),
								'is_deleted' => self::is_deleted_file_diff( $buffer ),
							);
						}
						$cur_file = $candidate;
						$buffer   = array();
					}
				}
			}

			if ( $cur_file ) {
				$buffer[] = $line;
			}
		}

		// Don't forget the last file.
		if ( $cur_file && ! empty( $buffer ) ) {
			$results[] = array(
				'file'       => $cur_file,
				'diff'       => implode( "\n", $buffer ),
				'is_new'     => self::is_new_file_diff( $buffer ),
				'is_deleted' => self::is_deleted_file_diff( $buffer ),
			);
		}

		return $results;
	}

	/**
	 * Check if a diff buffer represents a newly created file.
	 *
	 * @param array $buffer Diff lines.
	 * @return bool
	 */
	private static function is_new_file_diff( $buffer ) {
		// New files have "--- /dev/null" or "new file mode" in the header.
		$joined = implode( "\n", array_slice( $buffer, 0, 5 ) );
		return ( false !== strpos( $joined, 'new file mode' ) || false !== strpos( $joined, '--- /dev/null' ) );
	}

	/**
	 * Check if a diff buffer represents a deleted file.
	 *
	 * @param array $buffer Diff lines.
	 * @return bool
	 */
	private static function is_deleted_file_diff( $buffer ) {
		$joined = implode( "\n", array_slice( $buffer, 0, 5 ) );
		return ( false !== strpos( $joined, 'deleted file mode' ) || false !== strpos( $joined, '+++ /dev/null' ) );
	}

	/**
	 * List all .php and .js files in an SVN tag directory.
	 *
	 * Fetches and parses the SVN HTML directory listing for a version tag.
	 *
	 * @param string $slug      Item slug.
	 * @param string $item_type 'plugin' or 'theme'.
	 * @param string $version   Version tag.
	 * @return array List of relative file paths.
	 */
	private static function list_svn_files( $slug, $item_type, $version ) {
		$base = ( 'theme' === $item_type )
			? 'https://themes.svn.wordpress.org/'
			: 'https://plugins.svn.wordpress.org/';

		$cache_key = 'code_chaff_svn_list_' . md5( $base . $slug . '/tags/' . $version );
		$cached    = wp_cache_get( $cache_key, 'code_chaff' );

		if ( is_array( $cached ) ) {
			return $cached;
		}

		$files = self::walk_svn_directory( $base, $slug, $version, '' );

		wp_cache_set( $cache_key, $files, 'code_chaff', HOUR_IN_SECONDS );

		return $files;
	}

	/**
	 * Recursively walk an SVN directory to find .php and .js files.
	 *
	 * @param string $base      Base SVN URL.
	 * @param string $slug      Item slug.
	 * @param string $version   Version tag.
	 * @param string $sub_path  Current subdirectory path.
	 * @param int    $depth     Current recursion depth (max 5).
	 * @return array List of file paths relative to the tag root.
	 */
	private static function walk_svn_directory( $base, $slug, $version, $sub_path, $depth = 0 ) {
		if ( $depth > 5 ) {
			return array();
		}

		$url      = $base . $slug . '/tags/' . $version . '/' . $sub_path;
		$response = \wp_remote_get( $url, array( 'timeout' => 15 ) );

		if ( \is_wp_error( $response ) || 200 !== \wp_remote_retrieve_response_code( $response ) ) {
			return array();
		}

		$body  = \wp_remote_retrieve_body( $response );
		$files = array();

		// Parse SVN HTML directory listing for file and directory links.
		// SVN listing uses <li><a href="...">filename</a></li> format.
		if ( preg_match_all( '!<li><a href="([^"]+)">([^<]+)</a></li>!', $body, $matches, PREG_SET_ORDER ) ) {
			foreach ( $matches as $match ) {
				$name = trim( $match[2] );
				$href = trim( $match[1] );

				// Skip parent directory link and non-code files.
				if ( '' === $name || '/' === $name || '../' === $name ) {
					continue;
				}

				// Directory: href ends with /
				if ( '/' === substr( $href, -1 ) ) {
					$sub_files = self::walk_svn_directory(
						$base,
						$slug,
						$version,
						( $sub_path ? $sub_path . '/' : '' ) . $name,
						$depth + 1
					);
					foreach ( $sub_files as $sf ) {
						$files[] = $sf;
					}
				} elseif ( preg_match( '/\.(php|js)$/', $name ) ) {
					$files[] = $sub_path ? $sub_path . '/' . $name : $name;
				}
			}
		}

		return $files;
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
	 * Fetch raw file content from SVN tag.
	 *
	 * @param string $slug      Item slug.
	 * @param string $item_type 'plugin' or 'theme'.
	 * @param string $version   Version tag.
	 * @param string $file      Relative file path.
	 * @return string Raw file contents or empty string on failure.
	 */
	public static function fetch_svn_file( $slug, $item_type, $version, $file ) {
		$base = ( 'theme' === $item_type )
			? 'https://themes.svn.wordpress.org/'
			: 'https://plugins.svn.wordpress.org/';

		$url      = $base . $slug . '/tags/' . $version . '/' . ltrim( $file, '/' );
		$response = \wp_remote_get( $url, array( 'timeout' => 20 ) );

		if ( \is_wp_error( $response ) ) {
			return '';
		}

		return \wp_remote_retrieve_body( $response );
	}

	/**
	 * Execute the actual AI audit (called by async action).
	 *
	 * @param array $args Job arguments.
	 * @return void
	 */
	public static function run_audit( $args ) {
		// Allow long-running audits.
		set_time_limit( 300 );

		$slug      = $args['slug'] ?? '';
		$item_type = $args['item_type'] ?? 'plugin';
		$old_ver   = $args['old_ver'] ?? '';
		$new_ver   = $args['new_ver'] ?? '';

		if ( ! $slug || ! $new_ver ) {
			return;
		}

		// Guard: WordPress 7 AI Client must be available.
		if ( ! \function_exists( 'wp_ai_client_prompt' ) || ! \function_exists( 'wp_supports_ai' ) || ! \wp_supports_ai() ) {
			error_log( '[CodeChaff] AI client not available. Skipping audit for ' . $slug );
			return;
		}

		// fetch_changed_files may return array of diffs or WP_Error on network failure.
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
							'error'  => $changed_files->get_error_code(),
							'note'   => $changed_files->get_error_message(),
						)
					),
					'completed_at' => current_time( 'mysql' ),
				),
				array( '%s', '%s', '%s', '%s', '%s', '%s', '%s' )
			);
			return;
		}

		if ( empty( $changed_files ) ) {
			error_log( '[CodeChaff] No changed files found for ' . $slug . ' between ' . $old_ver . ' and ' . $new_ver );
			// Still record an empty audit so the user knows it ran.
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
							'note' => 'No changed .php or .js files found between these versions.',
						)
					),
					'completed_at' => current_time( 'mysql' ),
				),
				array( '%s', '%s', '%s', '%s', '%s', '%s', '%s' )
			);
			return;
		}

		$report = array(
			'security'    => array(),
			'performance' => array(),
			'files'       => array(),
		);

		// Limit files per job to prevent timeouts (max 25 changed files).
		$max_files     = 25;
		$changed_files = array_slice( $changed_files, 0, $max_files );

		// Prevent object cache from bloating during batch processing.
		\wp_suspend_cache_addition( true );

		foreach ( $changed_files as $change ) {
			$file       = $change['file'];
			$diff       = $change['diff'];
			$is_new     = $change['is_new'] ?? false;
			$is_deleted = $change['is_deleted'] ?? false;

			// Record metadata about this file change.
			$report['files'][ $file ] = array(
				'is_new'     => $is_new,
				'is_deleted' => $is_deleted,
			);

			// Skip deleted files — nothing to audit.
			if ( $is_deleted ) {
				continue;
			}

			if ( ! $diff ) {
				continue;
			}

			// Security prompt — audit the code diff for security issues.
			$sec_schema = array(
				'type'       => 'object',
				'properties' => array(
					'issues' => array(
						'type'  => 'array',
						'items' => array(
							'type'       => 'object',
							'properties' => array(
								'severity' => array(
									'type' => 'string',
									'enum' => array( 'critical', 'high', 'medium', 'low', 'info' ),
								),
								'category' => array( 'type' => 'string' ),
								'message'  => array( 'type' => 'string' ),
							),
							'required'   => array( 'severity', 'category', 'message' ),
						),
					),
				),
				'required'   => array( 'issues' ),
			);

			$sec_builder = \wp_ai_client_prompt()
				->with_text( $diff )
				->using_system_instruction(
					'You are a security auditor reviewing a code diff between plugin/theme versions. ' .
					'Audit the changes for OWASP Top 10 issues, missing sanitization or escaping, ' .
					'lack of nonce verification, privilege escalation, and authentication bypass risks. ' .
					'Lines starting with "-" are removed code, "+" are added code. ' .
					'Output must be valid JSON only, no other text.'
				)
				->as_json_response( $sec_schema );

			$sec_result = $sec_builder->generate_text();

			// Performance prompt — audit the code diff for performance issues.
			$perf_schema = array(
				'type'       => 'object',
				'properties' => array(
					'issues' => array(
						'type'  => 'array',
						'items' => array(
							'type'       => 'object',
							'properties' => array(
								'severity' => array(
									'type' => 'string',
									'enum' => array( 'critical', 'high', 'medium', 'low', 'info' ),
								),
								'category' => array( 'type' => 'string' ),
								'message'  => array( 'type' => 'string' ),
							),
							'required'   => array( 'severity', 'category', 'message' ),
						),
					),
				),
				'required'   => array( 'issues' ),
			);

			$perf_builder = \wp_ai_client_prompt()
				->with_text( $diff )
				->using_system_instruction(
					'You are a performance auditor reviewing a code diff between plugin/theme versions. ' .
					'Analyze the changes for unoptimized SQL queries, N+1 problems, missing caching, ' .
					'inefficient loops, uncached remote requests, and excessive filesystem operations. ' .
					'Lines starting with "-" are removed code, "+" are added code. ' .
					'Output must be valid JSON only, no other text.'
				)
				->as_json_response( $perf_schema );

			$perf_result = $perf_builder->generate_text();

			// Store results (decode JSON if valid, otherwise store as raw error).
			$report['security'][ $file ]    = ! \is_wp_error( $sec_result ) ? $sec_result : $sec_result->get_error_message();
			$report['performance'][ $file ] = ! \is_wp_error( $perf_result ) ? $perf_result : $perf_result->get_error_message();
		}

		\wp_suspend_cache_addition( false );

		// Compute risk level from structured audit data.
		$risk = self::compute_risk_level( $report );

		global $wpdb;
		$table = $wpdb->prefix . 'code_chaff_audits';

		$wpdb->insert(
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
				'pluginSlugs'    => self::get_plugin_slugs_map(),
				'pluginVersions' => self::get_plugin_versions_map(),
				'themeVersions'  => self::get_theme_versions_map(),
			)
		);
	}

	/**
	 * Build a map of plugin file => slug for the admin JS.
	 *
	 * @return array
	 */
	private static function get_plugin_slugs_map() {
		if ( ! function_exists( 'get_plugins' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}

		$plugins = get_plugins();
		$map     = array();

		foreach ( $plugins as $plugin_file => $plugin_data ) {
			$slug                = dirname( $plugin_file );
			$map[ $plugin_file ] = ( '.' === $slug ) ? basename( $plugin_file, '.php' ) : $slug;
		}

		return $map;
	}

	/**
	 * Build a map of plugin slug => installed version.
	 *
	 * @return array
	 */
	private static function get_plugin_versions_map() {
		if ( ! function_exists( 'get_plugins' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}

		$plugins = get_plugins();
		$map     = array();

		foreach ( $plugins as $plugin_file => $plugin_data ) {
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
