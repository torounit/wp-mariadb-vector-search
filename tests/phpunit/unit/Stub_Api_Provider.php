<?php
/**
 * Minimal AbstractApiProvider stub for unit tests.
 *
 * @package WP_MariaDB_Vector_Search
 */

declare(strict_types=1);

namespace WP_MariaDB_Vector_Search\Tests\Unit;

use WordPress\AiClient\Providers\ApiBasedImplementation\AbstractApiProvider;
use WordPress\AiClient\Providers\Contracts\ModelMetadataDirectoryInterface;
use WordPress\AiClient\Providers\Contracts\ProviderAvailabilityInterface;
use WordPress\AiClient\Providers\DTO\ProviderMetadata;
use WordPress\AiClient\Providers\Models\Contracts\ModelInterface;
use WordPress\AiClient\Providers\Models\DTO\ModelMetadata;

/**
 * Concrete AbstractApiProvider subclass used only in unit tests.
 *
 * Its sole purpose is to expose a known baseUrl so that resolve_url()
 * in Embedding_Prompt_Builder can be verified without needing the real
 * OpenAI-provider plugin to be installed.
 *
 * All abstract methods that are not relevant to URL building throw
 * \LogicException so any accidental call fails loudly.
 */
class Stub_Api_Provider extends AbstractApiProvider {

	/**
	 * {@inheritDoc}
	 *
	 * @return string
	 */
	protected static function baseUrl(): string {
		return 'https://custom.example.com/v1';
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param ModelMetadata    $model_metadata    Unused in stub.
	 * @param ProviderMetadata $provider_metadata Unused in stub.
	 * @return never
	 * @throws \LogicException Always — method not implemented in stub.
	 */
	protected static function createModel( ModelMetadata $model_metadata, ProviderMetadata $provider_metadata ): never {
		throw new \LogicException( 'Not implemented in test stub.' );
	}

	/**
	 * {@inheritDoc}
	 *
	 * @return never
	 * @throws \LogicException Always — method not implemented in stub.
	 */
	protected static function createProviderMetadata(): never {
		throw new \LogicException( 'Not implemented in test stub.' );
	}

	/**
	 * {@inheritDoc}
	 *
	 * @return never
	 * @throws \LogicException Always — method not implemented in stub.
	 */
	protected static function createProviderAvailability(): never {
		throw new \LogicException( 'Not implemented in test stub.' );
	}

	/**
	 * {@inheritDoc}
	 *
	 * @return never
	 * @throws \LogicException Always — method not implemented in stub.
	 */
	protected static function createModelMetadataDirectory(): never {
		throw new \LogicException( 'Not implemented in test stub.' );
	}
}
