<?php

namespace SweetCode\Pixel_Manager\Admin\Opportunities\Free;

use SweetCode\Pixel_Manager\Admin\Documentation;
use SweetCode\Pixel_Manager\Admin\Opportunities\Opportunity;
use SweetCode\Pixel_Manager\Options;

defined('ABSPATH') || exit; // Exit if accessed directly

/**
 * Opportunity: LTV calculation
 *
 * @since 1.37.2
 */
class LTV_Order_Level_Calculation extends Opportunity {

	public static function available() {

		// Google Ads purchase conversion must be enabled
		if (!Options::is_google_ads_purchase_conversion_enabled()) {
			return false;
		}

		// If the LTV calculation is enabled, then this opportunity is not available
		if (Options::is_order_level_ltv_calculation_active()) {
			return false;
		}

		return true;
	}

	public static function card_data() {

		return [
			'id'          => 'ltv-order-level-calculation',
			'title'       => esc_html__(
				'Lifetime Value Calculation',
				'woocommerce-google-adwords-conversion-tracking-tag'
			),
			'description' => [
				esc_html__(
					'The Pixel Manager detected that Google Ads purchase conversion is enabled, but the Customer Lifetime Value Calculation has yet to be enabled.',
					'woocommerce-google-adwords-conversion-tracking-tag'
				),
				esc_html__(
					'Enabling the Customer Lifetime Value Calculation will allow you to send the customer lifetime value (LTV) to Google Ads, which will help you optimize your campaigns for better ROI.',
					'woocommerce-google-adwords-conversion-tracking-tag'
				),
			],
			'impact'      => esc_html__(
				'medium',
				'woocommerce-google-adwords-conversion-tracking-tag'
			),
			'setup_link'  => Documentation::get_link('ltv_order_calculation'),
			//			'learn_more_link' => '#',
			'since'       => 1706849519, // current timestamp
		];
	}
}
