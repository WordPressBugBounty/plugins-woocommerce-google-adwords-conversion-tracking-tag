<?php

namespace SweetCode\Pixel_Manager\Admin\Opportunities\Free;

use SweetCode\Pixel_Manager\Admin\Documentation;
use SweetCode\Pixel_Manager\Admin\Facebook_Event_Setup_Scan;
use SweetCode\Pixel_Manager\Admin\Opportunities\Opportunity;

defined('ABSPATH') || exit; // Exit if accessed directly

/**
 * Opportunity: Meta Event Setup Tool rules detected
 *
 * Warns when the Meta (Facebook) pixel carries active Event Setup Tool rules
 * or value extractors. Those rules are configured point-and-click in the Meta
 * Events Manager, are delivered to every browser through the public signals
 * config file, and fire additional events (e.g. a Purchase on a button click)
 * without an event ID and usually without a value. They cannot be deduplicated
 * against the events the Pixel Manager sends, so they inflate event counts and
 * corrupt purchase values in Meta.
 *
 * Reference: https://secure.helpscout.net/conversation/3309525073
 *
 * @since 1.63.1
 */
class Facebook_Event_Setup_Tool extends Opportunity {

	/**
	 * Check if the opportunity is available.
	 * Available if the signals config scan found active Event Setup Tool rules
	 * or value extractors on at least one configured Facebook pixel.
	 *
	 * @return bool
	 */
	public static function available() {
		return Facebook_Event_Setup_Scan::has_findings(Facebook_Event_Setup_Scan::get_scan_results());
	}

	/**
	 * Get the card data for this opportunity.
	 *
	 * @return array
	 */
	public static function card_data() {

		$descriptions = [
			esc_html__(
				'The Pixel Manager detected active Event Setup Tool rules on your Meta (Facebook) pixel. These are point-and-click rules that were created in the Meta Events Manager, are stored on the pixel itself, and fire additional browser events, for example a Purchase event when a visitor clicks a button.',
				'woocommerce-google-adwords-conversion-tracking-tag'
			),
			esc_html__(
				'These events bypass the Pixel Manager. They fire without an event ID, so Meta cannot deduplicate them against the events the Pixel Manager already sends, and they usually carry no value. The result is inflated event counts and inaccurate purchase values in Meta.',
				'woocommerce-google-adwords-conversion-tracking-tag'
			),
		];

		foreach (self::get_findings() as $finding) {

			if (!empty($finding['events'])) {
				$descriptions[] = sprintf(
					/* translators: 1: the Meta pixel ID, 2: comma separated list of event names */
					esc_html__(
						'Pixel %1$s fires these additional events: %2$s',
						'woocommerce-google-adwords-conversion-tracking-tag'
					),
					$finding['pixel_id'],
					implode(', ', $finding['events'])
				);
			}

			if (!empty($finding['extractors'])) {
				$descriptions[] = sprintf(
					/* translators: 1: the Meta pixel ID, 2: comma separated list of event names */
					esc_html__(
						'Pixel %1$s also has Event Setup Tool value extraction rules for these events: %2$s',
						'woocommerce-google-adwords-conversion-tracking-tag'
					),
					$finding['pixel_id'],
					implode(', ', $finding['extractors'])
				);
			}
		}

		$descriptions[] = esc_html__(
			'To fix this, open the Meta Events Manager and remove the rules under: Data sources > select your pixel > Settings > Event setup > Manage. The Pixel Manager already tracks all shop events with deduplication and accurate values.',
			'woocommerce-google-adwords-conversion-tracking-tag'
		);

		return [
			'id'             => 'facebook-event-setup-tool',
			'title'          => esc_html__(
				'Meta Event Setup Tool Rules Detected',
				'woocommerce-google-adwords-conversion-tracking-tag'
			),
			'description'    => $descriptions,
			'impact'         => 'high',
			'custom_buttons' => [
				[
					'label'  => esc_html__('Open Meta Events Manager', 'woocommerce-google-adwords-conversion-tracking-tag'),
					'url'    => 'https://business.facebook.com/events_manager2/',
					'target' => '_blank',
				],
			],
			'learn_more_link' => Documentation::get_link('facebook_event_setup_tool'),
			'since'           => 1784332800, // July 18, 2026 timestamp
			'repeat_interval' => MONTH_IN_SECONDS, // Re-show after 1 month if still applicable
		];
	}

	/**
	 * Get the scan findings, one entry per pixel that has rules or extractors.
	 *
	 * @return array Entries with the keys pixel_id, events and extractors.
	 */
	private static function get_findings() {

		$results = Facebook_Event_Setup_Scan::get_scan_results();

		$findings = [];

		if (!is_array($results) || empty($results['pixels'])) {
			return $findings;
		}

		foreach ($results['pixels'] as $pixel_id => $pixel) {

			$events     = Facebook_Event_Setup_Scan::get_active_rule_event_names($pixel);
			$extractors = !empty($pixel['iwl_extractors']) ? $pixel['iwl_extractors'] : [];

			if (empty($events) && empty($extractors)) {
				continue;
			}

			$findings[] = [
				'pixel_id'   => $pixel_id,
				'events'     => $events,
				'extractors' => $extractors,
			];
		}

		return $findings;
	}
}
