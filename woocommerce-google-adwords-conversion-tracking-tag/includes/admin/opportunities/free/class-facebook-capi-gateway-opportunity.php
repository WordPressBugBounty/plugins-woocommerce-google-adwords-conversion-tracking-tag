<?php

namespace SweetCode\Pixel_Manager\Admin\Opportunities\Free;

use SweetCode\Pixel_Manager\Admin\Documentation;
use SweetCode\Pixel_Manager\Admin\Facebook_Event_Setup_Scan;
use SweetCode\Pixel_Manager\Admin\Opportunities\Opportunity;
use SweetCode\Pixel_Manager\Helpers;
use SweetCode\Pixel_Manager\Options;

defined('ABSPATH') || exit; // Exit if accessed directly

/**
 * Opportunity: Meta Conversions API Gateway detected next to the Pixel Manager's CAPI
 *
 * Meta's own Conversions API Gateway (internally called OpenBridge) is a
 * Meta-hosted relay. Once it is connected to a pixel, Meta delivers an
 * openbridge block through the public signals config, fbevents.js loads the
 * openbridge3 plugin, and from then on every event the browser pixel fires is
 * also POSTed to the gateway, which forwards it to Meta as a server event.
 *
 * This is not a data corruption problem: the mirror carries the browser event's
 * own event ID, so Meta deduplicates it, and "Additional conversions reported"
 * stays at 0%. It is a redundancy problem. The gateway can only mirror events
 * the browser already sent, so it contributes nothing for the orders where the
 * browser pixel was blocked or never reached the order confirmation page, which
 * is exactly what the Pixel Manager's Conversions API covers. What merchants do
 * get is a server intake that is a multiple of the browser intake, and a second
 * server-side sender to reason about.
 *
 * The gateway runs on Meta's side, so it is invisible in the WordPress plugin
 * list and survives removing the plugin that once configured it. That is why
 * this card exists: the merchant cannot see it anywhere in WordPress.
 *
 * Only shown when the Pixel Manager's own Conversions API can actually send,
 * because a shop running the gateway instead of it has a working single-sender
 * setup and must not be told to disconnect it.
 *
 * Reference: https://secure.helpscout.net/conversation/3405785588
 *
 * @since 1.66.0
 */
class Facebook_CAPI_Gateway extends Opportunity {

	/**
	 * Check if the opportunity is available.
	 * Available when the signals config scan found an active Conversions API
	 * Gateway on at least one configured Facebook pixel while the Pixel
	 * Manager's own Conversions API is also sending, so the pixel really does
	 * have two server-side senders.
	 *
	 * @return bool
	 */
	public static function available() {

		// A CAPI token survives an expired license, but the sender itself is
		// premium code and is stripped from the free build. Without it the
		// gateway is the shop's only server-side sender, so recommending its
		// removal would leave the shop with no server-side tracking at all.
		if (!Helpers::is_pmw_pro_version_active()) {
			return false;
		}

		if (!Options::is_facebook_capi_active()) {
			return false;
		}

		return Facebook_Event_Setup_Scan::has_openbridge(Facebook_Event_Setup_Scan::get_scan_results());
	}

	/**
	 * Get the card data for this opportunity.
	 *
	 * @return array
	 */
	public static function card_data() {

		$descriptions = [
			esc_html__(
				"The Pixel Manager detected that Meta's own Conversions API Gateway is connected to your Meta (Facebook) pixel while the Pixel Manager's Conversions API is active as well. Your pixel therefore has two server-side senders.",
				'woocommerce-google-adwords-conversion-tracking-tag'
			),
			esc_html__(
				'The Gateway copies every event your browser pixel fires and delivers it to Meta a second time through the server channel. A single order then arrives three times: once from the browser pixel, once as the Gateway copy of that browser event, and once from the Pixel Manager\'s Conversions API. That is what makes your server event count roughly twice your browser event count in Meta Events Manager.',
				'woocommerce-google-adwords-conversion-tracking-tag'
			),
		];

		foreach (self::get_findings() as $finding) {

			if (!empty($finding['events'])) {
				$descriptions[] = sprintf(
					/* translators: 1: the Meta pixel ID, 2: comma separated list of event names */
					esc_html__(
						'Pixel %1$s: the Gateway mirrors these events: %2$s',
						'woocommerce-google-adwords-conversion-tracking-tag'
					),
					$finding['pixel_id'],
					implode(', ', $finding['events'])
				);
			}
		}

		$descriptions[] = esc_html__(
			'Your conversions are not being counted twice. The Gateway copy carries the browser event\'s own event ID, so Meta deduplicates the pair. You can verify this under Events Manager > your event > Event match quality > Additional conversions reported, which should read 0% for Purchase.',
			'woocommerce-google-adwords-conversion-tracking-tag'
		);

		$descriptions[] = esc_html__(
			'Running both is still not worth it. The Gateway can only mirror events the browser already sent, so it adds nothing for the orders where the browser pixel is blocked or never reaches the order confirmation page, and those are exactly the orders the Pixel Manager\'s Conversions API recovers. We recommend disconnecting the Gateway and keeping the Pixel Manager\'s Conversions API: open the Meta Events Manager under Data sources > select your pixel > Settings, and remove the Conversions API Gateway entry. While you are there, remove any stale connections on the same dataset, such as a WordPress connection left over from a Meta tracking plugin you no longer run.',
			'woocommerce-google-adwords-conversion-tracking-tag'
		);

		return [
			'id'             => 'facebook-capi-gateway',
			'title'          => esc_html__(
				'Meta Conversions API Gateway Detected',
				'woocommerce-google-adwords-conversion-tracking-tag'
			),
			'description'    => $descriptions,
			'impact'         => 'medium',
			'custom_buttons' => [
				[
					'label'  => esc_html__('Open Meta Events Manager', 'woocommerce-google-adwords-conversion-tracking-tag'),
					'url'    => 'https://business.facebook.com/events_manager2/',
					'target' => '_blank',
				],
			],
			'learn_more_link' => Documentation::get_link('facebook_capi_gateway'),
			'since'           => 1787702400, // August 26, 2026 timestamp
			'repeat_interval' => MONTH_IN_SECONDS, // Re-show after 1 month if still applicable
		];
	}

	/**
	 * Get the scan findings, one entry per pixel that has an active gateway.
	 *
	 * @return array Entries with the keys pixel_id and events.
	 */
	private static function get_findings() {

		$results = Facebook_Event_Setup_Scan::get_scan_results();

		$findings = [];

		if (!is_array($results) || empty($results['pixels'])) {
			return $findings;
		}

		foreach ($results['pixels'] as $pixel_id => $pixel) {

			if (empty($pixel['openbridge']['active'])) {
				continue;
			}

			$findings[] = [
				'pixel_id' => $pixel_id,
				'events'   => !empty($pixel['openbridge']['mirrored_events']) ? $pixel['openbridge']['mirrored_events'] : [],
			];
		}

		return $findings;
	}
}
