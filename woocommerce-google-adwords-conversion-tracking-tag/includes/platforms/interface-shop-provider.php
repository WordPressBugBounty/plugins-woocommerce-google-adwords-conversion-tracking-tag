<?php
/**
 * Shop platform provider interface.
 *
 * The PHP-side seam between the Pixel Manager and the shop platform it
 * runs on (WooCommerce today; FluentCart and SureCart next). One provider
 * is active per request, resolved by Environment::get_active_shop_platform().
 *
 * Providers answer platform questions (page types, cart, products, orders)
 * and map their platform's order lifecycle onto the Pixel Manager's neutral
 * actions. Everything else in the plugin must stay platform-agnostic and go
 * through this interface (or through the Shop/Product facades, whose
 * platform-specific internals delegate here).
 *
 * See docs/PLATFORM-CONTRACT.md section 3.
 *
 * @since 1.65.0
 */

namespace SweetCode\Pixel_Manager\Platforms;

defined('ABSPATH') || exit; // Exit if accessed directly

interface Shop_Provider {

	/**
	 * Platform slug, e.g. 'woocommerce', 'fluentcart', 'surecart'.
	 * Emitted into the data layer as shop.platform.
	 *
	 * @return string
	 */
	public function get_name();

	/**
	 * Whether this platform's shop plugin is active on the site.
	 *
	 * @return bool
	 */
	public function is_shop_active();

	/**
	 * Platform-neutral page type of the current request, matching the
	 * shop.page_type enum of the data layer schema: front_page, product,
	 * product_category, search, cart, checkout, order_received_page, page.
	 *
	 * @return string
	 */
	public function get_page_type();

	/**
	 * The order shown on the current order confirmation page, or null.
	 *
	 * @return Order_Data|null
	 */
	public function get_current_order();

	/**
	 * Wrap a platform-native order (or order id) in the neutral Order_Data
	 * surface consumed by the S2S payload builders and the data layer.
	 *
	 * @param mixed $order Platform-native order object or order id.
	 * @return Order_Data|null
	 */
	public function get_order_data( $order );

	/**
	 * Whether the visitor currently has an active shop session/cart on the
	 * server side.
	 *
	 * @return bool
	 */
	public function is_session_active();

	/**
	 * Capability flag: whether this platform supports a Pixel Manager
	 * feature. Flags express the OUTCOME of the per-platform feature
	 * investigation (see the feature scope section of
	 * docs/PLATFORM-ABSTRACTION-PLAN.md); nothing else in the codebase may
	 * branch on the platform name.
	 *
	 * Known feature keys: 'acr', 'tracking_accuracy', 'cart_data',
	 * 'checkout_events', 'refunds', 'gateway_accuracy', 'split_payments'.
	 *
	 * @param string $feature
	 * @return bool
	 */
	public function supports( $feature );
}
