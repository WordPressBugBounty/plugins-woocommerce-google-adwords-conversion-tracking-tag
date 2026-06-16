<?php
/**
 * WordPress Abilities API integration for Pixel Manager for WooCommerce.
 *
 * Registers PMW abilities using the WordPress Abilities API,
 * enabling AI agents and external tools to discover and interact
 * with Pixel Manager's capabilities.
 *
 * @package SweetCode\Pixel_Manager
 * @since 1.57.0
 */

namespace SweetCode\Pixel_Manager;

use SweetCode\Pixel_Manager\Admin\Debug_Info;
use SweetCode\Pixel_Manager\Admin\Environment;
use SweetCode\Pixel_Manager\Pixels\Core\Pixel_Registry;

defined('ABSPATH') || exit; // Exit if accessed directly

/**
 * Class Abilities
 *
 * Registers PMW abilities with the WordPress Abilities API.
 *
 * Read abilities (tracking status, plugin info, debug info, settings schema,
 * settings values, setup status) expose the plugin state to AI agents. Write
 * abilities (update settings, configure pixel) apply validated sparse patches
 * to the options tree and can be disabled site-wide via the
 * pmw_abilities_allow_write filter. All abilities require the same capability
 * as the settings UI (manage_options or manage_woocommerce).
 *
 * @since 1.57.0
 */
class Abilities {

	/**
	 * Initialize the Abilities API integration.
	 *
	 * Hooks into the Abilities API init actions to register
	 * the PMW ability category and individual abilities.
	 *
	 * @since 1.57.0
	 * @return void
	 */
	public static function init() {

		// Only register if the Abilities API is available (WP 6.9+ or plugin)
		if (!function_exists('\wp_register_ability')) {
			return;
		}

		add_action('wp_abilities_api_categories_init', [ __CLASS__, 'register_categories' ]);
		add_action('wp_abilities_api_init', [ __CLASS__, 'register_abilities' ]);
	}

	/**
	 * Register the PMW ability category.
	 *
	 * @since 1.57.0
	 * @return void
	 */
	public static function register_categories() {
		\wp_register_ability_category('tracking', [
			'label'       => __('Tracking & Analytics', 'woocommerce-google-adwords-conversion-tracking-tag'),
			'description' => __('Abilities related to conversion tracking, analytics pixels, and marketing tag management.', 'woocommerce-google-adwords-conversion-tracking-tag'),
		]);
	}

	/**
	 * Register all PMW abilities.
	 *
	 * @since 1.57.0
	 * @return void
	 */
	public static function register_abilities() {
		self::register_get_tracking_status();
		self::register_get_plugin_info();
		self::register_get_debug_info();
		self::register_get_settings_schema();
		self::register_get_settings();
		self::register_get_setup_status();

		// Write abilities can be disabled site-wide:
		// add_filter('pmw_abilities_allow_write', '__return_false');
		if (Abilities_Settings::is_write_enabled()) {
			self::register_update_settings();
			self::register_configure_pixel();
		}
	}

