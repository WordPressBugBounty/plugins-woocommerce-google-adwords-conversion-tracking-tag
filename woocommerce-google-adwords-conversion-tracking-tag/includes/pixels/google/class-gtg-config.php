<?php
/**
 * Google Tag Gateway Configuration
 *
 * Reads the GTG handler type from a browser-set cookie and provides
 * the configuration to the frontend via pmwDataLayer.
 *
 * The browser is the source of truth for handler detection because
 * server-to-server requests (wp_remote_get) bypass CDN/proxy layers
 * and cannot reliably detect Cloudflare. JavaScript detects the handler
 * via health check requests that traverse the actual network path,
 * then sets a cookie (pmw_gtg_handler) for PHP to read on subsequent
 * page loads. This eliminates all wp_remote_get() self-probing and
 * prevents thundering herd problems on transient cache expiry.
 *
 * @package SweetCode\Pixel_Manager\Pixels\Google
 */

namespace SweetCode\Pixel_Manager\Pixels\Google;

use SweetCode\Pixel_Manager\Helpers;

defined( 'ABSPATH' ) || exit;

/**
 * Google Tag Gateway Configuration
 *
 * Cookie-based handler detection with transient/option caching.
 *
 * Detection flow:
 * 1. Filter override (pmw_gtg_handler)
 * 2. Transient/option cache
 * 3. Browser-set cookie (pmw_gtg_handler)
 * 4. null (first visit — JS will detect and set cookie)
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
	 * Transient key for the stale flag
	 *
	 * Set by refresh_handler() when settings change.
	 * When present, the cookie is ignored and JS re-detects on next page load.
	 *
	 * @since 1.58.7
	 */
	const STALE_FLAG_KEY = 'pmw_gtg_handler_stale';

	/**
	 * Cookie name set by JavaScript after handler detection
	 *
	 * @since 1.58.7
	 */
	const COOKIE_NAME = 'pmw_gtg_handler';

	/**
	 * Valid handler types
	 */
	const VALID_HANDLERS = [ 'external', 'standalone', 'wordpress' ];

	/**
	 * Get the GTG handler from cache, cookie, or return null
	 *
	 * Priority:
	 * 1. Filter override (pmw_gtg_handler)
	 * 2. Transient/option cache (unless force_refresh)
	 * 3. Browser-set cookie (unless stale flag is set)
	 * 4. null — no handler known (first visit, JS will detect)
	 *
	 * @param bool $force_refresh Force re-read from cookie, bypassing cache.
	 * @return string|null Handler type or null if not yet detected by browser
	 *
	 * @since 1.58.7
	 */
	public static function get_handler( $force_refresh = false ) {

		/**
		 * Filters the GTG handler type.
		 *
		 * Allows manual override of handler detection. Return a valid handler
		 * string ('external', 'standalone', 'WordPress') to bypass all detection.
		 *
		 * @since 1.58.5
		 */
		$filtered = apply_filters( 'pmw_gtg_handler', null );
		if ( null !== $filtered && in_array( $filtered, self::VALID_HANDLERS, true ) ) {
			return $filtered;
		}

		if ( ! $force_refresh ) {
			$cached = self::get_cached_handler();
			if ( null !== $cached ) {
				return $cached;
			}
		}

		// Read handler from browser-set cookie
		$cookie_handler = self::get_cookie_handler();
		if ( null !== $cookie_handler ) {
			self::cache_handler( $cookie_handler );
			return $cookie_handler;
		}

		// No handler known — first visit or cookie expired
		// JS will detect and set the cookie on this page load
		return null;
	}

	/**
	 * Read the GTG handler from the browser-set cookie
	 *
	 * The cookie is set by JavaScript after browser-side handler detection.
	 * Returns null if the cookie is missing, invalid, or marked stale.
	 *
	 * @return string|null Valid handler type or null
	 *
	 * @since 1.58.7
	 */
	private static function get_cookie_handler() {

		// If settings recently changed, ignore the stale cookie
		// and let JS re-detect on this page load
		if ( get_transient( self::STALE_FLAG_KEY ) ) {
			delete_transient( self::STALE_FLAG_KEY );
			return null;
		}

		$_cookie = Helpers::get_input_vars( INPUT_COOKIE );

		if ( empty( $_cookie[ self::COOKIE_NAME ] ) ) {
			return null;
		}

		$handler = $_cookie[ self::COOKIE_NAME ];

		// Validate against known handler types
		if ( ! in_array( $handler, self::VALID_HANDLERS, true ) ) {
			return null;
		}

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
	 * Invalidate handler detection and force JS re-detection
	 *
	 * Clears all PHP caches and sets a one-shot stale flag so the next
	 * page load ignores the (now stale) browser cookie and lets JS
	 * re-detect the handler from scratch.
	 *
	 * Called when GTG settings change (e.g., measurement_path updated).
	 *
	 * @return void
	 *
	 * @since 1.58.7
	 */
	public static function refresh_handler() {
		self::clear_cached_handler();
		set_transient( self::STALE_FLAG_KEY, 1, HOUR_IN_SECONDS );
	}
}
