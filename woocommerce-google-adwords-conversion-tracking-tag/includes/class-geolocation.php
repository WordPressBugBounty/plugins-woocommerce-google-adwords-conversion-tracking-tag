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
	 * Source: https://woocommerce.github.io/code-reference/files/woocommerce-includes-class-wc-geolocation.html#source-view.80
	 *
	 * @return string
	 */
	public static function get_ip_address() {

		// If class WC_Geolocation exists, use it to get the IP address
		if (class_exists('WC_Geolocation')) {
			return \WC_Geolocation::get_ip_address();
		} else {
			if (isset($_SERVER['HTTP_X_REAL_IP'])) {
				return sanitize_text_field(wp_unslash($_SERVER['HTTP_X_REAL_IP']));
			} elseif (isset($_SERVER['HTTP_X_FORWARDED_FOR'])) {
				// Proxy servers can send through this header like this: X-Forwarded-For: client1, proxy1, proxy2
				// Make sure we always only send through the first IP in the list which should always be the client IP.
				return (string) rest_is_ip_address(trim(current(preg_split('/,/', sanitize_text_field(wp_unslash($_SERVER['HTTP_X_FORWARDED_FOR']))))));
			} elseif (isset($_SERVER['REMOTE_ADDR'])) {
				return sanitize_text_field(wp_unslash($_SERVER['REMOTE_ADDR']));
			}
			return '';
		}
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
