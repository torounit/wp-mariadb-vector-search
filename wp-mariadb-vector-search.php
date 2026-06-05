<?php
/**
 * Plugin Name:       WP MariaDB Vector Search
 * Plugin URI:        https://github.com/torounit/wp-mariadb-vector-search
 * Description:       Base scaffold for a MariaDB vector search WordPress plugin.
 * Version:           0.1.0
 * Requires at least: 7.0
 * Requires PHP:      8.2
 * Author:            Toro_Unit
 * Author URI:        https://torounit.com
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       wp-mariadb-vector-search
 *
 * @package WP_MariaDB_Vector_Search
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'WP_MARIADB_VECTOR_SEARCH_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'WP_MARIADB_VECTOR_SEARCH_PLUGIN_URL', plugin_dir_url( __FILE__ ) );

if ( file_exists( __DIR__ . '/vendor/autoload.php' ) ) {
	require_once __DIR__ . '/vendor/autoload.php';
}

/**
 * Create an Embedding_Prompt_Builder for building embedding requests.
 *
 * @param string|null $prompt Optional initial prompt text.
 * @return WP_MariaDB_Vector_Search\Embedding_Prompt_Builder
 */
function wp_mariadb_vector_search_prompt( ?string $prompt = null ): WP_MariaDB_Vector_Search\Embedding_Prompt_Builder {
	return new WP_MariaDB_Vector_Search\Embedding_Prompt_Builder( $prompt );
}


register_activation_hook( __FILE__, array( 'WP_MariaDB_Vector_Search\\Plugin', 'activate' ) );
register_deactivation_hook( __FILE__, array( 'WP_MariaDB_Vector_Search\\Plugin', 'deactivate' ) );

add_action(
	'plugins_loaded',
	static function (): void {
		( new WP_MariaDB_Vector_Search\Plugin() )->init();
	}
);
