<?php
/**
 * Database schema management.
 *
 * @package WP_MariaDB_Vector_Search
 */

declare(strict_types=1);

namespace WP_MariaDB_Vector_Search;

/**
 * Creates, upgrades, and drops the embeddings table.
 *
 * dbDelta() does not understand VECTOR(N) or VECTOR INDEX, so all DDL is
 * executed with raw $wpdb->query() calls guarded by a versioned option.
 */
class Schema {

	const DB_VERSION       = '1';
	const DB_VERSION_OPTION = 'wp_mariadb_vector_search_db_version';

	/**
	 * Install or upgrade the embeddings table.
	 *
	 * Safe to call multiple times (idempotent).
	 *
	 * @param int $dimensions Embedding vector dimension, e.g. 1536.
	 * @return void
	 */
	public static function install( int $dimensions ): void {
		global $wpdb;

		$table           = $wpdb->prefix . 'mariadb_vector_embeddings';
		$charset_collate = $wpdb->get_charset_collate();
		$current_ver     = get_option( self::DB_VERSION_OPTION, '' );

		if ( $current_ver === self::DB_VERSION ) {
			return;
		}

		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$result = $wpdb->query(
			"CREATE TABLE IF NOT EXISTS `{$table}` (
				`id`           BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
				`post_id`      BIGINT UNSIGNED NOT NULL,
				`chunk_index`  SMALLINT UNSIGNED NOT NULL,
				`post_type`    VARCHAR(20) NOT NULL,
				`model`        VARCHAR(64) NOT NULL,
				`dimensions`   SMALLINT UNSIGNED NOT NULL,
				`embedding`    VECTOR({$dimensions}) NOT NULL,
				`chunk_text`   TEXT NOT NULL,
				`content_hash` CHAR(64) NOT NULL,
				`updated_at`   DATETIME NOT NULL,
				UNIQUE KEY `post_chunk` (`post_id`, `chunk_index`),
				KEY `post_type_idx` (`post_type`),
				VECTOR INDEX (`embedding`) DISTANCE=cosine
			) ENGINE=InnoDB {$charset_collate}"
		);
		// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		if ( false !== $result ) {
			update_option( self::DB_VERSION_OPTION, self::DB_VERSION );
		}
	}

	/**
	 * Return true if the embeddings table has been successfully created.
	 *
	 * @return bool
	 */
	public static function is_installed(): bool {
		return get_option( self::DB_VERSION_OPTION ) === self::DB_VERSION;
	}

	/**
	 * Drop the embeddings table.
	 *
	 * Called on plugin uninstall.
	 *
	 * @return void
	 */
	public static function drop(): void {
		global $wpdb;
		$table = $wpdb->prefix . 'mariadb_vector_embeddings';
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$wpdb->query( "DROP TABLE IF EXISTS `{$table}`" );
	}

	/**
	 * Check whether the connected database supports VECTOR operations.
	 *
	 * @return bool True if MariaDB 11.7 or higher.
	 */
	public static function is_vector_supported(): bool {
		global $wpdb;
		$version = $wpdb->get_var( 'SELECT VERSION()' );

		if ( ! is_string( $version ) ) {
			return false;
		}

		// Must be MariaDB (not MySQL).
		if ( stripos( $version, 'MariaDB' ) === false ) {
			return false;
		}

		// Extract "11.7.x" or similar.
		if ( ! preg_match( '/(\d+\.\d+\.\d+)/', $version, $matches ) ) {
			return false;
		}

		return version_compare( $matches[1], '11.7.0', '>=' );
	}
}
