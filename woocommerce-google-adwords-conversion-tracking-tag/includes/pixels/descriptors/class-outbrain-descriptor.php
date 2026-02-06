<?php
/**
 * Outbrain Pixel Descriptor
 *
 * Browser-only pixel descriptor for Outbrain tracking.
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
 * Class Outbrain_Descriptor
 *
 * Descriptor for Outbrain pixel (browser-only tracking).
 */
class Outbrain_Descriptor extends Abstract_Pixel_Descriptor {

	/**
	 * Get the pixel's unique identifier
	 *
	 * @return string
	 */
	public function get_name() {
		return 'outbrain';
	}

	/**
	 * Get the pixel's human-readable label
	 *
	 * @return string
	 */
	public function get_label() {
		return 'Outbrain';
	}

	/**
	 * Get the pixel's category
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
		return Options::is_outbrain_active();
	}
}

// Auto-instantiate to register with the registry
new Outbrain_Descriptor();
