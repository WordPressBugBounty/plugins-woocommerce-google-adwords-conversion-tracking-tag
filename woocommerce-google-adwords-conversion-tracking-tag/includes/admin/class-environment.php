<?php

namespace SweetCode\Pixel_Manager\Admin;

use ActionScheduler_Versions;
use SweetCode\Pixel_Manager\Helpers;
use SweetCode\Pixel_Manager\Logger;
use SweetCode\Pixel_Manager\Options;
use SweetCode\Pixel_Manager\Product;
use SweetCode\Pixel_Manager\Profit_Margin;

defined('ABSPATH') || exit; // Exit if accessed directly

class Environment {

	/**
	 * The option that carries the cache layers which failed to purge during
	 * the most recent purge run, so the admin notice can surface them.
	 *
	 * @since 1.66.1
	 */
	const PURGE_FAILURES_OPTION = 'pmw_cache_purge_failures';

	private static $last_order_id         = null;
	private static $last_order            = null;
	private static $transients_enabled    = null;
	private static $external_object_cache = null;

	/**
	 * Cache layers that failed to purge during the current run.
	 *
	 * @since 1.66.1
	 */
	private static $purge_failures = [];

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

		if ('pmw' !== $page) {
			return false;
		}

		return true;
	}

	public static function is_not_allowed_notification_page( $page = null ) {
		return !self::is_allowed_notification_page($page);
	}

	/**
	 * Check if the install is a development install.
	 *
	 * Checks for common development domain patterns like .local, .test, localhost, etc.
	 *
	 * @return bool
	 * @since 1.55.0
	 */
	public static function is_development_install() {

		$site_url = get_site_url();

		$development_patterns = [
			'.local',
			'.test',
			'.dev',
			'.localhost',
			'localhost',
			'127.0.0.1',
			'staging.',
			'.staging',
			'dev.',
			'.dev.',
		];

		foreach ($development_patterns as $pattern) {
			if (strpos($site_url, $pattern) !== false) {
				return true;
			}
		}

		return false;
	}

