<?php

namespace SweetCode\Pixel_Manager\Admin\Opportunities\Free;

use SweetCode\Pixel_Manager\Admin\Documentation;
use SweetCode\Pixel_Manager\Admin\Opportunities\Opportunity;
use SweetCode\Pixel_Manager\Options;

defined('ABSPATH') || exit; // Exit if accessed directly

/**
 * Opportunity: Microsoft Ads Consent Mode
 *
 * @since 1.53.0
 */
class Microsoft_Ads_Consent_Mode extends Opportunity {

	public static function available() {

		// Microsoft Ads must be active
		if (!Options::is_bing_active()) {
			return false;
		}

		// Microsoft Ads Consent Mode must be disabled
		if (Options::is_bing_consent_mode_active()) {
			return false;
		}

		return true;
	}

	public static function card_data() {

		return [
			'id'              => 'microsoft-ads-consent-mode',
			'title'           => esc_html__(
				'Microsoft Ads Consent Mode',
				'woocommerce-google-adwords-conversion-tracking-tag'
			),
			'description'     => [
				esc_html__(
					'The Pixel Manager detected that Microsoft Consent Mode is disabled for Microsoft-based ad tracking.',
					'woocommerce-google-adwords-conversion-tracking-tag'
				),
				esc_html__(
					'Activating Microsoft Consent Mode ensures your Microsoft tags respect each visitor\'s consent before using cookies. The UET tag will check a parameter (ad_storage) set to "granted" or "denied", depending on the user\'s choice.',
					'woocommerce-google-adwords-conversion-tracking-tag'
				),
				esc_html__(
					'If consent is denied: No advertising cookies are written or read. Only minimal or anonymised data may be logged (e.g. basic page-view signals), but no personal tracking or attribution will occur.',
					'woocommerce-google-adwords-conversion-tracking-tag'
				),
				esc_html__(
					'Because of this strict consent-based behaviour, there is no fallback modelling: unconsented conversions or events are simply not tracked or attributed.',
					'woocommerce-google-adwords-conversion-tracking-tag'
				),
				esc_html__(
					'This makes Microsoft Consent Mode especially important if you target users in regions with mandatory consent rules (e.g. EU, UK, Switzerland). Without it, or without user consent, conversion tracking, ad attribution, and retargeting will break.',
					'woocommerce-google-adwords-conversion-tracking-tag'
				),
			],
			'impact'          => esc_html__(
				'high',
				'woocommerce-google-adwords-conversion-tracking-tag'
			),
			'setup_link'      => Documentation::get_link('microsoft_ads_consent_mode'),
			'learn_more_link' => 'https://help.ads.microsoft.com/apex/index/3/en/60119',
			'since'           => 1733529600, // timestamp: December 7, 2025
		];
	}
}
