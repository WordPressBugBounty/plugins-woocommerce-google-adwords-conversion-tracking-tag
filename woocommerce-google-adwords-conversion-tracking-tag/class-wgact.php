<?php

/**
 * Defines the main class WCPM for the Pixel Manager for WooCommerce plugin.
 * It handles plugin initialization, activation, and deactivation,
 * checks WooCommerce requirements, registers hooks, and injects tracking pixels into the front end.
 * It also sets up the Freemius environment, manages plugin settings, and runs WooCommerce reports.
 *
 * @var string $pmw_version
 * @var string $plugin_basename
 */
defined( 'ABSPATH' ) || exit;
// Exit if accessed directly
use SweetCode\Pixel_Manager\Admin\Admin;
use SweetCode\Pixel_Manager\Admin\Admin_REST;
use SweetCode\Pixel_Manager\Admin\Borlabs;
use SweetCode\Pixel_Manager\Admin\Debug_Info;
use SweetCode\Pixel_Manager\Admin\Environment;
use SweetCode\Pixel_Manager\Admin\LTV;
use SweetCode\Pixel_Manager\Admin\Notifications\Notifications;
use SweetCode\Pixel_Manager\Admin\Order_Columns;
use SweetCode\Pixel_Manager\Deprecated_Filters;
use SweetCode\Pixel_Manager\Helpers;
use SweetCode\Pixel_Manager\Logger;
use SweetCode\Pixel_Manager\Options;
use SweetCode\Pixel_Manager\Pixels\Pixel_Manager;
use SweetCode\Pixel_Manager\Product;
use SweetCode\Pixel_Manager\Shop;
use SweetCode\Pixel_Manager\Admin\Ask_For_Rating;
// autoloader
require_once 'autoload.php';
// Define constants
define( 'PMW_CURRENT_VERSION', $pmw_version );
define( 'PMW_PLUGIN_PREFIX', 'pmw_' );
define( 'PMW_DB_VERSION', '3' );
define( 'PMW_DB_OPTIONS_NAME', 'wgact_plugin_options' );
define( 'PMW_DB_NOTIFICATIONS_NAME', 'wgact_notifications' );
define( 'PMW_PLUGIN_DIR_PATH', plugin_dir_url( __FILE__ ) );
define( 'PMW_PLUGIN_BASENAME', $plugin_basename );
define( 'PMW_PLUGIN_FILE', WP_PLUGIN_DIR . '/' . PMW_PLUGIN_BASENAME );
define( 'PMW_DISTRO', 'fms' );
define( 'PMW_DB_RATINGS', 'wgact_ratings' );
class WCPM {
    public function __construct() {
        require_once __DIR__ . '/vendor/woocommerce/action-scheduler/action-scheduler.php';
        // check if WooCommerce is running
        // currently this is the most reliable test for single and multisite setups
        include_once ABSPATH . 'wp-admin/includes/plugin.php';
        if ( $this->are_requirements_not_met() ) {
            add_action( 'admin_menu', [$this, 'add_empty_admin_page'], 99 );
            add_action( 'admin_notices', [$this, 'requirements_error'] );
            return;
        }
        if ( is_readable( __DIR__ . '/vendor/autoload.php' ) ) {
            require __DIR__ . '/vendor/autoload.php';
        }
        Environment::purge_cache_on_plugin_changes();
        register_activation_hook( __FILE__, [$this, 'plugin_activated'] );
        register_deactivation_hook( __FILE__, [$this, 'plugin_deactivated'] );
        register_deactivation_hook( __FILE__, function () {
            $timestamp = wp_next_scheduled( 'pmw_tracking_accuracy_analysis' );
            wp_unschedule_event( $timestamp, 'pmw_tracking_accuracy_analysis' );
        } );
        Deprecated_Filters::load_deprecated_filters();
        Environment::third_party_plugin_tweaks_on_plugins_loaded();
        if ( Environment::is_woocommerce_active() ) {
            add_action( 'before_woocommerce_init', [__CLASS__, 'declare_woocommerce_compatibilities'] );
            add_action(
                'init',
                [$this, 'register_hooks_for_woocommerce'],
                10,
                2
            );
            add_action(
                'init',
                [$this, 'register_generic_hooks'],
                10,
                2
            );
            add_action(
                'init',
                [$this, 'run_woocommerce_reports'],
                10,
                2
            );
            add_action( 'woocommerce_init', [$this, 'init'] );
        } else {
            add_action( 'init', [$this, 'init'] );
        }
    }

