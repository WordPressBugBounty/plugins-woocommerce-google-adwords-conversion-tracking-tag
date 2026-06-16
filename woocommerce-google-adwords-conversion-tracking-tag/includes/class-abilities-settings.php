<?php
/**
 * Settings catalog and execution logic for the PMW Abilities API integration.
 *
 * Provides a machine-readable catalog of all agent-operable plugin settings
 * (dot-notation path, type, description, default, tier, secret flag) and the
 * read, write, and setup-status logic built on top of it. AI agents discover
 * the catalog through the pmw/get-settings-schema ability and operate on it
 * through pmw/get-settings, pmw/update-settings, pmw/configure-pixel and
 * pmw/get-setup-status.
 *
 * Writes are sparse patches: only the submitted paths are validated and merged
 * into the full options tree, everything else is preserved. Each save runs
 * through the same single-field validation the Nova admin UI uses and creates
 * an automatic options backup.
 *
 * @package SweetCode\Pixel_Manager
 * @since 1.59.0
 */

namespace SweetCode\Pixel_Manager;

use SweetCode\Pixel_Manager\Admin\Consent_Mode_Regions;
use SweetCode\Pixel_Manager\Admin\Environment;
use SweetCode\Pixel_Manager\Admin\Validations;
use SweetCode\Pixel_Manager\Pixels\Core\Pixel_Registry;

defined('ABSPATH') || exit; // Exit if accessed directly

class Abilities_Settings {

	/**
	 * Cached flat path index, built once per request.
	 *
	 * @var array|null
	 */
	private static $path_index;

