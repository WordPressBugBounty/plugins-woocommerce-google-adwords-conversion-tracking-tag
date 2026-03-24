<?php

namespace SweetCode\Pixel_Manager\Admin;

use ActionScheduler;
use WC_Order;
use SweetCode\Pixel_Manager\Helpers;
use SweetCode\Pixel_Manager\Logger;
use SweetCode\Pixel_Manager\Options;
use SweetCode\Pixel_Manager\Shop;

defined('ABSPATH') || exit; // Exit if accessed directly

class LTV {

	private static $max_failed_as_attempts      = 3;
	private static $as_group_name               = 'pmw_ltv_calculation';
	private static $max_orders_per_chain_action = 50;

	private static function pmw_order_value_meta_key() {
		return '_pmw_order_value';
	}

	private static function default_pmw_order_values() {
		return [
			'marketing_ltv'         => 0,
			'marketing_order_value' => 0,
			'total_order_value'     => 0,
			'total_ltv'             => 0,
		];
	}

	public static function get_marketing_ltv_from_order( $order ) {
		return self::get_pmw_order_value_by_key($order, 'marketing_ltv');
	}

	public static function get_marketing_order_value_from_order( $order ) {
		return self::get_pmw_order_value_by_key($order, 'marketing_order_value');
	}

	public static function get_total_order_value_from_order( $order ) {
		return self::get_pmw_order_value_by_key($order, 'total_order_value');
	}

	public static function get_total_ltv_from_order( $order ) {
		return self::get_pmw_order_value_by_key($order, 'total_ltv');
	}

	private static function get_pmw_order_value_by_key( $order, $key ) {

		// Get order meta for the PMW order value
		$order_values = $order->get_meta(self::pmw_order_value_meta_key());

		if (isset($order_values[$key])) {
			return (float) Helpers::format_decimal($order_values[$key]);
		}

		return null;
	}

	/**
	 * Retrieves the PMW order values from the order.
	 *
	 * A static method of the LTV class that fetches the PMW order values from a given WooCommerce order.
	 * It performs a check if the order exists and gets the order meta for the PMW order value.
	 * Returns null if the order does not exist or if the PMW order value meta key is not set for the order.
	 *
	 * @param int $order_id The ID of the order to get the PMW values from.
	 * @return mixed The PMW order value if it exists, null otherwise.
	 *
	 * @since 1.35.1
	 */
	public static function get_pmw_order_values( $order_id ) {

		$order = wc_get_order($order_id);

		if ($order) {

			// Get order meta for the PMW order value
			$order_value = $order->get_meta(self::pmw_order_value_meta_key());

			if (isset($order_value)) {
				return $order_value;
			}

			return self::default_pmw_order_values();
		}

		return null;
	}

	/**
	 * Sets the PMW order values on an order.
	 *
	 * It updates the order metadata with the provided values. The $order_values array should contain 'marketing_ltv' and 'marketing_order_value' keys.
	 * - If these keys are missing, this function will log an error message and return prematurely.
	 * - If the order with $order_id doesn't exist, this function does nothing.
	 *
	 * @param WC_Order $order        The order object to set the values on.
	 * @param array    $order_values The array of values to set on the order. Must contain 'marketing_ltv' and 'marketing_order_value' keys.
	 *
	 * @return bool True if the order was updated, false otherwise.
	 *
	 * @since 1.35.1
	 */
	public static function set_pmw_order_values_on_order( $order, $order_values ) {

		// $order_values must contain "ltv" and "value_marketing" keys
		if (
			!isset($order_values['marketing_ltv'])
			|| !isset($order_values['marketing_order_value'])
		) {
			Logger::warning('LTV::set_pmw_order_values_on_order() - $order_values must contain "ltv" and "value_marketing" keys');
			return false;
		}

		// Get order meta for the PMW order value
		$order->update_meta_data(self::pmw_order_value_meta_key(), $order_values);
		$id = $order->save();

		if ($id === $order->get_id()) {
			return true;
		}

		return false;
	}

	/**
	 * Fetches the ID of the most recent previous order with the same email address as the provided order.
	 *
	 * @param WC_Order $order The order object to use as the basis for fetching the previous order.
	 *
	 * @return int|null The ID of the most recent order placed by the same email address as the provided order, or null if no such orders exist.
	 *
	 * @since 1.35.1
	 */
	public static function get_previous_order_with_same_email_address( $order ) {

		// Get customer email address
		$email_address = $order->get_billing_email();

		// Return null if no email address is set
		if (empty($email_address)) {
			return null;
		}

		$orders = wc_get_orders([
			'type'          => 'shop_order',
			'billing_email' => $email_address,
			'limit'         => 1,
			'orderby'       => 'date',
			'order'         => 'DESC',
			'status'        => Shop::get_active_order_statuses_for_db_queries(),
			'date_created'  => '<' . $order->get_date_created()->getTimestamp(),
			'return'        => 'ids',
		]);

		if (count($orders) > 0) {
			return $orders[0];
		}

		return null;
	}

