<?php
/**
 * Blocks, assets and page-level headers.
 *
 * @package OldevHealthReport
 */

namespace Oldev\HealthReport\Frontend;

defined( 'ABSPATH' ) || exit;

/**
 * Registers the dynamic blocks, the report route and response headers.
 */
final class Blocks {

	/**
	 * Registers blocks and assets.
	 *
	 * @return void
	 */
	public static function register(): void {
		wp_register_style( 'shr-app', OLDEV_HR_URL . 'assets/css/app.css', array(), OLDEV_HR_VERSION );
		wp_register_script(
			'shr-editor',
			OLDEV_HR_URL . 'assets/js/editor.js',
			array( 'wp-blocks', 'wp-element', 'wp-block-editor', 'wp-server-side-render' ),
			OLDEV_HR_VERSION,
			true
		);
		foreach ( array( 'scan-form', 'report', 'dashboard' ) as $name ) {
			wp_register_script(
				'shr-' . $name,
				OLDEV_HR_URL . 'assets/js/' . $name . '.js',
				array(),
				OLDEV_HR_VERSION,
				array(
					'in_footer' => true,
					'strategy'  => 'defer',
				)
			);
			register_block_type( OLDEV_HR_DIR . 'blocks/' . $name );
		}
	}

	/**
	 * Adds the report route.
	 *
	 * @return void
	 */
	public static function add_rewrites(): void {
		add_rewrite_rule(
			'^report/([0-9a-f-]{36})/([A-Za-z0-9_-]{43})/?$',
			'index.php?pagename=report&shr_scan=$matches[1]&shr_sig=$matches[2]',
			'top'
		);
	}

	/**
	 * Registers query variables.
	 *
	 * @param string[] $vars Query variables.
	 * @return string[]
	 */
	public static function query_vars( array $vars ): array {
		$vars[] = 'shr_scan';
		$vars[] = 'shr_sig';
		return $vars;
	}

	/**
	 * Whether the current request shows private data.
	 *
	 * @return bool
	 */
	private static function is_private_page(): bool {
		return is_page( array( 'report', 'dashboard' ) );
	}

	/**
	 * Sends security headers. Capability URLs must not leak through Referer or caches.
	 *
	 * @return void
	 */
	public static function send_headers(): void {
		if ( is_admin() || wp_doing_ajax() || is_user_logged_in() ) {
			return;
		}
		header( "Content-Security-Policy: default-src 'self'; script-src 'self' https://challenges.cloudflare.com; style-src 'self' 'unsafe-inline'; img-src 'self' data:; font-src 'self'; connect-src 'self'; frame-src https://challenges.cloudflare.com; frame-ancestors 'self'; base-uri 'self'; form-action 'self'; object-src 'none'" );
		header( 'X-Content-Type-Options: nosniff' );
		header( 'X-Frame-Options: SAMEORIGIN' );
		header( 'Permissions-Policy: camera=(), microphone=(), geolocation=(), payment=()' );
		if ( is_ssl() ) {
			header( 'Strict-Transport-Security: max-age=31536000; includeSubDomains' );
		}

		if ( self::is_private_page() ) {
			header( 'Referrer-Policy: no-referrer' );
			header( 'X-Robots-Tag: noindex, nofollow' );
			header( 'Cache-Control: private, no-store' );
		} else {
			header( 'Referrer-Policy: strict-origin-when-cross-origin' );
		}
	}

	/**
	 * Keeps private pages out of search.
	 *
	 * @param array<string,bool|string> $robots Robots directives.
	 * @return array<string,bool|string>
	 */
	public static function robots( array $robots ): array {
		if ( self::is_private_page() ) {
			$robots['noindex']  = true;
			$robots['nofollow'] = true;
		}
		return $robots;
	}
}