	/**
	 * Register the get-tracking-status ability.
	 *
	 * Returns information about which tracking pixels are configured,
	 * active, and their capabilities (browser/server tracking).
	 *
	 * @since 1.57.0
	 * @return void
	 */
	private static function register_get_tracking_status() {
		\wp_register_ability('pmw/get-tracking-status', [
			'label'               => __('Get Tracking Status', 'woocommerce-google-adwords-conversion-tracking-tag'),
			'description'         => __('Retrieves the status of all configured tracking pixels in Pixel Manager for WooCommerce, including which pixels are active, their categories (marketing, statistics, optimization), and whether they use browser-side or server-side tracking.', 'woocommerce-google-adwords-conversion-tracking-tag'),
			'category'            => 'tracking',
			'output_schema'       => [
				'type'       => 'object',
				'properties' => [
					'active_pixels' => [
						'type'        => 'array',
						'description' => 'List of currently active tracking pixels with their details',
						'items'       => [
							'type'       => 'object',
							'properties' => [
								'name'             => [
									'type'        => 'string',
									'description' => 'Pixel identifier (e.g., facebook, google_ads, tiktok)',
								],
								'label'            => [
									'type'        => 'string',
									'description' => 'Human-readable pixel name',
								],
								'category'         => [
									'type'        => 'string',
									'description' => 'Pixel category: marketing, statistics, or optimization',
								],
								'browser_tracking' => [
									'type'        => 'boolean',
									'description' => 'Whether the pixel uses browser-side JavaScript tracking',
								],
								'server_tracking'  => [
									'type'        => 'boolean',
									'description' => 'Whether the pixel uses server-to-server (S2S/CAPI) tracking',
								],
							],
						],
					],
					'all_pixels'    => [
						'type'        => 'array',
						'description' => 'List of all registered tracking pixels with their status',
						'items'       => [
							'type'       => 'object',
							'properties' => [
								'name'   => [
									'type'        => 'string',
									'description' => 'Pixel identifier',
								],
								'label'  => [
									'type'        => 'string',
									'description' => 'Human-readable pixel name',
								],
								'active' => [
									'type'        => 'boolean',
									'description' => 'Whether the pixel is currently active',
								],
							],
						],
					],
					'summary'       => [
						'type'       => 'object',
						'properties' => [
							'total_registered'          => [
								'type'        => 'integer',
								'description' => 'Total number of registered pixels',
							],
							'total_active'              => [
								'type'        => 'integer',
								'description' => 'Total number of active pixels',
							],
							'has_marketing_pixels'      => [
								'type'        => 'boolean',
								'description' => 'Whether any marketing pixels are active',
							],
							'has_statistics_pixels'     => [
								'type'        => 'boolean',
								'description' => 'Whether any statistics/analytics pixels are active',
							],
							'has_optimization_pixels'   => [
								'type'        => 'boolean',
								'description' => 'Whether any optimization pixels are active',
							],
							'has_server_side_tracking'  => [
								'type'        => 'boolean',
								'description' => 'Whether any server-to-server tracking is active',
							],
						],
					],
				],
			],
			'execute_callback'    => [ __CLASS__, 'execute_get_tracking_status' ],
			'permission_callback' => function () {
				return current_user_can('manage_options');
			},
			'meta'                => [
				'annotations' => [
					'readonly'    => true,
					'destructive' => false,
					'idempotent'  => true,
				],
				'show_in_rest' => true,
			],
		]);
	}

	/**
	 * Register the get-plugin-info ability.
	 *
	 * Returns general information about the Pixel Manager plugin,
	 * including version, tier, and environment details.
	 *
	 * @since 1.57.0
	 * @return void
	 */
	private static function register_get_plugin_info() {
		\wp_register_ability('pmw/get-plugin-info', [
			'label'               => __('Get Plugin Info', 'woocommerce-google-adwords-conversion-tracking-tag'),
			'description'         => __('Retrieves general information about the Pixel Manager for WooCommerce plugin, including version, license tier (free/pro), distribution channel, and environment compatibility status.', 'woocommerce-google-adwords-conversion-tracking-tag'),
			'category'            => 'tracking',
			'output_schema'       => [
				'type'       => 'object',
				'properties' => [
					'version'              => [
						'type'        => 'string',
						'description' => 'Current plugin version',
					],
					'tier'                 => [
						'type'        => 'string',
						'description' => 'License tier: free or pro',
					],
					'distribution'         => [
						'type'        => 'string',
						'description' => 'Distribution channel (e.g., fms for Freemius)',
					],
					'woocommerce_active'   => [
						'type'        => 'boolean',
						'description' => 'Whether WooCommerce is active',
					],
					'woocommerce_version'  => [
						'type'        => 'string',
						'description' => 'WooCommerce version if active',
					],
					'wordpress_version'    => [
						'type'        => 'string',
						'description' => 'WordPress version',
					],
					'php_version'          => [
						'type'        => 'string',
						'description' => 'PHP version',
					],
				],
			],
			'execute_callback'    => [ __CLASS__, 'execute_get_plugin_info' ],
			'permission_callback' => function () {
				return current_user_can('manage_options');
			},
			'meta'                => [
				'annotations' => [
					'readonly'    => true,
					'destructive' => false,
					'idempotent'  => true,
				],
				'show_in_rest' => true,
			],
		]);
	}

