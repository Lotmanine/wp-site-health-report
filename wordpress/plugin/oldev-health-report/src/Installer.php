<?php
/**
 * Activation.
 *
 * @package OldevHealthReport
 */

namespace Oldev\HealthReport;

use Oldev\HealthReport\Data\Schema;
use Oldev\HealthReport\Frontend\Blocks;

defined( 'ABSPATH' ) || exit;

/**
 * Creates tables, pages and rewrite rules.
 */
final class Installer {

	/**
	 * Runs on activation.
	 *
	 * @return void
	 */
	public static function activate(): void {
		Schema::install();
		self::create_pages();
		Blocks::add_rewrites();
		flush_rewrite_rules();
	}

	/**
	 * Creates the plugin pages once. Existing pages are left alone.
	 *
	 * @return void
	 */
	private static function create_pages(): void {
		$pages = array(
			'report'    => array( __( 'Your report', 'oldev-health-report' ), '<!-- wp:shr/report /-->' ),
			'dashboard' => array( __( 'Dashboard', 'oldev-health-report' ), '<!-- wp:shr/dashboard /-->' ),
			'privacy'   => array( __( 'Privacy', 'oldev-health-report' ), self::privacy_content() ),
		);

		foreach ( $pages as $slug => $page ) {
			if ( get_page_by_path( $slug ) ) {
				continue;
			}
			wp_insert_post(
				array(
					'post_type'    => 'page',
					'post_status'  => 'publish',
					'post_name'    => $slug,
					'post_title'   => $page[0],
					'post_content' => $page[1],
				)
			);
		}
	}

	/**
	 * Privacy page content.
	 *
	 * @return string
	 */
	private static function privacy_content(): string {
		$sections = array(
			array( 'h2', 'What this page covers' ),
			array( 'p', 'This page explains what Website Health Report collects when you scan a website, why, and how to delete it. OLDEV, the WordPress practice of Lotfi Otmanine, runs this service. Contact: lotfi@oldev.site.' ),
			array( 'h2', 'What is collected' ),
			array( 'ul', array( 'The website address you enter and the results of the checks.', 'Your confirmation that you own the site or may test it, with the time you gave it.', 'A keyed hash of your IP address, used for rate limits and abuse prevention. The IP address itself is not stored.', 'Your email address, only if you enter one, with the consents you chose.' ) ),
			array( 'h2', 'Why it is used' ),
			array( 'ul', array( 'To run the scan and show your report.', 'To email the PDF report, only if you ask for it.', 'To let you sign in to your dashboard with a one-time link.', 'To send occasional messages about website health, only if you tick that box.', 'To stop abuse of the service.' ) ),
			array( 'h2', 'Services involved' ),
			array( 'ul', array( 'Cloudflare Turnstile checks that the request comes from a person.', 'Google PageSpeed Insights measures performance for the scanned address.', 'Public DNS-over-HTTPS resolvers answer the SPF, DKIM and DMARC lookups.', 'Brevo delivers email.', 'Anthropic writes the summary when the AI summary is enabled. It receives scores and check results for the scanned domain, not your email address.' ) ),
			array( 'h2', 'How long it is kept' ),
			array( 'p', 'Reports, findings and the abuse log are deleted after 30 days by an automated job. An email address with no remaining reports is deleted at the same time, including its contact record.' ),
			array( 'h2', 'How to delete your data' ),
			array( 'p', 'Every report has a button that deletes that report at once. If you left an email address, sign in on the Dashboard page and choose Delete my data to erase your reports, your address and your contact record. You can also email lotfi@oldev.site.' ),
			array( 'h2', 'What the scan does not do' ),
			array( 'p', 'Scans are passive. They read the public home page, robots.txt, the sitemap, the certificate and public DNS records. They do not scan ports, test passwords or attempt to exploit anything.' ),
		);

		$content = '';
		foreach ( $sections as $section ) {
			if ( 'h2' === $section[0] ) {
				$content .= "<!-- wp:heading -->\n<h2 class=\"wp-block-heading\">" . esc_html( $section[1] ) . "</h2>\n<!-- /wp:heading -->\n\n";
			} elseif ( 'p' === $section[0] ) {
				$content .= "<!-- wp:paragraph -->\n<p>" . esc_html( $section[1] ) . "</p>\n<!-- /wp:paragraph -->\n\n";
			} else {
				$items = '';
				foreach ( $section[1] as $item ) {
					$items .= '<!-- wp:list-item --><li>' . esc_html( $item ) . '</li><!-- /wp:list-item -->';
				}
				$content .= "<!-- wp:list -->\n<ul class=\"wp-block-list\">" . $items . "</ul>\n<!-- /wp:list -->\n\n";
			}
		}
		return $content;
	}
}
