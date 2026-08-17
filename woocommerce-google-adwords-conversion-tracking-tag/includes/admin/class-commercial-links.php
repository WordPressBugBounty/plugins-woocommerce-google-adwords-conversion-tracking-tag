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

	// Our own public pricing / checkout pages. They are the fallback whenever
	// Freemius' in-dashboard pages are not available (see
	// has_freemius_dashboard_pages()).
	const SC_PRICING_URL = 'https://sweetcode.com/pricing';
	const SC_TRIAL_URL   = 'https://sweetcode.com/plugins/pmw/?open-checkout=&trial=&billing-cycle=annual&utm_source=plugin&utm_medium=start-free-trial-button&utm_campaign=freemius-pages-unavailable#pricing-section';

	/**
	 * Whether Freemius' own in-dashboard pages (Pricing, Account) actually exist
	 * on this install.
	 *
	 * The SDK hands out admin URLs for those pages unconditionally
	 * (get_upgrade_url(), get_trial_url(), get_account_url()), but it only
	 * REGISTERS the pages once the plugin has left activation mode, meaning once
	 * the site has been connected to Freemius or a license is active. A premium
	 * build whose license expired or was removed, and a build that was never
	 * opted in, both sit in activation mode. Following the URL then lands on a
	 * page WordPress knows nothing about, and the shop admin gets a bare
	 * "Sorry, you are not allowed to access this page." 403 instead of the
	 * checkout. Demo mode and white-labelled installs drop the pages as well.
	 *
	 * @return bool
	 *
	 * @since 1.64.1
	 */
	public static function has_freemius_dashboard_pages() {

		if (!function_exists('wpm_fs')) {
			return false;
		}

		if (defined('WP_FS__DEMO_MODE') && WP_FS__DEMO_MODE) {
			return false;
		}

		$fs = wpm_fs();

		return !$fs->is_activation_mode() && !$fs->is_whitelabeled();
	}

	/**
	 * Upgrade / upsell / pricing / checkout call-to-action target.
	 *
	 * @return string
	 */
	public static function upgrade_url() {

		if (Helpers::is_pmw_wcm_distro()) {
			return self::WC_PRODUCT_URL;
		}

		if (self::has_freemius_dashboard_pages()) {
			return wpm_fs()->get_upgrade_url();
		}

		return self::SC_PRICING_URL;
	}

	/**
	 * Free trial call-to-action target.
	 *
	 * @return string
	 *
	 * @since 1.64.1
	 */
	public static function trial_url() {

		if (Helpers::is_pmw_wcm_distro()) {
			return self::WC_PRODUCT_URL;
		}

		if (self::has_freemius_dashboard_pages()) {
			return (string) wpm_fs()->get_trial_url();
		}

		return self::SC_TRIAL_URL;
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

		if (self::has_freemius_dashboard_pages()) {
			return wpm_fs()->get_account_url();
		}

		return self::SC_PRICING_URL;
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