	/**
	 * Register the get-debug-info ability.
	 *
	 * Returns the full debug information report, useful for
	 * troubleshooting and support.
	 *
	 * @since 1.57.0
	 * @return void
	 */
	private static function register_get_debug_info() {
		\wp_register_ability('pmw/get-debug-info', [
			'label'               => __('Get Debug Info', 'woocommerce-google-adwords-conversion-tracking-tag'),
			'description'         => __('Retrieves the full Pixel Manager for WooCommerce debug information report, including system environment details, active pixel configurations, consent mode settings, and tracking accuracy data. Useful for troubleshooting and support.', 'woocommerce-google-adwords-conversion-tracking-tag'),
			'category'            => 'tracking',
			'output_schema'       => [
				'type'       => 'object',
				'properties' => [
					'report' => [
						'type'        => 'string',
						'description' => 'Full debug information report as formatted text',
					],
				],
			],
			'execute_callback'    => [ __CLASS__, 'execute_get_debug_info' ],
			'permission_callback' => function () {
				return current_user_can('manage_options');
			},
			'meta'                => [
				'annotations' => [
					'readonly'    => true,
					'destructive' => false,
					'idempotent'  => true,
				],
				'show_in_rest' => true,
			],
		]);
	}

	/**
	 * Register the get-settings-schema ability.
	 *
	 * Returns the machine-readable catalog of all agent-operable settings:
	 * paths, types, descriptions, defaults, tier and secret flags. This is
	 * the discovery surface that makes the plugin configurable by AI agents.
	 *
	 * @since 1.59.0
	 * @return void
	 */
	private static function register_get_settings_schema() {
		\wp_register_ability('pmw/get-settings-schema', [
			'label'               => __('Get Settings Schema', 'woocommerce-google-adwords-conversion-tracking-tag'),
			'description'         => __('Retrieves the machine-readable catalog of all configurable Pixel Manager for WooCommerce settings, grouped by tracking destination (pixel) and plugin area. Each setting includes its dot-notation path, type, description (including where to find the value), default, whether it is required to activate the pixel, whether it is an advanced feature and what its benefit is, whether it requires the Pro version, and whether it is a secret. Use this before reading or updating settings.', 'woocommerce-google-adwords-conversion-tracking-tag'),
			'category'            => 'tracking',
			'output_schema'       => [
				'type'       => 'object',
				'properties' => [
					'groups'        => [
						'type'        => 'array',
						'description' => 'Setting groups: tracking destinations (is_pixel true) and plugin-level groups',
						'items'       => [
							'type'       => 'object',
							'properties' => [
								'key'      => [ 'type' => 'string', 'description' => 'Group identifier (e.g. google_ads, facebook, consent)' ],
								'label'    => [ 'type' => 'string', 'description' => 'Human-readable group name' ],
								'category' => [ 'type' => 'string', 'description' => 'marketing, statistics, optimization or plugin' ],
								'is_pixel' => [ 'type' => 'boolean', 'description' => 'Whether the group is a tracking destination configurable via pmw/configure-pixel' ],
								'settings' => [
									'type'  => 'array',
									'items' => [
										'type'       => 'object',
										'properties' => [
											'key'         => [ 'type' => 'string', 'description' => 'Short setting key within the group' ],
											'path'        => [ 'type' => 'string', 'description' => 'Dot-notation path used by pmw/update-settings' ],
											'type'        => [ 'type' => 'string', 'description' => 'string, boolean, integer, number or array' ],
											'label'       => [ 'type' => 'string' ],
											'description' => [ 'type' => 'string' ],
											'required'    => [ 'type' => 'boolean', 'description' => 'Required for the pixel to become active' ],
											'advanced'    => [ 'type' => 'boolean', 'description' => 'Optional feature on top of the base setup' ],
											'benefit'     => [ 'type' => 'string', 'description' => 'Why a shop would enable the advanced feature' ],
											'pro'         => [ 'type' => 'boolean', 'description' => 'Only takes effect with the Pro version' ],
											'secret'      => [ 'type' => 'boolean', 'description' => 'Value is never returned in reads' ],
											'enum'        => [ 'type' => 'array', 'description' => 'Allowed values, when the setting is an enumeration' ],
											'format_hint' => [ 'type' => 'string', 'description' => 'Hint about the expected value format' ],
											'default'     => [ 'description' => 'Default value' ],
										],
									],
								],
							],
						],
					],
					'tier'          => [ 'type' => 'string', 'description' => 'Current license tier: free or pro' ],
					'write_enabled' => [ 'type' => 'boolean', 'description' => 'Whether settings writes through the Abilities API are enabled on this site' ],
				],
			],
			'execute_callback'    => [ Abilities_Settings::class, 'execute_get_settings_schema' ],
			'permission_callback' => function () {
				// Always returns a strict boolean: current_user_can('manage_woocommerce') || current_user_can('manage_options').
				// nosemgrep
				return Environment::can_current_user_edit_options();
			},
			'meta'                => [
				'annotations' => [
					'readonly'    => true,
					'destructive' => false,
					'idempotent'  => true,
				],
				'show_in_rest' => true,
			],
		]);
	}

