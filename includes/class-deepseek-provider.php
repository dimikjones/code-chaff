<?php
/**
 * DeepSeek Provider for WordPress AI Client.
 *
 * @package CodeChaff
 */

namespace CodeChaff;

use WordPress\AiClient\Providers\AbstractProvider;
use WordPress\AiClient\Providers\Contracts\ProviderAvailabilityInterface;
use WordPress\AiClient\Providers\DTO\ProviderMetadata;
use WordPress\AiClient\Providers\Enums\ProviderTypeEnum;
use WordPress\AiClient\Providers\Http\Enums\RequestAuthenticationMethod;
use WordPress\AiClient\Providers\Models\Contracts\ModelInterface;
use WordPress\AiClient\Providers\Models\DTO\ModelConfig;
use WordPress\AiClient\Providers\Models\DTO\ModelMetadata;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * DeepSeek AI Provider implementation.
 */
class DeepSeek_Provider extends AbstractProvider {

	/**
	 * Provider ID.
	 *
	 * @var string
	 */
	const ID = 'deepseek';

	/**
	 * API base URL for DeepSeek.
	 *
	 * @var string
	 */
	const API_BASE_URL = 'https://api.deepseek.com';

	/**
	 * Creates provider metadata.
	 *
	 * @return ProviderMetadata
	 */
	protected static function createProviderMetadata(): ProviderMetadata {
		return new ProviderMetadata(
			self::ID,
			'DeepSeek',
			ProviderTypeEnum::from( 'cloud' ),
			'https://platform.deepseek.com/api_keys',
			RequestAuthenticationMethod::from( 'api_key' ),
			'Text generation with DeepSeek models including DeepSeek Chat and DeepSeek Coder.',
			null // No logo path for now.
		);
	}

	/**
	 * Creates a model instance.
	 *
	 * @param ModelMetadata    $model_metadata Model metadata.
	 * @param ProviderMetadata $provider_metadata Provider metadata.
	 * @return ModelInterface
	 */
	protected static function createModel( ModelMetadata $model_metadata, ProviderMetadata $provider_metadata ): ModelInterface {
		return new DeepSeek_Model( $model_metadata, $provider_metadata );
	}

	/**
	 * Creates model metadata directory.
	 *
	 * @return \WordPress\AiClient\Providers\Contracts\ModelMetadataDirectoryInterface
	 */
	protected static function createModelMetadataDirectory(): \WordPress\AiClient\Providers\Contracts\ModelMetadataDirectoryInterface {
		return new DeepSeek_Model_Metadata_Directory();
	}

	/**
	 * Creates provider availability checker.
	 *
	 * @return ProviderAvailabilityInterface
	 */
	protected static function createProviderAvailability(): ProviderAvailabilityInterface {
		return new DeepSeek_Availability();
	}
}