	/**
	 * Retrieves the next order ID with the same email address as the given order.
	 *
	 * @param WC_Order $order The order to compare email addresses with.
	 *
	 * @return  int|null The ID of the next order with the same email address, or null if there are no further orders with the same email address.
	 *
	 * @since 1.35.1
	 */
	public static function get_next_order_with_same_email_address( $order ) {

		// Get customer email address
		$email_address = $order->get_billing_email();

		// Return null if no email address is set
		if (empty($email_address)) {
			return null;
		}

		$orders = wc_get_orders([
			'type'          => 'shop_order',
			'billing_email' => $email_address,
			'limit'         => 1,
			'orderby'       => 'date',
			'order'         => 'ASC',
			'status'        => Shop::get_active_order_statuses_for_db_queries(),
			'date_created'  => '>' . $order->get_date_created()->getTimestamp(),
			'return'        => 'ids',
		]);

		if (count($orders) > 0) {
			return $orders[0];
		}

		return null;
	}

	/**
	 * This method fetches the earliest order in the database that has a status defined in get_active_order_statuses_for_db_queries()
	 * If no such order is found, the method returns null.
	 *
	 * @return int|null The ID of the first paid order in the database or null if none found.
	 *
	 * @uses  get_active_order_statuses_for_db_queries() to get the list of active order statuses for the wc_get_orders function.
	 *
	 * @method static int|null get_the_first_order_in_the_db()
	 *
	 * @since 1.35.1
	 */
	public static function get_the_first_order_in_the_db() {

		$orders = wc_get_orders([
			'type'    => 'shop_order',
			'limit'   => 1,
			'orderby' => 'date',
			'order'   => 'ASC',
			'status'  => Shop::get_active_order_statuses_for_db_queries(),
			'return'  => 'ids',
		]);

		if (count($orders) > 0) {
			return $orders[0];
		}

		return null;
	}

	/**
	 * Checks if conditions are met for a horizontal LTV calculation process.
	 *
	 * The function checks if the following are true:
	 *  1. The order exists,
	 *  2. The order's billing email is not a test email,
	 *  3. There is no previous order with the same email address,
	 *  4. No actions for this order are already scheduled.
	 * If any of these conditions are met, the function logs a message and stops. Otherwise, it enqueues an asynchronous action (LTV calculation).
	 *
	 * @param int $order_id The ID of the order to check.
	 * @return void
	 * @uses  wc_get_order(), apply_filters(), in_array(), \SweetCode\Pixel_Manager\Admin\LTV::get_previous_order_with_same_email_address(), Logger::warning(), Logger::info(), Helpers::pmw_as_has_scheduled_action(), as_enqueue_async_action(),
	 *
	 * @since 1.35.1
	 */
	public static function horizontal_ltv_calculation_check( $order_id ) {

		$order = wc_get_order($order_id);

		// Do nothing if the order does not exist
		if (!$order) {
			Logger::warning('LTV::horizontal_ltv_calculation_check() - order with ID ' . $order_id . ' does not exist');
			return;
		}

		/**
		 * Stop if the billing email address is one of the email address returned by the get_test_email_addresses filter.
		 *
		 * @since 1.58.5
		 */
		$test_email_addresses = apply_filters('pmw_get_test_email_addresses', []);
		if (in_array($order->get_billing_email(), $test_email_addresses)) {
			Logger::info('LTV::horizontal_ltv_calculation_check() - order with ID ' . $order_id . ' has a test email address. Stopping.');
			return;
		}

		// Stop if there is a previous order with the same email address,
		if (self::get_previous_order_with_same_email_address($order)) {
			return;
		}

		// Stop if there is already an action scheduled for the same order ID
		if (
			Helpers::pmw_as_has_scheduled_action(
				'pmw_horizontal_ltv_calculation',
				[ 'order_id' => $order_id ],
				self::$as_group_name,
				true
			)
		) {
			Logger::info('LTV::horizontal_ltv_calculation_check() - action already scheduled for order ID: ' . $order_id);
			return;
		}

		as_enqueue_async_action(
			'pmw_horizontal_ltv_calculation',
			[
				'order_id'      => $order_id,
				'billing_email' => $order->get_billing_email(),
			],
			self::$as_group_name
		);
	}

