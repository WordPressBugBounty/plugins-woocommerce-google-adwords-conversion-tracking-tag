<?php

/**
 * Profit Margin calculation class
 *
 * TODO: Check out if this should not be a premium class. If yes, also make sure that all activation methods are behind premium checks.
 */

namespace SweetCode\Pixel_Manager;

use WC_Order;
use WC_Product;

defined('ABSPATH') || exit; // Exit if accessed directly

class Profit_Margin {

	/**
	 * Calculates the profit margin of a given order.
	 *
	 * This method calculates the total profit margin of an order by utilizing other methods
	 * within the Profit Margin class. The profit margin is initially calculated based on the cost
	 * of goods sold for each product in the order. After that, it deducts any discounts applied
	 * to the order, and also any fees associated with the order. The final profit margin
	 * is then returned as a formatted decimal number.
	 *
	 * @param WC_Order $order The order for which the profit margin needs to be calculated.
	 *
	 * @return float The profit margin of the order formatted as a decimal number.
	 */
	public static function get_order_profit_margin( $order ) {

		$cogs_margin = self::get_order_item_profit_margin($order);

		$cogs_margin -= $order->get_total_discount();
		$cogs_margin -= Shop::get_order_fees($order);

		return Helpers::format_decimal($cogs_margin);
	}

	/**
	 * Calculates the profit margin for the order items.
	 *
	 * This function is responsible for calculating the profit margin for given order items.
	 * It iterates through all the order items, gets the related product
	 * and takes into account any refunds that may have occurred.
	 * The calculation takes into consideration the cost of goods (COG).
	 *
	 * @param WC_Order $order The order object whose items' profit margin needs to be calculated.
	 *
	 * @return float The calculated profit margin for the order items.
	 */
	private static function get_order_item_profit_margin( $order ) {

		$order_items = $order->get_items();

		$order_value = 0;

		foreach ($order_items as $item) {

			$product = $item->get_product();

			if (Product::is_not_wc_product($product)) {
				continue;
			}

			// The $refund_qty is 0 or a negative number.
			// So we use abs() to get the positive number of refunded items for easier calculation.
			$refund_qty = abs($order->get_qty_refunded_for_item($item->get_id()));

			// The historic price of the item is not saved in the data store, so we have to calculate it
			$item_price_of_order = $item->get_subtotal() / $item->get_quantity();

			$item_refund_total = $refund_qty * $item_price_of_order;

			$item_cog = self::get_cog($item, $product);

			$item_cog_total = ( $item->get_quantity() - $refund_qty ) * $item_cog;

			$order_value += $item->get_subtotal() - $item_refund_total - $item_cog_total;
		}

		return $order_value;
	}

	/**
	 * Determine and return the cost of goods (COG) for the given order item and product.
	 *
	 * This method attempts to calculate the COG in the following order until a valid result is obtained:
	 * - Get it from the order item meta by calling the private method `get_cog_from_order_item()`
	 * - If not available, get it from the product by calling the private method `get_cog_from_product()`
	 *
	 * If none of these methods provide a valid COG, default value of 0 is returned.
	 *
	 * @param mixed $order_item : The item in the order for which COG needs to be calculated.
	 * @param mixed $product    : The product for which COG needs to be calculated.
	 *
	 * @return float|int The cost of goods for the given order item and product, or 0 if it cannot be calculated.
	 */
	private static function get_cog( $order_item, $product ) {

		$order_item_cog = self::get_cog_from_order_item($order_item);
		if (!is_null($order_item_cog)) {
			return $order_item_cog;
		}

		$product_cog = self::get_cog_from_product($product);
		if (!is_null($product_cog)) {
			return $product_cog;
		}

		return 0;
	}

	/**
	 * Retrieves the cost of goods (COG) from an order item by evaluating multiple item meta key options in order.
	 *
	 * The method loops through a number of predefined meta keys. It checks if these meta keys have values set for
	 * the provided order item. If a value is found, it returns the value as a float data type.
	 * If no value is found for any meta keys, it returns null.
	 *
	 * @param mixed $order_item The order item object from which the COG is to be retrieved.
	 *
	 * @return float|null The value of the item cost of goods if found, else null.
	 *
	 * @since 1.35.1
	 */
	private static function get_cog_from_order_item( $order_item ) {

		$meta_keys = [
			self::get_custom_cog_product_meta_key(),
			'_wc_cog_item_cost',
			'_alg_wc_cog_item_cost',
		];

		foreach ($meta_keys as $meta_key) {
			$item_cog = $order_item->get_meta($meta_key);
			if ($item_cog) {
				return floatval($item_cog);
			}
		}

		return null;
	}

