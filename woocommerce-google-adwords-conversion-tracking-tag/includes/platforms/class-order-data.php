<?php
/**
 * Neutral order surface.
 *
 * Order_Data is what the S2S payload builders, the SSP purchase proxy, and
 * the data layer consume instead of a platform-native order object. The
 * method names deliberately match the WC_Order getters the codebase already
 * calls, so on WooCommerce an Order_Data wraps the WC_Order one-to-one and
 * existing call sites keep working unchanged; a FluentCart or SureCart
 * provider implements the same surface over its own order.
 *
 * The methods declared here are the REQUIRED cross-platform surface (the
 * contract every provider must fill, see docs/PLATFORM-CONTRACT.md).
 * WooCommerce-only code paths may reach deeper via get_native_order().
 *
 * @since 1.65.0
 */

namespace SweetCode\Pixel_Manager\Platforms;

defined('ABSPATH') || exit; // Exit if accessed directly

abstract class Order_Data {

	/**
	 * The order id.
	 *
	 * @return int
	 */
	abstract public function get_id();

	/**
	 * Customer-facing order number (often equals the id).
	 *
	 * @return string
	 */
	abstract public function get_order_number();

	/**
	 * Opaque order key for confirmation-page URLs.
	 *
	 * @return string
	 */
	abstract public function get_order_key();

	/**
	 * ISO 4217 currency code.
	 *
	 * @return string
	 */
	abstract public function get_currency();

	/**
	 * Order total including tax and shipping.
	 *
	 * @return float
	 */
	abstract public function get_total();

	/**
	 * Total tax.
	 *
	 * @return float
	 */
	abstract public function get_total_tax();

	/**
	 * Shipping total.
	 *
	 * @return float
	 */
	abstract public function get_shipping_total();

	/**
	 * Total discount amount.
	 *
	 * @return float
	 */
	abstract public function get_total_discount();

	/**
	 * Applied coupon/discount codes.
	 *
	 * @return string[]
	 */
	abstract public function get_coupon_codes();

	/**
	 * Payment gateway identifier.
	 *
	 * @return string
	 */
	abstract public function get_payment_method();

	/**
	 * URL of the order confirmation page for this order.
	 *
	 * @return string
	 */
	abstract public function get_checkout_order_received_url();

	/**
	 * Billing email address.
	 *
	 * @return string
	 */
	abstract public function get_billing_email();

	/**
	 * Billing phone number.
	 *
	 * @return string
	 */
	abstract public function get_billing_phone();

	/**
	 * Billing first name.
	 *
	 * @return string
	 */
	abstract public function get_billing_first_name();

	/**
	 * Billing last name.
	 *
	 * @return string
	 */
	abstract public function get_billing_last_name();

	/**
	 * Billing city.
	 *
	 * @return string
	 */
	abstract public function get_billing_city();

	/**
	 * Billing postcode.
	 *
	 * @return string
	 */
	abstract public function get_billing_postcode();

	/**
	 * Billing country, two-letter code.
	 *
	 * @return string
	 */
	abstract public function get_billing_country();

	/**
	 * Order line items in a neutral shape.
	 *
	 * @return array[] Each item: id (int), variation_id (int), name (string),
	 *                 quantity (int), price (float), total (float),
	 *                 total_tax (float).
	 */
	abstract public function get_items_data();

	/**
	 * Order meta storage: used for S2S hit dedup keys and identifier
	 * snapshots. Providers back this with their platform's order meta.
	 *
	 * @param string $key
	 * @return mixed
	 */
	abstract public function get_meta( $key );

	/**
	 * Stage an order meta update (persisted by save()).
	 *
	 * @param string $key
	 * @param mixed  $value
	 * @return void
	 */
	abstract public function update_meta_data( $key, $value );

	/**
	 * Persist pending meta changes.
	 *
	 * @return void
	 */
	abstract public function save();

	/**
	 * The platform-native order object, for platform-gated code paths only.
	 * Platform-agnostic code must not call this.
	 *
	 * @return mixed
	 */
	abstract public function get_native_order();
}
