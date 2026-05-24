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


	/**
	 * Initialize plugin hooks.
	 *
	 * @return void
	 */
	public function init(): void {
		add_action( 'init', array( $this, 'register_hooks' ) );
	}

	/**
	 * Register runtime hooks.
	 *
	 * @return void
	 */
	public function register_hooks(): void {
		// Add runtime hooks here.
	}

	/**
	 * Plugin activation.
	 *
	 * @return void
	 */
	public static function activate(): void {}

	/**
	 * Plugin deactivation.
	 *
	 * @return void
	 */
	public static function deactivate(): void {}
}
