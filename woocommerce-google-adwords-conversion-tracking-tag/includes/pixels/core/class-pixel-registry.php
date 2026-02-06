<?php
/**
 * Pixel Registry
 *
 * Central registry for all pixel types - both server-side adapters and browser-side descriptors.
 * Enables automatic pixel discovery, categorization, and third-party pixel registration.
 *
 * @package SweetCode\Pixel_Manager
 * @since 1.52.0
 */

namespace SweetCode\Pixel_Manager\Pixels\Core;

defined( 'ABSPATH' ) || exit; // Exit if accessed directly

/**
 * Class Pixel_Registry
 *
 * Manages registration and retrieval of both:
 * - Pixel Adapters (server-to-server integrations)
 * - Pixel Descriptors (all pixel types including browser-only)
 *
 * Third-party developers can register custom pixels using the
 * 'pmw_register_pixel_descriptors' action hook.
 */
class Pixel_Registry {

	/**
	 * Registered server-side pixel adapters
	 *
	 * @var Pixel_Adapter[]
	 */
	private static $adapters = [];

	/**
	 * Registered pixel descriptors
	 *
	 * @var Pixel_Descriptor[]
	 */
	private static $descriptors = [];

	/**
	 * Register a pixel adapter (server-to-server integration)
	 *
	 * @param Pixel_Adapter $adapter The adapter to register
	 * @return void
	 */
	public static function register( Pixel_Adapter $adapter ) {
		self::$adapters[ $adapter->get_pixel_name() ] = $adapter;
	}

	/**
	 * Register a pixel descriptor
	 *
	 * @param Pixel_Descriptor $descriptor The descriptor to register
	 * @return void
	 */
	public static function register_descriptor( Pixel_Descriptor $descriptor ) {
		self::$descriptors[ $descriptor->get_name() ] = $descriptor;
	}

	/**
	 * Get all registered adapters
	 *
	 * @return Pixel_Adapter[]
	 */
	public static function get_adapters() {
		return self::$adapters;
	}

	/**
	 * Get all registered descriptors
	 *
	 * @return Pixel_Descriptor[]
	 */
	public static function get_descriptors() {
		return self::$descriptors;
	}

	/**
	 * Get a specific adapter by pixel name
	 *
	 * @param string $pixel_name The pixel name (e.g., 'facebook', 'tiktok')
	 * @return Pixel_Adapter|null The adapter or null if not found
	 */
	public static function get_adapter( $pixel_name ) {
		return isset( self::$adapters[ $pixel_name ] ) ? self::$adapters[ $pixel_name ] : null;
	}

	/**
	 * Get a specific descriptor by pixel name
	 *
	 * @param string $pixel_name The pixel name
	 * @return Pixel_Descriptor|null The descriptor or null if not found
	 */
	public static function get_descriptor( $pixel_name ) {
		return isset( self::$descriptors[ $pixel_name ] ) ? self::$descriptors[ $pixel_name ] : null;
	}

	/**
	 * Check if an adapter is registered
	 *
	 * @param string $pixel_name The pixel name
	 * @return bool True if registered, false otherwise
	 */
	public static function has_adapter( $pixel_name ) {
		return isset( self::$adapters[ $pixel_name ] );
	}

	/**
	 * Check if a descriptor is registered
	 *
	 * @param string $pixel_name The pixel name
	 * @return bool True if registered, false otherwise
	 */
	public static function has_descriptor( $pixel_name ) {
		return isset( self::$descriptors[ $pixel_name ] );
	}

	/**
	 * Check if any server-to-server adapters are available
	 *
	 * Iterates through all registered adapters and checks if any are available.
	 * This allows for automatic detection of S2S integrations without manually
	 * updating a centralized list.
	 *
	 * @return bool True if at least one S2S adapter is available, false otherwise
	 * @since 1.52.0
	 */
	public static function has_available_adapters() {
		foreach ( self::$adapters as $adapter ) {
			if ( $adapter->is_available() ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Get all available adapters
	 *
	 * Returns only adapters that are currently available (enabled and configured).
	 *
	 * @return Pixel_Adapter[] Array of available adapters
	 * @since 1.52.0
	 */
	public static function get_available_adapters() {
		return array_filter(
			self::$adapters,
			function ( $adapter ) {
				return $adapter->is_available();
			}
		);
	}

	/**
	 * Get pixels by category
	 *
	 * @param string $category One of: 'marketing', 'statistics', 'optimization'
	 * @return Pixel_Descriptor[] Array of descriptors in the specified category
	 */
	public static function get_pixels_by_category( $category ) {
		return array_filter(
			self::$descriptors,
			function ( $descriptor ) use ( $category ) {
				return $descriptor->get_category() === $category;
			}
		);
	}

	/**
	 * Get active pixels by category
	 *
	 * @param string $category One of: 'marketing', 'statistics', 'optimization'
	 * @return Pixel_Descriptor[] Array of active descriptors in the specified category
	 */
	public static function get_active_pixels_by_category( $category ) {
		return array_filter(
			self::$descriptors,
			function ( $descriptor ) use ( $category ) {
				return $descriptor->get_category() === $category && $descriptor->is_active();
			}
		);
	}

	/**
	 * Check if any marketing pixels are active
	 *
	 * @return bool True if at least one marketing pixel is active
	 */
	public static function has_active_marketing_pixels() {
		foreach ( self::$descriptors as $descriptor ) {
			if ( $descriptor->get_category() === 'marketing' && $descriptor->is_active() ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Check if any statistics pixels are active
	 *
	 * @return bool True if at least one statistics pixel is active
	 */
	public static function has_active_statistics_pixels() {
		foreach ( self::$descriptors as $descriptor ) {
			if ( $descriptor->get_category() === 'statistics' && $descriptor->is_active() ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Check if any optimization pixels are active
	 *
	 * @return bool True if at least one optimization pixel is active
	 */
	public static function has_active_optimization_pixels() {
		foreach ( self::$descriptors as $descriptor ) {
			if ( $descriptor->get_category() === 'optimization' && $descriptor->is_active() ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Get all active pixels
	 *
	 * @return Pixel_Descriptor[] Array of active descriptors
	 */
	public static function get_active_pixels() {
		return array_filter(
			self::$descriptors,
			function ( $descriptor ) {
				return $descriptor->is_active();
			}
		);
	}

	/**
	 * Initialize third-party pixel registration
	 *
	 * Fires an action hook allowing third-party developers to register
	 * their custom pixel descriptors.
	 *
	 * @return void
	 */
	public static function init_third_party_pixels() {
		do_action( 'pmw_register_pixel_descriptors', __CLASS__ );
	}
}
