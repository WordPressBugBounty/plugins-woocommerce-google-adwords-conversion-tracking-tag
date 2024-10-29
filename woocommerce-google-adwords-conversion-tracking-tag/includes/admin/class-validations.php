<?php

namespace SweetCode\Pixel_Manager\Admin;

use SweetCode\Pixel_Manager\Helpers;
use SweetCode\Pixel_Manager\Logger;
use SweetCode\Pixel_Manager\Options;

defined('ABSPATH') || exit; // Exit if accessed directly

class Validations {

	public static function validate_imported_options( $options ) {

		$options_to_check = [
			'google'     => [
				'ads'          => [
					'conversion_id'    => '',
					'conversion_label' => '',
				],
				'analytics'    => [
					'ga4'              => [
						'measurement_id' => '',
					],
					'link_attribution' => false,
				],
				'optimize'     => [
					'container_id' => '',
				],
				'consent_mode' => [
					'active'  => false,
					'regions' => [],
				],
				'user_id'      => false,
			],
			'facebook'   => [
				'pixel_id' => '',
			],
			'shop'       => [
				'order_total_logic' => 0,
			],
			'general'    => [
				'variations_output' => true,
			],
			'db_version' => PMW_DB_VERSION,
		];

		return self::do_all_keys_exist_recursive($options_to_check, $options);
	}

	private static function do_all_keys_exist_recursive( $partial_array, $full_array ) {

		foreach ($partial_array as $key => $value) {
			if (!array_key_exists($key, $full_array)) {
				error_log('key not found: ' . $key);
				return false;
			}
			if (is_array($value)) {
				if (!self::do_all_keys_exist_recursive($value, $full_array[$key])) {
					return false;
				}
			}
		}

		return true;
	}

