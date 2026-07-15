<?php
/**
 * GroundTruth Pixel Descriptor
 *
 * Pixel descriptor for GroundTruth tracking (Pro).
 *
 * @package SweetCode\Pixel_Manager
 * @since 1.63.0
 */

namespace SweetCode\Pixel_Manager\Pixels\Descriptors;

use SweetCode\Pixel_Manager\Options;
use SweetCode\Pixel_Manager\Pixels\Core\Abstract_Pixel_Descriptor;

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly
}

/**
 * Class GroundTruth_Descriptor
 *
 * Descriptor for the GroundTruth pixel (Pro). Loads the GroundTruth Web
 * Engagement Pixel for omnichannel ad attribution (mobile, desktop, CTV,
 * audio) and sends cart and purchase events with order values.
 */
class GroundTruth_Descriptor extends Abstract_Pixel_Descriptor {

	/**
	 * Get the pixel's unique identifier
	 *
	 * @return string
	 */
	public function get_name() {
		return 'groundtruth';
	}

	/**
	 * Get the pixel's human-readable label
	 *
	 * @return string
	 */
	public function get_label() {
		return 'GroundTruth';
	}

	/**
	 * Get the pixel's category
	 *
	 * Note: GroundTruth is an ads platform, so this is a marketing pixel.
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
		return Options::is_groundtruth_active();
	}
}

// Auto-instantiate to register with the registry
new GroundTruth_Descriptor();
