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

		if (Environment::is_woocommerce_active()) {

			$logger = wc_get_logger();
			$logger->log($type, $message, [ 'source' => $source ]);

			// For development environment, log to error_log as well.
			if (Helpers::is_pmw_debug_mode_active()) {
				error_log($source . ' [' . $type . '] ' . $message);
			}

		} else {
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
