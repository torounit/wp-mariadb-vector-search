<?php
/**
 * Unit tests for the Embedding_Prompt_Builder class.
 *
 * @package WP_MariaDB_Vector_Search
 */

declare(strict_types=1);

namespace WP_MariaDB_Vector_Search\Tests\Unit;

use WordPress\AiClient\Providers\DTO\ProviderModelsMetadata;
use WordPress\AiClient\Providers\DTO\ProviderMetadata;
use WordPress\AiClient\Providers\Enums\ProviderTypeEnum;
use WordPress\AiClient\Providers\Http\Contracts\HttpTransporterInterface;
use WordPress\AiClient\Providers\Http\Contracts\RequestAuthenticationInterface;
use WordPress\AiClient\Providers\Http\DTO\Request;
use WordPress\AiClient\Providers\Http\DTO\Response;
use WordPress\AiClient\Providers\Models\DTO\ModelMetadata;
use WordPress\AiClient\Providers\Models\Enums\CapabilityEnum;
use WordPress\AiClient\Providers\ProviderRegistry;
use WordPress\AiClient\Results\DTO\GenerativeAiResult;
use WP_MariaDB_Vector_Search\Embedding_Prompt_Builder;

/**
 * Class Embedding_Prompt_Builder_Test
 */
class Embedding_Prompt_Builder_Test extends \WP_UnitTestCase {

	/**
	 * Create a pass-through auth mock.
	 *
	 * @return RequestAuthenticationInterface&\PHPUnit\Framework\MockObject\MockObject
	 */
	private function make_auth(): RequestAuthenticationInterface {
		$auth = $this->createMock( RequestAuthenticationInterface::class );
		$auth->method( 'authenticateRequest' )
			->willReturnArgument( 0 );
		return $auth;
	}

	/**
	 * Create a ProviderRegistry mock returning one provider/model pair.
	 *
	 * @param string $provider_id Provider id string.
	 * @param string $model_id    Model id string.
	 * @return ProviderRegistry&\PHPUnit\Framework\MockObject\MockObject
	 */
	private function make_registry( string $provider_id, string $model_id ): ProviderRegistry {
		$provider_metadata = new ProviderMetadata( $provider_id, $provider_id, ProviderTypeEnum::cloud() );
		$model_metadata    = new ModelMetadata(
			$model_id,
			$model_id,
			array( CapabilityEnum::embeddingGeneration() ),
			array()
		);
		$pmc               = new ProviderModelsMetadata( $provider_metadata, array( $model_metadata ) );

		$registry = $this->createMock( ProviderRegistry::class );
		$registry->method( 'findModelsMetadataForSupport' )->willReturn( array( $pmc ) );

		return $registry;
	}

	/**
	 * Create an Embedding_Prompt_Builder with injected transport, auth, and registry.
	 *
	 * @param HttpTransporterInterface $transport   Mock transporter.
	 * @param string                   $provider_id Provider id.
	 * @param string                   $model_id    Model id.
	 * @return Embedding_Prompt_Builder
	 */
	private function make_builder(
		HttpTransporterInterface $transport,
		string $provider_id = 'openai',
		string $model_id = 'text-embedding-3-small'
	): Embedding_Prompt_Builder {
		return new Embedding_Prompt_Builder(
			null,
			$transport,
			$this->make_auth(),
			$this->make_registry( $provider_id, $model_id )
		);
	}

	// -----------------------------------------------------------------------
	// No authentication / no configured provider
	// -----------------------------------------------------------------------

