<?php

namespace SweetCode\Pixel_Manager\Admin;

use SweetCode\Pixel_Manager\Helpers;

defined('ABSPATH') || exit; // Exit if accessed directly

/**
 * Single source of truth for commercial links (upgrade, upsell, license,
 * account, support, vendor), resolved per distribution.
 *
 * Why this exists:
 * WooCommerce.com forbids external upsell / affiliate / checkout links in
 * Marketplace plugins. The 'wcm' distribution must therefore point EVERY
 * commercial call-to-action at the WooCommerce.com product, account, support or
 * vendor page. Only documentation links may stay on sweetcode.com (see
 * Documentation / data/docs.ts).
 *
 * This is the ONLY place a commercial host is decided. Do not hardcode a
 * sweetcode.com (or woocommerce.com) commercial URL anywhere else:
 *  - PHP call sites call these methods (or branch on Helpers::is_pmw_wcm_distro()).
 *  - The Nova React app NEVER hardcodes a commercial URL; it reads the values
 *    this class injects into the pmwAdminApi payload (Admin::output_wp_admin_ui).
 *    The compiled JS bundle is copied verbatim into every distribution and no
 *    build step can rewrite it, so a hardcoded host there leaks to woocommerce.com.
 *
 * A build-time guard (gulpfile.js after_build_checks) fails the build if a
 * commercial sweetcode.com URL is found in the compiled Nova bundle.
 *
 * @since 1.59.3
 */
class Commercial_Links {

	// WooCommerce.com destinations for the wcm distribution. Affiliate links are
	// not allowed, so these are plain product/account/support/vendor pages.
	const WC_PRODUCT_URL = 'https://woocommerce.com/products/pixel-manager-pro-for-woocommerce/';
	const WC_ACCOUNT_URL = 'https://woocommerce.com/my-account/my-subscriptions/';
	const WC_SUPPORT_URL = 'https://woocommerce.com/my-account/create-a-ticket/';
	const WC_VENDOR_URL  = 'https://woocommerce.com/vendor/sweetcode/';

	// WooCommerce.com's feature-request board for our product. The wcm
	// distribution may not link out to our own site to collect a feature
	// request, so "request a tracking pixel" points here instead of
	// pmw.sweetcode.com.
	const WC_FEATURE_REQUEST_URL = 'https://woocommerce.com/feature-requests/pixel-manager-pro-for-woocommerce/';

	/**
	 * Upgrade / upsell / pricing / checkout call-to-action target.
	 *
	 * @return string
	 */
	public static function upgrade_url() {

		if (Helpers::is_pmw_wcm_distro()) {
			return self::WC_PRODUCT_URL;
		}

		if (function_exists('wpm_fs')) {
			return wpm_fs()->get_upgrade_url();
		}

		return 'https://sweetcode.com/pricing';
	}

	/**
	 * Pro account / license / billing management target.
	 *
	 * @return string
	 */
	public static function account_url() {

		if (Helpers::is_pmw_wcm_distro()) {
			return self::WC_ACCOUNT_URL;
		}

		if (function_exists('wpm_fs')) {
			return wpm_fs()->get_account_url();
		}

		return 'https://sweetcode.com/pricing';
	}

	/**
	 * Support request target.
	 *
	 * @return string
	 */
	public static function support_url() {

		if (Helpers::is_pmw_wcm_distro()) {
			return self::WC_SUPPORT_URL;
		}

		return 'https://sweetcode.com/support';
	}

	/**
	 * Vendor / brand link target (e.g. the "Profit Driven Marketing" promo).
	 *
	 * @return string
	 */
	public static function vendor_url() {

		if (Helpers::is_pmw_wcm_distro()) {
			return self::WC_VENDOR_URL;
		}

		return 'https://sweetcode.com';
	}

	/**
	 * "Request a tracking pixel" target.
	 *
	 * Freemius / standalone builds open our own request form on
	 * pmw.sweetcode.com, with a little context appended so a request can be
	 * triaged: plugin version (v), distribution (distro: fms|wcm) and live
	 * license state (tier: pro|free). The wcm distribution may not link out to our site to
	 * collect a feature request, so it uses WooCommerce.com's feature-request
	 * board instead — and gets no params, as we don't decorate WooCommerce URLs.
	 *
	 * @return string
	 */
	public static function pixel_request_url() {

		if (Helpers::is_pmw_wcm_distro()) {
			return self::WC_FEATURE_REQUEST_URL;
		}

		$params = [
			'utm_source' => 'pmw-plugin',
			'v'          => PMW_CURRENT_VERSION,
			'distro'     => Helpers::get_pmw_distro(),
			'tier'       => Helpers::is_pmw_pro_version_active() ? 'pro' : 'free',
		];

		return add_query_arg(array_filter($params), 'https://pmw.sweetcode.com/request-pixel');
	}
}
