<?php
/**
 * Turns raw check data into findings.
 *
 * @package OldevHealthReport
 */

namespace Oldev\HealthReport\Scoring;

defined( 'ABSPATH' ) || exit;

/**
 * Pure rules. Page text from the scanner only ever lands in `detail`, which is escaped at output.
 */
final class Evaluator {

	public const CATEGORIES = array(
		'security'    => 30,
		'seo'         => 20,
		'tls'         => 15,
		'email'       => 15,
		'performance' => 15,
		'wordpress'   => 5,
	);

	/**
	 * Evaluates a verified scan result.
	 *
	 * @param array<string,mixed> $message Result message.
	 * @return array<int,array<string,mixed>>
	 */
	public static function evaluate( array $message ): array {
		$scanner = is_array( $message['scanner'] ?? null ) ? $message['scanner'] : array();
		return array_merge(
			self::security( $scanner ),
			self::tls( is_array( $scanner['tls'] ?? null ) ? $scanner['tls'] : array() ),
			self::seo( $scanner ),
			self::wordpress( is_array( $scanner['wordpress'] ?? null ) ? $scanner['wordpress'] : array() ),
			self::email( is_array( $message['dns'] ?? null ) ? $message['dns'] : array() ),
			self::performance( is_array( $message['pagespeed'] ?? null ) ? $message['pagespeed'] : array() )
		);
	}

	/**
	 * Security header and redirect checks.
	 *
	 * @param array<string,mixed> $scanner Scanner result.
	 * @return array<int,array<string,mixed>>
	 */
	private static function security( array $scanner ): array {
		$headers = is_array( $scanner['headers'] ?? null ) ? array_change_key_case( $scanner['headers'] ) : array();
		$get     = static fn( string $name ): string => strtolower( trim( (string) ( $headers[ $name ] ?? '' ) ) );
		$out     = array();

		$https = true === ( $scanner['https'] ?? false );
		$out[] = self::finding(
			'https',
			'security',
			$https ? 'pass' : 'fail',
			'high',
			10,
			$https ? __( 'Visitors end up on HTTPS', 'oldev-health-report' ) : __( 'The site does not end on HTTPS', 'oldev-health-report' ),
			(string) ( $scanner['final_url'] ?? '' ),
			__( 'Install a certificate and redirect all HTTP traffic to HTTPS.', 'oldev-health-report' )
		);

		$hsts = $get( 'strict-transport-security' );
		preg_match( '/max-age=(\d+)/', $hsts, $match );
		$max_age = (int) ( $match[1] ?? 0 );
		$out[]   = self::finding(
			'hsts',
			'security',
			'' === $hsts ? 'fail' : ( $max_age >= 15552000 ? 'pass' : 'warn' ),
			'high',
			8,
			__( 'Strict-Transport-Security header', 'oldev-health-report' ),
			'' === $hsts ? __( 'Header is missing.', 'oldev-health-report' ) : $hsts,
			__( 'Send Strict-Transport-Security with a max-age of at least 15552000 once HTTPS is stable.', 'oldev-health-report' )
		);

		$csp        = $get( 'content-security-policy' );
		$csp_report = $get( 'content-security-policy-report-only' );
		$out[]      = self::finding(
			'csp',
			'security',
			'' !== $csp ? 'pass' : ( '' !== $csp_report ? 'warn' : 'fail' ),
			'medium',
			6,
			__( 'Content-Security-Policy header', 'oldev-health-report' ),
			'' !== $csp ? __( 'Policy is enforced.', 'oldev-health-report' ) : ( '' !== $csp_report ? __( 'Policy is in report-only mode.', 'oldev-health-report' ) : __( 'Header is missing.', 'oldev-health-report' ) ),
			__( 'Start a Content-Security-Policy in report-only mode, review it, then enforce it.', 'oldev-health-report' )
		);

		$framing = '' !== $get( 'x-frame-options' ) || str_contains( $csp, 'frame-ancestors' );
		$out[]   = self::finding(
			'framing',
			'security',
			$framing ? 'pass' : 'fail',
			'medium',
			4,
			__( 'Clickjacking protection', 'oldev-health-report' ),
			$framing ? __( 'Framing is restricted.', 'oldev-health-report' ) : __( 'Neither X-Frame-Options nor frame-ancestors is set.', 'oldev-health-report' ),
			__( 'Send X-Frame-Options: SAMEORIGIN or a frame-ancestors directive.', 'oldev-health-report' )
		);

		$nosniff = 'nosniff' === $get( 'x-content-type-options' );
		$out[]   = self::finding(
			'nosniff',
			'security',
			$nosniff ? 'pass' : 'fail',
			'medium',
			4,
			__( 'X-Content-Type-Options header', 'oldev-health-report' ),
			$nosniff ? 'nosniff' : __( 'Header is missing.', 'oldev-health-report' ),
			__( 'Send X-Content-Type-Options: nosniff.', 'oldev-health-report' )
		);

		$referrer = '' !== $get( 'referrer-policy' );
		$out[]    = self::finding(
			'referrer_policy',
			'security',
			$referrer ? 'pass' : 'warn',
			'low',
			2,
			__( 'Referrer-Policy header', 'oldev-health-report' ),
			$referrer ? $get( 'referrer-policy' ) : __( 'Header is missing.', 'oldev-health-report' ),
			__( 'Send Referrer-Policy: strict-origin-when-cross-origin.', 'oldev-health-report' )
		);

		$permissions = '' !== $get( 'permissions-policy' );
		$out[]       = self::finding(
			'permissions_policy',
			'security',
			$permissions ? 'pass' : 'warn',
			'low',
			2,
			__( 'Permissions-Policy header', 'oldev-health-report' ),
			$permissions ? __( 'Header is set.', 'oldev-health-report' ) : __( 'Header is missing.', 'oldev-health-report' ),
			__( 'Send a Permissions-Policy that turns off browser features the site does not use.', 'oldev-health-report' )
		);

		return $out;
	}

