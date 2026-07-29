<?php
/**
 * Criteo Pixel Descriptor
 *
 * Pixel descriptor for Criteo tracking (Pro).
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
 * Class Criteo_Descriptor
 *
 * Descriptor for the Criteo pixel (Pro). Loads the Criteo OneTag and sends
 * the shopping journey events Criteo uses for retargeting and audience
 * building: homepage, product listing, product, basket and transaction.
 */
class Criteo_Descriptor extends Abstract_Pixel_Descriptor {

	/**
	 * Get the pixel's unique identifier
	 *
	 * @return string
	 */
	public function get_name() {
		return 'criteo';
	}

	/**
	 * Get the pixel's human-readable label
	 *
	 * @return string
	 */
	public function get_label() {
		return 'Criteo';
	}

	/**
	 * Get the pixel's category
	 *
	 * Note: Criteo is an ads platform, so this is a marketing pixel.
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
		return Options::is_criteo_active();
	}
}

// Auto-instantiate to register with the registry
new Criteo_Descriptor();
