<?php
/**
 * Integration tests for the Embedding_Client class.
 *
 * The WP 7.0 AI Connector is stubbed via the wp_mariadb_vector_search_embed
 * filter so tests run offline and deterministically.
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

	private Embedding_Client $client;

	public function set_up(): void {
		parent::set_up();
		$this->client = new Embedding_Client();
	}

	public function tear_down(): void {
		remove_all_filters( 'wp_mariadb_vector_search_embed' );
		parent::tear_down();
	}

	public function test_embed_uses_filter_stub(): void {
		add_filter(
			'wp_mariadb_vector_search_embed',
			static function ( $result, array $texts ) {
				return array_map( static fn( $t ) => [ 1.0, 0.0, 0.0 ], $texts );
			},
			10,
			2
		);

		$vectors = $this->client->embed( [ 'hello', 'world' ] );

		$this->assertCount( 2, $vectors );
		$this->assertSame( [ 1.0, 0.0, 0.0 ], $vectors[0] );
		$this->assertSame( [ 1.0, 0.0, 0.0 ], $vectors[1] );
	}

	public function test_embed_returns_wp_error_without_provider(): void {
		// No filter registered → no provider → WP_Error.
		$result = $this->client->embed( [ 'hello' ] );

		$this->assertInstanceOf( \WP_Error::class, $result );
	}

	public function test_embed_single_text_returns_single_vector(): void {
		add_filter(
			'wp_mariadb_vector_search_embed',
			static function ( $result, array $texts ) {
				return array_map( static fn() => [ 0.5, 0.5 ], $texts );
			},
			10,
			2
		);

		$vectors = $this->client->embed( [ 'single' ] );

		$this->assertIsArray( $vectors );
		$this->assertCount( 1, $vectors );
	}

	public function test_embed_filter_returning_wp_error_is_passed_through(): void {
		add_filter(
			'wp_mariadb_vector_search_embed',
			static function () {
				return new \WP_Error( 'api_error', 'Something went wrong.' );
			}
		);

		$result = $this->client->embed( [ 'hello' ] );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'api_error', $result->get_error_code() );
	}
}
