<?php

namespace SweetCode\Pixel_Manager\Admin;

use ActionScheduler_Versions;
use SweetCode\Pixel_Manager\Admin\Notifications\Notifications;
use SweetCode\Pixel_Manager\Geolocation;
use SweetCode\Pixel_Manager\Helpers;
use SweetCode\Pixel_Manager\Options;
use SweetCode\Pixel_Manager\Product;
use SweetCode\Pixel_Manager\Profit_Margin;

defined('ABSPATH') || exit; // Exit if accessed directly

class Environment {

	private static $last_order_id      = null;
	private static $last_order         = null;
	private static $transients_enabled = null;

	public static function is_allowed_notification_page( $page = null ) {

		global $pagenow;

		if (is_null($page)) {
			$page = $pagenow;
		}

		// Don't check for the plugin settings page. Notifications have to be handled there.
		$allowed_pages = [
			'index.php',
			'dashboard',
		];

		foreach ($allowed_pages as $allowed_page) {
			if (strpos($page, $allowed_page) !== false) {
				return true;
			}
		}

		return false;
	}

	public static function is_pmw_settings_page() {

		if (!is_admin()) {
			return false;
		}

		$_get = Helpers::get_input_vars(INPUT_GET);
		$page = isset($_get['page']) ? $_get['page'] : '';

		if ('wpm' !== $page) {
			return false;
		}

		return true;
	}

	public static function is_not_allowed_notification_page( $page = null ) {
		return !self::is_allowed_notification_page($page);
	}

//	public static function run_incompatible_plugins_checks() {
//
//		$saved_notifications = get_option(PMW_DB_NOTIFICATIONS_NAME);
//
//		foreach (self::get_incompatible_plugins_list() as $plugin) {
//
//			// If the plugin is not active, continue
//			if (!is_plugin_active($plugin['file_location'])) {
//				continue;
//			}
//
//			// If a notification has already been saved for this plugin, continue
//			if (
//				is_array($saved_notifications)
//				&& array_key_exists($plugin['slug'], $saved_notifications)
//			) {
//				continue;
//			}
//
//			Notifications::plugin_is_incompatible(
//				$plugin['name'],
//				$plugin['version'],
//				$plugin['slug'],
//				$plugin['link'],
//				$plugin['pmw_doc_link']
//			);
//		}
//	}

	public static function get_incompatible_plugins_list() {
		return [
			'wc-custom-thank-you' => [
				'name'          => 'WC Custom Thank You',
				'slug'          => 'wc-custom-thank-you',
				'file_location' => 'wc-custom-thank-you/woocommerce-custom-thankyou.php',
				'link'          => 'https://wordpress.org/plugins/wc-custom-thank-you/',
				'pmw_doc_link'  => Documentation::get_link('custom_thank_you'),
				'version'       => '1.2.1',
			],
		];
	}

	public static function purge_cache_on_plugin_changes() {

		// Purge cache after saving the plugin options
		// update_option_ only runs if the option has changed
		add_action('update_option_' . PMW_DB_OPTIONS_NAME, [ __CLASS__, 'purge_entire_cache' ], 10, 3);
		add_action('add_option_' . PMW_DB_OPTIONS_NAME, [ __CLASS__, 'purge_entire_cache' ], 10, 3);

		// Purge cache after install
		// we don't need that because after first install the user needs to set new options anyway where the cache purge happens too
//        add_filter('upgrader_post_install', [__CLASS__, 'purge_cache_of_all_cache_plugins'], 10, 3);

		// Purge cache after plugin update
		add_action('upgrader_process_complete', [ __CLASS__, 'upgrader_purge_cache_if_pmw_was_updated' ], 10, 2);
	}

	public static function upgrader_purge_cache_if_pmw_was_updated( $upgrader_object, $options ) {

		if (
			isset($options['type']) &&
			'plugin' === $options['type'] &&
			isset($options['plugins']) &&
			is_array($options['plugins']) &&
			in_array(PMW_PLUGIN_BASENAME, $options['plugins'], true)
		) {
			self::purge_entire_cache();
		}
	}

	/**
	 * Tries to purge all cache layers.
	 * The order is relevant, so we must make sure that the content is purged like a waterfall
	 * from the closest layer to the farthest.
	 *
	 * @return void
	 */
	public static function purge_entire_cache() {

		/**
		 * Purge the first cache layer.
		 * WordPress cache plugins.
		 * If a plugin does both first and second layer caching, then put it here.
		 */
		self::purge_first_layer_cache();

		/**
		 * Purge the second cache layer.
		 * Hosts like WP Engine that have their own cache layer.
		 */
		self::purge_second_layer_cache();

		/**
		 * Purge the third cache layer.
		 * External cache like Cloudflare.
		 */
		self::purge_third_layer_cache();
	}

	private static function purge_first_layer_cache() {

		if (self::is_wp_rocket_active()) {
			self::purge_wp_rocket_cache();
		}                                                                              // works
		if (self::is_litespeed_active()) {
			self::purge_litespeed_cache();
		}                                                                              // works
		if (self::is_autoptimize_active()) {
			self::purge_autoptimize_cache();
		}                                                                              // works
		if (self::is_hummingbird_active()) {
			self::purge_hummingbird_cache();
		}                                                                              // works
		if (self::is_nitropack_active()) {
			self::purge_nitropack_cache();
		}                                                                              // works
		if (self::is_w3_total_cache_active()) {
			self::purge_w3_total_cache();
		}                                                                              // works
		if (self::is_wp_optimize_active()) {
			self::purge_wp_optimize_cache();
		}                                                                              // works
		if (self::is_wp_super_cache_active()) {
			self::purge_wp_super_cache();
		}                                                                              // works
		if (self::is_wp_fastest_cache_active()) {
			self::purge_wp_fastest_cache();
		}                                                                              // works
		if (self::is_flying_press_active()) {
			self::purge_flying_press_cache();
		}
		// Delete the Real Cookie Banner cache if it exists
		if (function_exists('wp_rcb_invalidate_templates_cache')) {
			wp_rcb_invalidate_templates_cache();
		}
	}

	/**
	 * Purge the second layer cache.
	 * These are hosts that have their own caching layer.
	 *
	 * @return void
	 */
	private static function purge_second_layer_cache() {

		if (self::is_sg_optimizer_active()) {
			self::purge_sg_optimizer_cache();
		}                                                                           // works

		if (self::is_hosting_wp_engine()) {
			self::purge_wp_engine_cache();
		}                                                                           // works

		if (self::is_hosting_kinsta()) {
			self::purge_kinsta_cache();
		}                                                                           // TODO test

		if (self::is_nginx_helper_active()) {
			self::purge_nginx_helper_cache();
		}                                                                           // TODO test

		if (self::is_proxy_cache_purge_active()) {
			self::purge_proxy_cache_purge_cache();
		}                                                                           // TODO test

		//        if (self::is_hosting_pagely()) $this->purge_pagely_cache();		// TODO test

		// TODO add generic varnish purge
	}

	/**
	 * Purge the third layer cache.
	 * These are external services like Cloudflare.
	 *
	 * @return void
	 */
	private static function purge_third_layer_cache() {

		if (self::is_cloudflare_active()) {
			self::purge_cloudflare_cache();
		}                                                                              // works
	}

