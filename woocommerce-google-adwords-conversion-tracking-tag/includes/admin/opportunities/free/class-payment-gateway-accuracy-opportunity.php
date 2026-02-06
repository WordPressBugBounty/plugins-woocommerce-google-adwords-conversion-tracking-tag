<?php

namespace SweetCode\Pixel_Manager\Admin\Opportunities\Free;

use SweetCode\Pixel_Manager\Admin\Debug_Info;
use SweetCode\Pixel_Manager\Admin\Documentation;
use SweetCode\Pixel_Manager\Admin\Environment;
use SweetCode\Pixel_Manager\Admin\Opportunities\Opportunity;

defined('ABSPATH') || exit; // Exit if accessed directly

/**
 * Opportunity: Payment Gateway Accuracy
 *
 * Dynamic opportunity that checks each active payment gateway's tracking accuracy
 * and shows an alert when any gateway falls below the threshold.
 *
 * @since 1.45.0
 */
class Payment_Gateway_Accuracy extends Opportunity {

	/**
	 * Minimum percentage threshold for tracking accuracy.
	 * Gateways below this value will trigger an opportunity.
	 */
	const ACCURACY_THRESHOLD = 90;

	/**
	 * Minimum number of orders required before checking accuracy.
	 * This prevents false positives on low-volume gateways.
	 */
	const MINIMUM_ORDER_COUNT = 10;

	/**
	 * Get the gateways that are below the accuracy threshold.
	 *
	 * @return array Array of gateways below threshold with their data.
	 */
	private static function get_gateways_below_threshold() {

		// Check if transients are enabled
		if (!Environment::is_transients_enabled()) {
			return [];
		}

		// Use the non-weighted array which includes ALL gateways (active and inactive)
		// that have historical orders, not just currently enabled gateways
		$gateway_analysis = Debug_Info::get_gateway_analysis_array();

		// If no analysis data is available yet, return empty
		if (false === $gateway_analysis) {
			return [];
		}

		$gateways_below_threshold = [];

		foreach ($gateway_analysis as $gateway) {
			// Only check gateways with more than minimum order count
			if ($gateway['order_count_total'] <= self::MINIMUM_ORDER_COUNT) {
				continue;
			}

			// Check if gateway is below threshold
			if ($gateway['percentage'] < self::ACCURACY_THRESHOLD) {
				$gateways_below_threshold[] = $gateway;
			}
		}

		return $gateways_below_threshold;
	}

	/**
	 * Check if the opportunity is available.
	 * Available if at least one gateway is below the accuracy threshold.
	 *
	 * @return bool
	 */
	public static function available() {

		$gateways_below_threshold = self::get_gateways_below_threshold();

		return !empty($gateways_below_threshold);
	}

	/**
	 * Get the card data for this opportunity.
	 *
	 * @return array
	 */
	public static function card_data() {

		$gateways_below_threshold = self::get_gateways_below_threshold();

		$descriptions = [
			esc_html__(
				'The Pixel Manager detected that one or more of your payment gateways have a tracking accuracy below the recommended 90% threshold.',
				'woocommerce-google-adwords-conversion-tracking-tag'
			),
			esc_html__(
				'Low tracking accuracy means that not all customers are reaching the purchase confirmation page after completing their order, which results in missed conversion tracking.',
				'woocommerce-google-adwords-conversion-tracking-tag'
			),
			esc_html__(
				'This can significantly impact your paid advertising performance and ROAS calculations.',
				'woocommerce-google-adwords-conversion-tracking-tag'
			),
		];

		// Build the gateway list for the description
		if (!empty($gateways_below_threshold)) {
			$gateway_details = [];
			foreach ($gateways_below_threshold as $gateway) {
				$gateway_details[] = sprintf(
					'%s: %d%% (%d/%d orders)',
					$gateway['gateway_id'],
					$gateway['percentage'],
					$gateway['order_count_measured'],
					$gateway['order_count_total']
				);
			}
			$descriptions[] = esc_html__('Affected payment gateways:', 'woocommerce-google-adwords-conversion-tracking-tag') . ' ' . implode(', ', $gateway_details);
		}

		$descriptions[] = sprintf(
			/* translators: 1: opening anchor tag, 2: closing anchor tag */
			esc_html__(
				'Check the %1$sDiagnostics tab%2$s for more details and follow our documentation to improve tracking accuracy.',
				'woocommerce-google-adwords-conversion-tracking-tag'
			),
			'<a href="#" class="advanced-section-link" data-as-section="diagnostics">',
			'</a>'
		);

		$descriptions[] = sprintf(
			/* translators: 1: opening anchor tag, 2: closing anchor tag */
			esc_html__(
				'%1$sLearn more about payment gateway tracking accuracy%2$s and how to fix it.',
				'woocommerce-google-adwords-conversion-tracking-tag'
			),
			'<a href="' . esc_url(Documentation::get_link('payment_gateway_tracking_accuracy')) . '" target="_blank">',
			'</a>'
		);

		return [
			'id'             => 'payment-gateway-accuracy',
			'title'          => esc_html__(
				'Payment Gateway Tracking Accuracy Issue',
				'woocommerce-google-adwords-conversion-tracking-tag'
			),
			'description'    => $descriptions,
			'impact'         => esc_html__(
				'high',
				'woocommerce-google-adwords-conversion-tracking-tag'
			),
			'custom_buttons' => [
				[
					'label'           => esc_html__('Diagnostics', 'woocommerce-google-adwords-conversion-tracking-tag'),
					'class'           => 'advanced-section-link',
					'url'             => '#',
					'data_attributes' => [
						'as-section' => 'diagnostics',
					],
				],
			],
			'learn_more_link'  => Documentation::get_link('payment_gateway_tracking_accuracy'),
			'since'            => 1733443200, // December 6, 2024 timestamp
			'repeat_interval'  => MONTH_IN_SECONDS, // Re-show after 1 month if still applicable
		];
	}
}
