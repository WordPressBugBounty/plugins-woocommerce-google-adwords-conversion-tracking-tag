<?php
/**
 * Hyros Pixel Descriptor
 *
 * Pixel descriptor for Hyros attribution tracking (Pro).
 *
 * @package SweetCode\Pixel_Manager
 * @since 1.63.1
 */

namespace SweetCode\Pixel_Manager\Pixels\Descriptors;

use SweetCode\Pixel_Manager\Options;
use SweetCode\Pixel_Manager\Pixels\Core\Abstract_Pixel_Descriptor;

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly
}

/**
 * Class Hyros_Descriptor
 *
 * Descriptor for the Hyros pixel (Pro). Loads the Hyros Universal Script and
 * adds action tags for the shopping funnel milestones to the visitor journey.
 * Sales themselves are pulled by Hyros from the WooCommerce REST API, they are
 * not sent by the pixel.
 */
class Hyros_Descriptor extends Abstract_Pixel_Descriptor {

	/**
	 * Get the pixel's unique identifier
	 *
	 * @return string
	 */
	public function get_name() {
		return 'hyros';
	}

	/**
	 * Get the pixel's human-readable label
	 *
	 * @return string
	 */
	public function get_label() {
		return 'Hyros';
	}

	/**
	 * Get the pixel's category
	 *
	 * Note: Hyros is an ad attribution platform.
	 *
	 * @return string
	 */
	public function get_category() {
		return 'attribution';
	}

	/**
	 * Check if the pixel is currently active
	 *
	 * @return bool
	 */
	public function is_active() {
		return Options::is_hyros_active();
	}
}

// Auto-instantiate to register with the registry
new Hyros_Descriptor();
