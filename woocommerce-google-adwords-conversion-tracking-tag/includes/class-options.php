<?php
/**
 * Options class
 * https://stackoverflow.com/a/55658771/4688612
 *
 * TODO: in an new db version move consent_management to the general section
 * TODO: change ->google->consent_mode->active to ->google->consent_mode->is_active
 */

namespace SweetCode\Pixel_Manager;

use SweetCode\Pixel_Manager\Admin\Environment;

defined('ABSPATH') || exit; // Exit if accessed directly

class Options {

	private static $options;
	private static $options_obj;
	public static  $options_backup_name = 'wgact_options_backup';

	private static $did_init = false;

	private static function init() {

		// If already initialized, do nothing
		if (self::$did_init) {
			return;
		}

		self::$did_init = true;

		self::$options = get_option(PMW_DB_OPTIONS_NAME);

		if (self::$options) { // If option retrieved, update it with new defaults

			// running the DB updater
			Database::run_options_db_upgrade();

			// Update options that are missing with defaults, recursively
			self::$options = self::update_with_defaults(self::$options, self::get_default_options());
		} else { // If option not available, get default options and save it

			self::$options = self::get_default_options();
			update_option(PMW_DB_OPTIONS_NAME, self::$options);
		}

		// Allow other plugins to modify the options before they are used
		self::$options = apply_filters('pmw_options', self::$options);

		self::$options_obj = self::encode_options_object(self::$options);
	}

	public static function invalidate_cache() {
		self::$did_init = false;
		self::$options  = null;
	}

	private function __construct() {
		// Do nothing
	}

	public static function get_options() {
		self::init();

		return self::$options;
	}

	public static function get_options_obj() {
		self::init();

		return self::$options_obj;
	}

	public static function encode_options_object( $options ) {

		// This is the most elegant way to convert an array to an object recursively
		$options_obj = json_decode(wp_json_encode($options));

		if (function_exists('get_woocommerce_currency')) {
			$options_obj->shop->currency = get_woocommerce_currency();
		}

		return $options_obj;
	}

	// get the default options
	public static function get_default_options() {

		// default options settings
		return [
			'bing'       => [
				'uet_tag_id'           => '',
				'enhanced_conversions' => false,
				'consent_mode'         => [
					'is_active' => true,
				],
			],
			'facebook'   => [
				'pixel_id'               => '',
				'microdata'              => false,
				'capi'                   => [
					'token'             => '',
					'test_event_code'   => '',
					'user_transparency' => [
						'process_anonymous_hits'             => false,
						'send_additional_client_identifiers' => false,
					],
				],
				'domain_verification_id' => '',
			],
			'google'     => [
				'ads'          => [
					'conversion_id'            => '',
					'conversion_label'         => '',
					'aw_merchant_id'           => '',
					'product_identifier'       => 0, // TODO: Move to general section
					'google_business_vertical' => 0,
					'dynamic_remarketing'      => true, // TODO is always active, can be removed
					'phone_conversion_number'  => '',
					'phone_conversion_label'   => '',
					'enhanced_conversions'     => false,
					'conversion_adjustments'   => [
						'conversion_name' => '',
					],
				],
				'analytics'    => [
					'universal'        => [                // TODO remove
														   'property_id' => '',
					],
					'ga4'              => [
						'measurement_id'          => '',
						'api_secret'              => '',
						'data_api'                => [
							'property_id' => '',
							'credentials' => [],
						],
						'page_load_time_tracking' => false,
					],
					'link_attribution' => false,
				],
				'optimize'     => [
					'container_id'         => '',
					'anti_flicker'         => false,
					'anti_flicker_timeout' => 4000,
				],
				'consent_mode' => [
					'active'  => true,
					'regions' => [],  // TODO: Move to the consent management section
				],
				'tcf_support'  => false,
				'user_id'      => false,
				'tag_gateway'  => [
					'measurement_path' => '',
				],
			],
			'hotjar'     => [
				'site_id' => '',
			],
			'pinterest'  => [
				'pixel_id'          => '',
				'ad_account_id'     => '',
				'enhanced_match'    => false,
				'advanced_matching' => false,
				'apic'              => [
					'token'                  => '',
					'process_anonymous_hits' => false,
				],
			],
			'snapchat'   => [
				'pixel_id'          => '',
				'advanced_matching' => false,
				'capi'              => [
					'token' => '',
				],
			],
			'tiktok'     => [
				'pixel_id'          => '',
				'advanced_matching' => false,
				'eapi'              => [
					'token'                  => '',
					'test_event_code'        => '',
					'process_anonymous_hits' => false,
				],
			],
			'twitter'    => [
				'pixel_id'  => '',
				'event_ids' => [
					'view_content'      => '',
					'search'            => '',
					'add_to_cart'       => '',
					'add_to_wishlist'   => '',
					'initiate_checkout' => '',
					'add_payment_info'  => '',
					'purchase'          => '',
				],
			],
			'pixels'     => [
				'ab_tasty'   => [
					'account_id' => '',
				],
				'adroll'     => [
					'advertiser_id' => '',
					'pixel_id'      => '',
				],
				'linkedin'   => [
					'partner_id'     => '',
					'conversion_ids' => [
						'search'         => '',
						'view_content'   => '',
						'add_to_list'    => '',
						'add_to_cart'    => '',
						'start_checkout' => '',
						'purchase'       => '',
					],
				],
				'optimizely' => [
					'project_id' => '',
				],
				'outbrain'   => [
					'advertiser_id' => '',
				],
				'reddit'     => [
					'advertiser_id'     => '',
					'advanced_matching' => false,
				],
				'taboola'    => [
					'account_id' => '',
				],
				'vwo'        => [
					'account_id' => '',
				],
			],
			'shop'       => [
				'order_total_logic'             => 0,
				// TODO: Move to the general section
				'cookie_consent_mgmt'           => [
					'explicit_consent' => false,
				],
				'order_deduplication'           => true,
				'disable_tracking_for'          => [],
				'order_list_info'               => true,
				'subscription_value_multiplier' => 1.00,
				'ltv'                           => [
					'order_calculation'       => [
						'is_active' => false,
					],
					'automatic_recalculation' => [
						'is_active' => false,
					],
				],
				'order_extra_details'           => [
					'is_active' => false,
				],
			],
			'general'    => [
				'variations_output'          => true,  // TODO maybe should be in the shop section
				'maximum_compatibility_mode' => false,
				'pro_version_demo'           => false,
				'scroll_tracker_thresholds'  => [],
				'lazy_load_pmw'              => false,
				'logger'                     => [
					'is_active'         => false,
					'level'             => 'warning',
					'log_http_requests' => false,
				],
				'pageview_events_s2s'        => false,
			],
			'db_version' => PMW_DB_VERSION,
			'timestamp'  => null, // This will be set when the options are saved
		];
	}

