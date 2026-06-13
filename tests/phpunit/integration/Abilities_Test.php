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
 * Tests for the Abilities API implementation.
 */
class Abilities_Test extends WP_UnitTestCase {

	/**
	 * Test that the 'get-status' ability is registered and returns correct data.
	 */
	public function test_get_status_ability(): void {
		// Check if the ability is registered.
		$this->assertTrue( function_exists( 'wp_has_ability' ) );
		$this->assertTrue( wp_has_ability( 'wp-mariadb-vector-search/get-status' ) );

		// Set up a user with manage_options capability.
		$user_id = $this->factory->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $user_id );

		// Simulate a REST API request to the ability endpoint.
		$ability = wp_get_ability( 'wp-mariadb-vector-search/get-status' );
		$this->assertNotNull( $ability );

		// Execute the ability.
		$result = $ability->execute();

		// Verify the structure of the result (based on StatusResponse).
		$this->assertIsArray( $result );
		$this->assertArrayHasKey( 'is_supported', $result );
		$this->assertArrayHasKey( 'installed', $result );
		$this->assertArrayHasKey( 'indexed', $result );
		$this->assertArrayHasKey( 'table_dims', $result );
		$this->assertArrayHasKey( 'progress', $result );
		$this->assertArrayHasKey( 'settings', $result );
		$this->assertArrayHasKey( 'available_models', $result );
		$this->assertArrayHasKey( 'dim_changed', $result );
	}

	/**
	 * Test that the 'reindex' ability is registered and can be executed.
	 */
	public function test_reindex_ability(): void {
		// Check if the ability is registered.
		$this->assertTrue( wp_has_ability( 'wp-mariadb-vector-search/reindex' ) );

		// Set up a user with manage_options capability.
		$user_id = $this->factory->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $user_id );

		$ability = wp_get_ability( 'wp-mariadb-vector-search/reindex' );
		$this->assertNotNull( $ability );

		// Execute the ability with some input.
		$input = array(
			'force'           => true,
			'confirm_rebuild' => true,
		);

		$result = $ability->execute( $input );

		// Verify the result.
		$this->assertIsArray( $result );
		$this->assertArrayHasKey( 'rebuilt', $result );
	}
}
