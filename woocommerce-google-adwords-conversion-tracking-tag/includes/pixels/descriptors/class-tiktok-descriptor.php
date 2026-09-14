<?php
/**
 * TikTok Pixel Descriptor
 *
 * Browser pixel descriptor for TikTok. The browser pixel is part of the free
 * plugin. The TikTok Events API lives in the premium-only adapter and API
 * classes, which only exist on Pro builds.
 *
 * @package SweetCode\Pixel_Manager
 * @since 1.68.0
 */

namespace SweetCode\Pixel_Manager\Pixels\Descriptors;

use SweetCode\Pixel_Manager\Options;
use SweetCode\Pixel_Manager\Pixels\Core\Abstract_Pixel_Descriptor;

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly
}

/**
 * Class TikTok_Descriptor
 */
class TikTok_Descriptor extends Abstract_Pixel_Descriptor {

	public function get_name() {
		return 'tiktok';
	}

	public function get_label() {
		return 'TikTok';
	}

	public function get_category() {
		return 'marketing';
	}

	public function is_active() {
		return Options::is_tiktok_active();
	}

	/**
	 * Server-side tracking exists only while the Events API is configured,
	 * which a free install cannot do because the token is a Pro-only setting.
	 *
	 * @return bool
	 */
	public function has_server_tracking() {
		return Options::is_tiktok_eapi_active();
	}
}

// Auto-instantiate to register with the registry
new TikTok_Descriptor();
