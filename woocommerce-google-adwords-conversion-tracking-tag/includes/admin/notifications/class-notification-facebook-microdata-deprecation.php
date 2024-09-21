<?php

use SweetCode\Pixel_Manager\Admin\Documentation;
use SweetCode\Pixel_Manager\Admin\Notifications\Notification;
use SweetCode\Pixel_Manager\Options;

defined('ABSPATH') || exit; // Exit if accessed directly


class Facebook_Microdata_Deprecation extends Notification {

	public static function should_notify() {

		if (Options::is_facebook_microdata_active()) {
			return true;
		}

		return false;
	}

	public static function notification_data() {

		return [
			'id'              => 'facebook-microdata-deprecation',
			'title'           => esc_html__(
				'Facebook Microdata Deprecation Notice',
				'woocommerce-google-adwords-conversion-tracking-tag'
			),
			'description'     => [
				esc_html__(
					'The Pixel Manager detected that the Facebook Microdata output is active. We have deprecated this feature and recommend using a dedicated product feed plugin for this purpose.',
					'woocommerce-google-adwords-conversion-tracking-tag'
				),
				esc_html__(
					"The reason why we have deprecated this feature is because Facebook can't handle product variations through microdata on their end.",
					'woocommerce-google-adwords-conversion-tracking-tag'
				),
			],
			'importance'      => esc_html__(
				'high',
				'woocommerce-google-adwords-conversion-tracking-tag'
			),
//			'video_id' => 'pzpnvb5h94',
			'learn_more_link' => Documentation::get_link('facebook_microdata_deprecation', true),        // Optional
			'settings_link'   => '/wp-admin/admin.php?page=wpm&section=advanced&subsection=facebook',    // Optional
			'since'           => 1714123201, // timestamp
			'repeat_interval' => MONTH_IN_SECONDS,  // Can be empty or no set for no repeat
		];
	}
}
