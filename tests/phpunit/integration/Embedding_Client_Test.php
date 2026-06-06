<?php
/**
 * Integration tests for the Embedding_Client class.
 *
 * Uses injected Embedding_Prompt_Builder with a mock transport to avoid
 * real HTTP calls and to remove the need for registered AI providers.
 *
 * @package WP_MariaDB_Vector_Search
 */

declare(strict_types=1);

namespace WP_MariaDB_Vector_Search\Tests\Integration;

use WordPress\AiClient\Providers\DTO\ProviderModelsMetadata;
use WordPress\AiClient\Providers\DTO\ProviderMetadata;
use WordPress\AiClient\Providers\Enums\ProviderTypeEnum;
use WordPress\AiClient\Providers\Http\Contracts\HttpTransporterInterface;
use WordPress\AiClient\Providers\Http\Contracts\RequestAuthenticationInterface;
use WordPress\AiClient\Providers\Http\DTO\Response;
use WordPress\AiClient\Providers\Models\DTO\ModelMetadata;
use WordPress\AiClient\Providers\Models\Enums\CapabilityEnum;
use WordPress\AiClient\Providers\ProviderRegistry;
use WP_MariaDB_Vector_Search\Embedding_Client;
use WP_MariaDB_Vector_Search\Embedding_Prompt_Builder;

/**
 * Class Embedding_Client_Test
 */
class Embedding_Client_Test extends \WP_UnitTestCase {

	/**
	 * Create a pass-through auth mock.
	 *
	 * @return RequestAuthenticationInterface&\PHPUnit\Framework\MockObject\MockObject
	 */
	private function make_auth(): RequestAuthenticationInterface {
		$auth = $this->createMock( RequestAuthenticationInterface::class );
		$auth->method( 'authenticateRequest' )->willReturnArgument( 0 );
		return $auth;
	}

	/**
	 * Create a registry mock that returns a single provider/model via auto-detection.
	 *
	 * @param string $provider_id Provider id.
	 * @param string $model_id    Model id.
	 * @return ProviderRegistry&\PHPUnit\Framework\MockObject\MockObject
	 */
	private function make_registry( string $provider_id, string $model_id ): ProviderRegistry {
		$provider_meta = new ProviderMetadata( $provider_id, $provider_id, ProviderTypeEnum::cloud() );
		$model_meta    = new ModelMetadata( $model_id, $model_id, array( CapabilityEnum::embeddingGeneration() ), array() );
		$pmc           = new ProviderModelsMetadata( $provider_meta, array( $model_meta ) );

		$registry = $this->createMock( ProviderRegistry::class );
		$registry->method( 'findModelsMetadataForSupport' )->willReturn( array( $pmc ) );
		$registry->method( 'hasProvider' )->willReturnCallback( static fn( $p ) => $p === $provider_id );
		return $registry;
	}

	/**
	 * Build an Embedding_Client with a mock transport injected via Embedding_Prompt_Builder.
	 *
	 * @param HttpTransporterInterface $transport Mock transporter.
	 * @return Embedding_Client
	 */
	private function make_client( HttpTransporterInterface $transport ): Embedding_Client {
		$prompt = new Embedding_Prompt_Builder(
			null,
			$transport,
			$this->make_auth(),
			$this->make_registry( 'openai', 'text-embedding-3-small' )
		);
		return new Embedding_Client( $prompt );
	}

	/** Successful response returns float[][] vectors. */
	public function test_embed_returns_vectors(): void {
		$payload   = (string) wp_json_encode(
			array( 'data' => array( array( 'embedding' => array( 0.1, 0.2, 0.3 ) ) ) )
		);
		$transport = $this->createMock( HttpTransporterInterface::class );
		$transport->method( 'send' )->willReturn( new Response( 200, array(), $payload ) );

		$result = $this->make_client( $transport )->embed( array( 'hello' ) );

		$this->assertIsArray( $result );
		$this->assertSame( array( 0.1, 0.2, 0.3 ), $result[0] );
	}

	/** Multiple texts produce a vector per text. */
	public function test_embed_returns_one_vector_per_text(): void {
		// Use non-integer float values so JSON decoding preserves float type.
		$payload   = (string) wp_json_encode(
			array(
				'data' => array(
					array( 'embedding' => array( 0.9, 0.1 ) ),
					array( 'embedding' => array( 0.1, 0.9 ) ),
				),
			)
		);
		$transport = $this->createMock( HttpTransporterInterface::class );
		$transport->method( 'send' )->willReturn( new Response( 200, array(), $payload ) );

		$result = $this->make_client( $transport )->embed( array( 'hello', 'world' ) );

		$this->assertCount( 2, $result );
		$this->assertSame( array( 0.9, 0.1 ), $result[0] );
		$this->assertSame( array( 0.1, 0.9 ), $result[1] );
	}

	/** HTTP error from provider propagates as WP_Error. */
	public function test_embed_returns_wp_error_on_http_failure(): void {
		$transport = $this->createMock( HttpTransporterInterface::class );
		$transport->method( 'send' )->willReturn( new Response( 401, array(), '{"error":"Unauthorized"}' ) );

		$result = $this->make_client( $transport )->embed( array( 'hello' ) );

		$this->assertInstanceOf( \WP_Error::class, $result );
	}

	/** No provider configured and no transport injected → WP_Error. */
	public function test_embed_returns_wp_error_without_provider(): void {
		$result = ( new Embedding_Client() )->embed( array( 'hello' ) );

		$this->assertInstanceOf( \WP_Error::class, $result );
	}
}
