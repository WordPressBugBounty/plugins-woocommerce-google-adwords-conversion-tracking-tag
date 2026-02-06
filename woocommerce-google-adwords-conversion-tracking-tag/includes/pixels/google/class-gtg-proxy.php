<?php

namespace SweetCode\Pixel_Manager\Pixels\Google;

use SweetCode\Pixel_Manager\Geolocation;
use SweetCode\Pixel_Manager\Helpers;
use SweetCode\Pixel_Manager\Logger;
use SweetCode\Pixel_Manager\Options;

defined('ABSPATH') || exit; // Exit if accessed directly

/**
 * Google Tag Gateway Proxy
 *
 * Handles proxying Google Tag requests through WordPress to Google's First-Party Servers (FPS).
 * This enables first-party context for tracking, bypassing ad blockers and improving cookie longevity.
 *
 * @since 1.53.0
 */
class GTG_Proxy {

	/**
	 * REST API namespace
	 *
	 * @var string
	 */
	private static $rest_namespace = 'pmw/v1';

	/**
	 * Timeout for proxy requests in seconds.
	 *
	 * @var int
	 */
	private static $request_timeout = 5;

	/**
	 * Maximum allowed request body size in bytes (1MB).
	 *
	 * @var int
	 */
	private static $max_request_body_size = 1048576;

	/**
	 * Maximum allowed response body size in bytes (5MB).
	 *
	 * @var int
	 */
	private static $max_response_body_size = 5242880;

	/**
	 * Rate limit: maximum requests per minute per IP.
	 *
	 * @var int
	 */
	private static $rate_limit_max_requests = 100;

	/**
	 * Reserved headers that should not be forwarded to Google FPS
	 *
	 * These headers are either managed by PHP/curl automatically,
	 * or are sensitive headers that should not be forwarded.
	 *
	 * Note: CONTENT_TYPE is NOT in this list because it needs to be
	 * forwarded for POST requests (tracking beacons, etc.)
	 *
	 * @var array
	 */
	private static $reserved_headers = [
		// PHP managed headers - auto populated by curl or file_get_contents
		'HTTP_ACCEPT_ENCODING',
		'HTTP_CONNECTION',
		'HTTP_CONTENT_LENGTH',
		'CONTENT_LENGTH',
		'HTTP_EXPECT',
		'HTTP_HOST',
		'HTTP_TRANSFER_ENCODING',
		// Sensitive headers to exclude from all requests
		'HTTP_AUTHORIZATION',
		'HTTP_PROXY_AUTHORIZATION',
		'HTTP_X_API_KEY',
	];

	/**
	 * Valid Google Tag ID prefixes
	 *
	 * @var array
	 */
	private static $valid_tag_prefixes = [
		'AW',  // Google Ads
		'G',   // GA4
		'GT',  // Google Tag
		'DC',  // DoubleClick/Floodlight
	];

	/**
	 * FPS path placeholder used by Google's response.
	 *
	 * When we send requests to Google FPS with this placeholder in the URL,
	 * Google returns responses containing this placeholder which we then
	 * rewrite to point back to our proxy.
	 *
	 * @var string
	 */
	private static $fps_path_placeholder = 'PHP_GTG_REPLACE_PATH';

	/**
	 * Initialize the proxy - called from Pixel_Manager
	 *
	 * @return void
	 */
	public static function init() {
		add_action('rest_api_init', [ __CLASS__, 'register_rest_routes' ]);
		add_filter('do_parse_request', [ __CLASS__, 'maybe_handle_proxy_request' ], 10, 3);

		// Flush rewrite rules when the PMW options change (measurement path might have changed)
		add_action('update_option_wgact_plugin_options', [ __CLASS__, 'maybe_flush_rewrite_rules' ], 10, 2);

		// Ensure rewrite rules are registered after plugin upgrade
		// This handles the case where GTG was enabled in an older version before the proxy was available
		self::maybe_flush_rewrite_rules_on_upgrade();
		
		// Ensure isolated proxy file exists and is up to date
		add_action('init', [ __CLASS__, 'ensure_isolated_proxy_file' ], 21);
	}

