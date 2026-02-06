<?php

namespace SweetCode\Pixel_Manager\Admin\Opportunities\Free;

use SweetCode\Pixel_Manager\Admin\Documentation;
use SweetCode\Pixel_Manager\Admin\Environment;
use SweetCode\Pixel_Manager\Admin\Opportunities\Opportunity;
use SweetCode\Pixel_Manager\Options;

defined('ABSPATH') || exit; // Exit if accessed directly

/**
 * Opportunity: Google tag gateway for advertisers
 *
 * @since 1.48.0
 */
class Google_Gtag_Gateway_For_Advertisers extends Opportunity {

	public static function available() {

		// Google tag gateway for advertisers must be disabled
		if (Options::get_google_tag_gateway_measurement_path()) {
			return false;
		}

		// Only show if Cloudflare is available (plugin active or server behind Cloudflare)
		if (!self::is_cloudflare_available()) {
			return false;
		}

		return true;
	}

	/**
	 * Check if Cloudflare is available for use.
	 *
	 * The Google Tag Gateway works best with Cloudflare because it can handle
	 * all requests at the CDN level, reducing server load.
	 *
	 * @return bool True if Cloudflare is available, false otherwise
	 *
	 * @since 1.48.0
	 */
	private static function is_cloudflare_available() {
		return Environment::is_cloudflare_active() || Environment::is_server_behind_cloudflare();
	}

	public static function card_data() {

		return [
			'id'              => 'google-tag-gateway-for-advertisers',
			'title'           => esc_html__(
				'Google tag gateway for advertisers',
				'woocommerce-google-adwords-conversion-tracking-tag'
			),
			'description'     => [
				esc_html__(
					'The Pixel Manager detected that you are not using the Google tag gateway for advertisers.',
					'woocommerce-google-adwords-conversion-tracking-tag'
				),
				esc_html__(
					'Enabling the Google tag gateway for advertisers will allow you to track conversions and events more accurately.',
					'woocommerce-google-adwords-conversion-tracking-tag'
				),
			],
			'impact'          => esc_html__(
				'high',
				'woocommerce-google-adwords-conversion-tracking-tag'
			),
			'setup_link'      => Documentation::get_link('google_tag_gateway_measurement_path'),
			'learn_more_link' => 'https://support.google.com/google-ads/answer/16214371',
			'since'           => 1747353600, // timestamp
		];
	}
}
