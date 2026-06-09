<?php
/**
 * Integration tests for Settings API.
 *
 * @package WP_MariaDB_Vector_Search
 */

declare(strict_types=1);

namespace WP_MariaDB_Vector_Search\Tests\Integration;

use WP_MariaDB_Vector_Search\Settings;
use WP_UnitTestCase;

/**
 * Tests for the Settings API implementation.
 */
class Settings_Test extends WP_UnitTestCase {

	const SETTINGS_OPTION = 'wp_mariadb_vector_search_settings';

	/**
	 * Ensure each test starts with no stored settings.
	 */
	public function set_up(): void {
		parent::set_up();
		delete_option( self::SETTINGS_OPTION );
	}

	/**
	 * Clean up stored settings after each test.
	 */
	public function tear_down(): void {
		delete_option( self::SETTINGS_OPTION );
		parent::tear_down();
	}

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
	 * Non-numeric dimensions are rejected; unknown keys are not passed through.
	 */
	public function test_settings_sanitize_rejects_non_numeric_dimensions(): void {
		$result = Settings::sanitize_settings(
			array(
				'provider'   => 'openai',
				'model'      => 'text-embedding-3-small',
				'dimensions' => 'not-a-number',
				'unknown'    => 'should-be-dropped',
			)
		);

		$this->assertSame( 'openai', $result['provider'] );
		$this->assertSame( 'text-embedding-3-small', $result['model'] );
		$this->assertArrayNotHasKey( 'dimensions', $result );
		$this->assertArrayNotHasKey( 'unknown', $result );
	}

	/**
	 * Partial updates preserve existing provider, model, and dimensions.
	 */
	public function test_settings_sanitize_preserves_existing_on_partial_update(): void {
		update_option(
			self::SETTINGS_OPTION,
			array(
				'provider'   => 'openai',
				'model'      => 'text-embedding-3-small',
				'dimensions' => 1536,
			)
		);

		// Only dimensions is in the payload; provider and model should be preserved.
		$result = Settings::sanitize_settings( array( 'dimensions' => 768 ) );

		$this->assertSame( 'openai', $result['provider'] );
		$this->assertSame( 'text-embedding-3-small', $result['model'] );
		$this->assertSame( 768, $result['dimensions'] );
	}
}
