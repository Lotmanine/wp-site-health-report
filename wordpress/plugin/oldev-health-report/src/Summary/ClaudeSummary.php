<?php
/**
 * Claude API summary.
 *
 * @package OldevHealthReport
 */

namespace Oldev\HealthReport\Summary;

use Oldev\HealthReport\Config;

defined( 'ABSPATH' ) || exit;

/**
 * Calls the Messages API with data this plugin generated. Text from the scanned page is never sent,
 * so a hostile page cannot steer the model.
 */
final class ClaudeSummary implements SummaryProvider {

	private const ENDPOINT = 'https://api.anthropic.com/v1/messages';

	private const SYSTEM = 'You write short summaries of website health reports for business owners. '
		. 'Use plain English and at most four sentences. Use only the data provided. '
		. 'Do not use em dashes or en dashes. Do not mention prices, offers or services.';

	/**
	 * Writes a summary.
	 *
	 * @param array<string,mixed>            $scan     Scan row.
	 * @param array<int,array<string,mixed>> $findings Ranked findings.
	 * @return string Empty on any failure.
	 */
	public function write( array $scan, array $findings ): string {
		$response = wp_remote_post(
			self::ENDPOINT,
			array(
				'timeout' => 30,
				'headers' => array(
					'content-type'      => 'application/json',
					'x-api-key'         => Config::string( 'ANTHROPIC_API_KEY' ),
					'anthropic-version' => '2026-10-21',
				),
				'body'    => (string) wp_json_encode(
					array(
						'model'         => Config::string( 'SHR_AI_MODEL' ),
						'max_tokens'    => 2000,
						'output_config' => array( 'effort' => 'low' ),
						'system'        => self::SYSTEM,
						'messages'      => array(
							array(
								'role'    => 'user',
								'content' => (string) wp_json_encode( self::facts( $scan, $findings ) ),
							),
						),
					)
				),
			)
		);

		if ( is_wp_error( $response ) || 200 !== wp_remote_retrieve_response_code( $response ) ) {
			return '';
		}

		$data = json_decode( wp_remote_retrieve_body( $response ), true );
		if ( ! is_array( $data ) || 'end_turn' !== ( $data['stop_reason'] ?? '' ) ) {
			return '';
		}

		foreach ( (array) ( $data['content'] ?? array() ) as $block ) {
			if ( 'text' === ( $block['type'] ?? '' ) ) {
				return self::clean( (string) ( $block['text'] ?? '' ) );
			}
		}
		return '';
	}

	/**
	 * Builds the fact sheet. Titles here come from the rule catalogue, not from the page.
	 *
	 * @param array<string,mixed>            $scan     Scan row.
	 * @param array<int,array<string,mixed>> $findings Ranked findings.
	 * @return array<string,mixed>
	 */
	public static function facts( array $scan, array $findings ): array {
		$issues = array_values( array_filter( $findings, static fn( array $finding ): bool => in_array( $finding['outcome'], array( 'fail', 'warn' ), true ) ) );
		return array(
			'domain'     => $scan['domain'],
			'score'      => (int) $scan['overall_score'],
			'grade'      => $scan['grade'],
			'categories' => json_decode( (string) ( $scan['category_scores'] ?? '{}' ), true ),
			'issues'     => array_map(
				static fn( array $finding ): array => array(
					'check'    => $finding['check_key'],
					'outcome'  => $finding['outcome'],
					'severity' => $finding['severity'],
					'title'    => $finding['title'],
				),
				array_slice( $issues, 0, 8 )
			),
		);
	}

	/**
	 * Normalises model text.
	 *
	 * @param string $text Model output.
	 * @return string
	 */
	private static function clean( string $text ): string {
		$text = str_replace( array( "\u{2014}", "\u{2013}" ), ', ', trim( wp_strip_all_tags( $text ) ) );
		return mb_substr( $text, 0, 800 );
	}
}
