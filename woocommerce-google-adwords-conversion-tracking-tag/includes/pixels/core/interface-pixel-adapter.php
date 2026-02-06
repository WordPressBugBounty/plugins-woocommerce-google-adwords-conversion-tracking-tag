<?php

namespace SweetCode\Pixel_Manager\Pixels\Core;

defined('ABSPATH') || exit; // Exit if accessed directly

/**
 * Interface for server-side pixel adapters
 *
 * Each pixel adapter handles:
 * - Availability checking (class exists, enabled, etc.)
 * - Filter pipeline application
 * - Sending data to the pixel's API
 *
 * @package SweetCode\Pixel_Manager\Pixels\Adapters
 * @since 1.51.0
 */
interface Pixel_Adapter {

	/**
	 * Get the pixel name
	 *
	 * @return string Pixel identifier (e.g., 'facebook', 'tiktok', 'google_analytics')
	 */
	public function get_pixel_name();

	/**
	 * Check if this pixel adapter is available and can process events
	 *
	 * Should check:
	 * - Required class exists
	 * - Pixel is enabled in settings
	 * - Any other prerequisites
	 *
	 * @return bool True if available, false otherwise
	 */
	public function is_available();

	/**
	 * Process an event through the filter pipeline and send to API
	 *
	 * This method should:
	 * 1. Apply pixel-specific filters
	 * 2. Apply pixel+event filters
	 * 3. Apply post-processing filters
	 * 4. Send to the pixel's API if not blocked
	 *
	 * @param array $pixel_data Event data specific to this pixel
	 * @param string|null $event_name The event name (e.g., 'add_to_cart', 'purchase')
	 * @return void
	 */
	public function process_event( $pixel_data, $event_name = null );
}