	/**
	 * Register the get-settings ability.
	 *
	 * Returns the current values of all catalog settings. Secrets are
	 * redacted to a set/not-set flag.
	 *
	 * @since 1.59.0
	 * @return void
	 */
	private static function register_get_settings() {
		\wp_register_ability('pmw/get-settings', [
			'label'               => __('Get Settings', 'woocommerce-google-adwords-conversion-tracking-tag'),
			'description'         => __('Retrieves the current values of all configurable Pixel Manager for WooCommerce settings. Secret values such as API tokens are never returned; for those only a set/not-set flag is included. Use pmw/get-settings-schema first to understand what each setting does.', 'woocommerce-google-adwords-conversion-tracking-tag'),
			'category'            => 'tracking',
			'output_schema'       => [
				'type'       => 'object',
				'properties' => [
					'settings'      => [
						'type'  => 'array',
						'items' => [
							'type'       => 'object',
							'properties' => [
								'path'   => [ 'type' => 'string', 'description' => 'Dot-notation setting path' ],
								'group'  => [ 'type' => 'string', 'description' => 'Group key the setting belongs to' ],
								'label'  => [ 'type' => 'string' ],
								'secret' => [ 'type' => 'boolean', 'description' => 'Whether the value is redacted' ],
								'is_set' => [ 'type' => 'boolean', 'description' => 'Whether the setting has a non-empty value' ],
								'value'  => [ 'description' => 'Current value, null for secrets' ],
							],
						],
					],
					'tier'          => [ 'type' => 'string', 'description' => 'Current license tier: free or pro' ],
					'write_enabled' => [ 'type' => 'boolean', 'description' => 'Whether settings writes through the Abilities API are enabled on this site' ],
				],
			],
			'execute_callback'    => [ Abilities_Settings::class, 'execute_get_settings' ],
			'permission_callback' => function () {
				// Always returns a strict boolean: current_user_can('manage_woocommerce') || current_user_can('manage_options').
				// nosemgrep
				return Environment::can_current_user_edit_options();
			},
			'meta'                => [
				'annotations' => [
					'readonly'    => true,
					'destructive' => false,
					'idempotent'  => true,
				],
				'show_in_rest' => true,
			],
		]);
	}

