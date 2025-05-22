<?php

namespace SweetCode\Pixel_Manager\Admin;

defined('ABSPATH') || exit; // Exit if accessed directly

class Documentation {

	public static function get_link( $key = 'default', $sweetcode_override = false ) {

		// Change to wcm through gulp for the wcm distribution
		$doc_host_url = PMW_DISTRO === 'wcm' ? 'wcm' : 'default';

		$url = self::get_documentation_host($sweetcode_override) . self::get_documentation_path($key, $doc_host_url);

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

	private static function get_documentation_host( $sweetcode_override ) {

		if ($sweetcode_override) {
			return 'https://sweetcode.com';
		}

		if ('wcm' === PMW_DISTRO) {
			return 'https://woocommerce.com';
		}

		return 'https://sweetcode.com';
	}

	private static function get_documentation_path( $key = 'default', $doc_host_url = 'default' ) {

		$documentation_links = [
			'default'                                                => [
				'default' => '/docs/wpm/',
				'wcm'     => '/document/pixel-manager-pro-for-woocommerce/',
			],
			'script_blockers'                                        => [
				'default' => '/docs/wpm/setup/script-blockers/',
				'wcm'     => '/document/pixel-manager-pro-for-woocommerce/script-blockers/',
			],
			'google_analytics_universal_property'                    => [
				'default' => '/docs/wpm/plugin-configuration/google-analytics',
				'wcm'     => '/document/pixel-manager-pro-for-woocommerce/pmw-plugin-configuration/google-analytics/',
			],
			'google_analytics_4_id'                                  => [
				'default' => '/docs/wpm/plugin-configuration/google-analytics#connect-an-existing-google-analytics-4-property',
				'wcm'     => '/document/pixel-manager-pro-for-woocommerce/pmw-plugin-configuration/google-analytics/#section-3',
			],
			'google_ads_conversion_id'                               => [
				'default' => '/docs/wpm/plugin-configuration/google-ads#configure-the-plugin',
				'wcm'     => '/document/pixel-manager-pro-for-woocommerce/pmw-plugin-configuration/google-ads/#section-2',
			],
			'google_ads_conversion_label'                            => [
				'default' => '/docs/wpm/plugin-configuration/google-ads#configure-the-plugin',
				'wcm'     => '/document/pixel-manager-pro-for-woocommerce/pmw-plugin-configuration/google-ads/#section-2',
			],
			'google_optimize_container_id'                           => [
				'default' => '/docs/wpm/plugin-configuration/google-optimize',
				'wcm'     => '/document/pixel-manager-pro-for-woocommerce/pmw-plugin-configuration/google-optimize/',
			],
			'google_optimize_anti_flicker'                           => [
				'default' => '/docs/wpm/plugin-configuration/google-optimize#anti-flicker-snippet',
				'wcm'     => '/document/pixel-manager-pro-for-woocommerce/pmw-plugin-configuration/google-optimize/#section-3',
			],
			'google_optimize_anti_flicker_timeout'                   => [
				'default' => '/docs/wpm/plugin-configuration/google-optimize#adjusting-the-anti-flicker-snippet-timeout',
				'wcm'     => '/document/pixel-manager-pro-for-woocommerce/pmw-plugin-configuration/google-optimize/#section-3',
			],
			'facebook_pixel_id'                                      => [
				'default' => '/docs/wpm/plugin-configuration/meta#find-the-pixel-id',
				'wcm'     => '/document/pixel-manager-pro-for-woocommerce/pmw-plugin-configuration/facebook/#find-the-pixel-id',
			],
			'bing_uet_tag_id'                                        => [
				'default' => '/docs/wpm/plugin-configuration/microsoft-advertising#setting-up-the-uet-tag',
				'wcm'     => '/document/pixel-manager-pro-for-woocommerce/pmw-plugin-configuration/microsoft-advertising-bing-ads/#section-1',
			],
			'twitter_pixel_id'                                       => [
				'default' => '/docs/wpm/plugin-configuration/twitter#pixel-id',
				'wcm'     => '/document/pixel-manager-pro-for-woocommerce/',
			],
			'twitter_event_ids'                                      => [
				'default' => '/docs/wpm/plugin-configuration/twitter#event-setup',
				'wcm'     => '/document/pixel-manager-pro-for-woocommerce/',
			],
			'pinterest_pixel_id'                                     => [
				'default' => '/docs/wpm/plugin-configuration/pinterest',
				'wcm'     => '/document/pixel-manager-pro-for-woocommerce/pmw-plugin-configuration/pinterest/',
			],
			'snapchat_pixel_id'                                      => [
				'default' => '/docs/wpm/plugin-configuration/snapchat',
				'wcm'     => '/document/pixel-manager-pro-for-woocommerce/',
			],
			'snapchat_capi_token'                                      => [
				'default' => '/docs/wpm/plugin-configuration/snapchat#conversions-api',
				'wcm'     => '/document/pixel-manager-pro-for-woocommerce/pmw-plugin-configuration/snapchat/#section-2',
			],
			'snapchat_advanced_matching'                             => [
				'default' => '/docs/wpm/plugin-configuration/snapchat#advanced-matching',
				'wcm'     => '/document/pixel-manager-pro-for-woocommerce/pmw-plugin-configuration/snapchat/#section-3',
			],
			'tiktok_pixel_id'                                        => [
				'default' => '/docs/wpm/plugin-configuration/tiktok',
				'wcm'     => '/document/pixel-manager-pro-for-woocommerce/pmw-plugin-configuration/tiktok/',
			],
			'tiktok_advanced_matching'                               => [
				'default' => '/docs/wpm/plugin-configuration/tiktok#advanced-matching',
				'wcm'     => '/document/pixel-manager-pro-for-woocommerce/pmw-plugin-configuration/tiktok/#tiktok-advanced-matching',
			],
			'tiktok_eapi_token'                                      => [
				'default' => '/docs/wpm/plugin-configuration/tiktok#access-token',
				'wcm'     => '/document/pixel-manager-pro-for-woocommerce/pmw-plugin-configuration/tiktok/#access-token',
			],
			'tiktok_eapi_process_anonymous_hits'                     => [
				'default' => '/docs/wpm/plugin-configuration/tiktok#process-anonymous-hits',
				'wcm'     => '/document/pixel-manager-pro-for-woocommerce/pmw-plugin-configuration/tiktok/#process-anonymous-hits',
			],
			'vwo_account_id'                                         => [
				'default' => '/docs/wpm/plugin-configuration/vwo',
				'wcm'     => '/document/pixel-manager-pro-for-woocommerce/vwo/',
			],
			'hotjar_site_id'                                         => [
				'default' => '/docs/wpm/plugin-configuration/hotjar#hotjar-site-id',
				'wcm'     => '/document/pixel-manager-pro-for-woocommerce/pmw-plugin-configuration/hotjar/#section-1',
			],
			'google_gtag_deactivation'                               => [
				'default' => '/docs/wpm/faq/&utm_medium=documentation-link&utm_campaign=pixel-manager-for-woocommerce-docs&utm_content=gtag-js#google-tag-assistant-reports-multiple-installations-of-global-site-tag-gtagjs-detected-what-shall-i-do',
				'wcm'     => '/document/pixel-manager-pro-for-woocommerce/',
			],
			'google_consent_mode'                                    => [
				'default' => '/docs/wpm/consent-management/google-consent-mode',
				'wcm'     => '/document/pixel-manager-pro-for-woocommerce/consent-management/google-consent-mode/',
			],
			'restricted_consent_regions'                             => [
				'default' => '/docs/wpm/consent-management/overview#explicit-consent-regions',
				'wcm'     => '/document/pixel-manager-pro-for-woocommerce/consent-management/google-consent-mode/#section-3',
			],
			'google_analytics_eec'                                   => [
				'default' => '/docs/wpm/plugin-configuration/google-analytics#enhanced-e-commerce-funnel-setup',
				'wcm'     => '/document/pixel-manager-pro-for-woocommerce/pmw-plugin-configuration/google-analytics/#section-5',
			],
			'google_analytics_4_api_secret'                          => [
				'default' => '/docs/wpm/plugin-configuration/google-analytics#ga4-api-secret',
				'wcm'     => '/document/pixel-manager-pro-for-woocommerce/pmw-plugin-configuration/google-analytics/#section-4',
			],
			'google_enhanced_conversions'                            => [
				'default' => '/docs/wpm/plugin-configuration/google-ads#enhanced-conversions',
				'wcm'     => '/document/pixel-manager-pro-for-woocommerce/pmw-plugin-configuration/google-ads/#section-5',
			],
			'google_ads_phone_conversion_number'                     => [
				'default' => '/docs/wpm/plugin-configuration/google-ads#phone-conversion-number',
				'wcm'     => '/document/pixel-manager-pro-for-woocommerce/pmw-plugin-configuration/google-ads/#section-4',
			],
			'google_ads_phone_conversion_label'                      => [
				'default' => '/docs/wpm/plugin-configuration/google-ads#phone-conversion-number',
				'wcm'     => '/document/pixel-manager-pro-for-woocommerce/pmw-plugin-configuration/google-ads/#section-4',
			],
			'explicit_consent_mode'                                  => [
				'default' => '/docs/wpm/consent-management/overview#explicit-consent-mode',
				'wcm'     => '/document/pixel-manager-pro-for-woocommerce/consent-management/overview/#section-1',
			],
			'facebook_capi_token'                                    => [
				'default' => '/docs/wpm/plugin-configuration/meta/#meta-facebook-conversion-api-capi',
				'wcm'     => '/document/pixel-manager-pro-for-woocommerce/pmw-plugin-configuration/facebook/#section-4',
			],
			'facebook_capi_user_transparency_process_anonymous_hits' => [
				'default' => '/docs/wpm/plugin-configuration/meta#user-transparency-settings',
				'wcm'     => '/document/pixel-manager-pro-for-woocommerce/pmw-plugin-configuration/facebook/#section-5',
			],
			'facebook_advanced_matching'                             => [
				'default' => '/docs/wpm/plugin-configuration/meta#meta-facebook-advanced-matching',
				'wcm'     => '/document/pixel-manager-pro-for-woocommerce/pmw-plugin-configuration/facebook/#section-8',
			],
			'facebook_microdata'                                     => [
				'default' => '/docs/wpm/plugin-configuration/meta#microdata-tags-for-catalogues',
				'wcm'     => '/document/pixel-manager-pro-for-woocommerce/pmw-plugin-configuration/facebook/#section-8',
			],
			'maximum_compatibility_mode'                             => [
				'default' => '/docs/wpm/plugin-configuration/general-settings/#maximum-compatibility-mode',
				'wcm'     => '/document/pixel-manager-pro-for-woocommerce/',
			],
			'dynamic_remarketing'                                    => [
				'default' => '/docs/wpm/plugin-configuration/shop-settings#dynamic-remarketing',
				'wcm'     => '/document/pixel-manager-pro-for-woocommerce/pmw-plugin-configuration/dynamic-remarketing/',
			],
			'variations_output'                                      => [
				'default' => '/docs/wpm/plugin-configuration/shop-settings#dynamic-remarketing',
				'wcm'     => '/document/pixel-manager-pro-for-woocommerce/pmw-plugin-configuration/dynamic-remarketing/',
			],
			'aw_merchant_id'                                         => [
				'default' => '/docs/wpm/plugin-configuration/google-ads/#conversion-cart-data',
				'wcm'     => '/document/pixel-manager-pro-for-woocommerce/pmw-plugin-configuration/google-ads/#section-3',
			],
			'custom_thank_you'                                       => [
				'default' => '/docs/wpm/troubleshooting/#wc-custom-thank-you',
				'wcm'     => '/document/pixel-manager-pro-for-woocommerce/troubleshooting/#wc-custom-thank-you',
			],
			'the_dismiss_button_doesnt_work_why'                     => [
				'default' => '/docs/wpm/faq/#the-dismiss-button-doesnt-work-why',
				'wcm'     => '/document/pixel-manager-pro-for-woocommerce/faq/#section-10',
			],
			'wp-rocket-javascript-concatenation'                     => [
				'default' => '/docs/wpm/troubleshooting',
				'wcm'     => '/document/pixel-manager-pro-for-woocommerce/',
			],
			'litespeed-cache-inline-javascript-after-dom-ready'      => [
				'default' => '/docs/wpm/troubleshooting',
				'wcm'     => '/document/pixel-manager-pro-for-woocommerce/',
			],
			'payment-gateways'                                       => [
				'default' => '/docs/wpm/setup/requirements#payment-gateways',
				'wcm'     => '/document/pixel-manager-pro-for-woocommerce/requirements/#payment-gateways',
			],
			'test_order'                                             => [
				'default' => '/docs/wpm/testing#test-order',
				'wcm'     => '/document/pixel-manager-pro-for-woocommerce/testing/',
			],
			'payment_gateway_tracking_accuracy'                      => [
				'default' => '/docs/wpm/diagnostics/#payment-gateway-tracking-accuracy-report',
				'wcm'     => '/document/pixel-manager-pro-for-woocommerce/diagnostics/#payment-gateway-tracking-accuracy-report',
			],
			'acr'                                                    => [
				'default' => '/docs/wpm/features/acr',
				'wcm'     => '/document/pixel-manager-pro-for-woocommerce/features/automatic-conversion-recovery-acr/',
			],
			'order_list_info'                                        => [
				'default' => '/docs/wpm/diagnostics#order-list-info',
				'wcm'     => '/document/pixel-manager-pro-for-woocommerce/pmw-plugin-configuration/general-settings/#order-list-info',
			],
			'marketing_value_logic'                                  => [
				'default' => '/docs/wpm/plugin-configuration/shop-settings#marketing-value-logic',
				'wcm'     => '/document/pixel-manager-pro-for-woocommerce/',
			],
			'marketing_value_subtotal'                               => [
				'default' => '/docs/wpm/plugin-configuration/shop-settings#order-subtotal-default',
				'wcm'     => '/document/pixel-manager-pro-for-woocommerce/',
			],
			'marketing_value_total'                                  => [
				'default' => '/docs/wpm/plugin-configuration/shop-settings#order-total',
				'wcm'     => '/document/pixel-manager-pro-for-woocommerce/',
			],
			'marketing_value_profit_margin'                          => [
				'default' => '/docs/wpm/plugin-configuration/shop-settings#profit-margin',
				'wcm'     => '/document/pixel-manager-pro-for-woocommerce/pmw-plugin-configuration/general-settings/#profit-margin',
			],
			'scroll_tracker_threshold'                               => [
				'default' => '/docs/wpm/plugin-configuration/general-settings/#scroll-tracker',
				'wcm'     => '/document/pixel-manager-pro-for-woocommerce/pmw-plugin-configuration/general-settings/#section-8',
			],
			'google_ads_conversion_adjustments'                      => [
				'default' => '/docs/wpm/plugin-configuration/google-ads#conversion-adjustments',
				'wcm'     => '/document/pixel-manager-pro-for-woocommerce/pmw-plugin-configuration/google-ads/#section-6',
			],
			'ga4_data_api'                               => [
				'default' => '/docs/wpm/plugin-configuration/google-analytics#ga4-data-api',
				'wcm'     => '/document/pixel-manager-pro-for-woocommerce/pmw-plugin-configuration/google-analytics/#section-3',
			],
			'ga4_data_api_property_id'                               => [
				'default' => '/docs/wpm/plugin-configuration/google-analytics#ga4-property-id',
				'wcm'     => '/document/pixel-manager-pro-for-woocommerce/pmw-plugin-configuration/google-analytics/#ga4-property-id',
			],
			'ga4_data_api_credentials'                               => [
				'default' => '/docs/wpm/plugin-configuration/google-analytics#ga4-data-api-credentials',
				'wcm'     => '/document/pixel-manager-pro-for-woocommerce/pmw-plugin-configuration/google-analytics/#ga4-data-api-credentials',
			],
			'duplication_prevention'                                 => [
				'default' => '/docs/wpm/shop#order-duplication-prevention',
				'wcm'     => '/document/pixel-manager-pro-for-woocommerce/shop/#order-duplication-prevention',
			],
			'license_expired_warning'                                => [
				'default' => '/docs/wpm/license-management#expired-license-warning',
				'wcm'     => '/',
			],
			'subscription_value_multiplier'                          => [
				'default' => '/docs/wpm/plugin-configuration/shop-settings#subscription-value-multiplier',
				'wcm'     => '/document/pixel-manager-pro-for-woocommerce/pmw-plugin-configuration/shop-settings/#section-9',
			],
			'lazy_load_pmw'                                          => [
				'default' => '/docs/wpm/plugin-configuration/general-settings#lazy-load-the-pixel-manager',
				'wcm'     => '/document/pixel-manager-pro-for-woocommerce/pmw-plugin-configuration/general-settings/#lazy-load-the-pixel-manager',
			],
			'opportunity_google_enhanced_conversions'                => [
				'default' => '/docs/wpm/opportunities#google-ads-enhanced-conversions',
				'wcm'     => '/document/pixel-manager-pro-for-woocommerce/opportunities/#section-1',
			],
			'opportunity_google_ads_conversion_adjustments'          => [
				'default' => '/docs/wpm/opportunities#google-ads-conversion-adjustments',
				'wcm'     => '/document/pixel-manager-pro-for-woocommerce/opportunities/#section-2',
			],
			'ga4_page_load_time_tracking'                            => [
				'default' => '/docs/wpm/plugin-configuration/google-analytics#page-load-time-tracking',
				'wcm'     => '/document/pixel-manager-pro-for-woocommerce/pmw-plugin-configuration/google-analytics/#section-9',
			],
			'reddit_advertiser_id'                                   => [
				'default' => '/docs/wpm/plugin-configuration/reddit#setup-instruction',
				'wcm'     => '/document/pixel-manager-pro-for-woocommerce/pmw-plugin-configuration/reddit/',
			],
			'reddit_advanced_matching'                               => [
				'default' => '/docs/wpm/plugin-configuration/reddit#advanced-matching',
				'wcm'     => '/document/pixel-manager-pro-for-woocommerce/pmw-plugin-configuration/reddit/#section-3',
			],
			'pinterest_ad_account_id'                                => [
				'default' => '/docs/wpm/plugin-configuration/pinterest#ad-account-id',
				'wcm'     => '/document/pixel-manager-pro-for-woocommerce/pmw-plugin-configuration/pinterest/#section-5',
			],
			'pinterest_apic_token'                                   => [
				'default' => '/docs/wpm/plugin-configuration/pinterest#api-for-conversions-token',
				'wcm'     => '/document/pixel-manager-pro-for-woocommerce/pmw-plugin-configuration/pinterest/#section-6',
			],
			'pinterest_apic_process_anonymous_hits'                  => [
				'default' => '/docs/wpm/plugin-configuration/pinterest#process-anonymous-hits',
				'wcm'     => '/document/pixel-manager-pro-for-woocommerce/pmw-plugin-configuration/pinterest/#section-8',
			],
			'pinterest_enhanced_match'                               => [
				'default' => '/docs/wpm/plugin-configuration/pinterest#enhanced-match',
				'wcm'     => '/document/pixel-manager-pro-for-woocommerce/pmw-plugin-configuration/pinterest/#pinterest-enhanced-match',
			],
			'pinterest_advanced_matching'                            => [
				'default' => '/docs/wpm/plugin-configuration/pinterest#advanced-matching',
				'wcm'     => '/document/pixel-manager-pro-for-woocommerce/pmw-plugin-configuration/pinterest/#section-7',
			],
			'outbrain_advertiser_id'                                 => [
				'default' => '/docs/wpm/plugin-configuration/outbrain',
				'wcm'     => '/document/pixel-manager-pro-for-woocommerce/pmw-plugin-configuration/outbrain/',
			],
			'taboola_account_id'                                     => [
				'default' => '/docs/wpm/plugin-configuration/taboola',
				'wcm'     => '/document/pixel-manager-pro-for-woocommerce/pmw-plugin-configuration/taboola/',
			],
			'adroll_advertiser_id'                                   => [
				'default' => '/docs/wpm/plugin-configuration/adroll#advertiser-id-and-pixel-id',
				'wcm'     => '/document/pixel-manager-pro-for-woocommerce/pmw-plugin-configuration/adroll/',
			],
			'adroll_pixel_id'                                        => [
				'default' => '/docs/wpm/plugin-configuration/adroll#advertiser-id-and-pixel-id',
				'wcm'     => '/document/pixel-manager-pro-for-woocommerce/pmw-plugin-configuration/adroll/',
			],
			'linkedin_partner_id'                                    => [
				'default' => '/docs/wpm/plugin-configuration/linkedin#partner-id',
				'wcm'     => '/document/pixel-manager-pro-for-woocommerce/pmw-plugin-configuration/linkedin/',
			],
			'google_tcf_support'                                     => [
				'default' => '/docs/wpm/consent-management/google#google-tcf-support',
				'wcm'     => '/document/pixel-manager-pro-for-woocommerce/consent-management/google-consent-mode/#section-6',
			],
			'logger_activation'                                      => [
				'default' => '/docs/wpm/developers/logs#logger-activation',
				'wcm'     => '/document/pixel-manager-pro-for-woocommerce/developers-documentation/logs-3/#section-1',
			],
			'log_level'                                              => [
				'default' => '/docs/wpm/developers/logs#log-levels',
				'wcm'     => '/document/pixel-manager-pro-for-woocommerce/developers-documentation/logs-3/#section-2',
			],
			'log_http_requests'                                      => [
				'default' => '/docs/wpm/developers/logs#log-http-requests',
				'wcm'     => '/document/pixel-manager-pro-for-woocommerce/developers-documentation/logs-3/#section-3',
			],
			'log_files'                                              => [
				'default' => '/docs/wpm/developers/logs#accessing-log-files',
				'wcm'     => '/document/pixel-manager-pro-for-woocommerce/developers-documentation/logs-3/',
			],
			'ltv_order_calculation'                                  => [
				'default' => '/docs/wpm/plugin-configuration/shop-settings#active-lifetime-value-calculation',
				'wcm'     => '/document/pixel-manager-pro-for-woocommerce/pmw-plugin-configuration/shop-settings-2/#section-9',
			],
			'ltv_recalculation'                                      => [
				'default' => '/docs/wpm/plugin-configuration/shop-settings#lifetime-value-recalculation',
				'wcm'     => '/document/pixel-manager-pro-for-woocommerce/pmw-plugin-configuration/shop-settings-2/#section-11',
			],
			'order_modal_ltv'                                        => [
				'default' => '/docs/wpm/shop#lifetime-value',
				'wcm'     => '/document/pixel-manager-pro-for-woocommerce/shop/#section-6',
			],
			'facebook_microdata_deprecation'                         => [
				'default' => '/blog/facebook-microdata-for-catalog-deprecation-notice',
				'wcm'     => '/blog/facebook-microdata-for-catalog-deprecation-notice',
			],
			'order_extra_details'                         => [
				'default' => '/docs/wpm/plugin-configuration/shop-settings#extra-order-data-output',
				'wcm'     => '/document/pixel-manager-pro-for-woocommerce/pmw-plugin-configuration/shop-settings-2/#section-12',
			],
			'microsoft_ads_consent_mode'                         => [
				'default' => '/docs/wpm/consent-management/microsoft#microsoft-ads-consent-mode',
				'wcm'     => '/document/pixel-manager-pro-for-woocommerce/consent-management/microsoft-ads-consent-settings/',
			],
			'facebook_domain_verification_id' 						=> [
				'default' => '/docs/wpm/plugin-configuration/meta#domain-verification',
				'wcm'     => '',
			],
			'google_tag_gateway_measurement_path' 				=> [
				'default' => '/docs/wpm/plugin-configuration/google#google-gtag-gateway-for-advertisers',
				'wcm'     => '',
			],
			'google_tag_id' 									   => [
				'default' => '/docs/wpm/plugin-configuration/google#google-gtag-gateway-for-advertisers',
				'wcm'     => '',
			],
		];

		if (array_key_exists($key, $documentation_links)) {
			return $documentation_links[$key][$doc_host_url];
		} else {
			return $documentation_links['default'][$doc_host_url];
		}
	}
}
