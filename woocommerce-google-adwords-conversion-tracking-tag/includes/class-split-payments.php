<?php

/**
 * Compatibility layer for deposit and split-payment plugins.
 *
 * Deposit plugins split one sale across several WooCommerce orders, and the same
 * parent/child structure means different things in different plugins. The tracking
 * rule the Pixel Manager implements is: report each sale once, in full, at the moment
 * the deposit is paid, on the order that represents the sale. Orders that merely
 * collect an instalment for a sale that lives elsewhere never report a purchase.
 *
 * The classification is keyed on what a plugin declares about its orders (a registered
 * order type, or created_via), never on the parent/child structure alone: an amount-only
 * refund has a parent and no line items too, and a child order can just as well be a
 * genuinely new sale. Structure cannot tell those apart, a declared purpose can. The
 * structure is only used as a guard: when it does not look like what the plugin
 * normally produces, the order is treated as standard and nothing changes.
 *
 * Supported plugins:
 *
 * - Acowebs "Deposits & Partial Payments for WooCommerce" (free + Pro): checkout is
 *   hijacked to charge a child order of the registered type `awcdp_payment` that
 *   carries the instalment as a fee line and holds no products. The products, and
 *   therefore the sale, live on the parent order. The order-received page and the
 *   payment hooks belong to the child.
 *
 * - "WooCommerce Deposits" (Webtomizer, woocommerce.com): the checkout order itself
 *   carries the products with deposit-reduced line totals and the full amounts in the
 *   line item meta. Follow-up invoices for the remaining balance are child orders
 *   with created_via `wc_deposits` that re-invoice parts of the same sale.
 */

namespace SweetCode\Pixel_Manager;

use WC_Order;

defined('ABSPATH') || exit; // Exit if accessed directly

class Split_Payments {

	/**
	 * The order is a regular order. No special handling.
	 */
	const ROLE_STANDARD = 'standard';

	/**
	 * The order only collects money for a sale that lives on its parent order
	 * (Acowebs deposit and balance legs). The purchase is reported from the parent.
	 */
	const ROLE_PAYMENT_LEG = 'payment_leg';

	/**
	 * The order re-invoices a part of a sale that was already reported when its
	 * parent order was placed (Webtomizer follow-up invoices). Never a purchase.
	 */
	const ROLE_FOLLOW_UP_INVOICE = 'follow_up_invoice';

	private static $instance;

	public static function get_instance() {

		if (is_null(self::$instance)) {
			self::$instance = new self();
		}

		return self::$instance;
	}

	public function __construct() {

		// Server-side purchases: an instalment collector or a follow-up invoice is never
		// a purchase of its own. The parent (Acowebs) respectively the checkout order
		// (Webtomizer) fires the server-side purchase through its own payment hooks.
		// This filter is consumed by the direct CAPI sends, the SSP Purchase Proxy and
		// the Google Ads Conversion Adjustments feed alike.
		add_filter('pmw_skip_s2s_purchase_event', [ $this, 'skip_s2s_purchase_for_non_sales' ], 5, 2);

		// Webtomizer reduces the checkout order's line totals to the deposit amount and
		// keeps the full amounts in the line item meta. Report the sale in full.
		add_filter('pmw_marketing_conversion_value_filter', [ $this, 'uplift_deposit_order_marketing_value' ], 5, 2);
		add_filter('pmw_order_value_total_statistics', [ $this, 'uplift_deposit_order_total_value' ], 5, 2);
		add_filter('pmw_order_value_subtotal_statistics', [ $this, 'uplift_deposit_order_subtotal_value' ], 5, 2);
	}

	/**
	 * Classify the role an order plays in a split-payment structure.
	 *
	 * @param mixed $order
	 * @return string One of the ROLE_* constants.
	 */
	public static function get_order_role( $order ) {

		$role = self::ROLE_STANDARD;

		// Refunds (WC_Order_Refund) are excluded here by design: they extend
		// WC_Abstract_Order, not WC_Order, yet also carry a parent ID.
		if ($order instanceof WC_Order) {

			if ('awcdp_payment' === $order->get_type()) {
				$role = self::is_guarded_payment_leg($order) ? self::ROLE_PAYMENT_LEG : self::ROLE_STANDARD;
			} elseif ('wc_deposits' === $order->get_created_via() && $order->get_parent_id() > 0) {
				$role = self::ROLE_FOLLOW_UP_INVOICE;
			}
		}

		/**
		 * Filter the role an order plays in a split-payment structure.
		 *
		 * Return 'payment_leg' for orders that only collect an instalment for a sale
		 * on their parent order (the purchase is then reported from the parent),
		 * 'follow_up_invoice' for orders that re-invoice a part of an already reported
		 * sale (never a purchase), or 'standard' for regular orders.
		 *
		 * @param string $role  One of 'standard', 'payment_leg', 'follow_up_invoice'.
		 * @param mixed  $order The order being classified.
		 *
		 * @since 1.64.1
		 */
		return apply_filters('pmw_split_payment_order_role', $role, $order);
	}

	/**
	 * Resolve which order the purchase must be reported from on the order-received page.
	 *
	 * @param mixed $order The order the page belongs to.
	 * @return WC_Order|null The order to report, or null when this page must not report a purchase.
	 */
	public static function resolve_order_for_purchase( $order ) {

		$role = self::get_order_role($order);

		if (self::ROLE_PAYMENT_LEG === $role) {
			// The guard in is_guarded_payment_leg() has already verified the parent.
			return wc_get_order($order->get_parent_id());
		}

		if (self::ROLE_FOLLOW_UP_INVOICE === $role) {
			return null;
		}

		return $order instanceof WC_Order ? $order : null;
	}

