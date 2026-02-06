<?php
/**
 * Pixel Descriptor Interface
 *
 * Defines the contract that all pixel descriptors must implement.
 * This interface enables automatic pixel discovery, categorization, and third-party pixel registration.
 *
 * @package SweetCode\Pixel_Manager
 * @since 1.52.0
 */

namespace SweetCode\Pixel_Manager\Pixels\Core;

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly
}

/**
 * Interface Pixel_Descriptor
 *
 * All pixel implementations should implement this interface to enable automatic
 * registration and discovery through the Pixel_Registry.
 */
interface Pixel_Descriptor {

	/**
	 * Get the pixel's unique identifier.
	 *
	 * @return string Lowercase identifier (e.g., 'facebook', 'google_ads', 'tiktok')
	 */
	public function get_name();

	/**
	 * Get the pixel's human-readable label.
	 *
	 * @return string Display name (e.g., 'Facebook Pixel', 'Google Ads', 'TikTok')
	 */
	public function get_label();

	/**
	 * Get the pixel's category.
	 *
	 * @return string One of: 'marketing', 'statistics', 'optimization'
	 */
	public function get_category();

	/**
	 * Check if the pixel is currently active/enabled.
	 *
	 * @return bool True if the pixel is enabled in settings
	 */
	public function is_active();

	/**
	 * Check if the pixel has browser-side tracking.
	 *
	 * @return bool True if the pixel loads JavaScript tracking code
	 */
	public function has_browser_tracking();

	/**
	 * Check if the pixel has server-side tracking.
	 *
	 * @return bool True if the pixel has server-to-server API integration
	 */
	public function has_server_tracking();

	/**
	 * Get the pixel's configuration schema.
	 *
	 * Optional method to provide metadata about the pixel's settings structure.
	 * Can be used for dynamic admin UI generation, validation, etc.
	 *
	 * @return array Configuration schema (empty array if not applicable)
	 */
	public function get_config_schema();
}
