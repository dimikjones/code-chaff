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
	 * Register the connector with the core Connectors API.
	 *
	 * @return void
	 */
	public static function register() {
		if ( ! \function_exists( 'wp_register_connector' ) ) {
			return;
		}

		\wp_register_connector(
			self::ID,
			array(
				'name'        => 'DeepSeek',
				'description' => 'Text generation with DeepSeek models.',
				'credentials' => array(
					'api_key' => array(
						'label'       => 'API Key',
						'type'        => 'password',
						'required'    => true,
						'description' => 'Your DeepSeek API key from platform.deepseek.com',
					),
				),
			)
		);
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
