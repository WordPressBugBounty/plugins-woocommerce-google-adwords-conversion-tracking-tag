<?php

namespace SweetCode\Pixel_Manager;

defined('ABSPATH') || exit; // Exit if accessed directly

class Geolocation {

	/**
	 * API endpoints for looking up user IP address.
	 *
	 * @var array
	 */
	private static $ip_lookup_apis
		= [
			'ipify'  => 'http://api.ipify.org/',
			'ipecho' => 'http://ipecho.net/plain',
			'ident'  => 'http://ident.me',
			'tnedi'  => 'http://tnedi.me',
		];

	/**
	 * API endpoints for geolocating an IP address
	 *
	 * @var array
	 */
	private static $geoip_apis
		= [
			'ipinfo.io'  => 'https://ipinfo.io/%s/json',
			'ip-api.com' => 'http://ip-api.com/json/%s',
		];

	/**
	 * Check if the current visitor is on localhost.
	 *
	 * @return bool
	 */
	public static function is_localhost() {

		// If the IP is local, return true, else false
		// https://stackoverflow.com/a/13818647/4688612

		return !filter_var(
			self::get_ip_address(),
			FILTER_VALIDATE_IP,
			FILTER_FLAG_IPV4 | FILTER_FLAG_IPV6 | FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE
		);
	}

	/**
	 * Get the (external) IP address of the current visitor.
	 *
	 * @return array|string|string[]
	 */
	public static function get_user_ip() {

		if (self::is_localhost()) {
			$ip = self::get_external_ip_address();
		} else {
			$ip = self::get_ip_address();
		}

		// only set the IP if it is a public address
		$ip = filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE);

