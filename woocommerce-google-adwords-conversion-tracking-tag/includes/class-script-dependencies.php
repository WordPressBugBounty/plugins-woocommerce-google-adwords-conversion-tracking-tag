<?php

namespace SweetCode\Pixel_Manager;

defined('ABSPATH') || exit; // Exit if accessed directly

/**
 * Detects when WordPress drops the Pixel Manager front-end bundle.
 *
 * The bundle is enqueued with the dependencies jquery and wp-hooks. When one
 * of them, or one of their own dependencies such as jquery-core, is not
 * registered at print time, WordPress silently skips the bundle: no script
 * tag, no console warning, no PHP notice. pmwDataLayer still prints, so the
 * shop looks configured while no pixel fires (Verdict #199, support
 * 3496544615: a theme deregistered jquery).
 *
 * The check runs on wp_print_footer_scripts, after WordPress printed the
 * footer scripts, so it sees the final state no matter at which priority a
 * theme or plugin deregistered the dependency. The last occurrence is stored
 * in an autoloaded option, which costs nothing on healthy requests, and the
 * debug info and the Diagnostics tab report it.
 *
 * @since 1.71.1
 */
class Script_Dependencies {

	const OPTION_NAME = 'pmw_script_dependencies_missing';

	/**
	 * A detection that was not seen again for this long is treated as resolved.
	 */
	const STALE_AFTER_SECONDS = WEEK_IN_SECONDS;

	/**
	 * Minimum interval between two writes of the same detection, so a broken
	 * shop does not write the option on every page view.
	 */
	const REWRITE_INTERVAL_SECONDS = HOUR_IN_SECONDS;

	/**
	 * Hook the print time check.
	 *
	 * @return void
	 * @since 1.71.1
	 */
	public static function init() {
		// After _wp_footer_scripts (priority 10), so the head and footer queues are both processed.
		add_action('wp_print_footer_scripts', [ __CLASS__, 'check_after_print' ], 100);
	}

	/**
	 * Return the handles in the dependency tree of $handle that are not
	 * registered, which makes WordPress drop $handle when it prints scripts.
	 *
	 * @param string             $handle  The script handle to inspect.
	 * @param \WP_Scripts|null   $scripts The scripts registry, wp_scripts() by default.
	 * @return string[]
	 * @since 1.71.1
	 */
	public static function get_missing_dependencies( $handle, $scripts = null ) {

		$scripts = $scripts ? $scripts : wp_scripts();

		if (!isset($scripts->registered[ $handle ])) {
			return [];
		}

		$missing = [];
		$visited = [];
		$stack   = (array) $scripts->registered[ $handle ]->deps;

		while ($stack) {
			$dependency = (string) array_pop($stack);

			if (isset($visited[ $dependency ])) {
				continue;
			}

			$visited[ $dependency ] = true;

			if (!isset($scripts->registered[ $dependency ])) {
				$missing[] = $dependency;
				continue;
			}

			foreach ((array) $scripts->registered[ $dependency ]->deps as $sub_dependency) {
				$stack[] = $sub_dependency;
			}
		}

		sort($missing);

		return $missing;
	}

	/**
	 * After the footer scripts are printed, record when the enqueued bundle
	 * was not printed because a dependency is missing.
	 *
	 * @return void
	 * @since 1.71.1
	 */
	public static function check_after_print() {

		$scripts = wp_scripts();

		// Not enqueued on this request, or printed fine.
		if (!in_array('pmw', (array) $scripts->queue, true) || in_array('pmw', (array) $scripts->done, true)) {
			return;
		}

		$missing = self::get_missing_dependencies('pmw', $scripts);

		if (!$missing) {
			return;
		}

		self::record($missing);
	}

	/**
	 * Store a detection, at most once per REWRITE_INTERVAL_SECONDS unless the
	 * set of missing handles changed.
	 *
	 * @param string[] $missing Missing dependency handles.
	 * @param int|null $now     Current timestamp, for tests.
	 * @return void
	 * @since 1.71.1
	 */
	public static function record( $missing, $now = null ) {

		$now    = null === $now ? time() : (int) $now;
		$stored = get_option(self::OPTION_NAME);

		if (
			is_array($stored)
			&& isset($stored['missing'], $stored['last_seen'])
			&& $stored['missing'] === $missing
			&& ( $now - (int) $stored['last_seen'] ) < self::REWRITE_INTERVAL_SECONDS
		) {
			return;
		}

		$path = isset($_SERVER['REQUEST_URI']) ? (string) wp_parse_url(esc_url_raw(wp_unslash($_SERVER['REQUEST_URI'])), PHP_URL_PATH) : '';

		update_option(
			self::OPTION_NAME,
			[
				'missing'   => array_values(array_map('strval', $missing)),
				'last_seen' => $now,
				'path'      => $path,
			],
			true
		);
	}

	/**
	 * The current detection, or null when there is none or it went stale.
	 *
	 * @param int|null $now Current timestamp, for tests.
	 * @return array|null { missing: string[], last_seen: int, path: string }
	 * @since 1.71.1
	 */
	public static function get_detection( $now = null ) {

		$now    = null === $now ? time() : (int) $now;
		$stored = get_option(self::OPTION_NAME);

		if (!is_array($stored) || empty($stored['missing']) || empty($stored['last_seen'])) {
			return null;
		}

		if (( $now - (int) $stored['last_seen'] ) > self::STALE_AFTER_SECONDS) {
			return null;
		}

		return [
			'missing'   => array_values(array_map('strval', (array) $stored['missing'])),
			'last_seen' => (int) $stored['last_seen'],
			'path'      => isset($stored['path']) ? (string) $stored['path'] : '',
		];
	}

	/**
	 * Human readable explanation of the current detection, shared by the debug
	 * info and the Diagnostics tab. Null when there is nothing to report.
	 *
	 * @param int|null $now Current timestamp, for tests.
	 * @return string|null
	 * @since 1.71.1
	 */
	public static function get_warning_message( $now = null ) {

		$detection = self::get_detection($now);

		if (!$detection) {
			return null;
		}

		$message = sprintf(
			'WordPress did not output the Pixel Manager script because its dependency %1$s is not registered, so no pixel loads in the browser. A theme, plugin or script optimizer deregistered it. Keep the script registered (it may load from a CDN) and turn off any setting that removes it. Last seen %2$s UTC',
			implode(', ', $detection['missing']),
			gmdate('Y-m-d H:i', $detection['last_seen'])
		);

		if ('' !== $detection['path']) {
			$message .= ' on ' . $detection['path'];
		}

		return $message . '.';
	}
}
