<?php
/**
 * Unit tests for the Model_Catalog class.
 *
 * @package WP_MariaDB_Vector_Search
 */

declare(strict_types=1);

namespace WP_MariaDB_Vector_Search\Tests\Unit;

use WordPress\AiClient\Providers\DTO\ProviderModelsMetadata;
use WordPress\AiClient\Providers\DTO\ProviderMetadata;
use WordPress\AiClient\Providers\Enums\ProviderTypeEnum;
use WordPress\AiClient\Providers\Models\DTO\ModelMetadata;
use WordPress\AiClient\Providers\Models\Enums\CapabilityEnum;
use WordPress\AiClient\Providers\ProviderRegistry;
use WP_MariaDB_Vector_Search\Model_Catalog;

/**
 * Class Model_Catalog_Test
 */
class Model_Catalog_Test extends \WP_UnitTestCase {

	/**
	 * Tear down registered filters.
	 */
	public function tear_down(): void {
		remove_all_filters( 'wp_mariadb_vector_search_known_embedding_models' );
		parent::tear_down();
	}

	/**
	 * Build a ProviderRegistry mock.
	 *
	 * @param array<int, array{string, string}> $auto_detect   [provider_id, model_id] pairs from capability detection.
	 * @param string[]                          $has_providers Provider ids where hasProvider returns true.
	 * @param string[]                          $configured    Provider ids where isProviderConfigured returns true.
	 * @return ProviderRegistry&\PHPUnit\Framework\MockObject\MockObject
	 */
	private function make_registry(
		array $auto_detect = array(),
		array $has_providers = array(),
		array $configured = array()
	): ProviderRegistry {
		// Build ProviderModelsMetadata candidates.
		$by_provider = array();
		foreach ( $auto_detect as [ $provider_id, $model_id ] ) {
			$by_provider[ $provider_id ][] = $model_id;
		}
		$candidates = array();
		foreach ( $by_provider as $provider_id => $model_ids ) {
			$provider_meta = new ProviderMetadata( $provider_id, $provider_id, ProviderTypeEnum::cloud() );
			$model_metas   = array_map(
				static fn( $m ) => new ModelMetadata( $m, $m, array( CapabilityEnum::embeddingGeneration() ), array() ),
				$model_ids
			);
			$candidates[]  = new ProviderModelsMetadata( $provider_meta, $model_metas );
		}

		$registry = $this->createMock( ProviderRegistry::class );
		$registry->method( 'findModelsMetadataForSupport' )->willReturn( $candidates );
		$registry->method( 'hasProvider' )->willReturnCallback(
			static fn( $p ) => in_array( $p, $has_providers, true )
		);
		$registry->method( 'isProviderConfigured' )->willReturnCallback(
			static fn( $p ) => in_array( $p, $configured, true )
		);

		return $registry;
	}

	// -----------------------------------------------------------------------
	// Auto-detection
	// -----------------------------------------------------------------------

	/** Auto-detected models appear in the result. */
	public function test_auto_detected_models_are_returned(): void {
		$registry = $this->make_registry(
			array( array( 'lmstudio', 'nomic-embed-text-v1.5' ) )
		);
		$catalog  = new Model_Catalog( $registry );

		$models = $catalog->get_available_models();

		$this->assertCount( 1, $models );
		$this->assertSame( 'lmstudio', $models[0]['provider'] );
		$this->assertSame( 'nomic-embed-text-v1.5', $models[0]['model'] );
	}

	/** Multiple auto-detected providers/models all appear. */
	public function test_multiple_auto_detected_models(): void {
		$registry = $this->make_registry(
			array(
				array( 'lmstudio', 'nomic-embed-text-v1.5' ),
				array( 'lmstudio', 'all-minilm-l6-v2' ),
			)
		);
		$catalog  = new Model_Catalog( $registry );

		$models = $catalog->get_available_models();
		$this->assertCount( 2, $models );
	}

	// -----------------------------------------------------------------------
	// Known list + provider configuration check
	// -----------------------------------------------------------------------

