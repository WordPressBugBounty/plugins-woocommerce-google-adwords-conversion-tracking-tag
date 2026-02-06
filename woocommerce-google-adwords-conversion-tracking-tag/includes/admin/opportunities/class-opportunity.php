<?php

namespace SweetCode\Pixel_Manager\Admin\Opportunities;

defined('ABSPATH') || exit; // Exit if accessed directly

/**
 * Abstract class Opportunity
 *
 * @since 1.28.0
 */
abstract class Opportunity {

	/**
	 * Check if the opportunity is available.
	 *
	 * @return bool
	 * @since 1.28.0
	 */
	abstract public static function available();

	public static function not_available() {
		return !static::available();
	}

	abstract public static function card_data();

	public static function custom_middle_cart_html() {
		return null;
	}

	public static function output_card() {

		if (static::not_available()) {
			return;
		}

		$card_data              = static::card_data();
		$card_data['dismissed'] = static::is_dismissed();

		Opportunities::card_html($card_data, static::custom_middle_cart_html());
	}

	public static function is_dismissed() {

		$option = get_option(Opportunities::$pmw_opportunities_option);

		if (empty($option)) {
			return false;
		}

		$card_data = static::card_data();

		// Check if dismissed key exists
		if (!isset($option[$card_data['id']]['dismissed'])) {
			return false;
		}

		// If no repeat_interval defined, stay dismissed forever
		if (!isset($card_data['repeat_interval'])) {
			return true;
		}

		// Check if current time is still within the repeat interval
		if (time() < ( $option[$card_data['id']]['dismissed'] + $card_data['repeat_interval'] )) {
			return true; // Still within cooldown period
		}

		// Cooldown expired, show opportunity again
		return false;
	}

	public static function is_not_dismissed() {
		return !static::is_dismissed();
	}

	public static function is_newer_than_dismissed_dashboard_time( $option ) {

		if (empty($option)) {
			return true;
		}

		if (!isset($option['dashboard_notification_dismissed'])) {
			return true;
		}

		if (static::card_data()['since'] > $option['dashboard_notification_dismissed']) {
			return true;
		}

		return false;
	}
}