	/**
	 * Get the options backup
	 *
	 * @since 1.49.0
	 */
	public static function get_options_backup() {

		// Get the backup options from the database
		$backup_options = get_option(self::$options_backup_name);

		if ($backup_options) {
			return $backup_options;
		}

		return self::get_default_options();
	}

	/**
	 * Get the automatic options backup
	 *
	 * @since 1.49.0
	 */
	public static function get_automatic_options_backup() {
		$options_backup = self::get_options_backup();
		return isset($options_backup['auto']) ? $options_backup['auto'] : [];
	}

	public static function get_automatic_options_backup_by_timestamp( $timestamp ) {

		// Get the automatic options backup from the database
		$automatic_options_backup = self::get_automatic_options_backup();

		if (isset($automatic_options_backup[$timestamp])) {
			return $automatic_options_backup[$timestamp];
		}

		return [];
	}


	public static function update_with_defaults( $target_array, $default_array ) {

//		error_log(print_r($target_array, true));

		// Walk through every key in the default array
		foreach ($default_array as $default_key => $default_value) {

			// If the target key doesn't exist yet
			// copy all default values,
			// including the subtree if one exists,
			// into the target array.
			if (!isset($target_array[$default_key])) {
				$target_array[$default_key] = $default_value;

				// We only want to keep going down the tree
				// if the array contains more settings in an associative array,
				// otherwise we keep the settings of what's in the target array.
			} elseif (self::is_associative_array($default_value)) {

				$target_array[$default_key] = self::update_with_defaults($target_array[$default_key], $default_value);
			}
		}

//		error_log(print_r($target_array, true));
		return $target_array;
	}

	protected static function does_contain_nested_arrays( $array ) {

		foreach ($array as $key) {
			if (is_array($key)) {
				return true;
			}
		}

		return false;
	}

	protected static function is_associative_array( $array ) {

		if (is_array($array)) {
			return ( array_values($array) !== $array );
		} else {
			return false;
		}
	}

	public static function get_db_version() {
		return self::get_options_obj()->db_version;
	}

	/**
	 * Facebook (Meta)
	 */

	public static function get_facebook_pixel_id() {
		return self::get_options_obj()->facebook->pixel_id;
	}

	public static function is_facebook_active() {
		return (bool) self::get_facebook_pixel_id();
	}

	public static function get_facebook_capi_token() {
		return self::get_options_obj()->facebook->capi->token;
	}

