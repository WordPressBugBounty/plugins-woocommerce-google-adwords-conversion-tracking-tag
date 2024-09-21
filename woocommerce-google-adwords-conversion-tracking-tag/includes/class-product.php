<?php

namespace SweetCode\Pixel_Manager;

use SweetCode\Pixel_Manager\Admin\Environment;
use SweetCode\Pixel_Manager\Pixels\Google\Google_Helpers;

defined('ABSPATH') || exit; // Exit if accessed directly

class Product {

	public static function get_order_item_ids( $order, $pixel_name ) {

		$order_items       = self::pmw_get_order_items($order);
		$order_items_array = [];

		foreach ((array) $order_items as $order_item) {

			$product_id = self::get_variation_or_product_id($order_item->get_data(), Options::get_options_obj()->general->variations_output);

			$product = wc_get_product($product_id);

			// Only add if WC retrieves a valid product
			if (self::is_not_wc_product($product)) {
				self::log_problematic_product_id($product_id);
				continue;
			}

			$order_items_array[] = self::get_dyn_r_id_for_product_by_pixel_name($product, $pixel_name);
		}

		return $order_items_array;
	}

	public static function pmw_get_order_items( $order ) {

		$order_items = apply_filters_deprecated('wooptpm_order_items', [ $order->get_items(), $order ], '1.13.0', 'wpm_order_items');
		$order_items = apply_filters_deprecated('wpm_order_items', [ $order_items ], '1.31.2', 'pmw_order_items');

		// Give option to filter order items
		// then return
		return apply_filters('pmw_order_items', $order_items, $order);
	}

	public static function get_variation_or_product_id( $item, $variations_output = true ) {

		if (true === filter_var($variations_output, FILTER_VALIDATE_BOOLEAN) && !empty($item['variation_id'])) {
			return $item['variation_id'];
		}

		return $item['product_id'];
	}

	public static function get_dyn_r_ids( $product ) {

		$dyn_r_ids = [
			'post_id' => (string) $product->get_id(),
			'sku'     => (string) $product->get_sku() ? $product->get_sku() : $product->get_id(),
			'gpf'     => 'woocommerce_gpf_' . $product->get_id(),
			'gla'     => 'gla_' . $product->get_id(),
		];

		// if you want to add a custom dyn_r_id for each product
		$dyn_r_ids = apply_filters_deprecated('wooptpm_product_ids', [ $dyn_r_ids, $product ], '1.13.0', 'pmw_product_ids');
		$dyn_r_ids = apply_filters_deprecated('wpm_product_ids', [ $dyn_r_ids, $product ], '1.31.2', 'pmw_product_ids');

		return apply_filters('pmw_product_ids', $dyn_r_ids, $product);
	}

	/**
	 * Get the dynamic remarketing ID for the product by the pixel name.
	 *
	 * @param $product
	 * @param $pixel_name
	 * @return mixed
	 */
	public static function get_dyn_r_id_for_product_by_pixel_name( $product, $pixel_name ) {

		$dyn_r_ids = self::get_dyn_r_ids($product);

		return $dyn_r_ids[self::get_dyn_r_id_type($pixel_name)];
	}

	/**
	 * Get the dyn_r_id type for the pixel.
	 *
	 * @param $pixel_name
	 * @return string
	 * @since 1.42.7
	 */
	public static function get_dyn_r_id_type( $pixel_name ) {

		$dyn_r_id_type = self::get_product_identifier_from_settings();

		// If you want to change the dyn_r_id type programmatically
		$dyn_r_id_type = apply_filters_deprecated('wooptpm_product_id_type_for_' . $pixel_name, [ $dyn_r_id_type ], '1.13.0', 'pmw_product_id_type_for_');
		$dyn_r_id_type = apply_filters_deprecated('wpm_product_id_type_for_' . $pixel_name, [ $dyn_r_id_type ], '1.31.2', 'pmw_product_id_type_for_');

		return apply_filters('pmw_product_id_type_for_' . $pixel_name, $dyn_r_id_type);
	}

	/**
	 * Get the product identifier from the settings.
	 *
	 * @return string
	 * @since 1.42.7
	 */
	private static function get_product_identifier_from_settings() {

		$product_identifier = Options::get_options_obj()->google->ads->product_identifier;

		switch ($product_identifier) {
			case 0:
				return 'post_id';
			case 1:
				return 'gpf';
			case 2:
				return 'sku';
			case 3:
				return 'gla';
			default:
				return 'post_id';
		}
	}

	public static function log_problematic_product_id( $product_id = 0 ) {

		Logger::debug(
			'WooCommerce detects the page ID ' . $product_id . ' as product, but when invoked by wc_get_product( ' . $product_id . ' ) it returns no product object'
		);
	}

	public static function get_product_price_for_datalayer( $product ) {

		// https://stackoverflow.com/a/37231033/4688612
		// This also works with WPML Multicurrency
		if (self::output_product_prices_with_tax()) {
			return wc_get_price_including_tax($product);
		} else {
			return wc_get_price_excluding_tax($product);
		}
	}