	/**
	 * Certificate checks.
	 *
	 * @param array<string,mixed> $tls TLS data.
	 * @return array<int,array<string,mixed>>
	 */
	private static function tls( array $tls ): array {
		if ( array() === $tls || ! empty( $tls['skipped'] ) ) {
			return array( self::unavailable( 'tls', 'tls', __( 'Certificate could not be checked', 'oldev-health-report' ) ) );
		}

		$valid = true === ( $tls['valid'] ?? false );
		$days  = (int) ( $tls['days_remaining'] ?? 0 );
		$out   = array(
			self::finding(
				'tls_valid',
				'tls',
				$valid ? 'pass' : 'fail',
				'critical',
				10,
				$valid ? __( 'TLS certificate is valid', 'oldev-health-report' ) : __( 'TLS certificate failed validation', 'oldev-health-report' ),
				$valid ? (string) ( $tls['issuer'] ?? '' ) : (string) ( $tls['error'] ?? '' ),
				__( 'Install a valid certificate that matches the hostname and includes the full chain.', 'oldev-health-report' )
			),
		);

		if ( $valid ) {
			$out[] = self::finding(
				'tls_expiry',
				'tls',
				$days > 30 ? 'pass' : ( $days > 7 ? 'warn' : 'fail' ),
				$days > 7 ? 'high' : 'critical',
				10,
				__( 'Certificate expiry', 'oldev-health-report' ),
				/* translators: %d: days until the certificate expires. */
				sprintf( _n( 'Expires in %d day.', 'Expires in %d days.', $days, 'oldev-health-report' ), $days ),
				__( 'Renew the certificate and confirm automatic renewal is working.', 'oldev-health-report' )
			);
		}
		return $out;
	}

