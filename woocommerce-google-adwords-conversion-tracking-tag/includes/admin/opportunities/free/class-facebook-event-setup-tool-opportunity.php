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

			if (!empty($finding['rules'])) {
				$descriptions[] = sprintf(
					/* translators: 1: the Meta pixel ID, 2: comma separated list of events with their rule IDs */
					esc_html__(
						'Pixel %1$s fires these additional events: %2$s',
						'woocommerce-google-adwords-conversion-tracking-tag'
					),
					$finding['pixel_id'],
					implode(', ', $finding['rules'])
				);
			}

			if (!empty($finding['extractors'])) {
				$descriptions[] = sprintf(
					/* translators: 1: the Meta pixel ID, 2: comma separated list of events with their extractor IDs and URLs */
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
			'To fix this, open the Meta Events Manager and remove the rules under: Data sources > select your pixel > Settings > Event setup > Manage. The list there only shows the rules of the URL the Event Setup Tool is opened on, so open it on the URL listed above. The Pixel Manager already tracks all shop events with deduplication and accurate values.',
			'woocommerce-google-adwords-conversion-tracking-tag'
		);

		$descriptions[] = esc_html__(
			'Meta sometimes keeps delivering rules that were already deleted. If a rule listed here no longer appears in the Events Manager, only Meta support can remove it. Send them the IDs above and tell them the rules are still delivered as active in the public pixel configuration.',
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
	 * The rule and extractor IDs are part of the description because Meta
	 * support needs them whenever a rule survives its deletion in the Events
	 * Manager, and the extractor URL because the Event Setup Tool only lists
	 * the entries of the URL it is opened on.
	 *
	 * @return array Entries with the keys pixel_id, rules and extractors, both
	 *               as ready to print label strings.
	 */
	private static function get_findings() {

		$results = Facebook_Event_Setup_Scan::get_scan_results();

		$findings = [];

		if (!is_array($results) || empty($results['pixels'])) {
			return $findings;
		}

		foreach ($results['pixels'] as $pixel_id => $pixel) {

			$rules      = self::get_rule_labels($pixel);
			$extractors = self::get_extractor_labels($pixel);

			if (empty($rules) && empty($extractors)) {
				continue;
			}

			$findings[] = [
				'pixel_id'   => $pixel_id,
				'rules'      => $rules,
				'extractors' => $extractors,
			];
		}

		return $findings;
	}

	/**
	 * Get one label per active rule, e.g. "Purchase (rule ID 1354287195403260)".
	 *
	 * @param array $pixel One entry of the 'pixels' array of the scan results.
	 * @return array
	 * @since 1.66.1
	 */
	private static function get_rule_labels( $pixel ) {

		$labels = [];

		if (empty($pixel['active_rules'])) {
			return $labels;
		}

		foreach ($pixel['active_rules'] as $rule) {

			if (empty($rule['event'])) {
				continue;
			}

			$labels[] = empty($rule['rule_id'])
				? $rule['event']
				: sprintf(
					/* translators: 1: the event name, 2: the Meta Event Setup Tool rule ID */
					esc_html__('%1$s (rule ID %2$s)', 'woocommerce-google-adwords-conversion-tracking-tag'),
					$rule['event'],
					$rule['rule_id']
				);
		}

		return $labels;
	}

	/**
	 * Get one label per value extractor, e.g. "Purchase (extractor ID 959937071660420 on https://example.com/checkout/)".
	 *
	 * @param array $pixel One entry of the 'pixels' array of the scan results.
	 * @return array
	 * @since 1.66.1
	 */
	private static function get_extractor_labels( $pixel ) {

		$labels = [];

		if (empty($pixel['iwl_extractors'])) {
			return $labels;
		}

		foreach ($pixel['iwl_extractors'] as $extractor) {

			if (empty($extractor['event'])) {
				continue;
			}

			if (empty($extractor['extractor_id'])) {
				$labels[] = $extractor['event'];
				continue;
			}

			if (empty($extractor['url'])) {
				$labels[] = sprintf(
					/* translators: 1: the event name, 2: the Meta Event Setup Tool extractor ID */
					esc_html__('%1$s (extractor ID %2$s)', 'woocommerce-google-adwords-conversion-tracking-tag'),
					$extractor['event'],
					$extractor['extractor_id']
				);
				continue;
			}

			$labels[] = sprintf(
				/* translators: 1: the event name, 2: the Meta Event Setup Tool extractor ID, 3: the URL the extractor is scoped to */
				esc_html__('%1$s (extractor ID %2$s on %3$s)', 'woocommerce-google-adwords-conversion-tracking-tag'),
				$extractor['event'],
				$extractor['extractor_id'],
				$extractor['url']
			);
		}

		return $labels;
	}
}
