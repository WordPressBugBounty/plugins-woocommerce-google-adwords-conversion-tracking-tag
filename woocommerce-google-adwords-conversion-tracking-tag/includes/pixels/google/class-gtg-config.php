<?php
/**
 * Google Tag Gateway Configuration
 *
 * Handles server-side detection of the optimal GTG handler and provides
 * the configuration to the frontend via pmwDataLayer.
 *
 * @package SweetCode\Pixel_Manager\Pixels\Google
 */

namespace SweetCode\Pixel_Manager\Pixels\Google;

use SweetCode\Pixel_Manager\Options;

defined( 'ABSPATH' ) || exit;

/**
 * Google Tag Gateway Configuration
 *
 * Server-side detection of the optimal proxy handler with caching.
 *
 * Priority:
 * 1. External (Cloudflare) - measurement_path responds without X-PMW-GTG-Handler header
 * 2. Standalone - direct PHP proxy responds with X-PMW-GTG-Handler: standalone
 * 3. WordPress - fallback when others fail
 */
class GTG_Config {

	/**
	 * Transient key for caching the handler
	 */
	const TRANSIENT_KEY = 'pmw_gtg_handler';

	/**
	 * Cache duration in seconds (24 hours)
	 */
	const CACHE_DURATION = DAY_IN_SECONDS;

	/**
	 * Option key for fallback when transients are disabled
	 */
	const OPTION_KEY = 'pmw_gtg_handler_cache';

	/**
	 * Valid handler types
	 */
	const VALID_HANDLERS = [ 'external', 'standalone', 'wordpress' ];

	/**
	 * Detect the GTG handler server-side
	 *
	 * Priority:
	 * 1. External (Cloudflare) - measurement_path responds without X-PMW-GTG-Handler
	 * 2. Standalone - direct PHP proxy responds with X-PMW-GTG-Handler: standalone
	 * 3. WordPress - fallback
	 *
	 * @return string Handler type ('external', 'standalone', 'wordpress')
	 */
	public static function detect_handler() {
		$measurement_path = Options::get_google_tag_gateway_measurement_path();
		$site_url         = get_site_url();

		// If no measurement path configured, can't use external/Cloudflare
		if ( empty( $measurement_path ) ) {
			// Still check if standalone proxy is available
			return self::check_standalone_proxy() ? 'standalone' : 'wordpress';
		}

		// Priority 1: Check if measurement_path is handled by external (Cloudflare)
		$handler = self::check_measurement_path( $site_url . $measurement_path );

		if ( 'external' === $handler ) {
			return 'external';
		}

		// If measurement_path returned 'standalone', use it
		if ( 'standalone' === $handler ) {
			return 'standalone';
		}

		// Priority 2: Check if standalone proxy is available via direct access
		if ( self::check_standalone_proxy() ) {
			return 'standalone';
		}

		// Priority 3: Fallback to WordPress proxy
		return 'wordpress';
	}

	/**
	 * Check measurement_path for handler type
	 *
	 * @param string $base_url The base URL with measurement_path.
	 * @return string|null Handler type or null if check failed
	 */
	private static function check_measurement_path( $base_url ) {
		$health_url = $base_url . '/healthy';

		$response = wp_remote_get(
			$health_url,
			[
				'timeout'   => 5,
				'sslverify' => self::should_verify_ssl(),
				'headers'   => [
					'Cache-Control' => 'no-cache',
				],
			]
		);

		if ( is_wp_error( $response ) ) {
			return null;
		}

		if ( 200 !== wp_remote_retrieve_response_code( $response ) ) {
			return null;
		}

		$handler_header = wp_remote_retrieve_header( $response, 'x-pmw-gtg-handler' );

		// No header = Cloudflare (external) is proxying
		if ( empty( $handler_header ) ) {
			return 'external';
		}

		// Return the detected handler (isolated or wordpress)
		if ( in_array( $handler_header, self::VALID_HANDLERS, true ) ) {
			return $handler_header;
		}

		return null;
	}

	/**
	 * Check if SSL verification should be disabled for health checks
	 *
	 * Detects local development environments where self-signed certificates
	 * are commonly used.
	 *
	 * @return bool True to verify SSL, false to skip verification
	 */
	private static function should_verify_ssl() {
		// Allow explicit override via filter
		$filter_value = apply_filters( 'pmw_gtg_health_check_sslverify', null );
		if ( null !== $filter_value ) {
			return (bool) $filter_value;
		}

		// Detect common local development domains
		$site_url = get_site_url();
		$host     = wp_parse_url( $site_url, PHP_URL_HOST );

		$local_patterns = [
			'.test',
			'.local',
			'.localhost',
			'.dev',
			'.ddev.site',
			'localhost',
		];

		foreach ( $local_patterns as $pattern ) {
			// PHP 5.6 compatible string ends with check
			$pattern_len = strlen( $pattern );
			if ( substr( $host, -$pattern_len ) === $pattern || ltrim( $pattern, '.' ) === $host ) {
				return false;
			}
		}

		// Check for IP addresses (usually local)
		if ( filter_var( $host, FILTER_VALIDATE_IP ) ) {
			return false;
		}

		return true;
	}

