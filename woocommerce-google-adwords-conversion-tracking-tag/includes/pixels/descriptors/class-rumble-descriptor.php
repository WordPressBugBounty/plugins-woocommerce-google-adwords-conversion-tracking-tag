<?php
/**
 * Rumble Ads Pixel Descriptor
 *
 * Pixel descriptor for Rumble Ads conversion tracking (Pro).
 *
 * @package SweetCode\Pixel_Manager
 * @since 1.72.1
 */

namespace SweetCode\Pixel_Manager\Pixels\Descriptors;

use SweetCode\Pixel_Manager\Options;
use SweetCode\Pixel_Manager\Pixels\Core\Abstract_Pixel_Descriptor;

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly
}

/**
 * Class Rumble_Descriptor
 *
 * Descriptor for the Rumble Ads pixel (Pro). Loads the Rumble global site
 * tag, which stores the ad click ID, and reports purchases and add to cart
 * events to the conversion actions configured in the settings.
 */
class Rumble_Descriptor extends Abstract_Pixel_Descriptor {

	/**
	 * Get the pixel's unique identifier
	 *
	 * @return string
	 */
	public function get_name() {
		return 'rumble';
	}

	/**
	 * Get the pixel's human-readable label
	 *
	 * @return string
	 */
	public function get_label() {
		return 'Rumble Ads';
	}

	/**
	 * Get the pixel's category
	 *
	 * Note: Rumble Ads is an ads platform, so this is a marketing pixel.
	 *
	 * @return string
	 */
	public function get_category() {
		return 'marketing';
	}

	/**
	 * Check if the pixel is currently active
	 *
	 * @return bool
	 */
	public function is_active() {
		return Options::is_rumble_active();
	}
}

// Auto-instantiate to register with the registry
new Rumble_Descriptor();
