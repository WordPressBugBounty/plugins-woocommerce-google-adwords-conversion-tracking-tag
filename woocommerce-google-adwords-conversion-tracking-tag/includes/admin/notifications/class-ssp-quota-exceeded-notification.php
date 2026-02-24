<?php

namespace SweetCode\Pixel_Manager\Admin\Notifications;

use SweetCode\Pixel_Manager\Admin\Environment;
use SweetCode\Pixel_Manager\Helpers;
use SweetCode\Pixel_Manager\Options;

defined('ABSPATH') || exit; // Exit if accessed directly

/**
 * SSP Quota Exceeded Notification
 *
 * Displays a high-importance notification when the SSP monthly
 * request quota has been exhausted. Events are either falling back
 * to WooCommerce REST API or being dropped, depending on the
 * proxy_failure_behavior setting.
 *
 * @since 1.57.0
 */
class SSP_Quota_Exceeded_Notification extends Notification {

	/**
	 * Check if the notification should be shown.
	 *
	 * Requirements:
	 * - SSP is active (enabled, synced, routing)
	 * - SSP quota is exceeded
	 * - User is on dashboard or PMW settings page
	 *
	 * @return bool
	 * @since 1.57.0
	 */
	public static function should_notify() {

		// Only show on dashboard or PMW settings page
		if ( ! Helpers::is_dashboard() && ! Environment::is_pmw_settings_page() ) {
			return false;
		}

		// SSP must be active
		if ( ! Options::is_ssp_active() ) {
			return false;
		}

		// Quota must be exceeded
		if ( ! Options::is_ssp_quota_exceeded() ) {
			return false;
		}

		return true;
	}

	/**
	 * Get the notification data.
	 *
	 * @return array
	 * @since 1.57.0
	 */
	public static function notification_data() {

		$behavior = Options::get_ssp_proxy_failure_behavior();

		if ( 'fallback_to_wc' === $behavior ) {
			$fallback_text = __(
				'Events are currently being routed through your WooCommerce server instead. This increases server load and may reduce tracking accuracy.',
				'woocommerce-google-adwords-conversion-tracking-tag'
			);
		} else {
			$fallback_text = __(
				'Server-side events are currently being dropped. Conversion tracking accuracy is reduced until the quota resets or you upgrade your plan.',
				'woocommerce-google-adwords-conversion-tracking-tag'
			);
		}

		return [
			'id'              => 'ssp-quota-exceeded',
			'title'           => __( 'SweetCode Cloud – Monthly Quota Exhausted', 'woocommerce-google-adwords-conversion-tracking-tag' ),
			'description'     => [
				__(
					'Your SweetCode Cloud (SSP) proxy has reached its monthly request limit. Server-side tracking events can no longer be routed through the proxy.',
					'woocommerce-google-adwords-conversion-tracking-tag'
				),
				$fallback_text,
				__(
					'To restore proxy routing, upgrade your plan or click "Sync Now" in the SSP settings after your billing period resets.',
					'woocommerce-google-adwords-conversion-tracking-tag'
				),
			],
			'importance'      => __( 'High', 'woocommerce-google-adwords-conversion-tracking-tag' ),
			'settings_link'   => admin_url( 'admin.php?page=pmw&section=sse' ),
			'portal_link'     => 'https://portal.sweetcode.cloud',
			'learn_more_link' => 'https://sweetcode.cloud',
			'repeat_interval' => DAY_IN_SECONDS, // Re-show daily while quota is exceeded
		];
	}
}