	/**
	 * Register the get-setup-status ability.
	 *
	 * Returns a derived setup checklist: per-pixel configuration state,
	 * missing required settings, available advanced features, and ordered
	 * recommendations for the next setup step. This is the data foundation
	 * for setup wizards and guided onboarding.
	 *
	 * @since 1.59.0
	 * @return void
	 */
	private static function register_get_setup_status() {
		\wp_register_ability('pmw/get-setup-status', [
			'label'               => __('Get Setup Status', 'woocommerce-google-adwords-conversion-tracking-tag'),
			'description'         => __('Retrieves a setup checklist for Pixel Manager for WooCommerce: which tracking pixels are configured and active, which required settings are missing, which advanced features are available but not enabled (including their benefits), and prioritized recommendations for the next setup step. Designed to drive guided setup conversations.', 'woocommerce-google-adwords-conversion-tracking-tag'),
			'category'            => 'tracking',
			'output_schema'       => [
				'type'       => 'object',
				'properties' => [
					'pixels'          => [
						'type'        => 'array',
						'description' => 'Per-pixel setup status',
						'items'       => [
							'type'       => 'object',
							'properties' => [
								'pixel'                => [ 'type' => 'string', 'description' => 'Pixel group key' ],
								'label'                => [ 'type' => 'string' ],
								'category'             => [ 'type' => 'string', 'description' => 'marketing, statistics or optimization' ],
								'pro'                  => [ 'type' => 'boolean', 'description' => 'Whether the pixel requires the Pro version' ],
								'available_in_tier'    => [ 'type' => 'boolean', 'description' => 'Whether the pixel can be used with the current license tier' ],
								'configured'           => [ 'type' => 'boolean', 'description' => 'All required settings are set' ],
								'partially_configured' => [ 'type' => 'boolean', 'description' => 'Some but not all required settings are set' ],
								'active'               => [ 'type' => 'boolean', 'description' => 'The pixel is currently tracking' ],
								'missing_required'     => [ 'type' => 'array', 'description' => 'Required settings that are not set yet, with descriptions of where to find the values' ],
								'advanced_available'   => [ 'type' => 'array', 'description' => 'Advanced features that are not enabled yet, with their benefits' ],
								'advanced_enabled'     => [ 'type' => 'array', 'description' => 'Advanced features that are already enabled' ],
							],
						],
					],
					'plugin_settings' => [
						'type'        => 'array',
						'description' => 'Current values of plugin-level setting groups (consent, shop, general)',
					],
					'summary'         => [
						'type'       => 'object',
						'properties' => [
							'woocommerce_active'   => [ 'type' => 'boolean' ],
							'pixels_configured'    => [ 'type' => 'integer' ],
							'pixels_active'        => [ 'type' => 'integer' ],
							'has_marketing_pixel'  => [ 'type' => 'boolean' ],
							'has_statistics_pixel' => [ 'type' => 'boolean' ],
							'tier'                 => [ 'type' => 'string' ],
							'write_enabled'        => [ 'type' => 'boolean' ],
						],
					],
					'recommendations' => [
						'type'        => 'array',
						'description' => 'Ordered, human-readable recommendations for the next setup step',
						'items'       => [ 'type' => 'string' ],
					],
				],
			],
			'execute_callback'    => [ Abilities_Settings::class, 'execute_get_setup_status' ],
			'permission_callback' => function () {
				// Always returns a strict boolean: current_user_can('manage_woocommerce') || current_user_can('manage_options').
				// nosemgrep
				return Environment::can_current_user_edit_options();
			},
			'meta'                => [
				'annotations' => [
					'readonly'    => true,
					'destructive' => false,
					'idempotent'  => true,
				],
				'show_in_rest' => true,
			],
		]);
	}

