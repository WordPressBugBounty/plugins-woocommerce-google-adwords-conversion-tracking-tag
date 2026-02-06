<?php
/**
 * Pixel Manager for WooCommerce - Standalone Google Tag Gateway Proxy
 *
 * This file runs completely independently of WordPress for maximum performance.
 * Configuration is read from wp-content/uploads/pmw-gtg/ written by WordPress when settings change.
 *
 * Performance benefits:
 * - Bypasses WordPress core loading (saves 15-25MB memory)
 * - Bypasses plugin/theme loading (saves 5-15MB memory)
 * - Response time: 50-150ms vs 200-500ms with WordPress
 *
 * Defensive behavior:
 * - Returns 503 with X-PMW-Fallback: wordpress header if config cannot be loaded
 * - JavaScript client detects this and switches to WordPress proxy
 *
 * @package PMW
 * @since   1.53.0
 */

// Prevent direct access except as standalone proxy
if ( ! defined( 'PMW_GTG_STANDALONE' ) ) {
	define( 'PMW_GTG_STANDALONE', true );
}

/**
 * Sanitize a file system path.
 *
 * Removes null bytes and normalizes the path for safe file system operations.
 *
 * @param string $path The path to sanitize.
 * @return string The sanitized path.
 * @since 1.53.0
 */
function pmw_gtg_sanitize_path( $path ) {
	// Remove null bytes (security concern)
	$path = str_replace( chr( 0 ), '', $path );
	// Remove any non-printable characters
	$path = preg_replace( '/[\x00-\x1F\x7F]/', '', $path );
	// Normalize directory separators
	$path = str_replace( '\\', '/', $path );
	return $path;
}

/**
 * Helper function to get wp-content path from current file location
 *
 * Navigates from plugin directory to wp-content.
 * Current file: wp-content/plugins/{plugin-name}/includes/pixels/google/pmw-gtg-proxy.php
 * Target: wp-content/
 *
 * @return string|false Path to wp-content or false if not found.
 */
function pmw_gtg_get_wp_content_path() {
	// Method 1: Navigate up from __DIR__
	$dir = __DIR__;
	for ( $i = 0; $i < 10; $i++ ) {
		$parent = dirname( $dir );
		if ( 'wp-content' === basename( $parent ) ) {
			return $parent;
		}
		$dir = $parent;

		// Safety check: stop at filesystem root
		if ( $dir === $parent ) {
			break;
		}
	}

	// Method 2: Try with realpath() to resolve symlinks
	$dir = realpath( __DIR__ );
	if ( $dir ) {
		for ( $i = 0; $i < 10; $i++ ) {
			$parent = dirname( $dir );
			if ( 'wp-content' === basename( $parent ) ) {
				return $parent;
			}
			$dir = $parent;

			if ( $dir === $parent ) {
				break;
			}
		}
	}

	// Method 3: Try DOCUMENT_ROOT based path
	// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Sanitized via pmw_gtg_sanitize_path below.
	$doc_root_raw = isset( $_SERVER['DOCUMENT_ROOT'] ) ? stripslashes( $_SERVER['DOCUMENT_ROOT'] ) : null;
	if ( $doc_root_raw ) {
		$doc_root = rtrim( pmw_gtg_sanitize_path( $doc_root_raw ), '/' );
		$possible_paths = [
			$doc_root . '/wp-content',
			$doc_root . '/public/wp-content',      // GridPane/Trellis style
			$doc_root . '/../wp-content',          // Some setups have public/web folder
			dirname( $doc_root ) . '/wp-content',  // Alternative structure
		];

		foreach ( $possible_paths as $path ) {
			$real_path = realpath( $path );
			if ( $real_path && is_dir( $real_path ) ) {
				return $real_path;
			}
		}
	}

	return false;
}

/**
 * Helper function to get config directory path
 *
 * Tries multiple methods to find the config directory to handle
 * various hosting configurations (symlinks, path mapping, etc.)
 *
 * @return string|false Path to config directory or false if not found.
 */
function pmw_gtg_get_config_directory() {
	// Method 1: Standard wp-content based path
	$wp_content = pmw_gtg_get_wp_content_path();
	if ( $wp_content ) {
		$config_dir = $wp_content . '/uploads/pmw-gtg';
		if ( is_dir( $config_dir ) ) {
			return $config_dir;
		}
	}

	// Method 2: Try paths relative to __DIR__ with realpath
	$dir = realpath( __DIR__ );
	if ( $dir ) {
		// Navigate to plugin root, then to wp-content/uploads/pmw-gtg
		// From: includes/pixels/google -> plugin root -> plugins -> wp-content -> uploads -> pmw-gtg
		$plugin_root = dirname( dirname( dirname( $dir ) ) ); // includes/pixels/google -> plugin root
		$plugins_dir = dirname( $plugin_root );               // plugin root -> plugins
		$wp_content  = dirname( $plugins_dir );               // plugins -> wp-content
		$config_dir  = $wp_content . '/uploads/pmw-gtg';

		if ( is_dir( $config_dir ) ) {
			return $config_dir;
		}
	}

	// Method 3: Try DOCUMENT_ROOT based paths
	// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Sanitized via pmw_gtg_sanitize_path below.
	$doc_root_raw = isset( $_SERVER['DOCUMENT_ROOT'] ) ? stripslashes( $_SERVER['DOCUMENT_ROOT'] ) : null;
	if ( $doc_root_raw ) {
		$doc_root = rtrim( pmw_gtg_sanitize_path( $doc_root_raw ), '/' );
		$possible_paths = [
			$doc_root . '/wp-content/uploads/pmw-gtg',
			$doc_root . '/public/wp-content/uploads/pmw-gtg',      // GridPane/Trellis style
			dirname( $doc_root ) . '/wp-content/uploads/pmw-gtg',  // Alternative structure
		];

		foreach ( $possible_paths as $path ) {
			if ( is_dir( $path ) ) {
				return $path;
			}
			// Also try with realpath
			$real_path = realpath( $path );
			if ( $real_path && is_dir( $real_path ) ) {
				return $real_path;
			}
		}
	}

	return false;
}

/**
 * Helper function to get site identifier from request
 *
 * Extracts hostname and optional path prefix for multisite support.
 *
 * @return string Site identifier (hostname or hostname/path).
 */