	/**
	 * The settings catalog: every agent-operable setting grouped by destination.
	 *
	 * Group fields:
	 * - label:    Human-readable group name.
	 * - category: marketing | statistics | optimization | plugin
	 * - is_pixel: Whether the group is a tracking destination (configurable
	 *             through pmw/configure-pixel) or a plugin-level settings group.
	 * - settings: Map of short setting key to setting definition.
	 *
	 * Setting fields:
	 * - path:        Dot-notation path into the wgact_plugin_options tree.
	 * - type:        string | boolean | integer | number | array
	 * - label:       Human-readable setting name.
	 * - description: Agent-facing description, including where to find the value.
	 * - required:    The setting is required for the pixel to become active.
	 * - advanced:    Optional feature on top of the base setup.
	 * - benefit:     Why a shop would enable the advanced feature.
	 * - secret:      Value is never returned in reads (only a set/not-set flag).
	 * - enum:        Allowed values, when the setting is an enumeration.
	 *
	 * The pro flag is not stored here. It is derived from
	 * Validations::premium_only_option_paths() so there is a single source of
	 * truth for which settings are premium-only.
	 *
	 * @return array
	 */
	public static function get_catalog() {

		return [
			'google_ads'    => [
				'label'    => 'Google Ads',
				'category' => 'marketing',
				'is_pixel' => true,
				'settings' => [
					'conversion_id'                          => [
						'path'        => 'google.ads.conversion_id',
						'type'        => 'string',
						'label'       => 'Conversion ID',
						'description' => 'Google Ads conversion ID, the 8 to 11 digits that follow "AW-" in the Google Ads tag. Found in Google Ads under Goals > Conversions > Summary > conversion action > Tag setup. Pasting the full "AW-123456789" or "AW-123456789/AbC..." value is allowed, prefix and label are stripped automatically.',
						'required'    => true,
					],
					'conversion_label'                       => [
						'path'        => 'google.ads.conversion_label',
						'type'        => 'string',
						'label'       => 'Conversion label',
						'description' => 'Google Ads conversion label of the purchase conversion action, the part after the slash in a tag like "AW-123456789/AbC-D_efG". Pasting the full "id/label" value is allowed, the ID part is stripped automatically.',
						'required'    => true,
					],
					'aw_merchant_id'                         => [
						'path'        => 'google.ads.aw_merchant_id',
						'type'        => 'string',
						'label'       => 'Merchant Center ID',
						'description' => 'Google Merchant Center ID, 6 to 12 digits.',
						'advanced'    => true,
						'benefit'     => 'Enables conversion with cart data: Google Ads reports which products were sold with each conversion, including revenue per product.',
					],
					'product_identifier'                     => [
						'path'        => 'google.ads.product_identifier',
						'type'        => 'integer',
						'label'       => 'Product identifier',
						'description' => 'Which product identifier is sent with dynamic remarketing and cart data events. Must match the product IDs in the connected Merchant Center feed. 0 = post ID (default), 2 = SKU, 1 = post ID with woocommerce_gpf_ prefix (WooCommerce Google Product Feed plugin), 3 = post ID with gla_ prefix (Google for WooCommerce plugin).',
						'enum'        => [ 0, 1, 2, 3 ],
						'advanced'    => true,
						'benefit'     => 'Aligns the tracked product IDs with the Merchant Center feed so dynamic remarketing and cart data match products correctly.',
					],
					'google_business_vertical'               => [
						'path'        => 'google.ads.google_business_vertical',
						'type'        => 'integer',
						'label'       => 'Business vertical',
						'description' => 'Dynamic remarketing business vertical. 0 = Retail (default), 1 = Education, 3 = Hotels and rentals, 4 = Jobs, 5 = Local deals, 6 = Real estate, 8 = Custom.',
						'enum'        => [ 0, 1, 3, 4, 5, 6, 8 ],
						'advanced'    => true,
						'benefit'     => 'Matches the dynamic remarketing events to the correct Google Ads business vertical.',
					],
					'enhanced_conversions'                   => [
						'path'        => 'google.ads.enhanced_conversions',
						'type'        => 'boolean',
						'label'       => 'Enhanced conversions',
						'description' => 'Sends hashed first-party customer data (email, name, address) with conversions.',
						'advanced'    => true,
						'benefit'     => 'Improves conversion measurement accuracy, especially where cookies are restricted. Enhanced conversions must also be enabled for the conversion action in Google Ads.',
					],
					'phone_conversion_number'                => [
						'path'        => 'google.ads.phone_conversion_number',
						'type'        => 'string',
						'label'       => 'Phone conversion number',
						'description' => 'The business phone number shown on the website, used for Google Ads website call conversions.',
						'advanced'    => true,
						'benefit'     => 'Tracks phone call conversions from the website by swapping the displayed number with a Google forwarding number.',
					],
					'phone_conversion_label'                 => [
						'path'        => 'google.ads.phone_conversion_label',
						'type'        => 'string',
						'label'       => 'Phone conversion label',
						'description' => 'Conversion label of the Google Ads call conversion action.',
						'advanced'    => true,
						'benefit'     => 'Required together with the phone conversion number to report call conversions.',
					],
					'conversion_adjustments.conversion_name' => [
						'path'        => 'google.ads.conversion_adjustments.conversion_name',
						'type'        => 'string',
						'label'       => 'Conversion adjustments conversion name',
						'description' => 'The exact name of the Google Ads conversion action used for conversion adjustments (refunds and restatements).',
						'advanced'    => true,
						'benefit'     => 'Enables the conversion adjustments export so refunds reduce the reported conversion value in Google Ads.',
					],
				],
			],
			'ga4'           => [
				'label'    => 'Google Analytics 4',
				'category' => 'statistics',
				'is_pixel' => true,
				'settings' => [
					'measurement_id'           => [
						'path'        => 'google.analytics.ga4.measurement_id',
						'type'        => 'string',
						'label'       => 'Measurement ID',
						'description' => 'GA4 measurement ID in the format "G-" followed by letters and digits. Found in Google Analytics under Admin > Data streams > web stream.',
						'required'    => true,
					],
					'api_secret'               => [
						'path'        => 'google.analytics.ga4.api_secret',
						'type'        => 'string',
						'label'       => 'Measurement Protocol API secret',
						'description' => 'GA4 Measurement Protocol API secret. Created in Google Analytics under Admin > Data streams > web stream > Measurement Protocol API secrets.',
						'secret'      => true,
						'advanced'    => true,
						'benefit'     => 'Enables server-side GA4 tracking via the Measurement Protocol, which captures purchases that browser tracking misses.',
					],
					'data_api.property_id'     => [
						'path'        => 'google.analytics.ga4.data_api.property_id',
						'type'        => 'string',
						'label'       => 'Data API property ID',
						'description' => 'Numeric GA4 property ID. Found in Google Analytics under Admin > Property settings.',
						'advanced'    => true,
						'benefit'     => 'Allows the plugin to pull GA4 report data through the Data API.',
					],
					'page_load_time_tracking'  => [
						'path'        => 'google.analytics.ga4.page_load_time_tracking',
						'type'        => 'boolean',
						'label'       => 'Page load time tracking',
						'description' => 'Reports page load timing events to GA4.',
						'advanced'    => true,
						'benefit'     => 'Adds page speed insight to GA4 reports.',
					],
					'link_attribution'         => [
						'path'        => 'google.analytics.link_attribution',
						'type'        => 'boolean',
						'label'       => 'Enhanced link attribution',
						'description' => 'Enables Google Analytics enhanced link attribution.',
						'advanced'    => true,
						'benefit'     => 'Distinguishes between multiple links to the same destination on a page in GA reports.',
					],
					'user_id'                  => [
						'path'        => 'google.user_id',
						'type'        => 'boolean',
						'label'       => 'User ID tracking',
						'description' => 'Sends the logged-in WordPress user ID to Google Analytics.',
						'advanced'    => true,
						'benefit'     => 'Enables cross-device and cross-session tracking of logged-in customers in GA4.',
					],
				],
			],
			'facebook'      => [
				'label'    => 'Meta (Facebook)',
				'category' => 'marketing',
				'is_pixel' => true,
				'settings' => [
					'pixel_id'               => [
						'path'        => 'facebook.pixel_id',
						'type'        => 'string',
						'label'       => 'Pixel ID',
						'description' => 'Meta (Facebook) pixel ID, 12 to 22 digits. Found in Meta Events Manager under Data sources.',
						'required'    => true,
					],
					'domain_verification_id' => [
						'path'        => 'facebook.domain_verification_id',
						'type'        => 'string',
						'label'       => 'Domain verification ID',
						'description' => 'Meta domain verification ID. Found in Meta Business Manager under Brand safety > Domains. Pasting the full meta tag is allowed, the ID is extracted automatically.',
						'advanced'    => true,
						'benefit'     => 'Verifies the shop domain with Meta, which is required for some ad features.',
					],
					'capi.token'             => [
						'path'        => 'facebook.capi.token',
						'type'        => 'string',
						'label'       => 'Conversions API token',
						'description' => 'Meta Conversions API access token. Generated in Meta Events Manager under the pixel > Settings > Conversions API.',
						'secret'      => true,
						'advanced'    => true,
						'benefit'     => 'Enables server-side tracking through the Meta Conversions API, which captures conversions that browsers block or miss.',
					],
					'capi.test_event_code'   => [
						'path'        => 'facebook.capi.test_event_code',
						'type'        => 'string',
						'label'       => 'CAPI test event code',
						'description' => 'Meta Conversions API test event code. Only used while testing server-side events; remove it for production.',
						'advanced'    => true,
						'benefit'     => 'Routes server-side events into the Meta test events view for verification.',
					],
					'capi.user_transparency.send_additional_client_identifiers' => [
						'path'        => 'facebook.capi.user_transparency.send_additional_client_identifiers',
						'type'        => 'boolean',
						'label'       => 'Send additional client identifiers',
						'description' => 'Sends the client IP address and user agent with Conversions API events.',
						'advanced'    => true,
						'benefit'     => 'Improves Meta event match quality at the cost of sending more client data.',
					],
				],
			],
			'bing'          => [
				'label'    => 'Microsoft Advertising (Bing)',
				'category' => 'marketing',
				'is_pixel' => true,
				'settings' => [
					'uet_tag_id'            => [
						'path'        => 'bing.uet_tag_id',
						'type'        => 'string',
						'label'       => 'UET tag ID',
						'description' => 'Microsoft Advertising UET tag ID, 7 to 9 digits. Found in Microsoft Advertising under Tools > UET tag.',
						'required'    => true,
					],
					'enhanced_conversions'  => [
						'path'        => 'bing.enhanced_conversions',
						'type'        => 'boolean',
						'label'       => 'Enhanced conversions',
						'description' => 'Sends hashed customer data with Microsoft Advertising conversions.',
						'advanced'    => true,
						'benefit'     => 'Improves Microsoft Advertising conversion measurement accuracy.',
					],
					'consent_mode.is_active' => [
						'path'        => 'bing.consent_mode.is_active',
						'type'        => 'boolean',
						'label'       => 'Microsoft consent mode',
						'description' => 'Enables Microsoft consent mode for the UET tag.',
						'advanced'    => true,
						'benefit'     => 'Adjusts Microsoft tracking based on visitor consent signals.',
					],
				],
			],
			'tiktok'        => [
				'label'    => 'TikTok',
				'category' => 'marketing',
				'is_pixel' => true,
				'settings' => [
					'pixel_id'             => [
						'path'        => 'tiktok.pixel_id',
						'type'        => 'string',
						'label'       => 'Pixel ID',
						'description' => 'TikTok pixel ID. Found in TikTok Ads Manager under Tools > Events > Web events.',
						'required'    => true,
					],
					'advanced_matching'    => [
						'path'        => 'tiktok.advanced_matching',
						'type'        => 'boolean',
						'label'       => 'Advanced matching',
						'description' => 'Sends hashed customer data with TikTok events.',
						'advanced'    => true,
						'benefit'     => 'Improves TikTok event match rates and attribution.',
					],
					'eapi.token'           => [
						'path'        => 'tiktok.eapi.token',
						'type'        => 'string',
						'label'       => 'Events API access token',
						'description' => 'TikTok Events API access token. Generated in TikTok Ads Manager under the pixel settings.',
						'secret'      => true,
						'advanced'    => true,
						'benefit'     => 'Enables server-side tracking through the TikTok Events API.',
					],
					'eapi.test_event_code' => [
						'path'        => 'tiktok.eapi.test_event_code',
						'type'        => 'string',
						'label'       => 'Events API test event code',
						'description' => 'TikTok Events API test event code. Only used while testing server-side events.',
						'advanced'    => true,
						'benefit'     => 'Routes server-side events into the TikTok test events view for verification.',
					],
				],
			],
			'pinterest'     => [
				'label'    => 'Pinterest',
				'category' => 'marketing',
				'is_pixel' => true,
				'settings' => [
					'pixel_id'          => [
						'path'        => 'pinterest.pixel_id',
						'type'        => 'string',
						'label'       => 'Pixel ID',
						'description' => 'Pinterest tag ID, 13 digits. Found in Pinterest Ads under Conversions.',
						'required'    => true,
					],
					'enhanced_match'    => [
						'path'        => 'pinterest.enhanced_match',
						'type'        => 'boolean',
						'label'       => 'Enhanced match',
						'description' => 'Sends a hashed email address with Pinterest browser events.',
						'advanced'    => true,
						'benefit'     => 'Improves Pinterest conversion attribution.',
					],
					'advanced_matching' => [
						'path'        => 'pinterest.advanced_matching',
						'type'        => 'boolean',
						'label'       => 'Advanced matching',
						'description' => 'Sends additional hashed customer data with Pinterest events.',
						'advanced'    => true,
						'benefit'     => 'Improves Pinterest event match rates.',
					],
					'ad_account_id'     => [
						'path'        => 'pinterest.ad_account_id',
						'type'        => 'string',
						'label'       => 'Ad account ID',
						'description' => 'Pinterest ad account ID. Found in Pinterest Ads under Business settings.',
						'advanced'    => true,
						'benefit'     => 'Required for the Pinterest API for Conversions.',
					],
					'apic.token'        => [
						'path'        => 'pinterest.apic.token',
						'type'        => 'string',
						'label'       => 'API for Conversions token',
						'description' => 'Pinterest API for Conversions access token. Generated in Pinterest Ads under Conversions.',
						'secret'      => true,
						'advanced'    => true,
						'benefit'     => 'Enables server-side tracking through the Pinterest API for Conversions.',
					],
				],
			],
			'snapchat'      => [
				'label'    => 'Snapchat',
				'category' => 'marketing',
				'is_pixel' => true,
				'settings' => [
					'pixel_id'          => [
						'path'        => 'snapchat.pixel_id',
						'type'        => 'string',
						'label'       => 'Pixel ID',
						'description' => 'Snapchat pixel ID. Found in Snapchat Ads Manager under Events Manager.',
						'required'    => true,
					],
					'advanced_matching' => [
						'path'        => 'snapchat.advanced_matching',
						'type'        => 'boolean',
						'label'       => 'Advanced matching',
						'description' => 'Sends hashed customer data with Snapchat events.',
						'advanced'    => true,
						'benefit'     => 'Improves Snapchat event match rates.',
					],
					'capi.token'        => [
						'path'        => 'snapchat.capi.token',
						'type'        => 'string',
						'label'       => 'Conversions API token',
						'description' => 'Snapchat Conversions API access token. Generated in Snapchat Ads Manager under Events Manager.',
						'secret'      => true,
						'advanced'    => true,
						'benefit'     => 'Enables server-side tracking through the Snapchat Conversions API.',
					],
				],
			],
			'twitter'       => [
				'label'    => 'X (Twitter)',
				'category' => 'marketing',
				'is_pixel' => true,
				'settings' => [
					'pixel_id'                    => [
						'path'        => 'twitter.pixel_id',
						'type'        => 'string',
						'label'       => 'Pixel ID',
						'description' => 'X (Twitter) pixel ID, 5 to 7 lowercase letters and digits. Found in X Ads under Tools > Events Manager.',
						'required'    => true,
					],
					'event_ids.purchase'          => [
						'path'        => 'twitter.event_ids.purchase',
						'type'        => 'string',
						'label'       => 'Purchase event ID',
						'description' => 'X event ID for the purchase event, created in X Events Manager.',
						'advanced'    => true,
						'benefit'     => 'Reports purchase conversions to X. Without event IDs only the base pixel loads.',
					],
					'event_ids.add_to_cart'       => [
						'path'        => 'twitter.event_ids.add_to_cart',
						'type'        => 'string',
						'label'       => 'Add to cart event ID',
						'description' => 'X event ID for the add to cart event.',
						'advanced'    => true,
						'benefit'     => 'Reports add to cart events to X.',
					],
					'event_ids.view_content'      => [
						'path'        => 'twitter.event_ids.view_content',
						'type'        => 'string',
						'label'       => 'View content event ID',
						'description' => 'X event ID for the view content event.',
						'advanced'    => true,
						'benefit'     => 'Reports product views to X.',
					],
					'event_ids.search'            => [
						'path'        => 'twitter.event_ids.search',
						'type'        => 'string',
						'label'       => 'Search event ID',
						'description' => 'X event ID for the search event.',
						'advanced'    => true,
						'benefit'     => 'Reports searches to X.',
					],
					'event_ids.add_to_wishlist'   => [
						'path'        => 'twitter.event_ids.add_to_wishlist',
						'type'        => 'string',
						'label'       => 'Add to wishlist event ID',
						'description' => 'X event ID for the add to wishlist event.',
						'advanced'    => true,
						'benefit'     => 'Reports wishlist events to X.',
					],
					'event_ids.initiate_checkout' => [
						'path'        => 'twitter.event_ids.initiate_checkout',
						'type'        => 'string',
						'label'       => 'Initiate checkout event ID',
						'description' => 'X event ID for the initiate checkout event.',
						'advanced'    => true,
						'benefit'     => 'Reports checkout starts to X.',
					],
					'event_ids.add_payment_info'  => [
						'path'        => 'twitter.event_ids.add_payment_info',
						'type'        => 'string',
						'label'       => 'Add payment info event ID',
						'description' => 'X event ID for the add payment info event.',
						'advanced'    => true,
						'benefit'     => 'Reports payment info events to X.',
					],
				],
			],
			'linkedin'      => [
				'label'    => 'LinkedIn',
				'category' => 'marketing',
				'is_pixel' => true,
				'settings' => [
					'partner_id'                  => [
						'path'        => 'pixels.linkedin.partner_id',
						'type'        => 'string',
						'label'       => 'Partner ID',
						'description' => 'LinkedIn partner (Insight Tag) ID. Found in LinkedIn Campaign Manager under Analyze > Insight Tag.',
						'required'    => true,
					],
					'conversion_ids.view_content' => [
						'path'        => 'pixels.linkedin.conversion_ids.view_content',
						'type'        => 'string',
						'label'       => 'View content conversion ID',
						'description' => 'LinkedIn conversion ID for the view content event.',
						'advanced'    => true,
						'benefit'     => 'Reports product views as LinkedIn conversions.',
					],
					'conversion_ids.add_to_cart'  => [
						'path'        => 'pixels.linkedin.conversion_ids.add_to_cart',
						'type'        => 'string',
						'label'       => 'Add to cart conversion ID',
						'description' => 'LinkedIn conversion ID for the add to cart event.',
						'advanced'    => true,
						'benefit'     => 'Reports add to cart events as LinkedIn conversions.',
					],
					'conversion_ids.purchase'     => [
						'path'        => 'pixels.linkedin.conversion_ids.purchase',
						'type'        => 'string',
						'label'       => 'Purchase conversion ID',
						'description' => 'LinkedIn conversion ID for the purchase event.',
						'advanced'    => true,
						'benefit'     => 'Reports purchases as LinkedIn conversions.',
					],
				],
			],
			'hotjar'        => [
				'label'    => 'Hotjar',
				'category' => 'statistics',
				'is_pixel' => true,
				'settings' => [
					'site_id' => [
						'path'        => 'hotjar.site_id',
						'type'        => 'string',
						'label'       => 'Site ID',
						'description' => 'Hotjar site ID, 6 to 9 digits. Found in Hotjar under Sites & Organizations.',
						'required'    => true,
					],
				],
			],
			'crazyegg'      => [
				'label'    => 'Crazy Egg',
				'category' => 'statistics',
				'is_pixel' => true,
				'settings' => [
					'account_number' => [
						'path'        => 'crazyegg.account_number',
						'type'        => 'string',
						'label'       => 'Account number',
						'description' => 'Crazy Egg account number, exactly 8 digits. Pasting the full Crazy Egg script URL is allowed, the number is extracted automatically.',
						'required'    => true,
					],
				],
			],
			'adroll'        => [
				'label'    => 'AdRoll',
				'category' => 'marketing',
				'is_pixel' => true,
				'settings' => [
					'advertiser_id' => [
						'path'        => 'pixels.adroll.advertiser_id',
						'type'        => 'string',
						'label'       => 'Advertiser ID',
						'description' => 'AdRoll advertiser ID, 22 uppercase letters and digits. Found in the AdRoll pixel snippet.',
						'required'    => true,
					],
					'pixel_id'      => [
						'path'        => 'pixels.adroll.pixel_id',
						'type'        => 'string',
						'label'       => 'Pixel ID',
						'description' => 'AdRoll pixel ID, 22 uppercase letters and digits. Found in the AdRoll pixel snippet.',
						'required'    => true,
					],
				],
			],
			'outbrain'      => [
				'label'    => 'Outbrain',
				'category' => 'marketing',
				'is_pixel' => true,
				'settings' => [
					'advertiser_id' => [
						'path'        => 'pixels.outbrain.advertiser_id',
						'type'        => 'string',
						'label'       => 'Advertiser ID',
						'description' => 'Outbrain advertiser ID. Found in the Outbrain dashboard pixel setup.',
						'required'    => true,
					],
				],
			],
			'reddit'        => [
				'label'    => 'Reddit',
				'category' => 'marketing',
				'is_pixel' => true,
				'settings' => [
					'advertiser_id'        => [
						'path'        => 'pixels.reddit.advertiser_id',
						'type'        => 'string',
						'label'       => 'Advertiser ID',
						'description' => 'Reddit advertiser ID in the format "a2_" or "t2_" followed by letters and digits. Found in Reddit Ads under Events Manager.',
						'required'    => true,
					],
					'advanced_matching'    => [
						'path'        => 'pixels.reddit.advanced_matching',
						'type'        => 'boolean',
						'label'       => 'Advanced matching',
						'description' => 'Sends hashed customer data with Reddit events.',
						'advanced'    => true,
						'benefit'     => 'Improves Reddit event match rates.',
					],
					'capi.token'           => [
						'path'        => 'pixels.reddit.capi.token',
						'type'        => 'string',
						'label'       => 'Conversions API token',
						'description' => 'Reddit Conversions API access token (a JWT). Generated in Reddit Ads under Events Manager.',
						'secret'      => true,
						'advanced'    => true,
						'benefit'     => 'Enables server-side tracking through the Reddit Conversions API.',
					],
					'capi.test_event_code' => [
						'path'        => 'pixels.reddit.capi.test_event_code',
						'type'        => 'string',
						'label'       => 'CAPI test event code',
						'description' => 'Reddit Conversions API test event code. Only used while testing server-side events.',
						'advanced'    => true,
						'benefit'     => 'Routes server-side events into the Reddit test events view for verification.',
					],
				],
			],
			'taboola'       => [
				'label'    => 'Taboola',
				'category' => 'marketing',
				'is_pixel' => true,
				'settings' => [
					'account_id' => [
						'path'        => 'pixels.taboola.account_id',
						'type'        => 'string',
						'label'       => 'Account ID',
						'description' => 'Taboola account ID. Found in Taboola Ads under the pixel setup.',
						'required'    => true,
					],
				],
			],
			'vwo'           => [
				'label'    => 'VWO',
				'category' => 'optimization',
				'is_pixel' => true,
				'settings' => [
					'account_id' => [
						'path'        => 'pixels.vwo.account_id',
						'type'        => 'string',
						'label'       => 'Account ID',
						'description' => 'VWO account ID, 4 to 10 digits. Found in the VWO dashboard settings.',
						'required'    => true,
					],
				],
			],
			'optimizely'    => [
				'label'    => 'Optimizely',
				'category' => 'optimization',
				'is_pixel' => true,
				'settings' => [
					'project_id' => [
						'path'        => 'pixels.optimizely.project_id',
						'type'        => 'string',
						'label'       => 'Project ID',
						'description' => 'Optimizely project ID. Found in the Optimizely project settings.',
						'required'    => true,
					],
				],
			],
			'ab_tasty'      => [
				'label'    => 'AB Tasty',
				'category' => 'optimization',
				'is_pixel' => true,
				'settings' => [
					'account_id' => [
						'path'        => 'pixels.ab_tasty.account_id',
						'type'        => 'string',
						'label'       => 'Account ID',
						'description' => 'AB Tasty account ID. Found in the AB Tasty tag snippet.',
						'required'    => true,
					],
				],
			],
			'contentsquare' => [
				'label'    => 'Contentsquare',
				'category' => 'statistics',
				'is_pixel' => true,
				'settings' => [
					'tag_id' => [
						'path'        => 'pixels.contentsquare.tag_id',
						'type'        => 'string',
						'label'       => 'Tag ID',
						'description' => 'Contentsquare tag ID. Found in the Contentsquare tag snippet.',
						'required'    => true,
					],
				],
			],
			'consent'       => [
				'label'    => 'Consent management',
				'category' => 'plugin',
				'is_pixel' => false,
				'settings' => [
					'google_consent_mode'         => [
						'path'        => 'google.consent_mode.active',
						'type'        => 'boolean',
						'label'       => 'Google consent mode',
						'description' => 'Enables Google consent mode v2 for all Google tags.',
					],
					'google_consent_mode_regions' => [
						'path'        => 'google.consent_mode.regions',
						'type'        => 'array',
						'label'       => 'Google consent mode regions',
						'description' => 'List of ISO 3166-1 alpha-2 region codes (e.g. ["DE", "FR"]) where Google consent mode applies. An empty list applies it everywhere.',
					],
					'tcf_support'                 => [
						'path'        => 'google.tcf_support',
						'type'        => 'boolean',
						'label'       => 'TCF support',
						'description' => 'Enables IAB Transparency and Consent Framework (TCF) support for Google tags.',
					],
					'explicit_consent'            => [
						'path'        => 'shop.cookie_consent_mgmt.explicit_consent',
						'type'        => 'boolean',
						'label'       => 'Explicit consent mode',
						'description' => 'When enabled, pixels wait until the visitor gives consent through a compatible consent management platform before tracking.',
					],
				],
			],
			'shop'          => [
				'label'    => 'Shop behavior',
				'category' => 'plugin',
				'is_pixel' => false,
				'settings' => [
					'order_total_logic'             => [
						'path'        => 'shop.order_total_logic',
						'type'        => 'string',
						'label'       => 'Order total logic',
						'description' => 'Which order value is reported to marketing pixels. "0" = order subtotal (default: excludes shipping and taxes, subtracts discounts, fees and refunds), "1" = order total (includes shipping and taxes, subtracts refunds), "2" = order profit margin.',
						'enum'        => [ '0', '1', '2' ],
					],
					'order_deduplication'           => [
						'path'        => 'shop.order_deduplication',
						'type'        => 'boolean',
						'label'       => 'Order deduplication',
						'description' => 'Prevents conversions from being reported more than once when the purchase confirmation page is reloaded. Should stay enabled except for short troubleshooting sessions.',
					],
					'subscription_value_multiplier' => [
						'path'        => 'shop.subscription_value_multiplier',
						'type'        => 'number',
						'label'       => 'Subscription value multiplier',
						'description' => 'Multiplies the reported value of subscription sign-ups, useful to reflect expected lifetime value. Must be at least 1.0.',
					],
				],
			],
			'general'       => [
				'label'    => 'General',
				'category' => 'plugin',
				'is_pixel' => false,
				'settings' => [
					'variations_output'                  => [
						'path'        => 'general.variations_output',
						'type'        => 'boolean',
						'label'       => 'Variations output',
						'description' => 'Reports product variation IDs instead of the parent product ID where applicable.',
					],
					'lazy_load_pmw'                      => [
						'path'        => 'general.lazy_load_pmw',
						'type'        => 'boolean',
						'label'       => 'Lazy load tracking scripts',
						'description' => 'Loads the tracking scripts only after the first user interaction. Improves page speed scores but may slightly reduce measured traffic.',
					],
					'scroll_tracker_thresholds'          => [
						'path'        => 'general.scroll_tracker_thresholds',
						'type'        => 'array',
						'label'       => 'Scroll tracker thresholds',
						'description' => 'Scroll depth percentages to report as events, e.g. [25, 50, 75, 100]. An empty list disables the scroll tracker.',
					],
					'google_tag_gateway_measurement_path' => [
						'path'        => 'google.tag_gateway.measurement_path',
						'type'        => 'string',
						'label'       => 'Google tag gateway measurement path',
						'description' => 'First-party measurement path for the Google tag gateway, e.g. "/metrics". Leave empty to disable the gateway.',
					],
				],
			],
		];
	}

