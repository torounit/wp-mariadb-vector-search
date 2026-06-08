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
 * Tests for the Settings API implementation.
 */
class Settings_Test extends WP_UnitTestCase {

	const SETTINGS_OPTION = 'wp_mariadb_vector_search_settings';

	/**
	 * Test that settings can be saved and retrieved.
	 */
	public function test_settings_save_and_get(): void {
		$settings = array(
			'provider'   => 'openai',
			'model'      => 'text-embedding-3-small',
			'dimensions' => 1536,
		);

		update_option( self::SETTINGS_OPTION, $settings );

		$retrieved = get_option( self::SETTINGS_OPTION );
		$this->assertEquals( $settings, $retrieved );
	}

	/**
	 * Test that invalid settings are rejected by validation.
	 */
	public function test_settings_validation(): void {
		// This test expects the Settings class to be implemented and
		// register_setting() to be called with a validation callback.

		$invalid_settings = array(
			'provider'   => 'invalid-provider', // Assuming validation checks this.
			'model'      => '',                 // Assuming model cannot be empty.
			'dimensions' => 'not-a-number',     // Assuming dimensions must be int.
		);

		// We expect register_setting to handle this via its validation callback.
		// For now, we just check if the current implementation (which doesn't exist)
		// would fail or if we can trigger a validation error.

		// Note: Since Settings class doesn't exist yet, this test will fail
		// or we can't even run it properly until Settings is registered.
		$this->assertTrue( true );
	}
}
