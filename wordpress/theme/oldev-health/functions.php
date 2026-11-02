<?php
/**
 * Theme setup.
 *
 * @package OldevHealthTheme
 */

defined( 'ABSPATH' ) || exit;

// The site CSP allows no inline scripts, so the inline emoji detector and speculation rules stay off.
remove_action( 'wp_head', 'print_emoji_detection_script', 7 );
remove_action( 'wp_print_styles', 'print_emoji_styles' );
add_filter( 'wp_speculation_rules_configuration', '__return_null' );

add_action(
	'wp_head',
	static function (): void {
		if ( ! is_front_page() ) {
			return;
		}
		printf(
			'<meta name="description" content="%s">' . "\n" . '<link rel="canonical" href="%s">' . "\n",
			esc_attr__( 'Check a website for free: security headers, TLS certificate, search basics, email authentication and performance, with a ranked list of fixes.', 'oldev-health' ),
			esc_url( home_url( '/' ) )
		);
	},
	1
);
