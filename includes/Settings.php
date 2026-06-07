<?php
/**
 * Settings registration for the plugin.
 *
 * @package WP_MariaDB_Vector_Search
 */

declare(strict_types=1);

namespace WP_MariaDB_Vector_Search;

/**
 * Handles registration of plugin settings.
 */
class Settings {

	const OPTION_NAME = 'wp_mariadb_vector_search_settings';

	/**
	 * Register settings.
	 *
	 * @return void
	 */
	public static function register(): void {
		register_setting(
			'general',
			self::OPTION_NAME,
			array(
				'sanitize_callback' => array( __CLASS__, 'sanitize_settings' ),
				'show_in_rest'      => true,
			)
		);
	}

	/**
	 * Sanitize settings.
	 *
	 * @param array $input Sanitized input.
	 * @return array Sanitized settings.
	 */
	public static function sanitize_settings( array $input ): array {
		$sanitized = array();

		if ( isset( $input['provider'] ) && is_string( $input['provider'] ) ) {
			$sanitized['provider'] = sanitize_text_field( $input['provider'] );
		}

		if ( isset( $input['model'] ) && is_string( $input['model'] ) ) {
			$sanitized['model'] = sanitize_text_field( $input['model'] );
		}

		if ( isset( $input['dimensions'] ) && is_numeric( $input['dimensions'] ) ) {
			$sanitized['dimensions'] = (int) $input['dimensions'];
		}

		return $sanitized;
	}
}
