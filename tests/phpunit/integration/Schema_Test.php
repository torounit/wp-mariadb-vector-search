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

	/**
	 * Embeddings table name.
	 *
	 * @var string
	 */
	private string $table;

	/**
	 * Set up test fixtures.
	 */
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

	/**
	 * Tear down test fixtures.
	 */
	public function tear_down(): void {
		Schema::drop();
		delete_option( 'wp_mariadb_vector_search_db_version' );

		// Restore WP test-framework filters.
		add_filter( 'query', array( $this, '_create_temporary_tables' ) );
		add_filter( 'query', array( $this, '_drop_temporary_tables' ) );

		parent::tear_down();
	}

	/** Table exists after install. */
	public function test_install_creates_table(): void {
		global $wpdb;
		Schema::install( 4 );

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$result = $wpdb->get_var( "SHOW TABLES LIKE '{$this->table}'" );
		$this->assertSame( $this->table, $result );
	}

	/** VECTOR column with correct dimension is created. */
	public function test_install_creates_vector_column(): void {
		global $wpdb;
		Schema::install( 4 );

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.DirectDatabaseQuery.SchemaChange
		$create = $wpdb->get_var( "SHOW CREATE TABLE `{$this->table}`", 1 );
		// MariaDB stores the column type in lowercase.
		$this->assertMatchesRegularExpression( '/VECTOR\(4\)/i', $create );
	}

	/** Cosine vector index is created. */
	public function test_install_creates_cosine_vector_index(): void {
		global $wpdb;
		Schema::install( 4 );

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.DirectDatabaseQuery.SchemaChange
		$create = $wpdb->get_var( "SHOW CREATE TABLE `{$this->table}`", 1 );
		// MariaDB outputs "VECTOR KEY" rather than "VECTOR INDEX" in SHOW CREATE TABLE.
		$this->assertMatchesRegularExpression( '/VECTOR (KEY|INDEX)/i', $create );
		$this->assertStringContainsStringIgnoringCase( 'cosine', $create );
	}

	/** DB version option is set after install. */
	public function test_install_stores_db_version(): void {
		Schema::install( 4 );
		$this->assertSame( Schema::DB_VERSION, get_option( 'wp_mariadb_vector_search_db_version' ) );
	}

	/** Calling install twice does not error or duplicate the table. */
	public function test_install_is_idempotent(): void {
		global $wpdb;
		Schema::install( 4 );
		Schema::install( 4 );

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$count = $wpdb->get_var( "SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = '{$this->table}'" );
		$this->assertSame( '1', $count );
		$this->assertSame( 0, $wpdb->last_error ? 1 : 0 );
	}

	/** Table is gone after drop. */
	public function test_drop_removes_table(): void {
		global $wpdb;
		Schema::install( 4 );
		Schema::drop();

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$result = $wpdb->get_var( "SHOW TABLES LIKE '{$this->table}'" );
		$this->assertNull( $result );
	}

	/** Returns bool indicating VECTOR support. */
	public function test_is_vector_supported_returns_bool(): void {
		$this->assertIsBool( Schema::is_vector_supported() );
	}
}