	public static function get_facebook_capi_test_event_code() {
		return self::get_options_obj()->facebook->capi->test_event_code;
	}

	public static function is_facebook_capi_user_transparency_process_anonymous_hits_active() {
		return (bool) self::get_options_obj()->facebook->capi->user_transparency->process_anonymous_hits;
	}

	public static function is_facebook_capi_advanced_matching_enabled() {
		return (bool) self::get_options_obj()->facebook->capi->user_transparency->send_additional_client_identifiers;
	}

	public static function is_facebook_capi_active() {
		return self::is_facebook_active() && self::get_facebook_capi_token();
	}

	public static function is_facebook_microdata_active() {
		return (bool) self::get_options_obj()->facebook->microdata;
	}

	public static function get_facebook_domain_verification_id() {
		return self::get_options_obj()->facebook->domain_verification_id;
	}

	/**
	 * TikTok
	 */

	public static function get_tiktok_pixel_id() {
		return self::get_options_obj()->tiktok->pixel_id;
	}

	public static function get_tiktok_eapi_token() {
		return self::get_options_obj()->tiktok->eapi->token;
	}

	public static function get_tiktok_eapi_test_event_code() {
		return self::get_options_obj()->tiktok->eapi->test_event_code;
	}

	public static function is_tiktok_active() {
		return (bool) self::get_tiktok_pixel_id();
	}

	public static function is_tiktok_eapi_test_event_code_set() {
		return (bool) self::get_tiktok_eapi_test_event_code();
	}

	public static function is_tiktok_eapi_active() {
		return self::is_tiktok_active() && self::get_tiktok_eapi_token();
	}

	public static function is_tiktok_advanced_matching_enabled() {
		return (bool) self::get_options_obj()->tiktok->advanced_matching;
	}

	public static function is_tiktok_eapi_process_anonymous_hits_active() {
		return (bool) self::get_options_obj()->tiktok->eapi->process_anonymous_hits;
	}

	/**
	 * Hotjar
	 */

	public static function get_hotjar_site_id() {
		return self::get_options_obj()->hotjar->site_id;
	}

	public static function is_hotjar_enabled() {
		return (bool) self::get_hotjar_site_id();
	}

	/**
	 * Microsoft Ads (Bing)
	 */

	public static function get_bing_uet_tag_id() {
		return self::get_options_obj()->bing->uet_tag_id;
	}

	public static function is_bing_active() {
		return (bool) self::get_bing_uet_tag_id();
	}

	public static function is_bing_enhanced_conversions_enabled() {
		return (bool) self::get_options_obj()->bing->enhanced_conversions;
	}

	public static function is_bing_consent_mode_active() {
		return (bool) self::get_options_obj()->bing->consent_mode->is_active;
	}

	/**
	 * Snapchat
	 */

	public static function get_snapchat_pixel_id() {
		return self::get_options_obj()->snapchat->pixel_id;
	}

	public static function is_snapchat_active() {
		return (bool) self::get_snapchat_pixel_id();
	}

	public static function is_snapchat_advanced_matching_enabled() {
		return (bool) self::get_options_obj()->snapchat->advanced_matching;
	}

	public static function get_snapchat_capi_token() {
		return self::get_options_obj()->snapchat->capi->token;
	}

	public static function is_snapchat_capi_active() {
		return self::is_snapchat_active() && self::get_snapchat_capi_token();
	}

	/**
	 * Pinterest
	 */

	public static function get_pinterest_pixel_id() {
		return self::get_options_obj()->pinterest->pixel_id;
	}

	public static function is_pinterest_active() {
		return (bool) self::get_pinterest_pixel_id();
	}

	public static function get_pinterest_ad_account_id() {
		return self::get_options_obj()->pinterest->ad_account_id;
	}

	// https://help.pinterest.com/en/business/article/enhanced-match
	public static function is_pinterest_enhanced_match_enabled() {
		return (bool) self::get_options_obj()->pinterest->enhanced_match;
	}

	public static function get_pinterest_apic_token() {
		return self::get_options_obj()->pinterest->apic->token;
	}

	public static function is_pinterest_apic_active() {
		return self::get_pinterest_ad_account_id() && self::get_pinterest_apic_token();
	}

	public static function is_pinterest_advanced_matching_active() {
		return (bool) self::get_options_obj()->pinterest->advanced_matching;
	}

	public static function is_pinterest_apic_process_anonymous_hits_active() {
		return (bool) self::get_options_obj()->pinterest->apic->process_anonymous_hits;
	}

	/**
	 * Twitter
	 */

	public static function get_twitter_pixel_id() {
		return self::get_options_obj()->twitter->pixel_id;
	}