	/**
	 * Search engine basics.
	 *
	 * @param array<string,mixed> $scanner Scanner result.
	 * @return array<int,array<string,mixed>>
	 */
	private static function seo( array $scanner ): array {
		$meta    = is_array( $scanner['meta'] ?? null ) ? $scanner['meta'] : array();
		$robots  = is_array( $scanner['robots'] ?? null ) ? $scanner['robots'] : array();
		$sitemap = is_array( $scanner['sitemap'] ?? null ) ? $scanner['sitemap'] : array();
		$headers = is_array( $scanner['headers'] ?? null ) ? array_change_key_case( $scanner['headers'] ) : array();

		$title     = (string) ( $meta['title'] ?? '' );
		$title_len = mb_strlen( $title );
		$desc      = (string) ( $meta['description'] ?? '' );
		$desc_len  = mb_strlen( $desc );
		$canonical = (string) ( $meta['canonical'] ?? '' );
		$directive = strtolower( (string) ( $meta['robots'] ?? '' ) . ' ' . (string) ( $headers['x-robots-tag'] ?? '' ) );
		$noindex   = str_contains( $directive, 'noindex' );
		$has_rules = true === ( $robots['found'] ?? false );
		$blocked   = true === ( $robots['disallow_all'] ?? false );

		return array(
			self::finding(
				'title',
				'seo',
				'' === $title ? 'fail' : ( $title_len >= 10 && $title_len <= 65 ? 'pass' : 'warn' ),
				'high',
				6,
				__( 'Page title', 'oldev-health-report' ),
				'' === $title ? __( 'No title found.', 'oldev-health-report' ) : $title,
				__( 'Write a unique title between 10 and 65 characters.', 'oldev-health-report' )
			),
			self::finding(
				'meta_description',
				'seo',
				'' === $desc ? 'fail' : ( $desc_len >= 50 && $desc_len <= 165 ? 'pass' : 'warn' ),
				'medium',
				4,
				__( 'Meta description', 'oldev-health-report' ),
				'' === $desc ? __( 'No meta description found.', 'oldev-health-report' ) : $desc,
				__( 'Write a meta description between 50 and 165 characters.', 'oldev-health-report' )
			),
			self::finding(
				'canonical',
				'seo',
				'' === $canonical ? 'warn' : 'pass',
				'medium',
				4,
				__( 'Canonical URL', 'oldev-health-report' ),
				'' === $canonical ? __( 'No canonical link found.', 'oldev-health-report' ) : $canonical,
				__( 'Add a canonical link to the preferred version of the page.', 'oldev-health-report' )
			),
			self::finding(
				'indexable',
				'seo',
				$noindex ? 'fail' : 'pass',
				'critical',
				6,
				$noindex ? __( 'The home page asks search engines not to index it', 'oldev-health-report' ) : __( 'The home page can be indexed', 'oldev-health-report' ),
				trim( $directive ),
				__( 'Remove the noindex directive unless the site is meant to stay out of search.', 'oldev-health-report' )
			),
			self::finding(
				'robots_txt',
				'seo',
				$blocked ? 'fail' : ( $has_rules ? 'pass' : 'warn' ),
				$blocked ? 'high' : 'low',
				3,
				__( 'robots.txt', 'oldev-health-report' ),
				$blocked ? __( 'All crawlers are blocked.', 'oldev-health-report' ) : ( $has_rules ? __( 'File found.', 'oldev-health-report' ) : __( 'File not found.', 'oldev-health-report' ) ),
				__( 'Publish a robots.txt that allows crawling and points to the sitemap.', 'oldev-health-report' )
			),
			self::finding(
				'sitemap',
				'seo',
				true === ( $sitemap['found'] ?? false ) ? 'pass' : 'warn',
				'medium',
				3,
				__( 'XML sitemap', 'oldev-health-report' ),
				(string) ( $sitemap['url'] ?? __( 'No sitemap found.', 'oldev-health-report' ) ),
				__( 'Publish an XML sitemap and list it in robots.txt.', 'oldev-health-report' )
			),
		);
	}

