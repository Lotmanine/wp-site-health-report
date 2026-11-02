<?php
/**
 * Wordfence firewall loader, set as auto_prepend_file in Apache so the firewall runs before WordPress.
 * It lives outside the web root and does nothing until the plugin is installed.
 */

if ( file_exists( '/var/www/html/wp-content/plugins/wordfence/waf/bootstrap.php' ) ) {
	define( 'WFWAF_LOG_PATH', '/var/www/html/wp-content/wflogs/' );
	include_once '/var/www/html/wp-content/plugins/wordfence/waf/bootstrap.php';
}