	/**
	 * Processes a horizontal LTV chain for a customer, starting from the given order.
	 *
	 * Processes up to self::$max_orders_per_chain_action orders in a single call to reduce
	 * Action Scheduler overhead. If more orders remain, queues continuation as a new async action.
	 *
	 * Includes early termination: if the next order in the chain already has LTV data that is
	 * consistent with the just-calculated values, the rest of the chain is assumed up-to-date
	 * and processing stops. This makes repeated recalculations nearly instant.
	 *
	 * @param int $order_id The ID of the order to start processing from.
	 * @return void
	 *
	 * @since 1.58.5
	 */
	public static function horizontal_ltv_calculation( $order_id ) {

		if (Environment::cannot_run_action_scheduler()) {
			Logger::debug('LTV::horizontal_ltv_calculation() - cannot run action scheduler');
			return;
		}

		// Stop if the order level LTV calculation is not active
		if (!Options::is_order_level_ltv_calculation_active()) {
			return;
		}

		$current_order = wc_get_order($order_id);

		// Do nothing if the order does not exist
		if (!$current_order) {
			Logger::warning('LTV::horizontal_ltv_calculation() - order with ID ' . $order_id . ' does not exist');
			return;
		}

		$processed = 0;

		while ($current_order) {

			$last_calculated_values = self::calculate_pmw_order_values($current_order);
			++$processed;

			$next_order_id = self::get_next_order_with_same_email_address($current_order);

			// End of customer chain
			if (!$next_order_id) {
				return;
			}

			// If we've reached the per-action limit, queue continuation for remaining orders
			if ($processed >= self::$max_orders_per_chain_action) {

				if (
					!Helpers::pmw_as_has_scheduled_action(
						'pmw_horizontal_ltv_calculation',
						[ 'order_id' => $next_order_id ],
						self::$as_group_name,
						true
					)
				) {
					as_enqueue_async_action(
						'pmw_horizontal_ltv_calculation',
						[ 'order_id' => $next_order_id ],
						self::$as_group_name
					);
				}

				return;
			}

			$next_order = wc_get_order($next_order_id);

			if (!$next_order) {
				return;
			}

			// Early termination: if next order's LTV is already consistent with what we just
			// calculated, the rest of the chain is up to date — no need to continue.
			if (self::is_order_ltv_consistent_with_previous($next_order, $last_calculated_values)) {
				return;
			}

			$current_order = $next_order;
		}
	}

	/**
	 * Calculate the order values for PMW and sets them on the given order.
	 *
	 * This function calculates the marketing order value and the total order value. These values
	 * are directly pulled from the order object. It also checks for the existence of a previous order
	 * with the same email address, and if that order exists and all its PMW values are set, it calculates
	 * the LTV (Customer Lifetime Value) as well. If the prior conditions aren't met, it sets the marketing
	 * LTV to the marketing order value and the total LTV to the total order value.
	 *
	 * After the calculation, it sets the calculated PMW values on the given order.
	 *
	 * @param WC_Order $order The order to calculate the order values for.
	 *
	 * @return array The calculated order values.
	 *
	 * @since 1.35.1
	 */
	public static function calculate_pmw_order_values( $order ) {

		$order_values = self::default_pmw_order_values();

		// Calculate the marketing value of the order
		$order_values['marketing_order_value'] = (float) Shop::get_order_value_total_marketing($order);
		$order_values['total_order_value']     = (float) $order->get_total();

		// LTV is the LTV of the previous order + the marketing value of the current order
		$previous_order_id = self::get_previous_order_with_same_email_address($order);
		$previous_order    = wc_get_order($previous_order_id);

		// If there is a previous order
		// and not all PMW order values are set,
		// it means the LTV calculation on that old order came from a previous version of the plugin.
		// Therefore, we need to schedule a complete vertical LTV calculation.
//      if (
//          $previous_order
//          && !self::are_all_pmw_order_values_set($previous_order)
//          && Options::is_automatic_ltv_recalculation_active()
//      ) {
//          self::schedule_complete_vertical_ltv_calculation();
//      }

		// If there is a previous order and all PMW order values are set, calculate the LTV
		if ($previous_order && self::are_all_pmw_order_values_set($previous_order)) {
			$order_values['marketing_ltv'] = self::get_marketing_ltv_from_order($previous_order) + $order_values['marketing_order_value'];
			$order_values['total_ltv']     = self::get_total_ltv_from_order($previous_order) + $order_values['total_order_value'];

			// Check if the marketing order value calculation changed
			// If yes, schedule a complete vertical LTV calculation
//          self::vertical_recalculation_if_the_marketing_order_value_calculation_changed($previous_order);
		} else {
			$order_values['marketing_ltv'] = $order_values['marketing_order_value'];
			$order_values['total_ltv']     = $order_values['total_order_value'];
		}

		self::set_pmw_order_values_on_order($order, $order_values);

		return $order_values;
	}

