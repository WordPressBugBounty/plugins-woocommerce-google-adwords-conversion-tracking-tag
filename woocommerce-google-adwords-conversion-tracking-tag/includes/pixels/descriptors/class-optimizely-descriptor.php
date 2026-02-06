<?php
/**
 * Optimizely Pixel Descriptor
 *
 * Browser-only pixel descriptor for Optimizely tracking.
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
 * Class Optimizely_Descriptor
 *
 * Descriptor for Optimizely pixel (browser-only tracking).
 */
class Optimizely_Descriptor extends Abstract_Pixel_Descriptor {

	/**
	 * Get the pixel's unique identifier
	 *
	 * @return string
	 */
	public function get_name() {
		return 'optimizely';
	}

	/**
	 * Get the pixel's human-readable label
	 *
	 * @return string
	 */
	public function get_label() {
		return 'Optimizely';
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
		return Options::is_optimizely_active();
	}
}

// Auto-instantiate to register with the registry
new Optimizely_Descriptor();
