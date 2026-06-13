<?php
/**
 * Embedding model catalog — enumerates available embedding models.
 *
 * @package WP_MariaDB_Vector_Search
 */

declare(strict_types=1);

namespace WP_MariaDB_Vector_Search;

use WordPress\AiClient\AiClient;
use WordPress\AiClient\Providers\Models\DTO\ModelRequirements;
use WordPress\AiClient\Providers\Models\Enums\CapabilityEnum;
use WordPress\AiClient\Providers\ProviderRegistry;

/**
 * Enumerates embedding models available for selection in the admin UI.
 *
 * Merges capability-auto-detected models with a filterable known list,
 * restricting the known list to providers that are registered and configured.
 * The combined list is deduplicated by provider:model key.
 */
class Model_Catalog {

	/**
	 * Constructor.
	 *
	 * @param ProviderRegistry $registry Provider registry.
	 */
	public function __construct(
		private ProviderRegistry $registry,
	) {}

	/**
	 * Factory: create using the default AI Client registry.
	 *
	 * @return static
	 */
	public static function create(): static {
		return new static( AiClient::defaultRegistry() );
	}

	/**
	 * Return all available embedding models.
	 *
	 * Each element is an array with keys:
	 *  - provider   (string):   Provider id, e.g. "openai".
	 *  - model      (string):   Model id, e.g. "text-embedding-3-small".
	 *  - label      (string):   Human-readable label for the select option.
	 *  - dimensions (int|null): Known embedding dimensions, or null if unknown.
	 *
	 * @return array<int, array{provider: string, model: string, label: string, dimensions: int|null}>
	 */
	public function get_available_models(): array {
		$models = array(); // Keyed by "provider:model" for deduplication.

		// 1. Auto-detect capability-tagged models from the registry.
		$requirements = new ModelRequirements(
			array( CapabilityEnum::embeddingGeneration() ),
			array()
		);
		$candidates   = $this->registry->findModelsMetadataForSupport( $requirements );
		foreach ( $candidates as $pmc ) {
			$provider_id = $pmc->getProvider()->getId();
			foreach ( $pmc->getModels() as $model_metadata ) {
				$model_id         = $model_metadata->getId();
				$key              = $provider_id . ':' . $model_id;
				$models[ $key ] ??= array(
					'provider' => $provider_id,
					'model'    => $model_id,
					'label'    => '' !== $model_metadata->getName() ? $model_metadata->getName() : $model_id,
				);
			}
		}

		// 2. Known list, filterable via wp_mariadb_vector_search_known_embedding_models.
		// Entries are restricted to providers that are registered AND configured.
		// Each entry may include 'dimensions' (int) so the UI can update settings on model change.
		$default_known = array(
			array(
				'provider'   => 'openai',
				'model'      => 'text-embedding-3-small',
				'dimensions' => 1536,
			),
			array(
				'provider'   => 'openai',
				'model'      => 'text-embedding-3-large',
				'dimensions' => 3072,
			),
			array(
				'provider'   => 'openai',
				'model'      => 'text-embedding-ada-002',
				'dimensions' => 1536,
			),
			array(
				'provider'   => 'google',
				'model'      => 'text-embedding-004',
				'dimensions' => 768,
			),
		);

		/**
		 * Filter the list of known embedding models shown in the admin UI.
		 *
		 * Each element must have 'provider' (string) and 'model' (string) keys.
		 * An optional 'dimensions' (int) key specifies the model's output dimensions.
		 * Entries for providers that are not registered or not configured are
		 * silently ignored.
		 *
		 * @param array<int, array{provider: string, model: string, dimensions?: int}> $known_list Default known models.
		 */
		$known_list = apply_filters( 'wp_mariadb_vector_search_known_embedding_models', $default_known );

		// Build a dimensions lookup from the known list so auto-detected models also get dimensions.
		$known_dims = array();
		foreach ( (array) $known_list as $item ) {
			if ( is_array( $item ) && isset( $item['provider'], $item['model'], $item['dimensions'] )
				&& is_int( $item['dimensions'] ) ) {
				$known_dims[ $item['provider'] . ':' . $item['model'] ] = $item['dimensions'];
			}
		}

		foreach ( (array) $known_list as $item ) {
			if ( ! is_array( $item ) ) {
				continue;
			}

			$provider_id = (string) ( $item['provider'] ?? '' );
			$model_id    = (string) ( $item['model'] ?? '' );
			if ( '' === $provider_id || '' === $model_id ) {
				continue;
			}

			try {
				if ( ! $this->registry->hasProvider( $provider_id ) ) {
					continue;
				}
				if ( ! $this->registry->isProviderConfigured( $provider_id ) ) {
					continue;
				}
			} catch ( \Throwable ) {
				continue;
			}

			$key              = $provider_id . ':' . $model_id;
			$models[ $key ] ??= array(
				'provider' => $provider_id,
				'model'    => $model_id,
				'label'    => $model_id,
			);
		}

		// Apply known dimensions to all entries (including auto-detected models).
		$result = array();
		foreach ( $models as $key => $m ) {
			$result[] = array(
				'provider'   => $m['provider'],
				'model'      => $m['model'],
				'label'      => $m['label'],
				'dimensions' => $known_dims[ $key ] ?? null,
			);
		}
		return $result;
	}
}
