<?php
/**
 * Microsoft Clarity Pixel Descriptor
 *
 * Pixel descriptor for Microsoft Clarity tracking (Pro).
 *
 * @package SweetCode\Pixel_Manager
 * @since 1.60.1
 */

namespace SweetCode\Pixel_Manager\Pixels\Descriptors;

use SweetCode\Pixel_Manager\Options;
use SweetCode\Pixel_Manager\Pixels\Core\Abstract_Pixel_Descriptor;

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly
}

/**
 * Class Clarity_Descriptor
 *
 * Descriptor for the Microsoft Clarity pixel (Pro). Loads the Clarity tag for
 * heatmaps and session recordings, and sends e-commerce custom events
 * (add_to_cart, begin_checkout, purchase) plus clarity('upgrade', ...) hints.
 */
class Clarity_Descriptor extends Abstract_Pixel_Descriptor {

	/**
	 * Get the pixel's unique identifier
	 *
	 * @return string
	 */
	public function get_name() {
		return 'clarity';
	}

	/**
	 * Get the pixel's human-readable label
	 *
	 * @return string
	 */
	public function get_label() {
		return 'Microsoft Clarity';
	}

	/**
	 * Get the pixel's category
	 *
	 * Note: Microsoft Clarity is primarily a statistics/analytics pixel.
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
		return Options::is_clarity_active();
	}
}

// Auto-instantiate to register with the registry
new Clarity_Descriptor();
