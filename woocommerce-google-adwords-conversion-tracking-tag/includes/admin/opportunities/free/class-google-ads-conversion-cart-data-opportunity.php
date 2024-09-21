<?php

namespace SweetCode\Pixel_Manager\Admin\Opportunities\Free;

use SweetCode\Pixel_Manager\Admin\Documentation;
use SweetCode\Pixel_Manager\Admin\Opportunities\Opportunity;
use SweetCode\Pixel_Manager\Options;

defined('ABSPATH') || exit; // Exit if accessed directly

/**
 * Opportunity: Google Ads Conversion Cart Data
 *
 * @since 1.28.0
 */
class Google_Ads_Conversion_Cart_Data extends Opportunity {

	public static function available() {

		// Google Ads purchase conversion must be enabled
		if (!Options::is_google_ads_purchase_conversion_enabled()) {
			return false;
		}

		// Conversion Cart Data must be disabled
		if (Options::is_google_ads_conversion_cart_data_enabled()) {
			return false;
		}

		return true;
	}

	public static function card_data() {

		return [
			'id'          => 'google-ads-conversion-cart-data',
			'title'       => esc_html__(
				'Google Ads Conversion Cart Data',
				'woocommerce-google-adwords-conversion-tracking-tag'
			),
			'description' => [
				esc_html__(
					'The Pixel Manager detected that Google Ads purchase conversion is enabled, but Google Ads Conversion Cart Data has yet to be enabled.',
					'woocommerce-google-adwords-conversion-tracking-tag'
				),
				esc_html__(
					'Enabling Google Ads Conversion Cart Data will improve reporting by including cart item data in your Google Ads conversion reports.',
					'woocommerce-google-adwords-conversion-tracking-tag'
				),
			],
			'impact'      => esc_html__(
				'medium',
				'woocommerce-google-adwords-conversion-tracking-tag'
			),
			'setup_link'  => Documentation::get_link('aw_merchant_id'),
			//			'learn_more_link' => '#',
			'since'       => 1672895375, // timestamp
		];
	}
}
