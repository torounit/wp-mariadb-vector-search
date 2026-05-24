<?php
/**
 * PHPUnit bootstrap file.
 *
 * @package WP_MariaDB_Vector_Search
 */

declare(strict_types=1);

// Load Composer dependencies.
require_once dirname( __DIR__, 2 ) . '/vendor/autoload.php';

$_tests_dir = getenv( 'WP_TESTS_DIR' );

if ( ! $_tests_dir ) {
	$_try_tests_dir = rtrim( sys_get_temp_dir(), '/\\' ) . '/wordpress-tests-lib';
	if ( file_exists( $_try_tests_dir . '/includes/functions.php' ) ) {
		$_tests_dir = $_try_tests_dir;
	}
	unset( $_try_tests_dir );
}

if ( ! $_tests_dir ) {
	$_tests_dir = getenv( 'WP_PHPUNIT__DIR' );
}

if ( ! $_tests_dir ) {
	$_try_tests_dir = __DIR__ . '/../../../../../tests/phpunit';
	if ( file_exists( $_try_tests_dir . '/includes/functions.php' ) ) {
		$_tests_dir = $_try_tests_dir;
	}
}

if ( ! $_tests_dir ) {
	$_tests_dir = '/tmp/wordpress-tests-lib';
}

if ( ! file_exists( $_tests_dir . '/includes/functions.php' ) ) {
	echo "Could not find $_tests_dir/includes/functions.php, have you run bin/install-wp-tests.sh ?" . PHP_EOL; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
	exit( 1 );
}

require_once $_tests_dir . '/includes/functions.php';

tests_add_filter(
	'muplugins_loaded',
	static function (): void {
		require dirname( __DIR__, 2 ) . '/wp-mariadb-vector-search.php';
	}
);

require $_tests_dir . '/includes/bootstrap.php';
