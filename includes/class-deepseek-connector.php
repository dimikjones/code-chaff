<?php
/**
 * DeepSeek Connector for CodeChaff.
 *
 * @package CodeChaff
 */

namespace CodeChaff;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * DeepSeek Connector Provider.
 */
class DeepSeek_Connector {

	/**
	 * Connector ID.
	 *
	 * @var string
	 */
	const ID = 'deepseek';

	/**
	 * Register the connector using the modern Connectors API.
	 *
	 * @param \WP_Connector_Registry $registry The connector registry.
	 * @return void
	 */
	public static function register( $registry ) {
		if ( ! $registry || ! method_exists( $registry, 'register' ) ) {
			return;
		}

		$connector = array(
			'name'           => 'DeepSeek',
			'description'    => 'Text generation with DeepSeek models.',
			'logo_url'       => '',
			'type'           => 'ai_provider',
			'authentication' => array(
				'method'          => 'api_key',
				'credentials_url' => 'https://platform.deepseek.com/api_keys',
				'setting_name'    => 'connectors_ai_deepseek_api_key',
				'env_var_name'    => 'DEEPSEEK_API_KEY',
				'constant_name'   => 'DEEPSEEK_API_KEY',
			),
			'plugin'         => array(
				'file' => 'code-chaff/code-chaff.php',
			),
		);

		$registry->register( self::ID, $connector );
	}

	/**
	 * Check if the connector is properly configured.
	 *
	 * @return bool
	 */
	public static function is_configured() {
		
		$api_key = self::get_api_key();
		$has_key = ! empty( $api_key );

		return $has_key;
	}

	/**
	 * Get the stored API key.
	 *
	 * @return string|false
	 */
	public static function get_api_key() {

		// Get the setting name from the connector registration.
		$setting_name = self::get_setting_name();
		if ( ! $setting_name ) {
			return false;
		}
		
		// Get the API key from WordPress options.
		$api_key = get_option( $setting_name );

		return $api_key ? $api_key : false;
	}

	/**
	 * Get the setting name for this connector.
	 *
	 * @return string|false
	 */
	private static function get_setting_name() {
		// This should match the setting name used in the connector registration.
		// Based on WordPress core patterns, it's typically formatted as:
		// 'connectors_' . connector_id . '_api_key'
		return 'connectors_ai_deepseek_api_key';
	}
}