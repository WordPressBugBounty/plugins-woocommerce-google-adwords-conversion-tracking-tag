<?php
/**
 * AB Tasty Pixel Descriptor
 *
 * Browser-only pixel descriptor for AB Tasty tracking.
 *
 * @package SweetCode\Pixel_Manager
 * @since 1.52.0
 */

namespace SweetCode\Pixel_Manager\Pixels\Descriptors;

use SweetCode\Pixel_Manager\Options;
use SweetCode\Pixel_Manager\Pixels\Core\Abstract_Pixel_Descriptor;

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly
}

/**
 * Class AB_Tasty_Descriptor
 *
 * Descriptor for AB Tasty pixel (browser-only tracking).
 */
class AB_Tasty_Descriptor extends Abstract_Pixel_Descriptor {

	/**
	 * Get the pixel's unique identifier
	 *
	 * @return string
	 */
	public function get_name() {
		return 'ab_tasty';
	}

	/**
	 * Get the pixel's human-readable label
	 *
	 * @return string
	 */
	public function get_label() {
		return 'AB Tasty';
	}

	/**
	 * Get the pixel's category
	 *
	 * @return string
	 */
	public function get_category() {
		return 'optimization';
	}

	/**
	 * Check if the pixel is currently active
	 *
	 * @return bool
	 */
	public function is_active() {
		return Options::is_ab_tasty_active();
	}
}

// Auto-instantiate to register with the registry
new AB_Tasty_Descriptor();