	/**
	 * WordPress checks. The category disappears on other platforms.
	 *
	 * @param array<string,mixed> $wordpress Fingerprint data.
	 * @return array<int,array<string,mixed>>
	 */
	private static function wordpress( array $wordpress ): array {
		if ( true !== ( $wordpress['detected'] ?? false ) ) {
			return array();
		}
		$version = (string) ( $wordpress['version'] ?? '' );
		return array(
			self::finding(
				'wp_version_hidden',
				'wordpress',
				'' === $version ? 'pass' : 'warn',
				'medium',
				3,
				__( 'WordPress version exposure', 'oldev-health-report' ),
				'' === $version ? __( 'The version is not advertised.', 'oldev-health-report' ) : $version,
				__( 'Remove the generator tag so the exact version is not advertised, and keep core updated.', 'oldev-health-report' )
			),
		);
	}

	/**
	 * Email authentication checks.
	 *
	 * @param array<string,mixed> $dns DNS data.
	 * @return array<int,array<string,mixed>>
	 */
	private static function email( array $dns ): array {
		$out = array();

		$spf = is_array( $dns['spf'] ?? null ) ? $dns['spf'] : array();
		if ( array() === $spf || ! empty( $spf['error'] ) ) {
			$out[] = self::unavailable( 'spf', 'email', __( 'SPF could not be checked', 'oldev-health-report' ) );
		} else {
			$record = strtolower( (string) ( $spf['record'] ?? '' ) );
			$weak   = str_contains( $record, '+all' ) || str_contains( $record, '?all' );
			$out[]  = self::finding(
				'spf',
				'email',
				true !== ( $spf['found'] ?? false ) || $weak ? 'fail' : 'pass',
				'high',
				6,
				__( 'SPF record', 'oldev-health-report' ),
				'' === $record ? __( 'No SPF record found.', 'oldev-health-report' ) : $record,
				__( 'Publish one SPF record that lists your senders and ends in ~all or -all.', 'oldev-health-report' )
			);
		}

		$dmarc = is_array( $dns['dmarc'] ?? null ) ? $dns['dmarc'] : array();
		if ( array() === $dmarc || ! empty( $dmarc['error'] ) ) {
			$out[] = self::unavailable( 'dmarc', 'email', __( 'DMARC could not be checked', 'oldev-health-report' ) );
		} else {
			$record = strtolower( (string) ( $dmarc['record'] ?? '' ) );
			preg_match( '/\bp=(none|quarantine|reject)/', $record, $match );
			$policy = $match[1] ?? '';
			$out[]  = self::finding(
				'dmarc',
				'email',
				'' === $policy ? 'fail' : ( 'none' === $policy ? 'warn' : 'pass' ),
				'high',
				6,
				__( 'DMARC policy', 'oldev-health-report' ),
				'' === $record ? __( 'No DMARC record found.', 'oldev-health-report' ) : $record,
				__( 'Publish DMARC, watch the reports, then move to p=quarantine or p=reject.', 'oldev-health-report' )
			);
		}

		$dkim = is_array( $dns['dkim'] ?? null ) ? $dns['dkim'] : array();
		if ( array() === $dkim || ! empty( $dkim['error'] ) ) {
			$out[] = self::unavailable( 'dkim', 'email', __( 'DKIM could not be checked', 'oldev-health-report' ) );
		} else {
			$selectors = is_array( $dkim['selectors'] ?? null ) ? implode( ', ', array_map( 'strval', $dkim['selectors'] ) ) : '';
			$out[]     = self::finding(
				'dkim',
				'email',
				'' === $selectors ? 'warn' : 'pass',
				'medium',
				4,
				__( 'DKIM keys', 'oldev-health-report' ),
				'' === $selectors ? __( 'None found among common selectors. A custom selector would not show here.', 'oldev-health-report' ) : $selectors,
				__( 'Turn on DKIM signing at every service that sends mail for this domain.', 'oldev-health-report' )
			);
		}
		return $out;
	}

