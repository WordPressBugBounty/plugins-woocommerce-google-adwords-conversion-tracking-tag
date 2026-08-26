<?php

namespace SweetCode\Pixel_Manager\Admin\Notifications;

use SweetCode\Pixel_Manager\Helpers;

defined('ABSPATH') || exit; // Exit if accessed directly

/**
 * Google Ads Data Manager Upload Error Notification
 *
 * Surfaces a dashboard warning while the experimental Google Ads conversion
 * upload (Data Manager API) is failing, so a setup problem (wrong account ID,
 * missing manager account, revoked grant) is seen within a day instead of at
 * the end of the experiment.
 *
 * Deliberately reads only the plain last-upload option: this file ships in
 * every build, while the upload implementation itself is premium-only.
 *
 * @since 1.66.0
 */
class Google_DMA_Upload_Error_Notification extends Notification {

	/**
	 * Show only while the most recent upload attempt failed. The option is
	 * rewritten on every attempt, so one later success clears the warning.
	 *
	 * @return bool
	 * @since 1.66.0
	 */
	public static function should_notify() {

		// The feature only exists behind the experiments flag.
		if (!Helpers::is_experiment()) {
			return false;
		}

		// Only show on the WP dashboard. The PMW settings page surfaces the
		// upload status on the card itself.
		if (!Helpers::is_dashboard()) {
			return false;
		}

		$last_upload = get_option('pmw_google_dma_last_upload');

		return is_array($last_upload) && isset($last_upload['status']) && 'error' === $last_upload['status'];
	}

	/**
	 * Get the notification data.
	 *
	 * @return array
	 * @since 1.66.0
	 */
	public static function notification_data() {

		$last_upload = (array) get_option('pmw_google_dma_last_upload');

		$description = [
			__(
				'The most recent Google Ads conversion upload through the Data Manager API failed. Failed orders are retried on their next status change, but while the cause persists no conversions reach Google through the upload.',
				'woocommerce-google-adwords-conversion-tracking-tag'
			),
		];

		if (!empty($last_upload['error_message'])) {
			$description[] = sprintf(
				/* translators: %s: the error message returned by the Google API */
				__('Reported error: %s', 'woocommerce-google-adwords-conversion-tracking-tag'),
				$last_upload['error_message']
			);
		}

		if (!empty($last_upload['order_number'])) {
			$description[] = sprintf(
				/* translators: %s: the order number of the failed upload */
				__('Last affected order: #%s. Use the "Send test upload" button in the settings to verify the fix.', 'woocommerce-google-adwords-conversion-tracking-tag'),
				$last_upload['order_number']
			);
		}

		return [
			'id'              => 'google-dma-upload-error',
			'title'           => __('Google Ads API Conversion Upload - Upload Error', 'woocommerce-google-adwords-conversion-tracking-tag'),
			'description'     => $description,
			'importance'      => __('High', 'woocommerce-google-adwords-conversion-tracking-tag'),
			'settings_link'   => admin_url('admin.php?page=pmw#server-side'),
			'repeat_interval' => DAY_IN_SECONDS, // Re-show daily while uploads keep failing
		];
	}
}
