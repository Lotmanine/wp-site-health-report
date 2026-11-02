<?php
/**
 * Wordfence settings, applied with `wp eval-file` on every boot so they survive redeploys.
 * Two-factor login stays available but is not forced: enrolling is a step for the site owner.
 */

if ( ! class_exists( 'wfConfig' ) ) {
	WP_CLI::warning( 'Wordfence is not loaded, settings skipped.' );
	return;
}

$settings = array(
	'firewallEnabled'                => 1,
	'loginSecurityEnabled'           => 1,
	// Five failures inside four hours lock the address out for four hours.
	'loginSec_maxFailures'           => 5,
	'loginSec_maxForgotPasswd'       => 5,
	'loginSec_countFailMins'         => 240,
	'loginSec_lockoutMins'           => 240,
	'loginSec_lockInvalidUsers'      => 1,
	'loginSec_userBlacklist'         => "admin\nadministrator\nroot\nwordpress",
	'loginSec_maskLoginErrors'       => 1,
	'loginSec_disableAuthorScan'     => 1,
	'loginSec_disableOEmbedAuthor'   => 1,
	'loginSec_breachPasswds_enabled' => 1,
	'other_hideWPVersion'            => 1,
	'other_blockBadPOST'             => 1,
	'disableCodeExecutionUploads'    => 1,
	// Rate limits. The n8n callbacks come from this server and are allowlisted below.
	'maxGlobalRequests'              => 480,
	'maxGlobalRequests_action'       => 'throttle',
	'maxRequestsCrawlers'            => 120,
	'maxRequestsCrawlers_action'     => 'throttle',
	'maxRequestsHumans'              => 240,
	'maxRequestsHumans_action'       => 'throttle',
	'max404Crawlers'                 => 60,
	'max404Crawlers_action'          => 'throttle',
	'max404Humans'                   => 60,
	'max404Humans_action'            => 'throttle',
	'blockedTime'                    => 300,
	// Traefik sits in front and appends the visitor address to X-Forwarded-For.
	'howGetIPs'                      => 'HTTP_X_FORWARDED_FOR',
	'howGetIPs_trusted_proxies'      => "10.0.0.0/8\n172.16.0.0/12\n192.168.0.0/16",
	'whitelisted'                    => '76.13.63.48',
	'scheduledScansEnabled'          => 1,
	'alertEmails'                    => (string) getenv( 'WP_ADMIN_EMAIL' ),
);

foreach ( $settings as $key => $value ) {
	wfConfig::set( $key, $value );
}

// Leave learning mode at once: this site has no traffic to learn from yet. The firewall's file storage
// refuses writes from the CLI unless told otherwise, and saves on shutdown.
if ( ! defined( 'WFWAF_ALWAYS_ALLOW_FILE_WRITING' ) ) {
	define( 'WFWAF_ALWAYS_ALLOW_FILE_WRITING', true );
}
if ( class_exists( 'wfWAF' ) && wfWAF::getInstance() ) {
	$storage = wfWAF::getInstance()->getStorageEngine();
	$storage->setConfig( 'wafStatus', 'enabled' );
	$storage->setConfig( 'learningModeGracePeriodEnabled', 0 );
	$storage->unsetConfig( 'learningModeGracePeriod' );
}

WP_CLI::success( 'Wordfence settings applied.' );
