<?php

namespace SweetCode\Pixel_Manager\Admin\Notifications;

use SweetCode\Pixel_Manager\Click_Ids;

defined('ABSPATH') || exit; // Exit if accessed directly

/**
 * Cookie Stripping Notification
 *
 * Some hosts remove every cookie they do not know before a request reaches
 * WordPress. Pantheon's edge does this for all requests. The visitor's
 * browser holds PMW's cookies, but PHP never receives them, so server-side
 * tracking cannot read the consent decision or the click IDs stored there
 * and falls back to the WooCommerce session, which concurrent requests can
 * overwrite. Nothing in the admin showed this before.
 *
 * PMW does not rename its cookies to fit a host's allow list (DD-045). The
 * host, or the shop on its plan, has to let them through, so this notice
 * says exactly that.
 *
 * The measurement only exists in Pro, which writes and reads the cookie.
 *
 * @since 1.69.3
 */
class Cookie_Stripping_Notification extends Notification {

	/**
	 * Show the notice while the latest measurement found the cookie removed.
	 * A host that starts letting it through clears the notice within a week,
	 * when the next sample reaches its verdict.
	 *
	 * @return bool
	 * @since 1.69.3
	 */
	public static function should_notify() {
		return class_exists('SweetCode\Pixel_Manager\Click_Ids') && Click_Ids::is_consent_cookie_stripped();
	}

	/**
	 * Get the notification data.
	 *
	 * @return array
	 * @since 1.69.3
	 */
	public static function notification_data() {

		return [
			'id'              => 'cookie-stripping',
			'title'           => __('Your host removes the Pixel Manager cookies', 'woocommerce-google-adwords-conversion-tracking-tag'),
			'description'     => [
				__(
					'Your visitors\' browsers hold the Pixel Manager cookies, but the requests reach WordPress without them. A caching or security layer of your host removes cookies it does not know, which some hosts, for example Pantheon, do by default.',
					'woocommerce-google-adwords-conversion-tracking-tag'
				),
				__(
					'Server-side tracking then cannot read the visitor\'s consent decision and click IDs from these cookies, and has to rely on the shop session, which is less reliable. Please ask your host to pass all cookies that start with pmw_ through to WordPress. The documentation lists the cookies and what to ask for.',
					'woocommerce-google-adwords-conversion-tracking-tag'
				),
			],
			'importance'      => __('High', 'woocommerce-google-adwords-conversion-tracking-tag'),
			'learn_more_link' => 'https://sweetcode.com/docs/pmw/caching-and-optimization#hosts-that-remove-cookies',
			'repeat_interval' => WEEK_IN_SECONDS,
		];
	}
}
