<?php

namespace SweetCode\Pixel_Manager\Admin\Opportunities\Free;

use SweetCode\Pixel_Manager\Admin\Documentation;
use SweetCode\Pixel_Manager\Admin\Environment;
use SweetCode\Pixel_Manager\Admin\Opportunities\Opportunity;
use SweetCode\Pixel_Manager\Options;

defined('ABSPATH') || exit; // Exit if accessed directly

/**
 * Opportunity: Google gtag gateway for advertisers
 *
 * @since 1.48.0
 */
class Google_Gtag_Gateway_For_Advertisers extends Opportunity {

	public static function available() {

		// Google gtag gateway for advertisers must be disabled
		if (Options::get_google_tag_gateway_measurement_path()) {
			return false;
		}

		// Return false if not behind Cloudflare
		// For now, we only push it to Cloudflare users
		// because for all other users it's much more challenging to set up
		if (!Environment::is_server_behind_cloudflare()) {
			return false;
		}

		return true;
	}

	public static function card_data() {

		return [
			'id'              => 'google-gtag-gateway-for-advertisers',
			'title'           => esc_html__(
				'Google gtag gateway for advertisers',
				'woocommerce-google-adwords-conversion-tracking-tag'
			),
			'description'     => [
				esc_html__(
					'The Pixel Manager detected that you are not using the Google gtag gateway for advertisers.',
					'woocommerce-google-adwords-conversion-tracking-tag'
				),
				esc_html__(
					'Enabling the Google gtag gateway for advertisers will allow you to track conversions and events more accurately.',
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