	private static function purge_kinsta_cache() {
		try {
			wp_remote_get('https://localhost/kinsta-clear-cache-all', [
				'sslverify' => !Geolocation::is_localhost(),
				'timeout'   => 5,
			]);

		} catch (\Exception $e) {
			error_log($e);
		}
	}

	public static function is_nginx_helper_active() {
		return defined('NGINX_HELPER_BASEPATH');
	}

	private static function is_proxy_cache_purge_active() {
		return defined('VHP_VARNISH_IP');
	}

	/**
	 * Purge the Nginx Helper cache.
	 * Can be Nginx or Redis.
	 *
	 * @return void
	 */
	private static function purge_nginx_helper_cache() {

		global $nginx_purger;

		if (
			$nginx_purger
			&& method_exists($nginx_purger, 'purge_all')
		) {
			$nginx_purger->purge_all();
		}
	}

	/**
	 * Purge the Proxy Cache Purge cache.
	 *
	 * @return void
	 */
	private static function purge_proxy_cache_purge_cache() {
		try {
			if (class_exists('\VarnishPurger')) {
				$varnishPurger = new \VarnishPurger();
				if (method_exists($varnishPurger, 'execute_purge')) {
					$varnishPurger->execute_purge();
				}
			}
		} catch (\Exception $e) {
			error_log($e);
		}
	}

	public static function purge_cloudflare_cache() {
		try {
			if (class_exists('\CF\WordPress\Hooks')) {
				( new \CF\WordPress\Hooks() )->purgeCacheEverything();
			}
		} catch (\Exception $e) {
			error_log($e);
		}
	}

	public static function purge_flying_press_cache() {
		try {
			if (class_exists('\FlyingPress\Purge') && method_exists('\FlyingPress\Purge', 'purge_cached_pages')) {
				\FlyingPress\Purge::purge_cached_pages();
			}
		} catch (\Exception $e) {
			error_log($e);
		}
	}

	public static function purge_wp_engine_cache() {
		try {
			if (class_exists('WpeCommon')) {
				\WpeCommon::purge_varnish_cache_all();
			}
		} catch (\Exception $e) {
			error_log($e);
		}
	}

	private static function purge_pagely_cache() {
		try {
			if (class_exists('PagelyCachePurge')) { // We need to have this check for clients that switch hosts
				$pagely = new \PagelyCachePurge();
				$pagely->purgeAll();
			}
		} catch (\Exception $e) {
			error_log($e);
		}
	}

	public static function purge_wp_fastest_cache() {
		if (function_exists('wpfc_clear_all_cache')) {
			wpfc_clear_all_cache(true);
		}
	}

	public static function purge_wp_super_cache() {
		if (function_exists('wp_cache_clean_cache')) {
			global $file_prefix;
			wp_cache_clean_cache($file_prefix, true);
		}
	}

	public static function purge_wp_optimize_cache() {
		if (function_exists('wpo_cache_flush')) {
			wpo_cache_flush();
		}
	}

	public static function purge_w3_total_cache() {
		if (function_exists('w3tc_flush_all')) {
			w3tc_flush_all();
		}
	}

	public static function purge_sg_optimizer_cache() {
		if (function_exists('sg_cachepress_purge_everything')) {
			sg_cachepress_purge_everything();
		}
	}

	public static function purge_nitropack_cache() {
		try {
			if (class_exists('\NitroPack\SDK\Api\Cache')) {
				$siteId     = get_option('nitropack-siteId');
				$siteSecret = get_option('nitropack-siteSecret');
				( new \NitroPack\SDK\Api\Cache($siteId, $siteSecret) )->purge();
			}

		} catch (\Exception $e) {
			error_log($e);
		}

//        do_action('nitropack_integration_purge_all');
	}

	public static function purge_hummingbird_cache() {
		do_action('wphb_clear_page_cache');
	}

	public static function purge_autoptimize_cache() {
		if (class_exists('autoptimizeCache')) {
			// we need the backslash because autoptimizeCache is in the global namespace
			// and otherwise our plugin would search in its own namespace and throw an error
			\autoptimizeCache::clearall();
		}
	}

	public static function purge_litespeed_cache() {
		do_action('litespeed_purge_all');
	}

	protected static function purge_wp_rocket_cache() {
		// Purge WP Rocket cache
		if (function_exists('rocket_clean_domain')) {
			rocket_clean_domain();
		}

		// Preload cache.
		if (function_exists('run_rocket_bot')) {
			run_rocket_bot();
		}

		if (function_exists('run_rocket_sitemap_preload')) {
			run_rocket_sitemap_preload();
		}
	}

	public static function run_checks() {
//        $this->check_wp_rocket_js_concatenation();
//        $this->check_litespeed_js_inline_after_dom();
	}

	/**
	 * Checks to find out if certain plugins are active
	 */

	public static function is_action_scheduler_active() {
		return function_exists('as_next_scheduled_action');
	}

	public static function is_elementor_pro_active() {
		return is_plugin_active('elementor-pro/elementor-pro.php');
	}

	public static function is_elementor_free_active() {
		return is_plugin_active('elementor/elementor.php');
	}

	public static function is_elementor_active() {
		return self::is_elementor_free_active() || self::is_elementor_pro_active();
	}

	public static function is_gtranslate_active() {
		return is_plugin_active('gtranslate/gtranslate.php');
	}

	public static function is_google_site_kit_active() {
		return is_plugin_active('google-site-kit/google-site-kit.php');
	}

	public static function is_wp_rocket_active() {
		return is_plugin_active('wp-rocket/wp-rocket.php');
	}

	public static function is_sg_optimizer_active() {
		return is_plugin_active('sg-cachepress/sg-cachepress.php');
	}

	public static function is_w3_total_cache_active() {
		return is_plugin_active('w3-total-cache/w3-total-cache.php');
	}

	public static function is_litespeed_active() {
		// TODO find out if there is a pro version with different folder and file name

		return is_plugin_active('litespeed-cache/litespeed-cache.php');
	}

	public static function is_litespeed_esi_active() {

		if (
			defined('LSCWP_V')
			&& apply_filters('litespeed_esi_status', false)
		) {
			return true;
		}

		return false;
	}

	public static function is_autoptimize_active() {
		// TODO find out if there is a pro version with different folder and file name

		return is_plugin_active('autoptimize/autoptimize.php');
	}

	public static function is_hummingbird_active() {
		// TODO find out if there is a pro version with different folder and file name

		return is_plugin_active('hummingbird-performance/wp-hummingbird.php');
	}

	public static function is_nitropack_active() {
		// TODO find out if there is a pro version with different folder and file name

		return is_plugin_active('nitropack/main.php');
	}

	public static function is_yoast_seo_active() {
		// TODO find out if there is a pro version with different folder and file name

		return is_plugin_active('wordpress-seo/wp-seo.php');
	}

	public static function is_borlabs_cookie_active() {
		// TODO find out if there is a pro version with different folder and file name

		return is_plugin_active('borlabs-cookie/borlabs-cookie.php');
	}

	public static function is_cookiebot_active() {
		return is_plugin_active('cookiebot/cookiebot.php');
	}

	public static function is_complianz_active() {
		return is_plugin_active('complianz-gdpr/complianz-gpdr.php') || is_plugin_active('complianz-gdpr-premium/complianz-gpdr-premium.php');
	}

// Cookie Notice by hu-manity.co
	public static function is_cookie_notice_active() {
		return is_plugin_active('cookie-notice/cookie-notice.php');
	}

