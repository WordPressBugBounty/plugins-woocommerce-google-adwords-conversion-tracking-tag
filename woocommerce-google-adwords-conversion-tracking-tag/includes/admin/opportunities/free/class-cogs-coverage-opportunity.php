<?php

namespace SweetCode\Pixel_Manager\Admin\Opportunities\Free;

use SweetCode\Pixel_Manager\Admin\Cogs_Coverage_Scan;
use SweetCode\Pixel_Manager\Admin\Documentation;
use SweetCode\Pixel_Manager\Admin\Opportunities\Opportunity;

defined('ABSPATH') || exit; // Exit if accessed directly

/**
 * Opportunity: products without a cost of goods under the Profit margin logic
 *
 * With the Marketing value logic set to Profit margin, the conversion value the
 * ad platforms receive is the margin of the order. A product the plugin knows no
 * cost for is counted with a cost of 0, so its entire revenue is reported as
 * profit. Nothing in the plugin said so, and the reported value is a plain
 * number that cannot express it, so shops only noticed when their profit figures
 * looked implausible. Help Scout 3435195259 was the third ticket on it.
 *
 * The card names the share of the recently scanned orders that are affected, so
 * the shop can tell a handful of stragglers apart from a cost source that never
 * covered the catalog at all.
 *
 * This lives in free/ rather than pro/ although the Profit margin logic itself is
 * a Pro setting: the fix is entering product costs, which needs no license, and
 * an install whose license lapsed keeps the stored setting and keeps reporting
 * the overstated margin, so the card has to reach it.
 *
 * @since 1.67.1
 */
class Cogs_Coverage extends Opportunity {

	/**
	 * Available while the profit margin logic is active and the scan found at
	 * least one recent order with a product line that resolves to no cost.
	 *
	 * The gate on the marketing value logic sits inside the scan, which returns
	 * nothing under every other logic, because the cost of goods never reaches
	 * the reported value there.
	 *
	 * @return bool
	 * @since 1.67.1
	 */
	public static function available() {
		return Cogs_Coverage_Scan::has_findings(Cogs_Coverage_Scan::get_scan_results());
	}

	/**
	 * Get the card data for this opportunity.
	 *
	 * @return array
	 * @since 1.67.1
	 */
	public static function card_data() {

		$results = Cogs_Coverage_Scan::get_scan_results();

		$orders_incomplete = is_array($results) ? (int) $results['orders_incomplete'] : 0;
		$orders_scanned    = is_array($results) ? (int) $results['orders_scanned'] : 0;

		$descriptions = [
			sprintf(
				/* translators: 1: number of affected orders, 2: number of scanned orders, 3: share of affected orders in percent */
				esc_html__(
					'Your Marketing value logic is set to Profit margin, and %1$d of the %2$d most recent orders (%3$d%%) contain a product the Pixel Manager knows no cost for.',
					'woocommerce-google-adwords-conversion-tracking-tag'
				),
				$orders_incomplete,
				$orders_scanned,
				Cogs_Coverage_Scan::get_incomplete_share($results)
			),
			esc_html__(
				'A product without a cost is counted with a cost of zero, so its entire revenue is reported to your ad platforms as profit. Those conversion values are too high, which skews smart bidding and every profit figure you read out of your ad accounts.',
				'woocommerce-google-adwords-conversion-tracking-tag'
			),
			esc_html__(
				'Enter the missing costs in your cost of goods source, or set General → Order configuration → Marketing value logic back to Order subtotal until the costs are complete.',
				'woocommerce-google-adwords-conversion-tracking-tag'
			),
			esc_html__(
				'One case is not a configuration error: WooCommerce\'s built-in Cost of Goods Sold deletes a cost of 0 instead of storing it, so a genuinely free item looks exactly like an item nobody entered a cost for and is counted here. Give free and promotional items a token cost, 0.01 for instance, and they stop counting as missing.',
				'woocommerce-google-adwords-conversion-tracking-tag'
			),
			sprintf(
				/* translators: 1: opening anchor tag, 2: closing anchor tag */
				esc_html__(
					'%1$sSee which cost sources the Pixel Manager reads, and in which order%2$s.',
					'woocommerce-google-adwords-conversion-tracking-tag'
				),
				'<a href="' . esc_url(Documentation::get_link('cogs_resolution_order')) . '" target="_blank">',
				'</a>'
			),
		];

		return [
			'id'              => 'cogs-coverage',
			'title'           => esc_html__(
				'Products without a cost of goods',
				'woocommerce-google-adwords-conversion-tracking-tag'
			),
			'description'     => $descriptions,
			'impact'          => 'high',
			'learn_more_link' => Documentation::get_link('cogs_resolution_order'),
			'since'           => 1788912000, // September 9, 2026 timestamp
			'repeat_interval' => MONTH_IN_SECONDS, // Re-show after a month while costs are still missing
		];
	}
}
