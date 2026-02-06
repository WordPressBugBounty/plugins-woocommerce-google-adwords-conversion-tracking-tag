<?php
/**
 * CrazyEgg Pixel Descriptor
 *
 * Browser-only pixel descriptor for CrazyEgg tracking.
 *
 * @package SweetCode\Pixel_Manager
 * @since 1.56.0
 */

namespace SweetCode\Pixel_Manager\Pixels\Descriptors;

use SweetCode\Pixel_Manager\Options;
use SweetCode\Pixel_Manager\Pixels\Core\Abstract_Pixel_Descriptor;

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly
}

/**
 * Class Crazyegg_Descriptor
 *
 * Descriptor for CrazyEgg pixel (browser-only tracking).
 */
class Crazyegg_Descriptor extends Abstract_Pixel_Descriptor {

	/**
	 * Get the pixel's unique identifier
	 *
	 * @return string
	 */
	public function get_name() {
		return 'crazyegg';
	}

	/**
	 * Get the pixel's human-readable label
	 *
	 * @return string
	 */
	public function get_label() {
		return 'CrazyEgg';
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
		return Options::is_crazyegg_enabled();
	}
}

// Auto-instantiate to register with the registry
new Crazyegg_Descriptor();