	/**
	 * Build a flat index of all catalog settings keyed by dot-notation path.
	 *
	 * Each entry contains the setting definition plus group, group_label,
	 * key (the short key within the group) and the derived pro flag.
	 *
	 * @return array<string, array>
	 */
	public static function get_path_index() {

		if (null !== self::$path_index) {
			return self::$path_index;
		}

		$premium_paths = array_flip(Validations::premium_only_option_paths());
		$defaults      = Options::get_default_options();
		$index         = [];

		foreach (self::get_catalog() as $group_key => $group) {
			foreach ($group['settings'] as $setting_key => $setting) {

				$setting['group']       = $group_key;
				$setting['group_label'] = $group['label'];
				$setting['key']         = $setting_key;
				$setting['pro']         = isset($premium_paths[$setting['path']]);
				$setting['default']     = self::get_value_by_path($defaults, $setting['path']);

				$index[$setting['path']] = $setting;
			}
		}

		self::$path_index = $index;

		return $index;
	}

	/**
	 * Whether write access through the Abilities API is enabled.
	 *
	 * Site owners can pin the abilities surface to read-only:
	 * add_filter('pmw_abilities_allow_write', '__return_false');
	 *
	 * @return bool
	 */
	public static function is_write_enabled() {

		/**
		 * Allow disabling all settings writes through the Abilities API.
		 *
		 * @since 1.59.0
		 */
		return (bool) apply_filters('pmw_abilities_allow_write', true);
	}