    public function register_hooks_for_woocommerce() {
        add_action( 'pmw_reactivate_duplication_prevention', function () {
            Options::enable_duplication_prevention();
        } );
        add_action( 'pmw_deactivate_log_http_requests', function () {
            Options::disable_http_request_logging();
        } );
        add_action( 'pmw_tracking_accuracy_analysis', function () {
            Debug_Info::run_tracking_accuracy_analysis();
        } );
        add_action( 'pmw_print_product_data_layer_script_by_product', function ( $product ) {
            Product::print_product_data_layer_script( $product );
        } );
        add_action( 'pmw_print_product_data_layer_script_by_product_id', function ( $product_id ) {
            Product::print_product_data_layer_script( wc_get_product( $product_id ) );
        } );
        $this->register_ltv_calculation_hooks();
    }

    private function register_ltv_calculation_hooks() {
        add_action( 'pmw_batch_process_vertical_ltv_calculation', function ( $order_id ) {
            LTV::batch_process_vertical_ltv_calculation( $order_id );
        } );
        add_action( 'pmw_horizontal_ltv_calculation_check', function ( $order_id ) {
            LTV::horizontal_ltv_calculation_check( $order_id );
        } );
        add_action( 'pmw_horizontal_ltv_calculation', function ( $order_id ) {
            LTV::horizontal_ltv_calculation( $order_id );
        } );
        add_action( 'action_scheduler_failed_action', function ( $action_id ) {
            LTV::handle_action_scheduler_failed_action( $action_id, 'failed action' );
        } );
        add_action( 'action_scheduler_failed_execution', function ( $action_id ) {
            LTV::handle_action_scheduler_failed_action( $action_id, 'failed execution' );
        } );
        add_action( 'action_scheduler_unexpected_shutdown', function ( $action_id ) {
            LTV::handle_action_scheduler_failed_action( $action_id, 'unexpected shutdown' );
        } );
        add_action( 'woocommerce_order_status_cancelled', function ( $order_id ) {
            Logger::info( 'Cancellation detected. Starting horizontal LTV calculation for order ' . $order_id );
            LTV::horizontal_ltv_calculation( $order_id );
        } );
        add_action( 'woocommerce_order_refunded', function ( $order_id ) {
            Logger::info( 'Refund detected. Starting horizontal LTV calculation for order ' . $order_id );
            LTV::horizontal_ltv_calculation( $order_id );
        } );
    }

    public function register_generic_hooks() {
        // Nothing here yet
    }

    public function run_woocommerce_reports() {
        if ( wp_doing_ajax() ) {
            return;
        }
        // Don't run on the frontend
        if ( !is_admin() ) {
            return;
        }
        // Only run reports if the Pixel Manager settings are being accessed
        if ( !Environment::is_pmw_settings_page() ) {
            return;
        }
        // Unschedule the WP cron event as we are moving to the Action Scheduler with version 1.30.8
        if ( wp_next_scheduled( 'pmw_tracking_accuracy_analysis' ) ) {
            wp_unschedule_event( wp_next_scheduled( 'pmw_tracking_accuracy_analysis' ), 'pmw_tracking_accuracy_analysis' );
        }
        // Only run if the Action Scheduler is loaded
        // and if transients are enabled
        if ( Environment::is_action_scheduler_active() && Environment::is_transients_enabled() ) {
            if ( !Helpers::pmw_as_has_scheduled_action( 'pmw_tracking_accuracy_analysis' ) ) {
                as_schedule_recurring_action(
                    Helpers::datetime_string_to_unix_timestamp_in_local_timezone( 'today 4:25am' ),
                    DAY_IN_SECONDS,
                    'pmw_tracking_accuracy_analysis',
                    [],
                    '',
                    true
                );
            }
            // If the tracking accuracy has not been run yet, run it immediately in the background.
            // https://github.com/woocommerce/action-scheduler/issues/839
            if ( function_exists( 'as_enqueue_async_action' ) && !get_transient( 'pmw_tracking_accuracy_analysis' ) ) {
                as_enqueue_async_action( 'pmw_tracking_accuracy_analysis' );
            }
        }
    }

