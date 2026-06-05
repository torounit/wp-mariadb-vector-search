<?php
/**
 * Post indexer — generates and stores embeddings when posts are saved.
 *
 * @package WP_MariaDB_Vector_Search
 */

declare(strict_types=1);

namespace WP_MariaDB_Vector_Search;

/**
 * Orchestrates the embed → store pipeline for a single post.
 *
 * The actual embedding is delegated to Embedding_Client; storage to
 * Repository. This class is also responsible for deciding WHEN to
 * (re-)index (published posts only, skip on unchanged content hash).
 */
class Indexer {

	private const MODEL = 'wp-ai-connector';

	/**
	 * Constructor.
	 *
	 * @param Embedding_Client $client     Embedding provider wrapper.
	 * @param Repository       $repository Embeddings table wrapper.
	 * @param Chunker          $chunker    Text splitter.
	 */
	public function __construct(
		private Embedding_Client $client,
		private Repository $repository,
		private Chunker $chunker = new Chunker(),
	) {}

	/**
	 * Index a single post: chunk → embed → store.
	 *
	 * Skips non-published posts and posts whose content hash has not changed.
	 *
	 * @param int $post_id Post ID.
	 * @return void
	 */
	public function index_post( int $post_id ): void {
		$post = get_post( $post_id );
		if ( ! $post instanceof \WP_Post ) {
			wp_mariadb_vector_search_log( "index_post({$post_id}): post not found, skipping." );
			return;
		}

		if ( 'publish' !== $post->post_status ) {
			wp_mariadb_vector_search_log( "index_post({$post_id}): status={$post->post_status}, skipping (not published)." );
			return;
		}

		$hash = Content_Hash::compute( $post->post_title, $post->post_content );
		if ( $this->repository->get_content_hash( $post_id ) === $hash ) {
			wp_mariadb_vector_search_log( "index_post({$post_id}): content hash unchanged, skipping." );
			return;
		}

		$texts  = $this->chunker->chunk( $post->post_content, $post->post_title );
		$result = $this->client->embed( $texts );

		if ( is_wp_error( $result ) ) {
			wp_mariadb_vector_search_log(
				sprintf(
					'index_post(%d): embed failed [%s] %s',
					$post_id,
					$result->get_error_code(),
					$result->get_error_message()
				)
			);
			return;
		}

		$dim_info = count( $result ) > 0 ? count( $result[0] ) : 0;
		wp_mariadb_vector_search_log(
			sprintf(
				'index_post(%d): embed OK — %d chunk(s), %d dimensions.',
				$post_id,
				count( $result ),
				$dim_info
			)
		);

		$chunks = array();
		foreach ( $result as $i => $vector ) {
			$chunks[] = array(
				'chunk_index' => $i,
				'chunk_text'  => $texts[ $i ],
				'vector'      => $vector,
			);
		}

		$this->repository->replace_post_chunks(
			$post_id,
			$post->post_type,
			$hash,
			self::MODEL,
			$chunks
		);
	}

	/**
	 * Remove all stored embeddings for a post.
	 *
	 * @param int $post_id Post ID.
	 * @return void
	 */
	public function delete_post( int $post_id ): void {
		$this->repository->delete_post( $post_id );
	}
}
