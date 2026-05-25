<?php
/**
 * Main plugin class.
 *
 * @package WP_MariaDB_Vector_Search
 */

declare(strict_types=1);

namespace WP_MariaDB_Vector_Search;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Bootstrap plugin lifecycle and hooks.
 */
class Plugin {

	const DEFAULT_DIMENSIONS = 1536;

	/**
	 * Initialize plugin hooks.
	 *
	 * @return void
	 */
	public function init(): void {
		$this->register_hooks();
	}

	/**
	 * Register runtime hooks.
	 *
	 * @return void
	 */
	public function register_hooks(): void {
		if ( ! Schema::is_vector_supported() ) {
			add_action( 'admin_notices', array( $this, 'notice_no_vector' ) );
			return;
		}

		$repository = new Repository();
		$client     = new Embedding_Client();
		$indexer    = new Indexer( $client, $repository );
		$backfill   = new Cron_Backfill( $indexer );
		$search     = new Search( $client, $repository );
		$admin      = new Admin( $backfill, $repository );

		// Index posts on save / delete.
		add_action( 'save_post', array( $indexer, 'index_post' ) );
		add_action( 'delete_post', array( $indexer, 'delete_post' ) );
		add_action( 'trashed_post', array( $indexer, 'delete_post' ) );

		$backfill->register_hooks();
		$search->register_hooks();
		$admin->register_hooks();

		if ( defined( 'WP_CLI' ) && \WP_CLI ) {
			\WP_CLI::add_command( 'mariadb-vector', new CLI( $indexer ) );
		}
	}

	/**
	 * Show an admin notice when the database does not support VECTOR.
	 *
	 * @return void
	 */
	public function notice_no_vector(): void {
		echo '<div class="notice notice-error"><p>';
		echo esc_html__( 'WP MariaDB Vector Search requires MariaDB 11.7 or higher with VECTOR support.', 'wp-mariadb-vector-search' );
		echo '</p></div>';
	}

	/**
	 * Plugin activation: create / upgrade the embeddings table.
	 *
	 * @return void
	 */
	public static function activate(): void {
		if ( ! Schema::is_vector_supported() ) {
			return;
		}

		$settings   = get_option( 'wp_mariadb_vector_search_settings', array() );
		$dimensions = isset( $settings['dimensions'] ) ? (int) $settings['dimensions'] : self::DEFAULT_DIMENSIONS;
		Schema::install( $dimensions );
	}

	/**
	 * Plugin deactivation: clear pending cron events.
	 *
	 * @return void
	 */
	public static function deactivate(): void {
		$timestamp = wp_next_scheduled( Cron_Backfill::CRON_HOOK );
		if ( $timestamp ) {
			wp_unschedule_event( $timestamp, Cron_Backfill::CRON_HOOK );
		}
	}
}
