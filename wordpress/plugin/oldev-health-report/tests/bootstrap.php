<?php
/**
 * PHPUnit bootstrap: real WordPress, real MariaDB.
 *
 * @package OldevHealthReport
 */

$tests_dir = dirname( __DIR__ ) . '/vendor/wp-phpunit/wp-phpunit';

putenv( 'WP_TESTS_DIR=' . $tests_dir );
putenv( 'WP_PHPUNIT__TESTS_CONFIG=' . __DIR__ . '/wp-tests-config.php' );
define( 'WP_TESTS_PHPUNIT_POLYFILLS_PATH', dirname( __DIR__ ) . '/vendor/yoast/phpunit-polyfills' );

require_once dirname( __DIR__ ) . '/vendor/yoast/phpunit-polyfills/phpunitpolyfills-autoload.php';
require_once $tests_dir . '/includes/functions.php';

tests_add_filter(
	'muplugins_loaded',
	static function (): void {
		require dirname( __DIR__ ) . '/oldev-health-report.php';
	}
);
tests_add_filter(
	'setup_theme',
	static function (): void {
		\Oldev\HealthReport\Data\Schema::install();
	}
);

require $tests_dir . '/includes/bootstrap.php';
require __DIR__ . '/TestCase.php';