	public static function is_cookie_script_active() {
		return is_plugin_active('cookie-script-com/cookie-script.php');
	}

	public static function is_wp_cookie_consent_active() {
		return is_plugin_active('gdpr-cookie-consent/gdpr-cookie-consent.php');
	}

	public static function is_freemius_active() {
		return function_exists('wpm_fs');
	}

	public static function is_iubenda_active() {
		return is_plugin_active('iubenda-cookie-law-solution/iubenda_cookie_solution.php');
	}

	public static function is_moove_gdpr_active() {
		return is_plugin_active('gdpr-cookie-compliance/moove-gdpr.php');
	}

	/**
	 * Check if CookieYes is active
	 *
	 * Formerly called Cookie Law Info
	 *
	 * @return bool
	 */
	public static function is_cookieyes_active() {
		return is_plugin_active('cookie-law-info/cookie-law-info.php');
	}

	public static function is_real_cookie_banner_active() {
		return
			is_plugin_active('real-cookie-banner/index.php')
			|| is_plugin_active('real-cookie-banner-pro/index.php');
	}

	public static function is_termly_active() {
		// TODO find out if there is a pro version with different folder and file name and check if uk-cookie-consent-premium is the correct slug

		return
			is_plugin_active('uk-cookie-consent/uk-cookie-consent.php')
			|| is_plugin_active('uk-cookie-consent-premium/uk-cookie-consent-premium.php');
	}

// WooCommerce Cost of Goods
// https://woocommerce.com/products/woocommerce-cost-of-goods/
	public static function is_woocommerce_cog_active() {
		return class_exists('WC_COG') || is_plugin_active('woocommerce-cost-of-goods/woocommerce-cost-of-goods.php');
	}

// Cost of Good for WooCommerce
// https://wordpress.org/plugins/cost-of-goods-for-woocommerce/
	public static function is_cog_for_woocommerce_active() {
		return class_exists('Alg_WC_Cost_of_Goods') || is_plugin_active('cost-of-goods-for-woocommerce/cost-of-goods-for-woocommerce.php');
	}

	public static function is_a_cog_plugin_active() {
		return self::is_woocommerce_cog_active() || self::is_cog_for_woocommerce_active() || Profit_Margin::get_custom_cog_product_meta_key();
	}

	public static function is_some_cmp_active() {
		if (
			self::is_borlabs_cookie_active()
			|| self::is_complianz_active()
			|| self::is_cookiebot_active()
			|| self::is_cookieyes_active()
			|| self::is_cookie_notice_active()
			|| self::is_cookie_script_active()
			|| self::is_moove_gdpr_active()
			|| self::is_real_cookie_banner_active()
			|| self::is_termly_active()
		) {
			return true;
		} else {
			return false;
		}
	}

	public static function is_woocommerce_active() {
		return is_plugin_active('woocommerce/woocommerce.php');
	}

	public static function is_wp_super_cache_active() {
		// TODO find out if there is a pro version with different folder and file name

		return is_plugin_active('wp-super-cache/wp-cache.php');
	}

	public static function is_wp_fastest_cache_active() {
		// The pro version requires the free version to be active

		return is_plugin_active('wp-fastest-cache/wpFastestCache.php');
	}

	public static function is_cloudflare_active() {
		return is_plugin_active('cloudflare/cloudflare.php');
	}

	public static function is_wpml_woocommerce_multi_currency_active() {
		global $woocommerce_wpml;

		if (
			is_plugin_active('woocommerce-multilingual/wpml-woocommerce.php') &&
			is_object($woocommerce_wpml->multi_currency)
		) {
			return true;
		} else {
			return false;
		}
	}

	public static function is_woo_discount_rules_active() {
		return
			is_plugin_active('woo-discount-rules/woo-discount-rules.php') ||
			is_plugin_active('woo-discount-rules-pro/woo-discount-rules-pro.php');
	}

	public static function is_woofunnels_active() {
		return
			is_plugin_active('funnel-builder/funnel-builder.php') ||
			is_plugin_active('funnel-builder-pro/funnel-builder-pro.php');
	}

	public static function is_woo_product_feed_active() {
		return
			is_plugin_active('woo-product-feed-pro/woocommerce-sea.php') ||
			is_plugin_active('woo-product-feed-elite/woocommerce-sea.php');
	}

	public static function is_wp_optimize_active() {
		return is_plugin_active('wp-optimize/wp-optimize.php');
	}

	public static function is_woocommerce_brands_active() {
		return is_plugin_active('woocommerce-brands/woocommerce-brands.php');
	}

	public static function is_woocommerce_subscriptions_active() {
		return is_plugin_active('woocommerce-subscriptions/woocommerce-subscriptions.php');
	}

	public static function is_yith_wc_brands_active() {
		return is_plugin_active('yith-woocommerce-brands-add-on-premium/init.php');
	}

	public static function is_optimocha_active() {
		// TODO find out if there is a pro version with different folder and file name
		return is_plugin_active('speed-booster-pack/speed-booster-pack.php');
	}

	public static function is_async_javascript_active() {
		// TODO find out if there is a pro version with different folder and file name
		return is_plugin_active('async-javascript/async-javascript.php');
	}

	public static function is_flying_press_active() {
		// TODO find out if there is a pro version with different folder and file name
		return is_plugin_active('flying-press/flying-press.php');
	}

	/*
	 * Check to find out what hosting provider is being used
	 * */

	public static function is_hosting_flywheel() {
		return defined('FLYWHEEL_PLUGIN_DIR');
	}

	public static function is_hosting_cloudways() {

		$_server = Helpers::get_input_vars(INPUT_SERVER);

		if ($_server && array_key_exists('cw_allowed_ip', $_server)) {
			return true;
		} elseif (preg_match('~/home/.*?cloudways.*~', __FILE__)) {
			return true;
		} else {
			return false;
		}
	}

	public static function is_hosting_wp_engine() {
		return (bool) getenv('IS_WPE');
	}

	public static function is_hosting_godaddy_wpaas() {
		return class_exists('\WPaaS\Plugin');
	}

	public static function is_hosting_siteground() {
		$configFilePath = self::get_wpconfig_path();
		if (!$configFilePath) {
			return false;
		}
		return strpos(file_get_contents($configFilePath), 'Added by SiteGround WordPress management system') !== false;
	}

	public static function is_hosting_gridpane() {
		$configFilePath = self::get_wpconfig_path();
		if (!$configFilePath) {
			return false;
		}
		return strpos(file_get_contents($configFilePath), 'GridPane Cache Settings') !== false;
	}

	public static function is_hosting_kinsta() {
		return defined('KINSTAMU_VERSION');
	}

	public static function is_hosting_closte() {
		return defined('CLOSTE_APP_ID');
	}

	public static function is_hosting_pagely() {
		return class_exists('\PagelyCachePurge');
	}

