<?php
/**
 * Embedding prompt builder — extends WP_AI_Client_Prompt_Builder with embedding support.
 *
 * @package WP_MariaDB_Vector_Search
 */

declare(strict_types=1);

namespace WP_MariaDB_Vector_Search;

use WordPress\AiClient\AiClient;
use WordPress\AiClient\Messages\DTO\Message;
use WordPress\AiClient\Messages\DTO\MessagePart;
use WordPress\AiClient\Messages\Enums\MessageRoleEnum;
use WordPress\AiClient\Providers\ApiBasedImplementation\AbstractApiProvider;
use WordPress\AiClient\Providers\DTO\ProviderMetadata;
use WordPress\AiClient\Providers\Enums\ProviderTypeEnum;
use WordPress\AiClient\Providers\Http\Contracts\HttpTransporterInterface;
use WordPress\AiClient\Providers\Http\Contracts\RequestAuthenticationInterface;
use WordPress\AiClient\Providers\Http\DTO\Request;
use WordPress\AiClient\Providers\Http\Enums\HttpMethodEnum;
use WordPress\AiClient\Providers\Http\HttpTransporterFactory;
use WordPress\AiClient\Providers\Http\Util\ResponseUtil;
use WordPress\AiClient\Providers\Models\DTO\ModelMetadata;
use WordPress\AiClient\Providers\Models\DTO\ModelRequirements;
use WordPress\AiClient\Providers\Models\Enums\CapabilityEnum;
use WordPress\AiClient\Providers\ProviderRegistry;
use WordPress\AiClient\Results\DTO\Candidate;
use WordPress\AiClient\Results\DTO\GenerativeAiResult;
use WordPress\AiClient\Results\DTO\TokenUsage;
use WordPress\AiClient\Results\Enums\FinishReasonEnum;

/**
 * Extends WP_AI_Client_Prompt_Builder with embedding generation.
 *
 * Inherits all fluent methods from the parent class. Adds generate_embeddings_result(),
 * which uses the AI Client provider registry to discover the first configured provider
 * that supports embedding generation, makes a direct HTTP request to that provider,
 * and returns a GenerativeAiResult with the float[][] vectors in additionalData['embeddings'].
 */
class Embedding_Prompt_Builder extends \WP_AI_Client_Prompt_Builder {

	/**
	 * Provider registry used for discovering embedding-capable providers.
	 *
	 * @var ProviderRegistry
	 */
	private ProviderRegistry $registry;

	/**
	 * Optional injected transporter (for tests).
	 *
	 * @var HttpTransporterInterface|null
	 */
	private ?HttpTransporterInterface $transport;

	/**
	 * Optional injected auth (for tests; bypasses registry for all providers).
	 *
	 * @var RequestAuthenticationInterface|null
	 */
	private ?RequestAuthenticationInterface $auth;

	/**
	 * Constructor.
	 *
	 * @param mixed                               $prompt    Optional. Initial prompt content forwarded to parent.
	 * @param HttpTransporterInterface|null       $transport HTTP transporter; null uses the SDK factory.
	 * @param RequestAuthenticationInterface|null $auth      Auth instance; null resolves from registry.
	 * @param ProviderRegistry|null               $registry  Provider registry; null uses the AI Client default.
	 */
	public function __construct(
		$prompt = null,
		?HttpTransporterInterface $transport = null,
		?RequestAuthenticationInterface $auth = null,
		?ProviderRegistry $registry = null,
	) {
		$this->registry  = $registry ?? AiClient::defaultRegistry();
		$this->transport = $transport;
		$this->auth      = $auth;
		parent::__construct( $this->registry, $prompt );
	}