	// validate the options
	public static function options_validate( $input ) {

		$input = Helpers::generic_sanitization($input);

//		// validate Adroll advertiser ID
		if (isset($input['pixels']['adroll']['advertiser_id'])) {

			// Trim space, newlines and quotes
			$input['pixels']['adroll']['advertiser_id'] = Helpers::trim_string($input['pixels']['adroll']['advertiser_id']);

			if (!self::is_adroll_advertiser_id($input['pixels']['adroll']['advertiser_id'])) {
				$input['pixels']['adroll']['advertiser_id']
					= Options::get_adroll_advertiser_id()
					? Options::get_adroll_advertiser_id()
					: '';
				add_settings_error('wgact_plugin_options', 'invalid-adroll-advertiser-id', esc_html__('You have entered an invalid Adroll advertiser ID.', 'woocommerce-google-adwords-conversion-tracking-tag'));
			}
		}

//		// validate Adroll pixel ID
		if (isset($input['pixels']['adroll']['pixel_id'])) {

			// Trim space, newlines and quotes
			$input['pixels']['adroll']['pixel_id'] = Helpers::trim_string($input['pixels']['adroll']['pixel_id']);

			if (!self::is_adroll_pixel_id($input['pixels']['adroll']['pixel_id'])) {
				$input['pixels']['adroll']['pixel_id']
					= Options::get_adroll_pixel_id()
					? Options::get_adroll_pixel_id()
					: '';
				add_settings_error('wgact_plugin_options', 'invalid-adroll-pixel-id', esc_html__('You have entered an invalid Adroll pixel ID.', 'woocommerce-google-adwords-conversion-tracking-tag'));
			}
		}

		// validate Google Analytics 4 measurement ID
		if (isset($input['google']['analytics']['ga4']['measurement_id'])) {

			// Trim space, newlines and quotes
			$input['google']['analytics']['ga4']['measurement_id'] = Helpers::trim_string($input['google']['analytics']['ga4']['measurement_id']);

			if (!self::is_google_analytics_4_measurement_id($input['google']['analytics']['ga4']['measurement_id'])) {
				$input['google']['analytics']['ga4']['measurement_id']
					= Options::get_ga4_measurement_id()
					? Options::get_ga4_measurement_id()
					: '';
				add_settings_error('wgact_plugin_options', 'invalid-google-analytics-4-measurement-id', esc_html__('You have entered an invalid Google Analytics 4 measurement ID.', 'woocommerce-google-adwords-conversion-tracking-tag'));
			}
		}

		// validate Google Analytics 4 API key
		if (isset($input['google']['analytics']['ga4']['api_secret'])) {

			// Trim space, newlines and quotes
			$input['google']['analytics']['ga4']['api_secret'] = Helpers::trim_string($input['google']['analytics']['ga4']['api_secret']);

			if (!self::is_google_analytics_4_api_secret($input['google']['analytics']['ga4']['api_secret'])) {
				$input['google']['analytics']['ga4']['api_secret']
					= Options::get_ga4_mp_api_secret()
					? Options::get_ga4_mp_api_secret()
					: '';
				add_settings_error('wgact_plugin_options', 'invalid-google-analytics-4-measurement-id', esc_html__('You have entered an invalid Google Analytics 4 API key.', 'woocommerce-google-adwords-conversion-tracking-tag'));
			}
		}

		// Validate the Google Analytics 4 property ID
		if (isset($input['google']['analytics']['ga4']['data_api']['property_id'])) {

			// Trim space, newlines and quotes
			$input['google']['analytics']['ga4']['data_api']['property_id'] = Helpers::trim_string($input['google']['analytics']['ga4']['data_api']['property_id']);

			if (!self::is_google_analytics_4_property_id($input['google']['analytics']['ga4']['data_api']['property_id'])) {
				$input['google']['analytics']['ga4']['data_api']['property_id']
					= Options::get_ga4_data_api_property_id()
					? Options::get_ga4_data_api_property_id()
					: '';
				add_settings_error(
					'wgact_plugin_options',
					'invalid-google-analytics-4-property-id',
					esc_html__('You have entered an invalid GA4 property ID.', 'woocommerce-google-adwords-conversion-tracking-tag')
				);
			}
		}

		// validate ['google]['ads']['conversion_id']
		if (isset($input['google']['ads']['conversion_id'])) {

			// Trim space, newlines and quotes
			$input['google']['ads']['conversion_id'] = Helpers::trim_string($input['google']['ads']['conversion_id']);

			// Remove "AW-" prefix
			$input['google']['ads']['conversion_id'] = preg_replace('/^AW-/', '', $input['google']['ads']['conversion_id']);

			// If there is a slash, remove it and everything after it
			$input['google']['ads']['conversion_id'] = preg_replace('/\/.*$/', '', $input['google']['ads']['conversion_id']);

			if (!self::is_gads_conversion_id($input['google']['ads']['conversion_id'])) {
				$input['google']['ads']['conversion_id']
					= Options::get_google_ads_conversion_id()
					? Options::get_google_ads_conversion_id()
					: '';
				add_settings_error('wgact_plugin_options', 'invalid-conversion-id', esc_html__('You have entered an invalid conversion ID. It only contains 8 to 10 digits.', 'woocommerce-google-adwords-conversion-tracking-tag'));
			}
		}

		// validate ['google]['ads']['conversion_label']
		if (isset($input['google']['ads']['conversion_label'])) {

			// Trim space, newlines and quotes
			$input['google']['ads']['conversion_label'] = Helpers::trim_string($input['google']['ads']['conversion_label']);

			// If there is a slash, remove it and everything before it
			$input['google']['ads']['conversion_label'] = preg_replace('/^.*\//', '', $input['google']['ads']['conversion_label']);

			if (!self::is_gads_conversion_label($input['google']['ads']['conversion_label'])) {
				$input['google']['ads']['conversion_label']
					= Options::get_google_ads_conversion_label()
					? Options::get_google_ads_conversion_label()
					: '';
				add_settings_error('wgact_plugin_options', 'invalid-conversion-label', esc_html__('You have entered an invalid Google Ads conversion label.', 'woocommerce-google-adwords-conversion-tracking-tag'));
			}
		}

		// validate ['google]['ads']['phone_conversion_label']
		if (isset($input['google']['ads']['phone_conversion_label'])) {

			// Trim space, newlines and quotes
			$input['google']['ads']['phone_conversion_label'] = Helpers::trim_string($input['google']['ads']['phone_conversion_label']);

			if (!self::is_gads_conversion_label($input['google']['ads']['phone_conversion_label'])) {
				$input['google']['ads']['phone_conversion_label']
					= Options::get_google_ads_phone_conversion_label()
					? Options::get_google_ads_phone_conversion_label()
					: '';
				add_settings_error('wgact_plugin_options', 'invalid-conversion-label', esc_html__('You have entered an invalid Google Ads conversion label.', 'woocommerce-google-adwords-conversion-tracking-tag'));
			}
		}

		// validate ['google]['ads']['aw_merchant_id']
		if (isset($input['google']['ads']['aw_merchant_id'])) {

			// Trim space, newlines and quotes
			$input['google']['ads']['aw_merchant_id'] = Helpers::trim_string($input['google']['ads']['aw_merchant_id']);

			if (!self::is_gads_aw_merchant_id($input['google']['ads']['aw_merchant_id'])) {
				$input['google']['ads']['aw_merchant_id']
					= Options::get_google_ads_merchant_id()
					? Options::get_google_ads_merchant_id()
					: '';
				add_settings_error('wgact_plugin_options', 'invalid-aw-merchant-id', esc_html__('You have entered an invalid merchant ID. It only contains 6 to 12 digits.', 'woocommerce-google-adwords-conversion-tracking-tag'));
			}
		}

		// validate ['facebook']['pixel_id']
		if (isset($input['facebook']['pixel_id'])) {

			// Trim space, newlines and quotes
			$input['facebook']['pixel_id'] = Helpers::trim_string($input['facebook']['pixel_id']);

			if (!self::is_facebook_pixel_id($input['facebook']['pixel_id'])) {
				$input['facebook']['pixel_id']
					= Options::get_facebook_pixel_id()
					? Options::get_facebook_pixel_id()
					: '';
				add_settings_error('wgact_plugin_options', 'invalid-facebook-pixel-id', esc_html__('You have entered an invalid Meta (Facebook) pixel ID. It only contains 12 to 22 digits.', 'woocommerce-google-adwords-conversion-tracking-tag'));
			}
		}

		// validate ['facebook']['capi']['token']
		if (isset($input['facebook']['capi']['token'])) {

			// Trim space, newlines and quotes
			$input['facebook']['capi']['token'] = Helpers::trim_string($input['facebook']['capi']['token']);

			if (!self::is_facebook_capi_token($input['facebook']['capi']['token'])) {
				$input['facebook']['capi']['token']
					= Options::get_facebook_capi_token()
					? Options::get_facebook_capi_token()
					: '';
				add_settings_error('wgact_plugin_options', 'invalid-facebook-pixel-id', esc_html__('You have entered an invalid Meta (Facebook) CAPI token.', 'woocommerce-google-adwords-conversion-tracking-tag'));
			}
		}

		// validate ['facebook']['capi']['test_event_code']
		if (isset($input['facebook']['capi']['test_event_code'])) {

			// Trim space, newlines and quotes
			$input['facebook']['capi']['test_event_code'] = Helpers::trim_string($input['facebook']['capi']['test_event_code']);

			if (!self::is_facebook_capi_test_event_code($input['facebook']['capi']['test_event_code'])) {
				$input['facebook']['capi']['test_event_code']
					= Options::get_facebook_capi_test_event_code()
					? Options::get_facebook_capi_test_event_code()
					: '';
				add_settings_error('wgact_plugin_options', 'invalid-facebook-capi-test-event-code', esc_html__('You have entered an invalid Meta (Facebook) CAPI test_event_code.', 'woocommerce-google-adwords-conversion-tracking-tag'));
			}
		}

		// validate ['facebook']['domain_verification_id']
		if (isset($input['facebook']['domain_verification_id'])) {

			// Trim space, newlines and quotes
			$input['facebook']['domain_verification_id'] = Helpers::trim_string($input['facebook']['domain_verification_id']);

			// The input might look like this <meta name= "facebook-domain-verification" content="uk6zwiftxsaywayn14x04ouhz4fhd" / >
			// or it might look like this uk6zwiftxsaywayn14x04ouhz4fhd
			// or like this  content="uk6zwiftxsaywayn14x04ouhz4fhd"
			// We need to extract the content value
			$input['facebook']['domain_verification_id'] = preg_replace('/^.*content\s*=\s*["\']?([^"\']+?)["\']?\s*\/?\s*>?$/', '$1', $input['facebook']['domain_verification_id']);

			if (!self::is_facebook_domain_verification_id($input['facebook']['domain_verification_id'])) {
				$input['facebook']['domain_verification_id']
					= Options::get_facebook_domain_verification_id()
					? Options::get_facebook_domain_verification_id()
					: '';
				add_settings_error('wgact_plugin_options', 'invalid-meta-domain-verification-id', esc_html__('You have entered an invalid Meta (Facebook) domain verification ID.', 'woocommerce-google-adwords-conversion-tracking-tag'));
			}
		}


		// validate Bing Ads UET tag ID
		if (isset($input['bing']['uet_tag_id'])) {

			// Trim space, newlines and quotes
			$input['bing']['uet_tag_id'] = Helpers::trim_string($input['bing']['uet_tag_id']);

			if (!self::is_bing_uet_tag_id($input['bing']['uet_tag_id'])) {
				$input['bing']['uet_tag_id']
					= Options::get_bing_uet_tag_id()
					? Options::get_bing_uet_tag_id()
					: '';
				add_settings_error('wgact_plugin_options', 'invalid-bing-ads-uet-tag-id', esc_html__('You have entered an invalid Bing Ads UET tag ID. It only contains 7 to 9 digits.', 'woocommerce-google-adwords-conversion-tracking-tag'));
			}
		}

		// validate LinkedIn partner ID
		if (isset($input['pixels']['linkedin']['partner_id'])) {

			// Trim space, newlines and quotes
			$input['pixels']['linkedin']['partner_id'] = Helpers::trim_string($input['pixels']['linkedin']['partner_id']);

			if (!self::is_linkedin_partner_id($input['pixels']['linkedin']['partner_id'])) {
				$input['pixels']['linkedin']['partner_id']
					= Options::get_linkedin_partner_id()
					? Options::get_linkedin_partner_id()
					: '';
				add_settings_error('wgact_plugin_options', 'invalid-linkedin-partner-id', esc_html__('You have entered an invalid LinkedIn partner ID.', 'woocommerce-google-adwords-conversion-tracking-tag'));
			}
		}

		// Validate LinkedIn conversion IDs add_to_cart
		$input = self::validate_linkedin_conversion_id($input, 'add_to_cart');
		$input = self::validate_linkedin_conversion_id($input, 'start_checkout');
		$input = self::validate_linkedin_conversion_id($input, 'purchase');

		// validate Outbrain advertiser ID
		if (isset($input['pixels']['outbrain']['advertiser_id'])) {

			// Trim space, newlines and quotes
			$input['pixels']['outbrain']['advertiser_id'] = Helpers::trim_string($input['pixels']['outbrain']['advertiser_id']);

			if (!self::is_outbrain_account_id($input['pixels']['outbrain']['advertiser_id'])) {
				$input['pixels']['outbrain']['advertiser_id']
					= Options::get_outbrain_advertiser_id()
					? Options::get_outbrain_advertiser_id()
					: '';
				add_settings_error('wgact_plugin_options', 'invalid-outbrain-advertiser-id', esc_html__('You have entered an invalid Outbrain advertiser ID.', 'woocommerce-google-adwords-conversion-tracking-tag'));
			}
		}

		// validate ['pinterest']['ad_account_id']
		if (isset($input['pinterest']['ad_account_id'])) {

			// Trim space, newlines and quotes
			$input['pinterest']['ad_account_id'] = Helpers::trim_string($input['pinterest']['ad_account_id']);

			if (!self::is_pinterest_ad_account_id($input['pinterest']['ad_account_id'])) {
				$input['pinterest']['ad_account_id']
					= Options::get_pinterest_ad_account_id()
					? Options::get_pinterest_ad_account_id()
					: '';
				add_settings_error('wgact_plugin_options', 'invalid-pinterest-ad-account-id', esc_html__('You have entered an invalid Pinterest ad account ID.', 'woocommerce-google-adwords-conversion-tracking-tag'));
			}
		}

		// validate ['pinterest']['apic']['token']
		if (isset($input['pinterest']['apic']['token'])) {

			// Trim space, newlines and quotes
			$input['pinterest']['apic']['token'] = Helpers::trim_string($input['pinterest']['apic']['token']);

			if (!self::is_pinterest_apic_token($input['pinterest']['apic']['token'])) {
				$input['pinterest']['apic']['token']
					= Options::get_pinterest_apic_token()
					? Options::get_pinterest_apic_token()
					: '';
				add_settings_error('wgact_plugin_options', 'invalid-pinterest-apic-token', esc_html__('You have entered an invalid Pinterest API token.', 'woocommerce-google-adwords-conversion-tracking-tag'));
			}
		}

		// validate Twitter pixel ID
		if (isset($input['twitter']['pixel_id'])) {

			// Trim space, newlines and quotes
			$input['twitter']['pixel_id'] = Helpers::trim_string($input['twitter']['pixel_id']);

			if (!self::is_twitter_pixel_id($input['twitter']['pixel_id'])) {
				$input['twitter']['pixel_id']
					= Options::get_twitter_pixel_id()
					? Options::get_twitter_pixel_id()
					: '';
				add_settings_error('wgact_plugin_options', 'invalid-twitter-pixel-id', esc_html__('You have entered an invalid Twitter pixel ID. It only contains 5 to 7 lowercase letters and numbers.', 'woocommerce-google-adwords-conversion-tracking-tag'));
			}
		}

		// Validate Twitter event add_to_cart
		$input = self::validate_twitter_event($input, 'add_to_cart');
		$input = self::validate_twitter_event($input, 'add_to_wishlist');
		$input = self::validate_twitter_event($input, 'view_content');
		$input = self::validate_twitter_event($input, 'search');
		$input = self::validate_twitter_event($input, 'initiate_checkout');
//		$input = self::validate_twitter_event($input, 'add_payment_info);
		$input = self::validate_twitter_event($input, 'purchase');

		// validate Pinterest pixel ID
		if (isset($input['pinterest']['pixel_id'])) {

			// Trim space, newlines and quotes
			$input['pinterest']['pixel_id'] = Helpers::trim_string($input['pinterest']['pixel_id']);

			if (!self::is_pinterest_pixel_id($input['pinterest']['pixel_id'])) {
				$input['pinterest']['pixel_id']
					= Options::get_pinterest_pixel_id()
					? Options::get_pinterest_pixel_id()
					: '';
				add_settings_error('wgact_plugin_options', 'invalid-pinterest-pixel-id', esc_html__('You have entered an invalid Pinterest pixel ID. It only contains 13 digits.', 'woocommerce-google-adwords-conversion-tracking-tag'));
			}
		}

		// validate Snapchat pixel ID
		if (isset($input['snapchat']['pixel_id'])) {

			// Trim space, newlines and quotes
			$input['snapchat']['pixel_id'] = Helpers::trim_string($input['snapchat']['pixel_id']);

			if (!self::is_snapchat_pixel_id($input['snapchat']['pixel_id'])) {
				$input['snapchat']['pixel_id']
					= Options::get_snapchat_pixel_id()
					? Options::get_snapchat_pixel_id()
					: '';
				add_settings_error('wgact_plugin_options', 'invalid-snapchat-pixel-id', esc_html__('You have entered an invalid Snapchat pixel ID.', 'woocommerce-google-adwords-conversion-tracking-tag'));
			}
		}

		// validate Snapchat CAPI token
		if (isset($input['snapchat']['capi']['token'])) {

			// Trim space, newlines and quotes
			$input['snapchat']['capi']['token'] = Helpers::trim_string($input['snapchat']['capi']['token']);

			if (!self::is_snapchat_capi_token($input['snapchat']['capi']['token'])) {
				$input['snapchat']['capi']['token']
					= Options::get_snapchat_capi_token()
					? Options::get_snapchat_capi_token()
					: '';
				add_settings_error('wgact_plugin_options', 'invalid-snapchat-capi-token', esc_html__('You have entered an invalid Snapchat CAPI token.', 'woocommerce-google-adwords-conversion-tracking-tag'));
			}
		}

		// validate Taboola account ID
		if (isset($input['pixels']['taboola']['account_id'])) {

			// Trim space, newlines and quotes
			$input['pixels']['taboola']['account_id'] = Helpers::trim_string($input['pixels']['taboola']['account_id']);

			if (!self::is_taboola_account_id($input['pixels']['taboola']['account_id'])) {
				$input['pixels']['taboola']['account_id']
					= Options::get_taboola_account_id()
					? Options::get_taboola_account_id()
					: '';
				add_settings_error('wgact_plugin_options', 'invalid-taboola-account-id', esc_html__('You have entered an invalid Taboola account ID.', 'woocommerce-google-adwords-conversion-tracking-tag'));
			}
		}

		// validate TikTok pixel ID
		if (isset($input['tiktok']['pixel_id'])) {

			// Trim space, newlines and quotes
			$input['tiktok']['pixel_id'] = Helpers::trim_string($input['tiktok']['pixel_id']);

			if (!self::is_tiktok_pixel_id($input['tiktok']['pixel_id'])) {
				$input['tiktok']['pixel_id']
					= Options::get_tiktok_pixel_id()
					? Options::get_tiktok_pixel_id()
					: '';
				add_settings_error('wgact_plugin_options', 'invalid-tiktok-pixel-id', esc_html__('You have entered an invalid TikTok pixel ID.', 'woocommerce-google-adwords-conversion-tracking-tag'));
			}
		}

		// Validate TikTok Events API access token
		if (isset($input['tiktok']['eapi']['token'])) {

			// Trim space, newlines and quotes
			$input['tiktok']['eapi']['token'] = Helpers::trim_string($input['tiktok']['eapi']['token']);

			if (!self::is_tiktok_eapi_access_token($input['tiktok']['eapi']['token'])) {
				$input['tiktok']['eapi']['token']
					= Options::get_tiktok_eapi_token()
					? Options::get_tiktok_eapi_token()
					: '';
				add_settings_error('wgact_plugin_options', 'invalid-tiktok-eapi-access-token', esc_html__('You have entered an invalid TikTok Events API access token.', 'woocommerce-google-adwords-conversion-tracking-tag'));
			}
		}

		// Validate ['tiktok']['eapi']['test_event_code']
		if (isset($input['tiktok']['eapi']['test_event_code'])) {

			// Trim space, newlines and quotes
			$input['tiktok']['eapi']['test_event_code'] = Helpers::trim_string($input['tiktok']['eapi']['test_event_code']);

			if (!self::is_tiktok_eapi_test_event_code($input['tiktok']['eapi']['test_event_code'])) {
				$input['tiktok']['eapi']['test_event_code']
					= Options::get_tiktok_eapi_test_event_code()
					? Options::get_tiktok_eapi_test_event_code()
					: '';
				add_settings_error('wgact_plugin_options', 'invalid-tiktok-eapi-test-event-code', esc_html__('You have entered an invalid TikTok EAPI test_event_code.', 'woocommerce-google-adwords-conversion-tracking-tag'));
			}
		}

		// validate Hotjar site ID
		if (isset($input['hotjar']['site_id'])) {

			// Trim space, newlines and quotes
			$input['hotjar']['site_id'] = Helpers::trim_string($input['hotjar']['site_id']);

			if (!self::is_hotjar_site_id($input['hotjar']['site_id'])) {
				$input['hotjar']['site_id']
					= Options::get_hotjar_site_id()
					? Options::get_hotjar_site_id()
					: '';
				add_settings_error('wgact_plugin_options', 'invalid-hotjar-site-id', esc_html__('You have entered an invalid Hotjar site ID. It only contains 6 to 9 digits.', 'woocommerce-google-adwords-conversion-tracking-tag'));
			}
		}

		// Validate Reddit advertiser ID
		if (isset($input['pixels']['reddit']['advertiser_id'])) {

			// Trim space, newlines and quotes
			$input['pixels']['reddit']['advertiser_id'] = Helpers::trim_string($input['pixels']['reddit']['advertiser_id']);

			if (!self::is_reddit_advertiser_id($input['pixels']['reddit']['advertiser_id'])) {
				$input['pixels']['reddit']['advertiser_id']
					= Options::get_reddit_advertiser_id()
					? Options::get_reddit_advertiser_id()
					: '';
				add_settings_error('wgact_plugin_options', 'invalid-reddit-advertiser-id', esc_html__('You have entered an invalid Reddit pixel ID.', 'woocommerce-google-adwords-conversion-tracking-tag'));
			}
		}

		// Validate the VWO account ID
		if (isset($input['pixels']['vwo']['account_id'])) {

			// Trim space, newlines and quotes
			$input['pixels']['vwo']['account_id'] = Helpers::trim_string($input['pixels']['vwo']['account_id']);

			if (!self::is_vwo_account_id($input['pixels']['vwo']['account_id'])) {
				$input['pixels']['vwo']['account_id']
					= Options::get_vwo_account_id()
					? Options::get_vwo_account_id()
					: '';
				add_settings_error('wgact_plugin_options', 'invalid-vwo-account-id', esc_html__('You have entered an invalid VWO account ID.', 'woocommerce-google-adwords-conversion-tracking-tag'));
			}
		}

		// Validate the Optimizely project ID
		if (isset($input['pixels']['optimizely']['project_id'])) {

			// Trim space, newlines and quotes
			$input['pixels']['optimizely']['project_id'] = Helpers::trim_string($input['pixels']['optimizely']['project_id']);

			if (!self::is_optimizely_project_id($input['pixels']['optimizely']['project_id'])) {
				$input['pixels']['optimizely']['project_id']
					= Options::get_optimizely_project_id()
					? Options::get_optimizely_project_id()
					: '';
				add_settings_error('wgact_plugin_options', 'invalid-vwo-account-id', esc_html__('You have entered an invalid Optimizely project ID.', 'woocommerce-google-adwords-conversion-tracking-tag'));
			}
		}

		// Validate the AB Tasty account ID
		if (isset($input['pixels']['ab_tasty']['account_id'])) {

			// Trim space, newlines and quotes
			$input['pixels']['ab_tasty']['account_id'] = Helpers::trim_string($input['pixels']['ab_tasty']['account_id']);

			if (!self::is_ab_tasty_account_id($input['pixels']['ab_tasty']['account_id'])) {
				$input['pixels']['ab_tasty']['account_id']
					= Options::get_ab_tasty_account_id()
					? Options::get_ab_tasty_account_id()
					: '';
				add_settings_error('wgact_plugin_options', 'invalid-vwo-account-id', esc_html__('You have entered an invalid AB Tasty account ID.', 'woocommerce-google-adwords-conversion-tracking-tag'));
			}
		}

		// Sanitize and validate scroll tracker thresholds
		if (isset($input['general']['scroll_tracker_thresholds'])) {

			if (is_string($input['general']['scroll_tracker_thresholds'])) {

				$scroll_tracker_thresholds = $input['general']['scroll_tracker_thresholds'];

				// remove all spaces
				$scroll_tracker_thresholds = str_replace(' ', '', $scroll_tracker_thresholds);

				// remove leading and trailing commas
				$scroll_tracker_thresholds = trim($scroll_tracker_thresholds, ',');

				// remove duplicate commas and replace with single comma
				$scroll_tracker_thresholds = preg_replace('/,+/', ',', $scroll_tracker_thresholds);

				// remove quotes
				$scroll_tracker_thresholds = str_replace('"', '', $scroll_tracker_thresholds);

				// remove single quotes
				$scroll_tracker_thresholds = str_replace("'", '', $scroll_tracker_thresholds);

				if (!self::is_scroll_tracker_thresholds($scroll_tracker_thresholds)) {
					$input['general']['scroll_tracker_thresholds']
						= Options::get_scroll_tracking_thresholds()
						? Options::get_scroll_tracking_thresholds()
						: '';
					add_settings_error('wgact_plugin_options', 'invalid-scroll-tracker-thresholds', esc_html__('You have entered the Scroll Tracker thresholds in the wrong format. It must be a list of comma separated percentages, like this "25,50,75,100"', 'woocommerce-google-adwords-conversion-tracking-tag'));
				} elseif ('' !== $scroll_tracker_thresholds) { // If $scroll_tracker_thresholds not empty string
					$input['general']['scroll_tracker_thresholds'] = explode(',', $scroll_tracker_thresholds);
				} else {
					$input['general']['scroll_tracker_thresholds'] = [];
				}
			} else {
				Logger::debug('Scroll Tracker Thresholds is not a string: ' . print_r($input['general']['scroll_tracker_thresholds'], true));
			}
		}

		// Validate the subscription value multiplier
		if (isset($input['shop']['subscription_value_multiplier'])) {

			// Trim space, newlines and quotes
			$input['shop']['subscription_value_multiplier'] = Helpers::trim_string($input['shop']['subscription_value_multiplier']);

			if (!self::is_subscription_value_multiplier($input['shop']['subscription_value_multiplier'])) {

				$input['shop']['subscription_value_multiplier']
					= Options::get_subscription_multiplier()
					? Options::get_subscription_multiplier()
					: 1;

				add_settings_error('wgact_plugin_options', 'invalid-subscription-value-multiplier', esc_html__('You have entered an invalid subscription value multiplier. It must be a number and at least 1.00', 'woocommerce-google-adwords-conversion-tracking-tag'));
			}

			// Count decimal places
			$decimal_places = strlen(substr(strrchr($input['shop']['subscription_value_multiplier'], '.'), 1));

			$input['shop']['subscription_value_multiplier'] = Helpers::format_decimal($input['shop']['subscription_value_multiplier'], max($decimal_places, 2));
		}

		// Validate the Google Ads Conversion Adjustments Conversion Name
		if (isset($input['google']['ads']['conversion_adjustments']['conversion_name'])) {

			// Trim space, newlines and quotes
			$input['google']['ads']['conversion_adjustments']['conversion_name'] = Helpers::trim_string($input['google']['ads']['conversion_adjustments']['conversion_name']);

			if (!self::is_valid_conversion_adjustments_conversion_name($input['google']['ads']['conversion_adjustments']['conversion_name'])) {

				$input['google']['ads']['conversion_adjustments']['conversion_name']
					= Options::get_google_ads_conversion_adjustments_conversion_name()
					? Options::get_google_ads_conversion_adjustments_conversion_name()
					: '';

				add_settings_error('wgact_plugin_options', 'invalid-conversion-adjustments-conversion-name', esc_html__('You have entered an invalid conversion adjustments conversion name. Special characters, quotes and single quotes are not allowed due to security reasons.', 'woocommerce-google-adwords-conversion-tracking-tag'));
			}
		}

		self::schedule_duplication_prevention_activation($input);
		self::schedule_http_request_logging_deactivation($input);

		/**
		 * Merging with the existing options and overwriting old values
		 * since disabling a checkbox doesn't send a value,
		 * we need to set one to overwrite the old value
		 */

		return array_replace_recursive(self::non_form_keys($input), $input);
	}