function pmw_gtg_get_site_identifier() {
	// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Used for lookup only
	$host = isset( $_SERVER['HTTP_HOST'] ) ? $_SERVER['HTTP_HOST'] : '';
	// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Used for path extraction only
	$request_uri = isset( $_SERVER['REQUEST_URI'] ) ? $_SERVER['REQUEST_URI'] : '';

	// Sanitize host: lowercase, remove port
	$host = strtolower( $host );
	if ( false !== strpos( $host, ':' ) ) {
		$host = substr( $host, 0, strpos( $host, ':' ) );
	}

	// For subdirectory multisite, extract the first path segment
	// Example: /site2/metrics/... -> site2
	$path_prefix = '';
	if ( ! empty( $request_uri ) && '/' !== $request_uri ) {
		$parts = explode( '/', ltrim( $request_uri, '/' ) );
		if ( ! empty( $parts[0] ) ) {
			// Check if this looks like a site prefix (not a measurement path or file)
			$first_segment = $parts[0];
			// Skip if it's a query string or looks like a file extension
			if ( false === strpos( $first_segment, '?' ) && false === strpos( $first_segment, '.' ) ) {
				$path_prefix = $first_segment;
			}
		}
	}

	// Return hostname/path for subdirectory, hostname only for subdomain/single
	if ( ! empty( $path_prefix ) ) {
		return $host . '/' . $path_prefix;
	}

	return $host;
}

/**
 * Helper function to find config file using site map
 *
 * @param string $config_dir Config directory path.
 * @return string|false Config file path or false if not found.
 */
function pmw_gtg_find_config_file( $config_dir ) {
	$site_map_file = $config_dir . '/site-map.json';

	// Check if site map exists
	if ( ! file_exists( $site_map_file ) ) {
		return false;
	}

	// Read site map
	$site_map_content = file_get_contents( $site_map_file );
	if ( false === $site_map_content ) {
		return false;
	}

	$site_map = json_decode( $site_map_content, true );
	if ( ! is_array( $site_map ) ) {
		return false;
	}

	// Get site identifier
	$site_identifier = pmw_gtg_get_site_identifier();

	// Try exact match first
	if ( isset( $site_map[ $site_identifier ] ) ) {
		$config_file = $config_dir . '/' . $site_map[ $site_identifier ];
		if ( file_exists( $config_file ) ) {
			return $config_file;
		}
	}

	// Try hostname-only fallback (for subdirectory multisite where path might be in the URL)
	$host_only = $site_identifier;
	if ( false !== strpos( $site_identifier, '/' ) ) {
		$host_only = substr( $site_identifier, 0, strpos( $site_identifier, '/' ) );
	}

	if ( isset( $site_map[ $host_only ] ) ) {
		$config_file = $config_dir . '/' . $site_map[ $host_only ];
		if ( file_exists( $config_file ) ) {
			return $config_file;
		}
	}

	return false;
}

/**
 * Send fallback response signaling client to use WordPress proxy
 *
 * @param string $reason Reason for fallback (for debugging).
 * @return void
 */
function pmw_gtg_send_fallback_response( $reason = 'unknown' ) {
	http_response_code( 503 );
	header( 'Content-Type: text/plain; charset=utf-8' );
	header( 'X-PMW-Fallback: wordpress' );
	header( 'X-PMW-Fallback-Reason: ' . $reason );
	header( 'Cache-Control: no-store, no-cache, must-revalidate, max-age=0' );
	echo 'Fallback to WordPress proxy';
	exit;
}

// Health check endpoint - validates that the proxy is functional
if ( isset( $_GET['healthCheck'] ) ) {
	// Validate that config is accessible before returning "ok"
	$config_dir = pmw_gtg_get_config_directory();
	if ( ! $config_dir ) {
		pmw_gtg_send_fallback_response( 'config-directory-not-found' );
	}

	$config_file = pmw_gtg_find_config_file( $config_dir );
	if ( ! $config_file || ! file_exists( $config_file ) ) {
		pmw_gtg_send_fallback_response( 'config-file-not-found' );
	}

	// Check config age (24 hours max)
	if ( ( time() - filemtime( $config_file ) ) >= 86400 ) {
		pmw_gtg_send_fallback_response( 'config-expired' );
	}

	// Read and validate config
	$config = json_decode( file_get_contents( $config_file ), true );
	if ( ! is_array( $config ) || ! isset( $config['enabled'], $config['measurement_path'] ) ) {
		pmw_gtg_send_fallback_response( 'config-invalid' );
	}

	// Check if proxy is enabled
	if ( empty( $config['enabled'] ) ) {
		pmw_gtg_send_fallback_response( 'proxy-disabled' );
	}

	// Config is valid - log if enabled
	if ( ! empty( $config['logging_enabled'] ) && ! empty( $config['log_directory'] ) ) {
		$log_level = isset( $config['log_level'] ) ? $config['log_level'] : 'error';
		$level_priority = [ 'debug' => 7, 'info' => 6, 'notice' => 5, 'warning' => 4, 'error' => 3 ];
		$configured_priority = isset( $level_priority[ $log_level ] ) ? $level_priority[ $log_level ] : 3;

		if ( 6 <= $configured_priority ) { // info level = 6
			$wp_content = dirname( dirname( $config['log_directory'] ) );
			$debug_log  = $wp_content . '/debug.log';
			$timestamp  = gmdate( 'd-M-Y H:i:s \U\T\C' );
			$log_entry  = "[{$timestamp}] pmw [info] [GTG-Proxy-Standalone] Health check request received - proxy functional\n";
			@file_put_contents( $debug_log, $log_entry, FILE_APPEND | LOCK_EX );
		}
	}

	http_response_code( 200 );
	header( 'Content-Type: text/plain; charset=utf-8' );
	header( 'X-PMW-GTG-Handler: standalone' );
	echo 'ok';
	exit;
}

/**
 * Standalone Google Tag Gateway Proxy
 *
 * Implements the same functionality as GTG_Proxy but without WordPress dependencies.
 */
final class PMW_GTG_Proxy_Standalone {

	/**
	 * Maximum request body size in bytes (1MB)
	 *
	 * @var int
	 */
	const MAX_REQUEST_BODY_SIZE = 1048576;

	/**
	 * Maximum response body size in bytes (5MB)
	 *
	 * @var int
	 */
	const MAX_RESPONSE_BODY_SIZE = 5242880;

	/**
	 * Request timeout in seconds
	 *
	 * @var int
	 */
	const REQUEST_TIMEOUT = 5;

	/**
	 * Cache file max age in seconds (1 hour)
	 *
	 * @var int
	 */
	const CACHE_MAX_AGE = 86400;

	/**
	 * FPS path placeholder used by Google's response
	 *
	 * @var string
	 */
	const FPS_PATH_PLACEHOLDER = 'PHP_GTG_REPLACE_PATH';

	/**
	 * Reserved headers that should not be forwarded
	 *
	 * @var array
	 */
	const RESERVED_HEADERS = [
		'HTTP_ACCEPT_ENCODING',
		'HTTP_CONNECTION',
		'HTTP_CONTENT_LENGTH',
		'CONTENT_LENGTH',
		'HTTP_EXPECT',
		'HTTP_HOST',
		'HTTP_TRANSFER_ENCODING',
		'HTTP_AUTHORIZATION',
		'HTTP_PROXY_AUTHORIZATION',
		'HTTP_X_API_KEY',
	];

