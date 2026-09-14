<?php

namespace SweetCode\Pixel_Manager\Admin;

use SweetCode\Pixel_Manager\Helpers;
use SweetCode\Pixel_Manager\Options;
use SweetCode\Pixel_Manager\Profit_Margin;
use SweetCode\Pixel_Manager\Shop;

defined('ABSPATH') || exit; // Exit if accessed directly

/**
 * Scans the newest orders for product lines that resolve to no cost of goods.
 *
 * Under the Profit margin marketing value logic the conversion value the ad
 * platforms receive is the margin of the order. A product without a cost is
 * counted with a cost of 0 there, which reports that product's entire revenue
 * as profit. The calculation returns a plain number, so nothing about the
 * reported value says that half of it was never a margin at all, and the shop
 * only finds out when the profit figures look implausible. Help Scout 3435195259
 * was the third ticket that came down to this.
 *
 * The scan answers the one question the shop cannot answer from the value:
 * how many of its recent orders carry a product the plugin knows no cost for.
 * It reads the newest orders once a day at most, caches counts only, and drives
 * the Cogs_Coverage opportunity card.
 *
 * Costs are resolved through Profit_Margin::order_has_complete_cogs(), so the
 * scan measures exactly what the calculation does, including the sources and
 * meta keys it reads and the ones it ignores while their plugin is inactive.
 *
 * @since 1.67.1
 */
class Cogs_Coverage_Scan {

	/**
	 * Cached scan result. The version suffix retires older payload shapes.
	 */
	const TRANSIENT_KEY = 'pmw_cogs_coverage_scan_v1';

	/**
	 * How many of the newest orders one scan reads.
	 *
	 * Each order is loaded in full and every line item resolves its product, so
	 * this is the expensive part of the check and the reason the result is
	 * cached. A hundred orders is enough for a share that means something and
	 * short enough to stay well inside a settings page request.
	 */
	const ORDER_LIMIT = 100;

	/**
	 * How long a scan result stays valid.
	 *
	 * A shop that fills in the missing costs wants the card gone, so the result
	 * cannot be cached for a week the way a remote scan can. A day is short
	 * enough for that and long enough to keep the order read off almost every
	 * settings page load.
	 */
	const CACHE_DURATION = DAY_IN_SECONDS;

	/**
	 * Get the scan result, from the cache whenever there is one.
	 *
	 * Returns false rather than an empty report whenever the check does not
	 * apply or cannot run: the profit margin logic is not in use, the scan is
	 * filtered off, WooCommerce is not there to read the orders, or nothing is
	 * cached and this request is not allowed to run a fresh scan.
	 *
	 * A fresh scan only runs while an admin is on the Pixel Manager settings
	 * page, or when $force_fetch is set. Opportunity availability is evaluated
	 * on every admin page load for the menu badge, and reading a hundred orders
	 * there would be paid for by every admin page view of the shop.
	 *
	 * @param bool $force_fetch Run the scan even outside the settings page when nothing is cached.
	 * @return array|false {
	 *     scanned_at:        int Unix timestamp of the scan.
	 *     orders_scanned:    int How many orders the scan read.
	 *     orders_incomplete: int How many of them carry a product line without a cost.
	 * } or false when no result is available.
	 * @since 1.67.1
	 */
	public static function get_scan_results( $force_fetch = false ) {

		// Harmless under every other marketing value logic, because the cost of
		// goods never reaches the reported value there.
		if (!self::is_profit_margin_logic_active()) {
			return false;
		}

		/**
		 * Allows disabling the scan for orders whose products have no cost of goods.
		 *
		 * @param bool $enabled Default true.
		 *
		 * @since 1.67.1
		 */
		if (!apply_filters('pmw_cogs_coverage_scan_enabled', true)) {
			return false;
		}

		$cached = Environment::is_transients_enabled() ? get_transient(self::TRANSIENT_KEY) : false;

		if (self::is_result($cached)) {
			return $cached;
		}

		if (!$force_fetch) {

			// Refresh the cache lazily while an admin is looking at the Pixel
			// Manager settings page. Everywhere else (admin menu badge, WordPress
			// dashboard) the check works with the cache only.
			if (!Environment::is_pmw_settings_page()) {
				return false;
			}

			// Without transients there is nothing to cache into, so every
			// settings page load would read the orders again.
			if (!Environment::is_transients_enabled()) {
				return false;
			}
		}

		// Only the fresh scan needs WooCommerce. The check above the cache read
		// deliberately does not, so a cached result stays readable.
		if (!function_exists('wc_get_orders')) {
			return false;
		}

		return self::scan();
	}

