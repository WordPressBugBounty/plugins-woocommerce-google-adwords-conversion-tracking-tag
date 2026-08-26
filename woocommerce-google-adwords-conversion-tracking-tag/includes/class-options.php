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
use SweetCode\Pixel_Manager\Admin\Opportunities\Opportunities;

defined('ABSPATH') || exit; // Exit if accessed directly

class Options {

	private static $options;
	private static $options_obj;
	public static  $options_backup_name = 'wgact_options_backup';

	/**
	 * Fresh-install marker, written once when Options creates the initial
	 * defaults (see init()). Installs without it predate Nova. Since 1.62.0
	 * Nova is the default UI for every install; the marker now identifies
	 * fresh installs for the onboarding checklist.
	 *
	 * @since 1.59.0
	 */
	public static $default_admin_theme_option_name = 'pmw_default_admin_theme';

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

			// The updater may have rewritten the stored options (schema
			// migrations). Re-read so this very request already works with the
			// migrated tree instead of the pre-upgrade snapshot.
			self::$options = get_option(PMW_DB_OPTIONS_NAME);

			// Update options that are missing with defaults, recursively
			self::$options = self::update_with_defaults(self::$options, self::get_default_options());
		} else { // If option not available, get default options and save it

			self::$options = self::get_default_options();
			update_option(PMW_DB_OPTIONS_NAME, self::$options);

			// Fresh-install marker (no stored options yet). Used by the
			// onboarding checklist and to suppress the "Nova is now the
			// default" announcement on installs that started on Nova.
			add_option(self::$default_admin_theme_option_name, 'wp');
		}

		/**
		 * Allow other plugins to modify the options before they are used.
		 *
		 * @since 1.58.5
		 */
		self::$options = apply_filters('pmw_options', self::$options);

		self::$options_obj = self::encode_options_object(self::$options);
	}

	public static function invalidate_cache() {
		self::$did_init = false;
		self::$options  = null;
	}

	/**
	 * Reads a single value straight from the stored options, without
	 * initializing the filtered options tree.
	 *
	 * Some third party plugins decide whether to load their own tracking before
	 * shops can register their pmw_options filters. Google for WooCommerce makes
	 * that decision on plugins_loaded priority 20, Google Analytics for
	 * WooCommerce in its integration constructor on init priority 0. We still
	 * have to answer "is this pixel configured?" that early, and going through
	 * get_options_obj() there would run init() and cache the unfiltered tree for
	 * the rest of the request, which is the very thing those shop filters exist
	 * for.
	 *
	 * Once init() has run this delegates to the initialized tree, so callers
	 * arriving later keep seeing the filtered values.
	 *
	 * @param array $path Key path into the options array, for example [ 'google', 'ads', 'conversion_id' ].
	 *
	 * @return mixed|null The stored value, or null if the path is not set.
	 *
	 * @since 1.65.2
	 */
	private static function get_stored_option_value( array $path ) {

		$node = self::$did_init ? self::$options : get_option(PMW_DB_OPTIONS_NAME);

		foreach ($path as $key) {

			if (!is_array($node) || !isset($node[ $key ])) {
				return null;
			}

			$node = $node[ $key ];
		}

		return $node;
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
				'capi'                 => [
					'token' => '',
				],
				'consent_mode'         => [
					'is_active' => true,
				],
			],
			'facebook'   => [
				'pixel_id'               => '',
				'capi'                   => [
					'token'             => '',
					'test_event_code'   => '',
					'send_fb_login_id'  => false,
					'user_transparency' => [
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
					'data_manager'             => [
						'is_active'            => false,
						'mode'                 => 'multi_source', // 'multi_source' | 'separate_action'
						'operating_account_id' => '',
						'login_account_id'     => '',
						'conversion_action_id' => '',
						'auth_method'          => 'service_account', // 'service_account' | 'broker'
						'credentials'          => [],
						'validate_only'        => false,
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
			'crazyegg'   => [
				'account_number' => '',
			],
			'pinterest'  => [
				'pixel_id'          => '',
				'ad_account_id'     => '',
				'enhanced_match'    => false,
				'advanced_matching' => false,
				'apic'              => [
					'token' => '',
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
					'token'           => '',
					'test_event_code' => '',
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
						'view_content' => '',
						'add_to_cart'  => '',
						'purchase'     => '',
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
					'capi'              => [
						'token'           => '',
						'test_event_code' => '',
					],
				],
				'openai'     => [
					'pixel_id'          => '',
					'advanced_matching' => false,
					'capi'              => [
						'token' => '',
					],
				],
				'taboola'    => [
					'account_id' => '',
				],
				'vwo'        => [
					'account_id' => '',
				],
				'contentsquare' => [
					'tag_id' => '',
				],
				'clarity'    => [
					'project_id' => '',
				],
				'groundtruth' => [
					'gtid' => '',
				],
				'criteo'     => [
					'account_id'        => '',
					'advanced_matching' => false,
				],
				'nextdoor'   => [
					'pixel_id'          => '',
					'advanced_matching' => false,
					'capi'              => [
						'token'           => '',
						'test_event_code' => '',
					],
				],
				'triple_whale' => [
					'enabled'    => false,
					'orders_api' => [
						'token' => '',
					],
				],
				'hyros'      => [
					'product_hash'    => '',
					'application_tag' => '',
				],
				'mixpanel'   => [
					'project_token'       => '',
					'data_residency'      => 'us',
					'session_recording'   => false,
					'autocapture'         => false,
					'user_identification' => false,
					'ingestion_api'       => [
						'enabled' => false,
					],
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
				'pro_version_demo'           => false,
				'scroll_tracker_thresholds'  => [],
				'lazy_load_pmw'              => false,
				'logger'                     => [
					'is_active'         => false,
					'level'             => 'warning',
					'log_http_requests' => false,
				],
				'pageview_events_s2s'        => false,
				'always_send_s2s'            => false,
				'modules'                    => [
					'load_deprecated_functions' => true,
				],
				// When true, all plugin data (settings, backups, custom table, etc.)
				// is removed when the plugin is deleted. Read by uninstall.php.
				'delete_plugin_data_on_uninstall' => false,
			],
			'ssp'        => [
				'sync_token'              => '',
				'enabled'                 => false,
				'proxy_hostname'          => '',
				'domain_id'               => '',
				'routing_status'          => '',
				'config_status'           => '',
				'last_sync_at'            => 0,
				'last_sync_error'         => '',
				'resync_callback_token'   => '',
				'domain_token'            => '',
				'verification_key'        => '', // Deprecated: use domain_token
				'proxy_failure_behavior'  => 'fallback_to_wc', // 'fallback_to_wc' | 'drop_events'
				'plan_name'               => '',
				'subscription_status'     => '',
				'usage_percent'           => 0,
				'monthly_request_limit'   => 0,
				'billable_this_period'    => 0,
				'quota_exceeded'          => false,
				'activation_retry_start'  => 0,
				'additional_domain_keys'  => [], // Keyed by proxy_hostname, stores domain_token + resync_callback_token
				'destination_results'         => [], // Per-destination credential-validation results from the last sync
				'destination_results_checked' => 0, // Unix timestamp of the last destination health check
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

//      error_log(print_r($target_array, true));

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

//      error_log(print_r($target_array, true));
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

	public static function is_facebook_capi_advanced_matching_enabled() {
		return (bool) self::get_options_obj()->facebook->capi->user_transparency->send_additional_client_identifiers;
	}

	/**
	 * Whether the Facebook app-scoped login ID should be sent with CAPI events.
	 *
	 * Requires a supported third party social login plugin to supply the ID.
	 *
	 * @return bool
	 * @since 1.64.1
	 */
	public static function is_facebook_send_fb_login_id_enabled() {
		return (bool) self::get_options_obj()->facebook->capi->send_fb_login_id;
	}

	public static function is_facebook_capi_active() {
		return self::is_facebook_active() && self::get_facebook_capi_token();
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
	 * CrazyEgg
	 */

	public static function get_crazyegg_account_number() {
		return self::get_options_obj()->crazyegg->account_number;
	}

	public static function is_crazyegg_enabled() {
		return (bool) self::get_crazyegg_account_number();
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

	public static function get_bing_capi_token() {
		return self::get_options_obj()->bing->capi->token;
	}

	/**
	 * The Microsoft Advertising Conversions API uses the UET tag ID as its
	 * endpoint, so it needs both the tag ID and the token.
	 *
	 * @return bool
	 * @since 1.65.2
	 */
	public static function is_bing_capi_active() {
		return self::is_bing_active() && (bool) self::get_bing_capi_token();
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

	/**
	 * Google Ads activity check that is safe to call before init.
	 *
	 * Used by the Google for WooCommerce compatibility filter, which that plugin
	 * reads on plugins_loaded priority 20.
	 *
	 * @return bool
	 *
	 * @see Options::get_stored_option_value()
	 * @since 1.65.2
	 */
	public static function is_google_ads_active_early() {
		return (bool) self::get_stored_option_value([ 'google', 'ads', 'conversion_id' ]);
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

	/**
	 * Google Ads Data Manager API upload (experimental)
	 */

	public static function is_google_ads_dm_enabled() {
		return (bool) self::get_options_obj()->google->ads->data_manager->is_active;
	}

	public static function is_google_ads_dm_active() {
		return self::is_google_ads_dm_enabled()
			&& self::get_google_ads_dm_operating_account_id()
			&& self::get_google_ads_dm_conversion_action_id()
			&& (
				'broker' === self::get_google_ads_dm_auth_method()
				|| !empty(self::get_google_ads_dm_credentials())
			);
	}

	public static function get_google_ads_dm_mode() {
		return self::get_options_obj()->google->ads->data_manager->mode;
	}

	public static function get_google_ads_dm_operating_account_id() {
		return self::get_options_obj()->google->ads->data_manager->operating_account_id;
	}

	public static function get_google_ads_dm_login_account_id() {
		return self::get_options_obj()->google->ads->data_manager->login_account_id;
	}

	public static function get_google_ads_dm_conversion_action_id() {
		return self::get_options_obj()->google->ads->data_manager->conversion_action_id;
	}

	public static function get_google_ads_dm_auth_method() {
		return self::get_options_obj()->google->ads->data_manager->auth_method;
	}

	public static function get_google_ads_dm_credentials() {
		return (array) self::get_options_obj()->google->ads->data_manager->credentials;
	}

	public static function get_google_ads_dm_credentials_client_email() {
		return self::get_options_obj()->google->ads->data_manager->credentials->client_email;
	}

	public static function get_google_ads_dm_credentials_private_key() {
		return self::get_options_obj()->google->ads->data_manager->credentials->private_key;
	}

	public static function is_google_ads_dm_validate_only() {
		return (bool) self::get_options_obj()->google->ads->data_manager->validate_only;
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
		return self::get_ga4_data_api_property_id()
			&& !empty(self::get_ga4_data_api_credentials());
	}

	public static function is_google_analytics_active() {
		return self::is_ga4_enabled();
	}

	/**
	 * Google Analytics activity check that is safe to call before init.
	 *
	 * Used by the Google Analytics for WooCommerce compatibility filter, which
	 * that plugin reads in its integration constructor on init priority 0.
	 *
	 * @return bool
	 *
	 * @see Options::get_stored_option_value()
	 * @since 1.65.2
	 */
	public static function is_google_analytics_active_early() {
		return (bool) self::get_stored_option_value([ 'google', 'analytics', 'ga4', 'measurement_id' ]);
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

	public static function get_reddit_capi_token() {
		return self::get_options_obj()->pixels->reddit->capi->token;
	}

	public static function get_reddit_capi_test_event_code() {
		return self::get_options_obj()->pixels->reddit->capi->test_event_code;
	}

	public static function is_reddit_capi_active() {
		return self::is_reddit_active() && (bool) self::get_reddit_capi_token();
	}

	public static function is_reddit_capi_test_event_code_set() {
		return (bool) self::get_reddit_capi_test_event_code();
	}

	/**
	 * OpenAI
	 */

	public static function get_openai_pixel_id() {
		return self::get_options_obj()->pixels->openai->pixel_id;
	}

	public static function is_openai_active() {
		return (bool) self::get_openai_pixel_id();
	}

	public static function is_openai_advanced_matching_enabled() {
		return (bool) self::get_options_obj()->pixels->openai->advanced_matching;
	}

	public static function get_openai_capi_token() {
		return self::get_options_obj()->pixels->openai->capi->token;
	}

	public static function is_openai_capi_active() {
		return self::is_openai_active() && (bool) self::get_openai_capi_token();
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
	 * Contentsquare
	 */

	public static function get_contentsquare_tag_id() {
		return self::get_options_obj()->pixels->contentsquare->tag_id;
	}

	public static function is_contentsquare_active() {
		return (bool) self::get_contentsquare_tag_id();
	}

	/**
	 * Microsoft Clarity
	 */

	public static function get_clarity_project_id() {
		return self::get_options_obj()->pixels->clarity->project_id;
	}

	public static function is_clarity_active() {
		return (bool) self::get_clarity_project_id();
	}

	/**
	 * GroundTruth
	 */

	public static function get_groundtruth_gtid() {
		return self::get_options_obj()->pixels->groundtruth->gtid;
	}

	public static function is_groundtruth_active() {
		return (bool) self::get_groundtruth_gtid();
	}

	/**
	 * Criteo
	 */

	public static function get_criteo_account_id() {
		return self::get_options_obj()->pixels->criteo->account_id;
	}

	public static function is_criteo_active() {
		return (bool) self::get_criteo_account_id();
	}

	public static function is_criteo_advanced_matching_enabled() {
		return (bool) self::get_options_obj()->pixels->criteo->advanced_matching;
	}

	/**
	 * Nextdoor
	 */

	public static function get_nextdoor_pixel_id() {
		return self::get_options_obj()->pixels->nextdoor->pixel_id;
	}

	public static function is_nextdoor_active() {
		return (bool) self::get_nextdoor_pixel_id();
	}

	public static function is_nextdoor_advanced_matching_enabled() {
		return (bool) self::get_options_obj()->pixels->nextdoor->advanced_matching;
	}

	public static function get_nextdoor_capi_token() {
		return self::get_options_obj()->pixels->nextdoor->capi->token;
	}

	public static function get_nextdoor_capi_test_event_code() {
		return self::get_options_obj()->pixels->nextdoor->capi->test_event_code;
	}

	public static function is_nextdoor_capi_active() {
		return self::is_nextdoor_active() && (bool) self::get_nextdoor_capi_token();
	}

	public static function is_nextdoor_capi_test_event_code_set() {
		return (bool) self::get_nextdoor_capi_test_event_code();
	}

	/**
	 * Triple Whale
	 */

	public static function is_triple_whale_active() {
		return (bool) self::get_options_obj()->pixels->triple_whale->enabled;
	}

	public static function get_triple_whale_orders_api_token() {
		return self::get_options_obj()->pixels->triple_whale->orders_api->token;
	}

	public static function is_triple_whale_orders_api_active() {
		return self::is_triple_whale_active() && self::get_triple_whale_orders_api_token();
	}

	/**
	 * Hyros
	 *
	 * @since 1.63.1
	 */

	public static function get_hyros_product_hash() {
		return self::get_options_obj()->pixels->hyros->product_hash;
	}

	public static function is_hyros_active() {
		return (bool) self::get_hyros_product_hash();
	}

	/**
	 * The application tag as configured by the shop, which can be empty.
	 *
	 * @since 1.63.1
	 *
	 * @return string
	 */
	public static function get_hyros_application_tag() {
		return self::get_options_obj()->pixels->hyros->application_tag;
	}

	/**
	 * The application tag Hyros attributes to visitors when they land on a tracked page.
	 * Hyros defaults to !clicked when no custom tag has been configured.
	 *
	 * @since 1.63.1
	 *
	 * @return string
	 */
	public static function get_hyros_effective_application_tag() {

		$tag = self::get_hyros_application_tag();

		return $tag ? $tag : '!clicked';
	}

	/**
	 * Mixpanel
	 *
	 * @since 1.64.1
	 */

	public static function get_mixpanel_project_token() {
		return self::get_options_obj()->pixels->mixpanel->project_token;
	}

	public static function is_mixpanel_active() {
		return (bool) self::get_mixpanel_project_token();
	}

	/**
	 * The Mixpanel data residency region the project is hosted in.
	 *
	 * Events sent to the wrong region are not ingested, so the region drives both
	 * the browser SDK's api_host and the Ingestion API endpoint.
	 *
	 * @since 1.64.1
	 *
	 * @return string One of us, eu, in
	 */
	public static function get_mixpanel_data_residency() {

		$region = self::get_options_obj()->pixels->mixpanel->data_residency;

		return in_array($region, [ 'us', 'eu', 'in' ], true) ? $region : 'us';
	}

	/**
	 * The Mixpanel API host matching the configured data residency region.
	 *
	 * @since 1.64.1
	 *
	 * @return string
	 */
	public static function get_mixpanel_api_host() {

		$region = self::get_mixpanel_data_residency();

		if ('eu' === $region) {
			return 'https://api-eu.mixpanel.com';
		}

		if ('in' === $region) {
			return 'https://api-in.mixpanel.com';
		}

		return 'https://api.mixpanel.com';
	}

	public static function is_mixpanel_session_recording_enabled() {
		return (bool) self::get_options_obj()->pixels->mixpanel->session_recording;
	}

	public static function is_mixpanel_autocapture_enabled() {
		return (bool) self::get_options_obj()->pixels->mixpanel->autocapture;
	}

	public static function is_mixpanel_user_identification_enabled() {
		return (bool) self::get_options_obj()->pixels->mixpanel->user_identification;
	}

	public static function is_mixpanel_ingestion_api_enabled() {
		return (bool) self::get_options_obj()->pixels->mixpanel->ingestion_api->enabled;
	}

	/**
	 * The Mixpanel Ingestion API only becomes active once the project token is set,
	 * since the token is the credential the API authenticates with.
	 *
	 * @since 1.64.1
	 *
	 * @return bool
	 */
	public static function is_mixpanel_ingestion_api_active() {
		return self::is_mixpanel_active() && self::is_mixpanel_ingestion_api_enabled();
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
		/**
		 * Filters Consent management is explicit consent active.
		 *
		 * @since 1.58.5
		 */
		return (bool) apply_filters('pmw_consent_management_is_explicit_consent_active', false);
	}

	public static function is_consent_management_explicit_consent_active() {
		return self::get_options_obj()->shop->cookie_consent_mgmt->explicit_consent
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
		return (bool) self::get_options_obj()->general->pageview_events_s2s
			|| self::is_pageview_events_s2s_active_override();
	}

	/**
	 * Check if pageview S2S is force-enabled by an external condition.
	 *
	 * Returns true when the SweetCode Server-Side Proxy is active,
	 * because pageview events can be offloaded to the proxy without
	 * adding load to the WooCommerce server.
	 *
	 * @return bool
	 * @since 1.57.1
	 */
	public static function is_pageview_events_s2s_active_override() {
		return self::is_ssp_active();
	}

	/**
	 * Check if server-side events should always be sent, even when browser-side pixels haven't loaded.
	 *
	 * When enabled, S2S events are sent to ad platforms independently of browser pixel state.
	 * Browser-side tracking remains unaffected — only server-side events are sent independently.
	 *
	 * @return bool
	 * @since 1.57.0
	 */
	public static function is_always_send_s2s_active() {
		return (bool) self::get_options_obj()->general->always_send_s2s;
	}

	public static function is_lazy_load_pmw_active() {
		return (bool) self::get_options_obj()->general->lazy_load_pmw;
	}

	/**
	 * Whether all plugin data should be deleted when the plugin is uninstalled.
	 *
	 * The actual deletion happens in uninstall.php (which reads the raw option,
	 * since the plugin classes aren't loaded during uninstall).
	 *
	 * @return bool
	 * @since 1.59.0
	 */
	public static function is_delete_plugin_data_on_uninstall_active() {
		return (bool) self::get_options_obj()->general->delete_plugin_data_on_uninstall;
	}

	/**
	 * Check if a specific module should be loaded.
	 *
	 * @param string $module_name The name of the module to check.
	 * @return bool True if the module should be loaded, false otherwise.
	 * @since 1.51.0
	 */
	public static function should_load_module( $module_name ) {
		$options = self::get_options_obj();

		if (isset($options->general->modules->$module_name)) {
			return (bool) $options->general->modules->$module_name;
		}

		// Default to true for backward compatibility
		return true;
	}

	/**
	 * Check if deprecated functions module should be loaded.
	 *
	 * @return bool True if deprecated functions should be loaded.
	 * @since 1.51.0
	 */
	public static function should_load_deprecated_functions() {
		return self::should_load_module('load_deprecated_functions');
	}

	/**
	 * Ensure that lazy loading is only active if the optimizers (VWO, Optimizely, AB Tasty, etc.) allow it.
	 * The reason is, because optimizers might flicker the page during loading (when test variations are applied).
	 *
	 * @return bool
	 */
	public static function lazy_load_requirements() {

		// If Google Optimize is active we need to make sure that the Google Optimize anti flicker snippet is active too

//      if (self::is_google_optimize_active() && !self::is_google_optimize_anti_flicker_active()) {
//          return false;
//      }

		return true;
	}

	/**
	 * Check if any statistics pixels are active
	 *
	 * Uses the Pixel_Registry to automatically detect all active statistics pixels.
	 * No manual updates needed when adding new statistics pixels - just ensure they
	 * implement Pixel_Descriptor with get_category() returning 'statistics'.
	 *
	 * @return bool True if at least one statistics pixel is active
	 * @since 1.52.0 Refactored to use pixel registry for automatic detection
	 */
	public static function is_at_least_one_statistics_pixel_active() {
		return \SweetCode\Pixel_Manager\Pixels\Core\Pixel_Registry::has_active_statistics_pixels();
	}

	/**
	 * Check if any marketing pixels are active
	 *
	 * Uses the Pixel_Registry to automatically detect all active marketing pixels.
	 * No manual updates needed when adding new marketing pixels - just ensure they
	 * implement Pixel_Descriptor with get_category() returning 'marketing'.
	 *
	 * @return bool True if at least one marketing pixel is active
	 * @since 1.52.0 Refactored to use pixel registry for automatic detection
	 */
	public static function is_at_least_one_marketing_pixel_active() {
		return \SweetCode\Pixel_Manager\Pixels\Core\Pixel_Registry::has_active_marketing_pixels();
	}

	/**
	 * Check if any server-to-server (S2S) integrations are enabled
	 *
	 * This method uses the Pixel_Registry to automatically detect all available S2S integrations.
	 *
	 * When adding a new S2S pixel:
	 * 1. Create the adapter class extending Abstract_Pixel_Adapter (e.g., MyPixel_Adapter)
	 * 2. Implement is_available() to check if your pixel is configured
	 * 3. Add `new MyPixel_Adapter();` at the bottom of your adapter file (auto-registers)
	 * 4. Load the adapter class in Pixel_Manager::__construct() with class_exists()
	 *
	 * That's it! The registry will automatically detect it - no updates needed here.
	 *
	 * @return bool True if at least one S2S integration is active
	 * @since 1.52.0 Refactored to use adapter registry for automatic detection
	 */
	public static function server_2_server_enabled() {
		// Use the pixel registry for dynamic detection
		if (class_exists('\SweetCode\Pixel_Manager\Pixels\Core\Pixel_Registry')) {
			return \SweetCode\Pixel_Manager\Pixels\Core\Pixel_Registry::has_available_adapters();
		}

		// Fallback for edge cases where registry isn't loaded (shouldn't happen in normal flow)
		return false;
	}

	/**
	 * Whether any server-side destination exists that the "always send
	 * server-side events" setting can apply to.
	 *
	 * This is deliberately broader than server_2_server_enabled(). The pixel
	 * registry only knows about adapters, and an adapter is the dispatch target
	 * for browser-originated funnel events (Meta CAPI and the like). GA4's
	 * Measurement Protocol has no adapter, and shouldn't have one, because it
	 * only sends purchases and refunds off WooCommerce order hooks and would
	 * double-count against the browser tag on funnel events.
	 *
	 * It is still governed by this setting though: Google_MP_GA4 extends S2S,
	 * and S2S::is_purchase_suppressed_by_consent() consults
	 * is_always_send_s2s_active() before suppressing a purchase. So a shop that
	 * runs Google only does have a destination this setting governs, and must
	 * not be told the setting is inert.
	 *
	 * @return bool
	 * @since 1.64.1
	 */
	public static function always_send_s2s_has_destination() {
		return self::server_2_server_enabled() || (bool) self::is_ga4_mp_active();
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

		if (self::is_reddit_active()) {
			$pixels[] = 'reddit';
		}

		if (self::is_openai_active()) {
			$pixels[] = 'openai';
		}

		return $pixels;
	}

	/**
	 * Returns the list of pixels that have active server-to-server (S2S/CAPI) tracking.
	 *
	 * Checks Facebook CAPI, TikTok Events API, Pinterest API for Conversions,
	 * Snapchat CAPI, and Reddit CAPI.
	 *
	 * @return string[] Array of pixel slugs with active S2S.
	 * @since 1.57.1
	 */
	public static function pixels_with_active_s2s() {
		$pixels = [];

		if (self::is_facebook_capi_active()) {
			$pixels[] = 'facebook';
		}

		if (self::is_tiktok_eapi_active()) {
			$pixels[] = 'tiktok';
		}

		if (self::is_pinterest_apic_active()) {
			$pixels[] = 'pinterest';
		}

		if (self::is_snapchat_capi_active()) {
			$pixels[] = 'snapchat';
		}

		if (self::is_reddit_capi_active()) {
			$pixels[] = 'reddit';
		}

		if (self::is_openai_capi_active()) {
			$pixels[] = 'openai';
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

	/**
	 * No longer read anywhere.
	 *
	 * The automatic LTV drift detection this gated was disabled because it
	 * caused performance problems on large shops, and its settings field was
	 * removed in 1.63.1. The option key stays in the options tree so saved
	 * values and options backups remain valid, and this accessor stays so any
	 * third-party code calling it does not fatal. A full recalculation is a
	 * manual operation now. See LTV::calculate_pmw_order_values().
	 *
	 * @deprecated 1.63.1 The setting it reads no longer has any effect.
	 */
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

		// Almost every opportunity's availability is derived from the settings,
		// so the cached admin-menu badge count is stale after a save.
		// @since 1.63.1
		Opportunities::flush_active_opportunities_count_cache();
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
				++$recent_count;
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
	 * Check if server-side events (purchase, refund, etc.) should be routed through the SSP.
	 *
	 * Currently returns is_ssp_active(): when SSP is active, server-side events
	 * (purchase + GA4 refund payloads) go through it. Add a toggle here in the
	 * future if we want to let users control this separately.
	 *
	 * @return bool
	 * @since 1.57.0
	 */
	public static function should_process_server_events_via_ssp() {
		return self::is_ssp_active();
	}

	/**
	 * Get the SSP purchase events URL.
	 *
	 * Uses the SSP API base (supports PMW_SSP_API_BASE override for local dev)
	 * with the /v1/sync/purchase-events path.
	 *
	 * @return string Full URL for the SSP purchase events endpoint.
	 * @since 1.57.0
	 */
	public static function get_ssp_purchase_events_url() {

		return self::get_ssp_api_base() . '/v1/sync/purchase-events';
	}

	/**
	 * Get the SSP dispatch status URL.
	 *
	 * Asks the SSP whether it already dispatched a given dispatch key. Used
	 * after an inconclusive purchase POST (timeout, 5xx) to decide whether the
	 * direct-send fallback would duplicate an event the SSP already delivered.
	 *
	 * @return string Full URL for the SSP dispatch status endpoint.
	 * @since 1.66.0
	 */
	public static function get_ssp_dispatch_status_url() {

		return self::get_ssp_api_base() . '/v1/sync/dispatch-status';
	}

	/**
	 * The SSP API base, with the PMW_SSP_API_BASE override for local dev.
	 *
	 * @return string
	 * @since 1.66.0
	 */
	private static function get_ssp_api_base() {

		return defined( 'PMW_SSP_API_BASE' ) ? PMW_SSP_API_BASE : 'https://ssp.sweetcode.cloud';
	}

	/**
	 * Check if the SSP (Server Side Proxy) is fully active and operational.
	 *
	 * Returns true only when all conditions are met:
	 * - SSP is enabled
	 * - A sync token is set
	 * - The proxy domain routing is active
	 * - Config has been successfully synced
	 *
	 * @return bool
	 * @since 1.57.0
	 */
	public static function is_ssp_active() {
		$options = self::get_options();

		return !empty($options['ssp']['enabled'])
			&& !empty($options['ssp']['sync_token'])
			&& 'active' === ( isset($options['ssp']['routing_status']) ? $options['ssp']['routing_status'] : '' )
			&& 'synced' === ( isset($options['ssp']['config_status']) ? $options['ssp']['config_status'] : '' );
	}

	/**
	 * Check if the SSP is configured (enabled with a sync token) but not
	 * necessarily fully operational.
	 *
	 * Unlike is_ssp_active(), this does NOT require routing_status or
	 * config_status to have reached their final states. Used by the daily
	 * sync scheduler so that background syncs can still run while DNS is
	 * propagating or config is pending — allowing those statuses to
	 * self-heal.
	 *
	 * @return bool
	 * @since 1.57.1
	 */
	public static function is_ssp_configured() {
		$options = self::get_options();

		return !empty($options['ssp']['enabled'])
			&& !empty($options['ssp']['sync_token']);
	}

	/**
	 * Get the SSP proxy hostname.
	 *
	 * @return string The proxy hostname (e.g. "ssp.myshop.com")
	 * @since 1.57.0
	 */
	public static function get_ssp_proxy_hostname() {
		$options = self::get_options();
		return isset($options['ssp']['proxy_hostname']) ? $options['ssp']['proxy_hostname'] : '';
	}

	/**
	 * Get the SSP events URL for the proxy.
	 *
	 * @return string Full URL for the SSP events endpoint (e.g. "https://ssp.myshop.com/v1/pmw-events")
	 * @since 1.57.0
	 */
	public static function get_ssp_events_url() {

		// Allow overriding for local development:
		// define( 'PMW_SSP_EVENTS_URL', 'http://localhost:8787/v1/pmw-events' );
		if ( defined( 'PMW_SSP_EVENTS_URL' ) ) {
			return PMW_SSP_EVENTS_URL;
		}

		$hostname = self::get_ssp_proxy_hostname();

		if (empty($hostname)) {
			return '';
		}

		return 'https://' . $hostname . '/v1/pmw-events';
	}

	/**
	 * Get the SSP sync token.
	 *
	 * @return string
	 * @since 1.57.0
	 */
	public static function get_ssp_sync_token() {
		$options = self::get_options();
		return isset($options['ssp']['sync_token']) ? $options['ssp']['sync_token'] : '';
	}

	/**
	 * Get the SSP resync callback token.
	 *
	 * @return string
	 * @since 1.57.0
	 */
	public static function get_ssp_resync_callback_token() {
		$options = self::get_options();
		return isset($options['ssp']['resync_callback_token']) ? $options['ssp']['resync_callback_token'] : '';
	}

	/**
	 * Get the SSP domain token.
	 *
	 * This token is provisioned by the SSP during domain-config sync
	 * and used to authenticate proxy requests via X-SSP-Token header.
	 *
	 * @return string The 64-char hex domain token, or empty string.
	 * @since 1.58.5
	 */
	public static function get_ssp_domain_token() {
		$options = self::get_options();
		return isset( $options['ssp']['domain_token'] ) ? $options['ssp']['domain_token'] : '';
	}

	/**
	 * Get the SSP verification key.
	 *
	 * @deprecated Use get_ssp_domain_token() instead.
	 * @return string
	 */
	public static function get_ssp_verification_key() {
		return self::get_ssp_domain_token();
	}

	/**
	 * Get the SSP proxy failure behavior setting.
	 *
	 * Determines what happens when the SSP proxy is unreachable:
	 * - 'fallback_to_wc': Fall back to PMW's internal WooCommerce event router (default)
	 * - 'drop_events': Drop server-side events entirely
	 *
	 * @return string 'fallback_to_wc' or 'drop_events'
	 * @since 1.57.0
	 */
	public static function get_ssp_proxy_failure_behavior() {
		$options  = self::get_options();
		$behavior = isset($options['ssp']['proxy_failure_behavior']) ? $options['ssp']['proxy_failure_behavior'] : 'fallback_to_wc';

		// Validate against allowed values
		if ( ! in_array( $behavior, [ 'fallback_to_wc', 'drop_events' ], true ) ) {
			return 'fallback_to_wc';
		}

		return $behavior;
	}

	/**
	 * Check if the SSP monthly quota has been exceeded.
	 *
	 * When true, PMW should stop sending events to the SSP proxy
	 * and fall back to WC REST API or drop events per the
	 * proxy_failure_behavior setting.
	 *
	 * @return bool
	 * @since 1.57.0
	 */
	public static function is_ssp_quota_exceeded() {
		return ! empty( self::get_options()['ssp']['quota_exceeded'] );
	}

	/**
	 * Get the per-destination credential-validation results from the last sync.
	 *
	 * Each entry is an array with keys: type, ok, status_code, error.
	 *
	 * @return array
	 * @since 1.59.0
	 */
	public static function get_ssp_destination_results() {
		$options = self::get_options();
		$results = isset($options['ssp']['destination_results']) ? $options['ssp']['destination_results'] : [];
		return is_array($results) ? $results : [];
	}

	/**
	 * Get the SSP destinations that failed their credential-validation probe
	 * during the last sync.
	 *
	 * @return array List of failed destination result entries (ok === false).
	 * @since 1.59.0
	 */
	public static function get_ssp_failed_destinations() {
		return array_values(array_filter(
			self::get_ssp_destination_results(),
			static function ( $result ) {
				return empty( $result['ok'] );
			}
		));
	}

	/**
	 * Get or create the SSP session ID for the current visitor.
	 *
	 * Uses the WooCommerce session to persist a UUID across page loads.
	 * The session ID is used for Tier 2 cookie verification.
	 *
	 * @return string UUID session ID or empty string if no WC session.
	 * @since 1.57.0
	 */
	public static function get_ssp_session_id() {

		if ( ! function_exists( 'WC' ) || ! WC()->session ) {
			return '';
		}

		$session_id = WC()->session->get( 'pmw_ssp_session_id' );

		if ( empty( $session_id ) ) {
			$session_id = wp_generate_uuid4();
			WC()->session->set( 'pmw_ssp_session_id', $session_id );
		}

		return $session_id;
	}

	/**
	 * Get additional SSP domain configurations from filter.
	 *
	 * Allows a single WordPress instance serving multiple domains to connect
	 * each domain to its own SSP proxy. The primary domain uses the standard
	 * SSP options; additional domains are registered via this filter.
	 *
	 * Each entry must contain:
	 * - 'sync_token'     (string) The domain sync token from the SSP portal.
	 * - 'proxy_hostname' (string) The SSP proxy hostname (e.g. 'ssp.otherdomain.com').
	 * - 'shop_origin'    (string) The full origin URL (e.g. 'https://otherdomain.com').
	 *
	 * @return array[] Validated array of additional domain configs.
	 * @since 1.57.1
	 */
	public static function get_ssp_additional_domains() {

		/**
		 * Filter to register additional SSP domains for multi-domain WordPress installs.
		 *
		 * @param array[] $domains Array of domain config arrays.
		 * @since 1.58.5
		 */
		$domains = apply_filters( 'pmw_ssp_additional_domains', [] );

		if ( empty( $domains ) || ! is_array( $domains ) ) {
			return [];
		}

		// Validate each entry
		$validated = [];
		foreach ( $domains as $domain ) {
			if (
				! empty( $domain['sync_token'] )
				&& ! empty( $domain['proxy_hostname'] )
				&& ! empty( $domain['shop_origin'] )
			) {
				$validated[] = $domain;
			}
		}

		return $validated;
	}

	/**
	 * Find an additional SSP domain config matching the current request host.
	 *
	 * @return array|null The matching domain config, or null if no match.
	 * @since 1.57.1
	 */
	public static function get_matching_ssp_additional_domain() {

		$additional_domains = self::get_ssp_additional_domains();

		if ( empty( $additional_domains ) ) {
			return null;
		}

		$current_host = isset( $_SERVER['HTTP_HOST'] ) ? sanitize_text_field( wp_unslash( $_SERVER['HTTP_HOST'] ) ) : '';

		foreach ( $additional_domains as $domain ) {
			$origin_host = wp_parse_url( $domain['shop_origin'], PHP_URL_HOST );
			if ( $origin_host && strcasecmp( $current_host, $origin_host ) === 0 ) {
				return $domain;
			}
		}

		return null;
	}

	/**
	 * Get the stored verification key for an additional SSP domain.
	 *
	 * @param string $proxy_hostname The proxy hostname to look up.
	 *
	 * @return string The domain token, or empty string.
	 * @since 1.57.1
	 */
	public static function get_ssp_additional_domain_domain_token( $proxy_hostname ) {
		$options = self::get_options();
		$keys    = isset( $options['ssp']['additional_domain_keys'][ $proxy_hostname ] ) ? $options['ssp']['additional_domain_keys'][ $proxy_hostname ] : [];
		return isset( $keys['domain_token'] ) ? $keys['domain_token'] : '';
	}

	/**
	 * Get the SSP additional domain verification key.
	 *
	 * @deprecated Use get_ssp_additional_domain_domain_token() instead.
	 */
	public static function get_ssp_additional_domain_verification_key( $proxy_hostname ) {
		return self::get_ssp_additional_domain_domain_token( $proxy_hostname );
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
		 * @since 1.58.5
		 */
		return apply_filters('pmw_backup_retention_settings', $default_settings);
	}
}