	/**
	 * Valid Google Tag ID prefixes
	 *
	 * @var array
	 */
	const VALID_TAG_PREFIXES = [ 'AW', 'G', 'GT', 'DC' ];

	/**
	 * Cached configuration
	 *
	 * @var array|null
	 */
	private static $config = null;

	/**
	 * Log levels with their numeric priority
	 *
	 * @var array
	 */
	const LOG_LEVELS = [
		'emergency' => 0,
		'alert'     => 1,
		'critical'  => 2,
		'error'     => 3,
		'warning'   => 4,
		'notice'    => 5,
		'info'      => 6,
		'debug'     => 7,
	];

	/**
	 * Run the proxy
	 *
	 * @return void
	 */
	public static function run() {
		try {
			$config = self::get_proxy_config();

			if ( ! $config['enabled'] ) {
				self::log( 'GTG proxy not enabled', [], 'debug' );
				http_response_code( 404 );
				exit( 'GTG Proxy not enabled' );
			}

			// Parse the request
			// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Request URI is used for routing only
			$request_uri = isset( $_SERVER['REQUEST_URI'] ) ? $_SERVER['REQUEST_URI'] : '';
			// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Request method is validated against known values
			$method      = isset( $_SERVER['REQUEST_METHOD'] ) ? $_SERVER['REQUEST_METHOD'] : 'GET';

			self::log( 'Processing GTG request', [ 'uri' => $request_uri, 'method' => $method ], 'debug' );

			// Detect access mode:
			// 1. Direct access: Request to this PHP file directly (e.g., /wp-content/plugins/.../pmw-gtg-proxy.php?id=TAG&s=/path)
			// 2. Measurement path access: Request via WordPress rewrite (e.g., /metrics2/?id=TAG&s=/path)
			$is_direct_access = strpos( $request_uri, 'pmw-gtg-proxy.php' ) !== false;
			
			if ( $is_direct_access ) {
				// Direct PHP file access - process query parameters directly
				self::log( 'Direct proxy access detected', [], 'debug' );
				self::handle_direct_access_request( $method );
				return;
			}

			// Measurement path mode - check if request matches
			$measurement_path = $config['measurement_path'];
			if ( 0 !== strpos( $request_uri, $measurement_path ) ) {
				// Check for secondary Google requests to root
				// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Tag ID is validated by is_valid_google_tag_id
				if ( isset( $_GET['id'] ) && isset( $_GET['gtm'] ) && self::is_valid_google_tag_id( $_GET['id'] ) ) {
					self::handle_secondary_request();
					return;
				}

				self::log( 'Request does not match measurement path', [ 'uri' => $request_uri, 'path' => $measurement_path ], 'debug' );
				http_response_code( 404 );
				exit;
			}

			// Extract the path after the measurement path
			$path = substr( $request_uri, strlen( $measurement_path ) );

			// Remove query string from path
			if ( strpos( $path, '?' ) !== false ) {
				$path = substr( $path, 0, strpos( $path, '?' ) );
			}
			$path = ltrim( $path, '/' );

			// Handle health check
			if ( 'healthy' === $path ) {
				self::send_health_response();
			}

			// Get query parameters
			// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Sanitized by sanitize_tag_id method
			$tag_id = isset( $_GET['id'] ) ? self::sanitize_tag_id( $_GET['id'] ) : '';
			// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Sanitized by sanitize_geo method
			$geo    = isset( $_GET['geo'] ) ? self::sanitize_geo( $_GET['geo'] ) : '';
			// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Path is validated against allowed patterns
			$s_path = isset( $_GET['s'] ) ? $_GET['s'] : '';
			// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Mpath is used in URL construction only
			$mpath  = isset( $_GET['mpath'] ) ? $_GET['mpath'] : '';

			// Build destination path
			$destination_path = self::build_destination_path( $s_path, $_GET, $tag_id, $path );

			if ( empty( $tag_id ) ) {
				self::log( 'Missing tag ID', [], 'warning' );
				http_response_code( 400 );
				exit( 'Missing tag ID' );
			}

			if ( ! self::validate_tag_id( $tag_id ) ) {
				self::log( 'Invalid tag ID format', [ 'tag_id' => $tag_id ], 'warning' );
				http_response_code( 400 );
				exit( 'Invalid tag ID' );
			}

			// Get request body for POST requests
			$body = '';
			if ( 'POST' === $method ) {
				$body = file_get_contents( 'php://input' );
				if ( strlen( $body ) > self::MAX_REQUEST_BODY_SIZE ) {
					self::log( 'Request body too large', [ 'size' => strlen( $body ) ], 'warning' );
					http_response_code( 413 );
					exit( 'Request too large' );
				}
			}

			// Determine which mpath to use
			$use_mpath = empty( $mpath ) ? self::FPS_PATH_PLACEHOLDER : $mpath;

			// Build the FPS URL
			$fps_url = self::build_fps_url( $tag_id, $destination_path, $use_mpath );

			if ( ! self::is_valid_fps_url( $fps_url ) ) {
				self::log( 'Invalid FPS URL generated', [ 'url' => $fps_url ], 'error' );
				http_response_code( 400 );
				exit( 'Invalid request' );
			}

			self::log( 'Proxying to FPS', [ 'url' => $fps_url, 'tag_id' => $tag_id ], 'info' );

			// Get headers to forward
			$headers = self::get_forwarded_headers( $geo );

			// Send request to Google FPS
			$response = self::send_request( $method, $fps_url, $headers, $body );

			if ( false === $response ) {
				self::log( 'Failed to proxy request to FPS', [ 'url' => $fps_url ], 'error' );
				http_response_code( 502 );
				exit( 'Proxy error' );
			}

			// Rewrite paths if using default placeholder
			if ( empty( $mpath ) || self::FPS_PATH_PLACEHOLDER === $mpath ) {
				$response = self::rewrite_response_paths( $response, $tag_id, $geo );
			}

			// Output the response
			self::output_response( $response, $method );

		} catch ( Exception $e ) {
			self::log( 'GTG Proxy exception', [ 'message' => $e->getMessage(), 'trace' => $e->getTraceAsString() ], 'error' );
			http_response_code( 500 );
			exit( 'Internal error' );
		}
	}

