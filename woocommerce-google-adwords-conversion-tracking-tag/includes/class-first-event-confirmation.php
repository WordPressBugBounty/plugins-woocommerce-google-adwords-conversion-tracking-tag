<?php
/**
 * First-event confirmation for the first-run onboarding.
 *
 * Gives a fresh install a fast, truthful "it works" signal in two stages:
 *
 * Stage A - "pixels are live on your storefront": while the onboarding
 * checklist is showing and nothing has been confirmed yet, the public bundle
 * reports once which pixels actually loaded on a storefront visit (a one-shot
 * beacon to a public REST route). The result is written to an option exactly
 * once, after which the data-layer flag disappears and the route rejects, so
 * the steady-state cost for every install is zero.
 *
 * Stage B - "first order tracked": a rider on the existing purchase
 * confirmation beacon (process_conversion_pixel_status). The first time an
 * order reports its conversion pixels as fired on a fresh install, the order
 * id is recorded once and the dashboard celebrates a verified end-to-end
 * setup. Per product decision this fires even when the checklist itself was
 * dismissed: dismissing means "stop walking me through setup", not "don't
 * tell me my setup is verified".
 *
 * No visitor data is stored in either stage: pixel slugs, a page type, a
 * consent snapshot, an order id, timestamps.
 *
 * @package SweetCode\Pixel_Manager
 * @since 1.61.2
 */

namespace SweetCode\Pixel_Manager;

use SweetCode\Pixel_Manager\Admin\Onboarding;

defined('ABSPATH') || exit; // Exit if accessed directly

class First_Event_Confirmation {

	/**
	 * Option holding the Stage A result:
	 * [ 'pixels' => string[], 'confirmed' => string[], 'consent' => array, 'page_type' => string, 'timestamp' => int ]
	 *
	 * 'pixels' is every active pixel the storefront reported (snippet
	 * injected), 'confirmed' the subset whose vendor script load was
	 * positively detected (gtag.js onload, fbevents callMethod).
	 *
	 * @var string
	 */
	public static $pixels_live_option = 'pmw_first_pixel_confirmation';

	/**
	 * Option holding the Stage B result:
	 * [ 'order_id' => int, 'timestamp' => int, 'celebration_dismissed' => int|absent ]
	 *
	 * @var string
	 */
	public static $first_order_option = 'pmw_first_tracked_order';

	/**
	 * Whether the storefront beacon should run: fresh install, checklist not
	 * dismissed, at least one pixel active, and nothing confirmed yet. This
	 * gates both the data-layer flag and the REST route, so no beacon code
	 * ships to visitors on established installs.
	 *
	 * @return bool
	 */
	public static function is_beacon_needed() {

		if (!Onboarding::is_eligible()) {
			return false;
		}

		// Stage A feeds the checklist strip; once the checklist is dismissed
		// nobody would see the result, so stop probing.
		$onboarding = get_option(Onboarding::$option_name, []);
		if (!empty($onboarding['dismissed'])) {
			return false;
		}

		if (get_option(self::$pixels_live_option)) {
			return false;
		}

		return !empty(\SweetCode\Pixel_Manager\Pixels\Core\Pixel_Registry::get_active_pixels());
	}

	/**
	 * Record the Stage A beacon payload. Write-once: returns false without
	 * touching anything when the beacon is no longer needed (already
	 * confirmed, checklist dismissed, not a fresh install).
	 *
	 * @param array $data Raw (sanitized upstream) request payload.
	 * @return bool Whether the confirmation was recorded.
	 */
	public static function record_pixels_live( $data ) {

		if (!self::is_beacon_needed()) {
			return false;
		}

		$pixels    = self::sanitize_slugs(isset($data['pixels']) ? $data['pixels'] : []);
		$confirmed = self::sanitize_slugs(isset($data['confirmed']) ? $data['confirmed'] : []);

		if (empty($pixels)) {
			return false;
		}

		$consent = [];
		if (isset($data['consent']) && is_array($data['consent'])) {
			foreach ([ 'marketing', 'statistics' ] as $category) {
				if (isset($data['consent'][$category])) {
					$consent[$category] = (bool) $data['consent'][$category];
				}
			}
		}

		$page_type = isset($data['page_type']) && is_string($data['page_type'])
			? substr(sanitize_key($data['page_type']), 0, 32)
			: '';

		update_option(
			self::$pixels_live_option,
			[
				'pixels'    => $pixels,
				'confirmed' => array_values(array_intersect($confirmed, $pixels)),
				'consent'   => $consent,
				'page_type' => $page_type,
				'timestamp' => time(),
			],
			false
		);

		return true;
	}