	/**
	 * Retrieve Cost of Goods (COG) for a specific product.
	 *
	 * This method attempts to retrieve COG from various COG plugins.
	 * If none of these plugins is available, the function will
	 * fall back to retrieve COG directly from postmeta.
	 *
	 * @param mixed $product represents the product object for which the COG needs to be determined.
	 *
	 * @return float|null Returns the COG value for the given product if available, otherwise returns null.
	 */
	private static function get_cog_from_product( $product ) {

		/**
		 * Try to get COG from one of the COG plugins
		 */

		// WooCommerce Cost of Goods (SkyVerge)
		if (class_exists('WC_COG_Product') && method_exists('WC_COG_Product', 'get_cost')) {
			return floatval(\WC_COG_Product::get_cost($product));
		}

		// Cost of Goods for WooCommerce (WPFactory)
		if (class_exists('Alg_WC_Cost_of_Goods_Products') && method_exists('Alg_WC_Cost_of_Goods_Products', 'get_product_cost')) {
			return floatval(( new \Alg_WC_Cost_of_Goods_Products() )->get_product_cost($product->get_id()));
		}

		/**
		 * Fallback to retrieving directly from postmeta if the COG plugin gets deactivated for some reason
		 */

		$meta_keys = [
			self::get_custom_cog_product_meta_key(),    // Custom COG meta key
			'_wc_cog_cost',                     // WooCommerce Cost of Goods (SkyVerge)
			'_alg_wc_cog_cost',                 // Cost of Goods for WooCommerce (WPFactory)
		];

		foreach ($meta_keys as $meta_key) {
			$product_cog = self::get_cog_for_product_from_meta($product, $meta_key);
			if ($product_cog) {
				return $product_cog;
			}
		}

		return null;
	}

	/**
	 * Checks for the existence of the WC_COG_Product class and 'get_cost' method.
	 *
	 * This method is used to verify the availability of Skyverge Cost of Goods (COG) method
	 * 'get_cost' on WC_COG_Product class. It helps in determining the cost of goods for individual
	 * products in various scenarios.
	 *
	 * @return bool Returns true if the WC_COG_Product class exists and the 'get_cost' method is available.
	 *              Otherwise, returns false.
	 *
	 * @since 1.35.1
	 */
	private static function is_skyverge_cog_method_get_cost_available() {
		return class_exists('WC_COG_Product') && method_exists('WC_COG_Product', 'get_cost');
	}

	/**
	 * Checks if the method 'get_product_cost' is available inside the 'Alg_WC_Cost_of_Goods_Products' class
	 *
	 * This method validates if the `Alg_WC_Cost_of_Goods_Products` class exists and
	 * if the method `get_product_cost` is defined in that class.
	 *
	 * @return bool
	 *    Returns `true` if the `Alg_WC_Cost_of_Goods_Products` class is defined and the `get_product_cost`
	 *    method is available in that class, otherwise `false`.
	 *
	 * @since 1.35.1
	 */
	private static function is_wpfactory_cog_method_get_product_cost_available() {
		return class_exists('Alg_WC_Cost_of_Goods_Products') && method_exists('Alg_WC_Cost_of_Goods_Products', 'get_product_cost');
	}

	/**
	 * Set a custom Cost Of Goods Sold meta key.
	 *
	 * @return mixed
	 * @since 1.30.6
	 */
	public static function get_custom_cog_product_meta_key() {

		$meta_key = null;

		$meta_key = apply_filters_deprecated('pmw_custom_cogs_meta_key', [ $meta_key ], '1.43.5', 'pmw_custom_cogs_product_meta_key');

		return apply_filters('pmw_custom_cogs_product_meta_key', $meta_key);
	}

	/**
	 * Retrieves the Cost of Goods for a product from metadata.
	 *
	 * @param WC_Product $product  The product for which the COG is being retrieved.
	 * @param string     $meta_key The meta_key under which the COG is stored.
	 *
	 * @return float|null                 The COG value if it exists, null otherwise.
	 */
	private static function get_cog_for_product_from_meta( $product, $meta_key ) {

		$cog = get_post_meta($product->get_id(), $meta_key, true);

		// If item is a variation and the COG is set, use the variation COG, otherwise try to use the parent COG
		if (empty($cog) && $product->is_type('variation')) {
			$cog = get_post_meta($product->get_parent_id(), $meta_key, true);
		}

		if ($cog) {
			return floatval($cog);
		}

		return null;
	}
}