	private static function validate_twitter_event( $input, $event ) {

		if (isset($input['twitter']['event_ids'][$event])) {

			// Trim space, newlines and quotes
			$input['twitter']['event_ids'][$event] = Helpers::trim_string($input['twitter']['event_ids'][$event]);

			if (!self::is_twitter_event_id($input['twitter']['event_ids'][$event])) {
				$input['twitter']['event_ids'][$event]
					= Options::get_twitter_event_id($event)
					? Options::get_twitter_event_id($event)
					: '';
				add_settings_error(
					'wgact_plugin_options',
					'invalid-twitter-event-id',
					esc_html__('You have entered an invalid Twitter event ID.', 'woocommerce-google-adwords-conversion-tracking-tag')
				);
				return $input;
			}

			return $input;
		}

		return $input;
	}

	private static function validate_linkedin_conversion_id( $input, $event ) {

		if (isset($input['pixels']['linkedin']['conversion_ids'][$event])) {

			// Trim space, newlines and quotes
			$input['pixels']['linkedin']['conversion_ids'][$event] = Helpers::trim_string($input['pixels']['linkedin']['conversion_ids'][$event]);

			if (!self::is_linkedin_conversion_id($input['pixels']['linkedin']['conversion_ids'][$event])) {
				$input['pixels']['linkedin']['conversion_ids'][$event]
					= Options::get_linkedin_conversion_id($event)
					? Options::get_linkedin_conversion_id($event)
					: '';
				add_settings_error(
					'wgact_plugin_options',
					'invalid-linkedin-conversion-id',
					esc_html__('You have entered an invalid LinkedIn conversion ID.', 'woocommerce-google-adwords-conversion-tracking-tag')
				);
				return $input;
			}

			return $input;
		}

		return $input;
	}

