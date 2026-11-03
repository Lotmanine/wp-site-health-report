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

/**
 * The one description of the tool, used by the meta description, the share cards and the structured data.
 *
 * @return string
 */
function oldev_health_description(): string {
	return __( 'Check a website for free: security headers, TLS certificate, search basics, email authentication and performance, with a ranked list of fixes.', 'oldev-health' );
}

add_action(
	'wp_head',
	static function (): void {
		if ( ! is_front_page() ) {
			return;
		}
		printf(
			'<meta name="description" content="%s">' . "\n" . '<link rel="canonical" href="%s">' . "\n",
			esc_attr( oldev_health_description() ),
			esc_url( home_url( '/' ) )
		);
	},
	1
);

// OpenGraph and Twitter cards, so a shared link shows the landing page. Reports and the dashboard get only
// the generic card: their canonical link is the bare page, never the signed URL.
add_action(
	'wp_head',
	static function (): void {
		$url   = is_front_page() ? home_url( '/' ) : wp_get_canonical_url();
		$title = wp_get_document_title();
		$image = get_theme_file_uri( 'assets/images/og-image.png' );
		$alt   = __( 'The Website Health Report landing page with the website address form.', 'oldev-health' );

		echo '<meta property="og:type" content="website">' . "\n";
		printf( '<meta property="og:site_name" content="%s">' . "\n", esc_attr( get_bloginfo( 'name' ) ) );
		printf( '<meta property="og:title" content="%s">' . "\n", esc_attr( $title ) );
		printf( '<meta property="og:description" content="%s">' . "\n", esc_attr( oldev_health_description() ) );
		if ( $url ) {
			printf( '<meta property="og:url" content="%s">' . "\n", esc_url( $url ) );
		}
		printf( '<meta property="og:image" content="%s">' . "\n", esc_url( $image ) );
		echo '<meta property="og:image:width" content="1200">' . "\n" . '<meta property="og:image:height" content="630">' . "\n";
		printf( '<meta property="og:image:alt" content="%s">' . "\n", esc_attr( $alt ) );
		echo '<meta name="twitter:card" content="summary_large_image">' . "\n";
		printf( '<meta name="twitter:title" content="%s">' . "\n", esc_attr( $title ) );
		printf( '<meta name="twitter:description" content="%s">' . "\n", esc_attr( oldev_health_description() ) );
		printf( '<meta name="twitter:image" content="%s">' . "\n", esc_url( $image ) );
		printf( '<meta name="twitter:image:alt" content="%s">' . "\n", esc_attr( $alt ) );
	},
	1
);

// Structured data for the home page: what the tool is and who built it.
add_action(
	'wp_head',
	static function (): void {
		if ( ! is_front_page() ) {
			return;
		}
		$data = array(
			'@context'            => 'https://schema.org',
			'@type'               => 'WebApplication',
			'name'                => get_bloginfo( 'name' ),
			'url'                 => home_url( '/' ),
			'description'         => oldev_health_description(),
			'applicationCategory' => 'UtilitiesApplication',
			'operatingSystem'     => 'Web',
			'creator'             => array(
				'@type'  => 'Person',
				'name'   => 'Lotfi Otmanine',
				'url'    => 'https://oldev.site',
				'sameAs' => array(
					'https://github.com/Lotmanine',
					'https://www.linkedin.com/in/lotfi-otmanine',
					'https://www.upwork.com/freelancers/~01bd748fcb665b90ca',
				),
			),
		);
		wp_print_inline_script_tag(
			(string) wp_json_encode( $data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ),
			array( 'type' => 'application/ld+json' )
		);
	},
	1
);