	public static function get_product_details_for_datalayer( $product ) {

		$dyn_r_ids = self::get_dyn_r_ids($product);

		$price = self::get_product_price_for_datalayer($product);

		$product_details = [
			'id'          => (string) $product->get_id(),
			'sku'         => (string) $product->get_sku(),
			'price'       => (float) Helpers::format_decimal($price, 2),
			'brand'       => self::get_brand_name($product->get_id()),
			'quantity'    => 1,
			'dyn_r_ids'   => $dyn_r_ids,
			'is_variable' => $product->get_type() === 'variable',
			'type'        => $product->get_type(),
		];

		if ($product->get_type() === 'variation') { // In case the product is a variation

			$parent_product = wc_get_product($product->get_parent_id());

			if ($parent_product) {

				$product_details['name']                = Helpers::clean_product_name_for_output($parent_product->get_name());
				$product_details['parent_id_dyn_r_ids'] = self::get_dyn_r_ids($parent_product);
				$product_details['parent_id']           = $parent_product->get_id();
				$product_details['brand']               = self::get_brand_name($parent_product->get_id());
				$product_details['category']            = self::get_product_category($product->get_parent_id());
			} else {
				Logger::debug('Variation ' . $product->get_id() . ' doesn\'t link to a valid parent product.');
			}

			$product_details['variant']      = self::get_formatted_variant_text($product);
			$product_details['is_variation'] = true;
		} else { // It's not a variation, so get the fields for a regular product

			$product_details['name']         = Helpers::clean_product_name_for_output((string) $product->get_name());
			$product_details['category']     = self::get_product_category($product->get_id());
			$product_details['is_variation'] = false;
		}

		return $product_details;
	}

	/**
	 * Set if the product prices should be output with tax or without tax.
	 * The default is to output the prices with tax.
	 *
	 * @return bool
	 */
	public static function output_product_prices_with_tax() {

		// Output the product prices with tax as default
		// otherwise, output the prices without tax
		return (bool) apply_filters('pmw_output_product_prices_with_tax', true);
	}

	// https://stackoverflow.com/a/56278308/4688612
	// https://stackoverflow.com/a/39034036/4688612
	public static function get_brand_name( $product_id ) {

		$brand_taxonomy = 'pa_brand';

		if (Environment::is_yith_wc_brands_active()) {
			$brand_taxonomy = 'yith_product_brand';
		} elseif (Environment::is_woocommerce_brands_active()) {
			$brand_taxonomy = 'product_brand';
		}

		$brand_taxonomy = apply_filters_deprecated('wooptpm_custom_brand_taxonomy', [ $brand_taxonomy ], '1.13.0', 'pmw_custom_brand_taxonomy');
		$brand_taxonomy = apply_filters_deprecated('wpm_custom_brand_taxonomy', [ $brand_taxonomy ], '1.31.2', 'pmw_custom_brand_taxonomy');

		// Use custom brand_taxonomy
		$brand_taxonomy = apply_filters('pmw_custom_brand_taxonomy', $brand_taxonomy);

		if (self::get_brand_by_taxonomy($product_id, $brand_taxonomy)) {
			return self::get_brand_by_taxonomy($product_id, $brand_taxonomy);
		} elseif (self::get_brand_by_taxonomy($product_id, 'pa_' . $brand_taxonomy)) {
			return self::get_brand_by_taxonomy($product_id, 'pa_' . $brand_taxonomy);
		} else {
			return '';
		}
	}

	public static function get_brand_by_taxonomy( $product_id, $taxonomy ) {

		if (taxonomy_exists($taxonomy)) {
			$brand_names = wp_get_post_terms($product_id, $taxonomy, [ 'fields' => 'names' ]);
			return reset($brand_names);
		} else {
			return '';
		}
	}

	public static function get_formatted_variant_text( $product ) {

		$variant_text_array = [];

		$attributes = $product->get_attributes();
		if ($attributes) {
			foreach ($attributes as $key => $value) {

				$key_name             = str_replace('pa_', '', $key);
				$variant_text_array[] = ucfirst($key_name) . ': ' . strtolower($value);
			}
		}

		return implode(' | ', $variant_text_array);
	}

	// get an array with all product categories
	public static function get_product_category( $product_id ) {

		/**
		 * On some installs the categories don't sync down to the variations.
		 * Therefore, we get the categories from the parent product.
		 */
		if ('variation' === wc_get_product($product_id)->get_type()) {
			$product_id = wc_get_product($product_id)->get_parent_id();
		}

		$prod_cats        = get_the_terms($product_id, 'product_cat');
		$prod_cats_output = [];

		// only continue with the loop if one or more product categories have been set for the product
		if (!empty($prod_cats)) {

			foreach ((array) $prod_cats as $key) {
				$prod_cats_output[] = $key->name;
			}

			// apply filter to the $prod_cats_output array
			$prod_cats_output = apply_filters_deprecated('wgact_filter', [ $prod_cats_output ], '1.10.2', '', 'This filter has been deprecated without replacement.');
		}

		return $prod_cats_output;
	}


	public static function is_variable_product_by_id( $product_id ) {

		$product = wc_get_product($product_id);

		return $product->get_type() === 'variable';
	}