	private static function schedule_duplication_prevention_activation( $input ) {

		// If action scheduler is not active, return
		if (!Environment::is_action_scheduler_active()) {
			return;
		}

		// If $input['shop']['order_deduplication'] is not set, return
		if (!isset($input['shop']['order_deduplication'])) {
			return;
		}

		// If pmw_reactivate_duplication_prevention action is already scheduled, unschedule it.
		// If the order duplication has been reactivated manually, we don't need to schedule the reactivation.
		// If the order duplication has been deactivated manually, and there is already a scheduled reactivation, we need to reset the time delay.
		as_unschedule_all_actions('pmw_reactivate_duplication_prevention');

		// If the order duplication is active, we don't need to do anything
		if ($input['shop']['order_deduplication']) {
			return;
		}

		// Schedule pmw_reactivate_duplication_prevention action
		as_schedule_single_action(time() + 6 * HOUR_IN_SECONDS, 'pmw_reactivate_duplication_prevention');
	}

	private static function schedule_http_request_logging_deactivation( $input ) {

		// If action scheduler is not active, return
		if (!Environment::is_action_scheduler_active()) {
			return;
		}

		// If $input['general']['logger']['log_http_requests'] is not set, return
		if (!isset($input['general']['logger']['log_http_requests'])) {
			return;
		}

		// If pmw_deactivate_log_http_requests action is already scheduled, unschedule it.
		// If http request logging has been deactivated manually, we don't need to schedule the deactivation.
		// If http request logging has been activated manually, and there is already a scheduled deactivation, we need to reset the time delay.
		as_unschedule_all_actions('pmw_deactivate_log_http_requests');

		// If $input['general']['logger']['log_http_requests'] is false, return
		// We only want to schedule the deactivation of http request logging if it is active
		if (!$input['general']['logger']['log_http_requests']) {
			return;
		}

		// set delay to 3 hours
		$delay = apply_filters('pmw_http_request_log_auto_off_delay', 12 * HOUR_IN_SECONDS);

		// schedule pmw_deactivate_log_http_requests action
		as_schedule_single_action(time() + $delay, 'pmw_deactivate_log_http_requests');
	}

