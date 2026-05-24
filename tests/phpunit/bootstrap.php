<?php
/**
 * PHPUnit bootstrap.
 *
 * For unit tests: requires only the Composer autoloader.
 * For integration tests: also loads WordPress via wp-phpunit when the
 * WP_PHPUNIT__TESTS_CONFIG env variable points to a valid config file
 * (set automatically by wp-env / wp-phpunit).
 *
 * @package WP_MariaDB_Vector_Search
 */

declare(strict_types=1);

require_once dirname( __DIR__, 2 ) . '/vendor/autoload.php';

$wp_phpunit_bootstrap = dirname( __DIR__, 2 ) . '/vendor/wp-phpunit/wp-phpunit/includes/bootstrap.php';

if (
	getenv( 'WP_PHPUNIT__TESTS_CONFIG' ) &&
	file_exists( $wp_phpunit_bootstrap )
) {
	require_once $wp_phpunit_bootstrap;
}
