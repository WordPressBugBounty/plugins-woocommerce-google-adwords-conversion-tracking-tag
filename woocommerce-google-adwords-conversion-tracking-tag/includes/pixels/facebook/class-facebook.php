<?php

namespace SweetCode\Pixel_Manager\Pixels\Facebook;

use SweetCode\Pixel_Manager\Options;

defined('ABSPATH') || exit; // Exit if accessed directly

class Facebook {

	/**
	 * Get all Facebook pixel identifiers.
	 *
	 * The list is seeded with the pixel that is configured in the settings.
	 * Additional pixels can be added with the pmw_facebook_pixel_identifiers filter.
	 *
	 * Each entry is an array with the following keys:
	 * - pixel_id        (string, required) The Facebook pixel ID.
	 * - capi_token      (string, optional) A Conversion API access token. If set, server-side events are also sent to this pixel.
	 * - test_event_code (string, optional) A Conversion API test event code for this pixel.
	 *
	 * @return array Array of pixel identifier arrays.
	 *
	 * @since 1.64.0
	 */
	public static function get_pixel_identifiers() {

		$identifiers = [];

		if (Options::get_facebook_pixel_id()) {
			$identifiers[] = [
				'pixel_id'        => Options::get_facebook_pixel_id(),
				'capi_token'      => Options::get_facebook_capi_token(),
				'test_event_code' => Options::get_facebook_capi_test_event_code(),
			];
		}

		/**
		 * Filters the Facebook pixel identifiers.
		 *
		 * Allows adding additional Facebook pixels. All events, browser and Conversion API,
		 * are sent to all pixels. Pixels without a capi_token only receive browser events.
		 *
		 * @param array $identifiers Array of pixel identifier arrays with the keys pixel_id, capi_token (optional) and test_event_code (optional).
		 *
		 * @since 1.64.0
		 */
		$identifiers = apply_filters('pmw_facebook_pixel_identifiers', $identifiers);

		$formatted_identifiers = [];

		foreach ($identifiers as $identifier) {

			if (!is_array($identifier) || empty($identifier['pixel_id'])) {
				continue;
			}

			$pixel_id = trim((string) $identifier['pixel_id']);

			// Facebook pixel IDs are numeric
			if (!preg_match('/^\d{5,20}$/', $pixel_id)) {
				continue;
			}

			if (isset($formatted_identifiers[$pixel_id])) {
				continue;
			}

			$formatted_identifiers[$pixel_id] = [
				'pixel_id'        => $pixel_id,
				'capi_token'      => isset($identifier['capi_token']) ? trim((string) $identifier['capi_token']) : '',
				'test_event_code' => isset($identifier['test_event_code']) ? trim((string) $identifier['test_event_code']) : '',
			];
		}

		return array_values($formatted_identifiers);
	}

	/**
	 * Get all Facebook pixel IDs.
	 *
	 * @return array Array of pixel ID strings.
	 *
	 * @since 1.64.0
	 */
	public static function get_pixel_ids() {

		$pixel_ids = [];

		foreach (self::get_pixel_identifiers() as $identifier) {
			$pixel_ids[] = $identifier['pixel_id'];
		}

		return $pixel_ids;
	}

	/**
	 * Get all Facebook pixel identifiers that can receive Conversion API events.
	 *
	 * @return array Array of pixel identifier arrays with a non-empty capi_token.
	 *
	 * @since 1.64.0
	 */
	public static function get_capi_pixel_identifiers() {

		$capi_identifiers = [];

		foreach (self::get_pixel_identifiers() as $identifier) {
			if ($identifier['capi_token']) {
				$capi_identifiers[] = $identifier;
			}
		}

		return $capi_identifiers;
	}

	/**
	 * Retrieve the standard event names for Facebook tracking.
	 *
	 * This function returns a list of standard event names which are used for event tracking in Facebook pixel.
	 * The events include various user activities like adding a product to the cart, completing registration,
	 * initiating checkout and more.
	 *
	 * Source: https://www.facebook.com/business/help/402791146561655?id=1205376682832142
	 *
	 * @return string[] Array of standard event names.
	 *
	 * @since 1.36.0
	 */
	public static function get_standard_event_names() {
		return [
			'AddPaymentInfo',
			'AddToCart',
			'AddToWishlist',
			'CompleteRegistration',
			'Contact',
			'CustomizeProduct',
			'Donate',
			'FindLocation',
			'InitiateCheckout',
			'Lead',
			'PageView',
			'Purchase',
			'Schedule',
			'Search',
			'StartTrial',
			'SubmitApplication',
			'Subscribe',
			'ViewContent',
		];
	}
}
