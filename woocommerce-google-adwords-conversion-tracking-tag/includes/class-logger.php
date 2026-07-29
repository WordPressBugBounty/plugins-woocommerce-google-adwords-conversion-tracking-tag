<?php

namespace SweetCode\Pixel_Manager;

use SweetCode\Pixel_Manager\Admin\Environment;

defined('ABSPATH') || exit; // Exit if accessed directly

/**
 * Logger class responsible for logging activities
 *
 * @since 1.35.1
 */
class Logger {

	/**
	 * Sub-pattern matching parameter, key and header names that carry credentials.
	 *
	 * The word list is embedded between an optional prefix and suffix group, each
	 * of which requires an underscore or dash as separator. That way `api_secret`,
	 * `capi_token` and `x-api-key` match, while `keyword`, `monkey` or `tokenizer`
	 * don't.
	 *
	 * @since 1.63.1
	 */
	const SENSITIVE_NAME_PATTERN = '(?:[a-z0-9]+[_\-])*(?:access[_\-]?token|api[_\-]?key|api[_\-]?secret|apikey|authorization|auth|credentials?|key|passwd|password|pwd|secret|signature|sig|token)(?:[_\-][a-z0-9]+)*';

	private static function is_logger_active() {
		return Helpers::is_pmw_debug_mode_active() || Options::is_logging_enabled();
	}

	private static function is_logger_not_active() {
		return !self::is_logger_active();
	}

	public static function get_log_levels() {
		return [
			0 => 'debug',
			1 => 'info',
			2 => 'warning',
			3 => 'error',
			4 => 'critical',
		];
	}

	public static function get_log_level_name_by_value( $value ) {
		$log_levels = self::get_log_levels();
		return $log_levels[$value];
	}

	public static function get_log_level_value_by_name( $name ) {
		$log_levels = self::get_log_levels();
		return array_search($name, $log_levels);
	}

	private static function can_log( $type ) {

		// Always log everything if the PMW_DEBUG constant is set to true.
		if (Helpers::is_pmw_debug_mode_active()) {
			return true;
		}

		// If logging is not enabled, then don't log anything.
		if (self::is_logger_not_active()) {
			return false;
		}

		// If $type is not a valid log level, then don't log anything.
		if (!in_array($type, self::get_log_levels())) {
			return false;
		}

		// If the numerical value of $type is greater than the numerical value of the log level set in the settings, then don't log anything.
		if (self::get_log_level_value_by_name($type) < self::get_log_level_value_by_name(Options::get_log_level())) {
			return false;
		}

		return true;
	}

	private static function can_not_log( $type ) {
		return !self::can_log($type);
	}

	/**
	 * Query parameter names that are safe to log in clear text.
	 *
	 * The URL redaction works with an allow list on purpose: everything that is
	 * not on this list gets masked. A new pixel that passes its credential as a
	 * query parameter is therefore masked by default, without anyone having to
	 * remember to register its parameter name here first.
	 *
	 * @return string[]
	 * @since 1.63.1
	 */
	private static function get_loggable_query_params() {
		return [
			'debug',
			'debug_mode',
			'event',
			'id',
			'measurement_id',
			'pixel_id',
			'test',
			'test_event_code',
			'test_mode',
			'v',
		];
	}

	/**
	 * Mask a secret, keeping the last four characters so that log readers can
	 * still tell two different tokens apart and match one against the value in
	 * the settings.
	 *
	 * @param string $value
	 *
	 * @return string
	 * @since 1.63.1
	 */
	private static function mask_value( $value ) {

		$value = (string) $value;

		if ('' === $value) {
			return $value;
		}

		if (8 >= strlen($value)) {
			return '***';
		}

		return '***' . substr($value, -4);
	}

