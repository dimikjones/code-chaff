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
	 * Collected AI connectors from the Connectors API.
	 *
	 * @var array
	 */
	private static $ai_connectors = array();

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
		// Placeholder for future cleanup (cron jobs, transients, etc.).
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
	 * Collect AI providers from the Connectors API registry.
	 *
	 * @param mixed $registry The connector registry.
	 * @return void
	 */
	public static function collect_ai_connectors( $registry ) {
		if ( ! $registry ) {
			return;
		}

		$connectors = array();

		// Try different methods to get all registered connectors.
		if ( method_exists( $registry, 'get_all' ) ) {
			$connectors = $registry->get_all();
		} elseif ( method_exists( $registry, 'get_all_registered' ) ) {
			$connectors = $registry->get_all_registered();
		} elseif ( method_exists( $registry, 'get_connectors' ) ) {
			$connectors = $registry->get_connectors();
		} elseif ( isset( $registry->registered ) ) {
			$connectors = $registry->registered;
		} elseif ( isset( $registry->connectors ) ) {
			$connectors = $registry->connectors;
		}

		// If methods failed, try object properties (public, protected, private).
		if ( empty( $connectors ) ) {
			$vars = get_object_vars( $registry );
			foreach ( $vars as $value ) {
				if ( is_array( $value ) && ! empty( $value ) ) {
					$first = reset( $value );
					if ( is_array( $first ) && isset( $first['type'] ) ) {
						$connectors = $value;
						break;
					}
				}
			}
		}

		// If still empty, use reflection to inspect all properties.
		if ( empty( $connectors ) ) {
			try {
				$reflection = new \ReflectionClass( $registry );
				foreach ( $reflection->getProperties() as $property ) {
					$property->setAccessible( true );
					$value = $property->getValue( $registry );
					if ( is_array( $value ) && ! empty( $value ) ) {
						$first = reset( $value );
						if ( is_array( $first ) && isset( $first['type'] ) ) {
							$connectors = $value;
							break;
						}
					}
				}
			} catch ( \Exception $e ) {
				$ignore = true; // Reflection failed, ignore.
			}
		}

		foreach ( $connectors as $id => $connector ) {
			if ( is_array( $connector ) && isset( $connector['type'] ) && 'ai_provider' === $connector['type'] ) {
				self::$ai_connectors[ $id ] = $connector['name'] ?? $id;
			}
		}
	}

	/**
	 * Get all available AI providers from the WP AI Client registry or Connectors API.
	 *
	 * @return array Associative array of provider_id => provider_name.
	 */
	public static function get_available_providers() {
		$providers = array();

		// Try WP AI Client registry first.
		if ( class_exists( '\WordPress\AiClient\AiClient' ) && method_exists( '\WordPress\AiClient\AiClient', 'defaultRegistry' ) ) {
			$registry = \WordPress\AiClient\AiClient::defaultRegistry();
			if ( $registry && method_exists( $registry, 'getProviders' ) ) {
				try {
					$registered = $registry->getProviders();
					foreach ( $registered as $provider ) {
						if ( is_object( $provider ) ) {
							if ( method_exists( $provider, 'getMetadata' ) ) {
								$metadata = $provider->getMetadata();
								if ( is_object( $metadata ) && method_exists( $metadata, 'getId' ) ) {
									$providers[ $metadata->getId() ] = method_exists( $metadata, 'getName' ) ? $metadata->getName() : $metadata->getId();
								}
							} elseif ( method_exists( $provider, 'getId' ) && method_exists( $provider, 'getName' ) ) {
								$providers[ $provider->getId() ] = $provider->getName();
							}
						} elseif ( is_string( $provider ) && class_exists( $provider ) ) {
							$id   = $provider;
							$name = $provider;
							if ( method_exists( $provider, 'getMetadata' ) ) {
								$metadata = $provider::getMetadata();
								if ( is_object( $metadata ) && method_exists( $metadata, 'getId' ) ) {
									$id   = $metadata->getId();
									$name = method_exists( $metadata, 'getName' ) ? $metadata->getName() : $id;
								}
							}
							$providers[ $id ] = $name;
						}
					}
				} catch ( \Exception $e ) {
					error_log( '[CodeChaff] Error retrieving AI providers from WP AI Client: ' . $e->getMessage() );
				}
			}
		}

		// If WP AI Client registry is empty, fall back to Connectors API.
		if ( empty( $providers ) && ! empty( self::$ai_connectors ) ) {
			$providers = self::$ai_connectors;
		}

		// Direct fallback: check for known provider classes that are configured.
		if ( empty( $providers ) ) {
			if ( class_exists( '\DeepSeekWpProvider\DeepSeek_Connector' ) && method_exists( '\DeepSeekWpProvider\DeepSeek_Connector', 'is_configured' ) ) {
				if ( \DeepSeekWpProvider\DeepSeek_Connector::is_configured() ) {
					$providers['deepseek'] = 'DeepSeek';
				}
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

	/**
	 * Execute the actual AI audit (called by async action).
	 *
	 * @param array $args Job arguments.
	 * @return void
	 */
	public static function run_audit( $args ) {
		$slug      = $args['slug'] ?? '';
		$item_type = $args['item_type'] ?? 'plugin';
		$old_ver   = $args['old_ver'] ?? '';
		$new_ver   = $args['new_ver'] ?? '';

		if ( ! $slug || ! $new_ver ) {
			return;
		}

		$changed_files = self::fetch_changed_files( $slug, $item_type, $old_ver, $new_ver );

		$report = array(
			'security'    => array(),
			'performance' => array(),
		);

		foreach ( $changed_files as $file ) {
			$content = self::fetch_svn_file( $slug, $new_ver, $file );
			if ( ! $content ) {
				continue;
			}

			// Security prompt via native WP AI client.
			$sec_prompt = 'Audit the following PHP/JS code for OWASP Top 10 issues, missing sanitization, escaping, and authentication checks. Return JSON only.';
			$sec_result = \wp_ai_client_prompt( $sec_prompt . "\n\n" . $content );

			// Performance prompt.
			$perf_prompt = 'Analyze the code for unoptimized database queries, heavy loops, and missing caching. Return JSON only.';
			$perf_result = \wp_ai_client_prompt( $perf_prompt . "\n\n" . $content );

			$report['security'][ $file ]    = $sec_result;
			$report['performance'][ $file ] = $perf_result;
		}

		// Simple risk classification.
		$risk = 'secure';
		if ( ! empty( $report['security'] ) ) {
			$risk = 'warning';
		}
		if ( strpos( wp_json_encode( $report ), 'critical' ) !== false ) {
			$risk = 'critical';
		}

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
	 * Admin UI: enqueue scripts on updates screens.
	 *
	 * @return void
	 */
	public static function admin_init() {
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
				'ajaxUrl' => admin_url( 'admin-ajax.php' ),
				'nonce'   => wp_create_nonce( 'code_chaff_audit' ),
			)
		);
	}

	/**
	 * AJAX handler – queue audit job from update row button.
	 *
	 * @return void
	 */
	public static function ajax_queue_audit() {
		check_ajax_referer( 'code_chaff_audit', 'nonce' );

		$slug      = sanitize_text_field( wp_unslash( $_POST['slug'] ?? '' ) );
		$item_type = sanitize_text_field( wp_unslash( $_POST['item_type'] ?? 'plugin' ) );
		$old_ver   = sanitize_text_field( wp_unslash( $_POST['old_ver'] ?? '' ) );
		$new_ver   = sanitize_text_field( wp_unslash( $_POST['new_ver'] ?? '' ) );

		if ( ! $slug || ! $new_ver ) {
			wp_send_json_error( 'Invalid request' );
		}

		$action_id = self::queue_audit_job( $slug, $item_type, $old_ver, $new_ver );

		wp_send_json_success( array( 'action_id' => $action_id ) );
	}
}

// Register the async action handler.
add_action( 'code_chaff_run_audit', array( 'CodeChaff\CodeChaff', 'run_audit' ) );

// Admin hooks.
add_action( 'admin_enqueue_scripts', array( 'CodeChaff\CodeChaff', 'admin_init' ) );
add_action( 'wp_ajax_code_chaff_queue_audit', array( 'CodeChaff\CodeChaff', 'ajax_queue_audit' ) );

// Collect AI connectors from the Connectors API.
add_action( 'wp_connectors_init', array( 'CodeChaff\CodeChaff', 'collect_ai_connectors' ), 100 );

// Register settings page.
CodeChaff_Settings::init();

// Bootstrap abilities.
add_action( 'init', array( 'CodeChaff\CodeChaff', 'register_ability' ) );

register_activation_hook( \CODE_CHAFF_SETUP_ROOT, array( 'CodeChaff\CodeChaff', 'activate' ) );
register_deactivation_hook( \CODE_CHAFF_SETUP_ROOT, array( 'CodeChaff\CodeChaff', 'deactivate' ) );