    protected function is_pmw_tracking_accuracy_analysis_scheduled_more_than_once() {
        $as_args = [
            'hook'   => 'pmw_tracking_accuracy_analysis',
            'status' => ActionScheduler_Store::STATUS_PENDING,
        ];
        return count( as_get_scheduled_actions( $as_args, 'ids' ) ) > 1;
    }

    protected function are_requirements_met() {
        if ( $this->is_pmw_woocommerce_requirement_disabled() ) {
            return true;
        }
        return Environment::is_woocommerce_active();
    }

    private function are_requirements_not_met() {
        return !$this->are_requirements_met();
    }

    protected function is_pmw_woocommerce_requirement_disabled() {
        //			if (
        //				defined('PMW_EXPERIMENTAL_DISABLE_WOOCOMMERCE_REQUIREMENT') &&
        //				true === PMW_EXPERIMENTAL_DISABLE_WOOCOMMERCE_REQUIREMENT
        //			) {
        //				return true;
        //			}
        //			return false;
        return true;
    }

    public function add_empty_admin_page() {
        add_submenu_page(
            'woocommerce',
            esc_html__( 'Pixel Manager', 'woocommerce-google-adwords-conversion-tracking-tag' ),
            esc_html__( 'Pixel Manager', 'woocommerce-google-adwords-conversion-tracking-tag' ),
            Environment::get_user_edit_capability(),
            'wpm',
            function () {
            }
        );
    }

    // https://github.com/iandunn/WordPress-Plugin-Skeleton/blob/master/views/requirements-error.php
    public function requirements_error() {
        ?>

		<div class="error">
			<p>
				<strong>
					<?php 
        esc_html_e( 'Pixel Manager for WooCommerce error', 'woocommerce-google-adwords-conversion-tracking-tag' );
        ?>
				</strong>:
				<?php 
        esc_html_e( "Your environment doesn't meet all the system requirements listed below.", 'woocommerce-google-adwords-conversion-tracking-tag' );
        ?>
			</p>

			<ul class="ul-disc">
				<li><?php 
        esc_html_e( 'The WooCommerce plugin needs to be activated', 'woocommerce-google-adwords-conversion-tracking-tag' );
        ?>
					:
					<a href="https://wordpress.org/plugins/woocommerce/" target="_blank">WooCommerce</a>
				</li>
			</ul>
		</div>
		<style>
            .fs-tab {
                display: none !important;
            }
		</style>

		<?php 
    }

    public function plugin_activated() {
        Environment::purge_entire_cache();
    }

    public function plugin_deactivated() {
        Environment::purge_entire_cache();
    }

    // startup all functions
    public function init() {
        // DeleteIf(wcMarketFree)
        // Needs to be under init to avoid issues with filters called in the Options class
        self::setup_freemius_environment();
        // endDeleteIf(wcMarketFree)
        // Needs to be under init to avoid issues with filters called in the Options class
        Environment::third_party_plugin_tweaks_on_init();
        // Needs to be under init to avoid issues with filters called in the Options class
        if ( Options::is_maximum_compatiblity_mode_active() ) {
            Environment::enable_compatibility_mode();
        }
        Admin_REST::get_instance();
        if ( is_admin() ) {
            Borlabs::init();
            // display admin views
            Admin::init();
            // ask visitor for rating
            Ask_For_Rating::get_instance();
            // Load admin notification handlers
            Notifications::get_instance();
            // Show PMW information on the order list page
            // TODO: Check if we need to only load this on the order list page
            if ( Environment::is_woocommerce_active() && Options::is_shop_order_list_info_enabled() ) {
                Order_Columns::get_instance();
            }
            // add a settings link on the plugins page
            add_filter( 'plugin_action_links_' . PMW_PLUGIN_BASENAME, [$this, 'pmw_settings_link'] );
        }
        Deprecated_Filters::load_deprecated_filters();
        // inject pixels into front end
        $this->inject_pixels();
    }

