<?php
/**
 * Scan form markup.
 *
 * @package OldevHealthReport
 */

use Oldev\HealthReport\Config;

defined( 'ABSPATH' ) || exit;
?>
<form <?php echo get_block_wrapper_attributes( array( 'class' => 'shr-form' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Core escapes these attributes. ?>
	data-shr-scan-form
	data-endpoint="<?php echo esc_url( rest_url( 'shr/v1/scans' ) ); ?>"
	novalidate>
	<label class="shr-label" for="shr-url"><?php esc_html_e( 'Website address', 'oldev-health-report' ); ?></label>
	<div class="shr-row">
		<input class="shr-input" id="shr-url" name="url" type="text" inputmode="url" autocomplete="url" placeholder="yourwebsite.com" required>
		<button class="shr-button" type="submit"><?php esc_html_e( 'Check my website', 'oldev-health-report' ); ?></button>
	</div>

	<label class="shr-check">
		<input type="checkbox" name="consent" required>
		<span><?php esc_html_e( 'I own this website or have permission to test it.', 'oldev-health-report' ); ?></span>
	</label>

	<details class="shr-more">
		<summary><?php esc_html_e( 'Email me the PDF report (optional)', 'oldev-health-report' ); ?></summary>
		<label class="shr-label" for="shr-email"><?php esc_html_e( 'Email address', 'oldev-health-report' ); ?></label>
		<input class="shr-input" id="shr-email" name="email" type="email" autocomplete="email" placeholder="you@example.com">
		<label class="shr-check">
			<input type="checkbox" name="email_consent">
			<span><?php esc_html_e( 'Send the PDF report to this address.', 'oldev-health-report' ); ?></span>
		</label>
		<label class="shr-check">
			<input type="checkbox" name="marketing_consent">
			<span><?php esc_html_e( 'I also agree to receive occasional website health advice from OLDEV.', 'oldev-health-report' ); ?></span>
		</label>
	</details>

	<div class="cf-turnstile" data-sitekey="<?php echo esc_attr( Config::string( 'SHR_TURNSTILE_SITE_KEY' ) ); ?>" data-size="flexible"></div>
	<p class="shr-error" role="alert" hidden></p>
	<p class="shr-note">
		<?php esc_html_e( 'Passive checks only.', 'oldev-health-report' ); ?>
		<a href="<?php echo esc_url( home_url( '/privacy/' ) ); ?>"><?php esc_html_e( 'How your data is handled', 'oldev-health-report' ); ?></a>
	</p>
</form>