	/**
	 * Record the Stage B result once. Called from the purchase confirmation
	 * beacon handler for every tracked order; the option guard makes it a
	 * single cheap get_option() in the steady state, and the fresh-install
	 * marker keeps established shops out entirely.
	 *
	 * @param int $order_id The tracked order.
	 * @return void
	 */
	public static function record_first_tracked_order( $order_id ) {

		if (get_option(self::$first_order_option)) {
			return;
		}

		// Deliberately NOT gated on the checklist dismissal (see class doc).
		if (!Onboarding::is_eligible()) {
			return;
		}

		update_option(
			self::$first_order_option,
			[
				'order_id'  => (int) $order_id,
				'timestamp' => time(),
			],
			false
		);
	}

	/**
	 * Hide the Stage B celebration card (persisted).
	 *
	 * @return bool Whether there was a celebration to dismiss.
	 */
	public static function dismiss_celebration() {

		$state = get_option(self::$first_order_option);

		if (!is_array($state)) {
			return false;
		}

		$state['celebration_dismissed'] = time();
		update_option(self::$first_order_option, $state, false);

		return true;
	}

	/**
	 * Snapshot for the Nova admin UI (pmwAdminApi and the dashboard poll).
	 *
	 * @return array {
	 *     beaconPending: bool   Stage A still waiting for a storefront visit.
	 *     pixelsLive:    array|null
	 *     firstOrder:    array|null
	 * }
	 */
	public static function get_data_for_nova() {

		$pixels_live = get_option(self::$pixels_live_option);
		$first_order = get_option(self::$first_order_option);

		return [
			'beaconPending' => self::is_beacon_needed(),
			'pixelsLive'    => is_array($pixels_live) ? [
				'pixels'    => isset($pixels_live['pixels']) ? $pixels_live['pixels'] : [],
				'confirmed' => isset($pixels_live['confirmed']) ? $pixels_live['confirmed'] : [],
				'timestamp' => isset($pixels_live['timestamp']) ? (int) $pixels_live['timestamp'] : 0,
			] : null,
			'firstOrder'    => is_array($first_order) ? [
				'orderId'              => isset($first_order['order_id']) ? (int) $first_order['order_id'] : 0,
				'timestamp'            => isset($first_order['timestamp']) ? (int) $first_order['timestamp'] : 0,
				'celebrationDismissed' => !empty($first_order['celebration_dismissed']),
			] : null,
		];
	}

	/**
	 * Reduce a submitted slug list to a short, safely-keyed, de-duplicated
	 * set. The beacon route is public, so nothing unvalidated may reach the
	 * option: slugs are pattern-checked and the list is capped well above
	 * the real pixel count.
	 *
	 * @param mixed $slugs Raw request value.
	 * @return string[]
	 */
	private static function sanitize_slugs( $slugs ) {

		if (!is_array($slugs)) {
			return [];
		}

		$clean = [];

		foreach (array_slice($slugs, 0, 30) as $slug) {

			if (!is_string($slug)) {
				continue;
			}

			$slug = sanitize_key($slug);

			if ('' !== $slug && strlen($slug) <= 32 && !in_array($slug, $clean, true)) {
				$clean[] = $slug;
			}
		}

		return $clean;
	}
}