	/**
	 * Handle secondary Google Tag requests
	 *
	 * These are requests made by gtag.js itself after the initial script load.
	 * They come to the root path with parameters like ?id=TAG&cx=c&gtm=...
	 *
	 * @return void
	 */
	private static function handle_secondary_request() {
		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized,WordPress.Security.ValidatedSanitizedInput.InputNotValidated -- Validated by caller and sanitized by sanitize_tag_id
		$tag_id = self::sanitize_tag_id( $_GET['id'] );
		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Sanitized by sanitize_geo method
		$geo    = isset( $_GET['geo'] ) ? self::sanitize_geo( $_GET['geo'] ) : '';

		self::log( 'Handling secondary Google request', [ 'tag_id' => $tag_id ], 'info' );

		// Build destination path from query parameters
		$destination_path = '/';
		$query_params     = [];
		foreach ( $_GET as $key => $value ) {
			$query_params[] = urlencode( $key ) . '=' . urlencode( $value );
		}
		$destination_path .= '?' . implode( '&', $query_params );

		// Build FPS URL
		$fps_url = self::build_fps_url( $tag_id, $destination_path, self::FPS_PATH_PLACEHOLDER );

		if ( ! self::is_valid_fps_url( $fps_url ) ) {
			self::log( 'Invalid FPS URL for secondary request', [ 'url' => $fps_url ], 'error' );
			http_response_code( 400 );
			exit( 'Invalid request' );
		}

		// Get request details
		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Request method is validated against known values
		$method  = isset( $_SERVER['REQUEST_METHOD'] ) ? $_SERVER['REQUEST_METHOD'] : 'GET';
		$body    = 'POST' === $method ? file_get_contents( 'php://input' ) : '';
		$headers = self::get_forwarded_headers( $geo );

		// Send request
		$response = self::send_request( $method, $fps_url, $headers, $body );

		if ( false === $response ) {
			self::log( 'Failed secondary request to FPS', [ 'url' => $fps_url ], 'error' );
			http_response_code( 502 );
			exit( 'Proxy error' );
		}

		// Rewrite paths and output
		$response = self::rewrite_response_paths( $response, $tag_id, $geo );
		self::output_response( $response, $method );
	}

	/**
	 * Handle direct access to the proxy PHP file
	 *
	 * This handles requests made directly to pmw-gtg-proxy.php
	 * via plugins_url(), bypassing WordPress rewrite rules entirely.
	 *
	 * Expected format: pmw-gtg-proxy.php?id=TAG_ID&s=/gtag/js
	 *
	 * @param string $method HTTP method.
	 * @return void
	 */
	private static function handle_direct_access_request( $method ) {
		// Get parameters
		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Sanitized by sanitize_tag_id method
		$tag_id = isset( $_GET['id'] ) ? self::sanitize_tag_id( $_GET['id'] ) : '';
		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Path is validated against allowed patterns
		$s_path = isset( $_GET['s'] ) ? $_GET['s'] : '';
		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Sanitized by sanitize_geo method
		$geo    = isset( $_GET['geo'] ) ? self::sanitize_geo( $_GET['geo'] ) : '';
		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Mpath is used in URL construction only
		$mpath  = isset( $_GET['mpath'] ) ? $_GET['mpath'] : '';

		self::log(
			'Processing direct access request',
			[
				'tag_id' => $tag_id,
				's_path' => $s_path,
				'geo'    => $geo,
				'mpath'  => $mpath,
				'method' => $method,
			],
			'info'
		);

		// Validate tag ID
		if ( empty( $tag_id ) ) {
			self::log( 'Missing tag ID in direct access', [], 'warning' );
			http_response_code( 400 );
			exit( 'Missing tag ID' );
		}

		if ( ! self::validate_tag_id( $tag_id ) ) {
			self::log( 'Invalid tag ID format in direct access', [ 'tag_id' => $tag_id ], 'warning' );
			http_response_code( 400 );
			exit( 'Invalid tag ID' );
		}

		// Build destination path
		$destination_path = self::build_destination_path( $s_path, $_GET, $tag_id );

		// Get request body for POST
		$body = '';
		if ( 'POST' === $method ) {
			$body = file_get_contents( 'php://input' );
			if ( strlen( $body ) > self::MAX_REQUEST_BODY_SIZE ) {
				self::log( 'Request body too large in direct access', [ 'size' => strlen( $body ) ], 'warning' );
				http_response_code( 413 );
				exit( 'Request too large' );
			}
		}

		// Determine mpath
		$use_mpath = empty( $mpath ) ? self::FPS_PATH_PLACEHOLDER : $mpath;

		// Build FPS URL
		$fps_url = self::build_fps_url( $tag_id, $destination_path, $use_mpath );

		if ( ! self::is_valid_fps_url( $fps_url ) ) {
			self::log( 'Invalid FPS URL in direct access', [ 'url' => $fps_url ], 'error' );
			http_response_code( 400 );
			exit( 'Invalid request' );
		}

		self::log( 'Proxying to FPS (direct access)', [ 'url' => $fps_url, 'tag_id' => $tag_id ], 'info' );

		// Get forwarded headers
		$headers = self::get_forwarded_headers( $geo );

		// Send request to FPS
		$response = self::send_request( $method, $fps_url, $headers, $body );

		if ( false === $response ) {
			self::log( 'FPS request failed in direct access', [ 'url' => $fps_url ], 'error' );
			http_response_code( 502 );
			exit( 'Proxy error' );
		}

		self::log(
			'FPS response received (direct access)',
			[
				'status_code'  => $response['status_code'],
				'body_size'    => strlen( $response['body'] ),
				'content_type' => isset( $response['headers']['content-type'] ) ? $response['headers']['content-type'] : 'unknown',
			],
			'info'
		);

		// Rewrite paths in response - use direct access mode to rewrite to proxy_url instead of measurement_path
		$response = self::rewrite_response_paths( $response, $tag_id, $geo, true );

		// Output response
		self::output_response( $response, $method );

		self::log( 'Direct access request completed successfully', [ 'tag_id' => $tag_id, 'status_code' => $response['status_code'] ], 'info' );
	}

