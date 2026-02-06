<?php

namespace SweetCode\Pixel_Manager\Admin\Opportunities\Free;

use SweetCode\Pixel_Manager\Admin\Documentation;
use SweetCode\Pixel_Manager\Admin\Opportunities\Opportunity;
use SweetCode\Pixel_Manager\Options;

defined('ABSPATH') || exit; // Exit if accessed directly

/**
 * Opportunity: Disable Deprecated Functions
 *
 * @since 1.53.0
 */
class Disable_Deprecated_Functions extends Opportunity {

	public static function available() {

		// Deprecated functions must be enabled (which is the default)
		if (!Options::should_load_deprecated_functions()) {
			return false;
		}

		return true;
	}

	public static function card_data() {

		return [
			'id'          => 'disable-deprecated-functions',
			'title'       => esc_html__(
				'Disable Deprecated Functions Module',
				'woocommerce-google-adwords-conversion-tracking-tag'
			),
			'description' => [
				esc_html__(
					'The deprecated functions module is currently enabled. Disabling it reduces front-end JavaScript size.',
					'woocommerce-google-adwords-conversion-tracking-tag'
				),
				esc_html__(
					'Only disable this if you are not using custom code that relies on deprecated Pixel Manager functions. Enable the logger and check the browser console for deprecation warnings to verify.',
					'woocommerce-google-adwords-conversion-tracking-tag'
				),
			],
			'impact'      => esc_html__(
				'low',
				'woocommerce-google-adwords-conversion-tracking-tag'
			),
			'setup_link'  => Documentation::get_link('load_deprecated_functions'),
			'since'       => 1733529600, // timestamp: December 7, 2025
		];
	}
}
