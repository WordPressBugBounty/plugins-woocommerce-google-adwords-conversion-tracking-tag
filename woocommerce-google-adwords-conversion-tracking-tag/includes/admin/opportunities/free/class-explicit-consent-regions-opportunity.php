<?php

namespace SweetCode\Pixel_Manager\Admin\Opportunities\Free;

use SweetCode\Pixel_Manager\Admin\Documentation;
use SweetCode\Pixel_Manager\Admin\Opportunities\Opportunity;
use SweetCode\Pixel_Manager\Options;

defined('ABSPATH') || exit; // Exit if accessed directly

/**
 * Opportunity: Explicit Consent Regions
 *
 * @since 1.53.0
 */
class Explicit_Consent_Regions extends Opportunity {

	public static function available() {

		// Explicit consent mode must be active
		if (!Options::is_consent_management_explicit_consent_active()) {
			return false;
		}

		// Restricted consent regions must not be set
		if (Options::are_restricted_consent_regions_set()) {
			return false;
		}

		return true;
	}

	public static function card_data() {

		return [
			'id'              => 'explicit-consent-regions',
			'title'           => esc_html__(
				'Explicit Consent Regions',
				'woocommerce-google-adwords-conversion-tracking-tag'
			),
			'description'     => [
				esc_html__(
					'The Pixel Manager detected that Explicit Consent Mode is enabled globally but no consent regions are configured.',
					'woocommerce-google-adwords-conversion-tracking-tag'
				),
				esc_html__(
					'Setting explicit consent regions limits strict consent enforcement to specific areas (e.g. EU, UK, Switzerland) while allowing full, unhindered tracking everywhere else. This significantly improves data accuracy outside those regions.',
					'woocommerce-google-adwords-conversion-tracking-tag'
				),
				esc_html__(
					'Pre-built region buckets are available (e.g. "European Union") so you don\'t have to add each country manually.',
					'woocommerce-google-adwords-conversion-tracking-tag'
				),
			],
			'impact'          => esc_html__(
				'high',
				'woocommerce-google-adwords-conversion-tracking-tag'
			),
			'setup_link'      => Documentation::get_link('restricted_consent_regions'),
			'since'           => 1733529600, // timestamp: December 7, 2025
		];
	}
}