	public static function get_hosting_provider() {
		if (self::is_hosting_flywheel()) {
			return 'Flywheel';
		} elseif (self::is_hosting_cloudways()) {
			return 'Cloudways';
		} elseif (self::is_hosting_wp_engine()) {
			return 'WP Engine';
		} elseif (self::is_hosting_siteground()) {
			return 'SiteGround';
		} elseif (self::is_hosting_godaddy_wpaas()) {
			return 'GoDaddy WPaas';
		} elseif (self::is_hosting_gridpane()) {
			return 'GridPane';
		} elseif (self::is_hosting_kinsta()) {
			return 'Kinsta';
		} elseif (self::is_hosting_closte()) {
			return 'Closte';
		} elseif (self::is_hosting_pagely()) {
			return 'Pagely';
		} else {
			return 'unknown';
		}
	}

// https://github.com/wp-cli/wp-cli/blob/c3bd5bd76abf024f9d492579539646e0d263a05a/php/utils.php#L257
	public static function get_wpconfig_path() {
		static $path;

		if (null === $path) {
			$path = false;

			if (getenv('WP_CONFIG_PATH') && file_exists(getenv('WP_CONFIG_PATH'))) {
				$path = getenv('WP_CONFIG_PATH');
			} elseif (file_exists(ABSPATH . 'wp-config.php')) {
				$path = ABSPATH . 'wp-config.php';
			} elseif (file_exists(dirname(ABSPATH) . '/wp-config.php') && !file_exists(dirname(ABSPATH) . '/wp-settings.php')) {
				$path = dirname(ABSPATH) . '/wp-config.php';
			}

			if ($path) {
				$path = realpath($path);
			}
		}

		return $path;
	}

	public static function disable_yoast_seo_facebook_social( $option ) {
		$option['opengraph'] = false;
		return $option;
	}

	public static function disable_litespeed_js_inline_after_dom( $option ) {
		return 0;
	}

	public static function wp_optimize_minify_default_exclusions( $default_exclusions ) {
		// $default_exclusions[] = 'something/else.js';
		// $default_exclusions[] = 'something/else.css';
		return array_unique(array_merge($default_exclusions, self::get_pmw_script_identifiers()));
	}

// https://github.com/futtta/autoptimize/blob/37b13d4e19269bb2f50df123257de51afa37244f/classes/autoptimizeScripts.php#L387
	public static function autoptimize_filter_js_consider_minified() {
		$exclude_js[] = 'wpm.min.js';
		$exclude_js[] = 'wpm.min.js';

		$exclude_js[] = 'wpm-public.p1.min.js';
		$exclude_js[] = 'wpm-public__premium_only.p1.min.js';

		$exclude_js[] = 'wpm-public.p2.min.js';
		$exclude_js[] = 'wpm-public__premium_only.p2.min.js';

//        $exclude_js[] = 'jquery.js';
//        $exclude_js[] = 'jquery.min.js';
		return $exclude_js;
	}

// https://github.com/futtta/autoptimize/blob/37b13d4e19269bb2f50df123257de51afa37244f/classes/autoptimizeScripts.php#L285
	public static function autoptimize_filter_js_dontmove( $dontmove ) {
		$dontmove[] = 'wpm.js';
		$dontmove[] = 'wpm.min.js';

		$dontmove[] = 'wpm-public.p1.min.js';
		$dontmove[] = 'wpm-public__premium_only.p1.min.js';

		$dontmove[] = 'wpm-public.p2.min.js';
		$dontmove[] = 'wpm-public__premium_only.p2.min.js';

		$dontmove[] = 'jquery.js';
		$dontmove[] = 'jquery.min.js';
		return $dontmove;
	}

	public static function litespeed_optm_cssjs( $excludes ) {
		return $excludes;
	}

	public static function litespeed_optimize_js_excludes( $excludes ) {
		if (is_array($excludes)) {
			$excludes = array_unique(array_merge($excludes, self::get_pmw_script_identifiers()));
		}

		return $excludes;
	}

	public static function litespeed_cache_js_defer_exc( $excludes ) {
		if (is_array($excludes)) {
			$excludes = array_unique(array_merge($excludes, self::get_pmw_script_identifiers()));
		}
		return $excludes;
	}

	public static function sg_optimizer_js_exclude_combine_inline_content( $exclude_list ) {
		if (is_array($exclude_list)) {
			$exclude_list = array_unique(array_merge($exclude_list, self::get_pmw_script_identifiers()));
		}

//        foreach (self::get_pmw_script_identifiers() as $exclusion) {
//            $exclude_list[] = $exclusion;
//        }

		return $exclude_list;
	}

	public static function sg_optimizer_js_minify_exclude( $exclude_list ) {

		$exclude_list[] = 'wpm-front-end-scripts';
		$exclude_list[] = 'wpm-front-end-scripts-premium-only';
		$exclude_list[] = 'wpm';
		$exclude_list[] = 'wpm-admin';
		$exclude_list[] = 'wpm-premium-only';
		$exclude_list[] = 'wpm-facebook';
		$exclude_list[] = 'wpm-script-blocker-warning';
		$exclude_list[] = 'wpm-admin-helpers';
		$exclude_list[] = 'wpm-admin-tabs';
		$exclude_list[] = 'wpm-selectWoo';
		$exclude_list[] = 'wpm-google-ads';
		$exclude_list[] = 'wpm-ga-ua-eec';
		$exclude_list[] = 'wpm-ga4-eec';

		$exclude_list[] = 'jquery';
		$exclude_list[] = 'jquery-core';
		$exclude_list[] = 'jquery-migrate';

		return $exclude_list;
	}

	public static function sgo_javascript_combine_exclude_move_after( $exclude_list ) {

		if (is_array($exclude_list)) {
			$exclude_list = array_unique(array_merge($exclude_list, self::get_pmw_script_identifiers()));
		}

		return $exclude_list;
	}

	public static function add_wp_rocket_exclusions( $exclusions ) {
		if (is_array($exclusions)) {
			$exclusions = array_unique(array_merge($exclusions, self::get_pmw_script_identifiers()));
		}

		return $exclusions;
	}


// works for WP Rocket >= 3.9
	public static function exclude_inline_scripts_from_wp_rocket_using_options() {
		$options = get_option('wp_rocket_settings');

		// if no options array could be retrieved.
		if (!is_array($options)) {
			return;
		}

		$update_options = false;

		$js_to_exclude = self::get_pmw_script_identifiers();

		foreach ($js_to_exclude as $string) {

			// add exclusions for inline js
//            if (array_key_exists('exclude_inline_js', $options) && is_array($options['exclude_inline_js']) && !in_array($string, $options['exclude_inline_js'])) {
//
//                array_push($options['exclude_inline_js'], $string);
//                $update_options = true;
//            }

			// add exclusions for js
//            if (array_key_exists('exclude_js', $options) && is_array($options['exclude_js']) && !in_array($string, $options['exclude_js'])) {
//
//                array_push($options['exclude_js'], $string);
//                $update_options = true;
//            }

			// remove scripts from delay_js_scripts
			if (array_key_exists('delay_js_scripts', $options) && is_array($options['delay_js_scripts']) && in_array($string, $options['delay_js_scripts'])) {

				unset($options['delay_js_scripts'][array_search($string, $options['delay_js_scripts'])]);
				$update_options = true;
			}

			// exclude_defer_js
//            if (array_key_exists('exclude_defer_js', $options) && is_array($options['exclude_defer_js']) && !in_array($string, $options['exclude_defer_js'])) {
//
//                array_push($options['exclude_defer_js'], $string);
//                $update_options = true;
//            }

			// exclude_delay_js
//            if (array_key_exists('delay_js_exclusions', $options) && is_array($options['delay_js_exclusions']) && !in_array($string, $options['delay_js_exclusions'])) {
//
//                array_push($options['delay_js_exclusions'], $string);
//                $update_options = true;
//            }
		}

		if (true === $update_options) {
			update_option('wp_rocket_settings', $options);
		}
	}