	/**
	 * Whether the marketing value logic reports the profit margin.
	 *
	 * Same pair of stored values Shop::get_order_value_total_marketing() reads:
	 * the numeric string the radio field saves, and the named value.
	 *
	 * @return bool
	 * @since 1.67.1
	 */
	public static function is_profit_margin_logic_active() {
		return in_array(Options::get_options_obj()->shop->order_total_logic, [ '2', 'order_profit_margin' ], true);
	}

	/**
	 * Whether the scan found orders whose reported margin is overstated.
	 *
	 * @param array|false $results The result of get_scan_results().
	 * @return bool
	 * @since 1.67.1
	 */
	public static function has_findings( $results ) {

		if (!self::is_result($results)) {
			return false;
		}

		return $results['orders_incomplete'] > 0;
	}

	/**
	 * The share of scanned orders that carry a product line without a cost, in percent.
	 *
	 * @param array|false $results The result of get_scan_results().
	 * @return int 0 when there is no result to read.
	 * @since 1.67.1
	 */
	public static function get_incomplete_share( $results ) {

		if (!self::is_result($results)) {
			return 0;
		}

		return (int) Helpers::get_percentage($results['orders_incomplete'], $results['orders_scanned']);
	}

	/**
	 * Count the orders that resolve a cost for every product line, and the ones that don't.
	 *
	 * Split from the order read so the counting is testable without WooCommerce.
	 *
	 * @param array $orders The orders to count.
	 * @return array See get_scan_results() for the shape.
	 * @since 1.67.1
	 */
	public static function build_report( $orders ) {

		$orders = is_array($orders) ? $orders : [];

		$incomplete = 0;

		foreach ($orders as $order) {
			if (!Profit_Margin::order_has_complete_cogs($order)) {
				++$incomplete;
			}
		}

		return [
			'scanned_at'        => time(),
			'orders_scanned'    => count($orders),
			'orders_incomplete' => $incomplete,
		];
	}

	/**
	 * Read the newest orders, count them and cache the counts.
	 *
	 * @return array
	 * @since 1.67.1
	 */
	private static function scan() {

		$report = self::build_report(self::get_recent_orders());

		if (Environment::is_transients_enabled()) {
			set_transient(self::TRANSIENT_KEY, $report, self::CACHE_DURATION);
		}

		return $report;
	}

	/**
	 * The newest orders that count as a sale.
	 *
	 * Orders in a status the shop does not count as active are left out, because
	 * a cancelled or failed order never reported a conversion value whose margin
	 * could be wrong.
	 *
	 * @return array
	 * @since 1.67.1
	 */
	private static function get_recent_orders() {

		$orders = wc_get_orders([
			'limit'   => self::ORDER_LIMIT,
			'type'    => 'shop_order',
			'orderby' => 'date',
			'order'   => 'DESC',
			'status'  => Shop::get_active_order_statuses_for_db_queries(),
		]);

		return is_array($orders) ? $orders : [];
	}

	/**
	 * Whether a value is a scan result rather than a missing or stale cache entry.
	 *
	 * @param mixed $results
	 * @return bool
	 * @since 1.67.1
	 */
	private static function is_result( $results ) {
		return is_array($results)
			&& isset($results['orders_scanned'])
			&& isset($results['orders_incomplete']);
	}

	/**
	 * Drop the cached scan result.
	 *
	 * @return void
	 * @since 1.67.1
	 */
	public static function flush_cache() {
		delete_transient(self::TRANSIENT_KEY);
	}
}