	public static function is_twitter_active() {
		return (bool) self::get_twitter_pixel_id();
	}

	public static function get_twitter_event_ids() {
		return self::get_options_obj()->twitter->event_ids;
	}

	public static function get_twitter_event_id( $event ) {
		return self::get_options_obj()->twitter->event_ids->$event;
	}

	/**
	 * Google
	 */

	public static function get_google_ads_conversion_id() {
		return self::get_options_obj()->google->ads->conversion_id;
	}

	public static function is_google_ads_active() {
		return (bool) self::get_google_ads_conversion_id();
	}

	public static function get_google_ads_conversion_label() {
		return self::get_options_obj()->google->ads->conversion_label;
	}

	public static function is_google_ads_purchase_conversion_enabled() {
		return self::is_google_ads_active() && self::get_google_ads_conversion_label();
	}

	public static function is_google_ads_conversion_active() {
		return self::get_google_ads_conversion_id() && self::get_google_ads_conversion_label();
	}

	public static function is_google_enhanced_conversions_active() {
		return (bool) self::get_options_obj()->google->ads->enhanced_conversions;
	}

	public static function is_google_ads_conversion_adjustments_active() {
		return self::is_google_ads_purchase_conversion_enabled() && self::is_google_ads_conversion_adjustments_conversion_name_set();
	}

	public static function get_google_ads_conversion_adjustments_conversion_name() {
		return self::get_options_obj()->google->ads->conversion_adjustments->conversion_name;
	}

	public static function is_google_ads_conversion_adjustments_conversion_name_set() {
		return (bool) self::get_google_ads_conversion_adjustments_conversion_name();
	}

	public static function get_google_ads_merchant_id() {
		$merchant_id = self::get_options_obj()->google->ads->aw_merchant_id;
		return empty($merchant_id) ? '' : (int) $merchant_id;
	}

	public static function is_google_ads_conversion_cart_data_enabled() {
		return self::is_google_ads_purchase_conversion_enabled() && self::get_google_ads_merchant_id();
	}

	public static function is_google_active() {
		return self::is_google_ads_active() || self::is_google_analytics_active();
	}

	public static function get_google_ads_business_vertical_id() {
		return self::get_options_obj()->google->ads->google_business_vertical;
	}

	public static function get_ga4_measurement_id() {
		return self::get_options_obj()->google->analytics->ga4->measurement_id;
	}

	public static function is_ga4_enabled() {
		return (bool) self::get_ga4_measurement_id();
	}

	public static function get_ga4_data_api_property_id() {
		return self::get_options_obj()->google->analytics->ga4->data_api->property_id;
	}

	public static function get_ga4_data_api_credentials() {
		return (array) self::get_options_obj()->google->analytics->ga4->data_api->credentials;
	}

	public static function get_ga4_data_api_credentials_client_email() {
		return self::get_options_obj()->google->analytics->ga4->data_api->credentials->client_email;
	}

	public static function get_ga4_data_api_credentials_private_key() {
		return self::get_options_obj()->google->analytics->ga4->data_api->credentials->private_key;
	}

	public static function is_ga4_data_api_active() {
		return
			self::get_ga4_data_api_property_id()
			&& !empty(self::get_ga4_data_api_credentials());
	}

	public static function is_google_analytics_active() {
		return self::is_ga4_enabled();
	}

	public static function get_ga4_mp_api_secret() {
		return self::get_options_obj()->google->analytics->ga4->api_secret;
	}

	public static function is_ga4_mp_active() {
		return self::is_ga4_enabled() && self::get_ga4_mp_api_secret();
	}

	public static function is_google_tcf_support_active() {
		return (bool) self::get_options_obj()->google->tcf_support;
	}

	public static function is_google_consent_mode_active() {
		return (bool) self::get_options_obj()->google->consent_mode->active;
	}

	public static function is_google_user_id_active() {
		return (bool) self::get_options_obj()->google->user_id;
	}

	public static function get_google_tag_gateway_measurement_path() {
		return self::get_options_obj()->google->tag_gateway->measurement_path;
	}

	public static function is_google_link_attribution_active() {
		return (bool) self::get_options_obj()->google->analytics->link_attribution;
	}

	public static function get_google_ads_phone_conversion_number() {
		return self::get_options_obj()->google->ads->phone_conversion_number;
	}

	public static function get_google_ads_phone_conversion_label() {
		return self::get_options_obj()->google->ads->phone_conversion_label;
	}

	public static function is_ga4_page_load_time_tracking_active() {
		return (bool) self::get_options_obj()->google->analytics->ga4->page_load_time_tracking;
	}

	public static function get_google_ads_product_identifier() {
		return (int) self::get_options_obj()->google->ads->product_identifier;
	}

