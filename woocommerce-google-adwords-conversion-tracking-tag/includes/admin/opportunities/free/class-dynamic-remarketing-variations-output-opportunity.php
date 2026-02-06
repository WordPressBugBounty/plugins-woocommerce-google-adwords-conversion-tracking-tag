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
					'Variations Output is disabled. Enabling it allows dynamic remarketing audiences at the variation level for more precise retargeting.',
					'woocommerce-google-adwords-conversion-tracking-tag'
				),
				esc_html__(
					'This setting is off by default because it must match your product feed: if you upload parent products, keep it disabled; if you upload variations, enable it. We cannot detect your feed setup automatically.',
					'woocommerce-google-adwords-conversion-tracking-tag'
				),
			],
			'impact'      => esc_html__(
				'medium',
				'woocommerce-google-adwords-conversion-tracking-tag'
			),
			'setup_link'  => Documentation::get_link('variations_output'),
			//			'learn_more_link' => '#',
			'since'       => 1672895375, // timestamp
		];
	}
}
