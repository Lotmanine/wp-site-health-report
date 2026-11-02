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

// Same separator as oldev.site titles. The core default is an en dash.
add_filter(
	'document_title_separator',
	static function (): string {
		return '|';
	}
);

add_action(
	'after_setup_theme',
	static function (): void {
		add_theme_support( 'editor-styles' );
		add_editor_style( 'assets/css/theme.css' );
	}
);

add_action(
	'wp_enqueue_scripts',
	static function (): void {
		$version = (string) wp_get_theme()->get( 'Version' );
		wp_enqueue_style( 'oldev-health-theme', get_theme_file_uri( 'assets/css/theme.css' ), array(), $version );
		// Loaded in the head without defer: it sets the theme class before first paint, and the CSP rules out an inline snippet.
		wp_enqueue_script( 'oldev-health-theme', get_theme_file_uri( 'assets/js/theme.js' ), array(), $version, false );
	}
);

// The OLDEV mark from oldev.site, unless a site icon is set in the Customizer.
add_action(
	'wp_head',
	static function (): void {
		if ( has_site_icon() ) {
			return;
		}
		printf(
			'<link rel="icon" href="%s" type="image/svg+xml">' . "\n" . '<link rel="icon" href="%s" sizes="32x32" type="image/png">' . "\n" . '<link rel="apple-touch-icon" href="%s">' . "\n",
			esc_url( get_theme_file_uri( 'assets/images/oldev-logo.svg' ) ),
			esc_url( get_theme_file_uri( 'assets/images/favicon-32.png' ) ),
			esc_url( get_theme_file_uri( 'assets/images/apple-touch-icon.png' ) )
		);
	},
	2
);

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
