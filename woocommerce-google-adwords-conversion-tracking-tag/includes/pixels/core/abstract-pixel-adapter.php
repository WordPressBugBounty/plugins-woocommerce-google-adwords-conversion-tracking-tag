<?php

namespace SweetCode\Pixel_Manager\Pixels\Core;

defined('ABSPATH') || exit; // Exit if accessed directly

/**
 * Abstract base class for server-side pixel adapters
 *
 * Provides common filtering pipeline logic that all adapters share.
 * Child classes only need to implement pixel-specific methods.
 *
 * @package SweetCode\Pixel_Manager\Pixels
 * @since 1.51.0
 */
abstract class Abstract_Pixel_Adapter implements Pixel_Adapter {

	/**
	 * Process an event through the filter pipeline and send to API
	 *
	 * Applies the standard 3-stage filter pipeline:
	 * 1. Pixel-specific filters (all events)
	 * 2. Pixel + Event filters (specific event)
	 * 3. Post-processing filters (final stage)
	 *
	 * @param array       $pixel_data Event data specific to this pixel
	 * @param string|null $event_name The event name (e.g., 'add_to_cart', 'purchase')
	 * @return void
	 */
	public function process_event( $pixel_data, $event_name = null ) {

		// Apply all filters
		$pixel_data = $this->apply_filters( $pixel_data, $event_name );

		// If filters didn't block the event, send it
		if ( ! empty( $pixel_data ) ) {
			$this->send( $pixel_data );
		}
	}

	/**
	 * Apply the standard filter pipeline
	 *
	 * @param array       $pixel_data Event data
	 * @param string|null $event_name Event name
	 * @return array|null Filtered data or null if blocked
	 */
	protected function apply_filters( $pixel_data, $event_name ) {

		$pixel_name = $this->get_pixel_name();

		// Stage 3: Pixel-specific filters (all events)
		$pixel_data = apply_filters( "pmw_server_event_payload_{$pixel_name}", $pixel_data, $pixel_name );

		if ( empty( $pixel_data ) ) {
			return null;
		}

		// Stage 4: Pixel + Event-specific filters
		if ( $event_name ) {
			$pixel_data = apply_filters( "pmw_server_event_payload_{$pixel_name}_{$event_name}", $pixel_data, $pixel_name, $event_name );

			if ( empty( $pixel_data ) ) {
				return null;
			}
		}

		// Stage 5: Post-processing filters
		$pixel_data = apply_filters( 'pmw_server_event_payload_post', $pixel_data, $pixel_name );

		if ( empty( $pixel_data ) ) {
			return null;
		}

		return $pixel_data;
	}

	/**
	 * Send the event data to the pixel's API
	 *
	 * Child classes must implement this to call their specific API.
	 *
	 * @param array $pixel_data Filtered event data ready to send
	 * @return void
	 */
	abstract protected function send( $pixel_data );
}