	/**
	 * Get proxy configuration from cache file
	 *
	 * This standalone proxy requires the config file to be written by WordPress.
	 * If the config file is missing, invalid, or expired, the proxy sends a fallback
	 * response signaling the client to use the WordPress proxy instead.
	 *
	 * Configuration is stored in wp-content/uploads/pmw-gtg/ with a site map
	 * for multisite support.
	 *
	 * @return array Configuration array
	 */
	private static function get_proxy_config() {
		if ( null !== self::$config ) {
			return self::$config;
		}

		// Step 1: Find wp-content directory
		$wp_content = pmw_gtg_get_wp_content_path();
		if ( ! $wp_content ) {
			pmw_gtg_send_fallback_response( 'wp-content-not-found' );
		}

		// Step 2: Check if config directory exists
		$config_dir = pmw_gtg_get_config_directory();
		if ( ! $config_dir ) {
			pmw_gtg_send_fallback_response( 'config-directory-not-found' );
		}

		// Step 3: Find config file using site map
		$cache_file = pmw_gtg_find_config_file( $config_dir );
		if ( ! $cache_file ) {
			pmw_gtg_send_fallback_response( 'config-file-not-found' );
		}

		// Step 4: Check if config file exists and is not expired
		if ( ! file_exists( $cache_file ) ) {
			pmw_gtg_send_fallback_response( 'config-file-missing' );
		}

		// Step 5: Check config age (24 hours max)
		if ( ( time() - filemtime( $cache_file ) ) >= self::CACHE_MAX_AGE ) {
			pmw_gtg_send_fallback_response( 'config-expired' );
		}

		// Step 6: Read and validate config
		$config_content = file_get_contents( $cache_file );
		if ( false === $config_content ) {
			pmw_gtg_send_fallback_response( 'config-unreadable' );
		}

		$config = json_decode( $config_content, true );
		if ( ! is_array( $config ) ) {
			pmw_gtg_send_fallback_response( 'config-invalid-json' );
		}

		// Step 7: Validate required fields
		if ( ! isset( $config['enabled'], $config['measurement_path'] ) ) {
			pmw_gtg_send_fallback_response( 'config-missing-fields' );
		}

		self::$config = $config;
		return $config;
	}

	/**
	 * Get the configuration file path (deprecated, use pmw_gtg_find_config_file)
	 *
	 * @deprecated 1.56.0 Config is now in wp-content/uploads/pmw-gtg/
	 * @return string|false Path to the configuration file or false.
	 */
	private static function get_config_file_path() {
		$config_dir = pmw_gtg_get_config_directory();
		if ( ! $config_dir ) {
			return false;
		}
		return pmw_gtg_find_config_file( $config_dir );
	}

	/**
	 * Log a message
	 *
	 * @param string $message Log message.
	 * @param array  $context Additional context data.
	 * @param string $level   Log level (debug, info, warning, error, critical).
	 * @return void
	 */
	private static function log( $message, $context = [], $level = 'info' ) {
		// Get configuration to check log level
		$config           = isset( self::$config ) ? self::$config : [ 'log_level' => 'error', 'logging_enabled' => false ];
		$configured_level = isset( $config['log_level'] ) ? $config['log_level'] : 'error';
		$logging_enabled  = isset( $config['logging_enabled'] ) ? $config['logging_enabled'] : false;

		// Skip if logging is disabled
		if ( ! $logging_enabled ) {
			return;
		}

		// Skip if log level is below configured threshold
		$level_priority      = isset( self::LOG_LEVELS[ $level ] ) ? self::LOG_LEVELS[ $level ] : 6;
		$configured_priority = isset( self::LOG_LEVELS[ $configured_level ] ) ? self::LOG_LEVELS[ $configured_level ] : 3;

		if ( $level_priority > $configured_priority ) {
			return;
		}

		// Format log entry
		$timestamp = gmdate( 'd-M-Y H:i:s \U\T\C' );
		$level_str = strtolower( $level );

		// Format context as JSON if not empty
		$context_str = '';
		if ( ! empty( $context ) ) {
			$context_str = ' | Context: ' . json_encode( $context, JSON_UNESCAPED_SLASHES );
		}

		// Format like WordPress proxy: [timestamp] pmw [level] [GTG-Proxy-Standalone] message | Context: {...}
		$log_entry = "[{$timestamp}] pmw [{$level_str}] [GTG-Proxy-Standalone] {$message}{$context_str}" . PHP_EOL;

		// Try to find WordPress debug.log
		// The log_directory in config points to wp-content/uploads/pmw-logs/
		// WordPress debug.log is at wp-content/debug.log
		$log_file = null;
		if ( ! empty( $config['log_directory'] ) ) {
			// Navigate from pmw-logs to wp-content/debug.log
			// /path/wp-content/uploads/pmw-logs/ -> /path/wp-content/debug.log
			$wp_content = dirname( dirname( $config['log_directory'] ) );
			$debug_log  = $wp_content . '/debug.log';
			if ( is_writable( dirname( $debug_log ) ) ) {
				$log_file = $debug_log;
			}
		}

		// Fallback: try to find wp-content from current file location
		if ( ! $log_file ) {
			// Current file is at: wp-content/plugins/plugin-name/includes/pixels/google/pmw-gtg-proxy.php
			// We need: wp-content/debug.log
			$dir = __DIR__;
			for ( $i = 0; $i < 10; $i++ ) {
				$parent = dirname( $dir );
				if ( basename( $parent ) === 'wp-content' ) {
					$debug_log = $parent . '/debug.log';
					if ( is_writable( $parent ) ) {
						$log_file = $debug_log;
					}
					break;
				}
				$dir = $parent;
			}
		}

		// Write to log file if found, otherwise fall back to error_log
		if ( $log_file ) {
			@file_put_contents( $log_file, $log_entry, FILE_APPEND | LOCK_EX );
		} else {
			error_log( rtrim( $log_entry ) );
		}
	}

	/**
	 * Build destination path following reference implementation logic
	 *
	 * @param string $s_path S parameter path.
	 * @param array  $get    GET parameters.
	 * @param string $tag_id Tag ID.
	 * @param string $path   Extracted path.
	 * @return string Destination path
	 */
	private static function build_destination_path( $s_path, $get, $tag_id, $path = '' ) {
		// Check if we have the 's' parameter (standard proxy format)
		if ( ! empty( $s_path ) ) {
			$destination_path = $s_path;

			// Remove reserved query parameters
			$params = $get;
			unset( $params['id'], $params['s'], $params['geo'], $params['mpath'] );

			$contains_query_params = strpos( $destination_path, '?' ) !== false;
			if ( $contains_query_params ) {
				list( $dest_path, $query ) = explode( '?', $destination_path, 2 );
				$destination_path = $dest_path . '?' . self::encode_query_parameter( $query );
			}

			if ( ! empty( $params ) ) {
				$param_separator   = $contains_query_params ? '&' : '?';
				$destination_path .= $param_separator . http_build_query( $params, '', '&', PHP_QUERY_RFC3986 );
			}

			return $destination_path;
		}

		// Path-based format: use extracted path
		if ( $path ) {
			return '/' . $path;
		}

		// Default fallback
		return '/';
	}

	/**
	 * Encode a query parameter
	 *
	 * @param string $parameter Query parameter string.
	 * @return string Encoded parameter
	 */
	private static function encode_query_parameter( $parameter ) {
		parse_str( $parameter, $parsed );
		return http_build_query( $parsed, '', '&', PHP_QUERY_RFC3986 );
	}

	/**
	 * Sanitize tag ID
	 *
	 * @param string $tag_id Raw tag ID.
	 * @return string Sanitized tag ID
	 */
	private static function sanitize_tag_id( $tag_id ) {
		return preg_replace( '/[^A-Za-z0-9-]/', '', $tag_id );
	}