	/**
	 * Mask the values of all query parameters that are not explicitly allow
	 * listed.
	 *
	 * Meta and Snapchat pass their CAPI token as `access_token`, GA4 passes its
	 * Measurement Protocol credential as `api_secret`, and future integrations
	 * will invent their own names. None of them may end up in the log file.
	 *
	 * @param string $url
	 *
	 * @return string
	 * @since 1.63.1
	 */
	public static function redact_url( $url ) {

		if (!is_string($url) || '' === $url) {
			return $url;
		}

		$separator_position = strpos($url, '?');

		if (false === $separator_position) {
			return $url;
		}

		$base  = substr($url, 0, $separator_position);
		$query = substr($url, $separator_position + 1);

		// Keep a potential fragment out of the last parameter's value.
		$fragment          = '';
		$fragment_position = strpos($query, '#');

		if (false !== $fragment_position) {
			$fragment = substr($query, $fragment_position);
			$query    = substr($query, 0, $fragment_position);
		}

		$loggable = self::get_loggable_query_params();
		$pairs    = explode('&', $query);

		foreach ($pairs as $index => $pair) {

			if ('' === $pair) {
				continue;
			}

			$parts = explode('=', $pair, 2);

			if (!isset($parts[1]) || '' === $parts[1]) {
				continue;
			}

			if (in_array(strtolower($parts[0]), $loggable, true)) {
				continue;
			}

			$pairs[$index] = $parts[0] . '=' . self::mask_value($parts[1]);
		}

		return $base . '?' . implode('&', $pairs) . $fragment;
	}

	/**
	 * Mask credentials in an arbitrary log message.
	 *
	 * This is the backstop that runs over every single message, no matter which
	 * class assembled it. It covers the shapes credentials appear in:
	 * query strings, JSON, print_r output, and Authorization headers.
	 *
	 * @param string $message
	 *
	 * @return string
	 * @since 1.63.1
	 */
	public static function redact( $message ) {

		if (!is_string($message) || '' === $message) {
			return $message;
		}

		$mask_second_group = function ( $matches ) {
			return $matches[1] . self::mask_value($matches[2]);
		};

		$patterns = [
			// Query string: ?access_token=abc / &api_secret=abc
			'/([?&]' . self::SENSITIVE_NAME_PATTERN . '=)([^&\s"\'<>]+)/i',
			// JSON and PHP arrays: "token":"abc" / 'Authorization' => 'Bearer abc'
			'/(["\']' . self::SENSITIVE_NAME_PATTERN . '["\']\s*(?::|=>)\s*["\'])([^"\']+)/i',
			// print_r output: [access_token] => abc
			'/(\[\s*' . self::SENSITIVE_NAME_PATTERN . '\s*\]\s*=>\s*)([^\r\n]+)/i',
			// Raw headers: Authorization: Bearer abc / x-api-key: abc
			'/((?:authorization|x-api-key|api-key|access-token)\s*:\s*)([^\r\n,;]+)/i',
			// Any leftover credential that travels with its scheme.
			'/(\b(?:Bearer|Basic)\s+)([A-Za-z0-9\-\._~\+\/=]{8,})/',
		];

		foreach ($patterns as $pattern) {

			$redacted = preg_replace_callback($pattern, $mask_second_group, $message);

			// preg_replace_callback() returns null when it hits a PCRE limit,
			// which a very large payload dump can do. Better to keep the
			// message that the previous patterns already scrubbed than to
			// replace it with nothing.
			if (is_string($redacted)) {
				$message = $redacted;
			}
		}

		return $message;
	}

	/**
	 * Log a message with specified log level.
	 *
	 * @param string $message Message to be logged.
	 * @param string $type    Type/Level of log (info, debug, warning, error).
	 *
	 * @since 1.35.1
	 */
	private static function log( $message, $type = 'info' ) {

		$source = 'pmw';

		if (self::can_not_log($type)) {
			return;
		}

		// Never write credentials into the log file, no matter which class
		// assembled the message.
		$message = self::redact($message);

		if (Environment::is_woocommerce_active()) {

			$logger = wc_get_logger();
			$logger->log($type, $message, [ 'source' => $source ]);

			// For development environment, log to error_log as well.
			if (Helpers::is_pmw_debug_mode_active()) {
				// phpcs:ignore QITStandard.PHP.DebugCode.DebugFunctionFound -- Intentional debug-mode logging.
				error_log($source . ' [' . $type . '] ' . $message);
			}

		} else {
			// phpcs:ignore QITStandard.PHP.DebugCode.DebugFunctionFound -- Fallback when WooCommerce logger is unavailable.
			error_log($source . ' [' . $type . '] ' . $message);
		}
	}

	public static function info( $message ) {
		self::log($message, 'info');
	}

	public static function debug( $message ) {
		self::log($message, 'debug');
	}

	public static function warning( $message ) {
		self::log($message, 'warning');
	}

	public static function error( $message ) {
		self::log($message, 'error');
	}

	public static function critical( $message ) {
		self::log($message, 'critical');
	}
}
