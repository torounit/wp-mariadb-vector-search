<?php
/**
 * Integration tests for Schema class.
 *
 * Requires MariaDB 11.7+ (VECTOR type). Tests are skipped on MySQL or
 * older MariaDB versions.
 *
 * @package WP_MariaDB_Vector_Search
 */

declare(strict_types=1);

namespace WP_MariaDB_Vector_Search\Tests\Integration;

use WP_MariaDB_Vector_Search\Schema;

/**
 * Class Schema_Test
 */
class Schema_Test extends \WP_UnitTestCase {

	private string $table;

	public function set_up(): void {
		parent::set_up();
		global $wpdb;
		$this->table = $wpdb->prefix . 'mariadb_vector_embeddings';

		if ( ! Schema::is_vector_supported() ) {
			$this->markTestSkipped( 'MariaDB 11.7+ with VECTOR support is required.' );
		}

		// WP test framework converts CREATE/DROP TABLE to TEMPORARY TABLE,
		// which MariaDB disallows for VECTOR INDEX. Remove filters for this
		// test class and restore them in tear_down().
		remove_filter( 'query', array( $this, '_create_temporary_tables' ) );
		remove_filter( 'query', array( $this, '_drop_temporary_tables' ) );

		// Clean slate.
		Schema::drop();
		delete_option( 'wp_mariadb_vector_search_db_version' );
	}

	public function tear_down(): void {
		Schema::drop();
		delete_option( 'wp_mariadb_vector_search_db_version' );

		// Restore WP test-framework filters.
		add_filter( 'query', array( $this, '_create_temporary_tables' ) );
		add_filter( 'query', array( $this, '_drop_temporary_tables' ) );

		parent::tear_down();
	}

	public function test_install_creates_table(): void {
		global $wpdb;
		Schema::install( 4 );

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$result = $wpdb->get_var( "SHOW TABLES LIKE '{$this->table}'" );
		$this->assertSame( $this->table, $result );
	}

	public function test_install_creates_vector_column(): void {
		global $wpdb;
		Schema::install( 4 );

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$create = $wpdb->get_var( "SHOW CREATE TABLE `{$this->table}`", 1 );
		// MariaDB stores the column type in lowercase.
		$this->assertMatchesRegularExpression( '/VECTOR\(4\)/i', $create );
	}

	public function test_install_creates_cosine_vector_index(): void {
		global $wpdb;
		Schema::install( 4 );

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$create = $wpdb->get_var( "SHOW CREATE TABLE `{$this->table}`", 1 );
		// MariaDB outputs "VECTOR KEY" rather than "VECTOR INDEX" in SHOW CREATE TABLE.
		$this->assertMatchesRegularExpression( '/VECTOR (KEY|INDEX)/i', $create );
		$this->assertStringContainsStringIgnoringCase( 'cosine', $create );
	}

	public function test_install_stores_db_version(): void {
		Schema::install( 4 );
		$this->assertSame( Schema::DB_VERSION, get_option( 'wp_mariadb_vector_search_db_version' ) );
	}

	public function test_install_is_idempotent(): void {
		global $wpdb;
		Schema::install( 4 );
		Schema::install( 4 );

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$count = $wpdb->get_var( "SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = '{$this->table}'" );
		$this->assertSame( '1', $count );
		$this->assertSame( 0, $wpdb->last_error ? 1 : 0 );
	}

	public function test_drop_removes_table(): void {
		global $wpdb;
		Schema::install( 4 );
		Schema::drop();

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$result = $wpdb->get_var( "SHOW TABLES LIKE '{$this->table}'" );
		$this->assertNull( $result );
	}

	public function test_is_vector_supported_returns_bool(): void {
		$this->assertIsBool( Schema::is_vector_supported() );
	}
}
