<?php

namespace SweetCode\Pixel_Manager\Admin\Notifications;

use SweetCode\Pixel_Manager\Admin\Documentation;
use SweetCode\Pixel_Manager\Pixels\Google\Google_Helpers;

defined('ABSPATH') || exit; // Exit if accessed directly

/**
 * Google tag ID rejected notification
 *
 * PMW probes gtag.js for each configured Google ID from the server and
 * suppresses an ID for which Google refuses to serve the script. Until
 * 1.69.3 that was only written to the debug log, so a shop could lose Google
 * Ads conversions for a wrong ID with nothing in the admin saying so.
 *
 * Only a definitive answer from Google (400, 404 or 410) suppresses an ID. A
 * failed connection from the server is recorded as unverified instead, does
 * not change tracking and is not surfaced here, because the visitor's browser
 * loads the tag, not the server.
 *
 * Reads the cached probe result only. The admin never probes Google itself.
 *
 * @since 1.69.3
 */
class Google_Tag_ID_Rejected_Notification extends Notification {

	/**
	 * The rejected IDs from the cached probe, ID => reason.
	 *
	 * @return array<string, string>
	 * @since 1.69.3
	 */
	private static function get_rejected_ids() {

		$information = Google_Helpers::get_cached_google_tag_id_information();

		if (empty($information['rejected']) || !is_array($information['rejected'])) {
			return [];
		}

		return $information['rejected'];
	}

	/**
	 * Show the notice while the cached probe has a rejected ID on record. It
	 * disappears on its own once a later probe gets the script.
	 *
	 * @return bool
	 * @since 1.69.3
	 */
	public static function should_notify() {
		return !empty(self::get_rejected_ids());
	}

	/**
	 * Get the notification data.
	 *
	 * The advice depends on the ID type. A rejected Google Ads ID (AW-) is
	 * mistyped or belongs to a deleted account. A rejected GA4 measurement ID
	 * (G-) can be correct: some GA4 data streams are only served through their
	 * Google tag, so the fix is the stream's GT- Google tag ID in the GA4 field.
	 * The Measurement Protocol only accepts the G- ID, so it cannot run then
	 * and the browser tag sends purchases (Options::is_ga4_mp_active()).
	 *
	 * @return array
	 * @since 1.69.3
	 */
	public static function notification_data() {

		$rejected_ids = self::get_rejected_ids();

		$description = [
			__(
				'Google refused to serve its tag for the following Google IDs in the Pixel Manager settings.',
				'woocommerce-google-adwords-conversion-tracking-tag'
			),
		];

		$has_ga4_id   = false;
		$has_other_id = false;

		foreach ($rejected_ids as $tag_id => $reason) {

			$description[] = $tag_id . ': ' . $reason;

			if (0 === strpos((string) $tag_id, 'G-')) {
				$has_ga4_id = true;
			} else {
				$has_other_id = true;
			}
		}

		if ($has_other_id) {
			$description[] = __(
				'A rejected Google Ads ID (AW-) or Google tag ID (GT-) is usually mistyped or belongs to a deleted account or data stream. Google Ads conversions are not sent for a rejected Google Ads ID. Please check the IDs.',
				'woocommerce-google-adwords-conversion-tracking-tag'
			);
		}

		if ($has_ga4_id) {
			$description[] = __(
				'Some GA4 data streams are only served through their Google tag, so Google can refuse a GA4 measurement ID (G-) even when it is correct. Enter the Google tag ID (GT-) of that data stream in the GA4 field instead. You find it in Google Analytics under Admin, Data streams, your web stream, Configure tag settings.',
				'woocommerce-google-adwords-conversion-tracking-tag'
			);
			$description[] = __(
				'The GA4 Measurement Protocol only accepts the G- measurement ID, so with a GT- ID in the GA4 field it cannot run and the browser tag sends purchases to GA4 instead.',
				'woocommerce-google-adwords-conversion-tracking-tag'
			);
		}

		$description[] = __(
			'This notice disappears on its own within an hour of Google serving the tag again.',
			'woocommerce-google-adwords-conversion-tracking-tag'
		);

		return [
			'id'              => 'google-tag-id-rejected',
			'title'           => __('Google did not accept a Google tag ID', 'woocommerce-google-adwords-conversion-tracking-tag'),
			'description'     => $description,
			'importance'      => __('High', 'woocommerce-google-adwords-conversion-tracking-tag'),
			'learn_more_link' => Documentation::get_link($has_other_id ? 'google_ads_conversion_id' : 'google_analytics_4_id'),
			'repeat_interval' => DAY_IN_SECONDS,
		];
	}
}