	/**
	 * Execute callback for the pmw/get-settings-schema ability.
	 *
	 * @return array
	 */
	public static function execute_get_settings_schema() {

		$premium_paths = array_flip(Validations::premium_only_option_paths());
		$validators    = Validations::get_field_validators();
		$defaults      = Options::get_default_options();
		$groups        = [];

		foreach (self::get_catalog() as $group_key => $group) {

			$settings = [];

			foreach ($group['settings'] as $setting_key => $setting) {

				$entry = [
					'key'         => $setting_key,
					'path'        => $setting['path'],
					'type'        => $setting['type'],
					'label'       => $setting['label'],
					'description' => $setting['description'],
					'default'     => self::get_value_by_path($defaults, $setting['path']),
					'required'    => !empty($setting['required']),
					'advanced'    => !empty($setting['advanced']),
					'pro'         => isset($premium_paths[$setting['path']]),
					'secret'      => !empty($setting['secret']),
				];

				if (!empty($setting['benefit'])) {
					$entry['benefit'] = $setting['benefit'];
				}

				if (!empty($setting['enum'])) {
					$entry['enum'] = $setting['enum'];
				}

				if (isset($validators[$setting['path']])) {
					$entry['format_hint'] = $validators[$setting['path']][1];
				}

				$settings[] = $entry;
			}

			$groups[] = [
				'key'      => $group_key,
				'label'    => $group['label'],
				'category' => $group['category'],
				'is_pixel' => $group['is_pixel'],
				'settings' => $settings,
			];
		}

		return [
			'groups'        => $groups,
			'tier'          => Helpers::is_pmw_pro_version_active() ? 'pro' : 'free',
			'write_enabled' => self::is_write_enabled(),
		];
	}

