<?php
/**
 * Integration tests for Abilities API.
 *
 * @package WP_MariaDB_Vector_Search
 */

declare(strict_types=1);

namespace WP_MariaDB_Vector_Search\Tests\Integration;

use WP_UnitTestCase;

/**
 * Tests for the Abilities API integration.
 */
class Abilities_Test extends WP_UnitTestCase {

	/**
	 * Test that the get-status ability is registered and returns correct data.
	 */
	public function test_get_status_ability(): void {
		// This will fail because the ability is not yet registered.
		$ability = wp_get_ability( 'wp-mariadb-vector-search/get-status' );
		$this->assertNotNull( $ability, 'The get-status ability should be registered.' );

		// Simulate a REST API call to the ability.
		// In a real integration test, we would use the WP REST API testing framework.
		$result = $ability->execute();

		$this->assertIsArray( $result );
		$this->assertArrayHasKey( 'is_supported', $result );
		$this->assertArrayHasKey( 'indexed', $result );
	}

	/**
	 * Test that the reindex ability is registered and can be executed.
	 */
	public function test_reindex_ability(): void {
		// This will fail because the ability is not yet registered.
		$ability = wp_get_ability( 'wp-mariadb-vector-search/reindex' );
		$this->assertNotNull( $ability, 'The reindex ability should be registered.' );

		// Simulate a REST API call to the ability with input.
		$input = array(
			'force'           => true,
			'confirm_rebuild' => true,
		);

		$result = $ability->execute( $input );

		$this->assertIsArray( $result );
		$this->assertArrayHasKey( 'rebuilt', $result );
	}
}
