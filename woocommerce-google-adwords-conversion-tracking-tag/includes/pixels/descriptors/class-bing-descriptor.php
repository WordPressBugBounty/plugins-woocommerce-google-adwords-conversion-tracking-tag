<?php
/**
 * Microsoft Ads Pixel Descriptor
 *
 * Browser-only pixel descriptor for Microsoft Ads tracking.
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
 * Class Bing_Descriptor
 *
 * Descriptor for Microsoft Ads pixel (browser-only tracking).
 */
class Bing_Descriptor extends Abstract_Pixel_Descriptor {

	/**
	 * Get the pixel's unique identifier
	 *
	 * @return string
	 */
	public function get_name() {
		return 'bing';
	}

	/**
	 * Get the pixel's human-readable label
	 *
	 * @return string
	 */
	public function get_label() {
		return 'Microsoft Ads';
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
		return Options::is_bing_active();
	}
}

// Auto-instantiate to register with the registry
new Bing_Descriptor();
