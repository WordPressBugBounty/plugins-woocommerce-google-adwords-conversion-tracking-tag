<?php

namespace SweetCode\Pixel_Manager\Admin;

use SweetCode\Pixel_Manager\Geolocation;
use SweetCode\Pixel_Manager\Pixels\Facebook\Facebook;
use WP_Error;

defined('ABSPATH') || exit; // Exit if accessed directly

/**
 * Scans the Meta (Facebook) signals config for Event Setup Tool rules.
 *
 * Meta stores point-and-click "Event Setup Tool" rules server-side on the pixel.
 * They are delivered to every browser through the public file
 * https://connect.facebook.net/signals/config/{PIXEL_ID} and fire additional
 * browser events (e.g. a Purchase on a button click) without an event ID and
 * usually without a value. They cannot be deduplicated against the events the
 * Pixel Manager sends, so they inflate event counts and corrupt purchase values.
 * Merchants rarely know these rules exist.
 *
 * The config file delivers the rules like this:
 *
 *   fbq.set("estRules", "{PIXEL_ID}", [ { "condition": {...}, "derived_event_name": "Purchase", "rule_status": "ACTIVE", "rule_id": "..." }, ... ]);
 *   fbq.set("iwlExtractors", "{PIXEL_ID}", [ { "domain_uri": "...", "event_type": "Purchase", "extractor_config": {...} }, ... ]);
 *
 * The same config also carries Meta's business category event restrictions.
 * Pixels in restricted business categories (e.g. health and wellness) get an
 * eventValidation entry, and fbevents.js silently drops those events client
 * side while PageView and ViewContent keep working:
 *
 *   config.set("{PIXEL_ID}", "eventValidation", { "unverifiedEventNames": [], "restrictedEventNames": [ "AddToCart", "Purchase", ... ] });
 *
 * This class fetches the config server-side, extracts those payloads and caches
 * the result in a transient so the check stays cheap on admin page loads.
 *
 * Reference: https://secure.helpscout.net/conversation/3309525073
 *
 * @since 1.63.1
 */
class Facebook_Event_Setup_Scan {

	// The v2 suffix invalidates cached scans from before the
	// restricted_events extraction was added. The old transient expires on its own.
	const TRANSIENT_KEY = 'pmw_facebook_est_scan_v2';

	/**
	 * Get the scan results for all configured Facebook pixels.
	 *
	 * Returns the cached result whenever it is fresh and matches the currently
	 * configured pixel IDs. A new remote scan only runs while an admin is on the
	 * Pixel Manager settings page (or when $force_fetch is set, which the debug
	 * info uses), so regular admin page loads never trigger remote requests.
	 *
	 * @param bool $force_fetch Run the remote scan even outside the settings page when the cache is stale.
	 * @return array|false {
	 *     scanned_at: int    Unix timestamp of the scan.
	 *     pixel_ids:  array  The pixel IDs that were scanned.
	 *     pixels:     array  Per pixel ID: [ 'error' => string, 'active_rules' => array, 'iwl_extractors' => array, 'restricted_events' => array ].
	 * } or false when no result is available.
	 * @since 1.63.1
	 */
	public static function get_scan_results( $force_fetch = false ) {

		$pixel_ids = Facebook::get_pixel_ids();

		if (empty($pixel_ids)) {
			return false;
		}

		/**
		 * Allows disabling the server-side scan of the Meta signals config
		 * for Event Setup Tool rules.
		 *
		 * @param bool $enabled Default true.
		 *
		 * @since 1.63.1
		 */
		if (!apply_filters('pmw_facebook_event_setup_scan_enabled', true)) {
			return false;
		}

		$cached = Environment::is_transients_enabled() ? get_transient(self::TRANSIENT_KEY) : false;

		if (
			is_array($cached)
			&& isset($cached['pixel_ids'])
			&& $cached['pixel_ids'] === $pixel_ids
		) {
			return $cached;
		}

		if (!$force_fetch) {

			// Only refresh the cache lazily while an admin is looking at the
			// Pixel Manager settings page. Everywhere else (admin menu badge,
			// dashboard) we work with the cache only.
			if (!Environment::is_pmw_settings_page()) {
				return false;
			}

			// Without transients every settings page load would trigger the
			// remote requests, so skip the scan entirely in that case.
			if (!Environment::is_transients_enabled()) {
				return false;
			}
		}

		return self::scan($pixel_ids);
	}

