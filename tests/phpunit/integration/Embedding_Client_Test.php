<?php
/**
 * Integration tests for the Embedding_Client class.
 *
 * @package WP_MariaDB_Vector_Search
 */

declare(strict_types=1);

namespace WP_MariaDB_Vector_Search\Tests\Integration;

use WP_MariaDB_Vector_Search\Embedding_Client;

/**
 * Class Embedding_Client_Test
 */
class Embedding_Client_Test extends \WP_UnitTestCase {

	/**
	 * Tear down.
	 */
	public function tear_down(): void {
		remove_all_filters( 'wp_mariadb_vector_search_embed' );
		remove_all_filters( 'wp_mariadb_vector_search_embed_model' );
		parent::tear_down();
	}

	/** Filter overrides the decorator's WP_Error with valid vectors. */
	public function test_embed_uses_filter(): void {
		add_filter(
			'wp_mariadb_vector_search_embed',
			static function ( $_default, array $texts ) {
				return array_map( static fn() => array( 1.0, 0.0, 0.0 ), $texts );
			},
			10,
			2
		);

		$client  = new Embedding_Client();
		$vectors = $client->embed( array( 'hello', 'world' ) );

		$this->assertCount( 2, $vectors );
		$this->assertSame( array( 1.0, 0.0, 0.0 ), $vectors[0] );
	}

	/** No filter + no provider configured → WP_Error propagates. */
	public function test_embed_returns_wp_error_without_provider(): void {
		$result = ( new Embedding_Client() )->embed( array( 'hello' ) );

		$this->assertInstanceOf( \WP_Error::class, $result );
	}

	/** Filter returning WP_Error propagates that error. */
	public function test_embed_filter_wp_error_propagates(): void {
		add_filter(
			'wp_mariadb_vector_search_embed',
			static function () {
				return new \WP_Error( 'api_error', 'Something went wrong.' );
			}
		);

		$result = ( new Embedding_Client() )->embed( array( 'hello' ) );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'api_error', $result->get_error_code() );
	}

	/** Filter can override a WP_Error default with valid vectors. */
	public function test_embed_filter_overrides_error_with_vectors(): void {
		add_filter(
			'wp_mariadb_vector_search_embed',
			static function ( $_default, array $texts ) {
				return array_map( static fn() => array( 0.5, 0.5 ), $texts );
			},
			10,
			2
		);

		$result = ( new Embedding_Client() )->embed( array( 'hello' ) );

		$this->assertIsArray( $result );
		$this->assertSame( array( 0.5, 0.5 ), $result[0] );
	}

	/** The wp_mariadb_vector_search_embed_model filter is applied and surfaces the model via the out-param. */
	public function test_embed_surfaces_model(): void {
		add_filter(
			'wp_mariadb_vector_search_embed',
			static function ( $_default, array $texts ) {
				return array_map( static fn() => array( 1.0, 0.0, 0.0 ), $texts );
			},
			10,
			2
		);

		add_filter(
			'wp_mariadb_vector_search_embed_model',
			static function () {
				return 'my-model';
			}
		);

		$model  = null;
		$client = new Embedding_Client();
		$client->embed( array( 'hello' ), $model );

		$this->assertSame( 'my-model', $model );
	}
}
