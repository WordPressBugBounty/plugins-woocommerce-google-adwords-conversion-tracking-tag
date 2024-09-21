<?php

namespace SweetCode\Pixel_Manager\Pixels\Facebook;

// TODO disable Yoast SEO Open Graph wp_option: wpseo_social => opengraph => true / false

defined('ABSPATH') || exit; // Exit if accessed directly

class Facebook {

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
