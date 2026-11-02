<?php
/**
 * Display labels.
 *
 * @package OldevHealthReport
 */

namespace Oldev\HealthReport\Frontend;

defined( 'ABSPATH' ) || exit;

/**
 * Shared text for categories, severities and outcomes.
 */
final class Labels {

	/**
	 * Category label.
	 *
	 * @param string $key Category key.
	 * @return string
	 */
	public static function category( string $key ): string {
		$labels = array(
			'security'    => __( 'Security headers', 'oldev-health-report' ),
			'tls'         => __( 'TLS certificate', 'oldev-health-report' ),
			'seo'         => __( 'Search basics', 'oldev-health-report' ),
			'email'       => __( 'Email authentication', 'oldev-health-report' ),
			'performance' => __( 'Performance', 'oldev-health-report' ),
			'wordpress'   => __( 'WordPress', 'oldev-health-report' ),
		);
		return $labels[ $key ] ?? $key;
	}

	/**
	 * Severity label.
	 *
	 * @param string $key Severity key.
	 * @return string
	 */
	public static function severity( string $key ): string {
		$labels = array(
			'critical' => __( 'Critical', 'oldev-health-report' ),
			'high'     => __( 'High', 'oldev-health-report' ),
			'medium'   => __( 'Medium', 'oldev-health-report' ),
			'low'      => __( 'Low', 'oldev-health-report' ),
		);
		return $labels[ $key ] ?? '';
	}

	/**
	 * Outcome label.
	 *
	 * @param string $key Outcome key.
	 * @return string
	 */
	public static function outcome( string $key ): string {
		$labels = array(
			'pass' => __( 'Passed', 'oldev-health-report' ),
			'warn' => __( 'Needs attention', 'oldev-health-report' ),
			'fail' => __( 'Failed', 'oldev-health-report' ),
			'info' => __( 'Not measured', 'oldev-health-report' ),
		);
		return $labels[ $key ] ?? $key;
	}
}