	/**
	 * Execute callback for the pmw/get-settings ability.
	 *
	 * Returns the current value of every catalog setting. Secret values are
	 * never returned, only whether they are set.
	 *
	 * @return array
	 */
	public static function execute_get_settings() {

		$options  = Options::get_options();
		$settings = [];

		foreach (self::get_path_index() as $path => $entry) {

			$value  = self::get_value_by_path($options, $path);
			$secret = !empty($entry['secret']);

			$settings[] = [
				'path'   => $path,
				'group'  => $entry['group'],
				'label'  => $entry['label'],
				'secret' => $secret,
				'is_set' => !self::is_empty_value($value),
				'value'  => $secret ? null : $value,
			];
		}

		return [
			'settings'      => $settings,
			'tier'          => Helpers::is_pmw_pro_version_active() ? 'pro' : 'free',
			'write_enabled' => self::is_write_enabled(),
		];
	}

	/**
	 * Execute callback for the pmw/update-settings ability.
	 *
	 * Applies a sparse patch of dot-notation paths to new values. Only the
	 * submitted paths are touched, the rest of the options tree is preserved.
	 * Saving creates an automatic options backup.
	 *
	 * @param array $input Ability input: [ 'settings' => [ path => value, ... ] ]
	 * @return array
	 */
	public static function execute_update_settings( $input ) {

		$settings = isset($input['settings']) && is_array($input['settings']) ? $input['settings'] : [];

		return self::apply_updates($settings);
	}

