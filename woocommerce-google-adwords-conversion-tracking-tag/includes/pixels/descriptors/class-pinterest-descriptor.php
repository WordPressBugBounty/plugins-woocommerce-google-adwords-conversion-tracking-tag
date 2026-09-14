<?php
/**
 * Pinterest Pixel Descriptor
 *
 * Browser pixel descriptor for the Pinterest tag. The browser pixel is part of
 * the free plugin. The Pinterest API for Conversions lives in the premium-only
 * adapter and API classes, which only exist on Pro builds.
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
 * Class Pinterest_Descriptor
 */
class Pinterest_Descriptor extends Abstract_Pixel_Descriptor {

	public function get_name() {
		return 'pinterest';
	}

	public function get_label() {
		return 'Pinterest';
	}

	public function get_category() {
		return 'marketing';
	}

	public function is_active() {
		return Options::is_pinterest_active();
	}

	/**
	 * Server-side tracking exists only while the API for Conversions is
	 * configured, which needs Pro-only settings.
	 *
	 * @return bool
	 */
	public function has_server_tracking() {
		return Options::is_pinterest_apic_active();
	}
}

// Auto-instantiate to register with the registry
new Pinterest_Descriptor();