	/**
	 * Checks if the marketing order value calculation has changed and schedules a complete
	 * vertical LTV (Lifetime Value) calculation if necessary.
	 *
	 * This function retrieves the old marketing order value, calculates the new marketing
	 * order value and then compares them. If the old value is `null`, it means the marketing
	 * order value was never calculated for that order, so a complete vertical LTV calculation
	 * is scheduled. If the values are different, it logs the difference and schedules
	 * a complete vertical LTV calculation as well.
	 *
	 * @param WC_Order $order The order object.
	 *
	 * @return void
	 *
	 * @since 1.35.1
	 */
	private static function vertical_recalculation_if_the_marketing_order_value_calculation_changed( $order ) {

		if (Environment::cannot_run_action_scheduler()) {
			Logger::debug('LTV::vertical_recalculation_if_the_marketing_order_value_calculation_changed() - cannot run action scheduler');
			return;
		}

		// Return if the automatic LTV recalculation is not active
		if (!Options::is_automatic_ltv_recalculation_active()) {
			return;
		}

		// If the order has been partially refunded,
		// abort the recalculation.
		// We do recalculate the LTV for partially refunded orders already.
		// But that doesn't happen immediately.
		// So there is a chance that the marketing values are not correct when the current check runs.
		// Therefore, we abort the recalculation.
		if (Shop::has_order_been_partially_refunded($order)) {
			return;
		}

		$marketing_order_value_old = self::get_marketing_order_value_from_order($order);
		$marketing_order_value_new = Shop::get_order_value_total_marketing($order);

		// Stop if it is null
		// It means the marketing order value was never calculated on that order
		if (null === $marketing_order_value_old) {
			Logger::info('LTV::has_the_marketing_order_value_calculation_changed() - marketing_order_value_old is null. scheduling a complete vertical recalculation');
			self::schedule_complete_vertical_ltv_calculation();
			return;
		}

		// If the values are different, schedule a complete vertical LTV calculation
		if ($marketing_order_value_old != $marketing_order_value_new) {
			Logger::info('LTV::has_the_marketing_order_value_calculation_changed() - marketing_order_value_old != $marketing_order_value_new. scheduling a complete vertical recalculation');
			self::schedule_complete_vertical_ltv_calculation();
		}
	}

	/**
	 * Check if all PMW order values are set.
	 *
	 * This method is used to verify if all default PMW order values are set for a given order.
	 * It abstracts the check to allow addition of new keys to `default_pmw_order_values()`
	 * without needing changes to this method.
	 *
	 * @param $order
	 * @return bool Returns true if all values are set, false otherwise
	 *
	 * @since 1.35.1
	 */
	public static function are_all_pmw_order_values_set( $order ) {

		$order_values = $order->get_meta(self::pmw_order_value_meta_key());

		// If there is a value set for each self::default_pmw_order_values() key, return true
		// Abstract it so that we can add new keys to self::default_pmw_order_values() without having to change this method
		// And check each key individually
		foreach (self::default_pmw_order_values() as $key => $value) {
			if (!isset($order_values[$key])) {
				return false;
			}
		}

		return true;
	}

	/**
	 * Checks if an order's stored LTV data is consistent with the given previous order's calculated values.
	 *
	 * Used for early termination during horizontal chain processing: if the next order's stored LTV data
	 * already matches what we'd calculate based on the previous order, the rest of the chain is up to date
	 * and processing can stop. This makes repeated recalculations nearly instant on already-processed chains.
	 *
	 * @param WC_Order $order                  The order to check.
	 * @param array    $previous_order_values  The just-calculated values of the previous order in the chain.
	 *
	 * @return bool True if stored LTV data is consistent, false otherwise.
	 *
	 * @since 1.58.5
	 */
	private static function is_order_ltv_consistent_with_previous( $order, $previous_order_values ) {

		$stored = $order->get_meta(self::pmw_order_value_meta_key());

		// No stored values means not yet calculated
		if (empty($stored) || !is_array($stored)) {
			return false;
		}

		// Check all required keys exist
		foreach (self::default_pmw_order_values() as $key => $value) {
			if (!isset($stored[$key])) {
				return false;
			}
		}

		// Check if the stored order values still match current calculation
		$current_marketing_value = (float) Shop::get_order_value_total_marketing($order);
		$current_total_value     = (float) $order->get_total();

		if (abs((float) $stored['marketing_order_value'] - $current_marketing_value) > 0.01) {
			return false;
		}

		if (abs((float) $stored['total_order_value'] - $current_total_value) > 0.01) {
			return false;
		}

		// Check LTV consistency with previous order's values
		$expected_marketing_ltv = $previous_order_values['marketing_ltv'] + $current_marketing_value;
		$expected_total_ltv     = $previous_order_values['total_ltv'] + $current_total_value;

		if (abs((float) $stored['marketing_ltv'] - $expected_marketing_ltv) > 0.01) {
			return false;
		}

		if (abs((float) $stored['total_ltv'] - $expected_total_ltv) > 0.01) {
			return false;
		}

		return true;
	}

