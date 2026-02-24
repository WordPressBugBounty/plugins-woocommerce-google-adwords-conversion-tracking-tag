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
 * All abilities are read-only and require manage_options capability.
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
