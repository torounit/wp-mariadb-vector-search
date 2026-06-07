<?php
/**
 * Integration tests for Settings API.
 *
 * @package WP_MariaDB_Vector_Search
 */

declare(strict_types=1);

namespace WP_MariaDB_Vector_Search\Tests\Integration;

use WP_UnitTestCase;

/**
 * Tests for the Settings API integration.
 */
class Settings_Test extends WP_UnitTestCase {

	/**
	 * Test that settings can be saved and retrieved.
	 */
	public function test_settings_save_and_retrieve(): void {
		// This will fail because the settings are not yet registered.
		$settings = get_option( 'wp_mariadb_vector_search_settings' );
		$this->assertIsArray( $settings );

		$new_settings = array(
			'provider'   => 'openai',
			'model'      => 'text-embedding-3-small',
			'dimensions' => 1536,
		);

		update_option( 'wp_mariadb_vector_search_settings', $new_settings );

		$retrieved_settings = get_option( 'wp_mariadb_vector_search_settings' );
		$this->assertEquals( $new_settings, $retrieved_settings );
	}
}