	/**
	 * Adroll
	 */

	public static function get_adroll_advertiser_id() {
		return self::get_options_obj()->pixels->adroll->advertiser_id;
	}

	public static function is_adroll_advertiser_id_set() {
		return (bool) self::get_adroll_advertiser_id();
	}

	public static function get_adroll_pixel_id() {
		return self::get_options_obj()->pixels->adroll->pixel_id;
	}

	public static function is_adroll_pixel_id_set() {
		return (bool) self::get_adroll_pixel_id();
	}

	public static function is_adroll_active() {
		return self::is_adroll_advertiser_id_set() && self::is_adroll_pixel_id_set();
	}

	/**
	 * LinkedIn
	 */

	public static function get_linkedin_partner_id() {
		return self::get_options_obj()->pixels->linkedin->partner_id;
	}

	public static function is_linkedin_active() {
		return (bool) self::get_linkedin_partner_id();
	}

	public static function get_linkedin_conversion_id( $event ) {
		return self::get_options_obj()->pixels->linkedin->conversion_ids->$event;
	}

	public static function get_linkedin_conversion_ids() {
		return self::get_options_obj()->pixels->linkedin->conversion_ids;
	}

	/**
	 * Outbrain
	 */

	public static function get_outbrain_advertiser_id() {
		return self::get_options_obj()->pixels->outbrain->advertiser_id;
	}

	public static function is_outbrain_active() {
		return (bool) self::get_outbrain_advertiser_id();
	}

	/**
	 * Reddit
	 */

	public static function get_reddit_advertiser_id() {
		return self::get_options_obj()->pixels->reddit->advertiser_id;
	}

	public static function is_reddit_active() {
		return (bool) self::get_reddit_advertiser_id();
	}

	public static function is_reddit_advanced_matching_enabled() {
		return (bool) self::get_options_obj()->pixels->reddit->advanced_matching;
	}

	/**
	 * Taboola
	 */

	public static function get_taboola_account_id() {
		return self::get_options_obj()->pixels->taboola->account_id;
	}

	public static function is_taboola_active() {
		return (bool) self::get_taboola_account_id();
	}

	/**
	 * VWO
	 */

	public static function get_vwo_account_id() {
		return self::get_options_obj()->pixels->vwo->account_id;
	}

	public static function is_vwo_active() {
		return (bool) self::get_vwo_account_id();
	}

	/**
	 * Optimizely
	 */

	public static function get_optimizely_project_id() {
		return self::get_options_obj()->pixels->optimizely->project_id;
	}

	public static function is_optimizely_active() {
		return (bool) self::get_optimizely_project_id();
	}

	/**
	 * AB Tasty
	 */

	public static function get_ab_tasty_account_id() {
		return self::get_options_obj()->pixels->ab_tasty->account_id;
	}

	public static function is_ab_tasty_active() {
		return (bool) self::get_ab_tasty_account_id();
	}

	/**
	 * Logger
	 */

	public static function is_logging_enabled() {
		return (bool) self::get_options_obj()->general->logger->is_active;
	}

	public static function get_log_level() {
		return self::get_options_obj()->general->logger->level;
	}

	public static function is_http_request_logging_enabled() {
		return (bool) self::get_options_obj()->general->logger->log_http_requests;
	}

	public static function disable_http_request_logging() {
		self::init();
		self::$options['general']['logger']['log_http_requests'] = false;
		self::save_options_with_timestamp(self::$options, true);
	}

	/**
	 * Consent Management
	 */

	public static function get_restricted_consent_regions_raw() {
		return self::get_options_obj()->google->consent_mode->regions;
	}

	public static function are_restricted_consent_regions_set() {
		return !empty(self::get_options_obj()->google->consent_mode->regions);
	}

	public static function get_restricted_consent_regions() {

		$regions = self::get_restricted_consent_regions_raw();

		/**
		 * If the user selected the European Union,
		 * we have to add all EU country codes,
		 * then remove the 'EU' value.
		 */
		if (in_array('EU', $regions, true)) {
			$regions = array_diff(array_merge($regions, WC()->countries->get_european_union_countries()), [ 'EU' ]);
		}

		/**
		 * If any manipulation happened beforehand,
		 * make sure to deduplicate the values
		 * and make sure the array starts with a 0 key,
		 * otherwise the JSON output is wrong.
		 */
		return array_values(array_unique($regions));
	}

	public static function consent_management_is_explicit_consent_active_override() {
		return (bool) apply_filters('pmw_consent_management_is_explicit_consent_active', false);
	}