	/**
	 * Sanitize geo parameter
	 *
	 * @param string $geo Raw geo parameter.
	 * @return string Sanitized geo parameter
	 */
	private static function sanitize_geo( $geo ) {
		if ( ! preg_match( '/^[A-Za-z0-9-]+$/', $geo ) ) {
			return '';
		}
		return $geo;
	}

	/**
	 * Validate tag ID format
	 *
	 * @param string $tag_id Tag ID to validate.
	 * @return bool True if valid
	 */
	private static function validate_tag_id( $tag_id ) {
		if ( empty( $tag_id ) ) {
			return false;
		}
		return (bool) preg_match( '/^[A-Za-z0-9-]+$/', $tag_id );
	}

	/**
	 * Check if tag ID is valid Google format
	 *
	 * @param string $tag_id Tag ID to check.
	 * @return bool True if valid Google tag ID
	 */
	private static function is_valid_google_tag_id( $tag_id ) {
		foreach ( self::VALID_TAG_PREFIXES as $prefix ) {
			if ( 0 === strpos( $tag_id, $prefix . '-' ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Build the Google FPS URL
	 *
	 * Google FPS expects the mpath (measurement path placeholder) to be in the URL path itself,
	 * not as a query parameter. The format should be:
	 * https://TAG.fps.goog/MPATH/destination/path
	 *
	 * Reference: Google Site Kit uses:
	 * $fpsUrl = 'https://' . $tagId . '.fps.goog/' . $useMpath . $path;
	 *
	 * @param string $tag_id      Tag ID.
	 * @param string $destination Destination path (e.g., /gtag/js or /gtag/js?some=param).
	 * @param string $mpath       Measurement path placeholder (e.g., PHP_GTG_REPLACE_PATH).
	 * @return string FPS URL
	 */
	private static function build_fps_url( $tag_id, $destination, $mpath ) {
		// Format: https://TAG.fps.goog/MPATH/destination
		// The mpath is part of the URL path, Google FPS will replace references to it in the response
		$fps_host = 'https://' . $tag_id . '.fps.goog';
		
		// Ensure destination starts with / for proper URL construction
		if ( ! empty( $destination ) && '/' !== $destination[0] ) {
			$destination = '/' . $destination;
		}
		
		// Build the URL with mpath in the path (not as query parameter)
		return $fps_host . '/' . $mpath . $destination;
	}

	/**
	 * Validate FPS URL
	 *
	 * @param string $url URL to validate.
	 * @return bool True if valid
	 */
	private static function is_valid_fps_url( $url ) {
		$parsed = parse_url( $url );

		if ( ! $parsed || ! isset( $parsed['host'] ) ) {
			return false;
		}

		// Must be HTTPS
		if ( ! isset( $parsed['scheme'] ) || 'https' !== $parsed['scheme'] ) {
			return false;
		}

		// Must end with .fps.goog
		if ( '.fps.goog' !== substr( $parsed['host'], -9 ) ) {
			return false;
		}

		return true;
	}

	/**
	 * Get headers to forward to FPS
	 *
	 * @param string $geo Geo parameter.
	 * @return array Headers array
	 */
	private static function get_forwarded_headers( $geo = '' ) {
		$headers       = [];
		$extra_headers = [
			'CONTENT_TYPE'   => 'Content-Type',
			'CONTENT_LENGTH' => 'Content-Length',
			'CONTENT_MD5'    => 'Content-MD5',
		];

		foreach ( $_SERVER as $key => $value ) {
			// Skip reserved headers
			if ( in_array( $key, self::RESERVED_HEADERS, true ) ) {
				continue;
			}

			$header_key = '';

			// Process HTTP_ prefixed headers
			if ( 0 === strpos( $key, 'HTTP_' ) ) {
				$header_key = str_replace( '_', '-', substr( $key, 5 ) );
				$header_key = ucwords( strtolower( $header_key ), '-' );
			} elseif ( isset( $extra_headers[ $key ] ) ) {
				$header_key = $extra_headers[ $key ];
			}

			if ( empty( $header_key ) || empty( $value ) ) {
				continue;
			}

			$headers[] = $header_key . ': ' . $value;
		}

		// Add X-Forwarded-For
		$client_ip = self::get_client_ip();
		if ( $client_ip ) {
			$headers[] = 'X-Forwarded-For: ' . $client_ip;
		}

		// Add geo header if present
		if ( ! empty( $geo ) ) {
			$headers[] = 'X-Forwarded-CountryRegion: ' . $geo;
		}

		return $headers;
	}

	/**
	 * Get client IP address
	 *
	 * @return string Client IP or empty string
	 */
	private static function get_client_ip() {
		$ip_keys = [ 'HTTP_CF_CONNECTING_IP', 'HTTP_X_FORWARDED_FOR', 'HTTP_X_REAL_IP', 'REMOTE_ADDR' ];

		foreach ( $ip_keys as $key ) {
			if ( ! empty( $_SERVER[ $key ] ) ) {
				// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- IP address is validated by filter_var
				$ip = $_SERVER[ $key ];
				// Handle comma-separated IPs (X-Forwarded-For)
				if ( false !== strpos( $ip, ',' ) ) {
					$ip = trim( explode( ',', $ip )[0] );
				}
				// Validate IP
				if ( filter_var( $ip, FILTER_VALIDATE_IP ) ) {
					return $ip;
				}
			}
		}

		return '';
	}

	/**
	 * Send request to Google FPS
	 *
	 * @param string $method  HTTP method.
	 * @param string $url     URL to request.
	 * @param array  $headers Headers to send.
	 * @param string $body    Request body.
	 * @return array|false Response array or false on failure
	 */
	private static function send_request( $method, $url, $headers, $body = '' ) {
		// Prefer cURL if available
		if ( function_exists( 'curl_init' ) ) {
			return self::send_curl_request( $method, $url, $headers, $body );
		}

		// Fallback to file_get_contents
		return self::send_stream_request( $method, $url, $headers, $body );
	}

	/**
	 * Send request using cURL
	 *
	 * @param string $method  HTTP method.
	 * @param string $url     URL to request.
	 * @param array  $headers Headers to send.
	 * @param string $body    Request body.
	 * @return array|false Response array or false on failure
	 */
	private static function send_curl_request( $method, $url, $headers, $body = '' ) {
		$ch = curl_init();

		curl_setopt_array(
			$ch,
			[
				CURLOPT_URL            => $url,
				CURLOPT_RETURNTRANSFER => true,
				CURLOPT_HEADER         => true,
				CURLOPT_TIMEOUT        => self::REQUEST_TIMEOUT,
				CURLOPT_CONNECTTIMEOUT => self::REQUEST_TIMEOUT,
				CURLOPT_HTTPHEADER     => $headers,
				CURLOPT_FOLLOWLOCATION => false,
				CURLOPT_MAXREDIRS      => 0,
				CURLOPT_SSL_VERIFYPEER => true,
				CURLOPT_SSL_VERIFYHOST => 2,
				CURLOPT_USERAGENT      => 'PMW-GTG-Proxy/1.0',
			]
		);

		if ( 'POST' === $method ) {
			curl_setopt( $ch, CURLOPT_POST, true );
			if ( ! empty( $body ) ) {
				curl_setopt( $ch, CURLOPT_POSTFIELDS, $body );
			}
		}

		$response    = curl_exec( $ch );
		$status_code = curl_getinfo( $ch, CURLINFO_HTTP_CODE );
		$header_size = curl_getinfo( $ch, CURLINFO_HEADER_SIZE );
		$error       = curl_error( $ch );

		curl_close( $ch );

		if ( false === $response ) {
			self::log( 'cURL request failed', [ 'error' => $error, 'url' => $url ], 'error' );
			return false;
		}

		$response_headers = substr( $response, 0, $header_size );
		$response_body    = substr( $response, $header_size );

		// Check response size limit
		if ( self::MAX_RESPONSE_BODY_SIZE < strlen( $response_body ) ) {
			self::log( 'Response too large', [ 'size' => strlen( $response_body ) ], 'warning' );
			return false;
		}

		return [
			'status_code' => $status_code,
			'headers'     => self::parse_response_headers( $response_headers ),
			'body'        => $response_body,
		];
	}

	/**
	 * Send request using streams (file_get_contents)
	 *
	 * @param string $method  HTTP method.
	 * @param string $url     URL to request.
	 * @param array  $headers Headers to send.
	 * @param string $body    Request body.
	 * @return array|false Response array or false on failure
	 */
	private static function send_stream_request( $method, $url, $headers, $body = '' ) {
		$header_string = implode( "\r\n", $headers );

		$context_options = [
			'http' => [
				'method'          => $method,
				'header'          => $header_string,
				'timeout'         => self::REQUEST_TIMEOUT,
				'follow_location' => 0,
				'ignore_errors'   => true,
				'user_agent'      => 'PMW-GTG-Proxy/1.0',
			],
			'ssl' => [
				'verify_peer'      => true,
				'verify_peer_name' => true,
			],
		];

		if ( 'POST' === $method && ! empty( $body ) ) {
			$context_options['http']['content'] = $body;
		}

		$context  = stream_context_create( $context_options );
		$response = @file_get_contents( $url, false, $context );

		if ( false === $response ) {
			self::log( 'Stream request failed', [ 'url' => $url ], 'error' );
			return false;
		}

		// Parse response headers from $http_response_header
		$response_headers = [];
		$status_code      = 200;

		if ( isset( $http_response_header ) && is_array( $http_response_header ) ) {
			foreach ( $http_response_header as $header ) {
				if ( preg_match( '/^HTTP\/\d\.\d\s+(\d+)/', $header, $matches ) ) {
					$status_code = (int) $matches[1];
				} else {
					$parts = explode( ':', $header, 2 );
					if ( count( $parts ) === 2 ) {
						$response_headers[ strtolower( trim( $parts[0] ) ) ] = trim( $parts[1] );
					}
				}
			}
		}

		return [
			'status_code' => $status_code,
			'headers'     => $response_headers,
			'body'        => $response,
		];
	}

	/**
	 * Parse response headers from cURL response
	 *
	 * @param string $header_string Raw header string.
	 * @return array Parsed headers
	 */
	private static function parse_response_headers( $header_string ) {
		$headers = [];
		$lines   = explode( "\r\n", $header_string );

		foreach ( $lines as $line ) {
			if ( empty( $line ) || 0 === strpos( $line, 'HTTP/' ) ) {
				continue;
			}

			$parts = explode( ':', $line, 2 );
			if ( 2 === count( $parts ) ) {
				$headers[ strtolower( trim( $parts[0] ) ) ] = trim( $parts[1] );
			}
		}

		return $headers;
	}

	/**
	 * Rewrite response paths to point back to our proxy
	 *
	 * @param array  $response       Response array.
	 * @param string $tag_id         Tag ID.
	 * @param string $geo            Geo parameter.
	 * @param bool   $direct_access  Whether this is a direct access request (use proxy_url instead of measurement_path).
	 * @return array Modified response
	 */
	private static function rewrite_response_paths( $response, $tag_id, $geo, $direct_access = false ) {
		if ( ! isset( $response['body'] ) || empty( $response['body'] ) ) {
			self::log( 'Rewrite skipped - empty body', [], 'debug' );
			return $response;
		}

		// Get configuration for rewriting
		$config = self::get_proxy_config();

		// Determine the base path for rewrites
		// In direct access mode, use proxy_url (the PHP file path) for subsequent requests
		// Otherwise, use measurement_path for Cloudflare/external proxy compatibility
		$base_path = '';
		if ( $direct_access && ! empty( $config['proxy_url'] ) ) {
			// Parse proxy_url to get just the path part (e.g., /wp-content/plugins/.../pmw-gtg-proxy.php)
			$parsed_url = parse_url( $config['proxy_url'] );
			$base_path = isset( $parsed_url['path'] ) ? $parsed_url['path'] : '';
			
			if ( ! empty( $base_path ) ) {
				// Use proxy_url path for direct access mode
				$replacement = $base_path . '?id=' . urlencode( $tag_id );
				self::log( 'Using proxy_url for path rewrite (direct access mode)', [ 'base_path' => $base_path ], 'debug' );
			} else {
				self::log( 'Rewrite skipped - invalid proxy_url path', [], 'debug' );
				return $response;
			}
		} else {
			// Use measurement path for normal mode (e.g., /metrics5?id=TAG)
			$base_path = isset( $config['measurement_path'] ) ? $config['measurement_path'] : '';
			
			if ( ! empty( $base_path ) ) {
				$replacement = $base_path . '?id=' . urlencode( $tag_id );
			} else {
				self::log( 'Rewrite skipped - no measurement path', [], 'debug' );
				return $response;
			}
		}

		// Add geo parameter if present
		if ( ! empty( $geo ) ) {
			$replacement .= '&geo=' . urlencode( $geo );
		}

		// Add the path query parameter suffix - this is critical!
		// The JavaScript contains URLs like '/PHP_GTG_REPLACE_PATH/d/ccm/form-data/...'
		// These need to become '/metrics5?id=TAG&s=/d/ccm/form-data/...'
		$replacement .= '&s=';

		$content_type = isset( $response['headers']['content-type'] ) ? $response['headers']['content-type'] : '';
		$status_code  = isset( $response['status_code'] ) ? $response['status_code'] : 200;

		self::log( 'Rewrite check', [
			'content_type'   => $content_type,
			'status_code'    => $status_code,
			'replacement'    => $replacement,
			'body_length'    => strlen( $response['body'] ),
			'has_placeholder' => strpos( $response['body'], self::FPS_PATH_PLACEHOLDER ) !== false,
		], 'debug' );

		// Only rewrite JavaScript responses (matching Google Site Kit behavior)
		// Reference: strpos($normalizedHeader, 'content-type:application/javascript') === 0
		if ( 0 === strpos( strtolower( str_replace( ' ', '', $content_type ) ), 'application/javascript' ) ) {
			// Replace the FPS path placeholder (including the leading slash)
			// Input: '/PHP_GTG_REPLACE_PATH/some/path' => '/metrics5?id=TAG&s=/some/path'
			$before_count = substr_count( $response['body'], '/' . self::FPS_PATH_PLACEHOLDER . '/' );
			$response['body'] = str_replace( '/' . self::FPS_PATH_PLACEHOLDER . '/', $replacement, $response['body'] );
			$after_count = substr_count( $response['body'], '/' . self::FPS_PATH_PLACEHOLDER . '/' );
			self::log( 'Rewrite JS response', [
				'replacements' => $before_count - $after_count,
				'pattern'      => '/' . self::FPS_PATH_PLACEHOLDER . '/',
			], 'debug' );

			// Also rewrite known consent mode / CCM paths that Google hardcodes without the placeholder
			// These paths are used for consent mode data collection and need to be proxied through our endpoint
			// We need to transform: "/d/ccm/form-data" => "/base_path?id=TAG&s=/d/ccm/form-data"
			$ccm_paths = [
				'"/d/ccm/form-data"',
				'"/d/ccm/conversion"',
				'"/as/d/ccm/conversion"',
				'"/g/d/ccm/conversion"',
				'"/gs/ccm/conversion"',
				'"/gs/ccm/collect"',
			];
			
			$ccm_count = 0;
			foreach ( $ccm_paths as $ccm_path ) {
				// Build the replacement - remove quotes and add to base path format
				$path_without_quotes = trim( $ccm_path, '"' );
				$ccm_replacement = '"' . $base_path . '?id=' . urlencode( $tag_id );
				if ( ! empty( $geo ) ) {
					$ccm_replacement .= '&geo=' . urlencode( $geo );
				}
				$ccm_replacement .= '&s=' . $path_without_quotes . '"';
				
				$path_count = substr_count( $response['body'], $ccm_path );
				$response['body'] = str_replace( $ccm_path, $ccm_replacement, $response['body'] );
				$ccm_count += $path_count;
			}
			
			if ( $ccm_count > 0 ) {
				self::log( 'Rewrite CCM paths', [ 'count' => $ccm_count ], 'debug' );
			}
		} elseif ( self::is_redirect_response( $status_code ) && ! empty( $response['headers'] ) ) {
			// Handle redirect responses (3xx) - rewrite Location header
			if ( isset( $response['headers']['location'] ) ) {
				$response['headers']['location'] = str_replace(
					'/' . self::FPS_PATH_PLACEHOLDER,
					$replacement,
					$response['headers']['location']
				);
				self::log( 'Rewrite redirect location', [ 'location' => $response['headers']['location'] ], 'debug' );
			}
		} else {
			self::log( 'Rewrite skipped - not JS or redirect', [ 'content_type' => $content_type ], 'debug' );
		}

		return $response;
	}

	/**
	 * Check if the response is a redirect response (3xx status code).
	 *
	 * @param int $status_code The HTTP status code.
	 * @return bool True if this is a redirect response.
	 */
	private static function is_redirect_response( $status_code ) {
		return $status_code >= 300 && $status_code < 400;
	}

	/**
	 * Output the proxy response
	 *
	 * @param array  $response Response array.
	 * @param string $method   HTTP method.
	 * @return void
	 */
	private static function output_response( $response, $method ) {
		$status_code = isset( $response['status_code'] ) ? $response['status_code'] : 200;
		$headers     = isset( $response['headers'] ) ? $response['headers'] : [];
		$body        = isset( $response['body'] ) ? $response['body'] : '';

		// Set status code
		http_response_code( $status_code );

		// Add handler identification header
		header( 'X-PMW-GTG-Handler: standalone' );

		// Forward safe headers (skip problematic ones)
		$skip_headers = [ 'transfer-encoding', 'connection', 'content-encoding', 'content-length' ];
		foreach ( $headers as $name => $value ) {
			if ( in_array( strtolower( $name ), $skip_headers, true ) ) {
				continue;
			}
			header( ucwords( $name, '-' ) . ': ' . $value );
		}

		// Set cache headers for JavaScript files
		self::set_cache_headers( $headers );

		// Allow empty responses for POST requests (beacon/tracking requests)
		$allow_empty = ( strtoupper( $method ) === 'POST' );

		if ( empty( $body ) && ! $allow_empty ) {
			self::log( 'Empty response from upstream', [ 'status_code' => $status_code ], 'warning' );
			http_response_code( 502 );
			exit( 'Empty response from upstream' );
		}

		// Set content length and output
		if ( ! empty( $body ) ) {
			header( 'Content-Length: ' . strlen( $body ) );
		}

		self::log( 'Response sent', [ 'status' => $status_code, 'size' => strlen( $body ) ], 'debug' );

		// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Proxied response body from Google FPS, escaping would break JavaScript/binary content
		echo $body;
		exit;
	}

	/**
	 * Set cache headers based on content type
	 *
	 * @param array $headers Response headers.
	 * @return void
	 */
	private static function set_cache_headers( $headers ) {
		$content_type = isset( $headers['content-type'] ) ? $headers['content-type'] : '';

		// Cache JavaScript files in browser for 6 hours
		if ( strpos( $content_type, 'javascript' ) !== false || strpos( $content_type, 'json' ) !== false ) {
			$cache_duration = 21600; // 6 hours
			header( 'Cache-Control: private, max-age=' . $cache_duration );
			header( 'Expires: ' . gmdate( 'D, d M Y H:i:s', time() + $cache_duration ) . ' GMT' );
		} else {
			// For non-JS responses, don't cache
			header( 'Cache-Control: no-store, no-cache, must-revalidate' );
			header( 'Pragma: no-cache' );
		}
	}

	/**
	 * Send health response
	 *
	 * @return void
	 */
	private static function send_health_response() {
		http_response_code( 200 );
		header( 'Content-Type: text/plain; charset=utf-8' );
		header( 'X-PMW-GTG-Handler: standalone' );
		echo 'ok';
		exit;
	}
}

// Run the proxy
PMW_GTG_Proxy_Standalone::run();