	/**
	 * PageSpeed Insights checks.
	 *
	 * @param array<string,mixed> $psi PageSpeed data.
	 * @return array<int,array<string,mixed>>
	 */
	private static function performance( array $psi ): array {
		if ( array() === $psi || ! empty( $psi['error'] ) || ! isset( $psi['score'] ) ) {
			return array( self::unavailable( 'pagespeed', 'performance', __( 'Performance could not be measured', 'oldev-health-report' ) ) );
		}

		$score = (int) $psi['score'];
		$lcp   = (int) ( $psi['lcp_ms'] ?? 0 );
		$cls   = (float) ( $psi['cls'] ?? 0 );
		$tbt   = (int) ( $psi['tbt_ms'] ?? 0 );
		$grade = static fn( float $value, float $good, float $poor ): string => $value <= $good ? 'pass' : ( $value <= $poor ? 'warn' : 'fail' );

		return array(
			self::finding(
				'psi_score',
				'performance',
				$score >= 90 ? 'pass' : ( $score >= 50 ? 'warn' : 'fail' ),
				'high',
				6,
				__( 'PageSpeed performance score', 'oldev-health-report' ),
				/* translators: %d: PageSpeed score out of 100. */
				sprintf( __( '%d out of 100 on mobile.', 'oldev-health-report' ), $score ),
				__( 'Work through the PageSpeed opportunities, starting with the largest images and render-blocking files.', 'oldev-health-report' )
			),
			self::finding(
				'lcp',
				'performance',
				$grade( $lcp, 2500, 4000 ),
				'high',
				4,
				__( 'Largest Contentful Paint', 'oldev-health-report' ),
				/* translators: %s: time in seconds. */
				sprintf( __( '%s seconds.', 'oldev-health-report' ), number_format_i18n( $lcp / 1000, 1 ) ),
				__( 'Speed up the server response and preload the main image.', 'oldev-health-report' )
			),
			self::finding(
				'cls',
				'performance',
				$grade( $cls, 0.1, 0.25 ),
				'medium',
				3,
				__( 'Cumulative Layout Shift', 'oldev-health-report' ),
				number_format_i18n( $cls, 3 ),
				__( 'Set width and height on media and reserve space for late content.', 'oldev-health-report' )
			),
			self::finding(
				'tbt',
				'performance',
				$grade( $tbt, 200, 600 ),
				'medium',
				3,
				__( 'Total Blocking Time', 'oldev-health-report' ),
				/* translators: %d: time in milliseconds. */
				sprintf( __( '%d milliseconds.', 'oldev-health-report' ), $tbt ),
				__( 'Remove unused JavaScript and defer what is left.', 'oldev-health-report' )
			),
		);
	}

	/**
	 * Builds a finding.
	 *
	 * @param string $key      Check key.
	 * @param string $category Category.
	 * @param string $outcome  pass, warn or fail.
	 * @param string $severity Severity if the check does not pass.
	 * @param int    $weight   Weight.
	 * @param string $title    Title.
	 * @param string $detail   Detail, may contain page text.
	 * @param string $fix      Fix advice.
	 * @return array<string,mixed>
	 */
	private static function finding( string $key, string $category, string $outcome, string $severity, int $weight, string $title, string $detail, string $fix ): array {
		return array(
			'check_key'     => $key,
			'category'      => $category,
			'outcome'       => $outcome,
			'severity'      => 'pass' === $outcome ? 'none' : $severity,
			'weight'        => $weight,
			'rank_position' => 0,
			'title'         => $title,
			'detail'        => mb_substr( $detail, 0, 400 ),
			'fix'           => 'pass' === $outcome ? '' : $fix,
		);
	}

	/**
	 * Builds an unavailable finding, which is left out of scoring.
	 *
	 * @param string $key      Check key.
	 * @param string $category Category.
	 * @param string $title    Title.
	 * @return array<string,mixed>
	 */
	private static function unavailable( string $key, string $category, string $title ): array {
		return array(
			'check_key'     => $key,
			'category'      => $category,
			'outcome'       => 'info',
			'severity'      => 'none',
			'weight'        => 0,
			'rank_position' => 0,
			'title'         => $title,
			'detail'        => __( 'This check did not return data, so it is not part of the score.', 'oldev-health-report' ),
			'fix'           => '',
		);
	}
}
