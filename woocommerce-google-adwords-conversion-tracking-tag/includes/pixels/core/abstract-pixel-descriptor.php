<?php
/**
 * Abstract Pixel Descriptor
 *
 * Base class providing default implementations for the Pixel_Descriptor interface.
 * Most browser-only pixels can simply extend this class and override is_active().
 *
 * @package SweetCode\Pixel_Manager
 * @since 1.52.0
 */

namespace SweetCode\Pixel_Manager\Pixels\Core;

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly
}

/**
 * Class Abstract_Pixel_Descriptor
 *
 * Provides sensible defaults for pixel descriptors.
 * Default assumptions: browser tracking enabled, server tracking disabled, no config schema.
 */
abstract class Abstract_Pixel_Descriptor implements Pixel_Descriptor {

	/**
	 * Check if the pixel has browser-side tracking.
	 *
	 * Default implementation returns true (most pixels have browser tracking).
	 * Override if your pixel is server-only.
	 *
	 * @return bool
	 */
	public function has_browser_tracking() {
		return true;
	}

	/**
	 * Check if the pixel has server-side tracking.
	 *
	 * Default implementation returns false (browser-only pixels).
	 * Override if your pixel has S2S capabilities.
	 *
	 * @return bool
	 */
	public function has_server_tracking() {
		return false;
	}

	/**
	 * Get the pixel's configuration schema.
	 *
	 * Default implementation returns empty array.
	 * Override to provide schema for dynamic admin UI generation.
	 *
	 * @return array
	 */
	public function get_config_schema() {
		return [];
	}

	/**
	 * Auto-register this descriptor with the Pixel_Registry on instantiation.
	 *
	 * This ensures all descriptors are automatically discovered when their
	 * classes are loaded, without requiring manual registration calls.
	 */
	public function __construct() {
		if ( class_exists( '\SweetCode\Pixel_Manager\Pixels\Core\Pixel_Registry' ) ) {
			Pixel_Registry::register_descriptor( $this );
		}
	}
}