	/**
	 * Register the update-settings ability.
	 *
	 * Applies a sparse patch of settings: only the submitted dot-notation
	 * paths are validated and merged into the full options tree, everything
	 * else is preserved. Every save creates an automatic options backup.
	 *
	 * @since 1.59.0
	 * @return void
	 */
	private static function register_update_settings() {
		\wp_register_ability('pmw/update-settings', [
			'label'               => __('Update Settings', 'woocommerce-google-adwords-conversion-tracking-tag'),
			'description'         => __('Updates one or more Pixel Manager for WooCommerce settings by dot-notation path. Only the submitted settings are changed, all other settings are preserved. Each value is validated with the same rules as the admin UI (including format checks for IDs and tokens), and every save creates an automatic settings backup. Use pmw/get-settings-schema to discover the available paths, types and value formats.', 'woocommerce-google-adwords-conversion-tracking-tag'),
			'category'            => 'tracking',
			'input_schema'        => [
				'type'       => 'object',
				'properties' => [
					'settings' => [
						'type'                 => 'object',
						'description'          => 'Map of dot-notation setting paths to new values, e.g. {"google.ads.conversion_id": "123456789", "google.ads.conversion_label": "AbC-D_efG"}',
						'additionalProperties' => true,
					],
				],
				'required'   => [ 'settings' ],
			],
			'output_schema'       => [
				'type'       => 'object',
				'properties' => [
					'saved'         => [ 'type' => 'boolean', 'description' => 'Whether any settings were saved' ],
					'updated_count' => [ 'type' => 'integer', 'description' => 'Number of settings that were changed' ],
					'results'       => [
						'type'        => 'array',
						'description' => 'Per-setting result',
						'items'       => [
							'type'       => 'object',
							'properties' => [
								'path'    => [ 'type' => 'string' ],
								'status'  => [ 'type' => 'string', 'description' => 'updated, unchanged, invalid or unknown' ],
								'message' => [ 'type' => 'string', 'description' => 'Validation error message, when invalid' ],
								'value'   => [ 'description' => 'The saved (normalized) value, null for secrets' ],
								'note'    => [ 'type' => 'string', 'description' => 'Additional information, e.g. that the setting requires a Pro license to take effect' ],
							],
						],
					],
				],
			],
			'execute_callback'    => [ Abilities_Settings::class, 'execute_update_settings' ],
			'permission_callback' => function () {
				// Always returns a strict boolean: current_user_can('manage_woocommerce') || current_user_can('manage_options').
				// nosemgrep
				return Environment::can_current_user_edit_options();
			},
			'meta'                => [
				'annotations' => [
					'readonly'    => false,
					'destructive' => false,
					'idempotent'  => true,
				],
				'show_in_rest' => true,
			],
		]);
	}

	/**
	 * Register the configure-pixel ability.
	 *
	 * Intent-level ability for setting up a tracking destination in one call.
	 * Returns the pixel's new setup status including missing required
	 * settings and available advanced features, so an agent can guide the
	 * user through the next step.
	 *
	 * @since 1.59.0
	 * @return void
	 */
	private static function register_configure_pixel() {

		$pixel_keys = [];

		foreach (Abilities_Settings::get_catalog() as $group_key => $group) {
			if (!empty($group['is_pixel'])) {
				$pixel_keys[] = $group_key;
			}
		}

		\wp_register_ability('pmw/configure-pixel', [
			'label'               => __('Configure Pixel', 'woocommerce-google-adwords-conversion-tracking-tag'),
			'description'         => __('Configures a tracking pixel (destination) in Pixel Manager for WooCommerce in one call. Accepts the pixel key and a map of setting keys to values (e.g. for google_ads: conversion_id and conversion_label). Validates and saves the values, then returns the pixel\'s setup status: whether it is active, which required settings are still missing, and which advanced features are available with their benefits. Ideal for guided setup conversations.', 'woocommerce-google-adwords-conversion-tracking-tag'),
			'category'            => 'tracking',
			'input_schema'        => [
				'type'       => 'object',
				'properties' => [
					'pixel'    => [
						'type'        => 'string',
						'description' => 'The pixel to configure',
						'enum'        => $pixel_keys,
					],
					'settings' => [
						'type'                 => 'object',
						'description'          => 'Map of setting keys (relative to the pixel, as returned by pmw/get-settings-schema) to new values, e.g. {"conversion_id": "123456789", "conversion_label": "AbC-D_efG"}',
						'additionalProperties' => true,
					],
				],
				'required'   => [ 'pixel', 'settings' ],
			],
			'output_schema'       => [
				'type'       => 'object',
				'properties' => [
					'pixel'   => [ 'type' => 'string' ],
					'saved'   => [ 'type' => 'boolean', 'description' => 'Whether any settings were saved' ],
					'results' => [ 'type' => 'array', 'description' => 'Per-setting result with status updated, unchanged, invalid or unknown' ],
					'status'  => [ 'type' => 'object', 'description' => 'The pixel\'s setup status after the update: configured, active, missing_required, advanced_available with benefits' ],
				],
			],
			'execute_callback'    => [ Abilities_Settings::class, 'execute_configure_pixel' ],
			'permission_callback' => function () {
				// Always returns a strict boolean: current_user_can('manage_woocommerce') || current_user_can('manage_options').
				// nosemgrep
				return Environment::can_current_user_edit_options();
			},
			'meta'                => [
				'annotations' => [
					'readonly'    => false,
					'destructive' => false,
					'idempotent'  => true,
				],
				'show_in_rest' => true,
			],
		]);
	}

