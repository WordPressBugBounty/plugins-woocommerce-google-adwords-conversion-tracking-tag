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

		/**
		 * Give option to filter order items then return.
		 *
		 * @since 1.31.2
		 */
		$order_items = apply_filters('pmw_order_items', $order_items, $order);

		/**
		 * Collapse bundle/composite containers into a single reported line by
		 * dropping the bundled/composited child items. A bundle is then counted
		 * once (as its container, repriced via pmw_get_order_item_price) instead
		 * of as container + N children, which inflated item counts and produced
		 * 0-priced phantom rows. No-op when neither WooCommerce Product Bundles
		 * nor Composite Products is active.
		 */
		return self::remove_container_child_order_items($order_items, $order);
	}

	/**
	 * Total quantity of the reported (bundle-collapsed) order items. Use instead
	 * of $order->get_item_count() so bundle children don't inflate the count.
	 *
	 * @param \WC_Abstract_Order $order
	 * @return int
	 */
	public static function get_order_item_count( $order ) {

		$count = 0;

		foreach (self::pmw_get_order_items($order) as $order_item) {
			$count += (int) $order_item->get_quantity();
		}

		return $count;
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

		/**
		 * Filters Product ids.
		 *
		 * @since 1.31.2
		 */
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

		/**
		 * Filters Product id type for.
		 *
		 * @since 1.31.2
		 */
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

		// Container products (WooCommerce Product Bundles / Composite Products)
		// don't expose their assembled price through get_price(), which the
		// wc_get_price_* helpers read, so they would report 0 for "priced
		// individually" configurations. Resolve the assembled price first.
		$price = self::get_container_product_price_for_datalayer($product);

		if (null === $price) {
			// https://stackoverflow.com/a/37231033/4688612
			// This also works with WPML Multicurrency
			if (self::output_product_prices_with_tax()) {
				$price = wc_get_price_including_tax($product);
			} else {
				$price = wc_get_price_excluding_tax($product);
			}
		}

		/**
		 * Filter the per-product price written to the data layer (add_to_cart,
		 * view_item, view_item_list, cart).
		 *
		 * @since 1.61.0
		 *
		 * @param float|string $price   The resolved product price.
		 * @param \WC_Product  $product The product being priced.
		 */
		return apply_filters('pmw_product_price_for_datalayer', $price, $product);
	}

	/**
	 * Resolve the assembled price for container products whose get_price() does
	 * not reflect their real value.
	 *
	 * WooCommerce Product Bundles: a "priced individually" bundle keeps its value
	 * on the bundled child items, so the container's get_price() is 0/empty. The
	 * assembled minimum price lives in get_bundle_price_including/excluding_tax().
	 * WooCommerce Composite Products behaves the same way. For static bundles the
	 * bundle-price getters return the same value get_price() would, so calling
	 * them unconditionally is safe for both modes.
	 *
	 * Returns null for regular products (and when the relevant plugin is not
	 * active), so the caller falls back to the standard wc_get_price_* path.
	 *
	 * @param \WC_Product $product
	 * @return float|null
	 */
	private static function get_container_product_price_for_datalayer( $product ) {

		if (!is_callable([ $product, 'is_type' ])) {
			return null;
		}

		$incl_tax = self::output_product_prices_with_tax();

		if ($product->is_type('bundle') && method_exists($product, 'get_bundle_price_including_tax')) {
			$price = $incl_tax
				? $product->get_bundle_price_including_tax('min')
				: $product->get_bundle_price_excluding_tax('min');
			return ( '' === $price || null === $price ) ? null : (float) $price;
		}

		if ($product->is_type('composite') && method_exists($product, 'get_composite_price_including_tax')) {
			$price = $incl_tax
				? $product->get_composite_price_including_tax('min')
				: $product->get_composite_price_excluding_tax('min');
			return ( '' === $price || null === $price ) ? null : (float) $price;
		}

		return null;
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

		/**
		 * Output the product prices with tax as default otherwise, output the prices without tax.
		 *
		 * @since 1.58.5
		 */
		return (bool) apply_filters('pmw_output_product_prices_with_tax', true);
	}

	/**
	 * Retrieve the brand name for a given product ID based on brand taxonomy settings.
	 *
	 * Source: https://stackoverflow.com/a/56278308/4688612
	 * Source: https://stackoverflow.com/a/39034036/4688612
	 *
	 * @param int $product_id The ID of the product for which to get the brand name.
	 * @return string The brand name associated with the product, or an empty string if no brand is found.
	 */
	public static function get_brand_name( $product_id ) {

		// Works for the WooCommere internal brand taxonomy since version 9.7
		// and for the deprecated WooCommerce Brands plugin
		$brand_taxonomy = 'product_brand';

		if (Environment::is_yith_wc_brands_active()) {
			$brand_taxonomy = 'yith_product_brand';
		}

		$brand_taxonomy = apply_filters_deprecated('wooptpm_custom_brand_taxonomy', [ $brand_taxonomy ], '1.13.0', 'pmw_custom_brand_taxonomy');
		$brand_taxonomy = apply_filters_deprecated('wpm_custom_brand_taxonomy', [ $brand_taxonomy ], '1.31.2', 'pmw_custom_brand_taxonomy');

		/**
		 * Use custom brand_taxonomy.
		 *
		 * @since 1.31.2
		 */
		$brand_taxonomy = apply_filters('pmw_custom_brand_taxonomy', $brand_taxonomy);

		$brand = self::get_brand_by_taxonomy($product_id, $brand_taxonomy);
		if ($brand) {
			return $brand;
		}

		$brand = self::get_brand_by_taxonomy($product_id, 'pa_' . $brand_taxonomy);
		if ($brand) {
			return $brand;
		}

		return '';
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

		$product = wc_get_product($product_id);

		// Bail if the product no longer exists (e.g. it was deleted but is still referenced by an order item).
		if (self::is_not_wc_product($product)) {
			return [];
		}

		/**
		 * On some installs the categories don't sync down to the variations.
		 * Therefore, we get the categories from the parent product.
		 */
		if ('variation' === $product->get_type()) {
			$product_id = $product->get_parent_id();
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

		if (self::is_not_wc_product($product)) {
			return false;
		}

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
			$product         = $order_item->get_product();

			if (self::is_not_wc_product($product)) {
				return [];
			}

			$product_data = [
				'id'                  => $order_item_data['product_id'],
				'variation_id'        => $order_item_data['variation_id'],
				'name'                => $order_item_data['name'],
				'quantity'            => $order_item_data['quantity'],
				'price'               => self::pmw_get_order_item_price($order_item),
				'price_tax_included'  => self::pmw_get_order_item_price($order_item, true),
				'price_tax_excluded'  => self::pmw_get_order_item_price($order_item, false),
				'subtotal'            => (float) Helpers::format_decimal($order_item_data['subtotal'], 2),
				'subtotal_tax'        => (float) Helpers::format_decimal($order_item_data['subtotal_tax'], 2),
				'total'               => (float) Helpers::format_decimal($order_item_data['total'], 2),
				'total_tax'           => (float) Helpers::format_decimal($order_item_data['total_tax'], 2),
				'variant_description' => (string) ( $product->get_type() === 'variation' ) ? self::get_formatted_variant_text($product) : '',
			];

			// For a bundle/composite container, roll the children's amounts into
			// the single reported line so subtotal/total reflect the whole bundle
			// (the container's own line is 0 in "priced individually" mode). The
			// price fields above are already container-aware via pmw_get_order_item_price().
			$container_totals = self::get_container_order_item_totals($order_item, $order);
			if (null !== $container_totals) {
				$product_data['subtotal']     = $container_totals['subtotal'];
				$product_data['subtotal_tax'] = $container_totals['subtotal_tax'];
				$product_data['total']        = $container_totals['total'];
				$product_data['total_tax']    = $container_totals['total_tax'];
			}

			// Add the name of the parent product if the product is a variation
			if ($product->get_type() === 'variation') {
				// get the parent product
				$parent_product = wc_get_product($product->get_parent_id());

				// Only override with parent data if the parent product still exists.
				if (self::is_wc_product($parent_product)) {
					$product_data['brand']        = self::get_brand_name($parent_product->get_id());
					$product_data['name_variant'] = $product_data['name'];
					$product_data['name']         = $parent_product->get_name();
				}
			}

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
		$tag = $meta_tag ? "meta name='pmw-dataLayer-meta' content='" . $product->get_id() . "'" : "input type='hidden'";

		self::get_product_data_layer_script_html_part_1($tag, $product, $data, $set_position, $meta_tag);
	}

	public static function get_product_data_layer_script_html_part_1( $tag, $product, $data, $set_position, $meta_tag ) {

		if ($meta_tag) {
			?>
			<meta name="pm-dataLayer-meta" content="<?php echo esc_html($product->get_id()); ?>" class="pmwProductId"
					data-id="<?php echo esc_html($product->get_id()); ?>">
			<?php
		} else {
			?>
			<input type="hidden" class="pmwProductId" data-id="<?php echo esc_html($product->get_id()); ?>">
			<?php
		}

		?>
		<script<?php echo wp_kses(Helpers::get_opening_script_string(), Helpers::get_script_string_allowed_html()); ?>>
			(window.pmwDataLayer = window.pmwDataLayer || {}).products                = window.pmwDataLayer.products || {};
			window.pmwDataLayer.products[<?php echo esc_html($product->get_id()); ?>] = <?php echo wp_json_encode($data, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE); ?>;
			<?php $set_position ? self::get_product_data_layer_script_html_part_2($product) : ''; ?>
		</script>
		<?php
	}

	public static function get_product_data_layer_script_html_part_2( $product ) {
		?>
		window.pmw_product_position = window.pmw_product_position || 1;
		window.pmwDataLayer.products[<?php echo esc_html($product->get_id()); ?>]['position'] = window.pmw_product_position++;
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

	/**
	 * Get the price of an order item.
	 *
	 * @param      $order_item
	 * @param null $include_tax
	 * @return float
	 */
	public static function pmw_get_order_item_price( $order_item, $include_tax = null ) {

		if (null === $include_tax) {
			$include_tax = self::output_product_prices_with_tax();
		}

		// Bundle/Composite container: its own line is 0 ("priced individually")
		// or carries the whole price (static), while the children carry the rest.
		// Report the assembled per-unit price so the single collapsed line matches
		// what was paid for the bundle. Must run before the get_item_total() path,
		// which would return the container's own (possibly 0) line amount.
		$container_price = self::maybe_get_container_order_item_unit_price($order_item, $include_tax);
		if (null !== $container_price) {
			return $container_price;
		}

		if (Environment::is_woo_discount_rules_active()) {

			$item_value = $order_item->get_meta('_advanced_woo_discount_item_total_discount');

			if (isset($item_value['discounted_price']) && 0 !== $item_value['discounted_price']) {
				return (float) wc_format_decimal($item_value['discounted_price'], 2);
			}

			if (isset($item_value['initial_price']) && 0 !== $item_value['initial_price']) {
				return (float) wc_format_decimal($item_value['initial_price'], 2);
			}
		}

		return (float) wc_format_decimal($order_item->get_order()->get_item_total($order_item, $include_tax), 2);
	}

	/*
	|--------------------------------------------------------------------------
	| Container products (WooCommerce Product Bundles / Composite Products)
	|--------------------------------------------------------------------------
	|
	| A bundle/composite is stored as a container line item plus one line item
	| per child. PMW reports the bundle once (the container) and prices it as the
	| sum of the container and its children, so item counts and per-item revenue
	| stay correct in both pricing modes. Every helper is a no-op when the
	| relevant plugin is inactive (the function_exists guards return false), so
	| behaviour is unchanged on stores without bundles/composites.
	*/

	private static function remove_container_child_order_items( $order_items, $order ) {

		if (
			!function_exists('wc_pb_is_bundled_order_item')
			&& !function_exists('wc_cp_is_composited_order_item')
		) {
			return $order_items;
		}

		foreach ($order_items as $key => $order_item) {
			if (self::is_container_child_order_item($order_item, $order)) {
				unset($order_items[$key]);
			}
		}

		return $order_items;
	}

	public static function is_container_child_order_item( $order_item, $order ) {

		if (function_exists('wc_pb_is_bundled_order_item') && wc_pb_is_bundled_order_item($order_item, $order)) {
			return true;
		}

		if (function_exists('wc_cp_is_composited_order_item') && wc_cp_is_composited_order_item($order_item, $order)) {
			return true;
		}

		return false;
	}

	public static function is_container_order_item( $order_item ) {

		if (function_exists('wc_pb_is_bundle_container_order_item') && wc_pb_is_bundle_container_order_item($order_item)) {
			return true;
		}

		if (function_exists('wc_cp_is_composite_container_order_item') && wc_cp_is_composite_container_order_item($order_item)) {
			return true;
		}

		return false;
	}

	private static function get_container_child_order_items( $container_order_item, $order ) {

		if (
			function_exists('wc_pb_get_bundled_order_items')
			&& function_exists('wc_pb_is_bundle_container_order_item')
			&& wc_pb_is_bundle_container_order_item($container_order_item)
		) {
			return (array) wc_pb_get_bundled_order_items($container_order_item, $order);
		}

		if (
			function_exists('wc_cp_get_composited_order_items')
			&& function_exists('wc_cp_is_composite_container_order_item')
			&& wc_cp_is_composite_container_order_item($container_order_item)
		) {
			return (array) wc_cp_get_composited_order_items($container_order_item, $order);
		}

		return [];
	}

	/**
	 * The assembled total amount of a bundle/composite container order item
	 * (container line + all child lines, every unit). Returns null when the item
	 * is not a container.
	 *
	 * @param object   $order_item
	 * @param bool|null $include_tax
	 * @return float|null
	 */
	public static function maybe_get_container_order_item_line_amount( $order_item, $include_tax = null ) {

		if (!is_callable([ $order_item, 'get_order' ]) || !self::is_container_order_item($order_item)) {
			return null;
		}

		$order = $order_item->get_order();

		if (!$order) {
			return null;
		}

		if (null === $include_tax) {
			$include_tax = self::output_product_prices_with_tax();
		}

		$line_total = self::get_order_item_line_amount($order_item, $include_tax);

		foreach (self::get_container_child_order_items($order_item, $order) as $child) {
			$line_total += self::get_order_item_line_amount($child, $include_tax);
		}

		return (float) Helpers::format_decimal($line_total, 2);
	}

	/**
	 * The assembled per-unit price of a bundle/composite container order item,
	 * i.e. the rolled-up line amount divided by the container quantity. Returns
	 * null when the item is not a container.
	 *
	 * @param object   $order_item
	 * @param bool|null $include_tax
	 * @return float|null
	 */
	public static function maybe_get_container_order_item_unit_price( $order_item, $include_tax = null ) {

		$line_amount = self::maybe_get_container_order_item_line_amount($order_item, $include_tax);

		if (null === $line_amount) {
			return null;
		}

		$quantity = max(1, (int) $order_item->get_quantity());

		return (float) Helpers::format_decimal($line_amount / $quantity, 2);
	}

	/**
	 * Rolled-up subtotal/total figures for a container order item (container plus
	 * its children). Returns null when the item is not a container.
	 *
	 * @param object             $order_item
	 * @param \WC_Abstract_Order $order
	 * @return array|null
	 */
	private static function get_container_order_item_totals( $order_item, $order ) {

		if (!self::is_container_order_item($order_item)) {
			return null;
		}

		$items = array_merge([ $order_item ], self::get_container_child_order_items($order_item, $order));

		$totals = [
			'subtotal'     => 0.0,
			'subtotal_tax' => 0.0,
			'total'        => 0.0,
			'total_tax'    => 0.0,
		];

		foreach ($items as $item) {
			$totals['subtotal']     += (float) $item->get_subtotal();
			$totals['subtotal_tax'] += (float) $item->get_subtotal_tax();
			$totals['total']        += (float) $item->get_total();
			$totals['total_tax']    += (float) $item->get_total_tax();
		}

		foreach ($totals as $key => $value) {
			$totals[$key] = (float) Helpers::format_decimal($value, 2);
		}

		return $totals;
	}

	private static function get_order_item_line_amount( $order_item, $include_tax ) {

		$amount = (float) $order_item->get_total();

		if ($include_tax) {
			$amount += (float) $order_item->get_total_tax();
		}

		return $amount;
	}

	public static function is_container_child_cart_item( $cart_item ) {

		if (function_exists('wc_pb_is_bundled_cart_item') && wc_pb_is_bundled_cart_item($cart_item)) {
			return true;
		}

		if (function_exists('wc_cp_is_composited_cart_item') && wc_cp_is_composited_cart_item($cart_item)) {
			return true;
		}

		return false;
	}

	/**
	 * The assembled per-unit price of a bundle/composite container cart item,
	 * computed from the configured cart line subtotals (container + children).
	 * Returns null when the item is not a container.
	 *
	 * @param array     $cart_item
	 * @param array     $cart_contents
	 * @param bool|null $include_tax
	 * @return float|null
	 */
	public static function maybe_get_container_cart_item_unit_price( $cart_item, $cart_contents, $include_tax = null ) {

		$children = null;

		if (
			function_exists('wc_pb_is_bundle_container_cart_item')
			&& function_exists('wc_pb_get_bundled_cart_items')
			&& wc_pb_is_bundle_container_cart_item($cart_item)
		) {
			$children = wc_pb_get_bundled_cart_items($cart_item, $cart_contents);
		} elseif (
			function_exists('wc_cp_is_composite_container_cart_item')
			&& function_exists('wc_cp_get_composited_cart_items')
			&& wc_cp_is_composite_container_cart_item($cart_item)
		) {
			$children = wc_cp_get_composited_cart_items($cart_item, $cart_contents);
		}

		if (null === $children) {
			return null;
		}

		if (null === $include_tax) {
			$include_tax = self::output_product_prices_with_tax();
		}

		$subtotal = self::get_cart_item_line_amount($cart_item, $include_tax);

		foreach ((array) $children as $child) {
			$subtotal += self::get_cart_item_line_amount($child, $include_tax);
		}

		$quantity = max(1, (int) $cart_item['quantity']);

		return (float) Helpers::format_decimal($subtotal / $quantity, 2);
	}

	private static function get_cart_item_line_amount( $cart_item, $include_tax ) {

		$amount = isset($cart_item['line_subtotal']) ? (float) $cart_item['line_subtotal'] : 0.0;

		if ($include_tax && isset($cart_item['line_subtotal_tax'])) {
			$amount += (float) $cart_item['line_subtotal_tax'];
		}

		return $amount;
	}
}