	/**
	 * Third party plugin tweaks
	 *
	 * @return void
	 */
	public static function third_party_plugin_tweaks() {

		/**
		 * Complianz
		 */
		if (self::is_complianz_active()) {

			// Try to disable blocking of inline PMW configuration scripts
			add_filter('cmplz_whitelisted_script_tags', function ( $tags ) {
				$tags[] = 'wpmDataLayer';
				$tags[] = 'pmwDataLayer';
				return $tags;
			});
		}

		/**
		 * Cookiebot
		 *
		 * Disable the Cookiebot Google Consent Mode if the Google Consent Mode is active in PMW
		 */

		if (self::is_cookiebot_active() && Options::is_google_consent_mode_active()) {
			add_filter('option_cookiebot-gcm', '__return_false');
		}

		/**
		 * WP Cookie Consent
		 *
		 * Disable the auto script blocker.
		 *
		 * Source: https://wordpress.org/plugins/gdpr-cookie-consent/
		 */

		if (self::is_wp_cookie_consent_active()) {
			add_filter('option_wpl_options_custom-scripts', [ __CLASS__, 'add_wpl_options_custom_scripts' ]);
			add_filter('default_option_wpl_options_custom-scripts', [ __CLASS__, 'add_wpl_options_custom_scripts' ]);
		}

		/**
		 * WooCommerce Google Ads Dynamic Remarketing
		 */

		self::disable_woocommerce_google_ads_dynamic_remarketing();

		/**
		 * SiteGround Optimizer
		 */

		if (self::is_sg_optimizer_active()) {

			/**
			 * The function wpmFunctionExists needs to be excluded from combination from SGO.
			 * Otherwise, it won't work on pages which include WPM shortcodes.
			 * */

			add_filter('sgo_javascript_combine_excluded_inline_content', function ( $excluded_scripts ) {
				$excluded_scripts[] = 'wpmFunctionExists';
				return $excluded_scripts;
			});

			/**
			 * SGO's defer feature doesn't queue jQuery correctly on some pages,
			 * leading to errors "jQuery not defined" errors on several pages
			 * and thus breaking tracking in those cases.
			 *
			 * Therefore, we need to exclude jquery-core from deferring.
			 * */

			add_filter('sgo_js_async_exclude', function ( $excludes ) {
				$excludes[] = 'jquery-core';
				return $excludes;
			});
		}

		/**
		 * Litespeed
		 */

		if (self::is_litespeed_active()) {
			add_filter('litespeed_optimize_js_excludes', function ( $excludes ) {
				if (is_array($excludes)) {
					$excludes[] = 'wpmFunctionExists';
				}

				return $excludes;
			});

			do_action('litespeed_nonce', 'ajax-nonce');
			do_action('litespeed_nonce', 'wp_rest');
			do_action('litespeed_nonce', 'nonce-pmw-ajax');
		}

		/**
		 * WooFunnels
		 */

		if (self::is_woofunnels_active()) {
			// We need to check so early that is_admin() is not working yet
			$_server = Helpers::get_input_vars(INPUT_SERVER);

			// Only run if REQUEST_URI is available and only if we are not on the WooFunnels settings page
			if (isset($_server['REQUEST_URI']) && strpos($_server['REQUEST_URI'], 'woofunnels-admin') === false) {
				self::disable_woofunnels_features();
			}
		}

		/**
		 * Woo Product Feed
		 */

		if (self::is_woo_product_feed_active()) {
			// We need to check so early that is_admin() is not working yet
			$_server = Helpers::get_input_vars(INPUT_SERVER);

			// Only run if REQUEST_URI is available and only if we are not on the Woo Product Feed settings page
			if (
				isset($_server['REQUEST_URI']) &&
				(
					strpos($_server['REQUEST_URI'], 'woosea_manage_settings') === false &&
					strpos($_server['REQUEST_URI'], 'woosea_elite_manage_settings') === false
				)
			) {
				self::disable_woo_product_feed_features();
			}
		}

		/**
		 * Google Listing and Ads
		 *
		 * Disable gtag if Google Ads is active in PMW
		 */

		if (Options::is_google_ads_active()) {
			add_filter('woocommerce_gla_disable_gtag_tracking', '__return_true');
		}

		/**
		 * Facebook for WooCommerce
		 */

		if (Options::is_facebook_active()) {

			// Disable the Facebook Pixel in the Facebook for WooCommerce plugin
			add_filter('facebook_for_woocommerce_integration_pixel_enabled', '__return_false');

			// Override the product identifier uploaded by the Facebook for WooCommerce plugin
			// to the Facebook catalog with the PMW product identifier
			add_filter('wc_facebook_fb_retailer_id', function ( $fb_retailer_id, $product ) {
				return Product::get_dyn_r_id_for_product_by_pixel_name($product, 'facebook');
			}, 10, 2);
		}

		/**
		 * Pinterest for WooCommerce
		 */

		if (Options::is_pinterest_active()) {
			add_filter('woocommerce_pinterest_disable_tracking', '__return_true');
		}

		/**
		 * Disable the WooCommerce Google Analytics Integration if Google Analytics is active in PMW
		 *
		 * The WooCommerce Google Analytics Integration now is updated to use gtag.js for GA4. If PMW users want to use PMW for GA3 and the WooCommerce Google Analytics Integration for GA4 then we can't disable the WooCommerce Google Analytics Integration.
		 *
		 * We only disable the WooCommerce Google Analytics Integration if both, GA3 and GA4 are active in PMW.
		 */

		if (Options::is_google_analytics_active()) {
			add_filter('woocommerce_ga_disable_tracking', '__return_true');
		}

		/**
		 * Disable WP Rocket lazy load for the PMW lazy load script
		 */

		if (self::is_wp_rocket_active() && Options::is_lazy_load_pmw_active()) {

			add_filter('rocket_delay_js_exclusions', [ __CLASS__, 'exclude_pmw_lazy_from_wp_rocket' ]);
			add_filter('rocket_defer_inline_exclusions', [ __CLASS__, 'exclude_pmw_lazy_from_wp_rocket' ]);
			add_filter('rocket_exclude_defer_js', [ __CLASS__, 'exclude_pmw_lazy_from_wp_rocket' ]);
			add_filter('rocket_exclude_js', [ __CLASS__, 'exclude_pmw_lazy_from_wp_rocket' ]);
			add_filter('rocket_minify_excluded_external_js', [ __CLASS__, 'exclude_pmw_lazy_from_wp_rocket' ]);
			add_filter('rocket_excluded_inline_js_content', [ __CLASS__, 'exclude_pmw_lazy_from_wp_rocket' ]);
		}

		/**
		 * If the Real Cookie Banner is active we need to disable the script output for several cookies.
		 */
		if (self::is_real_cookie_banner_active()) {

			add_action('RCB/Templates/TechnicalHandlingIntegration', function ( $integration ) {

				$tag_names = [
					'bing-ads'        => 'bing-ads',
					'facebook-ads'    => 'facebook-pixel',
					'ga4'             => 'google-analytics-analytics-4',
					'google-ads'      => 'google-ads-conversion-tracking',
					'google-optimize' => 'google-optimize',
					'hotjar'          => 'hotjar',
					'pinterest-ads'   => 'pinterest-tag',
					'snapchat-ads'    => 'snapchat',
					'tiktok-ads'      => 'tik-tok-pixel',
					'twitter-ads'     => 'twitter-pixel',
					'reddit-ads'      => 'reddit-pixel',
				];

				$tag_names = apply_filters('pmw_real_cookie_banner_tag_names', $tag_names);

				self::handle_rcb_integration($integration, Options::is_bing_active(), $tag_names['bing-ads']);
				self::handle_rcb_integration($integration, Options::is_facebook_active(), $tag_names['facebook-ads']);
				self::handle_rcb_integration($integration, Options::is_ga4_enabled(), $tag_names['ga4']);
				self::handle_rcb_integration($integration, Options::is_google_ads_active(), $tag_names['google-ads']);
				self::handle_rcb_integration($integration, Options::is_hotjar_enabled(), $tag_names['hotjar']);
				self::handle_rcb_integration($integration, Options::is_pinterest_active(), $tag_names['pinterest-ads']);
				self::handle_rcb_integration($integration, Options::is_snapchat_active(), $tag_names['snapchat-ads']);
				self::handle_rcb_integration($integration, Options::is_tiktok_active(), $tag_names['tiktok-ads']);
				self::handle_rcb_integration($integration, Options::is_twitter_active(), $tag_names['twitter-ads']);
				self::handle_rcb_integration($integration, Options::is_reddit_active(), $tag_names['reddit-ads']);

				// Fully dynamic version
				// Advantage: PhpStorm should be able to detect refactored function names and update them automatically
				// Disadvantage: The the functions will be loaded dynamically (call_user_func) which is a small.
				// Disadvantage: Static analysis like PHPStan might not be able to reliably detect the function calls.
//				$tags_mergedList = [
//					['slug' => 'bing-ads', 'check' => ['Options', 'is_bing_enabled']],
//					['slug' => 'facebook-pixel', 'check' => ['Options', 'is_facebook_enabled']],
//					// ...
//				];
//
//				foreach ($tags_mergedList as $tag) {
//					if (is_callable($tag['check'])) {
//						self::handle_rcb_integration($integration, call_user_func($tag['check']), $tag['slug']);
//					}
//				}
			});
		}

		/**
		 * If Google Site Kit is active, we need to disable the ads and analytics tags.
		 *
		 * Source: https://github.com/google/site-kit-wp/blob/774ea23c2471170c96898f11c1909dedf6bc5db3/includes/Core/Modules/Tags/Module_Web_Tag.php#L31
		 */
		if (self::is_google_site_kit_active()) {

			// https://github.com/google/site-kit-wp/blob/774ea23c2471170c96898f11c1909dedf6bc5db3/includes/Modules/Analytics_4.php#L122
			if (Options::is_google_analytics_active()) {
				add_filter('googlesitekit_analytics-4_tag_blocked', '__return_true');
			}

			// https://github.com/google/site-kit-wp/blob/774ea23c2471170c96898f11c1909dedf6bc5db3/includes/Modules/Ads.php#L60
			if (Options::is_google_ads_active()) {
				add_filter('googlesitekit_ads_tag_blocked', '__return_true');
			}
		}
	}

