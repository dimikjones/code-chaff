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
		if ( ! \function_exists( 'wp_get_connector_credentials' ) ) {
			return false;
		}

		$credentials = \wp_get_connector_credentials( self::ID );
		return ! empty( $credentials['api_key'] );
	}

	/**
	 * Get the stored API key.
	 *
	 * @return string|false
	 */
	public static function get_api_key() {
		if ( ! \function_exists( 'wp_get_connector_credentials' ) ) {
			return false;
		}

		$credentials = \wp_get_connector_credentials( self::ID );
		return $credentials['api_key'] ?? false;
	}
}