	/**
	 * Check if the Google Tag Gateway rewrite rules are registered in WordPress
	 *
	 * This checks if the measurement path is present in the WordPress rewrite rules array.
	 * If not, the rules need to be flushed to register the GTG proxy endpoints.
	 *
	 * @return bool True if rules exist, false if flush is needed.
	 *
	 * @since 1.53.0
	 */
	public static function gtg_rewrite_rules_exist() {

		$rewrite_rules = get_option( 'rewrite_rules', [] );

		if ( empty( $rewrite_rules ) || ! is_array( $rewrite_rules ) ) {
			return false;
		}

		$measurement_path = Options::get_google_tag_gateway_measurement_path();

		if ( ! $measurement_path ) {
			return true; // No measurement path configured, no rules needed
		}

		// Remove leading slash for rewrite rule pattern matching
		$measurement_path_pattern = ltrim( $measurement_path, '/' );

		// Check for the GTG measurement path in the rewrite rules
		foreach ( $rewrite_rules as $pattern => $rewrite ) {
			if ( strpos( $pattern, $measurement_path_pattern ) !== false ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Flush rewrite rules on plugin upgrade if GTG is enabled but rules don't exist
	 *
	 * This handles the scenario where a user upgrades from a version where the
	 * Google Tag Gateway was enabled but the local proxy wasn't available yet.
	 * After the upgrade, we need to ensure the rewrite rules are registered.
	 *
	 * @return void
	 *
	 * @since 1.53.0
	 */
	public static function maybe_flush_rewrite_rules_on_upgrade() {

		// Only run in admin context to avoid frontend performance impact
		if ( ! is_admin() ) {
			return;
		}

		// Check if rules already exist
		if ( self::gtg_rewrite_rules_exist() ) {
			return;
		}

		// Rules don't exist but GTG is enabled - flush to register them
		flush_rewrite_rules();
	}

	/**
	 * Flush rewrite rules if the measurement path has changed
	 *
	 * @param mixed $old_value The old option value.
	 * @param mixed $new_value The new option value.
	 * @return void
	 */
	public static function maybe_flush_rewrite_rules( $old_value, $new_value ) {

		$old_path = isset( $old_value['google']['tag_gateway']['measurement_path'] ) ? $old_value['google']['tag_gateway']['measurement_path'] : '';
		$new_path = isset( $new_value['google']['tag_gateway']['measurement_path'] ) ? $new_value['google']['tag_gateway']['measurement_path'] : '';

		// Always update configuration cache for isolated proxy when options are saved
		// This ensures logging settings and other config changes are reflected
		self::update_proxy_config_cache();

		// If the measurement path has changed, flush rewrite rules
		if ( $old_path !== $new_path ) {
			flush_rewrite_rules();

			// Clear and refresh GTG handler cache since path changed
			GTG_Config::refresh_handler();
		}
	}

	/**
	 * Register REST API routes for the proxy
	 *
	 * @return void
	 */
	public static function register_rest_routes() {

		// Only register if measurement path is set
		if (!Options::get_google_tag_gateway_measurement_path()) {
			return;
		}

		// Health check endpoint
		// nosemgrep: audit.php.wp.security.rest-route.permission-callback.return-true
		register_rest_route(self::$rest_namespace, '/gtg-proxy/health/', [
			'methods'             => 'GET',
			'callback'            => [ __CLASS__, 'handle_health_check' ],
			'permission_callback' => '__return_true',
		]);

		// Main proxy endpoint - handles GET and POST
		// nosemgrep: audit.php.wp.security.rest-route.permission-callback.return-true
		register_rest_route(self::$rest_namespace, '/gtg-proxy/', [
			'methods'             => [ 'GET', 'POST' ],
			'callback'            => [ __CLASS__, 'handle_proxy_request' ],
			'permission_callback' => '__return_true',
		]);
	}

	/**
	 * Early check to see if this is a proxy request
	 *
	 * This runs before WordPress parses the request.
	 * We check if the request URI starts with our measurement path
	 * and if so, handle it directly.
	 *
	 * Also handles secondary Google Tag requests that come to the root
	 * with ?id=...&cx=...&gtm=... parameters.
	 *
	 * @param bool   $do_parse Whether to parse the request.
	 * @param \WP    $wp       The WordPress environment instance.
	 * @param array  $extra_query_vars Extra query vars.
	 * @return bool Whether to continue parsing the request.
	 */
	public static function maybe_handle_proxy_request( $do_parse, $wp, $extra_query_vars ) {

		// Quick check: get the raw request URI first to avoid unnecessary processing
		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- We need the raw URI for path matching
		$request_uri = isset( $_SERVER['REQUEST_URI'] ) ? $_SERVER['REQUEST_URI'] : '';

		// Skip static files immediately - these should never be proxied
		if (preg_match('/\.(js|css|png|jpg|jpeg|gif|ico|svg|woff|woff2|ttf|eot|map)(\?|$)/i', $request_uri)) {
			return $do_parse;
		}

		// Skip wp-admin and wp-includes paths
		if (strpos($request_uri, '/wp-admin/') === 0 || strpos($request_uri, '/wp-includes/') === 0) {
			return $do_parse;
		}

		$measurement_path = Options::get_google_tag_gateway_measurement_path();

		if (!$measurement_path) {
			return $do_parse;
		}

		// Check if the request starts with our measurement path (primary proxy requests)
		if (strpos($request_uri, $measurement_path) === 0) {
			// This is our request - handle it
			self::handle_rewrite_request();
			// We should never reach here because handle_rewrite_request calls die()
			return $do_parse;
		}

		// Check if this is a secondary Google Tag request to the root
		// These come as /?id=TAG_ID&cx=c&gtm=...
		// They have 'id' parameter with a Google tag ID pattern and 'gtm' parameter
		$_get = Helpers::get_input_vars(INPUT_GET);

		if (
			isset( $_get['id'] )
			&& isset( $_get['gtm'] )
			&& self::is_valid_google_tag_id( $_get['id'] )
			&& ( 0 === strpos( $request_uri, '/?' ) || '/' === $request_uri )
		) {
			// This is a secondary Google request - proxy it
			self::handle_secondary_google_request($_get);
			// We should never reach here because the handler calls die()
			return $do_parse;
		}

		return $do_parse;
	}

	/**
	 * Handle secondary Google Tag requests
	 *
	 * These are requests made by gtag.js itself after the initial script load.
	 * They come to the root path with parameters like ?id=TAG&cx=c&gtm=...
	 *
	 * @param array $_get The GET parameters.
	 * @return void
	 */
	private static function handle_secondary_google_request( $_get ) {

		$tag_id = sanitize_text_field($_get['id']);

		self::log_proxy_event(
			'Handling secondary Google request',
			[
				'tag_id'     => $tag_id,
				'params'     => array_keys( $_get ),
				'gtm_param'  => isset( $_get['gtm'] ) ? $_get['gtm'] : '',
			],
			'info'
		);

		// Build the path that Google expects
		// The request comes as /?id=TAG&cx=c&gtm=... and we need to forward it to FPS
		$destination_path = '/';

		// Rebuild the query string
		$query_params = [];
		foreach ($_get as $key => $value) {
			$query_params[] = urlencode($key) . '=' . urlencode($value);
		}
		$destination_path .= '?' . implode('&', $query_params);

		// Get geo parameter if present
		$geo = isset($_get['geo']) ? sanitize_text_field($_get['geo']) : '';

		// Process the proxy request
		$_server = Helpers::get_input_vars( INPUT_SERVER );
		$method  = isset( $_server['REQUEST_METHOD'] ) ? $_server['REQUEST_METHOD'] : 'GET';
		$body   = 'POST' === $method ? file_get_contents('php://input') : '';

		$response = self::process_and_return_proxy_response($tag_id, $destination_path, $geo, $method, $body);

		// Output response and terminate (allows empty responses for beacon requests)
		self::output_proxy_response( $response, true );
	}

	/**
	 * Handle requests coming through the rewrite rules
	 *
	 * @return void
	 */
	public static function handle_rewrite_request() {

		$measurement_path = Options::get_google_tag_gateway_measurement_path();
		$_server          = Helpers::get_input_vars( INPUT_SERVER );
		$request_uri      = isset( $_server['REQUEST_URI'] ) ? $_server['REQUEST_URI'] : '';

		self::log_proxy_event(
			'Handling rewrite request',
			[
				'request_uri'      => $request_uri,
				'measurement_path' => $measurement_path,
				'method'           => isset( $_server['REQUEST_METHOD'] ) ? $_server['REQUEST_METHOD'] : 'unknown',
			],
			'info'
		);

		if (!$measurement_path || strpos($request_uri, $measurement_path) !== 0) {
			return;
		}

		// Extract the path after the measurement path
		$path = substr($request_uri, strlen($measurement_path));

		// Remove query string from path
		if (strpos($path, '?') !== false) {
			$path = substr($path, 0, strpos($path, '?'));
		}
		$path = ltrim($path, '/');

		// Handle health check
		if ('healthy' === $path) {
			self::send_health_response();
			// send_health_response uses die(), so we never reach here
		}

		// Get query parameters
		$_get = Helpers::get_input_vars(INPUT_GET);

		// Check if we have the 's' parameter (standard proxy format)
		// Format: /measurement-path/?id=TAG&s=/gtag/js
		if (!empty($_get['s'])) {
			self::process_proxy_request($_get['s']);
			// process_proxy_request uses die(), so we never reach here
		}

		// Path-based format: /measurement-path/gtag/js?id=TAG
		if ($path) {
			self::process_proxy_request($path);
			// process_proxy_request uses die(), so we never reach here
		}

		// No path specified - error
		status_header(400);
		die('No path specified');
	}

	/**
	 * Handle health check requests
	 *
	 * @return \WP_REST_Response
	 */
	public static function handle_health_check() {
		return new \WP_REST_Response('ok', 200);
	}

	/**
	 * Send health response for rewrite-based requests
	 *
	 * @return void
	 */
	private static function send_health_response() {
		status_header(200);
		header('Content-Type: text/plain; charset=utf-8');
		header('X-PMW-GTG-Handler: wordpress');
		die('ok');
	}

	/**
	 * Handle proxy requests from the REST API
	 *
	 * @param \WP_REST_Request $request The REST request object.
	 * @return \WP_REST_Response|\WP_Error The response.
	 */
	public static function handle_proxy_request( $request ) {

		$param_id    = $request->get_param( 'id' );
		$param_s     = $request->get_param( 's' );
		$param_geo   = $request->get_param( 'geo' );
		$param_mpath = $request->get_param( 'mpath' );

		$tag_id = sanitize_text_field( isset( $param_id ) ? $param_id : '' );
		$path   = sanitize_text_field( isset( $param_s ) ? $param_s : '' );
		$geo    = sanitize_text_field( isset( $param_geo ) ? $param_geo : '' );
		$mpath  = sanitize_text_field( isset( $param_mpath ) ? $param_mpath : '' );

		self::log_proxy_event(
			'REST API proxy request received',
			[
				'tag_id' => $tag_id,
				'path'   => $path,
				'geo'    => $geo,
				'mpath'  => $mpath,
				'method' => $request->get_method(),
			],
			'info'
		);

		// If no ID or path provided, check if it's a direct path request
		if (!$tag_id && !$path) {
			self::log_proxy_event( 'Missing required parameters in REST API request', [], 'warning' );
			return new \WP_Error('missing_parameters', 'Missing required parameters: id and s', [ 'status' => 400 ]);
		}

		return self::process_and_return_proxy_response($tag_id, $path, $geo, $request->get_method(), $request->get_body(), $mpath);
	}

	/**
	 * Process a proxy request coming through the measurement path
	 *
	 * @param string $path The path after the measurement path.
	 * @return void
	 */
	private static function process_proxy_request( $path ) {

		$_server = Helpers::get_input_vars(INPUT_SERVER);
		$_get    = Helpers::get_input_vars(INPUT_GET);

		// Get the tag ID and other proxy params from query params
		$tag_id = isset($_get['id']) ? sanitize_text_field($_get['id']) : '';
		$geo    = isset($_get['geo']) ? sanitize_text_field($_get['geo']) : '';
		// Don't use sanitize_text_field for s_path as it may contain query parameters
		$s_path = isset($_get['s']) ? $_get['s'] : '';
		$mpath  = isset($_get['mpath']) ? sanitize_text_field($_get['mpath']) : '';

		self::log_proxy_event(
			'Processing proxy request via measurement path',
			[
				'path'   => $path,
				'tag_id' => $tag_id,
				's_path' => $s_path,
				'geo'    => $geo,
				'mpath'  => $mpath,
			],
			'info'
		);

		// Build destination path following reference implementation logic
		$destination_path = self::build_destination_path($s_path, $_get, $tag_id);

		// Get request method and body
		$method = isset( $_server['REQUEST_METHOD'] ) ? $_server['REQUEST_METHOD'] : 'GET';
		$body   = file_get_contents('php://input');

		// Process the request
		$response = self::process_and_return_proxy_response($tag_id, $destination_path, $geo, $method, $body, $mpath);

		// Output response and terminate
		// Allow empty responses for POST requests (beacon/tracking requests legitimately return empty bodies)
		$allow_empty = ( 'POST' === strtoupper( $method ) );
		self::output_proxy_response( $response, $allow_empty );
	}

	/**
	 * Process a proxy request and return the response
	 *
	 * @param string $tag_id The Google Tag ID.
	 * @param string $path   The destination path.
	 * @param string $geo    Geographic information.
	 * @param string $method The HTTP method.
	 * @param string $body   The request body.
	 * @param string $mpath  Optional custom measurement path override.
	 * @return \WP_REST_Response|\WP_Error The response.
	 */
	private static function process_and_return_proxy_response( $tag_id, $path, $geo, $method, $body, $mpath = '' ) {

		// Log incoming request
		self::log_proxy_event(
			'Incoming proxy request',
			[
				'method'    => $method,
				'tag_id'    => $tag_id,
				'path'      => $path,
				'geo'       => $geo,
				'body_size' => strlen( $body ),
				'client_ip' => self::get_client_ip(),
			],
			'info'
		);

		// Check rate limiting (disabled by default, can be enabled via filter)
		if ( self::is_rate_limited() ) {
			self::log_proxy_event( 'Request rate limited', [ 'client_ip' => self::get_client_ip() ], 'warning' );
			return new \WP_Error( 'rate_limited', 'Too many requests', [ 'status' => 429 ] );
		}

		// Validate request body size (1MB limit - Google Tag requests are typically a few KB)
		if ( strlen( $body ) > self::$max_request_body_size ) {
			self::log_proxy_event( 'Request body too large', [ 'size' => strlen( $body ) ] );
			return new \WP_Error( 'body_too_large', 'Request body exceeds maximum size', [ 'status' => 413 ] );
		}

		// Validate tag ID
		if (!self::validate_tag_id($tag_id)) {
			self::log_proxy_event( 'Invalid tag ID format', [ 'tag_id' => $tag_id ], 'warning' );
			return new \WP_Error('invalid_tag_id', 'Invalid Google Tag ID format', [ 'status' => 400 ]);
		}

		// Sanitize path
		$original_path = $path;
		$path          = self::sanitize_path($path);
		if (!$path) {
			self::log_proxy_event( 'Invalid path after sanitization', [ 'original_path' => $original_path ], 'warning' );
			return new \WP_Error('invalid_path', 'Invalid path', [ 'status' => 400 ]);
		}

		// Determine which mpath to use for the FPS URL
		// If a custom mpath is provided, use it; otherwise use the default placeholder
		$use_mpath = empty( $mpath ) ? self::$fps_path_placeholder : $mpath;

		// Build the FPS URL
		$fps_url = self::build_fps_url($tag_id, $path, $use_mpath);

		self::log_proxy_event(
			'FPS URL constructed',
			[
				'fps_url'    => $fps_url,
				'tag_id'     => $tag_id,
				'path'       => $path,
				'use_mpath'  => $use_mpath,
			]
		);

		// Validate the constructed URL points to Google FPS (SSRF protection)
		if ( ! self::is_valid_fps_url( $fps_url ) ) {
			self::log_proxy_event( 'Invalid FPS URL constructed', [ 'url' => $fps_url ], 'error' );
			return new \WP_Error( 'invalid_fps_url', 'Invalid FPS URL', [ 'status' => 400 ] );
		}

		// Get headers to forward
		$headers = self::get_forwarded_headers($geo);

		self::log_proxy_event(
			'Sending request to Google FPS',
			[
				'method'        => $method,
				'url'           => $fps_url,
				'headers_count' => count( $headers ),
			]
		);

		// Make the request to Google FPS
		$response = self::send_request($method, $fps_url, $headers, $body);

		if (is_wp_error($response)) {
			self::log_proxy_event(
				'FPS request failed',
				[
					'error'      => $response->get_error_message(),
					'error_code' => $response->get_error_code(),
					'url'        => $fps_url,
					'method'     => $method,
				],
				'error'
			);
			return $response;
		}

		// Log successful FPS response
		self::log_proxy_event(
			'FPS response received',
			[
				'status_code'  => isset( $response['status_code'] ) ? $response['status_code'] : 'unknown',
				'body_size'    => isset( $response['body'] ) ? strlen( $response['body'] ) : 0,
				'content_type' => isset( $response['headers']['content-type'] ) ? $response['headers']['content-type'] : 'unknown',
			],
			'info'
		);

		// Validate response body size (5MB limit - gtag.js is typically 100-200KB)
		if ( isset( $response['body'] ) && strlen( $response['body'] ) > self::$max_response_body_size ) {
			self::log_proxy_event( 'Response body too large', [ 'size' => strlen( $response['body'] ) ], 'error' );
			return new \WP_Error( 'response_too_large', 'Response exceeds maximum size', [ 'status' => 502 ] );
		}

		// Rewrite paths in the response if it's JavaScript
		// Only rewrite if we used the default FPS path placeholder
		if ( empty( $mpath ) || $mpath === self::$fps_path_placeholder ) {
			$response = self::rewrite_response_paths($response, $tag_id, $geo);
		}

		$status_code = isset( $response['status_code'] ) ? $response['status_code'] : 200;

		self::log_proxy_event(
			'Proxy request completed successfully',
			[
				'tag_id'      => $tag_id,
				'status_code' => $status_code,
			],
			'info'
		);

		return new \WP_REST_Response( $response, $status_code );
	}

	/**
	 * Validate a Google Tag ID
	 *
	 * Matches the reference implementation's validation:
	 * allows any alphanumeric characters with hyphens.
	 * This is more permissive to support future Google tag formats.
	 *
	 * @param string $tag_id The tag ID to validate.
	 * @return bool True if valid, false otherwise.
	 */
	private static function validate_tag_id( $tag_id ) {

		if (empty($tag_id)) {
			return false;
		}

		// Validate format: alphanumeric with hyphens (matches reference implementation)
		// This allows: AW-123456789, G-XXXXXXXX, GT-XXXXX, DC-XXXXX, and future formats
		if (!preg_match('/^[A-Za-z0-9-]+$/', $tag_id)) {
			return false;
		}

		return true;
	}

	/**
	 * Check if a string looks like a valid Google Tag ID
	 *
	 * This is a simpler check than validate_tag_id, used for
	 * detecting secondary Google requests.
	 *
	 * @param string $tag_id The tag ID to check.
	 * @return bool True if it looks like a Google Tag ID.
	 */
	private static function is_valid_google_tag_id( $tag_id ) {

		if (empty($tag_id)) {
			return false;
		}

		// Check for valid prefixes: AW-, G-, GT-, DC-
		return (bool) preg_match('/^(AW|G|GT|DC)-[A-Za-z0-9]+$/', $tag_id);
	}

	/**
	 * Set appropriate cache headers based on content type
	 *
	 * For JavaScript files (gtag.js), cache in browser for 6 hours (default).
	 * For tracking beacons and other requests, don't cache.
	 *
	 * @param array $data The response data containing headers.
	 * @return void
	 */
	private static function set_cache_headers( $data ) {

		$content_type = isset( $data['headers']['content-type'] ) ? $data['headers']['content-type'] : '';

		// Cache JavaScript files in browser for 6 hours (default)
		// This prevents repeated PHP requests for the same gtag.js within a session
		if ( false !== strpos( $content_type, 'javascript' ) || false !== strpos( $content_type, 'application/json' ) ) {
			/**
			 * Filter the cache duration for Google Tag Gateway proxy JavaScript responses.
			 *
			 * @since 1.53.0
			 *
			 * @param int $cache_duration Cache duration in seconds. Default 21600 (6 hours).
			 */
			$cache_duration = apply_filters( 'pmw_google_tag_gateway_cache_duration', 21600 );
			$cache_duration = absint( $cache_duration );

			header( 'Cache-Control: private, max-age=' . $cache_duration, true );
			header( 'Expires: ' . gmdate( 'D, d M Y H:i:s', time() + $cache_duration ) . ' GMT', true );
		} else {
			// For non-JS responses (like tracking beacons), don't cache
			header( 'Cache-Control: no-store, no-cache, must-revalidate', true );
			header( 'Pragma: no-cache', true );
		}
	}

	/**
	 * Output a proxy response and terminate execution.
	 *
	 * This method handles sending the response to the client including:
	 * - Error responses (WP_Error)
	 * - Empty responses (beacon requests)
	 * - Full responses with headers and body
	 *
	 * @param \WP_REST_Response|\WP_Error $response             The response to output.
	 * @param bool                        $allow_empty_response Whether to allow empty responses. Default true.
	 * @return void
	 */
	private static function output_proxy_response( $response, $allow_empty_response = true ) {

		// If it's a WP_Error, send error response
		if ( is_wp_error( $response ) ) {
			$error_data = $response->get_error_data();
			status_header( isset( $error_data['status'] ) ? $error_data['status'] : 500 );
			die( esc_html( $response->get_error_message() ) );
		}

		$data = $response->get_data();

		// Check if we have a valid body
		if ( ! isset( $data['body'] ) || empty( $data['body'] ) ) {
			if ( $allow_empty_response ) {
				// Empty response is OK for some Google requests (like beacon requests)
				status_header( isset( $data['status_code'] ) ? $data['status_code'] : 200 );
				die();
			} else {
				self::log_proxy_event( 'Empty response from upstream', [ 'status_code' => isset( $data['status_code'] ) ? $data['status_code'] : 'unknown' ], 'error' );
				status_header( 502 );
				die( 'Empty response from upstream' );
			}
		}

		// Set status code
		if ( isset( $data['status_code'] ) ) {
			http_response_code( $data['status_code'] );
		}

		// Set headers
		if ( isset( $data['headers'] ) ) {
			$skip_headers = [ 'transfer-encoding', 'connection', 'content-encoding', 'content-length', 'cache-control', 'expires', 'pragma' ];
			foreach ( $data['headers'] as $name => $value ) {
				if ( in_array( strtolower( $name ), $skip_headers, true ) ) {
					continue;
				}
				// Handle headers that may have multiple values (returned as arrays)
				if ( is_array( $value ) ) {
					$value = implode( ', ', $value );
				}
				header( "$name: $value", true );
			}
		}

		// Add browser caching headers for JavaScript files
		self::set_cache_headers( $data );

		// Set content length and output body
		$body_content = $data['body'];
		header( 'Content-Length: ' . strlen( $body_content ), true );

		// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Proxied content from Google
		die( $body_content );
	}

	/**
	 * Sanitize the path to prevent SSRF and path traversal
	 *
	 * This method performs two critical security actions:
	 * 1. Normalizes the path to resolve directory traversal segments like '.' and '..'
	 *    to prevent path traversal attacks.
	 * 2. URL-encodes path segments to prevent injection attacks.
	 *
	 * Note: This method only sanitizes the PATH portion. Query strings are handled
	 * separately by build_destination_path() using http_build_query() which properly
	 * encodes all parameters. This matches the reference implementation behavior.
	 *
	 * @param string $path The path to sanitize (may include query string).
	 * @return string|false The sanitized path or false if invalid.
	 */
	private static function sanitize_path( $path ) {

		// Default empty path to '/' as per reference implementation
		// When measurement path is present it might accidentally pass an empty
		// path character depending on how the url rules are processed
		if (empty($path)) {
			$path = '/';
		}

		// Separate path and query string
		$query_string = '';
		if (strpos($path, '?') !== false) {
			list($path, $query_string) = explode('?', $path, 2);
		}

		// Normalize directory separators (handle Windows-style paths)
		$path = str_replace('\\', '/', $path);

		// Normalize the path by resolving '..' and '.' segments
		$parts    = [];
		$segments = explode('/', trim($path, '/'));

		foreach ($segments as $segment) {
			// Ignore current directory references and empty segments
			if ('.' === $segment || '' === $segment) {
				continue;
			}

			if ('..' === $segment) {
				// Go up one level - remove the last part
				if (null === array_pop($parts)) {
					// Attempted traversal beyond root - reject the path
					return false;
				}
			} else {
				// URL-encode the segment to prevent injection
				$parts[] = rawurlencode($segment);
			}
		}

		// Rebuild the sanitized path
		$sanitized_path = '/' . implode('/', $parts);

		// Validate the final path contains only allowed characters
		// Allow: letters, numbers, slashes, hyphens, underscores, dots, percent (for encoded chars)
		if (!preg_match('/^[a-zA-Z0-9\/_\-\.%]+$/', $sanitized_path)) {
			return false;
		}

		// Re-append query string if present
		// Note: Query string is not validated here as it's already properly encoded
		// by build_destination_path() using http_build_query(). The reference
		// implementation also doesn't validate query strings in path sanitization.
		if (!empty($query_string)) {
			$sanitized_path .= '?' . $query_string;
		}

		return $sanitized_path;
	}

	/**
	 * Build the FPS URL
	 *
	 * @param string $tag_id The Google Tag ID.
	 * @param string $path   The destination path.
	 * @param string $mpath  The measurement path to include in the URL.
	 * @return string The FPS URL.
	 */
	private static function build_fps_url( $tag_id, $path, $mpath = '' ) {

		// Use the default placeholder if no mpath provided
		if ( empty( $mpath ) ) {
			$mpath = self::$fps_path_placeholder;
		}

		// Build the FPS URL
		// Google's FPS responds with paths containing '/PHP_GTG_REPLACE_PATH/'
		// (or the custom mpath) which we replace in the response with our proxy URL
		return 'https://' . $tag_id . '.fps.goog/' . $mpath . $path;
	}

	/**
	 * Build the destination path following reference implementation logic.
	 *
	 * This method:
	 * 1. Takes the 's' path parameter as the base
	 * 2. If 's' contains query parameters, encodes them properly
	 * 3. Appends all remaining query parameters (excluding reserved: id, s, geo, mpath)
	 *
	 * @param string $s_path      The 's' query parameter value.
	 * @param array  $query_params All query parameters from the request.
	 * @param string $tag_id      The tag ID (used for gtag/js requests).
	 * @return string The constructed destination path.
	 */
	private static function build_destination_path( $s_path, $query_params, $tag_id ) {

		$path = $s_path;

		// When measurement path is present it might accidentally pass an empty
		// path character depending on how the url rules are processed so as a
		// safety when path is empty we should assume that it is a request to
		// the root.
		if ( empty( $path ) ) {
			$path = '/';
		}

		// Remove reserved query parameters from the params to append
		$params = $query_params;
		unset( $params['id'], $params['s'], $params['geo'], $params['mpath'] );

		// Check if the 's' path already contains query parameters
		$contains_query_parameters = strpos( $path, '?' ) !== false;

		if ( $contains_query_parameters ) {
			// Split path and query, then encode the query portion
			list( $base_path, $query ) = explode( '?', $path, 2 );
			$path = $base_path . '?' . self::encode_query_parameter( $query );
		}

		// Append remaining query parameters
		if ( ! empty( $params ) ) {
			$param_separator = $contains_query_parameters ? '&' : '?';
			$path           .= $param_separator . http_build_query( $params, '', '&', PHP_QUERY_RFC3986 );
		}

		return $path;
	}

	/**
	 * Encode a single query parameter's key and value.
	 *
	 * Takes a single URL query parameter which has not been encoded and
	 * ensures its key & value are encoded.
	 *
	 * @param string $parameter Query parameter string (key=value format).
	 * @return string The encoded query parameter.
	 */
	private static function encode_query_parameter( $parameter ) {

		$parts = explode( '=', $parameter, 2 );
		$key   = isset( $parts[0] ) ? $parts[0] : '';
		$value = isset( $parts[1] ) ? $parts[1] : '';

		// Manually encode to avoid nuances with http_build_query
		// (e.g., it adds indexes to repeated parameters)
		$key   = rawurlencode( $key );
		$value = rawurlencode( $value );

		return "{$key}={$value}";
	}

	/**
	 * Validate that a URL is a valid Google FPS domain.
	 *
	 * This prevents SSRF attacks by ensuring the constructed URL
	 * actually points to a legitimate Google FPS endpoint.
	 *
	 * @param string $url The URL to validate.
	 * @return bool True if valid, false otherwise.
	 */
	private static function is_valid_fps_url( $url ) {

		$parsed = wp_parse_url( $url );

		if ( ! $parsed || empty( $parsed['host'] ) ) {
			return false;
		}

		// Ensure the host ends with .fps.goog
		$host = strtolower( $parsed['host'] );
		if ( '.fps.goog' !== substr( $host, -9 ) ) {
			return false;
		}

		// Ensure HTTPS scheme
		if ( empty( $parsed['scheme'] ) || 'https' !== strtolower( $parsed['scheme'] ) ) {
			return false;
		}

		return true;
	}

	/**
	 * Check if the current request should be rate limited.
	 *
	 * Rate limiting is disabled by default and can be enabled via filter.
	 * When enabled, limits requests per IP to prevent abuse.
	 *
	 * @return bool True if rate limited (should block), false otherwise.
	 */
	private static function is_rate_limited() {

		$client_ip = self::get_client_ip();

		/**
		 * Filter to enable rate limiting on the Google Tag Gateway proxy.
		 *
		 * Rate limiting is disabled by default as most production sites
		 * use CDNs with built-in rate limiting, and Google also rate limits
		 * on their FPS endpoints.
		 *
		 * @since 1.53.0
		 *
		 * @param bool   $enable_rate_limiting Whether to enable rate limiting. Default false.
		 * @param string $client_ip            The client IP address.
		 */
		$enable_rate_limiting = apply_filters( 'pmw_gtg_proxy_enable_rate_limiting', false, $client_ip );

		if ( ! $enable_rate_limiting ) {
			return false;
		}

		if ( empty( $client_ip ) ) {
			return false;
		}

		$transient_key = 'pmw_gtg_rate_' . md5( $client_ip );
		$request_count = get_transient( $transient_key );

		if ( false === $request_count ) {
			set_transient( $transient_key, 1, MINUTE_IN_SECONDS );
			return false;
		}

		/**
		 * Filter the maximum requests per minute for rate limiting.
		 *
		 * @since 1.53.0
		 *
		 * @param int    $max_requests Maximum requests per minute. Default 100.
		 * @param string $client_ip    The client IP address.
		 */
		$max_requests = apply_filters( 'pmw_gtg_proxy_rate_limit_max_requests', self::$rate_limit_max_requests, $client_ip );

		if ( $request_count >= $max_requests ) {
			self::log_proxy_event( 'Rate limit exceeded', [ 'ip' => $client_ip, 'count' => $request_count ] );
			return true;
		}

		set_transient( $transient_key, $request_count + 1, MINUTE_IN_SECONDS );
		return false;
	}

	/**
	 * Log proxy events for debugging.
	 *
	 * Uses the PMW Logger which respects logging settings.
	 *
	 * @param string $message The message to log.
	 * @param array  $context Additional context data.
	 * @param string $level   The log level (debug, info, warning, error). Default 'debug'.
	 * @return void
	 */
	private static function log_proxy_event( $message, $context = [], $level = 'debug' ) {

		$log_message = sprintf(
			'[GTG-Proxy-WordPress] %s | Context: %s',
			$message,
			wp_json_encode( $context )
		);

		switch ( $level ) {
			case 'info':
				Logger::info( $log_message );
				break;
			case 'warning':
				Logger::warning( $log_message );
				break;
			case 'error':
				Logger::error( $log_message );
				break;
			default:
				Logger::debug( $log_message );
				break;
		}
	}

	/**
	 * Get headers to forward to Google FPS
	 *
	 * @param string $geo Geographic information.
	 * @return array The headers to forward.
	 */
	private static function get_forwarded_headers( $geo = '' ) {

		$_server = Helpers::get_input_vars(INPUT_SERVER);
		$headers = [];

		// Extra headers not prefixed with `HTTP_` that should be forwarded
		// This matches the reference implementation's behavior
		$extra_headers = [
			'CONTENT_TYPE'   => 'content-type',
			'CONTENT_LENGTH' => 'content-length',
			'CONTENT_MD5'    => 'content-md5',
		];

		// Forward relevant headers
		foreach ($_server as $key => $value) {

			// Skip reserved headers
			if (in_array($key, self::$reserved_headers, true)) {
				continue;
			}

			$header_name = '';

			// Check if this is an HTTP_* header
			if (strpos($key, 'HTTP_') === 0) {
				// Convert HTTP_HEADER_NAME to header-name (lowercase with dashes)
				// This matches the reference implementation's convention
				$header_name = strtolower(str_replace('_', '-', substr($key, 5)));
			} elseif (isset($extra_headers[$key])) {
				// Handle extra headers not prefixed with HTTP_
				$header_name = $extra_headers[$key];
			}

			if (!empty($header_name) && !empty($value)) {
				$headers[$header_name] = $value;
			}
		}

		// Add forwarded-for header with the real client IP
		$client_ip = self::get_client_ip();
		if ($client_ip) {
			$headers['x-forwarded-for'] = $client_ip;
		}

		// Add geo header if provided
		if (!empty($geo)) {
			$headers['x-forwarded-countryregion'] = sanitize_text_field($geo);
		}

		// Set a user agent if not present
		if (!isset($headers['user-agent'])) {
			$headers['user-agent'] = isset($_server['HTTP_USER_AGENT']) ? $_server['HTTP_USER_AGENT'] : 'PMW-GTG-Proxy/1.0';
		}

		// Forward cookies for Google Tag tracking (required for proper session/user identification)
		if (!empty($_server['HTTP_COOKIE'])) {
			$headers['cookie'] = $_server['HTTP_COOKIE'];
		}

		return $headers;
	}

	/**
	 * Get the real client IP address
	 *
	 * Uses the centralized Geolocation class for consistent IP detection
	 * across all CDNs and proxies.
	 *
	 * @return string The client IP address.
	 */
	private static function get_client_ip() {
		return Geolocation::get_ip_address();
	}

	/**
	 * Send request to Google FPS
	 *
	 * @param string $method  The HTTP method.
	 * @param string $url     The target URL.
	 * @param array  $headers The headers to send.
	 * @param string $body    The request body.
	 * @return array|\WP_Error The response array or WP_Error.
	 */
	private static function send_request( $method, $url, $headers, $body = '' ) {

		// Remove Accept-Encoding to get uncompressed response
		// WordPress HTTP API will handle decompression but we want plain text
		unset($headers['Accept-Encoding']);

		/**
		 * Filter the timeout for Google Tag Gateway proxy requests.
		 *
		 * @since 1.53.0
		 *
		 * @param int $timeout Request timeout in seconds. Default 5.
		 */
		$timeout = apply_filters( 'pmw_gtg_proxy_request_timeout', self::$request_timeout );

		$args = [
			'method'       => strtoupper($method),
			'headers'      => $headers,
			'body'         => $body,
			'timeout'      => absint( $timeout ),
			'redirection'  => 0, // Don't follow redirects automatically
			'sslverify'    => true,
			'decompress'   => true, // Decompress the response
		];

		$response = wp_remote_request($url, $args);

		if (is_wp_error($response)) {
			return $response;
		}

		return [
			'body'        => wp_remote_retrieve_body($response),
			'headers'     => wp_remote_retrieve_headers($response)->getAll(),
			'status_code' => wp_remote_retrieve_response_code($response),
		];
	}

	/**
	 * Rewrite paths in the response body
	 *
	 * For JavaScript responses, internal paths reference '/PHP_GTG_REPLACE_PATH/'.
	 * These must be rewritten to point back to our proxy.
	 *
	 * @param array  $response The response array.
	 * @param string $tag_id   The Google Tag ID.
	 * @param string $geo      Geographic information.
	 * @return array The modified response.
	 */
	private static function rewrite_response_paths( $response, $tag_id, $geo ) {

		if (empty($response['body'])) {
			return $response;
		}

		// Build the substitution path
		// Reference implementation uses: $redirectorFile . '?id=' . $tagId
		$measurement_path = Options::get_google_tag_gateway_measurement_path();

		if ($measurement_path) {
			// Use the measurement path directly (matches reference: /path?id=TAG)
			$substitution_path = $measurement_path . '?id=' . $tag_id;
		} else {
			// Fall back to REST API endpoint
			$substitution_path = rest_url(self::$rest_namespace . '/gtg-proxy/') . '?id=' . $tag_id;
		}

		if (!empty($geo)) {
			$substitution_path .= '&geo=' . rawurlencode($geo);
		}

		$substitution_path .= '&s=';

		// Check if this is a script response (application/javascript)
		// Only rewrite body for JavaScript responses
		if ( self::is_script_response( $response['headers'] ) ) {
			$response['body'] = str_replace( '/' . self::$fps_path_placeholder . '/', $substitution_path, $response['body'] );

			// Also rewrite known consent mode / CCM paths that Google hardcodes without the placeholder
			// These paths are used for consent mode data collection and need to be proxied through our endpoint
			// We need to transform: "/d/ccm/form-data" => "/metrics5?id=TAG&s=/d/ccm/form-data"
			$ccm_paths = [
				'"/d/ccm/form-data"',
				'"/d/ccm/conversion"',
				'"/as/d/ccm/conversion"',
				'"/g/d/ccm/conversion"',
				'"/gs/ccm/conversion"',
				'"/gs/ccm/collect"',
			];
			
			foreach ( $ccm_paths as $ccm_path ) {
				// Build the replacement - remove quotes and add to measurement path format
				$path_without_quotes = trim( $ccm_path, '"' );
				$ccm_replacement = '"' . $measurement_path . '?id=' . $tag_id;
				if ( ! empty( $geo ) ) {
					$ccm_replacement .= '&geo=' . rawurlencode( $geo );
				}
				$ccm_replacement .= '&s=' . $path_without_quotes . '"';
				
				$response['body'] = str_replace( $ccm_path, $ccm_replacement, $response['body'] );
			}
		} elseif ( self::is_redirect_response( $response['status_code'] ) && ! empty( $response['headers'] ) ) {
			// Handle redirect responses (3xx) - rewrite Location header
			if ( isset( $response['headers']['location'] ) ) {
				$response['headers']['location'] = str_replace(
					'/' . self::$fps_path_placeholder,
					$substitution_path,
					$response['headers']['location']
				);
			}
		}

		return $response;
	}

	/**
	 * Check if the response is a script/JavaScript response.
	 *
	 * Matches the reference implementation behavior by checking for
	 * 'content-type:application/javascript' in the headers.
	 *
	 * @param array $headers The response headers (associative array or indexed array).
	 * @return bool True if this is a JavaScript response.
	 */
	private static function is_script_response( $headers ) {

		if ( empty( $headers ) ) {
			return false;
		}

		// Handle associative array format (from wp_remote_request)
		if ( isset( $headers['content-type'] ) ) {
			$content_type = strtolower( str_replace( ' ', '', $headers['content-type'] ) );
			return strpos( $content_type, 'application/javascript' ) === 0;
		}

		// Handle indexed array format (like reference implementation)
		foreach ( $headers as $header ) {
			if ( empty( $header ) || ! is_string( $header ) ) {
				continue;
			}

			$normalized_header = strtolower( str_replace( ' ', '', $header ) );
			if ( strpos( $normalized_header, 'content-type:application/javascript' ) === 0 ) {
				return true;
			}
		}

		return false;
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
	 * Rewrite redirect location headers
	 *
	 * @param string $location The original location.
	 * @param string $tag_id   The Google Tag ID.
	 * @param string $geo      Geographic information.
	 * @return string The rewritten location.
	 */
	private static function rewrite_redirect_location( $location, $tag_id, $geo ) {

		// Check if this is a Google FPS URL
		if (strpos($location, '.fps.goog') === false) {
			return $location;
		}

		// Parse the URL
		$parsed = wp_parse_url($location);
		if (!$parsed) {
			return $location;
		}

		$path = isset( $parsed['path'] ) ? $parsed['path'] : '';

		// Remove the PHP_GTG_REPLACE_PATH if present
		$path = str_replace('/PHP_GTG_REPLACE_PATH', '', $path);

		// Build the new location through our proxy
		$measurement_path = Options::get_google_tag_gateway_measurement_path();

		if ($measurement_path) {
			$new_location = get_site_url() . $measurement_path . '/?id=' . $tag_id;
		} else {
			$new_location = rest_url(self::$rest_namespace . '/gtg-proxy/') . '?id=' . $tag_id;
		}

		if (!empty($geo)) {
			$new_location .= '&geo=' . rawurlencode($geo);
		}

		$new_location .= '&s=' . rawurlencode($path);

		if (!empty($parsed['query'])) {
			$new_location .= rawurlencode('?' . $parsed['query']);
		}

		return $new_location;
	}

	/**
	 * Check if the Google Tag Gateway Proxy is active
	 *
	 * @return bool True if active, false otherwise.
	 */
	public static function is_active() {
		return (bool) Options::get_google_tag_gateway_measurement_path();
	}
	
	/**
	 * Get the GTG config directory path in wp-content/uploads
	 *
	 * @return string|false Config directory path or false on failure.
	 *
	 * @since 1.56.0
	 */
	public static function get_config_directory() {
		$upload_dir = wp_upload_dir();
		if ( ! isset( $upload_dir['basedir'] ) ) {
			return false;
		}
		return $upload_dir['basedir'] . '/pmw-gtg';
	}

	/**
	 * Get the GTG config file path for the current site
	 *
	 * Uses blog_id to support multisite installations.
	 *
	 * @return string|false Config file path or false on failure.
	 *
	 * @since 1.56.0
	 */
	public static function get_config_file_path() {
		$config_dir = self::get_config_directory();
		if ( ! $config_dir ) {
			return false;
		}
		$blog_id = get_current_blog_id();
		return $config_dir . '/config-' . $blog_id . '.json';
	}

	/**
	 * Get the site map file path
	 *
	 * The site map maps site identifiers (hostname + path) to config filenames.
	 *
	 * @return string|false Site map file path or false on failure.
	 *
	 * @since 1.56.0
	 */
	public static function get_site_map_path() {
		$config_dir = self::get_config_directory();
		if ( ! $config_dir ) {
			return false;
		}
		return $config_dir . '/site-map.json';
	}

	/**
	 * Get the site identifier for the current site
	 *
	 * For subdomain multisite: hostname (e.g., "site1.example.com")
	 * For subdirectory multisite: hostname/path (e.g., "example.com/site2")
	 * For single site: hostname (e.g., "example.com")
	 *
	 * @return string Site identifier.
	 *
	 * @since 1.56.0
	 */
	public static function get_site_identifier() {
		$site_url = get_site_url();
		$parsed   = wp_parse_url( $site_url );
		$host     = isset( $parsed['host'] ) ? $parsed['host'] : '';
		$path     = isset( $parsed['path'] ) ? trim( $parsed['path'], '/' ) : '';

		// Combine host and path for subdirectory multisite
		if ( ! empty( $path ) ) {
			return $host . '/' . $path;
		}

		return $host;
	}

	/**
	 * Ensure the config directory exists with security files
	 *
	 * Creates the directory if needed and adds .htaccess and index.php
	 * to prevent direct access to config files.
	 *
	 * @return bool True if directory exists and is writable, false otherwise.
	 *
	 * @since 1.56.0
	 */
	private static function ensure_config_directory() {
		$config_dir = self::get_config_directory();
		if ( ! $config_dir ) {
			return false;
		}

		// Create directory if it doesn't exist
		if ( ! file_exists( $config_dir ) ) {
			if ( ! wp_mkdir_p( $config_dir ) ) {
				return false;
			}

			// Create .htaccess to deny direct access
			$htaccess_file = $config_dir . '/.htaccess';
			if ( ! file_exists( $htaccess_file ) ) {
				file_put_contents( $htaccess_file, "deny from all\n", LOCK_EX );
			}

			// Create index.php to prevent directory listing
			$index_file = $config_dir . '/index.php';
			if ( ! file_exists( $index_file ) ) {
				file_put_contents( $index_file, "<?php\n// Silence is golden.\n", LOCK_EX );
			}
		}

		return is_dir( $config_dir ) && is_writable( $config_dir );
	}

	/**
	 * Update the site map with the current site's entry
	 *
	 * Uses file locking and atomic writes to prevent race conditions.
	 *
	 * @param string $site_identifier Site identifier (hostname or hostname/path).
	 * @param string $config_filename Config filename (e.g., "config-1.json").
	 * @param bool   $remove          Whether to remove the entry instead of adding it.
	 * @return bool True on success, false on failure.
	 *
	 * @since 1.56.0
	 */
	private static function update_site_map( $site_identifier, $config_filename, $remove = false ) {
		$site_map_file = self::get_site_map_path();
		if ( ! $site_map_file ) {
			return false;
		}

		// Read existing site map
		$site_map = [];
		if ( file_exists( $site_map_file ) ) {
			$content = file_get_contents( $site_map_file );
			if ( false !== $content ) {
				$decoded = json_decode( $content, true );
				if ( is_array( $decoded ) ) {
					$site_map = $decoded;
				}
			}
		}

		// Update or remove entry
		if ( $remove ) {
			unset( $site_map[ $site_identifier ] );
		} else {
			$site_map[ $site_identifier ] = $config_filename;
		}

		// Write site map atomically
		$site_map_json = wp_json_encode( $site_map, JSON_PRETTY_PRINT );
		$temp_file     = $site_map_file . '.tmp.' . uniqid();
		$result        = false;

		if ( false !== file_put_contents( $temp_file, $site_map_json, LOCK_EX ) ) {
			clearstatcache( true, $temp_file );
			if ( file_exists( $temp_file ) ) {
				// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- Silencing rename errors to prevent warnings in edge cases
				$result = @rename( $temp_file, $site_map_file );
			}
			clearstatcache( true, $temp_file );
			if ( ! $result && file_exists( $temp_file ) ) {
				@unlink( $temp_file );
			}
		}

		return $result;
	}

	/**
	 * Update proxy configuration cache for isolated proxy
	 *
	 * Writes configuration to wp-content/uploads/pmw-gtg/ that the isolated proxy
	 * can read without loading WordPress. Includes logging configuration so the
	 * isolated proxy can log to the same location as WordPress.
	 *
	 * Supports multisite by using blog_id in config filenames and maintaining
	 * a site map that maps site identifiers to config files.
	 *
	 * @return bool True on success, false on failure.
	 */
	public static function update_proxy_config_cache() {
		// Get upload directory for logging path reference
		$upload_dir    = wp_upload_dir();
		$log_directory = '';
		if ( isset( $upload_dir['basedir'] ) ) {
			$log_directory = $upload_dir['basedir'] . '/pmw-logs';
		}

		// Get config file path (in uploads directory)
		$config_file     = self::get_config_file_path();
		$site_identifier = self::get_site_identifier();
		$blog_id         = get_current_blog_id();
		$config_filename = 'config-' . $blog_id . '.json';

		if ( ! $config_file ) {
			return false;
		}

		// Only update if GTG is active
		if ( ! self::is_active() ) {
			// Remove config file and site map entry if GTG is disabled
			if ( file_exists( $config_file ) ) {
				@unlink( $config_file );
			}
			self::update_site_map( $site_identifier, $config_filename, true );
			return true;
		}

		// Ensure config directory exists with security files
		if ( ! self::ensure_config_directory() ) {
			return false;
		}

		// Ensure log directory exists (for logging, not for config)
		if ( $log_directory && ! file_exists( $log_directory ) ) {
			wp_mkdir_p( $log_directory );
		}

		// Get logging configuration from Options
		$options         = Options::get_options();
		$logging_enabled = ! empty( $options['general']['logger']['is_active'] );
		$log_level       = isset( $options['general']['logger']['level'] )
			? $options['general']['logger']['level']
			: 'error';

		// Allow disabling isolated proxy for testing
		// Usage: add_filter( 'pmw_gtg_isolated_proxy_enabled', '__return_false' );
		$isolated_proxy_enabled = apply_filters( 'pmw_gtg_isolated_proxy_enabled', true );

		// Get the isolated proxy URL for self-referencing in rewrites
		$proxy_url = plugins_url( 'pmw-gtg-proxy.php', __FILE__ );
		$site_url  = get_site_url();

		// Ensure proxy URL uses the same protocol as the site URL
		// plugins_url() may return HTTP even when site uses HTTPS (e.g., when called from CLI)
		if ( 0 === strpos( $site_url, 'https://' ) && 0 === strpos( $proxy_url, 'http://' ) ) {
			$proxy_url = 'https://' . substr( $proxy_url, 7 );
		}

		$config = [
			'enabled'          => $isolated_proxy_enabled,
			'measurement_path' => Options::get_google_tag_gateway_measurement_path(),
			'proxy_url'        => $proxy_url,
			'site_url'         => $site_url,
			'logging_enabled'  => $logging_enabled,
			'log_level'        => $log_level,
			'log_directory'    => $log_directory,
			'updated'          => time(),
		];

		$new_config_json = wp_json_encode( $config, JSON_PRETTY_PRINT );

		// Hash-based change detection: skip write if config hasn't changed
		// This reduces concurrent write conflicts and unnecessary disk I/O
		if ( file_exists( $config_file ) ) {
			$existing_content = file_get_contents( $config_file );
			if ( false !== $existing_content ) {
				$existing_config = json_decode( $existing_content, true );
				if ( is_array( $existing_config ) ) {
					// Compare all fields except 'updated' timestamp
					unset( $existing_config['updated'], $config['updated'] );
					if ( $existing_config === $config ) {
						// Config unchanged, but still update site map in case it's missing
						self::update_site_map( $site_identifier, $config_filename );
						return true;
					}
					// Restore 'updated' for new config
					$config['updated'] = time();
					$new_config_json   = wp_json_encode( $config, JSON_PRETTY_PRINT );
				}
			}
		}

		// Write config atomically to prevent corruption during reads
		$temp_file = $config_file . '.tmp.' . uniqid();
		$result    = false;
		if ( false !== file_put_contents( $temp_file, $new_config_json, LOCK_EX ) ) {
			// Clear stat cache for accurate file existence check (prevents stale cached values)
			clearstatcache( true, $temp_file );

			// Verify temp file still exists before renaming (race condition protection)
			if ( file_exists( $temp_file ) ) {
				// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- Silencing rename errors to prevent warnings in edge cases
				$result = @rename( $temp_file, $config_file );
			}

			// Clean up temp file if rename failed and file still exists
			clearstatcache( true, $temp_file );
			if ( ! $result && file_exists( $temp_file ) ) {
				@unlink( $temp_file );
			}
		}

		// Update site map with this site's entry
		if ( $result ) {
			self::update_site_map( $site_identifier, $config_filename );
		}

		return $result;
	}
	
	/**
	 * Check if the isolated proxy file exists
	 *
	 * The isolated proxy is accessed directly from the plugin folder
	 * via plugins_url(), similar to Google Site Kit's approach.
	 * This bypasses WordPress rewrite rules entirely.
	 *
	 * @return bool True if proxy file exists, false otherwise
	 */
	public static function ensure_isolated_proxy_file() {
		// Only run if GTG is active
		if ( ! self::is_active() ) {
			return false;
		}
		
		$proxy_file = __DIR__ . '/pmw-gtg-proxy.php';
		
		if ( ! file_exists( $proxy_file ) ) {
			self::log_proxy_event( 'Isolated proxy file not found', [ 'path' => $proxy_file ], 'error' );
			return false;
		}
		
		return true;
	}
	
	/**
	 * Get the isolated proxy file URL
	 *
	 * Returns the URL to the isolated proxy file. Uses plugins_url()
	 * for direct file access, bypassing WordPress rewrite rules.
	 * This is the same approach used by Google Site Kit.
	 *
	 * @return string|false Proxy URL or false if not available
	 */
	public static function get_isolated_proxy_url() {
		if ( ! self::is_active() ) {
			return false;
		}
		
		$proxy_file = __DIR__ . '/pmw-gtg-proxy.php';
		
		if ( ! file_exists( $proxy_file ) ) {
			return false;
		}
		
		// Use plugins_url() for direct file access (like Google Site Kit)
		// This bypasses WordPress rewrite rules entirely
		$proxy_url = plugins_url( 'pmw-gtg-proxy.php', __FILE__ );
		$site_url  = get_site_url();

		// Ensure proxy URL uses the same protocol as the site URL
		// plugins_url() may return HTTP even when site uses HTTPS (e.g., when called from CLI)
		if ( 0 === strpos( $site_url, 'https://' ) && 0 === strpos( $proxy_url, 'http://' ) ) {
			$proxy_url = 'https://' . substr( $proxy_url, 7 );
		}

		return $proxy_url;
	}
	
	/**
	 * Check if isolated proxy is available and functional
	 *
	 * Performs a health check on the isolated proxy file.
	 *
	 * @return bool True if isolated proxy is working, false otherwise
	 */
	public static function is_isolated_proxy_available() {

		// Allow disabling isolated proxy for testing
		// Usage: add_filter( 'pmw_gtg_isolated_proxy_enabled', '__return_false' );
		if ( ! apply_filters( 'pmw_gtg_isolated_proxy_enabled', true ) ) {
			return false;
		}

		$proxy_url = self::get_isolated_proxy_url();
		
		if ( ! $proxy_url ) {
			return false;
		}
		
		// Perform health check
		$health_url = add_query_arg( 'healthCheck', '1', $proxy_url );
		$response   = wp_remote_get( $health_url, [
			'timeout'    => 5,
			'user-agent' => 'PMW-GTG-HealthCheck/1.0',
		] );
		
		if ( is_wp_error( $response ) ) {
			return false;
		}
		
		$response_code = wp_remote_retrieve_response_code( $response );
		$response_body = wp_remote_retrieve_body( $response );
		
		return 200 === $response_code && 'ok' === trim( $response_body );
	}
}