	/**
	 * Place here what could be overwritten when a form field is missing
	 * and what should not be re-set to the default value
	 * but should be preserved
	 */
	private static function non_form_keys( $input ) {

		$non_form_keys = [
			'db_version' => Options::get_options()['db_version'],
			'shop'       => [
				'disable_tracking_for' => [],
			],
			'google'     => [
				'analytics' => [
					'ga4' => [
						'data_api' => [
							'credentials' => Options::get_ga4_data_api_credentials(),
						],
					],
				],
			],
		];

		// in case the form field input is missing
//        if (!array_key_exists('google_business_vertical', $input['google']['ads'])) {
//            $non_form_keys['google']['ads']['google_business_vertical'] = Options::get_google_ads_business_vertical_id();
//        }

		return $non_form_keys;
	}

	public static function validate_ga4_data_api_credentials( $credentials ) {

		// If $credentials is an empty array (thus the default empty value), return true
		if (empty($credentials)) {
			return true;
		}

		if (isset($credentials['type']) && 'service_account' !== $credentials['type']) {
			wp_send_json_error([ 'message' => 'type is not service_account' ]);
		}

		// Abort if $credentials['project_id'] is not regular string
		if (isset($credentials['project_id']) && !is_string($credentials['project_id'])) {
			wp_send_json_error([ 'message' => 'project_id is not a string' ]);
		}

		// Abort if $credentials['private_key_id'] is not a private key ID
		if (isset($credentials['private_key_id']) && !is_string($credentials['private_key_id'])) {
			wp_send_json_error([ 'message' => 'private_key_id is not a string' ]);
		}

		// Abort if $credentials['private_key'] is not a private key
		if (isset($credentials['private_key']) && !is_string($credentials['private_key'])) {
			wp_send_json_error([ 'message' => 'private_key is not a string' ]);
		}

		// Abort if $credentials['client_email'] is not a client email
		if (isset($credentials['client_email']) && !Helpers::is_email($credentials['client_email'])) {
			wp_send_json_error([ 'message' => 'client_email is not an email' ]);
		}

		// Abort if $credentials['client_id'] is not empty and not only numbers
		if (
			!empty($credentials['client_id'])
			&& !is_numeric($credentials['client_id'])
		) {
			wp_send_json_error([ 'message' => 'client_id is not numeric' ]);
		}

		// Abort if $credentials['auth_uri'] is not a valid URL
		if (isset($credentials['auth_uri']) && !Helpers::is_url($credentials['auth_uri'])) {
			wp_send_json_error([ 'message' => 'auth_uri is not a valid URL' ]);
		}

		// Abort if $credentials['token_uri'] is not a valid URL
		if (isset($credentials['token_uri']) && !Helpers::is_url($credentials['token_uri'])) {
			wp_send_json_error([ 'message' => 'token_uri is not a valid URL' ]);
		}

		// Abort if $credentials['auth_provider_x509_cert_url'] is not a valid URL
		if (
			isset($credentials['auth_provider_x509_cert_url'])
			&& !Helpers::is_url($credentials['auth_provider_x509_cert_url'])
		) {
			wp_send_json_error([ 'message' => 'auth_provider_x509_cert_url is not a valid URL' ]);
		}

		// Abort if $credentials['client_x509_cert_url'] is not a valid URL
		if (
			isset($credentials['client_x509_cert_url'])
			&& !Helpers::is_url($credentials['client_x509_cert_url'])
		) {
			wp_send_json_error([ 'message' => 'client_x509_cert_url is not a valid URL' ]);
		}

		return true;
	}