	public static function get_compiled_product_id( $product_id, $product_sku, $options, $channel = '' ) {

		// depending on setting use product IDs or SKUs
		if (0 == Options::get_options_obj()->google->ads->product_identifier || 'ga_ua' === $channel || 'ga_4' === $channel) {
			return (string) $product_id;
		} elseif (1 == Options::get_options_obj()->google->ads->product_identifier) {
			return (string) 'woocommerce_gpf_' . $product_id;
		} elseif ($product_sku) {
			return (string) $product_sku;
		} else {
			return (string) $product_id;
		}
	}

	public static function log_problematic_product( $product ) {

		Logger::debug(
			'WooCommerce detects the following product as product , but when invoked by wc_get_product( ' . $product->get_id() . ' ) it returns no product object'
		);
	}

	public static function get_front_end_order_items( $order ) {

		$order_items           = self::pmw_get_order_items($order);
		$order_items_formatted = [];

		foreach ((array) $order_items as $order_item) {

			$order_item_data = $order_item->get_data();

			$product = $order_item->get_product();

			if (self::is_not_wc_product($product)) {
				return [];
			}

			$product_data = [
				'id'           => $order_item_data['product_id'],
				'variation_id' => $order_item_data['variation_id'],
				'name'         => $order_item_data['name'],
				'quantity'     => $order_item_data['quantity'],
				'price'        => Google_Helpers::pmw_get_order_item_price($order_item),
				'subtotal'     => (float) Helpers::format_decimal($order_item_data['subtotal'], 2),
				'subtotal_tax' => (float) Helpers::format_decimal($order_item_data['subtotal_tax'], 2),
				'total'        => (float) Helpers::format_decimal($order_item_data['total'], 2),
				'total_tax'    => (float) Helpers::format_decimal($order_item_data['total_tax'], 2),
			];

			// Filter to add custom item parameters
			// that will be added to $product_data['custom_parameters']
			// if the filter returns a non-empty array
			$custom_parameters = Shop::get_custom_order_item_parameters($order_item, $order);
			if (!empty($custom_parameters)) {
				$product_data['custom_parameters'] = $custom_parameters;
			}

			$order_items_formatted[] = $product_data;
		}

		return $order_items_formatted;
	}

	// OB is needed for the Gutenberg block
	public static function ob_print_get_product_data_layer_script( $product, $set_position = true, $meta_tag = false ) {

		ob_start();

		self::print_product_data_layer_script($product, $set_position = true, $meta_tag = false);

		return ob_get_clean();
	}

	public static function print_product_data_layer_script( $product, $set_position = true, $meta_tag = false ) {

		if (self::is_not_wc_product($product)) {
			Logger::debug('get_product_data_layer_script received an invalid product');
			return '';
		}

		$data = self::get_product_details_for_datalayer($product);

		// If placed in <head> it must be a <meta> tag else, it can be an <input> tag
		// Added name and content to meta in order to pass W3 validation test at https://validator.w3.org/nu/
		$tag = $meta_tag ? "meta name='wpm-dataLayer-meta' content='" . $product->get_id() . "'" : "input type='hidden'";

		self::get_product_data_layer_script_html_part_1($tag, $product, $data, $set_position, $meta_tag);
	}

	public static function get_product_data_layer_script_html_part_1( $tag, $product, $data, $set_position, $meta_tag ) {

		if ($meta_tag) {
			?>
			<meta name="pm-dataLayer-meta" content="<?php esc_html_e($product->get_id()); ?>" class="wpmProductId"
				  data-id="<?php esc_html_e($product->get_id()); ?>">
			<?php
		} else {
			?>
			<input type="hidden" class="wpmProductId" data-id="<?php esc_html_e($product->get_id()); ?>">
			<?php
		}

		?>
		<script<?php echo wp_kses(Helpers::get_opening_script_string(), Helpers::get_script_string_allowed_html()); ?>>
			(window.wpmDataLayer = window.wpmDataLayer || {}).products             = window.wpmDataLayer.products || {};
			window.wpmDataLayer.products[<?php esc_html_e($product->get_id()); ?>] = <?php echo wp_json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE); ?>;
			<?php $set_position ? self::get_product_data_layer_script_html_part_2($product) : ''; ?>
		</script>
		<?php
	}

	public static function get_product_data_layer_script_html_part_2( $product ) {
		?>
		window.pmw_product_position = window.pmw_product_position || 1;
		window.wpmDataLayer.products[<?php esc_html_e($product->get_id()); ?>]['position'] = window.pmw_product_position++;
		<?php
	}

	/**
	 * Check if $var is a valid WooCommerce product.
	 *
	 * @param $var
	 * @return bool
	 * @since 1.28.0
	 */
	public static function is_wc_product( $var ) {
		return $var instanceof \WC_Product;
	}

	/**
	 * Check if $var is not a valid WooCommerce product.
	 *
	 * @param $var
	 * @return bool
	 * @since 1.28.0
	 */
	public static function is_not_wc_product( $var ) {
		return !self::is_wc_product($var);
	}
}