	public static function add_wpl_options_custom_scripts( $custom_scripts ) {

		if (!isset($custom_scripts['whitelist_script'])) {
			$custom_scripts['whitelist_script'] = [];
		}

		$script_to_add = [
			'enable' => true,
			'name'   => 'wpmDataLayer',
			'urls'   => [
				'wpmDataLayer',
			],
		];

		// Check if the script is already in the array
		$script_exists = false;
		foreach ($custom_scripts['whitelist_script'] as $script) {
			if ($script == $script_to_add) {
				$script_exists = true;
				break;
			}
		}

		// If the script is not in the array, add it
		if (!$script_exists) {
			$custom_scripts['whitelist_script'][] = $script_to_add;
		}

		return $custom_scripts;
	}

	private static function handle_rcb_integration( $integration, $is_active, $type ) {

		if (
			$is_active
			&& $integration->integrate(PMW_PLUGIN_FILE, $type)
		) {

//			error_log('PMW: RCB integration for ' . $type . ' was disabled.');
			$integration->setCodeOptIn('');
			$integration->setCodeOptOut('');
		}
	}

	public static function exclude_pmw_lazy_from_wp_rocket( $excluded_attributes ) {
		$excluded_attributes[] = 'pmw-lazy__premium_only';
		$excluded_attributes[] = 'wpmDataLayer';
		return $excluded_attributes;
	}

	private static function disable_woocommerce_google_ads_dynamic_remarketing() {
		// make sure to disable the WGDR plugin in case we use dynamic remarketing in this plugin
		add_filter('wgdr_third_party_cookie_prevention', '__return_true');
	}

	private static function disable_woofunnels_features() {

		add_filter('option_bwf_gen_config', function ( $options ) {

			// Disable Facebook events output
			if (Options::is_facebook_active()) {
				$options['fb_pixel_key'] = '';
			}

			// Disable Google Analytics events output
			if (Options::is_google_analytics_active()) {
				$options['ga_key'] = '';
			}

			// Disable Google Ads events output
			if (Options::is_google_ads_active()) {
				$options['gad_key'] = '';
			}

			// Disable Pinterest events output
			if (Options::is_pinterest_active()) {
				$options['pint_key'] = '';
			}

			// Disable TikTok events output
			if (Options::is_tiktok_active()) {
				$options['tiktok_pixel'] = '';
			}

			// Disable Snapchat events output
			if (Options::is_snapchat_active()) {
				$options['snapchat_pixel'] = '';
			}

			return $options;
		});
	}

	private static function disable_woo_product_feed_features() {

		// Disable Facebook events output
		if (Options::is_facebook_active()) {
			add_filter('option_add_facebook_pixel', function () {
				return 'no';
			});

			add_filter('option_add_facebook_capi', function () {
				return 'no';
			});
		}

		// Disable Google Ads events output
		if (Options::is_google_ads_active()) {
			add_filter('option_add_remarketing', function () {
				return 'no';
			});
		}
	}

	public static function enable_compatibility_mode() {

		self::compatibility_mode_prevent_third_party_js_optimization();
	}

	protected static function compatibility_mode_prevent_third_party_js_optimization() {

		if (self::is_wp_rocket_active()) {
			self::disable_wp_rocket_js_optimization();
		}

		if (self::is_flying_press_active()) {
			self::disable_flying_press_js_optimization();
		}

		if (self::is_optimocha_active()) {
			self::disable_optimocha_js_optimization();
		}

		if (self::is_wp_optimize_active()) {
			self::disable_wp_optimize_js_optimization();
		}

		if (self::is_async_javascript_active()) {
			self::disable_async_javascript_js_optimization();
		}

		if (self::is_sg_optimizer_active()) {
			self::disable_sg_optimizer_js_optimization();
		}

		if (self::is_litespeed_active()) {
			self::disable_litespeed_js_optimization();
		}

		if (self::is_autoptimize_active()) {
			self::disable_autoptimze_js_optimization();
		}
	}

	protected static function disable_sg_optimizer_js_optimization() {

		add_filter('sgo_javascript_combine_excluded_inline_content', [ __CLASS__, 'sg_optimizer_js_exclude_combine_inline_content' ]);
		add_filter('sgo_javascript_combine_exclude', [ __CLASS__, 'sgo_javascript_combine_exclude_move_after' ]);
		add_filter('sgo_javascript_combine_exclude_move_after', [ __CLASS__, 'sgo_javascript_combine_exclude_move_after' ]);
		add_filter('sgo_js_minify_exclude', [ __CLASS__, 'sg_optimizer_js_minify_exclude' ]);
		add_filter('sgo_js_async_exclude', [ __CLASS__, 'sgo_javascript_combine_exclude_move_after' ]);
	}