	/**
	 * Execute callback for the pmw/configure-pixel ability.
	 *
	 * Accepts a pixel key and a map of short setting keys (relative to the
	 * pixel, e.g. "conversion_id") to values. After saving, returns the
	 * pixel's new setup status including missing required settings and
	 * available advanced features, so an agent can guide the next step.
	 *
	 * @param array $input Ability input: [ 'pixel' => string, 'settings' => [ key => value, ... ] ]
	 * @return array
	 */
	public static function execute_configure_pixel( $input ) {

		$catalog   = self::get_catalog();
		$pixel_key = isset($input['pixel']) ? (string) $input['pixel'] : '';

		if (!isset($catalog[$pixel_key]) || empty($catalog[$pixel_key]['is_pixel'])) {
			return [
				'pixel'   => $pixel_key,
				'saved'   => false,
				'results' => [
					[
						'path'    => '',
						'status'  => 'invalid',
						'message' => 'Unknown pixel. Use pmw/get-settings-schema to list the available pixels.',
					],
				],
				'status'  => null,
			];
		}

		$group        = $catalog[$pixel_key];
		$settings_in  = isset($input['settings']) && is_array($input['settings']) ? $input['settings'] : [];
		$path_updates = [];
		$results      = [];

		foreach ($settings_in as $setting_key => $value) {
			if (isset($group['settings'][$setting_key])) {
				$path_updates[$group['settings'][$setting_key]['path']] = $value;
			} else {
				$results[] = [
					'path'    => (string) $setting_key,
					'status'  => 'unknown',
					'message' => 'Unknown setting key for this pixel. Use pmw/get-settings-schema to list the available settings.',
				];
			}
		}

		$update = self::apply_updates($path_updates);

		return [
			'pixel'   => $pixel_key,
			'saved'   => $update['saved'],
			'results' => array_merge($results, $update['results']),
			'status'  => self::get_group_status($pixel_key, $group),
		];
	}

	/**
	 * Execute callback for the pmw/get-setup-status ability.
	 *
	 * Returns a derived setup checklist: per pixel the configured/active state,
	 * the missing required settings, and the advanced features that are not
	 * enabled yet, plus prioritized recommendations for the next setup step.
	 *
	 * @return array
	 */
	public static function execute_get_setup_status() {

		$catalog = self::get_catalog();
		$is_pro  = Helpers::is_pmw_pro_version_active();

		$pixels          = [];
		$plugin_settings = [];

		foreach ($catalog as $group_key => $group) {

			if ($group['is_pixel']) {
				$pixels[] = self::get_group_status($group_key, $group);
				continue;
			}

			$options = Options::get_options();
			$values  = [];

			foreach ($group['settings'] as $setting_key => $setting) {
				$values[] = [
					'key'   => $setting_key,
					'path'  => $setting['path'],
					'label' => $setting['label'],
					'value' => self::get_value_by_path($options, $setting['path']),
				];
			}

			$plugin_settings[] = [
				'key'      => $group_key,
				'label'    => $group['label'],
				'settings' => $values,
			];
		}

		$configured_count = 0;
		$active_count     = 0;
		$has_marketing    = false;
		$has_statistics   = false;

		foreach ($pixels as $pixel) {

			if ($pixel['configured']) {
				$configured_count++;
			}

			if ($pixel['active']) {
				$active_count++;

				if ('marketing' === $pixel['category']) {
					$has_marketing = true;
				}

				if ('statistics' === $pixel['category']) {
					$has_statistics = true;
				}
			}
		}

		return [
			'pixels'          => $pixels,
			'plugin_settings' => $plugin_settings,
			'summary'         => [
				'woocommerce_active'    => Environment::is_woocommerce_active(),
				'pixels_configured'     => $configured_count,
				'pixels_active'         => $active_count,
				'has_marketing_pixel'   => $has_marketing,
				'has_statistics_pixel'  => $has_statistics,
				'tier'                  => $is_pro ? 'pro' : 'free',
				'write_enabled'         => self::is_write_enabled(),
			],
			'recommendations' => self::build_recommendations($pixels, $is_pro),
		];
	}

