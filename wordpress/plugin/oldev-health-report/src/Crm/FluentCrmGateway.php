<?php
/**
 * FluentCRM contact sync.
 *
 * @package OldevHealthReport
 */

namespace Oldev\HealthReport\Crm;

use FluentCrm\App\Models\Subscriber;
use FluentCrm\App\Models\Tag;

defined( 'ABSPATH' ) || exit;

/**
 * Writes a contact with tags from findings and consent. Without marketing consent the contact is
 * stored as transactional, so campaigns skip it.
 */
final class FluentCrmGateway implements CrmGateway {

	/**
	 * Creates or updates a contact.
	 *
	 * @param array<string,mixed>            $owner    Owner row.
	 * @param array<string,mixed>            $scan     Scan row.
	 * @param array<int,array<string,mixed>> $findings Ranked findings.
	 * @return bool
	 */
	public function sync( array $owner, array $scan, array $findings ): bool {
		$tag_ids = array();
		foreach ( self::tags_for( $owner, $scan, $findings ) as $slug => $title ) {
			$tag       = Tag::firstOrCreate( array( 'slug' => $slug ), array( 'title' => $title ) );
			$tag_ids[] = (int) $tag->id;
		}

		$contact = FluentCrmApi( 'contacts' )->createOrUpdate(
			array(
				'email'  => (string) $owner['email'],
				'status' => empty( $owner['marketing_consent_at'] ) ? 'transactional' : 'subscribed',
				'source' => 'website-health-report',
				'tags'   => $tag_ids,
			)
		);
		return (bool) $contact;
	}

	/**
	 * Deletes a contact.
	 *
	 * @param string $email Address.
	 * @return void
	 */
	public function erase( string $email ): void {
		$contact = Subscriber::where( 'email', $email )->first();
		if ( $contact ) {
			$contact->delete();
		}
	}

	/**
	 * Works out the tags for a contact.
	 *
	 * @param array<string,mixed>            $owner    Owner row.
	 * @param array<string,mixed>            $scan     Scan row.
	 * @param array<int,array<string,mixed>> $findings Ranked findings.
	 * @return array<string,string> Slug to title.
	 */
	public static function tags_for( array $owner, array $scan, array $findings ): array {
		$grade = strtolower( (string) $scan['grade'] );
		$tags  = array(
			'shr-report'          => 'Health report',
			'shr-grade-' . $grade => 'Health grade ' . strtoupper( $grade ),
		);

		$tags[ empty( $owner['pdf_consent_at'] ) ? 'shr-no-pdf-consent' : 'shr-consent-pdf' ]           = empty( $owner['pdf_consent_at'] ) ? 'No PDF consent' : 'Consent: PDF';
		$tags[ empty( $owner['marketing_consent_at'] ) ? 'shr-no-marketing' : 'shr-consent-marketing' ] = empty( $owner['marketing_consent_at'] ) ? 'No marketing consent' : 'Consent: marketing';

		foreach ( $findings as $finding ) {
			if ( 'wordpress' === $finding['category'] ) {
				$tags['shr-wordpress'] = 'Runs WordPress';
			}
			if ( 'fail' === $finding['outcome'] ) {
				$tags[ 'shr-fail-' . $finding['category'] ] = 'Failed: ' . $finding['category'];
			}
		}
		return $tags;
	}
}
