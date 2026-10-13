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

	/**
	 * WooCommerce's utility class for its logging settings (WooCommerce 8.6+).
	 *
	 * @since 1.69.3
	 */
	const WC_LOGGING_UTIL = 'Automattic\WooCommerce\Utilities\LoggingUtil';

	/**
	 * WooCommerce's file log handler (WooCommerce 8.6+).
	 *
	 * @since 1.69.3
	 */
	const WC_FILE_HANDLER_V2 = 'Automattic\WooCommerce\Internal\Admin\Logging\LogHandlerFileV2';

	/**
	 * The Pixel Manager's own WC_Logger instance, see get_woocommerce_logger().
	 *
	 * @var \WC_Logger|null
	 * @since 1.69.3
	 */
	private static $wc_logger;

	/**
	 * The threshold $wc_logger was created with.
	 *
	 * @var string|null
	 * @since 1.69.3
	 */
	private static $wc_logger_threshold;

	/**
	 * The file handler used while WooCommerce logging is switched off.
	 *
	 * @var \WC_Log_Handler_Interface|null
	 * @since 1.69.3
	 */
	private static $file_handler;

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

		// Email addresses: Klaviyo and Mixpanel take the customer's address in
		// clear text, so a logged payload used to carry it into the log file.
		// The local part is masked, the domain stays for diagnosis. @since 1.69.1
		$redacted = preg_replace('/\b[A-Za-z0-9._%+-]+(@[A-Za-z0-9.-]+\.[A-Za-z]{2,})/', '***$1', $message);

		if (is_string($redacted)) {
			$message = $redacted;
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

		if (Environment::is_woocommerce_active() && function_exists('wc_get_logger')) {

			self::write_to_woocommerce($type, $message, [ 'source' => $source ]);

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

	/**
	 * Hand an entry to WooCommerce's logging, making sure WooCommerce's global
	 * settings don't silently drop it while the Pixel Manager logger is on.
	 *
	 * - A logger that a shop swapped in through the woocommerce_logging_class
	 *   filter gets every entry, as before: the shop wants its log in that
	 *   system, and the report says so.
	 * - With WooCommerce logging switched off (WooCommerce 8.6+), the entry is
	 *   written straight to the regular WooCommerce log files, so "View latest
	 *   log" and "Download logs" keep working.
	 * - Otherwise PMW writes through its own WC_Logger instance with PMW's
	 *   level as threshold, so WooCommerce's global level threshold no longer
	 *   discards PMW entries. The handlers stay WooCommerce's default ones,
	 *   which keeps the storage choice and woocommerce_register_log_handlers.
	 *
	 * @param string $level   The log level.
	 * @param string $message The redacted message.
	 * @param array  $context The log context with the source.
	 * @return void
	 * @since 1.69.3
	 */
	private static function write_to_woocommerce( $level, $message, $context ) {

		try {

			$logger = wc_get_logger();

			if (!is_object($logger) || 'WC_Logger' !== get_class($logger)) {

				if (is_object($logger)) {
					$logger->log($level, $message, $context);
				}

				return;
			}

			if (self::is_woocommerce_logging_disabled() && self::write_directly_to_file($level, $message, $context)) {
				return;
			}

			self::get_woocommerce_logger()->log($level, $message, $context);
		} catch (\Exception $e) {
			// phpcs:ignore QITStandard.PHP.DebugCode.DebugFunctionFound -- Keep the entry when WooCommerce's logging throws.
			error_log($context['source'] . ' [' . $level . '] ' . $message);
		} catch (\Throwable $e) {
			// phpcs:ignore QITStandard.PHP.DebugCode.DebugFunctionFound -- Keep the entry when WooCommerce's logging throws.
			error_log($context['source'] . ' [' . $level . '] ' . $message);
		}
	}

	/**
	 * Whether WooCommerce's "Enable logging" switch is off. WooCommerce
	 * versions before 8.6 have no such switch.
	 *
	 * @return bool
	 * @since 1.69.3
	 */
	private static function is_woocommerce_logging_disabled() {
		return method_exists(self::WC_LOGGING_UTIL, 'logging_is_enabled')
			&& !call_user_func([ self::WC_LOGGING_UTIL, 'logging_is_enabled' ]);
	}

	/**
	 * The Pixel Manager's own WC_Logger, created with PMW's effective level as
	 * its threshold. WC_Logger prefers a threshold passed to its constructor
	 * over WooCommerce's global setting and the WC_LOG_THRESHOLD constant.
	 *
	 * @return \WC_Logger
	 * @since 1.69.3
	 */
	private static function get_woocommerce_logger() {

		$threshold = self::get_effective_log_level();

		if (!self::$wc_logger instanceof \WC_Logger || self::$wc_logger_threshold !== $threshold) {
			self::$wc_logger           = new \WC_Logger(null, $threshold);
			self::$wc_logger_threshold = $threshold;
		}

		return self::$wc_logger;
	}

	/**
	 * Write an entry with WooCommerce's file handler, bypassing the logging
	 * switch and the level threshold that only WC_Logger applies. The entry
	 * lands in the same wc-logs file, with the same source, as a regular one.
	 *
	 * @param string $level   The log level.
	 * @param string $message The redacted message.
	 * @param array  $context The log context with the source.
	 * @return bool False when the file handler is not available.
	 * @since 1.69.3
	 */
	private static function write_directly_to_file( $level, $message, $context ) {

		$class = self::WC_FILE_HANDLER_V2;

		if (!class_exists($class)) {
			return false;
		}

		if (!self::$file_handler instanceof $class) {
			self::$file_handler = new $class();
		}

		/**
		 * The filter WC_Logger::log() runs for every handler, so a shop's
		 * message filter keeps working on this path. Returning null skips the
		 * entry, exactly as it does there.
		 *
		 * @since 1.69.3
		 */
		$message = apply_filters('woocommerce_logger_log_message', $message, $level, $context, self::$file_handler);

		if (null !== $message) {
			self::$file_handler->handle(time(), $level, $message, $context);
		}

		return true;
	}

	/**
	 * Delete the Pixel Manager's own log files once they are older than
	 * WooCommerce's retention period, where WooCommerce's cleanup can't reach
	 * them.
	 *
	 * WooCommerce's daily woocommerce_cleanup_logs action keeps running while
	 * logging is switched off, but it only cleans up through the default
	 * handler. With the database handler selected, the files PMW writes while
	 * WooCommerce logging is off would stay forever. Runs on that same action.
	 *
	 * @return int The number of deleted files.
	 * @since 1.69.3
	 */
	public static function delete_expired_log_files() {

		$util = self::WC_LOGGING_UTIL;

		if (
			!method_exists($util, 'get_default_handler')
			|| !method_exists($util, 'get_retention_period')
			|| !method_exists($util, 'get_log_directory')
		) {
			return 0;
		}

		try {

			$handler = (string) call_user_func([ $util, 'get_default_handler' ]);

			// WooCommerce's own cleanup covers the files.
			if (class_exists(self::WC_FILE_HANDLER_V2) && is_a($handler, self::WC_FILE_HANDLER_V2, true)) {
				return 0;
			}

			$days      = max(1, (int) call_user_func([ $util, 'get_retention_period' ]));
			$directory = (string) call_user_func([ $util, 'get_log_directory' ], false);

			return self::delete_log_files_created_before($directory, strtotime('-' . $days . ' days'));
		} catch (\Exception $e) {
			return 0;
		} catch (\Throwable $e) {
			return 0;
		}
	}

	/**
	 * Delete the Pixel Manager's log files created before a point in time.
	 *
	 * Only files named like WooCommerce names the files of the "pmw" source
	 * qualify ("pmw-2026-09-24-<hash>.log", rotated "pmw.1-2026-09-24-<hash>.log"),
	 * so another source that merely starts with "pmw" is never touched. Like
	 * WooCommerce, the creation date comes from the file name.
	 *
	 * @param string $directory The log directory.
	 * @param int    $timestamp Files created before this time are deleted.
	 * @return int The number of deleted files.
	 * @since 1.69.3
	 */
	public static function delete_log_files_created_before( $directory, $timestamp ) {

		if (!is_string($directory) || '' === $directory || !is_dir($directory) || empty($timestamp)) {
			return 0;
		}

		$paths = glob(trailingslashit($directory) . 'pmw*.log');

		if (!is_array($paths)) {
			return 0;
		}

		$deleted = 0;

		foreach ($paths as $path) {

			if (!preg_match('/^pmw(?:\.\d+)?-(\d{4}-\d{2}-\d{2})-[a-f0-9]{32}\.log$/', basename($path), $matches)) {
				continue;
			}

			$created = strtotime($matches[1]);

			if (false === $created || $created >= $timestamp) {
				continue;
			}

			wp_delete_file($path);

			if (!file_exists($path)) {
				++$deleted;
			}
		}

		return $deleted;
	}

	/**
	 * WooCommerce's log level severities, lowest first. Mirrors WC_Log_Levels
	 * so the comparison does not depend on which WooCommerce version is
	 * installed, or on WooCommerce being loaded at all in a unit test.
	 *
	 * @return array<string, int>
	 * @since 1.69.3
	 */
	private static function get_woocommerce_level_severities() {
		return [
			'debug'     => 100,
			'info'      => 200,
			'notice'    => 300,
			'warning'   => 400,
			'error'     => 500,
			'critical'  => 600,
			'alert'     => 700,
			'emergency' => 800,
		];
	}

	/**
	 * The level PMW actually writes from. PMW_DEBUG logs everything, whatever
	 * the setting says.
	 *
	 * @return string
	 * @since 1.69.3
	 */
	public static function get_effective_log_level() {
		return Helpers::is_pmw_debug_mode_active() ? 'debug' : (string) Options::get_log_level();
	}

	/**
	 * WooCommerce's own logging state.
	 *
	 * Since WooCommerce 8.6 a shop can switch logging off entirely, store the
	 * entries in the database instead of in files, and set a level threshold
	 * (in WooCommerce > Status > Logs > Settings or with the WC_LOG_THRESHOLD
	 * constant). PMW's writer works around the switch and the threshold (see
	 * write_to_woocommerce()), the storage choice it respects. The report shows
	 * all of it, so support can tell where the entries went.
	 *
	 * Older WooCommerce versions have no settings screen: logging is always on,
	 * and only the WC_LOG_HANDLER and WC_LOG_THRESHOLD constants apply.
	 *
	 * @return array{available: bool, enabled: bool, handler: string, handler_is_file: bool, threshold: string, threshold_source: string, logger_class: string, file_handler_available: bool, retention_days: int}
	 * @since 1.69.3
	 */
	public static function get_woocommerce_logging_state() {

		$state = [
			'available'              => false,
			'enabled'                => false,
			'handler'                => '',
			'handler_is_file'        => false,
			'threshold'              => 'none',
			'threshold_source'       => 'default',
			'logger_class'           => '',
			'file_handler_available' => false,
			'retention_days'         => 0,
		];

		if (!Environment::is_woocommerce_active() || !function_exists('wc_get_logger')) {
			return $state;
		}

		$util = self::WC_LOGGING_UTIL;

		try {

			$state['available'] = true;

			$state['enabled'] = method_exists($util, 'logging_is_enabled')
				? (bool) call_user_func([ $util, 'logging_is_enabled' ])
				: true;

			if (method_exists($util, 'get_default_handler')) {
				$state['handler'] = (string) call_user_func([ $util, 'get_default_handler' ]);
			} elseif (defined('WC_LOG_HANDLER')) {
				$state['handler'] = (string) constant('WC_LOG_HANDLER');
			} else {
				$state['handler'] = 'WC_Log_Handler_File';
			}

			$file_handlers = [
				self::WC_FILE_HANDLER_V2,
				'WC_Log_Handler_File',
			];

			foreach ($file_handlers as $file_handler) {
				if (class_exists($file_handler) && is_a($state['handler'], $file_handler, true)) {
					$state['handler_is_file'] = true;
				}
			}

			$state['file_handler_available'] = class_exists(self::WC_FILE_HANDLER_V2);

			if (method_exists($util, 'get_level_threshold')) {
				$state['threshold'] = (string) call_user_func([ $util, 'get_level_threshold' ]);
			} elseif (defined('WC_LOG_THRESHOLD')) {
				$state['threshold'] = (string) constant('WC_LOG_THRESHOLD');
			}

			if (defined('WC_LOG_THRESHOLD')) {
				$state['threshold_source'] = 'WC_LOG_THRESHOLD constant';
			} elseif (method_exists($util, 'get_level_threshold')) {
				$state['threshold_source'] = 'WooCommerce settings';
			}

			if (method_exists($util, 'get_retention_period')) {
				$state['retention_days'] = (int) call_user_func([ $util, 'get_retention_period' ]);
			}

			$logger = wc_get_logger();

			if (is_object($logger)) {
				$state['logger_class'] = get_class($logger);
			}
		} catch (\Exception $e) {
			$state['available'] = false;
		} catch (\Throwable $e) {
			$state['available'] = false;
		}

		return $state;
	}

	/**
	 * Where the Pixel Manager's entries go, given WooCommerce's logging state.
	 * Mirrors the decisions in write_to_woocommerce().
	 *
	 * @param array $state As returned by get_woocommerce_logging_state().
	 * @return string One of error_log, replaced_logger, direct_file, discarded, pmw_logger.
	 * @since 1.69.3
	 */
	public static function get_write_path( $state ) {

		if (empty($state['available'])) {
			return 'error_log';
		}

		if (!empty($state['logger_class']) && 'WC_Logger' !== $state['logger_class']) {
			return 'replaced_logger';
		}

		if (empty($state['enabled'])) {
			return empty($state['file_handler_available']) ? 'discarded' : 'direct_file';
		}

		return 'pmw_logger';
	}

	/**
	 * What in WooCommerce's logging state still keeps PMW's entries out of the
	 * log, or out of reach of the Logger card's buttons.
	 *
	 * The level threshold only matters when a replaced logger receives the
	 * entries: PMW's own WC_Logger brings its own threshold, and the direct
	 * file write has none.
	 *
	 * Split from the state lookup so it can be tested without WooCommerce.
	 *
	 * @param array  $state     As returned by get_woocommerce_logging_state().
	 * @param string $pmw_level The level PMW writes from.
	 * @return string[] One sentence per problem, empty when there is none.
	 * @since 1.69.3
	 */
	public static function get_woocommerce_logging_warnings( $state, $pmw_level ) {

		$write_path = self::get_write_path($state);

		if ('error_log' === $write_path) {
			return [];
		}

		if ('discarded' === $write_path) {
			return [ 'WooCommerce logging is disabled (WooCommerce > Status > Logs > Settings) and its file log handler is not available, so WooCommerce discards every Pixel Manager log entry.' ];
		}

		$warnings = [];

		if ('replaced_logger' === $write_path) {

			$warnings[] = 'WooCommerce\'s logger is replaced by ' . $state['logger_class'] . ' through the woocommerce_logging_class filter. The Pixel Manager writes through it, so that logger decides which entries are kept and where.';

			$severities = self::get_woocommerce_level_severities();
			$threshold  = isset($state['threshold']) ? (string) $state['threshold'] : 'none';

			// "none" and anything WooCommerce does not recognize keep every level.
			$threshold_severity = isset($severities[$threshold]) ? $severities[$threshold] : 0;
			$pmw_severity       = isset($severities[$pmw_level]) ? $severities[$pmw_level] : $severities['debug'];

			if ($threshold_severity > $pmw_severity) {

				$discarded = [];

				foreach (self::get_log_levels() as $level) {
					if ($severities[$level] >= $pmw_severity && $severities[$level] < $threshold_severity) {
						$discarded[] = $level;
					}
				}

				$source = isset($state['threshold_source']) && 'WC_LOG_THRESHOLD constant' === $state['threshold_source']
					? 'the WC_LOG_THRESHOLD constant'
					: 'WooCommerce > Status > Logs > Settings';

				$warnings[] = 'WooCommerce only keeps log entries of level "' . $threshold . '" and above (set in ' . $source . '). If the replaced logger applies it, the Pixel Manager\'s "'
					. implode('", "', $discarded) . '" entries are discarded.';
			}
		}

		if ('pmw_logger' === $write_path && !empty($state['handler']) && empty($state['handler_is_file'])) {
			$warnings[] = 'WooCommerce stores log entries with ' . $state['handler'] . ' instead of in files, so "View latest log" and "Download logs" find nothing. Read the entries under WooCommerce > Status > Logs.';
		}

		return $warnings;
	}

	/**
	 * The notes the Logger card shows while the PMW logger is on, translated.
	 *
	 * @param array $state As returned by get_woocommerce_logging_state().
	 * @return string[]
	 * @since 1.69.3
	 */
	public static function get_logger_card_notices( $state ) {

		$write_path = self::get_write_path($state);

		if ('direct_file' === $write_path) {
			return [ __('The Pixel Manager writes its log even though WooCommerce logging is disabled.', 'woocommerce-google-adwords-conversion-tracking-tag') ];
		}

		if ('pmw_logger' !== $write_path || empty($state['handler']) || !empty($state['handler_is_file'])) {
			return [];
		}

		if ('WC_Log_Handler_DB' === $state['handler'] || ( class_exists('WC_Log_Handler_DB') && is_a($state['handler'], 'WC_Log_Handler_DB', true) )) {
			return [ __('WooCommerce stores its log entries in the database, so the Pixel Manager entries are only visible under WooCommerce > Status > Logs, not through the "View latest log" and "Download logs" buttons.', 'woocommerce-google-adwords-conversion-tracking-tag') ];
		}

		return [
			sprintf(
				/* translators: %s: the class name of WooCommerce's log handler */
				__('WooCommerce hands its log entries to %s instead of writing them to files, so the "View latest log" and "Download logs" buttons find no Pixel Manager entries.', 'woocommerce-google-adwords-conversion-tracking-tag'),
				$state['handler']
			),
		];
	}

	/**
	 * WooCommerce's logging state as the debug report prints it.
	 *
	 * @return string
	 * @since 1.69.3
	 */
	public static function get_debug_info() {

		$html  = 'PMW logger active:              ' . ( self::is_logger_active() ? 'yes' : 'no' ) . ( Helpers::is_pmw_debug_mode_active() ? ' (PMW_DEBUG)' : '' ) . PHP_EOL;
		$html .= 'PMW log level:                  ' . self::get_effective_log_level() . PHP_EOL;
		$html .= 'PMW HTTP request logging:       ' . ( Options::is_http_request_logging_enabled() ? 'yes' : 'no' ) . PHP_EOL;

		$state = self::get_woocommerce_logging_state();

		if (empty($state['available'])) {
			return $html . 'WooCommerce logging:            unavailable (entries go to the PHP error log)' . PHP_EOL;
		}

		$write_path = self::get_write_path($state);

		$threshold = ( 'none' === $state['threshold'] ? 'none, keeps every level' : $state['threshold'] ) . ' (' . $state['threshold_source'] . ')';

		if ('pmw_logger' === $write_path || 'direct_file' === $write_path) {
			$threshold .= ', not applied to Pixel Manager entries';
		}

		$html .= 'WooCommerce logging enabled:    ' . ( $state['enabled'] ? 'yes' : 'no' ) . PHP_EOL;
		$html .= 'WooCommerce log handler:        ' . $state['handler'] . ( $state['handler_is_file'] ? ' (files)' : '' ) . PHP_EOL;
		$html .= 'WooCommerce level threshold:    ' . $threshold . PHP_EOL;

		if ($state['retention_days'] > 0) {
			$html .= 'WooCommerce log retention:      ' . $state['retention_days'] . ' days' . PHP_EOL;
		}

		if ('' !== $state['logger_class'] && 'WC_Logger' !== $state['logger_class']) {
			$html .= 'WooCommerce logger class:       ' . $state['logger_class'] . ' (replaced through woocommerce_logging_class)' . PHP_EOL;
		}

		$write_paths = [
			'pmw_logger'      => 'WooCommerce\'s log handler, through the Pixel Manager\'s own WC_Logger (threshold: ' . self::get_effective_log_level() . ')',
			'direct_file'     => 'the WooCommerce log files, written directly because WooCommerce logging is disabled',
			'replaced_logger' => 'the replaced WooCommerce logger',
			'discarded'       => 'nowhere, WooCommerce discards them',
		];

		$html .= 'PMW entries go to:              ' . $write_paths[$write_path] . PHP_EOL;

		foreach (self::get_woocommerce_logging_warnings($state, self::get_effective_log_level()) as $warning) {
			$html .= 'Warning: ' . $warning . ' <---------' . PHP_EOL;
		}

		return $html;
	}

	/**
	 * WooCommerce's logging state for the Logger card in the Nova admin UI.
	 *
	 * The card shows the notices only while the PMW logger is on, which it
	 * reads from the live setting, or from debugMode for PMW_DEBUG.
	 *
	 * @return array{available: bool, enabled: bool, handler: string, writePath: string, debugMode: bool, notices: string[]}
	 * @since 1.69.3
	 */
	public static function get_woocommerce_logging_for_nova() {

		$state   = self::get_woocommerce_logging_state();
		$handler = $state['handler'];

		// The short class name is enough in a card, the report keeps the full one.
		$position = strrpos($handler, '\\');

		if (false !== $position) {
			$handler = substr($handler, $position + 1);
		}

		return [
			'available' => (bool) $state['available'],
			'enabled'   => (bool) $state['enabled'],
			'handler'   => $handler,
			'writePath' => self::get_write_path($state),
			'debugMode' => (bool) Helpers::is_pmw_debug_mode_active(),
			'notices'   => self::get_logger_card_notices($state),
		];
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
