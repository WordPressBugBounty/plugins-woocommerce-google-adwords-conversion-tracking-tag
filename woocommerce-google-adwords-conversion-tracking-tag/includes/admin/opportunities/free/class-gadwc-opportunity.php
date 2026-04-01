<?php

namespace SweetCode\Pixel_Manager\Admin\Opportunities\Free;

use SweetCode\Pixel_Manager\Admin\Environment;
use SweetCode\Pixel_Manager\Admin\Opportunities\Opportunity;
use SweetCode\Pixel_Manager\Helpers;
use SweetCode\Pixel_Manager\Options;

defined('ABSPATH') || exit; // Exit if accessed directly

/**
 * Opportunity: Google Automated Discounts for WooCommerce
 *
 * Cross-sell opportunity for PMW users who don't have the
 * Google Automated Discounts plugin active.
 *
 * @since 1.57.0
 */
class Google_Automated_Discounts extends Opportunity {

	public static function available() {

		// Only show if GADWC is not active
		if (Environment::is_gadwc_active()) {
			return false;
		}

		// Only show if Conversion Cart Data is enabled,
		// which indicates a Google Merchant Center is connected
		if (!Options::is_google_ads_conversion_cart_data_enabled()) {
			return false;
		}

		return true;
	}

	public static function card_data() {

		$learn_more_link = Helpers::is_pmw_wcm_distro()
			? 'https://woocommerce.com/products/google-automated-discounts-pro-for-woocommerce/?utm_source=pmw&utm_medium=opportunity&utm_campaign=gadwc-cross-sell'
			: 'https://sweetcode.com/plugins/gadwc/?utm_source=pmw&utm_medium=opportunity&utm_campaign=gadwc-cross-sell';

		$card_data = [
			'id'              => 'google-automated-discounts',
			'title'           => esc_html__(
				'Google Automated Discounts for WooCommerce',
				'woocommerce-google-adwords-conversion-tracking-tag'
			),
			'description'     => [
				esc_html__(
					'Did you know that Google provides promotional discount data for many products? Our Google Automated Discounts plugin automatically applies those discounts to your WooCommerce products, helping you win the Google Shopping sale price badge.',
					'woocommerce-google-adwords-conversion-tracking-tag'
				),
				esc_html__(
					'Products with a sale price badge on Google Shopping see up to 10-25% higher click-through rates compared to listings without one. More clicks at the same ad spend means more conversions and a lower cost per acquisition. Combined with the Pixel Manager\'s conversion tracking, you get a powerful combo: more clicks from Google Shopping and precise measurement of every sale.',
					'woocommerce-google-adwords-conversion-tracking-tag'
				),
				esc_html__(
					'Get the Pixel Manager and Google Automated Discounts together at a discount with the SweetCode Bundle, which also includes additional plugins as the portfolio grows.',
					'woocommerce-google-adwords-conversion-tracking-tag'
				),
			],
			'impact'          => esc_html__(
				'high',
				'woocommerce-google-adwords-conversion-tracking-tag'
			),
			'learn_more_link' => $learn_more_link,
			'custom_buttons'  => [
				[
					'label'  => esc_html__('Bundle Discount', 'woocommerce-google-adwords-conversion-tracking-tag'),
					'url'    => 'https://sweetcode.com/plugins/bundle/?utm_source=pmw&utm_medium=opportunity&utm_campaign=gadwc-cross-sell',
					'target' => '_blank',
				],
			],
			'since'            => 1738886400, // February 7, 2026
			'repeat_interval'  => 6 * MONTH_IN_SECONDS,
		];

		// For WooCommerce Marketplace distribution, remove the bundle button
		// since the bundle is only available on sweetcode.com
		if (Helpers::is_pmw_wcm_distro()) {
			$card_data['custom_buttons'] = [];
		}

		return $card_data;
	}
}
