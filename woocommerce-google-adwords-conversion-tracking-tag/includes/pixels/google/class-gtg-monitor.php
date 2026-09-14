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
 * Since 1.67.1 the monitor also answers stale service worker requests
 * itself: a worker that Google's tag registered under a path no handler
 * serves any more gets a 410 Gone before WordPress runs the main query and
 * renders the theme's 404 page. That turns seconds of PHP worker time per
 * visitor into a few milliseconds, and the browser drops the registration.
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

		// Stale service worker requests are answered before WordPress runs the
		// main query. Priority 20 leaves the proxy (priority 10) the first look
		// at requests under a configured measurement path.
		add_filter('do_parse_request', [ __CLASS__, 'maybe_answer_stale_service_worker_request' ], 20);

		// Front end: record gateway requests that are about to render a 404 page
		add_action('template_redirect', [ __CLASS__, 'maybe_record_gateway_404' ], 0);
	}

	/**
	 * Answer a stale gateway service worker request with a 410 before WordPress renders a 404 page
	 *
	 * Google's tag registers its service worker under the path it was served
	 * from. After the measurement path was changed or removed, or when the tag
	 * came through the standalone proxy file, browsers keep asking for that
	 * worker on every navigation, and each such request rendered the theme's
	 * full 404 page. On a slow host that is seconds of a PHP worker per visitor.
	 *
	 * 410 is the status the Service Worker specification names for a worker
	 * that is gone: the browser unregisters it instead of retrying.
	 *
	 * Runs on do_parse_request after the proxy's own filter, so a worker under
	 * a configured measurement path is still proxied and never answered here.
	 *
	 * @param bool $do_parse Whether WordPress should parse the request.
	 * @return bool Unchanged when the request is not a stale service worker request.
	 *
	 * @since 1.67.1
	 */
	public static function maybe_answer_stale_service_worker_request( $do_parse ) {

		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Raw URI needed for path matching, sanitized before storage
		$request_uri = isset( $_SERVER['REQUEST_URI'] ) ? $_SERVER['REQUEST_URI'] : '';

		if (!self::is_stale_service_worker_request($request_uri)) {
			return $do_parse;
		}

		self::record_unhandled_request($request_uri);
		self::send_gone_response();

		return $do_parse; // Not reached, send_gone_response() ends the request
	}

	/**
	 * Check whether a request asks for a gateway service worker that no handler serves
	 *
	 * @param string $request_uri The raw request URI.
	 * @return bool True for a service worker request outside the configured measurement path.
	 *
	 * @since 1.67.1
	 */
	public static function is_stale_service_worker_request( $request_uri ) {

		if (empty($request_uri) || false === strpos($request_uri, '/_/service_worker/')) {
			return false;
		}

		// Under the configured measurement path the proxy serves the worker
		$measurement_path = Options::get_google_tag_gateway_measurement_path();

		return !( $measurement_path && GTG_Proxy::is_measurement_path_request($request_uri, $measurement_path) );
	}

	/**
	 * End the request with an empty 410 Gone
	 *
	 * @return void
	 *
	 * @since 1.67.1
	 */
	private static function send_gone_response() {
		status_header(410);
		nocache_headers();
		header('Content-Type: text/plain; charset=utf-8');
		header('X-PMW-GTG: stale-service-worker');
		exit;
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

		self::record_unhandled_request($request_uri);
	}

	/**
	 * Count a gateway request that reached WordPress without a handler
	 *
	 * Daily bucket, capped writes, one log warning when the threshold is crossed.
	 *
	 * @param string $request_uri The raw request URI, sanitized before storage.
	 * @return void
	 *
	 * @since 1.67.1
	 */
	public static function record_unhandled_request( $request_uri ) {

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
					'[GTG-Monitor] %1$d Google Tag Gateway requests reached WordPress unhandled today. Last URI: %2$s',
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
			return 'Gateway requests that reached WordPress: none recorded' . PHP_EOL;
		}

		$is_current = gmdate('Y-m-d') === $stats['date'];
		$warning    = ( $is_current && $stats['count'] >= self::WARNING_THRESHOLD ) ? '❗ ' : '';

		$html  = $warning . 'Gateway requests that reached WordPress: ' . $stats['count'] . ' on ' . $stats['date'];
		$html .= $stats['count'] >= self::DAILY_WRITE_CAP ? ' (counting stopped at the daily cap)' : '';
		$html .= PHP_EOL;

		if (!empty($stats['last_uri'])) {
			$html .= 'Last such URI:                           ' . $stats['last_uri'] . PHP_EOL;
		}

		return $html;
	}
}
