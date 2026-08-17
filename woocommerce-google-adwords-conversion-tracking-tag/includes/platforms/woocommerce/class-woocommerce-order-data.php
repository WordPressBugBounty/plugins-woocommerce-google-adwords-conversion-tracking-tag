<?php
/**
 * WooCommerce Order_Data: wraps a WC_Order one-to-one.
 *
 * The neutral getters map straight onto WC_Order's; __call forwards
 * everything else so WooCommerce-gated code paths (and the Shop facade
 * helpers) can keep using the full WC_Order surface during the migration
 * period. Platform-agnostic code must limit itself to the methods declared
 * on Order_Data.
 *
 * @since 1.65.0
 */

namespace SweetCode\Pixel_Manager\Platforms\WooCommerce;

use SweetCode\Pixel_Manager\Platforms\Order_Data;

defined('ABSPATH') || exit; // Exit if accessed directly

class WooCommerce_Order_Data extends Order_Data {

	/**
	 * The wrapped WooCommerce order.
	 *
	 * @var \WC_Order
	 */
	private $order;

	/**
	 * Wraps the given WooCommerce order.
	 *
	 * @param \WC_Order $order
	 */
	public function __construct( $order ) {
		$this->order = $order;
	}

	public function get_id() {
		return (int) $this->order->get_id();
	}

	public function get_order_number() {
		return (string) $this->order->get_order_number();
	}

	public function get_order_key() {
		return (string) $this->order->get_order_key();
	}

	public function get_currency() {
		return (string) $this->order->get_currency();
	}

	public function get_total() {
		return (float) $this->order->get_total();
	}

	public function get_total_tax() {
		return (float) $this->order->get_total_tax();
	}

	public function get_shipping_total() {
		return (float) $this->order->get_shipping_total();
	}

	public function get_total_discount() {
		return (float) $this->order->get_total_discount();
	}

	public function get_coupon_codes() {
		return $this->order->get_coupon_codes();
	}

	public function get_payment_method() {
		return (string) $this->order->get_payment_method();
	}

	public function get_checkout_order_received_url() {
		return (string) $this->order->get_checkout_order_received_url();
	}

	public function get_billing_email() {
		return (string) $this->order->get_billing_email();
	}

	public function get_billing_phone() {
		return (string) $this->order->get_billing_phone();
	}

	public function get_billing_first_name() {
		return (string) $this->order->get_billing_first_name();
	}

	public function get_billing_last_name() {
		return (string) $this->order->get_billing_last_name();
	}

	public function get_billing_city() {
		return (string) $this->order->get_billing_city();
	}

	public function get_billing_postcode() {
		return (string) $this->order->get_billing_postcode();
	}

	public function get_billing_country() {
		return (string) $this->order->get_billing_country();
	}

	public function get_items_data() {

		$items = [];

		foreach ($this->order->get_items() as $item) {

			$product = $item->get_product();

			$items[] = [
				'id'           => (int) $item->get_product_id(),
				'variation_id' => (int) $item->get_variation_id(),
				'name'         => (string) $item->get_name(),
				'quantity'     => (int) $item->get_quantity(),
				'price'        => $product ? (float) $product->get_price() : 0.0,
				'total'        => (float) $item->get_total(),
				'total_tax'    => (float) $item->get_total_tax(),
			];
		}

		return $items;
	}

	public function get_meta( $key ) {
		return $this->order->get_meta($key);
	}

	public function update_meta_data( $key, $value ) {
		$this->order->update_meta_data($key, $value);
	}

	public function save() {
		$this->order->save();
	}

	public function get_native_order() {
		return $this->order;
	}

	/**
	 * Forward everything else to the wrapped WC_Order so WooCommerce-gated
	 * code keeps its full surface during the migration period.
	 *
	 * @param string $name
	 * @param array  $arguments
	 * @return mixed
	 */
	public function __call( $name, $arguments ) {
		return call_user_func_array([ $this->order, $name ], $arguments);
	}
}
