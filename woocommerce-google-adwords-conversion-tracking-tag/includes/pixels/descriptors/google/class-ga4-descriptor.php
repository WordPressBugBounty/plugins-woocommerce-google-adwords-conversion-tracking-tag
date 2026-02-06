<?php
/**
 * Google Analytics 4 Pixel Descriptor
 *
 * Descriptor for Google Analytics 4 tracking (both browser and server-side).
 *
 * @package SweetCode\Pixel_Manager
 * @since 1.52.0
 */

namespace SweetCode\Pixel_Manager\Pixels\Descriptors\Google;

use SweetCode\Pixel_Manager\Options;
use SweetCode\Pixel_Manager\Pixels\Core\Abstract_Pixel_Descriptor;

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly
}

/**
 * Class GA4_Descriptor
 *
 * Descriptor for Google Analytics 4 pixel (browser and server tracking).
 */
class GA4_Descriptor extends Abstract_Pixel_Descriptor {

	/**
	 * Get the pixel's unique identifier
	 *
	 * @return string
	 */
	public function get_name() {
		return 'ga4';
	}

	/**
	 * Get the pixel's human-readable label
	 *
	 * @return string
	 */
	public function get_label() {
		return 'Google Analytics 4';
	}

	/**
	 * Get the pixel's category
	 *
	 * @return string
	 */
	public function get_category() {
		return 'statistics';
	}

	/**
	 * Check if the pixel is currently active
	 *
	 * @return bool
	 */
	public function is_active() {
		return Options::is_ga4_enabled();
	}

	/**
	 * Check if the pixel has server-side tracking
	 *
	 * GA4 supports both browser and server-side tracking via Measurement Protocol.
	 *
	 * @return bool
	 */
	public function has_server_tracking() {
		return Options::is_ga4_mp_active();
	}
}

// Auto-instantiate to register with the registry
new GA4_Descriptor();
