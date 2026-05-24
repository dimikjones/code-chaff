<?php
/**
 * DeepSeek Availability Checker for WordPress AI Client.
 *
 * @package CodeChaff
 */

namespace CodeChaff;

use WordPress\AiClient\Providers\Contracts\ProviderAvailabilityInterface;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * DeepSeek Availability checker.
 */
class DeepSeek_Availability implements ProviderAvailabilityInterface {

	/**
	 * Checks if the provider is configured and available.
	 *
	 * @return bool
	 */
	public function isConfigured(): bool {
		
		// Check if we have a valid API key.
		$api_key = DeepSeek_Connector::get_api_key();
		
		if ( empty( $api_key ) ) {
			return false;
		}

		// Perform a simple API validation test by trying to list models.
		try {
			
			// Test API connectivity with a simple request to DeepSeek's models endpoint.
			$response = wp_remote_get(
				'https://api.deepseek.com/v1/models',
				array(
					'headers' => array(
						'Authorization' => 'Bearer ' . $api_key,
						'Content-Type'  => 'application/json',
					),
					'timeout' => 10,
				)
			);

			if ( is_wp_error( $response ) ) {
				return false;
			}

			$http_code = wp_remote_retrieve_response_code( $response );
			$body      = wp_remote_retrieve_body( $response );


			// Check if we got a successful response.
			if ( 200 === $http_code ) {
				$data = json_decode( $body, true );
				$has_valid_data = isset( $data['data'] ) && is_array( $data['data'] );
				return $has_valid_data;
			}

			return false;
		} catch ( \Exception $e ) {
			error_log( '[CodeChaff] Exception during API validation: ' . $e->getMessage() );
			return false;
		}
	}

	/**
	 * Gets a human-readable description of the configuration requirements.
	 *
	 * @return string
	 */
	public function getConfigurationRequirementsDescription(): string {
		return 'DeepSeek API key is required. Get one at https://platform.deepseek.com/api_keys';
	}
}
