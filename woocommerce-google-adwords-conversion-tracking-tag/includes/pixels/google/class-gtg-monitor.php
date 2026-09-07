<?php

namespace SweetCode\Pixel_Manager\Pixels\Google;

use SweetCode\Pixel_Manager\Logger;
use SweetCode\Pixel_Manager\Options;

defined('ABSPATH') || exit; // Exit if accessed directly

/**
 * Google Tag Gateway 404 Monitor
 *
 * Watches for Google Tag Gateway traffic that falls through to a WordPress
 * 404 instead of being answered by a gateway handler (edge, standalone or
 * WordPress proxy). Each of those requests costs a full WordPress 404 page
 * load, which under real visitor traffic can exhaust the server (PHP-FPM
 * pools, 404 logging plugins etc.).
 *
 * The health check cannot detect this reliably: it only probes endpoints the
 * proxy is known to answer, and Google rolls out new gateway endpoints (like
 * the service worker) in stages. This monitor counts the actual failures
 * instead, so it only fires on sites where real gateway traffic is being
 * dropped. It also runs when no measurement path is configured, because
 * browsers keep requesting gateway URLs under a previously configured path
 * for as long as the cached tag and its service worker live.
 *
 * The counter is surfaced through the log (one warning per day past the
 * threshold) and the debug report, where support sees it. It deliberately
 * has no admin notice: there is no in-plugin action an admin could take,
 * so a warning banner would only cause alarm without a remedy.
 *
 * @since 1.66.1
 */
class GTG_Monitor {

	/**
	 * Option key for the daily 404 counter (not autoloaded)
	 */
	const OPTION_KEY = 'pmw_gtg_404_monitor';

	/**
	 * Stop writing to the database after this many recorded 404s per day.
	 *
	 * The counter is a warning signal, not an analytics feature. Capping the
	 * writes keeps a request storm from turning into database churn on top of
	 * the 404s themselves.
	 */
	const DAILY_WRITE_CAP = 500;

	/**
	 * Number of gateway 404s per day at which the log warning is written
	 * and the debug report entry is flagged.
	 */
	const WARNING_THRESHOLD = 20;

	/**
	 * Initialize the monitor
	 *
	 * @return void
	 */
	public static function init() {

		// Front end: record gateway requests that are about to render a 404 page
		add_action('template_redirect', [ __CLASS__, 'maybe_record_gateway_404' ], 0);
	}

	/**
	 * Record a 404 that targets the Google Tag Gateway
	 *
	 * Runs on template_redirect so it only sees requests WordPress is about
	 * to answer with a 404 page, i.e. requests no gateway handler picked up.
	 *
	 * @return void
	 */
	public static function maybe_record_gateway_404() {

		if (!is_404()) {
			return;
		}

		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Raw URI needed for path matching, sanitized before storage
		$request_uri = isset( $_SERVER['REQUEST_URI'] ) ? $_SERVER['REQUEST_URI'] : '';

		if (!self::is_gateway_request($request_uri)) {
			return;
		}

		$today = gmdate('Y-m-d');
		$stats = get_option(self::OPTION_KEY);

		if (!is_array($stats) || !isset($stats['date'], $stats['count']) || $stats['date'] !== $today) {
			$stats = [
				'date'  => $today,
				'count' => 0,
			];
		}

		if ($stats['count'] >= self::DAILY_WRITE_CAP) {
			return;
		}

		++$stats['count'];
		$stats['last_uri'] = substr(esc_url_raw($request_uri), 0, 200);

		update_option(self::OPTION_KEY, $stats, false);

		// One log line per day, when the threshold is crossed
		if (self::WARNING_THRESHOLD === $stats['count']) {
			Logger::warning(
				sprintf(
					'[GTG-Monitor] %1$d Google Tag Gateway requests ended in a WordPress 404 today. Last URI: %2$s',
					$stats['count'],
					$stats['last_uri']
				)
			);
		}
	}

	/**
	 * Check whether a request URI looks like Google Tag Gateway traffic
	 *
	 * Matches requests under the configured measurement path as well as
	 * gateway service worker requests, which are identifiable without knowing
	 * the measurement path. The latter keep arriving under the old path when
	 * the measurement path was changed or removed while visitors' browsers
	 * still run a previously served tag.
	 *
	 * @param string $request_uri The raw request URI.
	 * @return bool True if the request targets the Google Tag Gateway.
	 */
	public static function is_gateway_request( $request_uri ) {

		if (empty($request_uri)) {
			return false;
		}

		if (false !== strpos($request_uri, '/_/service_worker/')) {
			return true;
		}

		$measurement_path = Options::get_google_tag_gateway_measurement_path();

		return $measurement_path && GTG_Proxy::is_measurement_path_request($request_uri, $measurement_path);
	}

	/**
	 * Get the recorded 404 stats
	 *
	 * @return array|null Array with date, count and last_uri, or null if nothing recorded.
	 */
	public static function get_stats() {

		$stats = get_option(self::OPTION_KEY);

		if (!is_array($stats) || !isset($stats['date'], $stats['count'])) {
			return null;
		}

		return $stats;
	}

	/**
	 * Get the recorded 404 stats formatted for the debug report
	 *
	 * @return string One or two plain text lines for the debug report.
	 */
	public static function get_stats_for_debug_info() {

		$stats = self::get_stats();

		if (!$stats) {
			return 'Gateway requests ending in WordPress 404s: none recorded' . PHP_EOL;
		}

		$is_current = gmdate('Y-m-d') === $stats['date'];
		$warning    = ( $is_current && $stats['count'] >= self::WARNING_THRESHOLD ) ? '❗ ' : '';

		$html  = $warning . 'Gateway requests ending in WordPress 404s: ' . $stats['count'] . ' on ' . $stats['date'];
		$html .= $stats['count'] >= self::DAILY_WRITE_CAP ? ' (counting stopped at the daily cap)' : '';
		$html .= PHP_EOL;

		if (!empty($stats['last_uri'])) {
			$html .= 'Last 404 URI:                              ' . $stats['last_uri'] . PHP_EOL;
		}

		return $html;
	}
}
