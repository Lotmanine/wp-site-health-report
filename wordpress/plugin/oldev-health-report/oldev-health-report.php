<?php
/**
 * Plugin Name:       Website Health Report
 * Description:       Scored website health reports from passive checks, with a magic-link dashboard.
 * Version:           1.1.0
 * Requires at least: 7.1
 * Requires PHP:      8.3
 * Author:            OLDEV
 * Author URI:        https://oldev.site
 * License:           GPL-2.0-or-later
 * Text Domain:       oldev-health-report
 *
 * @package OldevHealthReport
 */

defined( 'ABSPATH' ) || exit;

define( 'OLDEV_HR_VERSION', '1.1.0' );
define( 'OLDEV_HR_FILE', __FILE__ );
define( 'OLDEV_HR_DIR', plugin_dir_path( __FILE__ ) );
define( 'OLDEV_HR_URL', plugin_dir_url( __FILE__ ) );

require_once OLDEV_HR_DIR . 'vendor/autoload.php';
// Action Scheduler has to load before plugins_loaded so it can pick the newest bundled copy.
require_once OLDEV_HR_DIR . 'vendor/woocommerce/action-scheduler/action-scheduler.php';

register_activation_hook( __FILE__, array( \Oldev\HealthReport\Installer::class, 'activate' ) );

\Oldev\HealthReport\Plugin::instance()->boot();