	/** Known list entries are included when provider is registered and configured. */
	public function test_known_list_included_when_provider_configured(): void {
		$registry = $this->make_registry(
			array(),                 // No auto-detect.
			array( 'openai' ),       // hasProvider.
			array( 'openai' )        // isProviderConfigured.
		);
		$catalog  = new Model_Catalog( $registry );

		// Override known list to just one entry for test simplicity.
		add_filter(
			'wp_mariadb_vector_search_known_embedding_models',
			static fn() => array(
				array(
					'provider' => 'openai',
					'model'    => 'text-embedding-3-small',
				),
			)
		);

		$models = $catalog->get_available_models();

		$this->assertCount( 1, $models );
		$this->assertSame( 'openai', $models[0]['provider'] );
		$this->assertSame( 'text-embedding-3-small', $models[0]['model'] );
	}

	/** Known list entries are excluded when provider is not registered. */
	public function test_known_list_excluded_when_provider_not_registered(): void {
		$registry = $this->make_registry();  // hasProvider always false.
		$catalog  = new Model_Catalog( $registry );

		add_filter(
			'wp_mariadb_vector_search_known_embedding_models',
			static fn() => array(
				array(
					'provider' => 'openai',
					'model'    => 'text-embedding-3-small',
				),
			)
		);

		$models = $catalog->get_available_models();
		$this->assertCount( 0, $models );
	}

	/** Known list entries are excluded when provider is registered but not configured. */
	public function test_known_list_excluded_when_provider_not_configured(): void {
		$registry = $this->make_registry(
			array(),
			array( 'openai' ),  // registered.
			array()             // NOT configured.
		);
		$catalog  = new Model_Catalog( $registry );

		add_filter(
			'wp_mariadb_vector_search_known_embedding_models',
			static fn() => array(
				array(
					'provider' => 'openai',
					'model'    => 'text-embedding-3-small',
				),
			)
		);

		$models = $catalog->get_available_models();
		$this->assertCount( 0, $models );
	}

	// -----------------------------------------------------------------------
	// Deduplication
	// -----------------------------------------------------------------------

	/** A model returned by both auto-detect and known list appears only once. */
	public function test_deduplication_of_auto_detected_and_known(): void {
		$registry = $this->make_registry(
			array( array( 'openai', 'text-embedding-3-small' ) ),  // auto-detect.
			array( 'openai' ),                                       // registered.
			array( 'openai' )                                        // configured.
		);
		$catalog  = new Model_Catalog( $registry );

		add_filter(
			'wp_mariadb_vector_search_known_embedding_models',
			static fn() => array(
				array(
					'provider' => 'openai',
					'model'    => 'text-embedding-3-small',
				),
			)
		);

		$models = $catalog->get_available_models();
		$this->assertCount( 1, $models );
	}

	// -----------------------------------------------------------------------
	// wp_mariadb_vector_search_known_embedding_models filter
	// -----------------------------------------------------------------------

	/** Filter can add a custom model entry. */
	public function test_filter_can_add_custom_model(): void {
		$registry = $this->make_registry(
			array(),
			array( 'my-provider' ),
			array( 'my-provider' )
		);
		$catalog  = new Model_Catalog( $registry );

		add_filter(
			'wp_mariadb_vector_search_known_embedding_models',
			static fn( array $known_models ) => array_merge(
				$known_models,
				array(
					array(
						'provider' => 'my-provider',
						'model'    => 'my-model',
					),
				)
			)
		);

		$models = $catalog->get_available_models();

		$found = array_filter( $models, static fn( $m ) => 'my-model' === $m['model'] );
		$this->assertCount( 1, $found );
	}

	/** Invalid entries in the known list are silently ignored. */
	public function test_invalid_known_list_entries_ignored(): void {
		$registry = $this->make_registry();
		$catalog  = new Model_Catalog( $registry );

		add_filter(
			'wp_mariadb_vector_search_known_embedding_models',
			static fn() => array(
				'not-an-array',
				array(),
				array( 'provider' => '' ),
				array( 'model' => '' ),
			)
		);

		$models = $catalog->get_available_models();
		$this->assertCount( 0, $models );
	}
}
