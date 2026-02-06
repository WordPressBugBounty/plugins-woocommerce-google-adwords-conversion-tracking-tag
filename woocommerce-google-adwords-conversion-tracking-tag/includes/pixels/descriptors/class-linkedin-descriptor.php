<?php
/**
 * LinkedIn Pixel Descriptor
 *
 * Browser-only pixel descriptor for LinkedIn tracking.
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
 * Class LinkedIn_Descriptor
 *
 * Descriptor for LinkedIn pixel (browser-only tracking).
 */
class LinkedIn_Descriptor extends Abstract_Pixel_Descriptor {

	/**
	 * Get the pixel's unique identifier
	 *
	 * @return string
	 */
	public function get_name() {
		return 'linkedin';
	}

	/**
	 * Get the pixel's human-readable label
	 *
	 * @return string
	 */
	public function get_label() {
		return 'LinkedIn';
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
		return Options::is_linkedin_active();
	}
}

// Auto-instantiate to register with the registry
new LinkedIn_Descriptor();
