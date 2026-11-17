<?php

namespace SweetCode\Pixel_Manager\Admin\Notifications;

use SweetCode\Pixel_Manager\Optimizely_Security;

defined('ABSPATH') || exit; // Exit if accessed directly

/**
 * Optimizely Precaution Notification
 *
 * Shown on sites where the upgrade to 1.74.1 paused Optimizely after the
 * Optimizely security incident of 16 November 2026. It says why, what to
 * check before re-enabling, and goes away for good once Optimizely is
 * re-enabled or the notice is dismissed (DD-092).
 *
 * Wording rule: Optimizely reported the incident as contained, never call it
 * fixed or resolved, and never imply that the Pixel Manager was compromised.
 *
 * @since 1.73.1
 */
class Optimizely_Precaution_Notification extends Notification {

	/**
	 * Show the notice while the upgrade's pause is in place and Optimizely
	 * has not been re-enabled.
	 *
	 * @return bool
	 * @since 1.73.1
	 */
	public static function should_notify() {
		return Optimizely_Security::is_incident_disabled();
	}

	/**
	 * Get the notification data.
	 *
	 * No repeat interval: once dismissed it stays dismissed.
	 *
	 * @return array
	 * @since 1.73.1
	 */
	public static function notification_data() {

		return [
			'id'              => 'optimizely-incident-2026-11',
			'title'           => __('Optimizely integration paused for security', 'woocommerce-google-adwords-conversion-tracking-tag'),
			'description'     => [
				__(
					'On 16 November 2026, attackers injected malicious code into some Optimizely projects. Optimizely reports the incident as contained. We turned the integration off as a precaution.',
					'woocommerce-google-adwords-conversion-tracking-tag'
				),
				__(
					'Before re-enabling, check your Optimizely Project JavaScript for code you do not recognise. Then enable Optimizely again in the Pixel Manager settings.',
					'woocommerce-google-adwords-conversion-tracking-tag'
				),
			],
			'importance'      => __('High', 'woocommerce-google-adwords-conversion-tracking-tag'),
			'learn_more_link' => 'https://sweetcode.com/docs/pmw/plugin-configuration/optimizely',
		];
	}
}
