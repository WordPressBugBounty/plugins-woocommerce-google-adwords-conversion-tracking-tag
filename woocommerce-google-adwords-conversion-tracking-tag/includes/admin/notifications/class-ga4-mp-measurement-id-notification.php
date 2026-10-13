<?php

namespace SweetCode\Pixel_Manager\Admin\Notifications;

use SweetCode\Pixel_Manager\Admin\Documentation;
use SweetCode\Pixel_Manager\Helpers;
use SweetCode\Pixel_Manager\Options;

defined('ABSPATH') || exit; // Exit if accessed directly

/**
 * GA4 Measurement Protocol measurement ID notification
 *
 * The GA4 ID field accepts a Google tag ID (GT-) and a Google Ads ID (AW-),
 * because the browser tag works with either. The Measurement Protocol only
 * accepts the G- measurement ID of a web data stream, and the GA4 session
 * cookie it joins purchases to is named after that ID as well. Until 1.69.3 a
 * saved API secret next to a GT- ID sent every purchase server-side without
 * a session, so GA4 reported it under "(not set)", while the browser tag
 * stood down from sending the purchase itself.
 *
 * Options::is_ga4_mp_active() now treats that combination as inactive, so
 * the browser tag keeps sending the purchase. This notice tells the shop
 * owner why the Measurement Protocol is not running and how to fix it.
 *
 * @since 1.69.3
 */
class GA4_MP_Measurement_ID_Notification extends Notification {

	/**
	 * Show the notice while an API secret is saved next to a GA4 ID that the
	 * Measurement Protocol cannot use. It disappears on its own once the ID
	 * is changed to the G- measurement ID.
	 *
	 * @return bool
	 * @since 1.69.3
	 */
	public static function should_notify() {
		return Helpers::is_pmw_pro_version_active() && Options::is_ga4_mp_blocked_by_measurement_id();
	}

	/**
	 * Get the notification data.
	 *
	 * @return array
	 * @since 1.69.3
	 */
	public static function notification_data() {

		return [
			'id'              => 'ga4-mp-measurement-id',
			'title'           => __('GA4 Measurement Protocol needs a G- measurement ID', 'woocommerce-google-adwords-conversion-tracking-tag'),
			'description'     => [
				sprintf(
					/* translators: %s: the configured GA4 ID, for example GT-ABC1234 */
					__(
						'A GA4 API secret is saved, but the GA4 ID in the Pixel Manager settings is %s. The Measurement Protocol only accepts the G- measurement ID of your GA4 web data stream, so it is not running and the browser tag sends purchases instead.',
						'woocommerce-google-adwords-conversion-tracking-tag'
					),
					Options::get_ga4_measurement_id()
				),
				__(
					'To send purchases server-side, enter the measurement ID from GA4 under Admin, Data streams, your web stream. It starts with G-. This notice disappears once the ID is changed.',
					'woocommerce-google-adwords-conversion-tracking-tag'
				),
			],
			'importance'      => __('High', 'woocommerce-google-adwords-conversion-tracking-tag'),
			'learn_more_link' => Documentation::get_link('google_analytics_4_api_secret'),
			'repeat_interval' => WEEK_IN_SECONDS,
		];
	}
}