	protected static function disable_litespeed_js_optimization() {
		add_filter('litespeed_optimize_js_excludes', [ __CLASS__, 'litespeed_optimize_js_excludes' ]);
		add_filter('litespeed_optm_js_defer_exc', [ __CLASS__, 'litespeed_cache_js_defer_exc' ]);
		add_filter('litespeed_optm_cssjs', [ __CLASS__, 'litespeed_optm_cssjs' ]);
		add_filter('option_litespeed.conf.optm-js_inline_defer', [ __CLASS__, 'disable_litespeed_js_inline_after_dom' ]);
	}

	protected static function disable_autoptimze_js_optimization() {
		add_filter('autoptimize_filter_js_consider_minified', [ __CLASS__, 'autoptimize_filter_js_consider_minified' ]);
		add_filter('autoptimize_filter_js_dontmove', [ __CLASS__, 'autoptimize_filter_js_dontmove' ]);
	}

	protected static function disable_wp_optimize_js_optimization() {
		// add_filter('wpo_minify_inline_js', '__return_false');
		add_filter('wp-optimize-minify-default-exclusions', [ __CLASS__, 'wp_optimize_minify_default_exclusions' ]);
	}

	protected static function disable_async_javascript_js_optimization() {
		add_filter('option_aj_plugin_exclusions', function ( $options ) {

			if (!is_array($options)) {
				$options = [];
			}

			return array_unique(array_merge($options, [
				'woocommerce-google-adwords-conversion-tracking-tag',
				'woopt-pixel-manager-pro',
				'woocommerce-pixel-manager',
				'woocommerce-pixel-manager-pro',
			]));
		});
	}

	protected static function disable_optimocha_js_optimization() {
		add_filter('option_sbp_options', function ( $options ) {

			if (isset($options['js_exclude'])) {
				$options['js_exclude'] = $options['js_exclude'] . PHP_EOL . implode(PHP_EOL, self::get_pmw_script_identifiers());
				$js_include            = explode(PHP_EOL, $options['js_include']);
				$js_include            = array_filter($js_include, function ( $string ) {
					foreach (self::get_pmw_script_identifiers() as $value) {
						if (strpos($string, $value) !== false) {
							return false;
						}
					}

					return true;
				});
				$options['js_include'] = implode(PHP_EOL, $js_include);
			}

			return $options;
		});
	}

	protected static function disable_flying_press_js_optimization() {
		add_filter('pre_update_option_FLYING_PRESS_CONFIG', function ( $options ) {

			if (isset($options['js_defer_excludes'])) {
				$options['js_defer_excludes'] = array_unique(array_merge($options['js_defer_excludes'], self::get_pmw_script_identifiers()));
			}

			return $options;
		});

		add_filter('option_FLYING_PRESS_CONFIG', function ( $options ) {

			if (isset($options['js_defer_excludes'])) {
				$options['js_defer_excludes'] = array_unique(array_merge($options['js_defer_excludes'], self::get_pmw_script_identifiers()));
			}
			return $options;
		});

		// 		Make sure to never delay JS until interaction
//		if (self::is_flying_press_active()) {
//			add_filter('option_FLYING_PRESS_CONFIG', function ( $options ) {
//				if (isset($options['js_interaction'])) {
//					$options['js_interaction'] = false;
//				}
//				return $options;
//			});
//		}
	}

	protected static function disable_wp_rocket_js_optimization() {
		// for testing you need to clear the WP Rocket cache, only then the filters run
		self::exclude_inline_scripts_from_wp_rocket_using_options();
		add_filter('rocket_delay_js_exclusions', [ __CLASS__, 'add_wp_rocket_exclusions' ]);
		add_filter('rocket_defer_inline_exclusions', [ __CLASS__, 'add_wp_rocket_exclusions' ]);
		add_filter('rocket_exclude_defer_js', [ __CLASS__, 'add_wp_rocket_exclusions' ]);
		add_filter('rocket_exclude_js', [ __CLASS__, 'add_wp_rocket_exclusions' ]);
		add_filter('rocket_minify_excluded_external_js', [ __CLASS__, 'add_wp_rocket_exclusions' ]);
		add_filter('rocket_excluded_inline_js_content', [ __CLASS__, 'add_wp_rocket_exclusions' ]);
	}

	private static function get_pmw_script_identifiers() {
		return [
			'optimize.js',
			'googleoptimize.com/optimize.js',
			'jquery',
			'jQuery',
			'jQuery.min.js',
			'jquery.js',
			'jquery.min.js',
			'wpm',
			'wpm-js',
			'wpmDataLayer',
			'window.wpmDataLayer',
			'wpm.js',
			'wpm.min.js',
			'wpm__premium_only.js',
			'wpm__premium_only.min.js',
			'wpm-public.p1.min.js',
			'wpm-public__premium_only.p1.min.js',
			//            'facebook.js',
			//            'facebook.min.js',
			//            'facebook__premium_only.js',
			//            'facebook__premium_only.min.js',
			//            'google-ads.js',
			//            'google-ads.min.js',
			//            'google-ga-4-eec__premium_only.js',
			//            'google-ga-4-eec__premium_only.min.js',
			//            'google-ga-us-eec__premium_only.js',
			//            'google-ga-us-eec__premium_only.min.js',
			//            'google__premium_only.js',
			//            'google__premium_only.min.js',
			'window.dataLayer',
			//            '/gtag/js',
			'gtag',
			//            '/gtag/js',
			//            'gtag(',
			'gtm.js',
			//            '/gtm-',
			//            'GTM-',
			//            'fbq(',
			'fbq',
			'fbevents.js',
			//            'twq(',
			'twq',
			//            'e.twq',
			'static.ads-twitter.com/uwt.js',
			'platform.twitter.com/widgets.js',
			'uetq',
			'ttq',
			'events.js',
			'snaptr',
			'scevent.min.js',
		];
	}

	public static function is_curl_active() {
		return function_exists('curl_version');
	}


	/**
	 * Check if the URL redirects
	 *
	 * @param $url
	 * @return bool
	 */
	public static function does_url_redirect( $url ) {

		// Get the response from the URL and don't follow redirects
		$response = wp_remote_get($url, [
			'timeout'     => 4,
			'sslverify'   => !Geolocation::is_localhost(),
			'redirection' => 0,
		]);

		// If $repsonse is an error, then return false
		if (is_wp_error($response)) {
			return false;
		}

		$response_code = wp_remote_retrieve_response_code($response);

		// If $response_code is a redirect code (3xx), then it's a redirect and return true, otherwise return false
		return ( $response_code >= 300 && $response_code < 400 );
	}

// https://stackoverflow.com/questions/8429342/php-get-headers-set-temporary-stream-context
	protected static function get_headers_with_stream_context( $url, $context, $assoc = 0 ) {

		$fp = @fopen($url, 'r', null, $context);

		if (!is_bool($fp)) {

			$metaData = stream_get_meta_data($fp);
			fclose($fp);

			$headerLines = $metaData['wrapper_data'];

			if (!$assoc) {
				return $headerLines;
			}

			$headers = [];
			foreach ($headerLines as $line) {
				if (strpos($line, 'HTTP') === 0) {
					$headers[0] = $line;
					continue;
				}

				list($key, $value) = explode(': ', $line);
				$headers[$key] = $value;
			}

			return $headers;
		} else {
			return [];
		}
	}

// https://github.com/woocommerce/woocommerce/wiki/wc_get_orders-and-WC_Order_Query#usage
	public static function get_last_order_id() {

		if (self::$last_order_id) {
			return self::$last_order_id;
		}

		$orders = wc_get_orders([
			'limit'     => 1,
			'orderby'   => 'date',
			'order'     => 'DESC',
			'return'    => 'ids',
			'post_type' => 'shop_order',
		]);

//		error_log(reset($orders));

		self::$last_order_id = reset($orders);

		return self::$last_order_id;
	}