	public static function is_consent_management_explicit_consent_active() {
		return
			self::get_options_obj()->shop->cookie_consent_mgmt->explicit_consent
			|| self::consent_management_is_explicit_consent_active_override();
	}

	public static function get_cookie_consent_explicit_consent_input_field_name() {
		return PMW_DB_OPTIONS_NAME . '[shop][cookie_consent_mgmt][explicit_consent]';
	}

	/**
	 * General settings
	 */

	public static function get_scroll_tracking_thresholds() {
		return (array) self::get_options_obj()->general->scroll_tracker_thresholds;
	}

	public static function is_pro_version_demo_active() {

		if (!Helpers::is_pmw_wcm_distro() && wpm_fs()->is__premium_only()) {
			return false;
		}

		if (!Helpers::is_pmw_wcm_distro() && self::get_options_obj()->general->pro_version_demo) {
			return true;
		}

		if (Helpers::is_wcm_distro_free_version()) {
			return true;
		}

		// If transient _pmw_pro_version_demo_active is set to true, return true
		// This is used for the wordpress.org demo site
		if (get_transient('_pmw_pro_version_demo_active')) {
			return true;
		}

		// If the option is off and
		// if the server is playground.wordpress.net then return true.
		// This way the toggle will still work.
		if (
			!self::get_options_obj()->general->pro_version_demo
			&& Environment::is_on_playground_wordpress_net()
		) {
			return true;
		}

		return false;
	}

	public static function is_pageview_events_s2s_active() {
		return (bool) self::get_options_obj()->general->pageview_events_s2s;
	}

	public static function is_maximum_compatiblity_mode_active() {
		return (bool) self::get_options_obj()->general->maximum_compatibility_mode;
	}

	public static function is_lazy_load_pmw_active() {
		return (bool) self::get_options_obj()->general->lazy_load_pmw;
	}

	/**
	 * Ensure that lazy loading is only active if the optimizers (VWO, Optimizely, AB Tasty, etc.) allow it.
	 * The reason is, because optimizers might flicker the page during loading (when test variations are applied).
	 *
	 * @return bool
	 */
	public static function lazy_load_requirements() {

		// If Google Optimize is active we need to make sure that the Google Optimize anti flicker snippet is active too

//		if (self::is_google_optimize_active() && !self::is_google_optimize_anti_flicker_active()) {
//			return false;
//		}

		return true;
	}

	public static function is_at_least_one_statistics_pixel_active() {
		return self::is_ga4_enabled()
			|| self::is_hotjar_enabled()
			|| self::is_vwo_active();
	}

	public static function is_at_least_one_marketing_pixel_active() {
		return self::is_adroll_active()
			|| self::is_bing_active()
			|| self::is_facebook_active()
			|| self::is_google_ads_active()
			|| self::is_linkedin_active()
			|| self::is_outbrain_active()
			|| self::is_pinterest_active()
			|| self::is_reddit_active()
			|| self::is_snapchat_active()
			|| self::is_taboola_active()
			|| self::is_tiktok_active()
			|| self::is_twitter_active();
	}

	public static function server_2_server_enabled() {
		return
			self::is_facebook_capi_active()
			|| self::is_tiktok_eapi_active()
			|| self::is_pinterest_apic_active()
			|| self::is_snapchat_capi_active();
	}

	/**
	 * Returns the list of pixels that go through server-to-server pageview events.
	 *
	 * This is used to determine if they will be triggered through the pmw:page-view event.
	 * If yes, they will go through a detour and we have to ensure that the pixels are loaded
	 * before the pmw:page-view event is triggered.
	 *
	 * @return string[]
	 *
	 * @since 1.49.0
	 */
	public static function pixels_that_require_s2s_pageview_events() {
		$pixels = [];

		if (self::is_facebook_active()) {
			$pixels[] = 'facebook';
		}

		if (self::is_snapchat_active()) {
			$pixels[] = 'snapchat';
		}

		return $pixels;
	}

	public static function get_excluded_roles() {
		return (array) self::get_options_obj()->shop->disable_tracking_for;
	}

	/**
	 * Shop settings
	 */

	public static function is_order_duplication_prevention_option_active() {
		return (bool) self::get_options_obj()->shop->order_deduplication;
	}

	public static function is_order_duplication_prevention_option_disabled() {
		return !self::is_order_duplication_prevention_option_active();
	}

	public static function is_shop_variations_output_active() {
		return (bool) self::get_options_obj()->general->variations_output;
	}

	public static function is_order_level_ltv_calculation_active() {
		return (bool) self::get_options_obj()->shop->ltv->order_calculation->is_active;
	}

	public static function is_automatic_ltv_recalculation_active() {
		return (bool) self::get_options_obj()->shop->ltv->automatic_recalculation->is_active;
	}

