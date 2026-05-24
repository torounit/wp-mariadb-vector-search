<?php
/**
 * Database repository for vector embeddings.
 *
 * @package WP_MariaDB_Vector_Search
 */

declare(strict_types=1);

namespace WP_MariaDB_Vector_Search;

/**
 * Wraps $wpdb operations for the embeddings table and provides
 * low-level vector serialization helpers.
 */
class Repository {

	/**
	 * Serialize a float array to the text format expected by VEC_FromText().
	 *
	 * Uses number_format with an explicit '.' decimal separator so the output
	 * is locale-independent regardless of the LC_NUMERIC setting on the host.
	 *
	 * @param float[] $vector Non-empty array of finite floats.
	 * @return string e.g. "[0.1,0.2,0.3]"
	 * @throws \InvalidArgumentException On empty array or non-finite values.
	 */
	public static function format_vector_literal( array $vector ): string {
		if ( empty( $vector ) ) {
			throw new \InvalidArgumentException( 'Vector must not be empty.' );
		}

		$parts = [];
		foreach ( $vector as $value ) {
			$f = (float) $value;
			if ( is_nan( $f ) || is_infinite( $f ) ) {
				throw new \InvalidArgumentException(
					'Vector components must be finite numbers; got ' . var_export( $value, true ) . '.'
				);
			}
			// number_format always uses '.' as the decimal separator (locale-safe).
			// 10 decimal places covers float32 precision for values in [-1, 1].
			$str    = number_format( $f, 10, '.', '' );
			$parts[] = rtrim( rtrim( $str, '0' ), '.' );
		}

		return '[' . implode( ',', $parts ) . ']';
	}
}