	/**
	 * Processes a batch of orders for vertical LTV calculation using in-memory state.
	 *
	 * Instead of scheduling individual Action Scheduler actions per order (which created
	 * ~100K AS actions for 50K orders), this method processes orders directly in date-ascending
	 * order, maintaining a per-email running LTV total in memory.
	 *
	 * Key optimizations:
	 * - In-memory email→LTV map eliminates all chain-walking DB queries within a batch
	 * - Only the first encounter of each email per batch needs a previous-order lookup
	 * - Orders with already-correct values skip the expensive $order->save() call
	 * - Time safety valve bails and schedules continuation if approaching execution limits
	 *
	 * For 50K orders: ~250 batch AS actions (vs ~100K individual AS actions before).
	 *
	 * @param int $next_order_id The ID of the order to start processing from.
	 *
	 * @return void
	 *
	 * @since 1.58.5
	 */
	public static function batch_process_vertical_ltv_calculation( $next_order_id ) {

		$batch_size    = 200;
		$max_exec_time = 20; // seconds — safety margin below Action Scheduler's 30s limit
		$start_time    = time();

		$memory_limit_pct = 0.80;
		$memory_limit     = self::get_memory_limit_bytes();

		$order = wc_get_order($next_order_id);

		if (!$order) {
			Logger::warning('LTV::batch_process_vertical_ltv_calculation() - order with ID ' . $next_order_id . ' does not exist');
			return;
		}

		// Fetch batch_size + 1 (sentinel to detect if more orders exist)
		$order_ids = wc_get_orders([
			'type'         => 'shop_order',
			'limit'        => $batch_size + 1,
			'orderby'      => 'date',
			'order'        => 'ASC',
			'status'       => Shop::get_active_order_statuses_for_db_queries(),
			'date_created' => '>=' . $order->get_date_created()->getTimestamp(),
			'return'       => 'ids',
		]);

		sort($order_ids);

		$has_more    = count($order_ids) > $batch_size;
		$sentinel_id = $has_more ? array_pop($order_ids) : null;

		// In-memory LTV state per customer email — eliminates all chain-walking queries
		$email_state = [];
		/**
		 * Filters Get test email addresses.
		 *
		 * @since 1.58.5
		 */
		$test_emails = apply_filters('pmw_get_test_email_addresses', []);
		$order_count = count($order_ids);
		$processed   = 0;
		$skipped     = 0;
		$updated     = 0;

		// Index-based loop so bail points always advance past the current order
		for ($i = 0; $i < $order_count; $i++) {

			$order_id = $order_ids[$i];

			try {

				$current_order = wc_get_order($order_id);
				if (!$current_order) {
					continue;
				}

				$email = $current_order->get_billing_email();

				// Skip orders without valid email or with test emails
				if (empty($email) || !Helpers::is_email($email) || in_array($email, $test_emails, true)) {
					continue;
				}

				$marketing_order_value = (float) Shop::get_order_value_total_marketing($current_order);
				$total_order_value     = (float) $current_order->get_total();

				// Calculate LTV: use in-memory state if available, otherwise look up previous order
				if (isset($email_state[$email])) {
					// In-memory hit — zero DB queries needed
					$marketing_ltv = $email_state[$email]['marketing_ltv'] + $marketing_order_value;
					$total_ltv     = $email_state[$email]['total_ltv'] + $total_order_value;
				} else {
					// First encounter of this email in this batch — one efficient LIMIT 1 query
					$prev_order_id = self::get_previous_order_with_same_email_address($current_order);
					$prev_order    = $prev_order_id ? wc_get_order($prev_order_id) : null;

					if ($prev_order && self::are_all_pmw_order_values_set($prev_order)) {
						$marketing_ltv = self::get_marketing_ltv_from_order($prev_order) + $marketing_order_value;
						$total_ltv     = self::get_total_ltv_from_order($prev_order) + $total_order_value;
					} else {
						$marketing_ltv = $marketing_order_value;
						$total_ltv     = $total_order_value;
					}
				}

				// Update in-memory state for subsequent orders from this email
				$email_state[$email] = [
					'marketing_ltv' => $marketing_ltv,
					'total_ltv'     => $total_ltv,
				];

				// Skip the expensive $order->save() if values are already correct
				$stored = $current_order->get_meta(self::pmw_order_value_meta_key());

				if (
					is_array($stored)
					&& isset($stored['marketing_ltv'], $stored['total_ltv'], $stored['marketing_order_value'], $stored['total_order_value'])
					&& abs((float) $stored['marketing_ltv'] - $marketing_ltv) < 0.01
					&& abs((float) $stored['total_ltv'] - $total_ltv) < 0.01
					&& abs((float) $stored['marketing_order_value'] - $marketing_order_value) < 0.01
					&& abs((float) $stored['total_order_value'] - $total_order_value) < 0.01
				) {
					++$processed;
					++$skipped;
					continue;
				}

				$order_values = [
					'marketing_ltv'         => $marketing_ltv,
					'marketing_order_value' => $marketing_order_value,
					'total_order_value'     => $total_order_value,
					'total_ltv'             => $total_ltv,
				];

				self::set_pmw_order_values_on_order($current_order, $order_values);
				++$processed;
				++$updated;

			} catch ( \Exception $e ) {
				Logger::warning('LTV batch: failed processing order ID ' . $order_id . ': ' . $e->getMessage());
				++$processed;
				continue;
			} catch ( \Error $e ) {
				Logger::warning('LTV batch: fatal error on order ID ' . $order_id . ': ' . $e->getMessage());
				++$processed;
				continue;
			}

			// Resource checks AFTER processing — guarantees the pointer always advances past the current order
			if (time() - $start_time >= $max_exec_time) {
				$next_index    = $i + 1;
				$continue_from = ( $next_index < $order_count ) ? $order_ids[$next_index] : $sentinel_id;

				if ($continue_from) {
					Logger::info('LTV batch: time limit reached after ' . $processed . ' orders (' . $updated . ' updated, ' . $skipped . ' skipped). Continuing from order ID: ' . $continue_from);
					self::schedule_batch_continuation($continue_from);
				}

				return;
			}

			if ($memory_limit > 0 && memory_get_usage(true) > $memory_limit * $memory_limit_pct) {
				$next_index    = $i + 1;
				$continue_from = ( $next_index < $order_count ) ? $order_ids[$next_index] : $sentinel_id;

				if ($continue_from) {
					Logger::info('LTV batch: memory limit approaching (' . round(memory_get_usage(true) / 1048576) . 'MB) after ' . $processed . ' orders. Continuing from order ID: ' . $continue_from);
					self::schedule_batch_continuation($continue_from);
				}

				return;
			}
		}

		Logger::info('LTV batch: completed ' . $processed . ' orders (' . $updated . ' updated, ' . $skipped . ' unchanged) in ' . ( time() - $start_time ) . 's');

		// If more orders exist beyond this batch, schedule next batch
		if ($has_more && $sentinel_id) {
			self::schedule_batch_continuation($sentinel_id);
		}
	}