	/**
	 * Compute the setup status of a single catalog group.
	 *
	 * @param string $group_key The group key (e.g. "google_ads").
	 * @param array  $group     The group definition from the catalog.
	 * @return array
	 */
	private static function get_group_status( $group_key, $group ) {

		$options       = Options::get_options();
		$premium_paths = array_flip(Validations::premium_only_option_paths());
		$is_pro        = Helpers::is_pmw_pro_version_active();

		$missing_required   = [];
		$required_total     = 0;
		$required_set       = 0;
		$advanced_available = [];
		$advanced_enabled   = [];
		$group_is_pro       = true;

		foreach ($group['settings'] as $setting_key => $setting) {

			$value       = self::get_value_by_path($options, $setting['path']);
			$is_set      = !self::is_empty_value($value);
			$setting_pro = isset($premium_paths[$setting['path']]);

			if (!$setting_pro) {
				$group_is_pro = false;
			}

			if (!empty($setting['required'])) {
				$required_total++;

				if ($is_set) {
					$required_set++;
				} else {
					$missing_required[] = [
						'key'         => $setting_key,
						'path'        => $setting['path'],
						'label'       => $setting['label'],
						'description' => $setting['description'],
					];
				}
			}

			if (!empty($setting['advanced'])) {

				$entry = [
					'key'     => $setting_key,
					'path'    => $setting['path'],
					'label'   => $setting['label'],
					'benefit' => isset($setting['benefit']) ? $setting['benefit'] : '',
					'pro'     => $setting_pro,
				];

				// A boolean advanced feature counts as enabled when true,
				// everything else when it differs from its empty state.
				if ('boolean' === $setting['type'] ? (bool) $value : $is_set) {
					$advanced_enabled[] = $entry;
				} else {
					$advanced_available[] = $entry;
				}
			}
		}

		$configured = $required_total > 0 && $required_set === $required_total;

		// Prefer the pixel registry's own activity check when a descriptor is
		// registered (it knows about tier gating and special activation rules).
		$active      = $configured;
		$descriptors = Pixel_Registry::get_descriptors();

		if (isset($descriptors[$group_key])) {
			$active = (bool) $descriptors[$group_key]->is_active();
		}

		return [
			'pixel'                 => $group_key,
			'label'                 => $group['label'],
			'category'              => $group['category'],
			'pro'                   => $group_is_pro,
			'available_in_tier'     => !$group_is_pro || $is_pro,
			'configured'            => $configured,
			'partially_configured'  => $required_set > 0 && !$configured,
			'active'                => $active,
			'missing_required'      => $missing_required,
			'advanced_available'    => $advanced_available,
			'advanced_enabled'      => $advanced_enabled,
		];
	}

	/**
	 * Build the ordered recommendation list for the setup status.
	 *
	 * @param array $pixels Per-pixel status entries from get_group_status().
	 * @param bool  $is_pro Whether Pro features are available.
	 * @return array<string>
	 */
	private static function build_recommendations( $pixels, $is_pro ) {

		$recommendations = [];
		$by_key          = [];
		$has_marketing   = false;
		$has_statistics  = false;

		foreach ($pixels as $pixel) {
			$by_key[$pixel['pixel']] = $pixel;

			if ($pixel['active'] && 'marketing' === $pixel['category']) {
				$has_marketing = true;
			}

			if ($pixel['active'] && 'statistics' === $pixel['category']) {
				$has_statistics = true;
			}
		}

		if (!Environment::is_woocommerce_active()) {
			$recommendations[] = 'WooCommerce is not active. Activate WooCommerce before configuring tracking.';
		}

		// Unfinished setups come first, they are almost certainly mistakes.
		foreach ($pixels as $pixel) {
			if ($pixel['partially_configured']) {
				$labels = array_map(
					function ( $setting ) {
						return $setting['label'];
					},
					$pixel['missing_required']
				);

				$recommendations[] = sprintf(
					'%s is only partially configured. Missing: %s.',
					$pixel['label'],
					implode(', ', $labels)
				);
			}
		}

		if (!$has_marketing) {
			$recommendations[] = 'No marketing pixel is active yet. Start with the platform where the shop advertises, for example Google Ads (conversion ID and conversion label) or Meta (pixel ID).';
		}

		if (!$has_statistics) {
			$recommendations[] = 'No statistics pixel is active yet. Google Analytics 4 (measurement ID) provides shop analytics and complements the marketing pixels.';
		}

		// Suggest the highest-impact advanced features on active pixels.
		foreach ($pixels as $pixel) {

			if (!$pixel['active']) {
				continue;
			}

			foreach ($pixel['advanced_available'] as $advanced) {

				if (empty($advanced['benefit'])) {
					continue;
				}

				$suffix = $advanced['pro'] && !$is_pro ? ' (requires the Pro version)' : '';

				$recommendations[] = sprintf(
					'%s: consider enabling "%s"%s. %s',
					$pixel['label'],
					$advanced['label'],
					$suffix,
					$advanced['benefit']
				);
			}
		}

		return $recommendations;
	}

	/**
	 * Validate and apply a sparse patch of path => value updates.
	 *
	 * Every path must exist in the catalog (whitelist), pass its type and enum
	 * checks, and pass the same single-field validation the admin UI uses.
	 * Valid updates are merged into the full current options tree and saved
	 * once, with an automatic backup.
	 *
	 * @param array $path_values Map of dot-notation path to new value.
	 * @return array { saved: bool, updated_count: int, results: array }
	 */
	private static function apply_updates( $path_values ) {

		if (!self::is_write_enabled()) {
			return [
				'saved'         => false,
				'updated_count' => 0,
				'results'       => [
					[
						'path'    => '',
						'status'  => 'invalid',
						'message' => 'Settings writes through the Abilities API are disabled on this site (pmw_abilities_allow_write filter).',
					],
				],
			];
		}

		if (empty($path_values)) {
			return [
				'saved'         => false,
				'updated_count' => 0,
				'results'       => [
					[
						'path'    => '',
						'status'  => 'invalid',
						'message' => 'No settings provided.',
					],
				],
			];
		}

		$index   = self::get_path_index();
		$options = Options::get_options();
		$is_pro  = Helpers::is_pmw_pro_version_active();
		$results = [];
		$updates = [];

		foreach ($path_values as $path => $value) {

			$path = (string) $path;

			if (!isset($index[$path])) {
				$results[] = [
					'path'    => $path,
					'status'  => 'unknown',
					'message' => 'Unknown setting path. Use pmw/get-settings-schema to list all available settings.',
				];
				continue;
			}

			$entry      = $index[$path];
			$validation = self::validate_value($entry, $value);

			if (!$validation['valid']) {
				$results[] = [
					'path'    => $path,
					'status'  => 'invalid',
					'message' => $validation['message'],
				];
				continue;
			}

			$new_value     = $validation['value'];
			$current_value = self::get_value_by_path($options, $path);

			if ($current_value === $new_value) {
				$results[] = [
					'path'   => $path,
					'status' => 'unchanged',
					'value'  => !empty($entry['secret']) ? null : $new_value,
				];
				continue;
			}

			$result = [
				'path'   => $path,
				'status' => 'updated',
				'value'  => !empty($entry['secret']) ? null : $new_value,
			];

			if ($entry['pro'] && !$is_pro) {
				$result['note'] = 'Saved, but this setting only takes effect with an active Pro license.';
			}

			$results[]      = $result;
			$updates[$path] = $new_value;
		}

		if (empty($updates)) {
			return [
				'saved'         => false,
				'updated_count' => 0,
				'results'       => $results,
			];
		}

		foreach ($updates as $path => $value) {
			$options = self::set_value_by_path($options, $path, $value);
		}

		// Saves the full merged tree, creates an automatic backup and
		// invalidates the options cache.
		Options::save_options_with_timestamp($options);

		return [
			'saved'         => true,
			'updated_count' => count($updates),
			'results'       => $results,
		];
	}