	/** Returns WP_Error when no configured provider is found in the registry. */
	public function test_generate_returns_wp_error_when_no_configured_provider(): void {
		$registry = $this->createMock( ProviderRegistry::class );
		$registry->method( 'findModelsMetadataForSupport' )->willReturn( array() );

		$builder = new Embedding_Prompt_Builder( null, null, null, $registry );
		$result  = $builder->generate_embeddings_result( array( 'hello' ) );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'no_authentication', $result->get_error_code() );
	}

	// -----------------------------------------------------------------------
	// OpenAI success path
	// -----------------------------------------------------------------------

	/** Returns GenerativeAiResult with float[][] in additionalData on a valid OpenAI response. */
	public function test_generate_openai_returns_float_vectors(): void {
		$payload = (string) wp_json_encode(
			array(
				'data' => array(
					array(
						'embedding' => array( 0.1, 0.2, 0.3 ),
						'index'     => 0,
					),
					array(
						'embedding' => array( 0.4, 0.5, 0.6 ),
						'index'     => 1,
					),
				),
			)
		);

		$response  = new Response( 200, array(), $payload );
		$transport = $this->createMock( HttpTransporterInterface::class );
		$transport->method( 'send' )->willReturn( $response );

		$result = $this->make_builder( $transport )->generate_embeddings_result( array( 'hello', 'world' ) );

		$this->assertInstanceOf( GenerativeAiResult::class, $result );
		assert( $result instanceof GenerativeAiResult );
		$embeddings = $result->getAdditionalData()['embeddings'];
		$this->assertCount( 2, $embeddings );
		$this->assertSame( array( 0.1, 0.2, 0.3 ), $embeddings[0] );
		$this->assertSame( array( 0.4, 0.5, 0.6 ), $embeddings[1] );
	}

	/** Verifies the OpenAI request body structure. */
	public function test_generate_openai_sends_correct_request_body(): void {
		$payload  = (string) wp_json_encode( array( 'data' => array( array( 'embedding' => array( 0.1 ) ) ) ) );
		$response = new Response( 200, array(), $payload );

		$transport = $this->createMock( HttpTransporterInterface::class );
		$transport->expects( $this->once() )
			->method( 'send' )
			->with(
				$this->callback(
					static function ( Request $req ): bool {
						$body = json_decode( (string) $req->getBody(), true );
						return isset( $body['model'], $body['input'] )
							&& 'text-embedding-3-small' === $body['model']
							&& array( 'test' ) === $body['input'];
					}
				)
			)
			->willReturn( $response );

		$this->make_builder( $transport )->generate_embeddings_result( array( 'test' ) );
	}

	// -----------------------------------------------------------------------
	// OpenAI error paths
	// -----------------------------------------------------------------------

	/** 401 HTTP response → WP_Error. */
	public function test_generate_returns_wp_error_on_401(): void {
		$response  = new Response( 401, array(), (string) wp_json_encode( array( 'error' => 'Unauthorized' ) ) );
		$transport = $this->createMock( HttpTransporterInterface::class );
		$transport->method( 'send' )->willReturn( $response );

		$result = $this->make_builder( $transport )->generate_embeddings_result( array( 'hello' ) );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'embedding_http_error', $result->get_error_code() );
	}

	/** 429 HTTP response → WP_Error. */
	public function test_generate_returns_wp_error_on_429(): void {
		$response  = new Response( 429, array(), (string) wp_json_encode( array( 'error' => 'Rate limited' ) ) );
		$transport = $this->createMock( HttpTransporterInterface::class );
		$transport->method( 'send' )->willReturn( $response );

		$result = $this->make_builder( $transport )->generate_embeddings_result( array( 'hello' ) );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'embedding_http_error', $result->get_error_code() );
	}

	// -----------------------------------------------------------------------
	// Google success path
	// -----------------------------------------------------------------------

	/** Returns GenerativeAiResult with float[][] in additionalData on a valid Google response. */
	public function test_generate_google_returns_float_vectors(): void {
		$payload = (string) wp_json_encode(
			array(
				'embeddings' => array(
					array( 'values' => array( 0.7, 0.8 ) ),
					array( 'values' => array( 0.9, 0.95 ) ),
				),
			)
		);

		$response  = new Response( 200, array(), $payload );
		$transport = $this->createMock( HttpTransporterInterface::class );
		$transport->method( 'send' )->willReturn( $response );

		$result = $this->make_builder( $transport, 'google', 'text-embedding-004' )
			->generate_embeddings_result( array( 'foo', 'bar' ) );

		$this->assertInstanceOf( GenerativeAiResult::class, $result );
		assert( $result instanceof GenerativeAiResult );
		$embeddings = $result->getAdditionalData()['embeddings'];
		$this->assertCount( 2, $embeddings );
		$this->assertSame( array( 0.7, 0.8 ), $embeddings[0] );
		$this->assertSame( array( 0.9, 0.95 ), $embeddings[1] );
	}

	/** Verifies the Google request body structure. */
	public function test_generate_google_sends_correct_request_body(): void {
		$payload  = (string) wp_json_encode( array( 'embeddings' => array( array( 'values' => array( 0.1 ) ) ) ) );
		$response = new Response( 200, array(), $payload );

		$transport = $this->createMock( HttpTransporterInterface::class );
		$transport->expects( $this->once() )
			->method( 'send' )
			->with(
				$this->callback(
					static function ( Request $req ): bool {
						$body = json_decode( (string) $req->getBody(), true );
						return isset( $body['requests'][0]['model'], $body['requests'][0]['content'] )
							&& 'models/text-embedding-004' === $body['requests'][0]['model'];
					}
				)
			)
			->willReturn( $response );

		$this->make_builder( $transport, 'google', 'text-embedding-004' )
			->generate_embeddings_result( array( 'test' ) );
	}

	// -----------------------------------------------------------------------
	// GenerativeAiResult metadata
	// -----------------------------------------------------------------------

	/** Result carries the correct provider and model metadata. */
	public function test_result_carries_provider_and_model_metadata(): void {
		$payload  = (string) wp_json_encode( array( 'data' => array( array( 'embedding' => array( 0.1 ) ) ) ) );
		$response = new Response( 200, array(), $payload );

		$transport = $this->createMock( HttpTransporterInterface::class );
		$transport->method( 'send' )->willReturn( $response );

		$result = $this->make_builder( $transport, 'openai', 'text-embedding-3-small' )
			->generate_embeddings_result( array( 'hello' ) );

		$this->assertInstanceOf( GenerativeAiResult::class, $result );
		assert( $result instanceof GenerativeAiResult );
		$this->assertSame( 'openai', $result->getProviderMetadata()->getId() );
		$this->assertSame( 'text-embedding-3-small', $result->getModelMetadata()->getId() );
	}
}
