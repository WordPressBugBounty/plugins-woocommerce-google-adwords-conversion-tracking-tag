<?php

namespace SweetCode\Pixel_Manager;

use SweetCode\Pixel_Manager\Pixels\Core\Pixel_Registry;

defined('ABSPATH') || exit; // Exit if accessed directly

/**
 * Server-side event processor with filter pipeline
 *
 * Processes server-to-server events through a 5-stage filter pipeline:
 * 1. Pre-processing filters - pmw_server_event_payload_pre (runs once, all events, before pixel processing)
 * 2. Global event filters - pmw_server_event_payload_event_{event} (runs once per event type, before pixel processing)
 * 3. Pixel-specific filters - pmw_server_event_payload_{pixel} (runs per pixel, all events)
 * 4. Pixel + event filters - pmw_server_event_payload_{pixel}_{event} (runs per pixel per event)
 * 5. Post-processing filters - pmw_server_event_payload_post (runs per pixel, before sending to API)
 *
 * Each filter stage can:
 * - Modify the event data
 * - Return null to block the event from being sent
 * - Add custom parameters for tracking/attribution
 *
 * @package SweetCode\Pixel_Manager
 * @since 1.51.0
 */
class Server_Event_Processor {

	/**
	 * Flag to track if adapters have been loaded
	 *
	 * @var bool
	 */
	private static $adapters_loaded = false;

	/**
	 * Ensure S2S adapters are loaded and registered
	 *
	 * This is needed because REST API requests may not go through the normal
	 * Pixel_Manager initialization flow that loads adapters in the constructor.
	 *
	 * @return void
	 */
	private static function ensure_adapters_loaded() {

		if (self::$adapters_loaded) {
			return;
		}

		// Load adapter infrastructure if not already loaded
		$core_path = __DIR__ . '/pixels/core/';

		if (!interface_exists('SweetCode\Pixel_Manager\Pixels\Core\Pixel_Adapter')) {
			$interface_file = $core_path . 'interface-pixel-adapter.php';
			if (file_exists($interface_file)) {
				require_once $interface_file;
			}
		}

		if (!class_exists('SweetCode\Pixel_Manager\Pixels\Core\Abstract_Pixel_Adapter')) {
			$abstract_file = $core_path . 'abstract-pixel-adapter.php';
			if (file_exists($abstract_file)) {
				require_once $abstract_file;
			}
		}

		if (!class_exists('SweetCode\Pixel_Manager\Pixels\Core\Pixel_Registry')) {
			$registry_file = $core_path . 'class-pixel-registry.php';
			if (file_exists($registry_file)) {
				require_once $registry_file;
			}
		}

		// Load all S2S adapter classes - this triggers auto-registration
		// The class_exists() calls trigger the autoloader which loads the files
		// Each adapter file ends with `new XYZ_Adapter()` which registers it
		$adapters_to_load = [
			'SweetCode\Pixel_Manager\Pixels\Facebook\Facebook_Adapter',
			'SweetCode\Pixel_Manager\Pixels\TikTok\TikTok_Adapter',
			'SweetCode\Pixel_Manager\Pixels\Pinterest\Pinterest_Adapter',
			'SweetCode\Pixel_Manager\Pixels\Snapchat\Snapchat_Adapter',
			'SweetCode\Pixel_Manager\Pixels\Reddit\Reddit_Adapter',
		];

		foreach ($adapters_to_load as $adapter_class) {
			// Check if class already exists (might have been loaded elsewhere)
			$already_exists = class_exists($adapter_class, false); // false = don't autoload

			if (!$already_exists) {
				// Force autoload
				class_exists($adapter_class, true); // true = autoload
			}

			// If class exists but wasn't registered, manually instantiate it
			if (class_exists($adapter_class, false)) {
				$registered = Pixel_Registry::get_adapters();
				// Extract just the pixel name (e.g., 'reddit' from 'Reddit_Adapter')
				$parts = explode('\\', $adapter_class);
				$class_short = end($parts);
				$pixel_name = strtolower(str_replace('_Adapter', '', $class_short));

				if (!isset($registered[$pixel_name])) {
					try {
						new $adapter_class();
					} catch (\Exception $e) {
						// Log adapter instantiation failure for debugging
						wc_get_logger()->debug(
							'Failed to instantiate adapter: ' . $adapter_class . ' - ' . $e->getMessage(),
							[ 'source' => 'pmw' ]
						);
					}
				}
			}
		}

		self::$adapters_loaded = true;
	}

	/**
	 * Process a server-to-server event through the filter pipeline
	 *
	 * @param array $event_data Raw event data from client
	 * @return void
	 */
	public static function process_event( $event_data ) {

		// Ensure adapters are loaded before processing
		self::ensure_adapters_loaded();

		// Stage 1: Pre-processing filters
		// Apply filters before any pixel-specific processing
		$event_data = apply_filters('pmw_server_event_payload_pre', $event_data);

		// If pre-processing filter returns null/false, stop processing
		if (empty($event_data)) {
			return;
		}

		// Extract the event name for use in filters (e.g., 'add_to_cart', 'purchase')
		$event_name = isset($event_data['event']) ? $event_data['event'] : null;

		// Stage 2: Global event filter (runs once per event type, before pixel processing)
		// Apply filter for this specific event across all pixels
		if ($event_name) {
			$event_data = apply_filters("pmw_server_event_payload_event_{$event_name}", $event_data, $event_name);

			// If event filter returns null/false, stop processing
			if (empty($event_data)) {
				return;
			}
		}

		// Send to each active pixel
		self::send_to_pixels($event_data, $event_name);
	}

	/**
	 * Send event data to active server-to-server pixels
	 *
	 * @param array $event_data Processed event data
	 * @param string|null $event_name The event name (e.g., 'add_to_cart')
	 * @return void
	 */
	private static function send_to_pixels( $event_data, $event_name = null ) {

		// Get all registered pixel adapters
		$adapters = Pixel_Registry::get_adapters();

		// Process each pixel through its adapter
		foreach ($adapters as $pixel_name => $adapter) {

			// Check if event data exists for this pixel
			if (!isset($event_data[ $pixel_name ])) {
				continue;
			}

			// Check if adapter is available (class exists, etc.)
			if (!$adapter->is_available()) {
				continue;
			}

			// Let the adapter process the event (handles filtering and sending)
			$adapter->process_event($event_data[ $pixel_name ], $event_name);
		}
	}
}