	/**
	 * Validate and normalize a single value against its catalog entry.
	 *
	 * @param array $entry Catalog entry from the path index.
	 * @param mixed $value The submitted value.
	 * @return array { valid: bool, value: mixed, message?: string }
	 */
	private static function validate_value( $entry, $value ) {

		$type = $entry['type'];

		// Type coercion: agents send JSON, but be tolerant of stringified
		// booleans and numbers.
		if ('boolean' === $type) {

			if (is_string($value) && in_array(strtolower($value), [ 'true', 'false', '0', '1' ], true)) {
				$value = in_array(strtolower($value), [ 'true', '1' ], true);
			} elseif (is_int($value) && in_array($value, [ 0, 1 ], true)) {
				$value = (bool) $value;
			}

			if (!is_bool($value)) {
				return [ 'valid' => false, 'value' => $value, 'message' => 'Expected a boolean value.' ];
			}
		} elseif ('integer' === $type) {

			if (is_string($value) && ctype_digit($value)) {
				$value = (int) $value;
			}

			if (!is_int($value)) {
				return [ 'valid' => false, 'value' => $value, 'message' => 'Expected an integer value.' ];
			}
		} elseif ('number' === $type) {

			if (!is_numeric($value)) {
				return [ 'valid' => false, 'value' => $value, 'message' => 'Expected a numeric value.' ];
			}
		} elseif ('array' === $type) {

			if (!is_array($value)) {
				return [ 'valid' => false, 'value' => $value, 'message' => 'Expected an array value.' ];
			}

			$value = Helpers::generic_sanitization($value);
		} elseif ('string' === $type) {

			// Tolerate numeric input for string-typed IDs.
			if (is_int($value) || is_float($value)) {
				$value = (string) $value;
			}

			if (!is_string($value)) {
				return [ 'valid' => false, 'value' => $value, 'message' => 'Expected a string value.' ];
			}

			$value = sanitize_text_field($value);
		}

		if (!empty($entry['enum']) && !in_array($value, $entry['enum'], true)) {
			return [
				'valid'   => false,
				'value'   => $value,
				'message' => 'Value must be one of: ' . implode(', ', array_map('strval', $entry['enum'])) . '.',
			];
		}

		// Setting-specific validations that options_validate() covers outside
		// the single-field validator map.
		if ('shop.subscription_value_multiplier' === $entry['path'] && '' !== (string) $value) {
			if (!Validations::is_subscription_value_multiplier((string) $value)) {
				return [ 'valid' => false, 'value' => $value, 'message' => 'The subscription value multiplier must be a number of at least 1.00.' ];
			}
		}

		if ('general.scroll_tracker_thresholds' === $entry['path'] && !empty($value)) {

			$as_string = implode(',', array_map('strval', $value));

			if (!Validations::is_scroll_tracker_thresholds($as_string)) {
				return [ 'valid' => false, 'value' => $value, 'message' => 'Scroll tracker thresholds must be a list of percentages, e.g. [25, 50, 75, 100].' ];
			}

			$value = array_map('strval', $value);
		}

		if ('google.consent_mode.regions' === $entry['path'] && !empty($value)) {

			$valid_regions = array_keys(Consent_Mode_Regions::get_consent_mode_regions());

			foreach ($value as $region) {
				if (!is_string($region) || !in_array($region, $valid_regions, true)) {
					return [ 'valid' => false, 'value' => $value, 'message' => 'Consent mode regions must be a list of valid region codes, e.g. ["US", "DE", "EU"].' ];
				}
			}

			// Re-index so the value serializes as a JSON array, not an object.
			$value = array_values($value);
		}

		// Shared single-field pipeline: trim, path-specific preprocessing
		// (strip AW- prefixes, extract IDs from pasted snippets) and the
		// regex validation map. Non-strings pass through untouched.
		$single = Validations::validate_single_option($entry['path'], $value);

		if (!$single['valid']) {
			return [ 'valid' => false, 'value' => $single['value'], 'message' => $single['message'] ];
		}

		return [ 'valid' => true, 'value' => $single['value'] ];
	}

	/**
	 * Whether a value counts as "not set" for configuration purposes.
	 *
	 * @param mixed $value The value to check.
	 * @return bool
	 */
	private static function is_empty_value( $value ) {
		return null === $value || '' === $value || [] === $value;
	}

	/**
	 * Get a nested array value using a dot-notation path.
	 *
	 * @param array  $array The array to read from.
	 * @param string $path  Dot-notation path (e.g. "facebook.pixel_id").
	 * @return mixed The value, or null if the path does not exist.
	 */
	private static function get_value_by_path( $array, $path ) {

		$current = $array;

		foreach (explode('.', $path) as $key) {

			if (!is_array($current) || !array_key_exists($key, $current)) {
				return null;
			}

			$current = $current[$key];
		}

		return $current;
	}

	/**
	 * Set a nested array value using a dot-notation path.
	 *
	 * @param array  $array The array to modify.
	 * @param string $path  Dot-notation path (e.g. "facebook.pixel_id").
	 * @param mixed  $value The value to set.
	 * @return array The modified array.
	 */
	private static function set_value_by_path( $array, $path, $value ) {

		$keys    = explode('.', $path);
		$current = &$array;

		foreach ($keys as $i => $key) {

			if (count($keys) - 1 === $i) {
				$current[$key] = $value;
			} else {

				if (!isset($current[$key]) || !is_array($current[$key])) {
					$current[$key] = [];
				}

				$current = &$current[$key];
			}
		}

		return $array;
	}
}