	/**
	 * Check if standalone proxy is available via direct access
	 *
	 * If the config file doesn't exist but GTG is active, try to create it first.
	 * This ensures sites that upgraded to 1.56.0+ get the config file automatically.
	 *
	 * @return bool True if standalone proxy is available
	 */
	private static function check_standalone_proxy() {
		$proxy_url = GTG_Proxy::get_isolated_proxy_url();

		if ( ! $proxy_url ) {
			return false;
		}

		// Before checking the proxy, ensure the config file exists
		// This handles sites that upgraded but didn't have the config created
		self::ensure_config_exists();

		$response = wp_remote_get(
			$proxy_url . '?healthCheck=1',
			[
				'timeout'   => 5,
				'sslverify' => self::should_verify_ssl(),
				'headers'   => [
					'Cache-Control' => 'no-cache',
				],
			]
		);

		if ( is_wp_error( $response ) ) {
			return false;
		}

		if ( 200 !== wp_remote_retrieve_response_code( $response ) ) {
			return false;
		}

		$handler_header = wp_remote_retrieve_header( $response, 'x-pmw-gtg-handler' );

		return 'standalone' === $handler_header;
	}

	/**
	 * Ensure the standalone proxy config file exists
	 *
	 * Creates the config file if GTG is active but the config is missing.
	 * This handles sites that upgraded to 1.56.0+ but didn't have the config
	 * file created during the upgrade process.
	 *
	 * @return void
	 * @since 1.56.0
	 */
	private static function ensure_config_exists() {
		// Only run if GTG is active
		if ( ! GTG_Proxy::is_active() ) {
			return;
		}

		// Check if config file exists
		$config_file = GTG_Proxy::get_config_file_path();
		if ( ! $config_file ) {
			return;
		}

		// If config file already exists and is not too old, skip
		if ( file_exists( $config_file ) ) {
			// Check if it's not expired (24 hours)
			$file_age = time() - filemtime( $config_file );
			if ( $file_age < DAY_IN_SECONDS ) {
				return;
			}
		}

		// Create or refresh the config file
		GTG_Proxy::update_proxy_config_cache();
	}

	/**
	 * Get the proxy URL for direct PHP access
	 *
	 * @return string|false
	 */
	public static function get_proxy_url() {
		return GTG_Proxy::get_isolated_proxy_url();
	}

	/**
	 * Get cached handler or detect and cache
	 *
	 * Uses transients with fallback to options table when transients are disabled.
	 *
	 * @param bool $force_refresh Force re-detection.
	 * @return string Handler type
	 */
	public static function get_handler( $force_refresh = false ) {
		if ( ! $force_refresh ) {
			$cached = self::get_cached_handler();
			if ( null !== $cached ) {
				return $cached;
			}
		}

		$handler = self::detect_handler();
		self::cache_handler( $handler );

		return $handler;
	}

	/**
	 * Get the cached handler from transient or options
	 *
	 * @return string|null Handler type or null if not cached
	 */
	private static function get_cached_handler() {
		// Try transient first
		$cached = get_transient( self::TRANSIENT_KEY );
		if ( false !== $cached && in_array( $cached, self::VALID_HANDLERS, true ) ) {
			return $cached;
		}

		// Fallback to options table (for when transients are disabled)
		$option = get_option( self::OPTION_KEY );
		if ( is_array( $option ) && isset( $option['handler'], $option['expires'] ) ) {
			// Check if option cache is still valid
			if ( $option['expires'] > time() && in_array( $option['handler'], self::VALID_HANDLERS, true ) ) {
				return $option['handler'];
			}
		}

		return null;
	}

	/**
	 * Cache the handler in transient and options
	 *
	 * @param string $handler Handler type.
	 * @return bool Success
	 */
	private static function cache_handler( $handler ) {
		if ( ! in_array( $handler, self::VALID_HANDLERS, true ) ) {
			return false;
		}

		// Cache in transient
		set_transient( self::TRANSIENT_KEY, $handler, self::CACHE_DURATION );

		// Also cache in options as fallback (with expiry timestamp)
		update_option(
			self::OPTION_KEY,
			[
				'handler' => $handler,
				'expires' => time() + self::CACHE_DURATION,
			],
			false // Don't autoload
		);

		return true;
	}

	/**
	 * Clear the cached handler
	 * Called when GTG settings change
	 *
	 * @return bool
	 */
	public static function clear_cached_handler() {
		delete_transient( self::TRANSIENT_KEY );
		delete_option( self::OPTION_KEY );
		return true;
	}

	/**
	 * Force refresh the handler detection
	 * Called when settings change to immediately detect the new handler
	 *
	 * @return string The newly detected handler
	 */
	public static function refresh_handler() {
		self::clear_cached_handler();
		return self::get_handler( true );
	}
}
