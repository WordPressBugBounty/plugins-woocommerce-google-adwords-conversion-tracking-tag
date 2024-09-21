<?php

namespace SweetCode\Pixel_Manager\Admin\Opportunities\Free;

use SweetCode\Pixel_Manager\Admin\Documentation;
use SweetCode\Pixel_Manager\Admin\Opportunities\Opportunity;
use SweetCode\Pixel_Manager\Options;

defined('ABSPATH') || exit; // Exit if accessed directly

/**
 * Opportunity: Dynamic Remarketing Variations Output
 *
 * @since 1.28.0
 */
class Dynamic_Remarketing_Variations_Output extends Opportunity {

	public static function available() {

		// At least one paid ads pixel must be enabled
		if (!Options::is_at_least_one_marketing_pixel_active()) {
			return false;
		}

		// Dynamic Remarketing Variations Output must be disabled
		if (Options::is_dynamic_remarketing_variations_output_enabled()) {
			return false;
		}

		return true;
	}

	public static function card_data() {

		return [
			'id'          => 'dynamic-remarketing-variations-output',
			'title'       => esc_html__(
				'Dynamic Remarketing Variations Output',
				'woocommerce-google-adwords-conversion-tracking-tag'
			),
			'description' => [
				esc_html__(
					'The Pixel Manager detected that at least one paid ads pixel is enabled, Dynamic Remarketing is enabled, but Variations Output has yet to be enabled.',
					'woocommerce-google-adwords-conversion-tracking-tag'
				),
				esc_html__(
					'Enabling Dynamic Remarketing Variations Output will allow you to collect more fine-grained, dynamic audiences down to the product variation level.',
					'woocommerce-google-adwords-conversion-tracking-tag'
				),
				esc_html__(
					'When enabling this setting, you also need to upload product variations to your catalogs.',
					'woocommerce-google-adwords-conversion-tracking-tag'
				),
			],
			'impact'      => esc_html__(
				'low',
				'woocommerce-google-adwords-conversion-tracking-tag'
			),
			'setup_link'  => Documentation::get_link('variations_output'),
			//			'learn_more_link' => '#',
			'since'       => 1672895375, // timestamp
		];
	}
}
