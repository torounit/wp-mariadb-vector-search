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

require_once __DIR__ . '/vendor/autoload.php';

WP_MariaDB_Vector_Search\Schema::drop();

delete_option( 'wp_mariadb_vector_search_db_version' );
delete_option( 'wp_mariadb_vector_search_settings' );
delete_transient( WP_MariaDB_Vector_Search\Cron_Backfill::PROGRESS_KEY );

$timestamp = wp_next_scheduled( WP_MariaDB_Vector_Search\Cron_Backfill::CRON_HOOK );
if ( $timestamp ) {
	wp_unschedule_event( $timestamp, WP_MariaDB_Vector_Search\Cron_Backfill::CRON_HOOK );
}
