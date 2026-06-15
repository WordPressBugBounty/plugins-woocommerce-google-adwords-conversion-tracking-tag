<?php

namespace SweetCode\Pixel_Manager\Admin\Notifications;

use SweetCode\Pixel_Manager\Helpers;
use SweetCode\Pixel_Manager\Options;

defined('ABSPATH') || exit; // Exit if accessed directly

/**
 * SSP Destination Error Notification
 *
 * Surfaces a partial-sync warning when one or more SweetCode Cloud (SSP)
 * destinations fail their credential-validation probe during sync. On every
 * sync the SSP fires a test event against each destination and reports back
 * which ones passed. A destination with bad credentials or missing permissions
 * is flagged here, while all correctly-configured destinations keep working.
 *
 * @since 1.59.0
 */
class SSP_Destination_Error_Notification extends Notification {

	/**
	 * Check if the notification should be shown.
	 *
	 * Requirements:
	 * - SSP is active (enabled, synced, routing)
	 * - At least one destination failed its last credential-validation probe
	 * - User is on the WP dashboard
	 *
	 * The PMW settings page renders this warning natively inside the Nova UI
	 * (Dashboard tab and Server-Side tab), so the admin notice is suppressed there.
	 *
	 * @return bool
	 * @since 1.59.0
	 */
	public static function should_notify() {

		// Only show on the WP dashboard. The PMW settings page surfaces this
		// warning inside the app UI instead.
		if ( ! Helpers::is_dashboard() ) {
			return false;
		}

		// SSP must be active
		if ( ! Options::is_ssp_active() ) {
			return false;
		}

		// At least one destination must have failed its probe
		return ! empty( Options::get_ssp_failed_destinations() );
	}

	/**
	 * Get the notification data.
	 *
	 * @return array
	 * @since 1.59.0
	 */
	public static function notification_data() {

		$failed = Options::get_ssp_failed_destinations();

		$names = array_map(
			static function ( $result ) {
				return self::destination_label( $result['type'] );
			},
			$failed
		);
		$names = array_values( array_unique( $names ) );

		$description = [
			sprintf(
				/* translators: %s: comma-separated list of ad platform names */
				__(
					'SweetCode Cloud could not validate the credentials for the following destination(s) during the last sync: %s. Conversions for these destinations are not being delivered.',
					'woocommerce-google-adwords-conversion-tracking-tag'
				),
				implode( ', ', $names )
			),
			__(
				'All other destinations with valid credentials continue to track normally.',
				'woocommerce-google-adwords-conversion-tracking-tag'
			),
		];

		// Append the specific error message for each failed destination.
		foreach ( $failed as $result ) {
			if ( empty( $result['error'] ) ) {
				continue;
			}
			$description[] = sprintf(
				'%s: %s',
				self::destination_label( $result['type'] ),
				$result['error']
			);
		}

		$description[] = __(
			'Re-check the access token and pixel/source ID for the affected destination(s), then click "Sync Now" in the SSP settings.',
			'woocommerce-google-adwords-conversion-tracking-tag'
		);

		return [
			'id'              => 'ssp-destination-error',
			'title'           => __( 'SweetCode Cloud – Destination Credential Error', 'woocommerce-google-adwords-conversion-tracking-tag' ),
			'description'     => $description,
			'importance'      => __( 'High', 'woocommerce-google-adwords-conversion-tracking-tag' ),
			'settings_link'   => admin_url( 'admin.php?page=pmw&section=sse' ),
			'portal_link'     => 'https://portal.sweetcode.cloud',
			'learn_more_link' => 'https://sweetcode.cloud',
			'repeat_interval' => DAY_IN_SECONDS, // Re-show daily while a destination is failing
		];
	}

	/**
	 * Map an internal destination type to a human-readable platform name.
	 *
	 * @param string $type Destination type (e.g. "facebook").
	 * @return string
	 * @since 1.59.0
	 */
	private static function destination_label( $type ) {

		$labels = [
			'facebook'   => 'Meta (Facebook)',
			'tiktok'     => 'TikTok',
			'pinterest'  => 'Pinterest',
			'snapchat'   => 'Snapchat',
			'reddit'     => 'Reddit',
			'google_ga4' => 'Google Analytics 4',
		];

		return isset( $labels[ $type ] ) ? $labels[ $type ] : ucfirst( str_replace( '_', ' ', (string) $type ) );
	}
}
