<?php
/**
 * Admin Tools page — mounts the React admin app.
 *
 * @package WP_MariaDB_Vector_Search
 */

declare(strict_types=1);

namespace WP_MariaDB_Vector_Search;

/**
 * Registers the Tools > Vector Search admin page.
 *
 * Script enqueuing is handled automatically by the auto-generated
 * build/pages/wp-mariadb-vector-search/page-wp-admin.php loaded via build/build.php.
 */
class Admin {

	const PAGE_SLUG    = 'wp-mariadb-vector-search-wp-admin';
	const SETTINGS_KEY = 'wp_mariadb_vector_search_settings';

	/**
	 * Register admin hooks.
	 *
	 * @return void
	 */
	public function register_hooks(): void {
		add_action( 'admin_menu', array( $this, 'add_menu' ) );
	}

	/**
	 * Register the Tools submenu page.
	 *
	 * @return void
	 */
	public function add_menu(): void {
		add_management_page(
			__( 'Vector Search', 'wp-mariadb-vector-search' ),
			__( 'Vector Search', 'wp-mariadb-vector-search' ),
			'manage_options',
			self::PAGE_SLUG,
			array( $this, 'render_page' )
		);
	}

	/**
	 * Render the admin page mount point.
	 *
	 * The React app is initialized by the boot module loaded via
	 * build/pages/wp-mariadb-vector-search/page-wp-admin.php.
	 * If the boot module asset is missing, a notice is shown instead.
	 *
	 * @return void
	 */
	public function render_page(): void {
		$boot_asset = WP_MARIADB_VECTOR_SEARCH_PLUGIN_DIR . 'build/modules/boot/index.min.asset.php';

		if ( ! file_exists( $boot_asset ) ) {
			echo '<div class="wrap"><div class="notice notice-warning inline"><p>';
			echo esc_html__(
				'WP MariaDB Vector Search: boot module not found. Run `npm run build` or ensure WordPress is up to date.',
				'wp-mariadb-vector-search'
			);
			echo '</p></div></div>';
			return;
		}
		wp_mariadb_vector_search_wp_mariadb_vector_search_wp_admin_render_page();
	}
}