	public static function enable_duplication_prevention() {
		self::init();
		self::$options['shop']['order_deduplication'] = true;
		self::save_options_with_timestamp(self::$options, true);
	}

	public static function get_marketing_value_logic() {
		return self::get_options_obj()->shop->order_total_logic;
	}

	public static function get_marketing_value_logic_input_field_name() {
		return PMW_DB_OPTIONS_NAME . '[shop][order_total_logic]';
	}

	public static function is_shop_order_list_info_enabled() {
		return (bool) self::get_options_obj()->shop->order_list_info;
	}

	public static function is_dynamic_remarketing_enabled() {
		return true;
	}

	public static function is_dynamic_remarketing_variations_output_enabled() {
		return (bool) self::get_options_obj()->general->variations_output;
	}

	public static function get_subscription_multiplier() {
		return self::get_options_obj()->shop->subscription_value_multiplier;
	}

	public static function is_order_extra_details_active() {
		return (bool) self::get_options_obj()->shop->order_extra_details->is_active;
	}

	/**
	 * Save options with timestamp and create automatic backup using the same timestamp
	 *
	 * @param array $options       The options array to save
	 * @param bool  $create_backup Whether to create an automatic backup before saving
	 * @since 1.50.0
	 */
	public static function save_options_with_timestamp( $options, $create_backup = true, $timestamp = null ) {

		if (null === $timestamp) {
			// If no timestamp is provided, use the current time
			$timestamp = time();
		}

		// Create automatic backup before saving if requested
		if ($create_backup) {
			self::save_automatic_options_backup_with_timestamp($timestamp);
		}

		// Set the timestamp in the options
		$options['timestamp'] = $timestamp;

		// Save the options
		update_option(PMW_DB_OPTIONS_NAME, $options);

		// Invalidate cache so new options are loaded
		self::invalidate_cache();
	}

	/**
	 * Create an automatic backup when options are updated with a specific timestamp
	 *
	 * @param int $timestamp The timestamp to use for the backup
	 *
	 * @since 1.49.0
	 */
	public static function save_automatic_options_backup_with_timestamp( $timestamp, $options = null ) {

		// Create the backup entry
		$options_backup = get_option(self::$options_backup_name, []);

		// Ensure the auto backup section exists
		if (!isset($options_backup['auto'])) {
			$options_backup['auto'] = [];
		}

		// Save the current options as backup using the provided timestamp
		if (null === $options) {
			$options = self::get_options();
		}

		$options_backup['auto'][$timestamp] = $options;

		// Apply retention policy with configurable settings
		$options_backup['auto'] = self::apply_backup_retention_policy($options_backup['auto']);

		// Save with autoload=false to avoid loading on every page request
		update_option(self::$options_backup_name, $options_backup, false);
	}

