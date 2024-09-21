<?php

/**
 * HTTP request class
 *
 * For Dependency Injection
 *
 * Can get, gan post
 *
 * @since 1.42.6
 */

namespace SweetCode\Pixel_Manager;

defined('ABSPATH') || exit; // Exit if accessed directly

class HTTP {

	private $request_args;

	public function __construct( $request_args_override = [] ) {

		// Set the default options and allow these to be overridden upon instantiation
		$this->request_args = array_merge([
			'body'        => '',
			'timeout'     => 5,
			'redirection' => 5,
			'httpversion' => '1.0',
			'blocking'    => Options::is_http_request_logging_enabled(),
			'headers'     => [],
			'cookies'     => [],
			'sslverify'   => !Geolocation::is_localhost(),
		], $request_args_override);

		$this->request_args = array_merge($this->request_args, $this->filter_options());
	}

	/**
	 * Add and/or override the request args (partially or completely)
	 *
	 * @param array $request_args
	 */
	public function set_request_args( $request_args ) {
		$this->request_args = array_merge($this->request_args, $request_args);
	}

	/**
	 * Filter the options
	 *
	 * @return array
	 */
	private function filter_options() {
		$this->request_args = apply_filters_deprecated('wooptpm_http_post_request_args', [ $this->request_args ], '1.13.0', 'pmw_http_post_request_args');
		$this->request_args = apply_filters_deprecated('wpm_http_post_request_args', [ $this->request_args ], '1.31.2', 'pmw_http_post_request_args');

		return apply_filters('pmw_http_post_request_args', $this->request_args);
	}

	public function post( $url, $payload = null, $request_args_override = [] ) {

		if ($payload) {

			if (!isset($this->request_args['headers']['Content-Type'])) {
				$this->request_args['headers']['Content-Type'] = 'application/json';
			}

			$this->request_args['body'] = wp_json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
		}

		$this->request_args = array_merge($this->request_args, $request_args_override);

		$response = wp_safe_remote_post($url, $this->request_args);

		if (Options::is_http_request_logging_enabled()) {
			$this->log_request($url, $response, $payload, $request_args_override);
		}
	}

	public function get( $url ) {

		$response = wp_safe_remote_get($url, $this->request_args);

		if (Options::is_http_request_logging_enabled()) {
			$this->log_request($url, $response);
		}
	}

	private function log_request( $url, $response, $payload = null, $request_args_override = [] ) {

		Logger::debug('request url: ' . $url);

		if ($payload) {
			Logger::debug('payload: ' . print_r($payload, true));
			Logger::debug('json payload: ' . wp_json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
		}

		if ($request_args_override) {
			Logger::debug('request args override: ' . print_r($request_args_override, true));
		}

		$response_code = wp_remote_retrieve_response_code($response);

		if (200 <= $response_code && 300 > $response_code) {
			Logger::debug('request successful with response code: ' . $response_code);
		}

		$response_body = wp_remote_retrieve_body($response);

		if ($response_body) {
			Logger::debug('response body: ' . $response_body);
		}

		if (is_wp_error($response)) {
			Logger::error('response error message: ' . $response->get_error_message());
		}
	}
}
