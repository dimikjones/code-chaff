<?php
/**
 * DeepSeek Model for WordPress AI Client.
 *
 * @package CodeChaff
 */

namespace CodeChaff;

use WordPress\AiClient\Providers\DTO\ProviderMetadata;
use WordPress\AiClient\Providers\Models\Contracts\ModelInterface;
use WordPress\AiClient\Providers\Models\DTO\ModelConfig;
use WordPress\AiClient\Providers\Models\DTO\ModelMetadata;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * DeepSeek Model implementation.
 */
class DeepSeek_Model implements ModelInterface {

	/**
	 * Model metadata.
	 *
	 * @var ModelMetadata
	 */
	private $metadata;

	/**
	 * Model configuration.
	 *
	 * @var ModelConfig
	 */
	private $config;

	/**
	 * Provider metadata.
	 *
	 * @var ProviderMetadata
	 */
	private $provider_metadata;

	/**
	 * Constructor.
	 *
	 * @param ModelMetadata $metadata Model metadata.
	 * @param ProviderMetadata $provider_metadata Provider metadata.
	 */
	public function __construct( ModelMetadata $metadata, ProviderMetadata $provider_metadata ) {
		$this->metadata          = $metadata;
		$this->provider_metadata = $provider_metadata;
		$this->config            = new ModelConfig();
	}

	/**
	 * Gets model metadata.
	 *
	 * @return ModelMetadata
	 */
	public function metadata(): ModelMetadata {
		return $this->metadata;
	}

	/**
	 * Gets provider metadata.
	 *
	 * @return ProviderMetadata
	 */
	public function providerMetadata(): ProviderMetadata {
		return $this->provider_metadata;
	}

	/**
	 * Gets model configuration.
	 *
	 * @return ModelConfig
	 */
	public function getConfig(): ModelConfig {
		return $this->config;
	}

	/**
	 * Sets model configuration.
	 *
	 * @param ModelConfig $config Model configuration.
	 * @return void
	 */
	public function setConfig( ModelConfig $config ): void {
		$this->config = $config;
	}
}