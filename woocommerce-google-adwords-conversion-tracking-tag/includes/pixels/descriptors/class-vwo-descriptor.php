<?php
/**
 * VWO Pixel Descriptor
 *
 * Browser-only pixel descriptor for VWO (Visual Website Optimizer) tracking.
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
 * Class VWO_Descriptor
 *
 * Descriptor for VWO pixel (browser-only tracking).
 * VWO is both a statistics and optimization pixel.
 */
class VWO_Descriptor extends Abstract_Pixel_Descriptor {

	/**
	 * Get the pixel's unique identifier
	 *
	 * @return string
	 */
	public function get_name() {
		return 'vwo';
	}

	/**
	 * Get the pixel's human-readable label
	 *
	 * @return string
	 */
	public function get_label() {
		return 'VWO';
	}

	/**
	 * Get the pixel's category
	 *
	 * Note: VWO serves both statistics and optimization purposes.
	 * Setting to 'optimization' as primary category.
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
		return Options::is_vwo_active();
	}
}

// Auto-instantiate to register with the registry
new VWO_Descriptor();
