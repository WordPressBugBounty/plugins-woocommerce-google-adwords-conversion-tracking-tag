<?php

namespace SweetCode\Pixel_Manager\Admin\Notifications;

use SweetCode\Pixel_Manager\Admin\Environment;

defined('ABSPATH') || exit; // Exit if accessed directly

/**
 * Cache Purge Failure Notification
 *
 * PMW purges every cache layer it can detect after a plugin update, an
 * activation and a settings change, because a stale page cache keeps serving
 * the previous release's tracking library. That library then requests
 * content-hashed chunks the installed release no longer ships, the consent
 * module fails to load, and the library disables itself rather than track
 * without a consent state. Nothing fires, and nothing in the admin says so.
 *
 * Until 1.66.1 a failed purge produced a single Logger::error line, which is
 * only written when the logger is switched on. Ticket 3426583170 ran for days
 * on a NitroPack purge that had never worked on any shop. This notice makes
 * the failure visible to the shop owner instead.
 *
 * @since 1.66.1
 */
class Cache_Purge_Failure_Notification extends Notification {

	/**
	 * Show the notice while the most recent purge run has an unresolved
	 * failure on record. The record is deleted as soon as a later run purges
	 * everything cleanly, so the notice disappears on its own once the shop
	 * owner fixes the cause.
	 *
	 * @return bool
	 * @since 1.66.1
	 */
	public static function should_notify() {
		return !empty(Environment::get_purge_failures());
	}

	/**
	 * Get the notification data.
	 *
	 * @return array
	 * @since 1.66.1
	 */
	public static function notification_data() {

		$failures = Environment::get_purge_failures();

		$names = array_values(array_unique(array_map(
			static function ( $failure ) {
				return $failure['cache'];
			},
			$failures
		)));

		$description = [
			sprintf(
				/* translators: %s: comma-separated list of cache plugin or host names */
				__(
					'The Pixel Manager could not clear the following cache(s) after it was updated: %s. Visitors may still be served the previous version of the tracking library, in which case no tracking runs in their browser at all.',
					'woocommerce-google-adwords-conversion-tracking-tag'
				),
				implode(', ', $names)
			),
			__(
				'Please clear these caches manually, and clear them after every Pixel Manager update until this notice stops appearing. This notice disappears on its own once the caches clear successfully.',
				'woocommerce-google-adwords-conversion-tracking-tag'
			),
		];

		foreach ($failures as $failure) {

			if (empty($failure['reason'])) {
				continue;
			}

			$description[] = $failure['cache'] . ': ' . $failure['reason'];
		}

		return [
			'id'              => 'cache-purge-failure',
			'title'           => __('Cache could not be cleared', 'woocommerce-google-adwords-conversion-tracking-tag'),
			'description'     => $description,
			'importance'      => __('High', 'woocommerce-google-adwords-conversion-tracking-tag'),
			'learn_more_link' => 'https://sweetcode.com/docs/pmw/caching-and-optimization',
			'repeat_interval' => DAY_IN_SECONDS, // Re-show daily while a cache keeps failing to clear
		];
	}
}