	/**
	 * Schedules a continuation batch for vertical LTV calculation.
	 *
	 * @param int $order_id The order ID to continue from.
	 *
	 * @return void
	 *
	 * @since 1.58.5
	 */
	private static function schedule_batch_continuation( $order_id ) {

		if (
			Helpers::pmw_as_has_scheduled_action(
				'pmw_batch_process_vertical_ltv_calculation',
				[ 'order_id' => $order_id ],
				self::$as_group_name
			)
		) {
			return;
		}

		as_enqueue_async_action(
			'pmw_batch_process_vertical_ltv_calculation',
			[ 'order_id' => $order_id ],
			self::$as_group_name
		);
	}

	/**
	 * Schedules a complete Vertical LTV calculation.
	 *
	 * The function first retrieves the first order in the database using the get_the_first_order_in_the_db method.
	 * If there is no such order, the method returns. If the action is already scheduled, process stops.
	 * Otherwise, the method schedules the Vertical LTV calculation for a specific time in the local timezone.
	 *
	 * It uses the Action Scheduler library to schedule a single action that will be run once at a specified time in the future.
	 * This single action will call the pmw_batch_process_vertical_ltv_calculation function, with the ID of the first order as argument.
	 *
	 * @return bool Returns true if the action was scheduled, false otherwise.
	 * @see \SweetCode\Pixel_Manager\Admin\LTV.get_the_first_order_in_the_db()
	 * @see \SweetCode\Pixel_Manager\Admin\LTV.batch_process_vertical_ltv_calculation()
	 */
	public static function schedule_complete_vertical_ltv_calculation() {

		if (Environment::cannot_run_action_scheduler()) {
			Logger::debug('LTV::schedule_complete_vertical_ltv_calculation() - cannot run action scheduler');
			return false;
		}

		$first_order_id = self::get_the_first_order_in_the_db();

		if (!$first_order_id) {
			return false;
		}

		// Stop if the action is already scheduled
		if (
			Helpers::pmw_as_has_scheduled_action(
				'pmw_batch_process_vertical_ltv_calculation',
				[ 'order_id' => $first_order_id ],
				self::$as_group_name
			)
		) {
			return false;
		}

		// Schedule the calculation for 2:00 AM tomorrow in the local timezone
		as_schedule_single_action(
			Helpers::datetime_string_to_unix_timestamp_in_local_timezone('tomorrow 2:00am'),
			'pmw_batch_process_vertical_ltv_calculation',
			[ 'order_id' => $first_order_id ],
			self::$as_group_name,
			true
		);

		Logger::info('LTV::schedule_complete_vertical_ltv_calculation() - scheduled for tomorrow 2:00 AM, starting from order ID: ' . $first_order_id);

		return true;
	}