	/**
	 * Generate text embeddings for a batch of texts.
	 *
	 * Resolution order:
	 *  1. Capability auto-detection via the provider registry — used when a provider
	 *     declares embeddingGeneration support.
	 *  2. Fixed default fallback (filterable) — used when auto-detection finds no
	 *     candidates (e.g. the OpenAI provider plugin does not tag embedding models
	 *     with embeddingGeneration capability). Default: openai / text-embedding-3-small.
	 *     Override via the wp_mariadb_vector_search_embedding_model filter.
	 *
	 * @param string[] $texts Non-empty array of strings to embed.
	 * @return GenerativeAiResult|\WP_Error Result with embeddings in additionalData, or WP_Error on failure.
	 */
	public function generate_embeddings_result( array $texts ): GenerativeAiResult|\WP_Error {
		$requirements = new ModelRequirements(
			array( CapabilityEnum::embeddingGeneration() ),
			array()
		);

		$candidates = $this->registry->findModelsMetadataForSupport( $requirements );

		if ( ! empty( $candidates ) ) {
			// Auto-detection succeeded: use the first capable provider/model.
			$provider_metadata = $candidates[0]->getProvider();
			$model_metadata    = $candidates[0]->getModels()[0];
		} else {
			// Auto-detection failed: fall back to the fixed default (filterable).
			$selection = apply_filters(
				'wp_mariadb_vector_search_embedding_model',
				array(
					'provider' => 'openai',
					'model'    => 'text-embedding-3-small',
				)
			);
			$provider  = is_array( $selection ) ? (string) ( $selection['provider'] ?? '' ) : '';
			$model     = is_array( $selection ) ? (string) ( $selection['model'] ?? '' ) : '';

			if ( '' === $provider || '' === $model || ! $this->is_provider_usable( $provider ) ) {
				return new \WP_Error(
					'no_authentication',
					__( 'No configured embedding provider found. Configure one in Settings > General > AI Connector.', 'wp-mariadb-vector-search' )
				);
			}

			$provider_metadata = new ProviderMetadata( $provider, $provider, ProviderTypeEnum::cloud() );
			$model_metadata    = new ModelMetadata(
				$model,
				$model,
				array( CapabilityEnum::embeddingGeneration() ),
				array()
			);
		}

		$vectors = $this->try_provider( $texts, $provider_metadata->getId(), $model_metadata->getId() );

		if ( is_wp_error( $vectors ) ) {
			return $vectors;
		}

		$message   = new Message( MessageRoleEnum::model(), array( new MessagePart( '' ) ) );
		$candidate = new Candidate( $message, FinishReasonEnum::stop() );

		return new GenerativeAiResult(
			uniqid( 'embedding_', true ),
			array( $candidate ),
			new TokenUsage( 0, 0, 0 ),
			$provider_metadata,
			$model_metadata,
			array( 'embeddings' => $vectors )
		);
	}

	/**
	 * Check whether a provider is registered and usable.
	 *
	 * Returns true when:
	 *  - The provider is registered in the registry, AND
	 *  - Auth has been injected externally (unit-test path), OR the provider is configured
	 *    (i.e. has an API key set via the AI Connector settings).
	 *
	 * @param string $provider Provider id.
	 * @return bool
	 */
	private function is_provider_usable( string $provider ): bool {
		try {
			if ( ! $this->registry->hasProvider( $provider ) ) {
				return false;
			}

			// When auth is injected externally (e.g. unit tests), skip the
			// isProviderConfigured check — the injected auth takes precedence.
			if ( null !== $this->auth ) {
				return true;
			}

			return $this->registry->isProviderConfigured( $provider );
		} catch ( \Throwable ) {
			return false;
		}
	}

	/**
	 * Attempt embedding generation for a single [provider, model] pair.
	 *
	 * @param string[] $texts    Texts to embed.
	 * @param string   $provider Provider id.
	 * @param string   $model    Model name.
	 * @return float[][]|\WP_Error
	 */
	private function try_provider( array $texts, string $provider, string $model ): array|\WP_Error {
		$auth = $this->resolve_auth( $provider );

		if ( is_wp_error( $auth ) ) {
			return $auth;
		}

		try {
			$request   = $auth->authenticateRequest( $this->build_request( $texts, $provider, $model ) );
			$transport = $this->transport ?? HttpTransporterFactory::createTransporter();
			$response  = $transport->send( $request );
			ResponseUtil::throwIfNotSuccessful( $response );
			$data = $response->getData();
		} catch ( \Throwable $e ) {
			return new \WP_Error(
				'embedding_http_error',
				$e->getMessage(),
				array( 'exception' => $e )
			);
		}

		return $this->extract_vectors( $data, $provider );
	}

	/**
	 * Resolve authentication for a provider.
	 *
	 * Uses injected auth (testing) if set; otherwise queries the AI Client registry.
	 *
	 * @param string $provider Provider id.
	 * @return RequestAuthenticationInterface|\WP_Error
	 */
	private function resolve_auth( string $provider ): RequestAuthenticationInterface|\WP_Error {
		if ( null !== $this->auth ) {
			return $this->auth;
		}

		try {
			$auth = $this->registry->getProviderRequestAuthentication( $provider );
		} catch ( \Throwable $e ) {
			$auth = null;
		}

		if ( null === $auth ) {
			return new \WP_Error(
				'no_authentication',
				sprintf(
					/* translators: %s: AI provider id, e.g. "openai". */
					__( 'No API key is configured for the "%s" AI provider. Configure it in Settings > General > AI Connector.', 'wp-mariadb-vector-search' ),
					$provider
				)
			);
		}

		return $auth;
	}

