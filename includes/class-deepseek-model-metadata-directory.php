<?php
/**
 * DeepSeek Model Metadata Directory for WordPress AI Client.
 *
 * @package CodeChaff
 */

namespace CodeChaff;

use WordPress\AiClient\Providers\Models\DTO\ModelMetadata;
use WordPress\AiClient\Providers\Contracts\ModelMetadataDirectoryInterface;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * DeepSeek Model Metadata Directory.
 */
class DeepSeek_Model_Metadata_Directory implements ModelMetadataDirectoryInterface {

	/**
	 * Gets all available models for this provider.
	 *
	 * @return ModelMetadata[]
	 */
	public function getModels(): array {
		return array(
			new ModelMetadata(
				'deepseek-chat',
				'deepseek-chat',
				'DeepSeek Chat - General purpose conversational AI',
				array( 'text_generation' ),
				array()
			),
			new ModelMetadata(
				'deepseek-coder',
				'deepseek-coder',
				'DeepSeek Coder - Specialized for code generation and programming tasks',
				array( 'text_generation', 'code_generation' ),
				array()
			),
		);
	}

	/**
	 * Gets a specific model by ID.
	 *
	 * @param string $modelId Model identifier.
	 * @return ModelMetadata|null
	 */
	public function getModel( string $modelId ): ?ModelMetadata {
		foreach ( $this->getModels() as $model ) {
			if ( $model->getId() === $modelId ) {
				return $model;
			}
		}
		return null;
	}

	/**
	 * Gets model metadata by ID.
	 *
	 * @param string $modelId Model identifier.
	 * @return ModelMetadata
	 * @throws \RuntimeException If model not found.
	 */
	public function getModelMetadata( string $modelId ): ModelMetadata {
		$model = $this->getModel( $modelId );
		if ( null === $model ) {
			throw new \RuntimeException( 'Model not found: ' . $modelId );
		}
		return $model;
	}

	/**
	 * Checks if model metadata exists.
	 *
	 * @param string $modelId Model identifier.
	 * @return bool
	 */
	public function hasModelMetadata( string $modelId ): bool {
		return null !== $this->getModel( $modelId );
	}

	/**
	 * Lists all model metadata.
	 *
	 * @return array
	 */
	public function listModelMetadata(): array {
		return $this->getModels();
	}
}
