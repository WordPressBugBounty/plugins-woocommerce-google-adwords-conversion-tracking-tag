<?php

namespace SweetCode\Pixel_Manager\Admin\Notifications;

defined('ABSPATH') || exit; // Exit if accessed directly

/**
 * Abstract class Notification
 *
 * @since 1.42.5
 */
abstract class Notification {

	/**
	 * Check if the notification is available.
	 *
	 * @return bool
	 * @since 1.42.5
	 */
	abstract public static function should_notify();

	public static function not_available() {
		return !static::should_notify();
	}

	abstract public static function notification_data();

	public static function output_notification() {

		if (static::not_available()) {
			return;
		}

		$notification_data              = static::notification_data();
		$notification_data['dismissed'] = static::is_dismissed();

		Notifications::notification_html($notification_data);
	}

	public static function is_dismissed() {

		$option = get_option(PMW_DB_NOTIFICATIONS_NAME);

		// If the option is empty, we can return false
		if (empty($option)) {
			return false;
		}

		$notification_data = static::notification_data();

		// Check if the notification is dismissed
		// If not, we can return false
		if (!isset($option[$notification_data['id']]['dismissed'])) {
			return false;
		}

		// Check if 'repeat_interval' key exists, if not we can return true
		if (!isset($notification_data['repeat_interval'])) {
			return true;
		}

		// Check if the current time is less than the dismissed time plus the repeat interval
		if (time() < ( $option[$notification_data['id']]['dismissed'] + $notification_data['repeat_interval'] )) {
			return true;
		}

		return false;
	}

	public static function is_not_dismissed() {
		return !static::is_dismissed();
	}
}