	/**
	 * Build the provider-specific HTTP Request DTO.
	 *
	 * @param string[] $texts    Texts to embed.
	 * @param string   $provider Provider id.
	 * @param string   $model    Model name.
	 * @return Request
	 */
	private function build_request( array $texts, string $provider, string $model ): Request {
		return ( 'google' === $provider )
			? $this->build_google_request( $texts, $provider, $model )
			: $this->build_openai_request( $texts, $provider, $model );
	}

	/**
	 * Build an OpenAI /v1/embeddings request.
	 *
	 * @param string[] $texts    Texts to embed.
	 * @param string   $provider Provider id.
	 * @param string   $model    Model name.
	 * @return Request
	 */
	private function build_openai_request( array $texts, string $provider, string $model ): Request {
		return new Request(
			HttpMethodEnum::POST(),
			$this->resolve_url( $provider, 'embeddings', 'https://api.openai.com/v1/embeddings' ),
			array( 'Content-Type' => 'application/json' ),
			(string) wp_json_encode(
				array(
					'model' => $model,
					'input' => $texts,
				)
			)
		);
	}

	/**
	 * Build a Google Generative AI batchEmbedContents request.
	 *
	 * @param string[] $texts    Texts to embed.
	 * @param string   $provider Provider id.
	 * @param string   $model    Model name.
	 * @return Request
	 */
	private function build_google_request( array $texts, string $provider, string $model ): Request {
		$requests = array_map(
			static fn( string $text ) => array(
				'model'   => 'models/' . $model,
				'content' => array( 'parts' => array( array( 'text' => $text ) ) ),
			),
			$texts
		);

		return new Request(
			HttpMethodEnum::POST(),
			$this->resolve_url(
				$provider,
				'models/' . $model . ':batchEmbedContents',
				'https://generativelanguage.googleapis.com/v1beta/models/' . $model . ':batchEmbedContents'
			),
			array( 'Content-Type' => 'application/json' ),
			(string) wp_json_encode( array( 'requests' => $requests ) )
		);
	}

	/**
	 * Resolve the endpoint URL for a provider.
	 *
	 * If the provider class extends AbstractApiProvider, uses its url() method so
	 * that OpenAI-compatible providers (Azure, local LLMs, AI gateways, etc.) can
	 * supply their own base URL without hard-coding it here.
	 * Falls back to $fallback when the provider is not registered or does not
	 * extend AbstractApiProvider.
	 *
	 * @param string $provider Provider id.
	 * @param string $path     Endpoint path to append to the base URL.
	 * @param string $fallback Hard-coded URL used when the provider class is unknown.
	 * @return string Full endpoint URL.
	 */
	private function resolve_url( string $provider, string $path, string $fallback ): string {
		try {
			$class = $this->registry->getProviderClassName( $provider );
		} catch ( \Throwable ) {
			$class = '';
		}

		if ( '' !== $class && is_subclass_of( $class, AbstractApiProvider::class ) ) {
			return $class::url( $path );
		}

		return $fallback;
	}

	/**
	 * Extract float vectors from a decoded JSON API response.
	 *
	 * @param array<string, mixed>|null $data     Decoded JSON body.
	 * @param string                    $provider Provider id.
	 * @return float[][]|\WP_Error
	 */
	private function extract_vectors( ?array $data, string $provider ): array|\WP_Error {
		if ( null === $data ) {
			return new \WP_Error(
				'embedding_parse_error',
				__( 'Empty or non-JSON response from embedding API.', 'wp-mariadb-vector-search' )
			);
		}

		return ( 'google' === $provider )
			? $this->extract_google_vectors( $data )
			: $this->extract_openai_vectors( $data );
	}

	/**
	 * Extract vectors from OpenAI response (data[].embedding).
	 *
	 * @param array<string, mixed> $data Decoded OpenAI response body.
	 * @return float[][]|\WP_Error
	 */
	private function extract_openai_vectors( array $data ): array|\WP_Error {
		if ( empty( $data['data'] ) || ! is_array( $data['data'] ) ) {
			return new \WP_Error(
				'embedding_parse_error',
				__( 'Unexpected OpenAI embeddings response structure.', 'wp-mariadb-vector-search' )
			);
		}

		return array_column( $data['data'], 'embedding' );
	}

	/**
	 * Extract vectors from Google response (embeddings[].values).
	 *
	 * @param array<string, mixed> $data Decoded Google response body.
	 * @return float[][]|\WP_Error
	 */
	private function extract_google_vectors( array $data ): array|\WP_Error {
		if ( empty( $data['embeddings'] ) || ! is_array( $data['embeddings'] ) ) {
			return new \WP_Error(
				'embedding_parse_error',
				__( 'Unexpected Google embeddings response structure.', 'wp-mariadb-vector-search' )
			);
		}

		return array_column( $data['embeddings'], 'values' );
	}
}
