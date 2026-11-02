<?php
/**
 * Chooses between the AI and rule-based summaries.
 *
 * @package OldevHealthReport
 */

namespace Oldev\HealthReport\Summary;

use Oldev\HealthReport\Config;
use Oldev\HealthReport\Data\AbuseLog;
use Oldev\HealthReport\Data\Counters;

defined( 'ABSPATH' ) || exit;

/**
 * Tries Claude when enabled and under the daily cap. Always ends with usable text.
 */
final class SummaryService {

	/**
	 * Wires providers.
	 *
	 * @param SummaryProvider $ai    AI provider.
	 * @param SummaryProvider $rules Rule provider.
	 */
	public function __construct( private SummaryProvider $ai, private SummaryProvider $rules ) {}

	/**
	 * Writes a summary.
	 *
	 * @param array<string,mixed>            $scan     Scan row.
	 * @param array<int,array<string,mixed>> $findings Ranked findings.
	 * @return array{text:string,source:string}
	 */
	public function summarize( array $scan, array $findings ): array {
		if ( Config::ai_enabled() ) {
			if ( Counters::spend_daily( 'ai', Config::int( 'SHR_DAILY_AI_CAP', 25 ) ) ) {
				$text = $this->ai->write( $scan, $findings );
				if ( '' !== $text ) {
					return array(
						'text'   => $text,
						'source' => 'ai',
					);
				}
				AbuseLog::record( 'ai_failed', '', (string) $scan['domain'] );
			} else {
				AbuseLog::record( 'ai_cap_reached', '', (string) $scan['domain'] );
			}
		}

		return array(
			'text'   => $this->rules->write( $scan, $findings ),
			'source' => 'rules',
		);
	}
}
