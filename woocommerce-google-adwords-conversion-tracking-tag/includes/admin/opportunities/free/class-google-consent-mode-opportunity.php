<?php

namespace SweetCode\Pixel_Manager\Admin\Opportunities\Free;

use SweetCode\Pixel_Manager\Admin\Documentation;
use SweetCode\Pixel_Manager\Admin\Opportunities\Opportunity;
use SweetCode\Pixel_Manager\Options;

defined('ABSPATH') || exit; // Exit if accessed directly

/**
 * Opportunity: Google Consent Mode
 *
 * @since 1.53.0
 */
class Google_Consent_Mode extends Opportunity {

	public static function available() {

		// Google must be active
		if (!Options::is_google_active()) {
			return false;
		}

		// Google Consent Mode must be disabled
		if (Options::is_google_consent_mode_active()) {
			return false;
		}

		return true;
	}

	public static function card_data() {

		return [
			'id'              => 'google-consent-mode',
			'title'           => esc_html__(
				'Google Consent Mode',
				'woocommerce-google-adwords-conversion-tracking-tag'
			),
			'description'     => [
				esc_html__(
					'The Pixel Manager detected that Google Consent Mode is disabled.',
					'woocommerce-google-adwords-conversion-tracking-tag'
				),
				esc_html__(
					'Enabling Consent Mode lets Google tags respect user consent while still sending anonymised, cookieless pings. Google\'s modeling can recover more than 70 percent of conversions that would otherwise be lost when cookies are denied.',
					'woocommerce-google-adwords-conversion-tracking-tag'
				),
				esc_html__(
					'This improves data accuracy not only in regions with mandatory consent, but also anywhere a consent banner is used, since tracking would otherwise stop completely when users decline cookies.',
					'woocommerce-google-adwords-conversion-tracking-tag'
				),
			],
			'impact'          => esc_html__(
				'high',
				'woocommerce-google-adwords-conversion-tracking-tag'
			),
			'setup_link'      => Documentation::get_link('google_consent_mode'),
			'learn_more_link' => 'https://support.google.com/google-ads/answer/10000067',
			'since'           => 1733529600, // timestamp: December 7, 2025
		];
	}
}