	/**
	 * Runs the complete vertical lifetime value (LTV) calculation for the first order in the database.
	 *
	 * This method retrieves the first order in the database and enqueues an asynchronous action
	 * for the 'pmw_batch_process_vertical_ltv_calculation'. If a certain action is already scheduled,
	 * or if the first order id could not be retrieved, the method simply returns false and does not enqueue the action.
	 *
	 * The log Contains information about the order's ID for which the vertical LTV calculation will be run.
	 *
	 * @return bool Returns true if the action got successfully enqueued, false otherwise.
	 *
	 * @since 1.35.1
	 */
	public static function run_complete_vertical_ltv_calculation() {

		$first_order_id = self::get_the_first_order_in_the_db();

		if (!$first_order_id) {
			return false;
		}

		// Stop if the recalculation is already running
		if (self::is_recalculation_running()) {
			return false;
		}

		// If a pmw_batch_process_vertical_ltv_calculation is already scheduled,
		// it is probably scheduled to run sometime in the future.
		// Therefore unschedule it.
		if (
			Helpers::pmw_as_has_scheduled_action(
				'pmw_batch_process_vertical_ltv_calculation',
				[ 'order_id' => $first_order_id ],
				self::$as_group_name
			)
		) {
			as_unschedule_action(
				'pmw_batch_process_vertical_ltv_calculation',
				[ 'order_id' => $first_order_id ],
				self::$as_group_name
			);
		}

		// Schedule the calculation to run immediately
		as_enqueue_async_action(
			'pmw_batch_process_vertical_ltv_calculation',
			[ 'order_id' => $first_order_id ],
			self::$as_group_name
		);

		Logger::info('LTV::run_complete_vertical_ltv_calculation() - running a complete vertical LTV calculation for order ID: ' . $first_order_id);

		return true;
	}

	public static function get_ltv_recalculation_status() {

		$recalculation_status = [];

		$recalculation_status['is_running']   = self::is_recalculation_running();
		$recalculation_status['is_scheduled'] = self::is_recalculation_scheduled();

		return $recalculation_status;
	}

	private static function is_recalculation_running() {
		return Helpers::pmw_as_has_scheduled_action('pmw_horizontal_ltv_calculation_check')
			|| Helpers::pmw_as_has_scheduled_action('pmw_horizontal_ltv_calculation')
			|| true === as_next_scheduled_action('pmw_batch_process_vertical_ltv_calculation');
	}

	private static function is_recalculation_scheduled() {
		return Helpers::pmw_as_has_scheduled_action('pmw_batch_process_vertical_ltv_calculation')
			&& self::is_recalculation_running() === false;
	}

	/**
	 * Stops the Lifetime Value (LTV) recalculation process.
	 *
	 * This method unschedules all actions related to the LTV recalculation process.
	 * It uses the Action Scheduler library to unschedule all actions with the following hooks:
	 * - 'pmw_horizontal_ltv_calculation_check'
	 * - 'pmw_horizontal_ltv_calculation'
	 * - 'pmw_batch_process_vertical_ltv_calculation'
	 *
	 * @return void
	 *
	 * @since 1.37.1
	 */
	public static function stop_ltv_recalculation() {
		as_unschedule_all_actions('pmw_horizontal_ltv_calculation_check');
		as_unschedule_all_actions('pmw_horizontal_ltv_calculation');
		as_unschedule_all_actions('pmw_batch_process_vertical_ltv_calculation');
	}