	/**
	 * Regex validations
	 */

	public static function is_adroll_advertiser_id( $string ) {

		$re = '/^[A-Z0-9]{22}$/m';

		return self::validate_with_regex($re, $string);
	}

	public static function is_adroll_pixel_id( $string ) {

		$re = '/^[A-Z0-9]{22}$/m';

		return self::validate_with_regex($re, $string);
	}

	public static function is_gads_conversion_id( $string ) {

		$re = '/^\d{8,11}$/m';

		return self::validate_with_regex($re, $string);
	}

	public static function is_hotjar_site_id( $string ) {

		$re = '/^\d{6,9}$/m';

		return self::validate_with_regex($re, $string);
	}

	public static function is_reddit_advertiser_id( $string ) {

		$re = '/^(a2_|t2_)[a-z0-9]{6,12}$/m';

		return self::validate_with_regex($re, $string);
	}

	public static function is_vwo_account_id( $string ) {

		$re = '/^\d{4,10}$/m';

		return self::validate_with_regex($re, $string);
	}

	public static function is_optimizely_project_id( $string ) {

		$re = '/^\d{8,14}$/m';

		return self::validate_with_regex($re, $string);
	}

	public static function is_ab_tasty_account_id( $string ) {

		$re = '/^[\da-z]{26,38}$/m';

		return self::validate_with_regex($re, $string);
	}

