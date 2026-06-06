<?php
/**
 * Embedding client — thin wrapper over Embedding_Prompt_Builder.
 *
 * @package WP_MariaDB_Vector_Search
 */

declare(strict_types=1);

namespace WP_MariaDB_Vector_Search;

/**
 * Thin wrapper that delegates embedding generation to Embedding_Prompt_Builder.
 *
 * An optional Embedding_Prompt_Builder instance can be injected via the
 * constructor for testing purposes. When omitted, wp_mariadb_vector_search_prompt()
 * is used to obtain the shared instance.
 */
class Embedding_Client {

	/**
	 * Optional injected prompt builder (used in tests).
	 *
	 * @var Embedding_Prompt_Builder|null
	 */
	private ?Embedding_Prompt_Builder $prompt;

	/**
	 * Constructor.
	 *
	 * @param Embedding_Prompt_Builder|null $prompt Optional prompt builder; null uses the shared instance.
	 */
	public function __construct( ?Embedding_Prompt_Builder $prompt = null ) {
		$this->prompt = $prompt;
	}

	/**
	 * Generate embeddings for a batch of texts.
	 *
	 * Delegates to the injected (or shared) Embedding_Prompt_Builder and returns
	 * the float[][] vectors from additionalData['embeddings'], or WP_Error on failure.
	 *
	 * @param string[] $texts Non-empty array of strings to embed.
	 * @return float[][]|\WP_Error Parallel array of float vectors, or WP_Error on failure.
	 */
	public function embed( array $texts ): array|\WP_Error {
		$builder = $this->prompt ?? wp_mariadb_vector_search_prompt();
		$result  = $builder->generate_embeddings_result( $texts );

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		return $result->getAdditionalData()['embeddings'] ?? array();
	}
}
