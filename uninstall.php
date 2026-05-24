<?php
/**
 * Uninstall WP MariaDB Vector Search.
 *
 * @package WP_MariaDB_Vector_Search
 */

declare(strict_types=1);

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

delete_option( 'wp_mariadb_vector_search_settings' );