	/**
	 * Execute callback for the get-tracking-status ability.
	 *
	 * @since 1.57.0
	 * @return array Tracking status data
	 */
	public static function execute_get_tracking_status() {

		$descriptors   = Pixel_Registry::get_descriptors();
		$active_pixels = [];
		$all_pixels    = [];

		foreach ($descriptors as $descriptor) {
			$pixel_data = [
				'name'   => $descriptor->get_name(),
				'label'  => $descriptor->get_label(),
				'active' => $descriptor->is_active(),
			];

			$all_pixels[] = $pixel_data;

			if ($descriptor->is_active()) {
				$active_pixels[] = [
					'name'             => $descriptor->get_name(),
					'label'            => $descriptor->get_label(),
					'category'         => $descriptor->get_category(),
					'browser_tracking' => $descriptor->has_browser_tracking(),
					'server_tracking'  => $descriptor->has_server_tracking(),
				];
			}
		}

		return [
			'active_pixels' => $active_pixels,
			'all_pixels'    => $all_pixels,
			'summary'       => [
				'total_registered'         => count($descriptors),
				'total_active'             => count($active_pixels),
				'has_marketing_pixels'     => Pixel_Registry::has_active_marketing_pixels(),
				'has_statistics_pixels'    => Pixel_Registry::has_active_statistics_pixels(),
				'has_optimization_pixels'  => Pixel_Registry::has_active_optimization_pixels(),
				'has_server_side_tracking' => Pixel_Registry::has_available_adapters(),
			],
		];
	}

	/**
	 * Execute callback for the get-plugin-info ability.
	 *
	 * @since 1.57.0
	 * @return array Plugin information data
	 */
	public static function execute_get_plugin_info() {

		global $wp_version;

		$tier = wpm_fs()->can_use_premium_code__premium_only() ? 'pro' : 'free';

		$info = [
			'version'             => PMW_CURRENT_VERSION,
			'tier'                => $tier,
			'distribution'        => PMW_DISTRO,
			'woocommerce_active'  => Environment::is_woocommerce_active(),
			'wordpress_version'   => $wp_version,
			'php_version'         => phpversion(),
		];

		if (Environment::is_woocommerce_active()) {
			global $woocommerce;
			$info['woocommerce_version'] = $woocommerce->version;
		} else {
			$info['woocommerce_version'] = '';
		}

		return $info;
	}

	/**
	 * Execute callback for the get-debug-info ability.
	 *
	 * @since 1.57.0
	 * @return array Debug information data
	 */
	public static function execute_get_debug_info() {
		return [
			'report' => Debug_Info::get_debug_info(),
		];
	}
}