	public static function is_scroll_tracker_thresholds( $string ) {

		// https://regex101.com/r/4haInV/1
		$re = '/^([\d]|[\d][\d]|100)(,([\d]|[\d][\d]|100))*$/m';

		return self::validate_with_regex($re, $string);
	}

	public static function is_facebook_capi_token( $string ) {

		$re = '/^[a-zA-Z\d_-]{150,250}$/m';

		return self::validate_with_regex($re, $string);
	}

	public static function is_facebook_capi_test_event_code( $string ) {

		$re = '/^TEST\d{3,7}$/m';

		return self::validate_with_regex($re, $string);
	}

	public static function is_facebook_domain_verification_id( $string ) {

		$re = '/^[a-zA-Z\d]{20,40}$/m';

		return self::validate_with_regex($re, $string);
	}

	public static function is_gads_conversion_label( $string ) {

		$re = '/^[-a-zA-Z_0-9]{17,20}$/m';

		return self::validate_with_regex($re, $string);
	}

	public static function is_gads_aw_merchant_id( $string ) {

		$re = '/^\d{6,12}$/m';

		return self::validate_with_regex($re, $string);
	}

	public static function is_google_optimize_measurement_id( $string ) {

		$re = '/^(GTM|OPT)-[A-Z0-9]{6,8}$/m';

		return self::validate_with_regex($re, $string);
	}