	/**
	 * Structural guard for Acowebs payment legs.
	 *
	 * The reroute to the parent only happens when the structure looks like what the
	 * plugin produces today: the leg holds no products, and the parent is a real
	 * shop order that does. If a future plugin version changes the structure, the
	 * guard fails and the order is treated as standard, which is today's behavior.
	 *
	 * @param WC_Order $order
	 * @return bool
	 */
	private static function is_guarded_payment_leg( $order ) {

		if (count($order->get_items()) > 0) {
			return false;
		}

		if ($order->get_parent_id() <= 0) {
			return false;
		}

		$parent = wc_get_order($order->get_parent_id());

		if (!$parent instanceof WC_Order) {
			return false;
		}

		if ('shop_order' !== $parent->get_type()) {
			return false;
		}

		if (0 === count($parent->get_items())) {
			return false;
		}

		return true;
	}

	/**
	 * Debug info lines for the split-payment compatibility.
	 *
	 * The counts surface drift: when a deposit plugin creates orders but none of the
	 * recent ones classify into a special role anymore, the plugin's structure has
	 * changed and the compatibility silently fell back to standard handling. That
	 * state should be visible in the first debug dump a customer sends.
	 *
	 * @return string
	 */
	public static function get_debug_info() {

		$html = '';

		if (in_array('awcdp_payment', wc_get_order_types(), true)) {

			$legs       = wc_get_orders([ 'type' => 'awcdp_payment', 'limit' => 20, 'status' => 'any', 'orderby' => 'date', 'order' => 'DESC' ]);
			$recognized = 0;

			foreach ($legs as $leg) {
				if (self::ROLE_PAYMENT_LEG === self::get_order_role($leg)) {
					++$recognized;
				}
			}

			$html .= 'Deposits & Partial Payments (Acowebs): active, ' . count($legs) . ' recent payment orders, ' . $recognized . ' handled as payment legs' . PHP_EOL;
		}

		if (class_exists('WC_Deposits')) {

			$follow_ups = wc_get_orders([ 'created_via' => 'wc_deposits', 'limit' => 20, 'status' => 'any', 'orderby' => 'date', 'order' => 'DESC' ]);
			$recognized = 0;

			foreach ($follow_ups as $follow_up) {
				if (self::ROLE_FOLLOW_UP_INVOICE === self::get_order_role($follow_up)) {
					++$recognized;
				}
			}

			$html .= 'WooCommerce Deposits (Webtomizer): active, ' . count($follow_ups) . ' recent follow-up orders, ' . $recognized . ' handled as follow-up invoices' . PHP_EOL;
		}

		return $html;
	}

	/**
	 * Skip server-side purchase events for orders that are not sales of their own.
	 *
	 * @param bool  $skip
	 * @param mixed $order
	 * @return bool
	 */
	public function skip_s2s_purchase_for_non_sales( $skip, $order ) {

		if ($skip) {
			return $skip;
		}

		$role = self::get_order_role($order);

		if (self::ROLE_STANDARD !== $role) {
			Logger::debug('Split payments: skipping the server-side purchase for order ' . $order->get_id() . ' (' . $role . ')');
			return true;
		}

		return $skip;
	}

	/**
	 * Return the amount by which a Webtomizer deposit order's line totals fall short
	 * of the full sale, excluding tax. 0 for all other orders.
	 *
	 * @param mixed $order
	 * @param bool  $including_tax
	 * @return float
	 */
	public static function get_deposit_order_uplift( $order, $including_tax = false ) {

		if (!$order instanceof WC_Order) {
			return 0.0;
		}

		// Only the checkout order carries deposit line items. The follow-up invoices
		// do not, so they can never be uplifted, and neither can Acowebs orders.
		$uplift = 0.0;

		foreach ($order->get_items() as $item) {

			if ('yes' !== $item->get_meta('_is_deposit', true)) {
				continue;
			}

			if ($including_tax) {
				$full    = (float) $item->get_meta('_deposit_full_amount', true);
				$charged = (float) $item->get_total() + (float) $item->get_total_tax();
			} else {
				$full    = (float) $item->get_meta('_deposit_full_amount_ex_tax', true);
				$charged = (float) $item->get_subtotal();
			}

			if ($full > $charged) {
				$uplift += $full - $charged;
			}
		}

		return (float) $uplift;
	}

	/**
	 * Report the full sale value to the marketing pixels for deposit orders.
	 *
	 * The uplift excludes tax for the subtotal and the profit margin logic, and
	 * includes it for the order total logic, matching what each base contains.
	 *
	 * @param float $value
	 * @param mixed $order
	 * @return float
	 */
	public function uplift_deposit_order_marketing_value( $value, $order ) {

		$including_tax = in_array(Options::get_options_obj()->shop->order_total_logic, [ '1', 'order_total' ], true);

		return $value + self::get_deposit_order_uplift($order, $including_tax);
	}

	/**
	 * Report the full sale value to the statistics pixels for deposit orders.
	 *
	 * @param float $value
	 * @param mixed $order
	 * @return float
	 */
	public function uplift_deposit_order_total_value( $value, $order ) {
		return $value + self::get_deposit_order_uplift($order, true);
	}

	/**
	 * Report the full sale subtotal to the statistics pixels for deposit orders.
	 *
	 * @param float $value
	 * @param mixed $order
	 * @return float
	 */
	public function uplift_deposit_order_subtotal_value( $value, $order ) {
		return $value + self::get_deposit_order_uplift($order, false);
	}
}
