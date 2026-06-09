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
				'type'              => 'object',
				'default'           => array(),
				'sanitize_callback' => array( __CLASS__, 'sanitize_settings' ),
				'show_in_rest'      => array(
					'schema' => array(
						'type'                 => 'object',
						'additionalProperties' => false,
						'properties'           => array(
							'provider'   => array(
								'type' => 'string',
							),
							'model'      => array(
								'type' => 'string',
							),
							'dimensions' => array(
								'type' => 'integer',
							),
						),
					),
				),
			)
		);
	}

	/**
	 * Sanitize settings.
	 *
	 * @param mixed $input Sanitized input.
	 * @return array Sanitized settings.
	 */
	public static function sanitize_settings( mixed $input ): array {
		if ( ! is_array( $input ) ) {
			$input = array();
		}

		$existing = get_option( self::OPTION_NAME, array() );
		if ( ! is_array( $existing ) ) {
			$existing = array();
		}

		$sanitized = array();

		if ( isset( $input['provider'] ) && is_string( $input['provider'] ) ) {
			$sanitized['provider'] = sanitize_text_field( $input['provider'] );
		} elseif ( isset( $existing['provider'] ) && is_string( $existing['provider'] ) ) {
			$sanitized['provider'] = $existing['provider'];
		}

		if ( isset( $input['model'] ) && is_string( $input['model'] ) ) {
			$sanitized['model'] = sanitize_text_field( $input['model'] );
		} elseif ( isset( $existing['model'] ) && is_string( $existing['model'] ) ) {
			$sanitized['model'] = $existing['model'];
		}

		if ( isset( $input['dimensions'] ) && is_numeric( $input['dimensions'] ) && (int) $input['dimensions'] >= 1 ) {
			$sanitized['dimensions'] = (int) $input['dimensions'];
		} elseif ( isset( $existing['dimensions'] ) && is_numeric( $existing['dimensions'] ) ) {
			$sanitized['dimensions'] = (int) $existing['dimensions'];
		}

		return $sanitized;
	}
}