	public static function is_google_analytics_4_measurement_id( $string ) {

		$re = '/^G-[A-Z0-9]{10,12}$/m';

		return self::validate_with_regex($re, $string);
	}


	public static function is_google_analytics_4_api_secret( $string ) {

		$re = '/^[a-zA-Z\d_-]{18,26}$/m';

		return self::validate_with_regex($re, $string);
	}

	public static function is_google_analytics_4_property_id( $string ) {

		$re = '/^\d{6,12}$/m';

		return self::validate_with_regex($re, $string);
	}

	public static function is_facebook_pixel_id( $string ) {

		$re = '/^\d{12,22}$/m';

		return self::validate_with_regex($re, $string);
	}

	public static function is_bing_uet_tag_id( $string ) {

		$re = '/^\d{7,9}$/m';

		return self::validate_with_regex($re, $string);
	}

	public static function is_linkedin_partner_id( $string ) {

		$re = '/^\d{5,10}$/m';

		return self::validate_with_regex($re, $string);
	}

	public static function is_linkedin_conversion_id( $string ) {

		$re = '/^\d{6,12}$/m';

		return self::validate_with_regex($re, $string);
	}

	public static function is_outbrain_account_id( $string ) {

		$re = '/^[\da-z]{30,38}$/m';

		return self::validate_with_regex($re, $string);
	}

	public static function is_pinterest_ad_account_id( $string ) {

		$re = '/^\d{12,13}$/m';

		return self::validate_with_regex($re, $string);
	}

	public static function is_pinterest_apic_token( $string ) {

		$re = '/^pina_[A-Z0-9]{96}$/m';

		return self::validate_with_regex($re, $string);
	}

	public static function is_twitter_pixel_id( $string ) {

		$re = '/^[a-z0-9]{5,7}$/m';

		return self::validate_with_regex($re, $string);
	}

	public static function is_twitter_event_id( $string ) {

		$re = '/^tw-[a-z0-9]{5}-[a-z0-9]{5}$/m';

		return self::validate_with_regex($re, $string);
	}

	public static function is_pinterest_pixel_id( $string ) {

		$re = '/^\d{13}$/m';

		return self::validate_with_regex($re, $string);
	}

	public static function is_snapchat_pixel_id( $string ) {

		$re = '/^[a-z0-9\-]*$/m';

		return self::validate_with_regex($re, $string);
	}

	public static function is_snapchat_capi_token( $string ) {

		$re = '/^[a-zA-Z0-9\.\-_]{200,600}$/m';

		return self::validate_with_regex($re, $string);
	}

	public static function is_taboola_account_id( $string ) {

		$re = '/^[\d]{4,10}$/m';

		return self::validate_with_regex($re, $string);
	}

	public static function is_tiktok_pixel_id( $string ) {

		$re = '/^[A-Z0-9]{20,20}$/m';

		return self::validate_with_regex($re, $string);
	}

	public static function is_tiktok_eapi_access_token( $string ) {

		$re = '/^[\da-z]{30,50}$/m';

		return self::validate_with_regex($re, $string);
	}


	public static function is_tiktok_eapi_test_event_code( $string ) {

		$re = '/^TEST\d{3,7}$/m';

		return self::validate_with_regex($re, $string);
	}

	public static function validate_with_regex( $re, $string ) {

		if (empty($string)) {
			return true;
		}

		// Validate if string matches the regex $re
		if (preg_match($re, $string)) {
			return true;
		}

		return false;
	}

	/**
	 * Validate if string is a valid conversion name for conversion adjustments.
	 *
	 * It must be a string and not contain any special characters, quotes or single quotes.
	 * Dashes, underscores, spaces, numbers, slashes and letters are allowed.
	 *
	 * @param string $string
	 *
	 * @return bool
	 */
	public static function is_valid_conversion_adjustments_conversion_name( $string ) {

		// Return true if $string is empty
		// To be able to save empty conversion names
		if (empty($string)) {
			return true;
		}

		// Return false if $string is not a string
		if (!is_string($string)) {
			return false;
		}

		// Return false if $string contains any special characters, quotes or single quotes
		if (preg_match('/[^a-zA-Z0-9_\-\/\s]/', $string)) {
			return false;
		}

		return true;
	}

	public static function is_subscription_value_multiplier( $string ) {

		// Return true if $string is a float or integer
		if (!is_numeric($string)) {
			return false;
		}

		// The value must be at least 1.00
		if (floatval($string) < 1.00) {
			return false;
		}

		return true;
	}
}
