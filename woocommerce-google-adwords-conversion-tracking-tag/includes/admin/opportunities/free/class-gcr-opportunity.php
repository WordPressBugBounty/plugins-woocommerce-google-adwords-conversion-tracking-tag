<?php

namespace SweetCode\Pixel_Manager\Admin\Opportunities\Free;

use SweetCode\Pixel_Manager\Admin\Environment;
use SweetCode\Pixel_Manager\Admin\Opportunities\Opportunity;

defined('ABSPATH') || exit; // Exit if accessed directly

/**
 * Opportunity: Google Customer Reviews for WooCommerce
 *
 * Cross-sell opportunity for PMW users who don't have the
 * Google Customer Reviews plugin active.
 *
 * @since 1.57.0
 */
class Google_Customer_Reviews extends Opportunity {

	public static function available() {

		// Only show if GCR is not active
		if (Environment::is_gcr_active()) {
			return false;
		}

		return true;
	}

	public static function card_data() {

		return [
			'id'              => 'google-customer-reviews',
			'title'           => esc_html__(
				'Google Customer Reviews for WooCommerce',
				'woocommerce-google-adwords-conversion-tracking-tag'
			),
			'description'     => [
				esc_html__(
					'Google Customer Reviews is a free program by Google that collects post-purchase feedback from your customers. It enables seller ratings (star ratings) to appear on your Google Shopping and Google Ads listings, building trust and increasing click-through rates.',
					'woocommerce-google-adwords-conversion-tracking-tag'
				),
				esc_html__(
					'According to Google, seller ratings can boost ad click-through rates by up to 10%. Our plugin integrates Google Customer Reviews seamlessly into WooCommerce, so you can start collecting reviews and earning those stars right away. Combined with the Pixel Manager\'s conversion tracking, you get precise performance data alongside authentic social proof that drives more sales.',
					'woocommerce-google-adwords-conversion-tracking-tag'
				),
				esc_html__(
					'Get the Pixel Manager and Google Customer Reviews together at a discount with the SweetCode Bundle, which also includes additional plugins as the portfolio grows.',
					'woocommerce-google-adwords-conversion-tracking-tag'
				),
			],
			'impact'          => esc_html__(
				'high',
				'woocommerce-google-adwords-conversion-tracking-tag'
			),
			'learn_more_link' => 'https://sweetcode.com/plugins/gcr/?utm_source=pmw&utm_medium=opportunity&utm_campaign=gcr-cross-sell',
			'custom_buttons'  => [
				[
					'label'  => esc_html__('Bundle Discount', 'woocommerce-google-adwords-conversion-tracking-tag'),
					'url'    => 'https://sweetcode.com/plugins/bundle/?utm_source=pmw&utm_medium=opportunity&utm_campaign=gcr-cross-sell',
					'target' => '_blank',
				],
			],
			'since'            => 1738886400, // February 7, 2026
			'repeat_interval'  => 6 * MONTH_IN_SECONDS,
		];
	}
}