		// Remove the IPv6 to IPv4 mapping in case the IP contains one
		// and return the IP plain public IPv4 or IPv6 IP
		// https://en.wikipedia.org/wiki/IPv6_address
		return str_replace('::ffff:', '', $ip);
	}

	public static function get_visitor_country() {

		$location = self::geolocate_ip(self::get_user_ip());

		return $location['country'];
	}

	/**
	 * Get current user IP Address.
	 *
	 * Checks various headers set by CDNs and proxies in order of reliability:
	 * 1. Cloudflare (CF-Connecting-IP) - Most reliable when behind Cloudflare
	 * 2. Sucuri (X-Sucuri-ClientIP) - Set by Sucuri WAF
	 * 3. Akamai/Cloudflare Enterprise (True-Client-IP)
	 * 4. Incapsula (Incap-Client-IP) - Set by Imperva Incapsula
	 * 5. Generic proxy headers (X-Real-IP, X-Forwarded-For)
	 * 6. Direct connection (REMOTE_ADDR) - Fallback
	 *
	 * @return string
	 */
	public static function get_ip_address() {

		/**
		 * Filter to override server variables for IP detection.
		 * Useful for testing or custom IP detection logic.
		 *
		 * @param array|null $server_override Array of server variables to use instead of actual request data.
		 *                                    Return null to use the actual request data.
		 */
		$server_override = apply_filters('pmw_geolocation_server_vars', null);

		if (null !== $server_override && is_array($server_override)) {
			$_server = $server_override;
		} else {
			$_server = Helpers::get_input_vars(INPUT_SERVER);
		}

		/**
		 * Priority order for IP detection headers.
		 * CDN-specific headers are checked first as they are most reliable.
		 *
		 * - HTTP_CF_CONNECTING_IP: Cloudflare's header containing the original visitor IP
		 * - HTTP_X_SUCURI_CLIENTIP: Sucuri WAF's header for the original client IP
		 * - HTTP_TRUE_CLIENT_IP: Used by Akamai and Cloudflare Enterprise
		 * - HTTP_INCAP_CLIENT_IP: Imperva Incapsula's header for the original client IP
		 * - HTTP_FASTLY_CLIENT_IP: Fastly CDN's header for the original client IP
		 * - HTTP_X_FORWARDED_FOR: Standard proxy header (may contain multiple IPs), also used by AWS ALB/CloudFront
		 * - HTTP_X_REAL_IP: Common header set by nginx and other reverse proxies
		 * - REMOTE_ADDR: Direct connection IP (fallback, may be CDN IP if not configured)
		 */
		$ip_headers = [
			'HTTP_CF_CONNECTING_IP',    // Cloudflare
			'HTTP_X_SUCURI_CLIENTIP',   // Sucuri WAF
			'HTTP_TRUE_CLIENT_IP',      // Akamai / Cloudflare Enterprise
			'HTTP_INCAP_CLIENT_IP',     // Imperva Incapsula
			'HTTP_FASTLY_CLIENT_IP',    // Fastly CDN
			'HTTP_X_FORWARDED_FOR',     // Standard proxy header (AWS ALB/CloudFront also use this)
			'HTTP_X_REAL_IP',           // Nginx reverse proxy
			'REMOTE_ADDR',              // Direct connection (fallback)
		];

		foreach ($ip_headers as $header) {
			if (!empty($_server[$header])) {
				$ip = sanitize_text_field($_server[$header]);

				// X-Forwarded-For can contain multiple IPs: client, proxy1, proxy2
				// We want the first one (the original client)
				if (false !== strpos($ip, ',')) {
					$ip = trim(explode(',', $ip)[0]);
				}

				// Validate the IP address
				if (filter_var($ip, FILTER_VALIDATE_IP)) {
					self::log_ip_detection($header, $ip, $_server);
					return $ip;
				}
			}
		}

		// Note: No logging here - empty IP is expected for health checks, bots, etc.
		return '';
	}

	/**
	 * Log IP detection details for debugging.
	 *
	 * @param string $detected_header The header that provided the IP.
	 * @param string $ip              The detected IP address.
	 * @param array  $server          The server variables array.
	 * @return void
	 */
	private static function log_ip_detection( $detected_header, $ip, $server ) {

		// Only log in debug mode to avoid performance impact
		if (!Helpers::is_pmw_debug_mode_active()) {
			return;
		}

		// Only log when IP source is not the default REMOTE_ADDR (reduces noise)
		if ('REMOTE_ADDR' === $detected_header) {
			return;
		}

		Logger::debug(
			sprintf(
				'Geolocation: IP detected from %s: %s',
				$detected_header,
				$ip
			)
		);
	}

	/**
	 * Get user IP Address using an external service.
	 * This can be used as a fallback for users on localhost where
	 * get_ip_address() will be a local IP and non-geolocatable.
	 *
	 * Source: https://woocommerce.github.io/code-reference/files/woocommerce-includes-class-wc-geolocation.html#source-view.100
	 *
	 * @return string
	 */
	public static function get_external_ip_address() {

		if (class_exists('WC_Geolocation')) {
			return \WC_Geolocation::get_external_ip_address();
		} else {
			$external_ip_address = '0.0.0.0';

			if ('' !== self::get_ip_address()) {
				$transient_name      = 'external_ip_address_' . self::get_ip_address();
				$external_ip_address = get_transient($transient_name);
			}

			if (false === $external_ip_address) {
				$external_ip_address     = '0.0.0.0';
				$ip_lookup_services      = apply_filters('pmw_geolocation_ip_lookup_apis', self::$ip_lookup_apis);
				$ip_lookup_services_keys = array_keys($ip_lookup_services);
				shuffle($ip_lookup_services_keys);

				foreach ($ip_lookup_services_keys as $service_name) {
					$service_endpoint = $ip_lookup_services[$service_name];
					$response         = wp_safe_remote_get(
						$service_endpoint,
						[
							'timeout' => 2,
							//							'user-agent' => 'WooCommerce/' . wc()->version,
						]
					);

					if (!is_wp_error($response) && rest_is_ip_address($response['body'])) {
						$external_ip_address = apply_filters('pmw_geolocation_ip_lookup_api_response', wc_clean($response['body']), $service_name);
						break;
					}
				}

				set_transient($transient_name, $external_ip_address, DAY_IN_SECONDS);
			}

			return $external_ip_address;
		}
	}

	/**
	 * Geolocate an IP address.
	 *
	 * Source: https://woocommerce.github.io/code-reference/files/woocommerce-includes-class-wc-geolocation.html#source-view.138
	 *
	 * @param string $ip_address   IP Address.
	 * @param bool   $fallback     If true, fallbacks to alternative IP detection (can be slower).
	 * @param bool   $api_fallback If true, uses geolocation APIs if the database file doesn't exist (can be slower).
	 * @return array
	 */
	public static function geolocate_ip( $ip_address = '', $fallback = false, $api_fallback = true ) {

		if (class_exists('WC_Geolocation')) {
			return \WC_Geolocation::geolocate_ip(self::get_user_ip());
		} else {

			// Filter to allow custom geolocation of the IP address.
			$country_code = apply_filters('pmw_geolocate_ip', false, $ip_address, $fallback, $api_fallback);

			if (false !== $country_code) {
				return [
					'country'  => $country_code,
					'state'    => '',
					'city'     => '',
					'postcode' => '',
				];
			}

			if (empty($ip_address)) {
				$ip_address   = self::get_ip_address();
				$country_code = self::get_country_code_from_headers();
			}

			/**
			 * Get geolocation filter.
			 *
			 * @param array  $geolocation Geolocation data, including country, state, city, and postcode.
			 * @param string $ip_address  IP Address.
			 */
			$geolocation = apply_filters(
				'pmw_get_geolocation',
				[
					'country'  => $country_code,
					'state'    => '',
					'city'     => '',
					'postcode' => '',
				],
				$ip_address
			);

			// If we still haven't found a country code, let's consider doing an API lookup.
			if ('' === $geolocation['country'] && $api_fallback) {
				$geolocation['country'] = self::geolocate_via_api($ip_address);
			}

			// It's possible that we're in a local environment, in which case the geolocation needs to be done from the
			// external address.
			if ('' === $geolocation['country'] && $fallback) {
				$external_ip_address = self::get_external_ip_address();

				// Only bother with this if the external IP differs.
				if ('0.0.0.0' !== $external_ip_address && $external_ip_address !== $ip_address) {
					return self::geolocate_ip($external_ip_address, false, $api_fallback);
				}
			}

			return [
				'country'  => $geolocation['country'],
				'state'    => $geolocation['state'],
				'city'     => $geolocation['city'],
				'postcode' => $geolocation['postcode'],
			];
		}
	}

	/**
	 * Fetches the country code from the request headers, if one is available.
	 *
	 * Source: https://woocommerce.github.io/code-reference/files/woocommerce-includes-class-wc-geolocation.html#source-view.229
	 *
	 * @return string The country code pulled from the headers, or empty string if one was not found.
	 * @since 1.32.3
	 *
	 */
	private static function get_country_code_from_headers() {
		$country_code = '';

		$headers = [
			'MM_COUNTRY_CODE',
			'GEOIP_COUNTRY_CODE',
			'HTTP_CF_IPCOUNTRY',
			'HTTP_X_COUNTRY_CODE',
		];

		foreach ($headers as $header) {
			if (empty($_SERVER[$header])) {
				continue;
			}

			$country_code = strtoupper(sanitize_text_field(wp_unslash($_SERVER[$header])));
			break;
		}

		return $country_code;
	}

	/**
	 * Use APIs to Geolocate the user.
	 *
	 * Geolocation APIs can be added through the use of the pmw_geolocation_geoip_apis filter.
	 * Provide a name=>value pair for service-slug=>endpoint.
	 *
	 * If APIs are defined, one will be chosen at random to fulfil the request. After completing, the result
	 * will be cached in a transient.
	 *
	 * Source: https://woocommerce.github.io/code-reference/files/woocommerce-includes-class-wc-geolocation.html#source-view.263
	 *
	 * @param string $ip_address IP address.
	 * @return string
	 */
	private static function geolocate_via_api( $ip_address ) {

		$country_code = get_transient('geoip_' . $ip_address);

		if (false === $country_code) {
			$geoip_services = apply_filters('pmw_geolocation_geoip_apis', self::$geoip_apis);

			if (empty($geoip_services)) {
				return '';
			}

			$geoip_services_keys = array_keys($geoip_services);

			shuffle($geoip_services_keys);

			foreach ($geoip_services_keys as $service_name) {
				$service_endpoint = $geoip_services[$service_name];
				$response         = wp_safe_remote_get(
					sprintf($service_endpoint, $ip_address),
					[
						'timeout' => 2,
						//						'user-agent' => 'WooCommerce/' . wc()->version,
					]
				);

				if (!is_wp_error($response) && $response['body']) {
					switch ($service_name) {
						case 'ipinfo.io':
							$data         = json_decode($response['body']);
							$country_code = isset($data->country) ? $data->country : '';
							break;
						case 'ip-api.com':
							$data         = json_decode($response['body']);
							$country_code = isset($data->countryCode) ? $data->countryCode : ''; // @codingStandardsIgnoreLine
							break;
						default:
							$country_code = apply_filters('pmw_geolocation_geoip_response_' . $service_name, '', $response['body']);
							break;
					}

					$country_code = sanitize_text_field(strtoupper($country_code));

					if ($country_code) {
						break;
					}
				}
			}

			set_transient('geoip_' . $ip_address, $country_code, DAY_IN_SECONDS);
		}

		return $country_code;
	}
}