	/**
	 * Check if the scan found Event Setup Tool rules or value extractors on any pixel.
	 *
	 * @param array|false $results The result of get_scan_results().
	 * @return bool
	 * @since 1.63.1
	 */
	public static function has_findings( $results ) {

		if (!is_array($results) || empty($results['pixels'])) {
			return false;
		}

		foreach ($results['pixels'] as $pixel) {
			if (!empty($pixel['active_rules']) || !empty($pixel['iwl_extractors'])) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Fetch and parse the signals config for each pixel ID and cache the result.
	 *
	 * @param array $pixel_ids
	 * @return array
	 * @since 1.63.1
	 */
	private static function scan( $pixel_ids ) {

		$results = [
			'scanned_at' => time(),
			'pixel_ids'  => $pixel_ids,
			'pixels'     => [],
		];

		$had_error = false;

		foreach ($pixel_ids as $pixel_id) {

			$body = self::fetch_signals_config($pixel_id);

			if (is_wp_error($body)) {

				$had_error = true;

				$results['pixels'][$pixel_id] = [
					'error'             => $body->get_error_message(),
					'active_rules'      => [],
					'iwl_extractors'    => [],
					'restricted_events' => [],
				];

				continue;
			}

			$parsed          = self::parse_signals_config($body, $pixel_id);
			$parsed['error'] = '';

			$results['pixels'][$pixel_id] = $parsed;
		}

		if (Environment::is_transients_enabled()) {
			// Cache errors for a shorter period so temporary connectivity
			// problems don't suppress the check for a whole week.
			set_transient(self::TRANSIENT_KEY, $results, $had_error ? DAY_IN_SECONDS : WEEK_IN_SECONDS);
		}

		return $results;
	}

	/**
	 * Fetch the public signals config file for a pixel.
	 *
	 * @param string $pixel_id
	 * @return string|WP_Error The response body, or a WP_Error on failure.
	 * @since 1.63.1
	 */
	private static function fetch_signals_config( $pixel_id ) {

		$response = wp_remote_get('https://connect.facebook.net/signals/config/' . rawurlencode($pixel_id), [
			'timeout'             => 5,
			'sslverify'           => !Geolocation::is_localhost(),
			'limit_response_size' => 4 * MB_IN_BYTES,
			'redirection'         => 2,
		]);

		if (is_wp_error($response)) {
			return $response;
		}

		$response_code = wp_remote_retrieve_response_code($response);

		if (200 !== $response_code) {
			return new WP_Error('pmw_facebook_est_scan', 'HTTP ' . $response_code);
		}

		$body = wp_remote_retrieve_body($response);

		if ('' === $body) {
			return new WP_Error('pmw_facebook_est_scan', 'empty response');
		}

		return $body;
	}

	/**
	 * Extract Event Setup Tool rules and value extractors from a signals config body.
	 *
	 * Only the fbq.set() payloads for the given pixel ID are parsed. A plain
	 * substring search would false-positive on every pixel that has the
	 * ESTRuleEngine plugin bundled, because the generic plugin code also
	 * contains the string "derived_event_name".
	 *
	 * Public so the parser can be unit tested without remote requests.
	 *
	 * @param string $body     The signals config file content.
	 * @param string $pixel_id The pixel ID the payloads belong to.
	 * @return array {
	 *     active_rules:      array  One entry per ACTIVE rule: [ 'event' => string, 'rule_id' => string ].
	 *     iwl_extractors:    array  Unique event names that have value extractors configured.
	 *     restricted_events: array  Event names Meta blocks for this pixel because of its business category.
	 * }
	 * @since 1.63.1
	 */
	public static function parse_signals_config( $body, $pixel_id ) {

		$result = [
			'active_rules'      => [],
			'iwl_extractors'    => [],
			'restricted_events' => [],
		];

		$est_rules = self::extract_fbq_set_payload($body, 'estRules', $pixel_id);

		if (is_array($est_rules)) {
			foreach ($est_rules as $rule) {

				if (!is_array($rule)) {
					continue;
				}

				$status = isset($rule['rule_status']) ? $rule['rule_status'] : '';
				$event  = isset($rule['derived_event_name']) ? $rule['derived_event_name'] : '';

				if ('ACTIVE' === $status && '' !== $event) {
					$result['active_rules'][] = [
						'event'   => $event,
						'rule_id' => isset($rule['rule_id']) ? $rule['rule_id'] : '',
					];
				}
			}
		}

		$iwl_extractors = self::extract_fbq_set_payload($body, 'iwlExtractors', $pixel_id);

		if (is_array($iwl_extractors)) {
			foreach ($iwl_extractors as $extractor) {

				if (!is_array($extractor)) {
					continue;
				}

				$event_type = isset($extractor['event_type']) ? $extractor['event_type'] : '';

				if ('' !== $event_type && !in_array($event_type, $result['iwl_extractors'], true)) {
					$result['iwl_extractors'][] = $event_type;
				}
			}
		}

		$event_validation = self::extract_config_set_payload($body, $pixel_id, 'eventValidation');

		if (
			is_array($event_validation)
			&& isset($event_validation['restrictedEventNames'])
			&& is_array($event_validation['restrictedEventNames'])
		) {
			foreach ($event_validation['restrictedEventNames'] as $event_name) {

				if (!is_string($event_name) || '' === $event_name) {
					continue;
				}

				if (!in_array($event_name, $result['restricted_events'], true)) {
					$result['restricted_events'][] = $event_name;
				}
			}
		}

		return $result;
	}

	/**
	 * Get the unique event names of all ACTIVE rules of one pixel scan entry.
	 *
	 * @param array $pixel One entry of the 'pixels' array of get_scan_results().
	 * @return array
	 * @since 1.63.1
	 */
	public static function get_active_rule_event_names( $pixel ) {

		$event_names = [];

		if (empty($pixel['active_rules'])) {
			return $event_names;
		}

		foreach ($pixel['active_rules'] as $rule) {
			if (!empty($rule['event']) && !in_array($rule['event'], $event_names, true)) {
				$event_names[] = $rule['event'];
			}
		}

		return $event_names;
	}

	/**
	 * Extract and decode the JSON array of an fbq.set("{key}", "{pixel_id}", [...]) call.
	 *
	 * @param string $body
	 * @param string $key
	 * @param string $pixel_id
	 * @return array|null The decoded array, or null when the call or a valid payload is not present.
	 * @since 1.63.1
	 */
	private static function extract_fbq_set_payload( $body, $key, $pixel_id ) {

		$pattern = '/fbq\.set\(\s*["\']' . preg_quote($key, '/') . '["\']\s*,\s*["\']' . preg_quote($pixel_id, '/') . '["\']\s*,\s*/';

		if (!preg_match($pattern, $body, $matches, PREG_OFFSET_CAPTURE)) {
			return null;
		}

		$payload_start = $matches[0][1] + strlen($matches[0][0]);

		if (!isset($body[$payload_start]) || '[' !== $body[$payload_start]) {
			return null;
		}

		$json = self::extract_balanced_array($body, $payload_start);

		if (null === $json) {
			return null;
		}

		$decoded = json_decode($json, true);

		return is_array($decoded) ? $decoded : null;
	}

	/**
	 * Extract and decode the JSON object of a config.set("{pixel_id}", "{key}", {...}) call.
	 *
	 * The signals config delivers per-pixel plugin configuration through
	 * config.set() calls with the pixel ID first and the config key second,
	 * the reverse of the fbq.set() argument order.
	 *
	 * @param string $body
	 * @param string $pixel_id
	 * @param string $key
	 * @return array|null The decoded object, or null when the call or a valid payload is not present.
	 * @since 1.63.1
	 */
	private static function extract_config_set_payload( $body, $pixel_id, $key ) {

		$pattern = '/config\.set\(\s*["\']' . preg_quote($pixel_id, '/') . '["\']\s*,\s*["\']' . preg_quote($key, '/') . '["\']\s*,\s*/';

		if (!preg_match($pattern, $body, $matches, PREG_OFFSET_CAPTURE)) {
			return null;
		}

		$payload_start = $matches[0][1] + strlen($matches[0][0]);

		if (!isset($body[$payload_start]) || '{' !== $body[$payload_start]) {
			return null;
		}

		$json = self::extract_balanced_array($body, $payload_start);

		if (null === $json) {
			return null;
		}

		$decoded = json_decode($json, true);

		return is_array($decoded) ? $decoded : null;
	}

	/**
	 * Extract a balanced JSON structure starting at $start (which must point at a '[' or '{').
	 *
	 * Tracks string context so brackets inside string values (e.g. button
	 * texts in rule conditions) don't break the matching.
	 *
	 * @param string $body
	 * @param int    $start
	 * @return string|null The structure including the outer brackets, or null when unbalanced.
	 * @since 1.63.1
	 */
	private static function extract_balanced_array( $body, $start ) {

		$length    = strlen($body);
		$depth     = 0;
		$in_string = false;
		$escaped   = false;

		for ($i = $start; $i < $length; $i++) {

			$char = $body[$i];

			if ($in_string) {
				if ($escaped) {
					$escaped = false;
				} elseif ('\\' === $char) {
					$escaped = true;
				} elseif ('"' === $char) {
					$in_string = false;
				}
				continue;
			}

			if ('"' === $char) {
				$in_string = true;
				continue;
			}

			if ('[' === $char || '{' === $char) {
				++$depth;
				continue;
			}

			if (']' === $char || '}' === $char) {
				--$depth;

				if (0 === $depth) {
					return substr($body, $start, $i - $start + 1);
				}
			}
		}

		return null;
	}
}
