<?php
/**
 * WordPress test configuration.
 *
 * @package OldevHealthReport
 */

define( 'ABSPATH', '/opt/wordpress/' );
define( 'WP_DEFAULT_THEME', 'default' );
define( 'WP_TESTS_DOMAIN', 'example.org' );
define( 'WP_TESTS_EMAIL', 'admin@example.org' );
define( 'WP_TESTS_TITLE', 'Test Blog' );
define( 'WP_PHP_BINARY', 'php' );
define( 'WPLANG', '' );

define( 'DB_NAME', getenv( 'WP_TESTS_DB_NAME' ) ?: 'wp_tests' );
define( 'DB_USER', getenv( 'WP_TESTS_DB_USER' ) ?: 'wp_tests' );
define( 'DB_PASSWORD', getenv( 'WP_TESTS_DB_PASSWORD' ) ?: 'wp_tests' );
define( 'DB_HOST', getenv( 'WP_TESTS_DB_HOST' ) ?: 'testdb' );
define( 'DB_CHARSET', 'utf8mb4' );
define( 'DB_COLLATE', '' );

$table_prefix = 'wptests_';
