<?php
/**
 * WooCommerce shop provider.
 *
 * Reference implementation of the Shop_Provider seam: delegates to the
 * existing Shop facade and WooCommerce APIs. The heavy lifting
 * (order values, subtotal semantics, split payments, refunds) stays in
 * the Shop facade; this class is the addressing layer that makes it
 * reachable through the platform-neutral surface.
 *
 * @since 1.65.0
 */

namespace SweetCode\Pixel_Manager\Platforms\WooCommerce;

use SweetCode\Pixel_Manager\Platforms\Order_Data;
use SweetCode\Pixel_Manager\Platforms\Shop_Provider;
use SweetCode\Pixel_Manager\Shop;

defined('ABSPATH') || exit; // Exit if accessed directly

class WooCommerce_Provider implements Shop_Provider {

	public function get_name() {
		return 'woocommerce';
	}

	public function is_shop_active() {
		return class_exists('WooCommerce');
	}

	public function get_page_type() {

		if (is_front_page()) {
			return 'front_page';
		}

		if (function_exists('is_product') && is_product()) {
			return 'product';
		}

		if (function_exists('is_product_category') && is_product_category()) {
			return 'product_category';
		}

		if (is_search()) {
			return 'search';
		}

		/**
		 * The order received page is an endpoint of the checkout page, so it has to be
		 * resolved before both the cart and the checkout. Shop::pmw_is_cart_page()
		 * keeps the checkout out of the cart branch on shops where a checkout builder
		 * makes is_cart() true on the checkout route too.
		 */
		if (Shop::pmw_is_order_received_page()) {
			return 'order_received_page';
		}

		if (Shop::pmw_is_cart_page()) {
			return 'cart';
		}

		if (function_exists('is_checkout') && is_checkout()) {
			return 'checkout';
		}

		return 'page';
	}

	public function get_current_order() {

		$order = Shop::pmw_get_current_order();

		return $order ? new WooCommerce_Order_Data($order) : null;
	}

	public function get_order_data( $order ) {

		if (is_numeric($order)) {
			$order = wc_get_order($order);
		}

		if ($order instanceof Order_Data) {
			return $order;
		}

		return $order ? new WooCommerce_Order_Data($order) : null;
	}

	public function is_session_active() {
		return Shop::is_woocommerce_session_active();
	}

	public function supports( $feature ) {

		// WooCommerce is the master implementation: everything the Pixel
		// Manager offers is supported here. New platform providers return
		// the outcome of their feature investigation instead.
		return true;
	}
}