//  public static function run_incompatible_plugins_checks() {
//
//      $saved_notifications = get_option(PMW_DB_NOTIFICATIONS_NAME);
//
//      foreach (self::get_incompatible_plugins_list() as $plugin) {
//
//          // If the plugin is not active, continue
//          if (!is_plugin_active($plugin['file_location'])) {
//              continue;
//          }
//
//          // If a notification has already been saved for this plugin, continue
//          if (
//              is_array($saved_notifications)
//              && array_key_exists($plugin['slug'], $saved_notifications)
//          ) {
//              continue;
//          }
//
//          Notifications::plugin_is_incompatible(
//              $plugin['name'],
//              $plugin['version'],
//              $plugin['slug'],
//              $plugin['link'],
//              $plugin['pmw_doc_link']
//          );
//      }
//  }

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
		add_action('upgrader_process_complete', [ __CLASS__, 'upgrader_process_complete_tasks' ], 10, 2);
	}

	/**
	 * This function is called after a plugin has been updated.
	 * It checks if the updated plugin is PMW and runs tasks accordingly.
	 *
	 * It purges the entire cache.
	 *
	 * @param \WP_Upgrader $upgrader_object
	 * @param array        $options
	 *
	 * @return void
	 */
	public static function upgrader_process_complete_tasks( $upgrader_object, $options ) {

		if (!isset($options['type'])) {
			return;
		}
		if ('plugin' !== $options['type']) {
			return;
		}
		if (!isset($options['plugins'])) {
			return;
		}
		if (!is_array($options['plugins'])) {
			return;
		}
		if (!in_array(PMW_PLUGIN_BASENAME, $options['plugins'], true)) {
			return;
		}

		/**
		 * Save a backup of the current options.
		 */

		Options::save_automatic_options_backup_with_timestamp(time());

		/**
		 * We purge the entire cache.
		 * This is necessary because the plugin has been updated and we need to make sure that all front-end pages use the latest version of the plugin.
		 * This is especially important for the first layer cache, which is usually handled by a cache plugin.
		 */
		self::purge_entire_cache();

		/**
		 * Update GTG proxy config cache after plugin update.
		 * This ensures the isolated proxy has up-to-date configuration
		 * even if the config file was deleted during the update process.
		 *
		 * @since 1.56.0
		 */
		if ( class_exists( '\SweetCode\Pixel_Manager\Pixels\Google\GTG_Proxy' ) ) {
			\SweetCode\Pixel_Manager\Pixels\Google\GTG_Proxy::update_proxy_config_cache();
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
		 * Start a fresh failure record for this run. Whatever is left in it
		 * when the last layer is done gets persisted for the admin notice.
		 *
		 * @since 1.66.1
		 */
		self::$purge_failures = [];

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

		/**
		 * Delete specific transients.
		 */
		delete_transient('pmw_google_tag_id');
		delete_transient('pmw_google_tag_id_information');

		self::persist_purge_failures();
	}

	/**
	 * Note that a cache layer we detected as present could not be purged.
	 *
	 * A purge that silently does nothing is worse than no purge at all: the
	 * shop keeps serving the previous release's tracking library, whose
	 * content-hashed chunks the current release no longer ships, and the
	 * library then disables itself rather than track without a consent
	 * module. Ticket 3426583170 ran for days on exactly that.
	 *
	 * @param string $cache The human-readable name of the cache layer.
	 * @param string $reason Why the purge did not happen.
	 *
	 * @return void
	 * @since 1.66.1
	 */
	private static function record_purge_failure( $cache, $reason ) {

		self::$purge_failures[] = [
			'cache'  => $cache,
			'reason' => $reason,
		];

		Logger::error('Cache purge failed for ' . $cache . ': ' . $reason);
	}

	/**
	 * Persist the failures of the run that just finished so the admin notice
	 * can surface them, and clear the record when everything purged cleanly.
	 *
	 * @return void
	 * @since 1.66.1
	 */
	private static function persist_purge_failures() {

		if (empty(self::$purge_failures)) {
			delete_option(self::PURGE_FAILURES_OPTION);
			return;
		}

		update_option(
			self::PURGE_FAILURES_OPTION,
			[
				'time'     => time(),
				'failures' => self::$purge_failures,
			],
			false
		);
	}

	/**
	 * The cache layers that failed to purge during the most recent run, if any.
	 *
	 * @return array
	 * @since 1.66.1
	 */
	public static function get_purge_failures() {

		$record = get_option(self::PURGE_FAILURES_OPTION);

		if (!is_array($record) || empty($record['failures'])) {
			return [];
		}

		return $record['failures'];
	}

	/**
	 * Forget the recorded purge failures.
	 *
	 * @return void
	 * @since 1.66.1
	 */
	public static function clear_purge_failures() {
		delete_option(self::PURGE_FAILURES_OPTION);
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
		}                                                                              // code-reviewed 1.66.1 against nitropack 1.20.0
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
		}                                                                           // code-reviewed 1.66.1, not verified on a live Kinsta shop

		if (self::is_nginx_helper_active()) {
			self::purge_nginx_helper_cache();
		}                                                                           // code-reviewed 1.66.1 against nginx-helper 2.3.5

		if (self::is_proxy_cache_purge_active()) {
			self::purge_proxy_cache_purge_cache();
		}                                                                           // code-reviewed 1.66.1 against varnish-http-purge 5.x

		//        if (self::is_hosting_pagely()) $this->purge_pagely_cache();

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

	// This is a helper to work around the
	private static function localhost_domain() {
		return 'localhost';
	}

	/**
	 * Purge the Kinsta cache.
	 *
	 * Kinsta's MU plugin exposes a purge endpoint on the site itself, and the
	 * request has to go to the loopback address so it reaches the origin
	 * rather than Kinsta's edge.
	 *
	 * That means the TLS certificate presented on https://localhost/ is the
	 * shop's certificate, which never matches the host name "localhost", so
	 * certificate verification has to be off. It used to be tied to
	 * Geolocation::is_localhost(), which reports whether the *visitor* is on a
	 * private network. On a live shop that is false, verification was left on,
	 * and every purge failed on the handshake. wp_remote_get() returns a
	 * WP_Error for that instead of throwing, so the try/catch never saw it and
	 * nothing was logged.
	 *
	 * @return void
	 * @since 1.66.1
	 */
	private static function purge_kinsta_cache() {

		$response = wp_remote_get('https://' . self::localhost_domain() . '/kinsta-clear-cache-all', [
			// The loopback certificate can never match "localhost". See the docblock.
			'sslverify' => false,
			'timeout'   => 5,
		]);

		if (is_wp_error($response)) {
			self::record_purge_failure('Kinsta', $response->get_error_message());
			return;
		}

		$code = wp_remote_retrieve_response_code($response);

		if ($code < 200 || $code >= 400) {
			self::record_purge_failure('Kinsta', 'The purge endpoint answered with HTTP ' . $code . '.');
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
	 * Nginx Helper registers `rt_nginx_helper_purge_all` for exactly this
	 * purpose ("expose action to allow other plugins to purge the cache"), so
	 * we go through the documented action first and only reach into the global
	 * $nginx_purger when no listener is attached.
	 *
	 * @return void
	 * @since 1.66.1 Prefer the documented action, and report a purge we could not run.
	 */
	private static function purge_nginx_helper_cache() {

		if (has_action('rt_nginx_helper_purge_all')) {
			/**
			 * Fires Nginx Helper's purge-everything routine.
			 *
			 * @since 1.66.1
			 */
			do_action('rt_nginx_helper_purge_all');
			return;
		}

		global $nginx_purger;

		if (
			$nginx_purger
			&& method_exists($nginx_purger, 'purge_all')
		) {
			$nginx_purger->purge_all();
			return;
		}

		self::record_purge_failure(
			'Nginx Helper',
			'Nginx Helper is active but exposes neither the rt_nginx_helper_purge_all action nor a purger object.'
		);
	}

	/**
	 * Purge the Proxy Cache Purge (Varnish HTTP Purge) cache.
	 *
	 * We used to construct a fresh VarnishPurger and call execute_purge() on
	 * it. execute_purge() only flushes the URLs collected in that instance's
	 * $purge_urls, and a fresh instance has none, so the call returned without
	 * sending a single PURGE request. It also re-ran the constructor, which
	 * registers filters and writes site options.
	 *
	 * A full flush is a single regex purge against the home URL, which is what
	 * the plugin's own "Purge Cache" admin bar entry issues. purge_url() is
	 * public and static for that reason and has been since at least 4.7.2.
	 *
	 * @return void
	 * @since 1.66.1
	 */
	private static function purge_proxy_cache_purge_cache() {

		if (!is_callable([ '\VarnishPurger', 'purge_url' ])) {
			self::record_purge_failure(
				'Proxy Cache Purge',
				'Proxy Cache Purge is active but does not expose VarnishPurger::purge_url().'
			);
			return;
		}

		try {
			// The vhp-regex query is the plugin's marker for "flush everything".
			\VarnishPurger::purge_url(home_url('/?vhp-regex'));
		} catch (\Exception $e) {
			self::record_purge_failure('Proxy Cache Purge', $e->getMessage());
		}
	}

	public static function purge_cloudflare_cache() {
		try {
			if (class_exists('\CF\WordPress\Hooks')) {
				( new \CF\WordPress\Hooks() )->purgeCacheEverything();
			}
		} catch (\Exception $e) {
			Logger::error($e->getMessage());
		}
	}

	public static function purge_flying_press_cache() {
		try {
			if (class_exists('\FlyingPress\Purge') && method_exists('\FlyingPress\Purge', 'purge_cached_pages')) {
				\FlyingPress\Purge::purge_cached_pages();
			}
		} catch (\Exception $e) {
			Logger::error($e->getMessage());
		}
	}

	public static function purge_wp_engine_cache() {
		try {
			if (class_exists('WpeCommon')) {
				\WpeCommon::purge_varnish_cache_all();
			}
		} catch (\Exception $e) {
			Logger::error($e->getMessage());
		}
	}

	private static function purge_pagely_cache() {
		try {
			if (class_exists('PagelyCachePurge')) { // We need to have this check for clients that switch hosts
				$pagely = new \PagelyCachePurge();
				$pagely->purgeAll();
			}
		} catch (\Exception $e) {
			Logger::error($e->getMessage());
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

	/**
	 * Purge the NitroPack cache.
	 *
	 * NitroPack does not keep its credentials in options of its own. It stores
	 * them inside a site config array, and `nitropack-siteId` /
	 * `nitropack-siteSecret` exist only as the name attributes of the two
	 * inputs on its connect screen (`view/connect.php`). Reading them with
	 * get_option() therefore always yielded false, the SDK was constructed
	 * with empty credentials, and NitroPack's API answered every purge with
	 * "Invalid request - missing parameters". The purge had never worked on
	 * any NitroPack shop.
	 *
	 * nitropack_sdk_purge() is NitroPack's own public entry point. It resolves
	 * the credentials from the site config, purges the local cache and issues
	 * the remote complete purge, and it has been part of its functions.php
	 * across every version we could check back to 1.10.
	 *
	 * do_action('nitropack_integration_purge_all') is not an alternative:
	 * NitroPack fires that action to tell downstream integrations (its
	 * LiteSpeed integration, its purge log) to purge. It never purges its own
	 * cache in response to it.
	 *
	 * @return void
	 * @since 1.66.1
	 */
	public static function purge_nitropack_cache() {

		if (!function_exists('nitropack_sdk_purge')) {
			self::record_purge_failure(
				'NitroPack',
				'NitroPack is active but does not expose nitropack_sdk_purge().'
			);
			return;
		}

		try {
			if (nitropack_sdk_purge()) {
				return;
			}
		} catch (\Exception $e) {
			self::record_purge_failure('NitroPack', $e->getMessage());
			return;
		}

		/**
		 * A false answer from nitropack_sdk_purge() only means it could not
		 * build an SDK, so the site is not connected to a NitroPack account.
		 * Such an install caches nothing, so there is nothing to warn about.
		 * Anything else is a connection that exists but does not work.
		 */
		if (!self::is_nitropack_connected()) {
			return;
		}

		self::record_purge_failure(
			'NitroPack',
			'NitroPack rejected the purge. Its site connection is incomplete.'
		);
	}

	/**
	 * Whether NitroPack holds credentials for this site.
	 *
	 * @return bool
	 * @since 1.66.1
	 */
	private static function is_nitropack_connected() {

		if (!function_exists('nitropack_get_site_config')) {
			return false;
		}

		$config = nitropack_get_site_config();

		return is_array($config) && !empty($config['siteId']) && !empty($config['siteSecret']);
	}

	public static function purge_hummingbird_cache() {
		/**
		 * Fires Wphb clear page cache.
		 *
		 * @since 1.58.5
		 */
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
		/**
		 * Fires Litespeed purge all.
		 *
		 * @since 1.58.5
		 */
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

	/**
	 * TikTok for WooCommerce
	 *
	 * The wp.org slug is tiktok-for-business, the main file is named after the
	 * plugin's current title.
	 *
	 * @return bool
	 *
	 * @since 1.65.2
	 */
	public static function is_tiktok_for_woocommerce_active() {
		return is_plugin_active('tiktok-for-business/tiktok-for-woocommerce.php');
	}

	/**
	 * Triple Whale's own "Triple Whale Pixel" WordPress plugin.
	 *
	 * The class check covers an install under a renamed directory.
	 *
	 * @return bool
	 *
	 * @since 1.67.1
	 */
	public static function is_triple_whale_pixel_plugin_active() {
		return is_plugin_active('triple-whale/triple-whale.php') || class_exists('twpwe_extension');
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

		return is_plugin_active('litespeed-cache/litespeed-cache.php');
	}

	public static function is_litespeed_esi_active() {

		if (
			defined('LSCWP_V')
			/**
			 * Filters Litespeed esi status.
			 *
			 * @since 1.58.5
			 */
			&& apply_filters('litespeed_esi_status', false)
		) {
			return true;
		}

		return false;
	}

	public static function is_autoptimize_active() {

		return is_plugin_active('autoptimize/autoptimize.php');
	}

	public static function is_hummingbird_active() {

		return is_plugin_active('hummingbird-performance/wp-hummingbird.php');
	}

	public static function is_nitropack_active() {

		return is_plugin_active('nitropack/main.php');
	}

	public static function is_yoast_seo_active() {

		return is_plugin_active('wordpress-seo/wp-seo.php');
	}

	public static function is_borlabs_cookie_active() {

		return is_plugin_active('borlabs-cookie/borlabs-cookie.php');
	}

	public static function is_cookiebot_active() {
		return is_plugin_active('cookiebot/cookiebot.php');
	}

	public static function is_usercentrics_cmp_active() {
		return is_plugin_active('usercentrics-consent-management-platform/usercentrics.php');
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

	/**
	 * Check if Google Automated Discounts for WooCommerce (GADWC) is active.
	 *
	 * Checks constant, class, and all known plugin basenames across distributions
	 * (Freemius free, Freemius premium, WooCommerce Marketplace).
	 *
	 * @return bool
	 * @since 1.57.0
	 */
	public static function is_gadwc_active() {
		return defined('SGADWC_CURRENT_VERSION')
			|| class_exists('SGADWC')
			|| is_plugin_active('sgadwc/sgadwc.php')
			|| is_plugin_active('sgadwc-premium/sgadwc.php')
			|| is_plugin_active('google-automated-discounts-pro-for-woocommerce/google-automated-discounts-pro-for-woocommerce.php');
	}

	/**
	 * Check if Google Customer Reviews for WooCommerce (GCR) is active.
	 *
	 * Checks constant, class, and all known plugin basenames across distributions
	 * (Freemius free, Freemius premium).
	 *
	 * @return bool
	 * @since 1.57.0
	 */
	public static function is_gcr_active() {
		return defined('GCR_CURRENT_VERSION')
			|| class_exists('GCR')
			|| is_plugin_active('google-customer-reviews-for-woocommerce/google-customer-reviews-for-woocommerce.php')
			|| is_plugin_active('google-customer-reviews-for-woocommerce-premium/google-customer-reviews-for-woocommerce.php');
	}

	/**
	 * Check if Nextend Social Login is active.
	 *
	 * The Pro addon is a separate plugin that requires the free one, so
	 * detecting the free plugin covers both.
	 *
	 * @return bool
	 * @since 1.64.1
	 */
	public static function is_nextend_social_login_active() {
		return class_exists('NextendSocialLogin')
			|| is_plugin_active('nextend-facebook-connect/nextend-facebook-connect.php');
	}

	/**
	 * Check if miniOrange Social Login is active.
	 *
	 * @return bool
	 * @since 1.64.1
	 */
	public static function is_miniorange_social_login_active() {
		return defined('MO_OPENID_SOCIAL_LOGIN_VERSION')
			|| is_plugin_active('miniorange-login-openid/miniorange_openid_sso_settings.php');
	}

	/**
	 * Check if UsersWP Social Login is active.
	 *
	 * @return bool
	 * @since 1.64.1
	 */
	public static function is_userswp_social_login_active() {
		return defined('UWP_SOCIAL_VERSION')
			|| is_plugin_active('userswp-social-login/uwp-social.php');
	}

	/**
	 * Check if Super Socializer is active.
	 *
	 * Closed on WordPress.org, but still running on existing installs.
	 *
	 * @return bool
	 * @since 1.64.1
	 */
	public static function is_super_socializer_active() {
		return is_plugin_active('super-socializer/super_socializer.php');
	}

	/**
	 * Check if Wapu Auth is active.
	 *
	 * @return bool
	 * @since 1.64.1
	 */
	public static function is_wapu_auth_active() {
		return defined('WAPU_AUTH_VERSION')
			|| is_plugin_active('wapu-auth-social-login/wapu-auth-social-login.php');
	}

	/**
	 * Check if Heateor Login is active.
	 *
	 * @return bool
	 * @since 1.64.1
	 */
	public static function is_heateor_login_active() {
		return defined('HEATEOR_FBL_VERSION')
			|| is_plugin_active('heateor-login/heateor-login.php');
	}

	/**
	 * Check if Easy Social Login is active.
	 *
	 * @return bool
	 * @since 1.64.1
	 */
	public static function is_easy_social_login_active() {
		return defined('ESLP_VERSION')
			|| is_plugin_active('easy-social-login/easy-social-login.php');
	}

	public static function is_real_cookie_banner_active() {
		return is_plugin_active('real-cookie-banner/index.php')
			|| is_plugin_active('real-cookie-banner-pro/index.php');
	}

	public static function is_termly_active() {

		return is_plugin_active('uk-cookie-consent/uk-cookie-consent.php')
			|| is_plugin_active('uk-cookie-consent-premium/uk-cookie-consent-premium.php');
	}

	// Beautiful and Responsive Cookie Consent
	// https://wordpress.org/plugins/beautiful-and-responsive-cookie-consent/
	public static function is_beautiful_cookie_consent_active() {
		return is_plugin_active('beautiful-and-responsive-cookie-consent/nsc_bar-cookie-consent.php');
	}

	/**
	 * FAZ Cookie Manager
	 * https://wordpress.org/plugins/faz-cookie-manager/
	 *
	 * @return bool
	 * @since 1.58.12
	 */
	public static function is_faz_cookie_manager_active() {
		return is_plugin_active('faz-cookie-manager/faz-cookie-manager.php');
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

	/**
	 * Check if the WooCommerce native Cost of Goods Sold feature is enabled.
	 *
	 * This feature was introduced in WooCommerce 9.5 and is hidden behind a feature flag
	 * in WooCommerce → Settings → Advanced → Features.
	 *
	 * @return bool True if the WooCommerce native COGS feature is enabled.
	 *
	 * @since 1.58.1
	 */
	public static function is_woocommerce_native_cogs_active() {
		if (!class_exists('\\Automattic\\WooCommerce\\Utilities\\FeaturesUtil')) {
			return false;
		}

		return \Automattic\WooCommerce\Utilities\FeaturesUtil::feature_is_enabled('cost_of_goods_sold');
	}

	public static function is_a_cog_plugin_active() {
		return self::is_woocommerce_native_cogs_active()
			|| self::is_woocommerce_cog_active()
			|| self::is_cog_for_woocommerce_active()
			|| Profit_Margin::get_custom_cog_product_meta_key();
	}

	public static function is_woocommerce_active() {
		return is_plugin_active('woocommerce/woocommerce.php');
	}

	/**
	 * The active shop platform of this site.
	 *
	 * WooCommerce wins when several supported shop plugins are active.
	 * Returns 'none' when no supported shop platform is active. Part B of
	 * docs/PLATFORM-ABSTRACTION-PLAN.md adds 'fluentcart' and 'surecart'.
	 *
	 * @since 1.65.0
	 *
	 * @return string 'woocommerce' | 'none'
	 */
	public static function get_active_shop_platform() {

		$platform = 'none';

		if (self::is_woocommerce_active()) {
			$platform = 'woocommerce';
		}

		/**
		 * Filters the detected shop platform slug.
		 *
		 * @since 1.65.0
		 *
		 * @param string $platform
		 */
		return apply_filters('pmw_active_shop_platform', $platform);
	}

	public static function is_wp_super_cache_active() {

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
		return is_plugin_active('woo-discount-rules/woo-discount-rules.php') ||
			is_plugin_active('woo-discount-rules-pro/woo-discount-rules-pro.php');
	}

	public static function is_woofunnels_active() {
		return is_plugin_active('funnel-builder/funnel-builder.php') ||
			is_plugin_active('funnel-builder-pro/funnel-builder-pro.php');
	}

	public static function is_woo_product_feed_active() {
		return is_plugin_active('woo-product-feed-pro/woocommerce-sea.php') ||
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

	/**
	 * Klaviyo (the official WooCommerce plugin)
	 *
	 * @link https://wordpress.org/plugins/klaviyo/
	 *
	 * @return bool
	 *
	 * @since 1.68.0
	 */
	public static function is_klaviyo_plugin_active() {
		return is_plugin_active('klaviyo/klaviyo.php');
	}

	/**
	 * The version of the official Klaviyo plugin, or an empty string.
	 *
	 * WCK_API::VERSION is the reliable source. The plugin also defines
	 * WCK_VERSION, but from a property that is still unset at that moment, so
	 * on a live install the constant holds false; it is only consulted when it
	 * carries a value. The plugin header is the last resort. The plugin's
	 * KLAVIYO_PLUGIN_VERSION constant is a stale 1.3 and is never read.
	 *
	 * @return string
	 *
	 * @since 1.68.0
	 */
	public static function get_klaviyo_plugin_version() {

		if (!self::is_klaviyo_plugin_active()) {
			return '';
		}

		$version = '';

		if (class_exists('WCK_API') && defined('WCK_API::VERSION') && is_string(\WCK_API::VERSION) && '' !== \WCK_API::VERSION) {
			$version = \WCK_API::VERSION;
		} elseif (defined('WCK_VERSION') && is_string(WCK_VERSION) && '' !== WCK_VERSION) {
			$version = WCK_VERSION;
		} else {

			if (!function_exists('get_plugin_data')) {
				require_once ABSPATH . 'wp-admin/includes/plugin.php';
			}

			$plugin_file = WP_PLUGIN_DIR . '/klaviyo/klaviyo.php';

			if (file_exists($plugin_file)) {
				$plugin_data = get_plugin_data($plugin_file, false, false);
				$version     = isset($plugin_data['Version']) ? (string) $plugin_data['Version'] : '';
			}
		}

		/**
		 * Filter the detected Klaviyo plugin version.
		 *
		 * Lets a shop pin the version the takeover guard compares against, for
		 * example to keep automatic mode in takeover on a Klaviyo release that
		 * was checked by hand before the Pixel Manager caught up.
		 *
		 * @param string $version The detected version, or an empty string.
		 *
		 * @since 1.68.0
		 */
		return (string) apply_filters('pmw_klaviyo_plugin_version', $version);
	}

	/**
	 * The Klaviyo plugin versions the tracking takeover was tested against.
	 *
	 * The takeover removes the plugin's tracking hooks by name and calls its
	 * wck_build_cart_data() for the abandoned-cart key, so a Klaviyo release
	 * that moves either can silently break it. Outside this range the Pixel
	 * Manager falls back to filling the gaps instead. Bump the upper bound
	 * after verifying a new Klaviyo release; that is the whole maintenance rule.
	 *
	 * @since 1.68.0
	 */
	const KLAVIYO_PLUGIN_TESTED_MIN = '3.8.0';
	const KLAVIYO_PLUGIN_TESTED_MAX = '3.9';

	/**
	 * Whether the active Klaviyo plugin version is inside the tested range.
	 *
	 * @return bool
	 *
	 * @since 1.68.0
	 */
	public static function is_klaviyo_plugin_version_supported() {

		$version = self::get_klaviyo_plugin_version();

		if ('' === $version) {
			return false;
		}

		return version_compare($version, self::KLAVIYO_PLUGIN_TESTED_MIN, '>=')
			&& version_compare($version, self::KLAVIYO_PLUGIN_TESTED_MAX, '<');
	}

	public static function is_optimocha_active() {
		return is_plugin_active('speed-booster-pack/speed-booster-pack.php');
	}

	public static function is_async_javascript_active() {
		return is_plugin_active('async-javascript/async-javascript.php');
	}

	public static function is_flying_press_active() {
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

	public static function wp_optimize_minify_default_exclusions( $default_exclusions ) {
		return array_unique(array_merge($default_exclusions, self::get_pmw_core_script_identifiers()));
	}

// https://github.com/futtta/autoptimize/blob/37b13d4e19269bb2f50df123257de51afa37244f/classes/autoptimizeScripts.php#L387
	public static function autoptimize_filter_js_consider_minified() {
		$exclude_js[] = 'pmw.min.js';
		$exclude_js[] = 'pmw.min.js';

		$exclude_js[] = 'pmw-public.p1.min.js';
		$exclude_js[] = 'pmw-public__premium_only.p1.min.js';

		$exclude_js[] = 'pmw-public.p2.min.js';
		$exclude_js[] = 'pmw-public__premium_only.p2.min.js';

		// Include paths for free and pro folders
		$exclude_js[] = 'js/public/free/';
		$exclude_js[] = 'js/public/pro/';

//        $exclude_js[] = 'jquery.js';
//        $exclude_js[] = 'jquery.min.js';
		return $exclude_js;
	}

// https://github.com/futtta/autoptimize/blob/37b13d4e19269bb2f50df123257de51afa37244f/classes/autoptimizeScripts.php#L285
	public static function autoptimize_filter_js_dontmove( $dontmove ) {
		$dontmove[] = 'pmw.js';
		$dontmove[] = 'pmw.min.js';

		$dontmove[] = 'pmw-public.p1.min.js';
		$dontmove[] = 'pmw-public__premium_only.p1.min.js';

		$dontmove[] = 'pmw-public.p2.min.js';
		$dontmove[] = 'pmw-public__premium_only.p2.min.js';

		// Include paths for free and pro folders
		$dontmove[] = 'js/public/free/';
		$dontmove[] = 'js/public/pro/';

		$dontmove[] = 'jquery.js';
		$dontmove[] = 'jquery.min.js';
		return $dontmove;
	}

	public static function litespeed_optimize_js_excludes( $excludes ) {
		if (is_array($excludes)) {
			$excludes = array_unique(array_merge($excludes, self::get_pmw_core_script_identifiers()));
		}

		return $excludes;
	}

	public static function sg_optimizer_js_exclude_combine_inline_content( $exclude_list ) {
		if (is_array($exclude_list)) {
			$exclude_list = array_unique(array_merge($exclude_list, self::get_pmw_core_script_identifiers()));
		}

		return $exclude_list;
	}

	public static function sg_optimizer_js_minify_exclude( $exclude_list ) {

		$exclude_list[] = 'pmw-front-end-scripts';
		$exclude_list[] = 'pmw-front-end-scripts-premium-only';
		$exclude_list[] = 'pmw';
		$exclude_list[] = 'pmw-admin';
		$exclude_list[] = 'pmw-premium-only';
		$exclude_list[] = 'pmw-facebook';
		$exclude_list[] = 'pmw-script-blocker-warning';
		$exclude_list[] = 'pmw-admin-helpers';
		$exclude_list[] = 'pmw-admin-tabs';
		$exclude_list[] = 'pmw-selectWoo';
		$exclude_list[] = 'pmw-google-ads';
		$exclude_list[] = 'pmw-ga-ua-eec';
		$exclude_list[] = 'pmw-ga4-eec';

		$exclude_list[] = 'jquery';
		$exclude_list[] = 'jquery-core';
		$exclude_list[] = 'jquery-migrate';

		return $exclude_list;
	}

	public static function sgo_javascript_combine_exclude_move_after( $exclude_list ) {

		if (is_array($exclude_list)) {
			$exclude_list = array_unique(array_merge($exclude_list, self::get_pmw_core_script_identifiers()));
		}

		return $exclude_list;
	}

	/**
	 * Add PMW's core script identifiers to WP Rocket exclusion arrays.
	 *
	 * Used for unconditional minification/combination exclusions.
	 * Only includes PMW's own files, not third-party tracking scripts.
	 *
	 * @param array $exclusions Existing exclusion patterns.
	 *
	 * @return array Merged exclusion patterns.
	 *
	 * @since 1.58.5
	 */
	public static function add_wp_rocket_core_exclusions( $exclusions ) {
		if (is_array($exclusions)) {
			$exclusions = array_unique(array_merge($exclusions, self::get_pmw_core_script_identifiers()));
		}

		return $exclusions;
	}

	/**
	 * Third party plugin tweaks that have to be registered on plugins_loaded
	 *
	 * Two Google plugins decide whether to track earlier than our own init hook,
	 * so a filter registered from third_party_plugin_tweaks_on_init() is never
	 * seen by them and their tracking keeps running next to ours:
	 *
	 * - Google for WooCommerce evaluates GlobalSiteTag::is_needed() when its
	 *   service container registers on plugins_loaded priority 20.
	 * - Google Analytics for WooCommerce evaluates disable_tracking('all') in its
	 *   integration constructor, which WooCommerce runs in new WC_Integrations()
	 *   on init priority 0, a few statements before it fires woocommerce_init.
	 *
	 * Both filters are therefore registered here, from the plugin constructor on
	 * plugins_loaded priority 10, and both decide lazily inside the callback.
	 * Registering them unconditionally keeps Options out of plugins_loaded, where
	 * initializing it would cache the options tree before shops can register
	 * their pmw_options filters. That early Options read is why these tweaks were
	 * moved to init in the first place.
	 *
	 * @return void
	 *
	 * @since 1.65.2
	 */
	public static function third_party_plugin_tweaks_on_plugins_loaded() {

		/**
		 * Google for WooCommerce (formerly Google Listings & Ads, still "gla" in its code)
		 *
		 * Disable GLA's gtag tracking when Google Ads is active in PMW.
		 * GLA's tracking is specifically for Google Ads (remarketing, conversions).
		 * When PMW handles Google Ads, GLA's tracking must be disabled to prevent
		 * duplicate event tracking. If only GA4 is active in PMW, GLA's Google Ads
		 * tracking is left intact since PMW isn't handling Google Ads in that case.
		 */
		add_filter('woocommerce_gla_disable_gtag_tracking', function ( $disabled ) {
			return $disabled || Options::is_google_ads_active_early();
		});

		/**
		 * Google Analytics for WooCommerce (formerly WooCommerce Google Analytics Integration)
		 *
		 * Disable its tracking when GA4 is active in PMW, otherwise both plugins
		 * send the same GA4 events. Its own gate already exempts admins, so the
		 * duplicates only ever show up for logged out visitors.
		 */
		add_filter('woocommerce_ga_disable_tracking', function ( $disabled ) {
			return $disabled || Options::is_google_analytics_active_early();
		});

		/**
		 * LiteSpeed Cache
		 *
		 * LiteSpeed reads its delay-until-interaction exclusion lists on init priority 5,
		 * before our own init hook runs, so these have to be registered here. Everything
		 * else LiteSpeed related stays in third_party_plugin_tweaks_on_init().
		 */
		self::exclude_pmw_from_litespeed_delay_js();
	}

	/**
	 * Third party plugin tweaks
	 *
	 * !!
	 * Don't load these on plugins_loaded,
	 * because our filter won't be applied.
	 *
	 * Exception: plugins that read our filter before init runs. Those belong in
	 * third_party_plugin_tweaks_on_plugins_loaded().
	 *
	 * @return void
	 */
	public static function third_party_plugin_tweaks_on_init() {

		/**
		 * WP Consent API compatibility declaration
		 *
		 * Must be hooked into init
		 */
		add_filter('wp_consent_api_registered_' . PMW_PLUGIN_BASENAME, '__return_true');

		/**
		 * Complianz
		 *
		 * Must be hooked into init
		 */
		if (self::is_complianz_active()) {

			// Try to disable blocking of inline PMW configuration scripts
			add_filter('cmplz_whitelisted_script_tags', function ( $tags ) {
				$tags[] = 'pmwDataLayer';
				return $tags;
			});

			/**
			 * Disable the Complianz Google Consent Mode if the Google Consent Mode is active in PMW
			 *
			 * Two consent default blocks on one page compete: whichever gtag
			 * consent default runs last is the one Google keeps, and Complianz
			 * grants functionality_storage and security_storage by default while
			 * PMW denies everything but the essentials in explicit consent mode.
			 * The pixels then ran against a default state PMW never set.
			 * Ticket 3435984837.
			 *
			 * Complianz keeps its settings in one option array rather than in
			 * single options, so unlike the Cookiebot tweak below this has to go
			 * through the array. It is limited to the front end so that the
			 * Complianz settings screens keep showing the merchant what is
			 * actually stored.
			 *
			 * @since 1.67.1
			 */
			if (!is_admin() && Options::is_google_consent_mode_active()) {
				add_filter('option_cmplz_options', function ( $options ) {
					if (!is_array($options)) {
						return $options;
					}
					$options['consent-mode'] = 'no';
					return $options;
				});
			}
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
		 * Beautiful and Responsive Cookie Consent
		 *
		 * Disable the Beautiful Cookie Consent Google Consent Mode if the Google Consent Mode is active in PMW
		 * Disable the script blocker for PMW scripts (PMW handles consent internally)
		 */

		if (self::is_beautiful_cookie_consent_active()) {

			// Disable GCM if PMW handles it
			if (Options::is_google_consent_mode_active()) {
				add_filter('nsc_bar_output_google_consent_mode_script', '__return_false');
			}

			// Disable script blocker for PMW scripts
			add_filter('nsc_bar_block_script', function ( $should_block, $tag, $handle ) {
				if (
					strpos($handle, 'pmw') !== false
					|| strpos($handle, 'wpm') !== false
					|| strpos($tag, 'pmwDataLayer') !== false
				) {
					return false;
				}
				return $should_block;
			}, 10, 3);
		}

		/**
		 * FAZ Cookie Manager
		 *
		 * Whitelist PMW scripts so FAZ's script blocker leaves them alone.
		 * PMW handles consent internally and must not be blocked before consent.
		 *
		 * @since 1.58.12
		 */

		if (self::is_faz_cookie_manager_active()) {
			add_filter('faz_whitelisted_scripts', function ( $whitelist ) {
				$whitelist[] = 'pmwDataLayer';

				foreach (self::get_pmw_plugin_directory_slugs() as $slug) {
					$whitelist[] = '/wp-content/plugins/' . $slug;
				}

				return $whitelist;
			});
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
			 * Exclude PMW scripts from SGO's JS combination and minification.
			 * Combination and minification break webpack chunk loading.
			 *
			 * @since 1.59.0
			 */

			add_filter('sgo_javascript_combine_excluded_inline_content', [ __CLASS__, 'sg_optimizer_js_exclude_combine_inline_content' ]);
			add_filter('sgo_javascript_combine_exclude', [ __CLASS__, 'sgo_javascript_combine_exclude_move_after' ]);
			add_filter('sgo_javascript_combine_exclude_move_after', [ __CLASS__, 'sgo_javascript_combine_exclude_move_after' ]);
			add_filter('sgo_js_minify_exclude', [ __CLASS__, 'sg_optimizer_js_minify_exclude' ]);

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
		 * LiteSpeed Cache compatibility
		 *
		 * Exclude PMW scripts from LiteSpeed's JS optimization (minification/combination)
		 * and keep the ?ver cache buster on PMW's entry script. Plain deferring and inline
		 * optimization are fine and don't need exclusion.
		 *
		 * The delay-until-interaction exclusions are registered much earlier, from
		 * third_party_plugin_tweaks_on_plugins_loaded(), because LiteSpeed reads them
		 * on init priority 5.
		 *
		 * @since 1.59.0
		 */

		if (self::is_litespeed_active()) {
			add_filter('litespeed_optimize_js_excludes', [ __CLASS__, 'litespeed_optimize_js_excludes' ]);
			self::preserve_pmw_cache_buster_from_litespeed();

			/**
			 * Fires Litespeed nonce.
			 *
			 * @since 1.59.0
			 */
			do_action('litespeed_nonce', 'ajax-nonce');
			/**
			 * Fires Litespeed nonce.
			 *
			 * @since 1.59.0
			 */
			do_action('litespeed_nonce', 'wp_rest');
			/**
			 * Fires Litespeed nonce.
			 *
			 * @since 1.59.0
			 */
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
		 * TikTok for WooCommerce
		 *
		 * The plugin exposes no filter to switch its tracking off: its only
		 * apply_filters call suppresses debug output, and it fires no actions at
		 * all. Every event it sends comes from one of the static callbacks
		 * registered in its pixel/tt4b_pixel.php, and each injector emits BOTH
		 * transports, the Events API post and the browser pixel event, from the
		 * same method. So the callbacks are unhooked.
		 *
		 * They are all registered while the plugin file loads, long before
		 * plugins_loaded, and the earliest one can fire is woocommerce_add_to_cart
		 * on a wc-ajax add to cart request, which WooCommerce dispatches from
		 * template_redirect priority 0. Removing them from here, on init priority
		 * 0, is therefore in time for every path.
		 *
		 * Nothing else is touched: the catalog sync, the order sync, the settings
		 * screen, the Marketing API eligibility calls and the plugin's own
		 * tiktok_ttclid cookie all keep working. That cookie becomes unused once
		 * the events stop, and it stays: PMW reads _ttclid, written by TikTok's
		 * own events.js, never tiktok_ttclid, and policing another plugin's
		 * cookies is not PMW's job.
		 *
		 * Verified against TikTok for WooCommerce 1.4.1.
		 */

		if (Options::is_tiktok_active() && self::is_tiktok_for_woocommerce_active()) {
			self::disable_tiktok_for_woocommerce_tracking();
		}

		/**
		 * Triple Whale Pixel
		 *
		 * Triple Whale's own plugin installs the same Triple Pixel and reports
		 * add to cart and purchase from PHP hooks. It exposes no filter to
		 * switch that off, so its callbacks are unhooked while PMW's Triple
		 * Whale pixel is active. The pixel bootstrap itself guards against a
		 * second load, but the events would be reported twice.
		 *
		 * The plugin registers its hooks from a singleton created on
		 * plugins_loaded priority 10, so the removals cannot run from the
		 * plugins_loaded slot: the singleton might not exist yet, and asking
		 * for it would create it and register the very hooks we want gone.
		 * init priority 0 is after plugins_loaded has finished and before the
		 * earliest of its hooks, woocommerce_add_to_cart on a wc-ajax request
		 * from template_redirect priority 0.
		 *
		 * Verified against Triple Whale Pixel 1.0.4.
		 */

		if (Options::is_triple_whale_active() && self::is_triple_whale_pixel_plugin_active()) {
			self::disable_triple_whale_pixel_plugin_tracking();
		}

		/**
		 * Reddit for WooCommerce
		 */

		if (Options::is_reddit_active()) {

			add_filter('reddit_for_woocommerce_filter_tracking_data', function ( $data ) {
				$data['is_pixel_enabled']      = false;
				$data['is_conversion_enabled'] = false;
				return $data;
			});
		}

		/**
		 * WP Rocket compatibility
		 *
		 * Always exclude PMW's own scripts from WP Rocket's JS minification and combination.
		 * These features repackage/bundle scripts and break webpack chunk loading, causing
		 * ChunkLoadError when content hashes change between plugin updates.
		 *
		 * On cart, checkout, and order confirmation pages, also exclude from Delay JS.
		 * Delay JS defers script execution until user interaction, but users often leave
		 * the order confirmation page without interacting, causing missed purchase events.
		 * On other pages, delaying scripts until interaction is fine for performance.
		 *
		 * Defer JS is fine and doesn't need exclusion — it only changes load order,
		 * not whether scripts execute.
		 *
		 * @since 1.58.5
		 */

		if (self::is_wp_rocket_active()) {
			self::exclude_pmw_from_wp_rocket_minify_combine();
			self::exclude_pmw_from_wp_rocket_delay_js_on_critical_pages();
		}

		/**
		 * Flying Press compatibility
		 *
		 * On cart, checkout, and order confirmation pages, remove tracking script
		 * patterns from Flying Press's js_interaction_includes list. Flying Press
		 * delays scripts matching those patterns until user interaction, which breaks
		 * conversion tracking when users leave without interacting.
		 *
		 * @since 1.59.0
		 */

		if (self::is_flying_press_active()) {
			self::exclude_pmw_from_flying_press_delay_js_on_critical_pages();
		}

		/**
		 * Autoptimize compatibility
		 *
		 * Exclude PMW scripts from Autoptimize's JS minification and combination.
		 * These features repackage scripts and break webpack chunk loading.
		 *
		 * @since 1.59.0
		 */

		if (self::is_autoptimize_active()) {
			add_filter('autoptimize_filter_js_consider_minified', [ __CLASS__, 'autoptimize_filter_js_consider_minified' ]);
			add_filter('autoptimize_filter_js_dontmove', [ __CLASS__, 'autoptimize_filter_js_dontmove' ]);
		}

		/**
		 * WP-Optimize compatibility
		 *
		 * Exclude PMW scripts from WP-Optimize's JS minification.
		 *
		 * @since 1.59.0
		 */

		if (self::is_wp_optimize_active()) {
			add_filter('wp-optimize-minify-default-exclusions', [ __CLASS__, 'wp_optimize_minify_default_exclusions' ]);
		}

		/**
		 * Optimocha (Speed Booster Pack) compatibility
		 *
		 * Exclude PMW scripts from Optimocha's JS optimization.
		 *
		 * @since 1.59.0
		 */

		if (self::is_optimocha_active()) {
			self::exclude_pmw_from_optimocha_js_optimization();
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
			'name'   => 'pmwDataLayer',
			'urls'   => [
				'pmwDataLayer',
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

	private static function disable_woocommerce_google_ads_dynamic_remarketing() {
		// make sure to disable the WGDR plugin in case we use dynamic remarketing in this plugin
		add_filter('wgdr_third_party_cookie_prevention', '__return_true');
	}

	/**
	 * Unhooks every event sender of TikTok for WooCommerce.
	 *
	 * All eight callbacks are static class methods, so remove_action() matches
	 * them by their exact callback id and the class does not even have to be
	 * loaded yet. Priorities have to match the registration, and
	 * woocommerce_add_to_cart is the only one that is not the default 10.
	 *
	 * The option filter is a backstop rather than the mechanism. Every event
	 * path resolves its credentials through get_and_validate_option(), which
	 * treats false as "not configured": the two script printers return early and
	 * the injectors bail because the field array comes back empty. So an event
	 * hook added by a future release is stopped even though it is not in the
	 * list below. The filter is not used on its own because
	 * pixel_event_tracking_field_track() catches the resulting exception and logs
	 * it at debug level, and WooCommerce writes debug entries with its default
	 * settings, which would mean a log line per product view, add to cart,
	 * checkout render and confirmation page. As a backstop it only ever logs when
	 * something really did slip through the removals, which is exactly when we
	 * want to hear about it.
	 *
	 * tt4b_pixel_code is read nowhere outside the plugin's pixel class, so
	 * filtering it cannot affect the catalog sync, the order sync or the
	 * settings screen.
	 *
	 * @return void
	 *
	 * @since 1.65.2
	 */
	private static function disable_tiktok_for_woocommerce_tracking() {

		$pixel_class = 'Tt4b_Pixel_Class';

		remove_action('wp_head', [ $pixel_class, 'print_script' ]);
		remove_action('wp_enqueue_scripts', [ $pixel_class, 'add_ajax_snippet' ]);
		remove_action('woocommerce_before_single_product_summary', [ $pixel_class, 'inject_view_content_event' ]);
		remove_action('woocommerce_add_to_cart', [ $pixel_class, 'inject_add_to_cart_event' ], 40);
		remove_action('woocommerce_after_checkout_form', [ $pixel_class, 'inject_initiate_checkout_event' ]);
		remove_action('woocommerce_blocks_checkout_enqueue_data', [ $pixel_class, 'inject_initiate_checkout_event' ]);
		remove_action('woocommerce_payment_complete', [ $pixel_class, 'inject_purchase_event' ]);
		remove_action('woocommerce_thankyou', [ $pixel_class, 'inject_purchase_event' ]);

		add_filter('option_tt4b_pixel_code', '__return_false');
	}

	/**
	 * Unhooks every output of Triple Whale's own "Triple Whale Pixel" plugin.
	 *
	 * Two callbacks are instance methods of its twpwe_extension singleton, so
	 * remove_action() needs that very instance; instance() returns the one the
	 * plugin created on plugins_loaded. The other three are plain functions.
	 * Priorities match the registration; woocommerce_thankyou is the only one
	 * that is not the default 10.
	 *
	 * The pending-events transient the plugin writes for redirect add to carts
	 * is left alone: nothing reads it once wp_head no longer prints it.
	 *
	 * @return void
	 *
	 * @since 1.67.1
	 */
	private static function disable_triple_whale_pixel_plugin_tracking() {

		if (!class_exists('twpwe_extension')) {
			return;
		}

		$instance = \twpwe_extension::instance();

		remove_action('wp_head', [ $instance, 'inject_head' ], 10);
		remove_action('wp_enqueue_scripts', [ $instance, 'register_scripts' ], 10);
		remove_action('woocommerce_add_to_cart', 'twpwe_add_to_cart_handler', 10);
		remove_action('woocommerce_ajax_added_to_cart', 'twpwe_add_to_cart_ajax_handler', 10);
		remove_action('woocommerce_thankyou', 'twpwe_purchase_handler', 40);
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

	/**
	 * Filter out tracking script patterns from Flying Press's js_interaction_includes list
	 * that would delay PMW tracking scripts until user interaction.
	 *
	 * Flying Press delays scripts matching patterns in js_interaction_includes until user
	 * interaction (mouseover, keydown, touchstart, touchmove, wheel) or a 10-second timeout.
	 *
	 * The issue: PMW's inline pmwDataLayer script contains URLs like "fbevents_js_url":
	 * "https://connect.facebook.net/en_US/fbevents.js". Flying Press's pattern matching
	 * checks if ANY keyword appears ANYWHERE in the script tag (including inline content).
	 * So "fbevents.js" in js_interaction_includes matches the pmwDataLayer JSON, causing
	 * the ENTIRE initialization script to be delayed until user interaction.
	 *
	 * This breaks conversion tracking on the purchase confirmation page where users often
	 * don't interact with the page before leaving.
	 *
	 * @param array $includes The current js_interaction_includes array.
	 *
	 * @return array Filtered array with tracking patterns removed.
	 */
	private static function filter_flying_press_interaction_includes( $includes ) {

		// Patterns to remove from js_interaction_includes
		// These patterns would match tracking scripts that PMW loads and need to fire immediately
		$patterns_to_remove = [
			'googletagmanager.com',
			'google-analytics.com',
			'googleoptimize.com',
			'fbevents.js',
			'gtag',
		];

		return array_values(array_filter($includes, function ( $pattern ) use ( $patterns_to_remove ) {
			return ! in_array($pattern, $patterns_to_remove, true);
		}));
	}

	/**
	 * Always exclude PMW's own scripts from WP Rocket's JS minification and combination.
	 *
	 * Minification and combination repackage scripts, breaking webpack's dynamic chunk
	 * loading (ChunkLoadError).
	 *
	 * @since 1.58.5
	 */
	protected static function exclude_pmw_from_wp_rocket_minify_combine() {
		add_filter('rocket_exclude_js', [ __CLASS__, 'add_wp_rocket_core_exclusions' ]);
		add_filter('rocket_minify_excluded_external_js', [ __CLASS__, 'add_wp_rocket_core_exclusions' ]);
		add_filter('rocket_excluded_inline_js_content', [ __CLASS__, 'add_wp_rocket_core_exclusions' ]);
	}

	/**
	 * Exclude PMW scripts from WP Rocket's Delay JS on cart, checkout, and order pages.
	 *
	 * Delay JS defers script execution until user interaction. On order confirmation
	 * pages, users often leave without interacting, so purchase events would never fire.
	 * On other pages, delaying is fine for performance.
	 *
	 * @since 1.59.0
	 */
	protected static function exclude_pmw_from_wp_rocket_delay_js_on_critical_pages() {
		add_filter('rocket_delay_js_exclusions', function ( $exclusions ) {
			if (Helpers::is_cart_or_checkout_page()) {
				if (is_array($exclusions)) {
					$exclusions = array_unique(array_merge($exclusions, self::get_pmw_script_identifiers()));
				}
			}
			return $exclusions;
		});
	}

	/**
	 * Exclude PMW tracking scripts from Flying Press's delay-until-interaction on critical pages.
	 *
	 * Flying Press delays scripts matching patterns in js_interaction_includes until user
	 * interaction. On cart, checkout, and order confirmation pages, this breaks conversion
	 * tracking when users leave without interacting.
	 *
	 * @since 1.59.0
	 */
	protected static function exclude_pmw_from_flying_press_delay_js_on_critical_pages() {

		$filter_callback = function ( $options ) {
			if (
				isset($options['js_interaction_includes'])
				&& is_array($options['js_interaction_includes'])
				&& Helpers::is_cart_or_checkout_page()
			) {
				$options['js_interaction_includes'] = self::filter_flying_press_interaction_includes($options['js_interaction_includes']);
			}

			return $options;
		};

		add_filter('pre_update_option_FLYING_PRESS_CONFIG', $filter_callback);
		add_filter('option_FLYING_PRESS_CONFIG', $filter_callback);
	}

	/**
	 * Exclude PMW scripts from LiteSpeed's delay-until-interaction.
	 *
	 * LiteSpeed's "Load JS Deferred" setting has two modes. "Deferred" only changes the
	 * load order and is harmless. "Delayed" holds every script back until the visitor
	 * clicks, scrolls or moves the mouse. Shoppers routinely leave the order confirmation
	 * page without doing any of that, so in Delayed mode the purchase event is never sent.
	 * Guest Mode optimization always runs in Delayed mode.
	 *
	 * Unlike WP Rocket and FlyingPress, the exclusion cannot be limited to the cart,
	 * checkout and order confirmation pages: LiteSpeed reads both lists once, on init
	 * priority 5, long before WooCommerce's conditional tags can answer. The exclusion is
	 * therefore site wide, and only PMW's own scripts and its inline data layer are
	 * excluded. The vendor libraries are loaded by PMW at runtime, so they never appear
	 * in the HTML LiteSpeed rewrites and need no exclusion of their own.
	 *
	 * Both filters have to be registered before init priority 5, which is why this runs
	 * from third_party_plugin_tweaks_on_plugins_loaded() rather than from the init hook.
	 * Registering them unconditionally keeps is_plugin_active() and Options out of
	 * plugins_loaded; a filter nobody applies costs nothing.
	 *
	 * @return void
	 *
	 * @since 1.66.1
	 */
	protected static function exclude_pmw_from_litespeed_delay_js() {

		// Guest Mode optimization always delays JS until interaction.
		add_filter('litespeed_optm_gm_js_exc', [ __CLASS__, 'litespeed_optimize_js_excludes' ]);

		// Outside Guest Mode the same list covers "Deferred" and "Delayed". Only opt out
		// of "Delayed" (value 2) and leave plain deferring alone.
		add_filter('litespeed_optm_js_defer_exc', function ( $excludes ) {

			/**
			 * Reads LiteSpeed's own "Load JS Deferred" setting. 2 means "Delayed".
			 *
			 * @since 1.66.1
			 */
			if (2 !== (int) apply_filters('litespeed_conf', 'optm-js_defer')) {
				return $excludes;
			}

			return self::litespeed_optimize_js_excludes($excludes);
		});
	}

	/**
	 * Keep the ?ver cache buster on PMW's scripts when LiteSpeed strips query strings.
	 *
	 * LiteSpeed's "Remove Query Strings" (Page Optimization -> Tuning) drops the ?ver
	 * marker from every internal script URL. PMW's entry script loads its pixel code as
	 * webpack chunks whose file names change with every release, so once the URL is
	 * versionless a long lived browser or CDN cache keeps replaying the pre-update entry
	 * script, which then requests chunk names that no longer exist. The visible symptom
	 * is a 404 on a *.chunk.min.js file plus a ChunkLoadError, and with the consent chunk
	 * missing PMW falls back to deny-by-default and stops tracking altogether.
	 *
	 * LiteSpeed offers an opt-out marker for exactly this case: a src containing
	 * _litespeed_rm_qs=0 is returned untouched (optimize.cls.php, remove_query_strings()).
	 * The marker is added at priority 20, well before LiteSpeed's own filter at 999.
	 *
	 * @return void
	 *
	 * @since 1.66.1
	 */
	protected static function preserve_pmw_cache_buster_from_litespeed() {

		add_filter('script_loader_src', function ( $src, $handle ) {

			if (!in_array($handle, [ 'pmw', 'pmw-lazy' ], true)) {
				return $src;
			}

			// Nothing to protect if the URL carries no query string anyway.
			if (!is_string($src) || strpos($src, '?') === false) {
				return $src;
			}

			if (strpos($src, '_litespeed_rm_qs=') !== false) {
				return $src;
			}

			/**
			 * Reads LiteSpeed's own "Remove Query Strings" setting. Nothing to
			 * protect while it is switched off.
			 *
			 * @since 1.66.1
			 */
			if (!apply_filters('litespeed_conf', 'optm-qs_rm')) {
				return $src;
			}

			return $src . '&_litespeed_rm_qs=0';
		}, 20, 2);
	}

	/**
	 * Exclude PMW scripts from Optimocha (Speed Booster Pack) JS optimization.
	 *
	 * @since 1.59.0
	 */
	protected static function exclude_pmw_from_optimocha_js_optimization() {
		add_filter('option_sbp_options', function ( $options ) {

			if (isset($options['js_exclude'])) {
				$options['js_exclude'] = $options['js_exclude'] . PHP_EOL . implode(PHP_EOL, self::get_pmw_core_script_identifiers());
				$js_include            = explode(PHP_EOL, $options['js_include']);
				$js_include            = array_filter($js_include, function ( $string ) {
					foreach (self::get_pmw_core_script_identifiers() as $value) {
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

	/**
	 * Get PMW's core script identifiers for unconditional exclusions.
	 *
	 * Only includes PMW's own files and data layer — not third-party
	 * tracking scripts. Used for always-on minification/combination exclusions.
	 *
	 * @return string[]
	 *
	 * @since 1.58.5
	 */
	private static function get_pmw_core_script_identifiers() {
		return array_merge(
			// Plugin directory slug patterns (all distributions and historical variants)
			self::get_pmw_plugin_directory_slugs(),
			[
				// PMW data layer
				'pmwDataLayer',
				'window.pmwDataLayer',
				// Legacy data layer
				'wpmDataLayer',
				'window.wpmDataLayer',
				// Script handle identifiers
				'pmw',
				'pmw-js',
				'wpm',
				// Webpack chunk files
				'.chunk.min.js',
				// JS directory paths
				'js/public/free/',
				'js/public/pro/',
			]
		);
	}

	/**
	 * Get the plugin directory slugs PMW ships into.
	 *
	 * Covers every distribution (wp.org free, Freemius Pro, WooCommerce.com Pro)
	 * as well as the historical slugs. The slugs are matched as substrings, so
	 * `woocommerce-pixel-manager` also covers `woocommerce-pixel-manager-free`.
	 *
	 * @return string[]
	 *
	 * @since 1.64.1
	 */
	private static function get_pmw_plugin_directory_slugs() {
		return [
			'pixel-manager-pro-for-woocommerce',
			'pixel-manager-for-woocommerce',
			'woocommerce-google-adwords-conversion-tracking-tag',
			'woocommerce-pixel-manager',
			'woopt-pixel-manager',
		];
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
			// Plugin directory slug patterns (all historical variants)
			'pixel-manager-pro-for-woocommerce',
			'pixel-manager-for-woocommerce',
			'woocommerce-google-adwords-conversion-tracking-tag',
			'woocommerce-pixel-manager',
			'woopt-pixel-manager',
			// PMW identifiers (current naming)
			'pmw',
			'pmw-js',
			'pmwDataLayer',
			'window.pmwDataLayer',
			'pmw.js',
			'pmw.min.js',
			'pmw__premium_only.js',
			'pmw__premium_only.min.js',
			'pmw-public.p1.min.js',
			'pmw-public__premium_only.p1.min.js',
			// Legacy WPM identifiers (backwards compatibility)
			'wpm',
			'pmw-js',
			'wpmDataLayer',
			'window.wpmDataLayer',
			'wpm.js',
			'wpm.min.js',
			'wpm__premium_only.js',
			'wpm__premium_only.min.js',
			'pmw-public.p1.min.js',
			'pmw-public__premium_only.p1.min.js',
			// Webpack chunk files
			'.chunk.min.js',
			// Include paths for free and pro folders
			'js/public/free/',
			'js/public/pro/',
			'/free/',
			'/pro/',
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

//      error_log(reset($orders));

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

	/**
	 * Get the external object cache type if enabled.
	 *
	 * Checks for Redis or Memcached object caching.
	 *
	 * @return string The cache type ('Redis', 'Memcached') or 'no' if not enabled.
	 *
	 * @since 1.46.0
	 */
	public static function get_external_object_cache() {

		if ( null !== self::$external_object_cache ) {
			return self::$external_object_cache;
		}

		// Check for Redis
		if (class_exists('Redis')) {
			self::$external_object_cache = 'Redis';
			return self::$external_object_cache;
		}

		// Check for WP Redis plugin constant
		if (defined('WP_REDIS_DISABLED') && !WP_REDIS_DISABLED) {
			self::$external_object_cache = 'Redis';
			return self::$external_object_cache;
		}

		// Check for Memcached
		if (class_exists('Memcached') || class_exists('Memcache')) {
			self::$external_object_cache = 'Memcached';
			return self::$external_object_cache;
		}

		// Check object-cache.php drop-in for Redis or Memcached
		if (file_exists(WP_CONTENT_DIR . '/object-cache.php')) {
			$object_cache_content = file_get_contents(WP_CONTENT_DIR . '/object-cache.php');
			if (stripos($object_cache_content, 'redis') !== false) {
				self::$external_object_cache = 'Redis';
				return self::$external_object_cache;
			}
			if (stripos($object_cache_content, 'memcache') !== false) {
				self::$external_object_cache = 'Memcached';
				return self::$external_object_cache;
			}
		}

		self::$external_object_cache = 'no';
		return self::$external_object_cache;
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
