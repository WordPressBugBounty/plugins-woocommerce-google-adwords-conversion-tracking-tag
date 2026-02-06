<?php

namespace SweetCode\Pixel_Manager\Admin;

defined('ABSPATH') || exit; // Exit if accessed directly

class Documentation {

	public static function get_link( $key = 'default', $sweetcode_override = false ) {

		$url = self::get_documentation_host() . self::get_documentation_path($key);

		return self::add_utm_parameters($url, $key);
	}

	private static function add_utm_parameters( $url, $key ) {

		$url_parts = explode('#', $url);

		$url = $url_parts[0] . '?utm_source=woocommerce-plugin&utm_medium=documentation-link&utm_campaign=' . str_replace('_', '-', $key);

		if (count($url_parts) === 2) {
			$url .= '#' . $url_parts[1];
		}

		return $url;
	}

	private static function get_documentation_host() {
		return 'https://sweetcode.com';
	}

	private static function get_documentation_path( $key = 'default' ) {

		$documentation_links = [
			'default'                                           => '/docs/pmw/',
			'acr'                                               => '/docs/pmw/features/acr',
			'adroll_advertiser_id'                              => '/docs/pmw/plugin-configuration/adroll#advertiser-id-and-pixel-id',
			'adroll_pixel_id'                                   => '/docs/pmw/plugin-configuration/adroll#advertiser-id-and-pixel-id',
			'aw_merchant_id'                                    => '/docs/pmw/plugin-configuration/google-ads/#conversion-cart-data',
			'bing_enhanced_conversions'                         => '/docs/pmw/plugin-configuration/microsoft-advertising#enhanced-conversions',
			'bing_uet_tag_id'                                   => '/docs/pmw/plugin-configuration/microsoft-advertising#setting-up-the-uet-tag',
			'custom_thank_you'                                  => '/docs/pmw/troubleshooting/#wc-custom-thank-you',
			'duplication_prevention'                            => '/docs/pmw/shop#order-duplication-prevention',
			'dynamic_remarketing'                               => '/docs/pmw/plugin-configuration/shop-settings#dynamic-remarketing',
			'explicit_consent_mode'                             => '/docs/pmw/consent-management/overview#explicit-consent-mode',
			'facebook_advanced_matching'                        => '/docs/pmw/plugin-configuration/meta#meta-facebook-advanced-matching',
			'facebook_capi_token'                               => '/docs/pmw/plugin-configuration/meta/#meta-facebook-conversion-api-capi',
			'facebook_domain_verification_id'                   => '/docs/pmw/plugin-configuration/meta#domain-verification',
			'facebook_microdata'                                => '/docs/pmw/plugin-configuration/meta#microdata-tags-for-catalogues',
			'facebook_microdata_deprecation'                    => '/blog/facebook-microdata-for-catalog-deprecation-notice',
			'facebook_pixel_id'                                 => '/docs/pmw/plugin-configuration/meta#find-the-pixel-id',
			'ga4_data_api'                                      => '/docs/pmw/plugin-configuration/google-analytics#ga4-data-api',
			'ga4_data_api_credentials'                          => '/docs/pmw/plugin-configuration/google-analytics#ga4-data-api-credentials',
			'ga4_data_api_property_id'                          => '/docs/pmw/plugin-configuration/google-analytics#ga4-property-id',
			'ga4_page_load_time_tracking'                       => '/docs/pmw/plugin-configuration/google-analytics#page-load-time-tracking',
			'google_ads_conversion_adjustments'                 => '/docs/pmw/plugin-configuration/google-ads#conversion-adjustments',
			'google_ads_conversion_id'                          => '/docs/pmw/plugin-configuration/google-ads#configure-the-plugin',
			'google_ads_conversion_label'                       => '/docs/pmw/plugin-configuration/google-ads#configure-the-plugin',
			'google_ads_phone_conversion_label'                 => '/docs/pmw/plugin-configuration/google-ads#phone-conversion-number',
			'google_ads_phone_conversion_number'                => '/docs/pmw/plugin-configuration/google-ads#phone-conversion-number',
			'google_analytics_4_api_secret'                     => '/docs/pmw/plugin-configuration/google-analytics#ga4-api-secret',
			'google_analytics_4_id'                             => '/docs/pmw/plugin-configuration/google-analytics#connect-an-existing-google-analytics-4-property',
			'google_analytics_eec'                              => '/docs/pmw/plugin-configuration/google-analytics#enhanced-e-commerce-funnel-setup',
			'google_analytics_universal_property'               => '/docs/pmw/plugin-configuration/google-analytics',
			'google_consent_mode'                               => '/docs/pmw/consent-management/google#google-consent-mode',
			'google_enhanced_conversions'                       => '/docs/pmw/plugin-configuration/google-ads#enhanced-conversions',
			'google_gtag_deactivation'                          => '/docs/pmw/faq/#google-tag-assistant-reports-multiple-installations-of-global-site-tag-gtagjs-detected-what-shall-i-do',
			'google_optimize_anti_flicker'                      => '/docs/pmw/plugin-configuration/google-optimize#anti-flicker-snippet',
			'google_optimize_anti_flicker_timeout'              => '/docs/pmw/plugin-configuration/google-optimize#adjusting-the-anti-flicker-snippet-timeout',
			'google_optimize_container_id'                      => '/docs/pmw/plugin-configuration/google-optimize',
			'google_tag_gateway_measurement_path'               => '/docs/pmw/plugin-configuration/google#google-tag-gateway-for-advertisers',
			'google_tag_id'                                     => '/docs/pmw/plugin-configuration/google#google-tag-gateway-for-advertisers',
			'google_tcf_support'                                => '/docs/pmw/consent-management/google#google-tcf-support',
			'hotjar_site_id'                                    => '/docs/pmw/plugin-configuration/hotjar#hotjar-site-id',
			'crazyegg_account_number'                           => '/docs/pmw/plugin-configuration/crazyegg#crazyegg-account-number',
			'lazy_load_pmw'                                     => '/docs/pmw/plugin-configuration/general-settings#lazy-load-the-pixel-manager',
			'license_expired_warning'                           => '/docs/pmw/license-management#expired-license-warning',
			'linkedin_partner_id'                               => '/docs/pmw/plugin-configuration/linkedin#partner-id',
			'linkedin_event_ids'                                => '/docs/pmw/plugin-configuration/linkedin#event-setup',
			'litespeed-cache-inline-javascript-after-dom-ready' => '/docs/pmw/troubleshooting',
			'load_deprecated_functions'                         => '/docs/pmw/plugin-configuration/general-settings#load-deprecated-functions',
			'log_files'                                         => '/docs/pmw/developers/logs#accessing-log-files',
			'log_http_requests'                                 => '/docs/pmw/developers/logs#log-http-requests',
			'log_level'                                         => '/docs/pmw/developers/logs#log-levels',
			'logger_activation'                                 => '/docs/pmw/developers/logs#logger-activation',
			'ltv_order_calculation'                             => '/docs/pmw/plugin-configuration/shop-settings#active-lifetime-value-calculation',
			'ltv_recalculation'                                 => '/docs/pmw/plugin-configuration/shop-settings#lifetime-value-recalculation',
			'marketing_value_logic'                             => '/docs/pmw/plugin-configuration/shop-settings#marketing-value-logic',
			'marketing_value_profit_margin'                     => '/docs/pmw/plugin-configuration/shop-settings#profit-margin',
			'marketing_value_subtotal'                          => '/docs/pmw/plugin-configuration/shop-settings#order-subtotal-default',
			'marketing_value_total'                             => '/docs/pmw/plugin-configuration/shop-settings#order-total',
			'maximum_compatibility_mode'                        => '/docs/pmw/plugin-configuration/general-settings/#maximum-compatibility-mode',
			'microsoft_ads_consent_mode'                        => '/docs/pmw/consent-management/microsoft#microsoft-ads-consent-mode',
			'opportunity_google_ads_conversion_adjustments'     => '/docs/pmw/opportunities#google-ads-conversion-adjustments',
			'opportunity_google_enhanced_conversions'           => '/docs/pmw/opportunities#google-ads-enhanced-conversions',
			'order_extra_details'                               => '/docs/pmw/plugin-configuration/shop-settings#extra-order-data-output',
			'order_list_info'                                   => '/docs/pmw/diagnostics#order-list-info',
			'order_modal_ltv'                                   => '/docs/pmw/shop#lifetime-value',
			'outbrain_advertiser_id'                            => '/docs/pmw/plugin-configuration/outbrain',
			'pageview_events_s2s'                               => '/docs/pmw/plugin-configuration/general-settings#track-pageview-events-server-to-server',
			'payment-gateways'                                  => '/docs/pmw/setup/requirements#payment-gateways',
			'payment_gateway_tracking_accuracy'                 => '/docs/pmw/diagnostics/#payment-gateway-tracking-accuracy-report',
			'pinterest_ad_account_id'                           => '/docs/pmw/plugin-configuration/pinterest#ad-account-id',
			'pinterest_advanced_matching'                       => '/docs/pmw/plugin-configuration/pinterest#advanced-matching',
			'pinterest_apic_token'                              => '/docs/pmw/plugin-configuration/pinterest#api-for-conversions-token',
			'pinterest_enhanced_match'                          => '/docs/pmw/plugin-configuration/pinterest#enhanced-match',
			'pinterest_pixel_id'                                => '/docs/pmw/plugin-configuration/pinterest',
			'reddit_advanced_matching'                          => '/docs/pmw/plugin-configuration/reddit#advanced-matching',
			'reddit_advertiser_id'                              => '/docs/pmw/plugin-configuration/reddit#setup-instruction',
			'reddit_capi_test_event_code'                       => '/docs/pmw/plugin-configuration/reddit#testing',
			'reddit_capi_token'                                 => '/docs/pmw/plugin-configuration/reddit#conversions-api-capi',
			'restricted_consent_regions'                        => '/docs/pmw/consent-management/overview#explicit-consent-regions',
			'script_blockers'                                   => '/docs/pmw/setup/script-blockers/',
			'scroll_tracker_threshold'                          => '/docs/pmw/plugin-configuration/general-settings/#scroll-tracker',
			'snapchat_advanced_matching'                        => '/docs/pmw/plugin-configuration/snapchat#advanced-matching',
			'snapchat_capi_token'                               => '/docs/pmw/plugin-configuration/snapchat#conversions-api',
			'snapchat_pixel_id'                                 => '/docs/pmw/plugin-configuration/snapchat',
			'subscription_value_multiplier'                     => '/docs/pmw/plugin-configuration/shop-settings#subscription-value-multiplier',
			'taboola_account_id'                                => '/docs/pmw/plugin-configuration/taboola',
			'test_order'                                        => '/docs/pmw/testing#test-order',
			'the_dismiss_button_doesnt_work_why'                => '/docs/pmw/faq/#the-dismiss-button-doesnt-work-why',
			'tiktok_advanced_matching'                          => '/docs/pmw/plugin-configuration/tiktok#advanced-matching',
			'tiktok_eapi_token'                                 => '/docs/pmw/plugin-configuration/tiktok#access-token',
			'tiktok_pixel_id'                                   => '/docs/pmw/plugin-configuration/tiktok',
			'trial_promotion'                                   => '/docs/pmw/features/why-upgrade-to-pro',
			'twitter_event_ids'                                 => '/docs/pmw/plugin-configuration/twitter#event-setup',
			'twitter_pixel_id'                                  => '/docs/pmw/plugin-configuration/twitter#pixel-id',
			'variations_output'                                 => '/docs/pmw/plugin-configuration/shop-settings#variations-output',
			'vwo_account_id'                                    => '/docs/pmw/plugin-configuration/vwo',
			'wp-rocket-javascript-concatenation'                => '/docs/pmw/troubleshooting',
		];

		if (array_key_exists($key, $documentation_links)) {
			return $documentation_links[$key];
		} else {
			return $documentation_links['default'];
		}
	}
}