	/**
	 * Handles a failed action in the ActionScheduler.
	 *
	 * This function logs the failure, fetches the failed action, verifies whether it is from a specific group,
	 * checks the number of attempts, and determines whether the action is already scheduled.
	 * If necessary, it also reschedules the failed action.
	 *
	 * Source: https://gist.github.com/ryanshoover/83cc871056fc0e8f38bcb2fceb76ed27
	 * Source: https://github.com/woocommerce/action-scheduler/issues/234#issuecomment-462199033
	 *
	 * @param int $action_id The ID of the action.
	 *
	 * @return void
	 *
	 * @throws \Exception If there was an issue rescheduling the action.
	 *
	 * @since 1.35.1
	 */
	public static function handle_action_scheduler_failed_action( $action_id, $type ) {

		$action = ActionScheduler::store()->fetch_action($action_id);

		// Stop if it is not from our group
		if (strpos($action->get_group(), self::$as_group_name) === false) {
			return;
		}

		Logger::debug('LTV::handle_action_scheduler_failed_action() - action ID: ' . $action_id . ' - type: ' . $type);

		// Track retries across rescheduled actions via a counter in the args.
		// AS's built-in attempts column resets with each new action row, so
		// we maintain our own counter that survives rescheduling.
		$args        = $action->get_args();
		$retry_count = isset($args['pmw_retry_count']) ? (int) $args['pmw_retry_count'] : 0;

		if ($retry_count >= self::$max_failed_as_attempts) {
			Logger::info('LTV::handle_action_scheduler_failed_action() - max attempts (' . self::$max_failed_as_attempts . ') reached for hook: ' . $action->get_hook() . ' - args: ' . wp_json_encode($args));
			return;
		}

		$args['pmw_retry_count'] = $retry_count + 1;

		// Stop if the same action is already scheduled
		if (
			Helpers::pmw_as_has_scheduled_action(
				$action->get_hook(),
				$args,
				$action->get_group()
			)
		) {
			Logger::debug('LTV::handle_action_scheduler_failed_action() - action ID ' . $action_id . ' is already scheduled');
			return;
		}

		Logger::debug('LTV::handle_action_scheduler_failed_action() - rescheduling action (attempt ' . $args['pmw_retry_count'] . '/' . self::$max_failed_as_attempts . '): hook: ' . $action->get_hook() . ' - args: ' . wp_json_encode($args) . ' - group: ' . $action->get_group());

		if (
			$action->get_hook() == 'pmw_horizontal_ltv_calculation'
			&& isset($args['order_id'])
			&& self::is_horizontal_ltv_calculation_in_progress($args['order_id'])
		) {
			Logger::debug('LTV::handle_action_scheduler_failed_action() - horizontal ltv calculation for this customer is already in progress - stopping');
			return;
		}

		as_schedule_single_action(
			0,
			$action->get_hook(),
			$args,
			$action->get_group()
		);
	}

	/**
	 * Checks if a horizontal LTV (Lifetime Value) calculation is in progress for a specific order.
	 *
	 * This method checks if there's a scheduled action `pmw_horizontal_ltv_calculation` for the given order ID.
	 * The action should be grouped by customer's billing email.
	 *
	 * @param int $order_id The ID of the order to check.
	 *
	 * @return bool Returns true if a horizontal LTV calculation is currently scheduled for the order,
	 *              false if no such action is scheduled or if the order does not exist.
	 *
	 * @since 1.35.1
	 */
	private static function is_horizontal_ltv_calculation_in_progress( $order_id ) {

		$order = wc_get_order($order_id);

		if (!$order) {
			return false;
		}

		if (
			Helpers::pmw_as_has_scheduled_action(
				'pmw_horizontal_ltv_calculation',
				[ 'billing_email' => $order->get_billing_email() ],
				self::$as_group_name,
				true
			)
		) {
			return true;
		}

		return false;
	}

	/**
	 * Returns the PHP memory limit in bytes, or 0 if unlimited/unparseable.
	 *
	 * @return int
	 *
	 * @since 1.58.5
	 */
	private static function get_memory_limit_bytes() {
		$limit = ini_get('memory_limit');

		if (!$limit || '-1' === $limit) {
			return 0;
		}

		$value = (int) $limit;
		$unit  = strtolower(substr(trim($limit), -1));

		switch ($unit) {
			case 'g':
				$value *= 1024;
				// fall through
			case 'm':
				$value *= 1024;
				// fall through
			case 'k':
				$value *= 1024;
		}

		return $value;
	}
}