	public static function get_last_order_url() {

		$last_order = self::get_last_order();

		if ($last_order) {
			return $last_order->get_checkout_order_received_url();
		} else {
			return '';
		}
	}

	public static function get_last_order() {

		if (self::$last_order) {
			return self::$last_order;
		}

		self::$last_order = wc_get_order(self::get_last_order_id());

		return self::$last_order;
	}

	public static function does_one_order_exist() {
		if (self::get_last_order_id()) {
			return true;
		} else {
			return false;
		}
	}

	public static function get_wp_memory_limit() {

		$memory = WP_MEMORY_LIMIT;

		if (function_exists('memory_get_usage')) {
			$system_memory = @ini_get('memory_limit');

			// Convert WP_MEMORY_LIMIT to bytes
			$wp_memory = wp_convert_hr_to_bytes($memory);

			// Convert system memory limit to bytes
			$system_memory = wp_convert_hr_to_bytes($system_memory);

			$memory = max($wp_memory, $system_memory);
		}

		return size_format($memory);
	}

	public static function is_wp_memory_limit_set() {

		if (WP_MEMORY_LIMIT) {
			return true;
		} else {
			return false;
		}
	}

	public static function is_below_memory_limit( $memory_limit ) {

		$memory_limit = wc_let_to_num($memory_limit);

		$actual_memory_limit = wc_let_to_num(WP_MEMORY_LIMIT);
	}

	public static function is_memory_limit_higher_than( $memory_limit ) {

		$memory_limit = wc_let_to_num($memory_limit);

		$actual_memory_limit = wc_let_to_num(WP_MEMORY_LIMIT);

		if ($actual_memory_limit > $memory_limit) {
			return true;
		} else {
			return false;
		}
	}

	/**
	 * Checks if transients are enabled.
	 *
	 * This method sets a test transient and checks if it can be retrieved using the
	 * `get_transient` function. If the transient can be successfully retrieved, it
	 * means that transients are enabled, and the method returns true. Otherwise, it
	 * returns false.
	 *
	 * @return bool True if transients are enabled, false otherwise.
	 *
	 * @since 1.34.0
	 */
	public static function is_transients_enabled() {

		if (self::$transients_enabled) {
			return self::$transients_enabled;
		}

		set_transient('pmw_test_transient', 'test', 60);

		if (get_transient('pmw_test_transient')) {

			self::$transients_enabled = true;
			return true;
		}

		self::$transients_enabled = false;
		return false;
	}

	public static function is_on_playground_wordpress_net() {

		$_server = Helpers::get_input_vars(INPUT_SERVER);
		return isset($_server['SERVER_NAME']) && strpos($_server['SERVER_NAME'], 'playground.wordpress.net') !== false;
	}

	public static function get_action_scheduler_version() {

		if (!class_exists('ActionScheduler')) {
			return null;
		}

		// as_has_scheduled_action has been introduced in Action Scheduler 3.3.0
		if (!function_exists('as_has_scheduled_action')) {
			return '3.2.0';
		}

		// Fallback in case ActionScheduler_Versions is not available
		if (!class_exists('ActionScheduler_Versions')) {
			return '3.3.0';
		}

		return ActionScheduler_Versions::instance()->latest_version();
	}


	/**
	 * Checks if the Action Scheduler can be run.
	 *
	 * This method first checks if the Action Scheduler class exists.
	 * If it doesn't, it means that the Action Scheduler is not installed,
	 * and the method returns false.
	 *
	 * If the Action Scheduler class exists,
	 * the method retrieves the current version of the Action Scheduler and compares it with the version '3.5.3'.
	 * If the current version is lower than '3.5.3',
	 * it means that the Action Scheduler is installed but not supported, and the method returns false.
	 * Otherwise, it returns true, indicating that the Action Scheduler can be run.
	 *
	 * The minimum required version is '3.5.3' because that's when partial query match was introduced,
	 * which we use for finding scheduled actions.
	 * It is also above '3.2.1' which are the versions that reliably load the latest Action Scheduler version.
	 * And it is also above '3.3.0' which is the version that introduced the as_has_scheduled_action function.
	 *
	 * @return bool True if the Action Scheduler can be run, false otherwise.
	 *
	 * @since 1.37.1
	 */
	public static function can_run_action_scheduler() {

		if (!class_exists('ActionScheduler')) {
			return false;
		}

		// If the Action Scheduler is installed, but the version is too low, then we can't use it
		if (version_compare(self::get_action_scheduler_version(), self::get_action_scheduler_minimum_version(), '<')) {
			return false;
		}

		return true;
	}

	public static function cannot_run_action_scheduler() {
		return !self::can_run_action_scheduler();
	}

	// If the Action Scheduler is installed, but the version is lower than 3.5.3, then we can't use it
	// The minimum required version is 3.5.3 because that's when partial query match was introduced,
	// which we use for finding scheduled actions.
	// https://github.com/woocommerce/action-scheduler/releases/tag/3.5.3
	// It is also above 3.2.1 which are the versions that reliably load the latest Action Scheduler version.
	// And it is also above 3.3.0 which is the version that introduced the as_has_scheduled_action function.
	public static function get_action_scheduler_minimum_version() {
		return '3.5.3';
	}

	/**
	 * Get the user's editing capability
	 *
	 * Determines if the current user has permissions to manage WooCommerce if WooCommerce is active,
	 * else it returns the 'manage_options' capability.
	 *
	 * We need to do it this way because the 'manage_woocommerce' capability is only available if WooCommerce is active,
	 * and we need it to be working for non-WooCommerce admins as well.
	 *
	 * @return string 'manage_woocommerce' if the user can manage WooCommerce, otherwise 'manage_options'
	 *
	 * @since 1.44.3
	 */
	public static function get_user_edit_capability() {
		return user_can(wp_get_current_user(), 'manage_woocommerce') ? 'manage_woocommerce' : 'manage_options';
	}

	/**
	 * Check if the current user can edit options
	 *
	 * @return bool
	 *
	 * @since 1.46.1
	 */
	public static function can_current_user_edit_options() {
		return current_user_can('manage_woocommerce') || current_user_can('manage_options');
	}


	/**
	 * Check if the server is behind Cloudflare.
	 *
	 * @return bool True if the user can manage WooCommerce, false otherwise
	 *
	 * @since 1.45.1
	 */
	public static function is_server_behind_cloudflare() {
		$_server = Helpers::get_input_vars(INPUT_SERVER);
		return isset($_server['HTTP_CF_CONNECTING_IP']) || isset($_server['HTTP_CF_VISITOR']) || isset($_server['HTTP_CF_RAY']);
	}
}