	/**
	 * Apply backup retention policy with configurable settings.
	 * Default policy optimized for infrequent changes:
	 * - Keep configurable number of most recent backups (default: 5)
	 * - Keep 1 per day for configurable days (default: 14 days)
	 * - Keep 1 per month for configurable months (default: 12 months)
	 * - Keep 1 per year forever (archival)
	 *
	 * This policy is optimized for plugins that have infrequent changes but when
	 * changes happen, they come in bursts.
	 *
	 * @param array $backups  Array of timestamp => options_data
	 * @param array $settings Optional. Retention policy settings
	 * @return array Filtered backups according to retention policy
	 *
	 * @since 1.49.0
	 */
	private static function apply_backup_retention_policy( $backups ) {

		if (empty($backups)) {
			return $backups;
		}

		// Default retention policy settings (can be overridden via parameter)
		$settings = self::get_backup_retention_settings();

		// Sort backups by timestamp (newest first)
		krsort($backups);

		$now               = time();
		$retained_backups  = [];
		$backup_timestamps = array_keys($backups);

		// Step 1: Keep the configurable number of most recent backups
		$recent_count = 0;
		foreach ($backup_timestamps as $timestamp) {
			if ($recent_count < $settings['recent_count']) {
				$retained_backups[$timestamp] = $backups[$timestamp];
				$recent_count++;
			} else {
				break;
			}
		}

		// Get the oldest of the recent backups to determine where daily retention starts
		$oldest_recent = $recent_count > 0 ? min(array_keys($retained_backups)) : $now;

		// Step 2: Keep 1 per day for past configurable days (excluding recent backups)
		$daily_backups = [];
		$daily_cutoff  = $oldest_recent - ( $settings['daily_retention'] * 24 * 60 * 60 );

		// Get the days that already have recent backups to exclude them from daily retention
		$recent_days = [];
		foreach ($retained_backups as $timestamp => $data) {
			$recent_days[gmdate('Y-m-d', $timestamp)] = true;
		}

		foreach ($backup_timestamps as $timestamp) {
			if ($timestamp >= $oldest_recent) {
				continue; // Already included in recent backups
			}

			if ($timestamp >= $daily_cutoff) {
				$day_key = gmdate('Y-m-d', $timestamp);

				// Skip days that already have recent backups
				if (isset($recent_days[$day_key])) {
					continue;
				}

				if (!isset($daily_backups[$day_key]) || $timestamp > $daily_backups[$day_key]['timestamp']) {
					$daily_backups[$day_key] = [
						'timestamp' => $timestamp,
						'data'      => $backups[$timestamp],
					];
				}
			}
		}

		// Add daily backups to retained
		foreach ($daily_backups as $day_backup) {
			$retained_backups[$day_backup['timestamp']] = $day_backup['data'];
		}

		// Get the oldest daily backup timestamp
		$oldest_daily = !empty($daily_backups) ? min(array_column($daily_backups, 'timestamp')) : $oldest_recent - ( $settings['daily_retention'] * 24 * 60 * 60 );

		// Step 3: Keep 1 per month for past configurable months
		$monthly_backups = [];
		$monthly_cutoff  = $oldest_daily - ( $settings['monthly_retention'] * 30 * 24 * 60 * 60 ); // months before oldest daily

		// Get all days that already have recent or daily backups
		$excluded_days = [];
		foreach ($retained_backups as $timestamp => $data) {
			$excluded_days[gmdate('Y-m-d', $timestamp)] = true;
		}

		foreach ($backup_timestamps as $timestamp) {
			if ($timestamp >= $oldest_daily) {
				continue; // Already included in recent or daily
			}

			if ($timestamp >= $monthly_cutoff) {
				$day_key = gmdate('Y-m-d', $timestamp);

				// Skip days that already have recent or daily backups
				if (isset($excluded_days[$day_key])) {
					continue;
				}

				$month_key = gmdate('Y-m', $timestamp);
				if (!isset($monthly_backups[$month_key]) || $timestamp > $monthly_backups[$month_key]['timestamp']) {
					$monthly_backups[$month_key] = [
						'timestamp' => $timestamp,
						'data'      => $backups[$timestamp],
					];
				}
			}
		}

		// Add monthly backups to retained
		foreach ($monthly_backups as $month_backup) {
			$retained_backups[$month_backup['timestamp']] = $month_backup['data'];
		}

		// Get the oldest monthly backup timestamp
		$oldest_monthly = !empty($monthly_backups) ? min(array_column($monthly_backups, 'timestamp')) : $monthly_cutoff;

		// Step 4: Keep 1 per year for everything older (archival) - only if enabled
		if ($settings['enable_yearly']) {
			$yearly_backups = [];

			// Update excluded days to include monthly backups as well
			foreach ($monthly_backups as $month_backup) {
				$excluded_days[gmdate('Y-m-d', $month_backup['timestamp'])] = true;
			}

			foreach ($backup_timestamps as $timestamp) {
				if ($timestamp >= $oldest_monthly) {
					continue; // Already included
				}

				$day_key = gmdate('Y-m-d', $timestamp);

				// Skip days that already have recent, daily, or monthly backups
				if (isset($excluded_days[$day_key])) {
					continue;
				}

				$year_key = gmdate('Y', $timestamp);
				if (!isset($yearly_backups[$year_key]) || $timestamp > $yearly_backups[$year_key]['timestamp']) {
					$yearly_backups[$year_key] = [
						'timestamp' => $timestamp,
						'data'      => $backups[$timestamp],
					];
				}
			}

			// Add yearly backups to retained
			foreach ($yearly_backups as $year_backup) {
				$retained_backups[$year_backup['timestamp']] = $year_backup['data'];
			}
		}

		return $retained_backups;
	}

	/**
	 * Get backup retention policy settings.
	 * This method can be overridden or filtered to customize retention behavior.
	 *
	 * @return array Backup retention settings
	 *
	 * @since 1.49.0
	 */
	public static function get_backup_retention_settings() {
		$default_settings = [
			'recent_count'      => 5,    // Number of most recent backups to keep
			'daily_retention'   => 14,   // Number of days to keep daily backups
			'monthly_retention' => 12,   // Number of months to keep monthly backups
			'enable_yearly'     => true, // Whether to keep yearly backups forever
		];

		/**
		 * Filter backup retention policy settings.
		 *
		 * @param array $settings Default retention settings
		 */
		return apply_filters('pmw_backup_retention_settings', $default_settings);
	}
}