    public function inject_pixels() {
        // TODO Remove the cookie prevention filters by January 2023
        $cookie_prevention = apply_filters_deprecated(
            'wgact_cookie_prevention',
            [false],
            '1.10.4',
            'wooptpm_cookie_prevention'
        );
        $cookie_prevention = apply_filters_deprecated(
            'wooptpm_cookie_prevention',
            [$cookie_prevention],
            '1.12.1',
            '',
            'This filter has been replaced by a much more robust cookie consent handing in the plugin. Please read more about it in the documentation.'
        );
        if ( false === $cookie_prevention ) {
            // inject pixels
            Pixel_Manager::get_instance();
        }
    }

    /**
     * Adds a link on the plugins page for the settings
     * ! It can't be required. Must be in the main plugin file!
     */
    public function pmw_settings_link( $links ) {
        if ( Environment::is_woocommerce_active() ) {
            $admin_page = 'admin.php';
        } else {
            $admin_page = 'options-general.php';
        }
        $links[] = '<a href="' . admin_url( $admin_page . '?page=wpm' ) . '">Settings</a>';
        return $links;
    }

    // DeleteIf(wcMarketFree)
    protected static function setup_freemius_environment() {
        wpm_fs()->add_filter( 'show_trial', function () {
            if ( self::is_development_install() ) {
                return false;
            } else {
                return self::is_admin_trial_promo_active() && self::is_admin_notifications_active();
            }
        } );
        // re-show trial message after n seconds
        wpm_fs()->add_filter( 'reshow_trial_after_every_n_sec', function () {
            return MONTH_IN_SECONDS * 6;
        } );
    }

    private static function is_admin_trial_promo_active() {
        $admin_trial_promo_active = apply_filters_deprecated(
            'wooptpm_show_admin_trial_promo',
            [true],
            '1.13.0',
            'pmw_show_admin_trial_promo'
        );
        $admin_trial_promo_active = apply_filters_deprecated(
            'wpm_show_admin_trial_promo',
            [$admin_trial_promo_active],
            '1.31.2',
            'pmw_show_admin_trial_promo'
        );
        return apply_filters( 'pmw_show_admin_trial_promo', $admin_trial_promo_active );
    }

    private static function is_admin_notifications_active() {
        $admin_notifications_active = apply_filters_deprecated(
            'wooptpm_show_admin_notifications',
            [true],
            '1.13.0',
            'pmw_show_admin_notifications'
        );
        $admin_notifications_active = apply_filters_deprecated(
            'wpm_show_admin_notifications',
            [$admin_notifications_active],
            '1.31.2',
            'pmw_show_admin_notifications'
        );
        return apply_filters( 'pmw_show_admin_notifications', $admin_notifications_active );
    }

    // endDeleteIf(wcMarketFree)
    protected static function is_development_install() {
        if ( class_exists( 'FS_Site' ) ) {
            return FS_Site::is_localhost_by_address( get_site_url() );
        } else {
            return false;
        }
    }

    public static function declare_woocommerce_compatibilities() {
        if ( wp_doing_ajax() ) {
            return;
        }
        if ( !Helpers::does_the_woocommerce_declare_compatibility_function_exist() ) {
            return;
        }
        // Declare HPOS compatibility
        Helpers::declare_woocommerce_compatibility( 'custom_order_tables' );
        // Declare Cart and Checkout Blocks compatibility
        Helpers::declare_woocommerce_compatibility( 'cart_checkout_blocks' );
    }

}

new WCPM();