<?php

// TODO move script for copying debug info into a proper .js enqueued file, or switch tabs to JavaScript switching and always save all settings at the same time
namespace SweetCode\Pixel_Manager\Admin;

use SweetCode\Pixel_Manager\Admin\Notifications\Notifications;
use SweetCode\Pixel_Manager\Admin\Opportunities\Opportunities;
use SweetCode\Pixel_Manager\Helpers;
use SweetCode\Pixel_Manager\Logger;
use SweetCode\Pixel_Manager\Options;
use SweetCode\Pixel_Manager\Pixels\Pixel_Manager;
use WP_Post;
defined( 'ABSPATH' ) || exit;
// Exit if accessed directly
class Admin {
    private static $instance;

    public static function get_instance() {
        if ( is_null( self::$instance ) ) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        add_action( 'admin_enqueue_scripts', [$this, 'wpm_admin_scripts'] );
        add_action( 'admin_enqueue_scripts', [$this, 'pmw_edit_order_scripts'] );
        add_action( 'admin_enqueue_scripts', [$this, 'wpm_admin_css'] );
        // add the admin options page
        add_action( 'admin_menu', [$this, 'plugin_admin_add_page'], 99 );
        // install a settings page in the admin console
        add_action( 'admin_init', [$this, 'plugin_admin_init'] );
        // add admin scripts to plugins.php page
        add_action( 'load-plugins.php', [$this, 'freemius_load_deactivation_button_js'] );
        // Load textdomain
        add_action( 'init', [$this, 'load_plugin_textdomain'] );
        wpm_fs()->add_filter( 'templates/checkout.php', [$this, 'fs_inject_additional_scripts'] );
        wpm_fs()->add_filter( 'checkout/purchaseCompleted', [$this, 'fs_after_purchase_js'] );
        // output some info into the wpmDataLayer object in the header
        add_action( 'admin_head', [__CLASS__, 'wpm_data_layer'] );
    }

    public static function wpm_data_layer() {
        $data['version'] = Helpers::get_version_info();
        ?>
		<script>
			var wpmDataLayer = <?php 
        echo wp_json_encode( $data );
        ?>;
		</script>
		<?php 
    }

    private function if_is_wpm_admin_page() {
        $_get = Helpers::get_input_vars( INPUT_GET );
        if ( !empty( $_get['page'] ) && 'wpm' === $_get['page'] ) {
            return true;
        } else {
            return false;
        }
    }

    // This function is only called when our plugin's page loads!
    public function freemius_load_deactivation_button_js() {
        add_action( 'admin_enqueue_scripts', [$this, 'freemius_enqueue_deactivation_button_js'] );
    }

    public function freemius_enqueue_deactivation_button_js() {
        wp_enqueue_script(
            'freemius-enqueue-deactivation-button',
            PMW_PLUGIN_DIR_PATH . 'js/admin/wpm-admin-freemius.p1.min.js',
            ['jquery'],
            PMW_CURRENT_VERSION,
            true
        );
    }

    /**
     * This returns a JavaScript function that is called after a purchase is made.
     *
     * @param $js_function
     * @return string
     */
    public function fs_after_purchase_js( $js_function ) {
        return <<<JS
     (response) => {

\t\tlet product_name = "Pixel Manager for WooCommerce";
\t
\t\tlet trial_conversion_percentage = 0.52;
\t
\t\tlet is_trial = (null != response.purchase.trial_ends),
\t\t\tis_subscription = (null != response.purchase.initial_amount),
\t\t\tpre_total = Number(is_subscription ? response.purchase.initial_amount : response.purchase.gross).toFixed(2),
\t\t\ttrial_total = is_trial ? (pre_total * trial_conversion_percentage).toFixed(2) : pre_total,
\t\t\ttotal = is_trial ? trial_total : pre_total,
\t\t\tcurrency = response.purchase.currency.toUpperCase(),
\t\t\ttransaction_id = response.purchase.id.toString(),
\t\t\tstore_name = window.location.hostname;
\t
\t\twindow.dataLayer = window.dataLayer || [];
\t
\t\tdataLayer.push({
\t\t\tevent: "purchase",
\t\t\ttransaction_id: transaction_id,
\t\t\ttransaction_value: total,
\t\t\ttransaction_currency: currency,
\t\t\ttransaction_coupon: response.purchase.coupon_id,
\t\t\ttransaction_affiliation: store_name,
\t\t\titems: [
\t\t\t\t{
\t\t\t\t\titem_name: product_name,
\t\t\t\t\titem_id: response.purchase.plan_id.toString(),
\t\t\t\t\titem_category: "Plugin",
\t\t\t\t\tprice: response.purchase.initial_amount.toString(),
\t\t\t\t\tquantity: 1,
\t\t\t\t\tcurrency: currency,
\t\t\t\t\taffiliation: store_name,
\t\t\t\t},
\t\t\t],
\t\t\tfreemius_data: response,
\t\t});
\t
\t\t(function (w, d, s, l, i) {
\t\t\tw[l] = w[l] || []; w[l].push({
\t\t\t\t'gtm.start':
\t\t\t\t\tnew Date().getTime(), event: 'gtm.js'
\t\t\t}); var f = d.getElementsByTagName(s)[0],
\t\t\t\tj = d.createElement(s), dl = l != 'dataLayer' ? '&l=' + l : ''; j.async = true; j.src =
\t\t\t\t\t'https://www.googletagmanager.com/gtm.js?id=' + i + dl; f.parentNode.insertBefore(j, f);
\t\t})(window, document, 'script', 'dataLayer', 'GTM-NZ8WQ6QS');
\t}
JS;
    }

    // phpcs:disable
    public function fs_inject_additional_scripts( $html ) {
        return '<script async src="https://www.googletagmanager.com/gtag/js?id=UA-39746956-10"></script>' . $html;
    }

    // phpcs:enable
    public function wpm_admin_css( $hook_suffix ) {
        // Only output the css on PMW pages and the order page
        if ( !(strpos( $hook_suffix, 'page_wpm' ) || Helpers::is_orders_page() || Helpers::is_edit_order_page() || Helpers::is_dashboard()) ) {
            return;
        }
        wp_enqueue_style(
            'wpm-admin',
            PMW_PLUGIN_DIR_PATH . 'css/admin.css',
            [],
            PMW_CURRENT_VERSION
        );
    }

    public function wpm_admin_scripts( $hook_suffix ) {
        // Only output the remaining scripts on PMW settings page
        if ( !strpos( $hook_suffix, 'page_wpm' ) ) {
            return;
        }
        wp_enqueue_script(
            'wpm-admin',
            PMW_PLUGIN_DIR_PATH . 'js/admin/wpm-admin.p1.min.js',
            ['jquery'],
            PMW_CURRENT_VERSION,
            false
        );
        wp_localize_script( 'wpm-admin', 'pmwAdminApi', [
            'root'  => esc_url_raw( rest_url() ),
            'nonce' => wp_create_nonce( 'wp_rest' ),
        ] );
        //        wp_enqueue_script('wpm-script-blocker-warning', WPM_PLUGIN_DIR_PATH . 'js/admin/script-blocker-warning.js', ['jquery'], WPM_CURRENT_VERSION, false);
        //        wp_enqueue_script('wpm-admin-helpers', WPM_PLUGIN_DIR_PATH . 'js/admin/helpers.js', ['jquery'], WPM_CURRENT_VERSION, false);
        //        wp_enqueue_script('wpm-admin-tabs', WPM_PLUGIN_DIR_PATH . 'js/admin/tabs.js', ['jquery'], WPM_CURRENT_VERSION, false);
        wp_enqueue_script(
            'wpm-selectWoo',
            PMW_PLUGIN_DIR_PATH . 'js/admin/selectWoo.full.min.js',
            ['jquery'],
            PMW_CURRENT_VERSION,
            false
        );
        wp_enqueue_style(
            'wpm-selectWoo',
            PMW_PLUGIN_DIR_PATH . 'css/selectWoo.min.css',
            [],
            PMW_CURRENT_VERSION
        );
        // enqueue https://fast.wistia.com/assets/external/E-v1.js
        wp_enqueue_script(
            'wistia',
            'https://fast.wistia.com/assets/external/E-v1.js',
            [],
            PMW_CURRENT_VERSION,
            false
        );
    }

    public function pmw_edit_order_scripts( $hook_suffix ) {
        //		error_log('hook_suffix: ' . $hook_suffix);
        //		error_log('get_current_screen: ' . get_current_screen()->id);
        //		error_log('pmw_edit_order_scripts');
        // Only output the remaining scripts on PMW settings page
        if ( !Helpers::is_edit_order_page() ) {
            return;
        }
        return;
        if ( !Options::is_ga4_data_api_active() ) {
            return;
        }
        wp_enqueue_script(
            'pmw-edit-order',
            PMW_PLUGIN_DIR_PATH . 'js/admin/edit-order-page.js',
            ['jquery'],
            PMW_CURRENT_VERSION,
            false
        );
        wp_localize_script( 'pmw-edit-order', 'pmwAdminApi', [
            'root'  => esc_url_raw( rest_url() ),
            'nonce' => wp_create_nonce( 'wp_rest' ),
        ] );
    }

    // Load text domain function
    public function load_plugin_textdomain() {
        load_plugin_textdomain( 'woocommerce-google-adwords-conversion-tracking-tag', false, dirname( plugin_basename( __FILE__ ) ) . '/languages/' );
    }

    // add the admin options page
    public function plugin_admin_add_page() {
        //add_options_page('WPM Plugin Page', 'WPM Plugin Menu', 'manage_options', 'wpm', array($this, 'wpm_plugin_options_page'));
        add_submenu_page(
            $this->get_submenu_parent_slug(),
            esc_html__( 'Pixel Manager', 'woocommerce-google-adwords-conversion-tracking-tag' ),
            esc_html__( 'Pixel Manager', 'woocommerce-google-adwords-conversion-tracking-tag' ),
            Environment::get_user_edit_capability(),
            'wpm',
            [$this, 'plugin_options_page']
        );
    }

    private function get_submenu_parent_slug() {
        if ( Environment::is_woocommerce_active() ) {
            return 'woocommerce';
        } else {
            return 'options-general.php';
        }
    }

    // add the admin settings and such
    public function plugin_admin_init() {
        register_setting( 'wpm_plugin_options_group', 'wgact_plugin_options', ['\\SweetCode\\Pixel_Manager\\Admin\\Validations', 'options_validate'] );
        // don't load the UX if we are not on the plugin UX page
        if ( !$this->if_is_wpm_admin_page() ) {
            return;
        }
        $this->add_section_main();
        $this->add_section_advanced();
        if ( Environment::is_woocommerce_active() ) {
            $this->add_section_shop();
        }
        self::add_section_consent_management();
        self::add_section_opportunities();
        if ( Environment::is_woocommerce_active() ) {
            $this->add_section_diagnostics();
        }
        self::add_section_support();
        self::add_section_logs();
    }

    public function add_section_main() {
        $section_ids = [
            'title'         => esc_html__( 'Main', 'woocommerce-google-adwords-conversion-tracking-tag' ),
            'slug'          => 'main',
            'settings_name' => 'wpm_plugin_main_section',
        ];
        self::output_section_data_field( $section_ids );
        add_settings_section(
            $section_ids['settings_name'],
            esc_html__( $section_ids['title'], 'woocommerce-google-adwords-conversion-tracking-tag' ),
            [$this, 'plugin_section_main_description'],
            'wpm_plugin_options_page'
        );
        $this->add_section_main_subsection_marketing( $section_ids );
        $this->add_section_main_subsection_statistics( $section_ids );
        // pro version
        if ( wpm_fs()->can_use_premium_code__premium_only() || Options::pro_version_demo_active() ) {
            $this->add_section_main_subsection_optimization( $section_ids );
        }
    }

    public static function add_subsection_div( $section_ids, $sub_section_ids ) {
        add_settings_field(
            'wpm_plugin_subsection_' . $sub_section_ids['slug'] . '_opening_div',
            esc_html__( $sub_section_ids['title'], 'woocommerce-google-adwords-conversion-tracking-tag' ),
            function () use($section_ids, $sub_section_ids) {
                self::subsection_generic_opening_div_html( $section_ids, $sub_section_ids );
            },
            'wpm_plugin_options_page',
            $section_ids['settings_name']
        );
    }

    public function add_section_main_subsection_statistics( $section_ids ) {
        /**
         * Set up the subsection
         */
        // configuration
        $sub_section_ids = [
            'title' => esc_html__( 'Statistics', 'woocommerce-google-adwords-conversion-tracking-tag' ),
            'slug'  => 'statistics',
        ];
        // add the subsection div
        self::add_subsection_div( $section_ids, $sub_section_ids );
        /**
         * Add the settings fields
         */
        add_settings_field(
            'wpm_plugin_analytics_4_measurement_id',
            esc_html__( 'Google Analytics 4', 'woocommerce-google-adwords-conversion-tracking-tag' ),
            [$this, 'option_html_google_analytics_4_id'],
            'wpm_plugin_options_page',
            $section_ids['settings_name']
        );
        // add the field for the Hotjar pixel
        add_settings_field(
            'wpm_plugin_hotjar_site_id',
            esc_html__( 'Hotjar site ID', 'woocommerce-google-adwords-conversion-tracking-tag' ),
            [$this, 'option_html_hotjar_site_id'],
            'wpm_plugin_options_page',
            $section_ids['settings_name']
        );
    }

    public function add_section_main_subsection_marketing( $section_ids ) {
        /**
         * Set up the subsection
         */
        // configuration
        $sub_section_ids = [
            'title' => esc_html__( 'Marketing', 'woocommerce-google-adwords-conversion-tracking-tag' ),
            'slug'  => 'marketing',
        ];
        // add the subsection div
        self::add_subsection_div( $section_ids, $sub_section_ids );
        /**
         * Add the settings fields
         */
        // add the field for the Google Ads conversion id
        add_settings_field(
            'wpm_plugin_conversion_id',
            esc_html__( 'Google Ads Conversion ID', 'woocommerce-google-adwords-conversion-tracking-tag' ),
            [$this, 'option_html_google_ads_conversion_id'],
            'wpm_plugin_options_page',
            $section_ids['settings_name']
        );
        if ( Environment::is_woocommerce_active() ) {
            // add the field for the conversion label
            add_settings_field(
                'wpm_plugin_conversion_label',
                esc_html__( 'Google Ads Purchase Conversion Label', 'woocommerce-google-adwords-conversion-tracking-tag' ),
                [$this, 'option_html_google_ads_conversion_label'],
                'wpm_plugin_options_page',
                $section_ids['settings_name']
            );
        }
        // add the field for the conversion label
        add_settings_field(
            'wpm_plugin_facebook_pixel_id',
            esc_html__( 'Meta (Facebook) pixel ID', 'woocommerce-google-adwords-conversion-tracking-tag' ),
            [$this, 'option_html_facebook_pixel_id'],
            'wpm_plugin_options_page',
            $section_ids['settings_name']
        );
        /**
         * Pro version only
         */
        if ( wpm_fs()->can_use_premium_code__premium_only() || Options::pro_version_demo_active() ) {
            if ( Helpers::is_experiment() ) {
                // Add the field for the Adroll advertiser ID
                add_settings_field(
                    'pmw_adroll_advertiser_id',
                    esc_html__( 'Adroll advertiser ID', 'woocommerce-google-adwords-conversion-tracking-tag' ) . $this->html_beta(),
                    [$this, 'option_html_adroll_advertiser_id'],
                    'wpm_plugin_options_page',
                    $section_ids['settings_name']
                );
                // Add the field for the Adroll pixel ID
                add_settings_field(
                    'pmw_adroll_pixel_id',
                    esc_html__( 'Adroll pixel ID', 'woocommerce-google-adwords-conversion-tracking-tag' ) . $this->html_beta(),
                    [$this, 'option_html_adroll_pixel_id'],
                    'wpm_plugin_options_page',
                    $section_ids['settings_name']
                );
            }
            // Add the field for the LinkedIn partner ID
            add_settings_field(
                'pmw_linkedin_partner_id',
                esc_html__( 'LinkedIn partner ID', 'woocommerce-google-adwords-conversion-tracking-tag' ) . $this->html_beta(),
                [$this, 'option_html_linkedin_partner_id'],
                'wpm_plugin_options_page',
                $section_ids['settings_name']
            );
            // add the field for the Bing Ads UET tag ID
            add_settings_field(
                'wpm_plugin_bing_uet_tag_id',
                esc_html__( 'Microsoft Advertising UET tag ID', 'woocommerce-google-adwords-conversion-tracking-tag' ),
                [$this, 'option_html_bing_uet_tag_id'],
                'wpm_plugin_options_page',
                $section_ids['settings_name']
            );
            if ( Helpers::is_experiment() ) {
                // add the field for the Outbrain pixel
                add_settings_field(
                    'pmw_plugin_outbrain_advertiser_id',
                    esc_html__( 'Outbrain advertiser ID', 'woocommerce-google-adwords-conversion-tracking-tag' ) . $this->html_beta(),
                    [$this, 'option_html_outbrain_advertiser_id'],
                    'wpm_plugin_options_page',
                    $section_ids['settings_name']
                );
            }
            // Add the field for the Pinterest pixel
            add_settings_field(
                'pmw_plugin_pinterest_pixel_id',
                esc_html__( 'Pinterest pixel ID', 'woocommerce-google-adwords-conversion-tracking-tag' ),
                [$this, 'option_html_pinterest_pixel_id'],
                'wpm_plugin_options_page',
                $section_ids['settings_name']
            );
            // Add the field for the Reddit Ads pixel
            add_settings_field(
                'plugin_reddit_pixel_id',
                esc_html__( 'Reddit pixel ID', 'woocommerce-google-adwords-conversion-tracking-tag' ) . $this->html_beta(),
                [$this, 'option_html_reddit_pixel_id'],
                'wpm_plugin_options_page',
                $section_ids['settings_name']
            );
            // add the field for the Snapchat pixel
            add_settings_field(
                'wpm_plugin_snapchat_pixel_id',
                esc_html__( 'Snapchat pixel ID', 'woocommerce-google-adwords-conversion-tracking-tag' ),
                [$this, 'option_html_snapchat_pixel_id'],
                'wpm_plugin_options_page',
                $section_ids['settings_name']
            );
            // Add the field for the Taboola pixel
            add_settings_field(
                'pmw_plugin_taboola_account_id',
                esc_html__( 'Taboola account ID', 'woocommerce-google-adwords-conversion-tracking-tag' ) . $this->html_beta(),
                [$this, 'option_html_taboola_account_id'],
                'wpm_plugin_options_page',
                $section_ids['settings_name']
            );
            // add the field for the TikTok pixel
            add_settings_field(
                'wpm_plugin_tiktok_pixel_id',
                esc_html__( 'TikTok pixel ID', 'woocommerce-google-adwords-conversion-tracking-tag' ),
                [$this, 'option_html_tiktok_pixel_id'],
                'wpm_plugin_options_page',
                $section_ids['settings_name']
            );
            // add the field for the Twitter pixel
            add_settings_field(
                'wpm_plugin_twitter_pixel_id',
                esc_html__( 'Twitter pixel ID', 'woocommerce-google-adwords-conversion-tracking-tag' ),
                [$this, 'option_html_twitter_pixel_id'],
                'wpm_plugin_options_page',
                $section_ids['settings_name']
            );
        }
    }

    public function add_section_main_subsection_optimization( $section_ids ) {
        /**
         * Set up the subsection
         */
        // configuration
        $sub_section_ids = [
            'title' => esc_html__( 'Optimization', 'woocommerce-google-adwords-conversion-tracking-tag' ),
            'slug'  => 'optimization',
        ];
        // add the subsection div
        self::add_subsection_div( $section_ids, $sub_section_ids );
        /**
         * Add the settings fields
         */
        if ( Helpers::is_experiment() ) {
            add_settings_field(
                'pmw_plugin_ab_tasty_account_id',
                esc_html__( 'AB Tasty', 'woocommerce-google-adwords-conversion-tracking-tag' ) . $this->html_beta(),
                [$this, 'option_html_ab_tasty_account_id'],
                'wpm_plugin_options_page',
                $section_ids['settings_name']
            );
            add_settings_field(
                'pmw_plugin_optimizely_project_id',
                esc_html__( 'Optimizely', 'woocommerce-google-adwords-conversion-tracking-tag' ) . $this->html_beta(),
                [$this, 'option_html_optimizely_project_id'],
                'wpm_plugin_options_page',
                $section_ids['settings_name']
            );
        }
        add_settings_field(
            'pmw_plugin_vwo_account_id',
            esc_html__( 'VWO', 'woocommerce-google-adwords-conversion-tracking-tag' ) . $this->html_beta(),
            [$this, 'option_html_vwo_account_id'],
            'wpm_plugin_options_page',
            $section_ids['settings_name']
        );
    }

    public function add_section_advanced() {
        $section_ids = [
            'title'         => esc_html__( 'Advanced', 'woocommerce-google-adwords-conversion-tracking-tag' ),
            'slug'          => 'advanced',
            'settings_name' => 'wpm_plugin_advanced_section',
        ];
        add_settings_section(
            $section_ids['settings_name'],
            esc_html__( $section_ids['title'], 'woocommerce-google-adwords-conversion-tracking-tag' ),
            [$this, 'plugin_section_advanced_description'],
            'wpm_plugin_options_page'
        );
        self::output_section_data_field( $section_ids );
        $this->add_section_advanced_subsection_general( $section_ids );
        $this->add_section_advanced_subsection_google( $section_ids );
        if ( wpm_fs()->can_use_premium_code__premium_only() || Options::pro_version_demo_active() ) {
            $this->add_section_advanced_subsection_facebook( $section_ids );
            if ( Environment::is_woocommerce_active() ) {
                $this->add_section_advanced_subsection_bing( $section_ids );
                $this->add_section_advanced_subsection_linkedin( $section_ids );
            }
            $this->add_section_advanced_subsection_pinterest( $section_ids );
            $this->add_section_advanced_subsection_snapchat( $section_ids );
            $this->add_section_advanced_subsection_reddit( $section_ids );
            $this->add_section_advanced_subsection_tiktok( $section_ids );
            if ( Environment::is_woocommerce_active() ) {
                $this->add_section_advanced_subsection_twitter( $section_ids );
            }
        }
    }

    public function add_section_advanced_subsection_general( $section_ids ) {
        $sub_section_ids = [
            'title' => 'General',
            'slug'  => 'general',
        ];
        self::add_subsection_div( $section_ids, $sub_section_ids );
        if ( wpm_fs()->can_use_premium_code__premium_only() || Options::pro_version_demo_active() ) {
            // add checkbox for disabling tracking for user roles
            add_settings_field(
                'wpm_setting_disable_tracking_for_user_roles',
                esc_html__( 'Disable Tracking for User Roles', 'woocommerce-google-adwords-conversion-tracking-tag' ),
                [$this, 'setting_html_disable_tracking_for_user_roles'],
                'wpm_plugin_options_page',
                $section_ids['settings_name']
            );
            // Add checkbox for the scroll tracker
            add_settings_field(
                'pmw_setting_scroll_tracker_thresholds',
                esc_html__( 'Scroll Tracker', 'woocommerce-google-adwords-conversion-tracking-tag' ),
                [$this, 'info_html_scroll_tracker_thresholds'],
                'wpm_plugin_options_page',
                $section_ids['settings_name']
            );
            // Add option for lazy loading PMW
            add_settings_field(
                'pmw_setting_lazy_load_pmw',
                esc_html__( 'Lazy Load PMW', 'woocommerce-google-adwords-conversion-tracking-tag' ),
                [$this, 'html_lazy_load_pmw'],
                'wpm_plugin_options_page',
                $section_ids['settings_name']
            );
        }
        // add checkbox for maximum compatibility mode
        add_settings_field(
            'wpm_setting_maximum_compatibility_mode',
            esc_html__( 'Maximum Compatibility Mode', 'woocommerce-google-adwords-conversion-tracking-tag' ),
            [$this, 'setting_html_maximum_compatibility_mode'],
            'wpm_plugin_options_page',
            $section_ids['settings_name']
        );
    }

    public function add_section_shop() {
        $section_ids = [
            'title'         => esc_html__( 'Shop', 'woocommerce-google-adwords-conversion-tracking-tag' ),
            'slug'          => 'shop',
            'settings_name' => 'wpm_plugin_shop_section',
        ];
        self::output_section_data_field( $section_ids );
        add_settings_section(
            'wpm_plugin_shop_section',
            esc_html__( 'Shop', 'woocommerce-google-adwords-conversion-tracking-tag' ),
            [$this, 'plugin_section_shop_html'],
            'wpm_plugin_options_page'
        );
        // add fields for the marketing value logic
        add_settings_field(
            'pmw_plugin_marketing_value_logic',
            esc_html__( 'Marketing Value Logic', 'woocommerce-google-adwords-conversion-tracking-tag' ) . $this->get_documentation_html_e( Documentation::get_link( 'marketing_value_logic' ) ),
            [$this, 'html_marketing_value_logic'],
            'wpm_plugin_options_page',
            $section_ids['settings_name']
        );
        // Add info about dynamic remarketing
        add_settings_field(
            'wpm_plugin_option_dynamic_remarketing',
            esc_html__( 'Dynamic Remarketing', 'woocommerce-google-adwords-conversion-tracking-tag' ),
            [$this, 'option_html_dynamic_remarketing'],
            'wpm_plugin_options_page',
            $section_ids['settings_name']
        );
        // add fields for the product identifier
        add_settings_field(
            'wpm_plugin_option_product_identifier',
            esc_html__( 'Product Identifier', 'woocommerce-google-adwords-conversion-tracking-tag' ),
            [$this, 'plugin_option_product_identifier'],
            'wpm_plugin_options_page',
            $section_ids['settings_name']
        );
        // add checkbox for variations output
        add_settings_field(
            'wpm_plugin_option_variations_output',
            esc_html__( 'Variations output', 'woocommerce-google-adwords-conversion-tracking-tag' ),
            [$this, 'option_html_variations_output'],
            'wpm_plugin_options_page',
            $section_ids['settings_name']
        );
        if ( wpm_fs()->can_use_premium_code__premium_only() || Options::pro_version_demo_active() ) {
            // google_business_vertical
            add_settings_field(
                'wpm_plugin_google_business_vertical',
                esc_html__( 'Google Business Vertical', 'woocommerce-google-adwords-conversion-tracking-tag' ),
                [$this, 'plugin_option_google_business_vertical'],
                'wpm_plugin_options_page',
                $section_ids['settings_name']
            );
        }
        // add checkbox for order duplication prevention
        add_settings_field(
            'wpm_setting_order_duplication_prevention',
            esc_html__( 'Order Duplication Prevention', 'woocommerce-google-adwords-conversion-tracking-tag' ),
            [$this, 'setting_html_order_duplication_prevention'],
            'wpm_plugin_options_page',
            $section_ids['settings_name']
        );
        if ( wpm_fs()->can_use_premium_code__premium_only() || Options::pro_version_demo_active() ) {
            // add info for ACR
            add_settings_field(
                'wpm_setting_acr',
                esc_html__( 'ACR', 'woocommerce-google-adwords-conversion-tracking-tag' ),
                [$this, 'info_html_acr'],
                'wpm_plugin_options_page',
                $section_ids['settings_name']
            );
        }
        // add checkbox for order list info
        add_settings_field(
            'pmw_setting_order_list_info',
            esc_html__( 'Order List Info', 'woocommerce-google-adwords-conversion-tracking-tag' ),
            [$this, 'info_html_order_list_info'],
            'wpm_plugin_options_page',
            $section_ids['settings_name']
        );
        if ( wpm_fs()->can_use_premium_code__premium_only() || Options::pro_version_demo_active() ) {
            // Add a subscription value multiplier
            add_settings_field(
                'pmw_setting_subscription_value_multiplier',
                esc_html__( 'Subscription Value Multiplier', 'woocommerce-google-adwords-conversion-tracking-tag' ),
                [$this, 'html_subscription_value_multiplier'],
                'wpm_plugin_options_page',
                $section_ids['settings_name']
            );
        }
        // Add a button to enable the lifetime calculation on orders
        add_settings_field(
            'pmw_setting_ltv_on_orders',
            esc_html__( 'Lifetime Value Calculation on Orders', 'woocommerce-google-adwords-conversion-tracking-tag' ) . $this->html_beta(),
            [$this, 'html_ltv_calculation_on_orders'],
            'wpm_plugin_options_page',
            $section_ids['settings_name']
        );
        if ( Helpers::is_experiment() ) {
            // Add a button to enable the automatic lifetime value recalculation
            add_settings_field(
                'pmw_setting_ltv_automatic_recalculation',
                esc_html__( 'Automatic Lifetime Value Recalculation', 'woocommerce-google-adwords-conversion-tracking-tag' ) . $this->html_experiment(),
                [$this, 'html_ltv_automatic_recalculation'],
                'wpm_plugin_options_page',
                $section_ids['settings_name']
            );
        }
        // Add a button to schedule a lifetime value recalculation
        add_settings_field(
            'pmw_setting_ltv_manual_recalculation',
            esc_html__( 'Manual Lifetime Value Recalculation', 'woocommerce-google-adwords-conversion-tracking-tag' ) . $this->html_beta(),
            [$this, 'ltv_manual_recalculation'],
            'wpm_plugin_options_page',
            $section_ids['settings_name']
        );
    }

    public function add_section_advanced_subsection_google( $section_ids ) {
        $sub_section_ids = [
            'title' => 'Google',
            'slug'  => 'google',
        ];
        self::add_subsection_div( $section_ids, $sub_section_ids );
        if ( Environment::is_woocommerce_active() ) {
            // add the field for the aw_merchant_id
            add_settings_field(
                'wpm_plugin_aw_merchant_id',
                esc_html__( 'Conversion Cart Data', 'woocommerce-google-adwords-conversion-tracking-tag' ),
                [$this, 'plugin_setting_aw_merchant_id'],
                'wpm_plugin_options_page',
                $section_ids['settings_name']
            );
            // add fields for the Google enhanced e-commerce
            add_settings_field(
                'wpm_setting_google_analytics_eec',
                esc_html__( 'Enhanced E-Commerce', 'woocommerce-google-adwords-conversion-tracking-tag' ),
                [$this, 'info_html_google_analytics_eec'],
                'wpm_plugin_options_page',
                $section_ids['settings_name']
            );
            if ( wpm_fs()->can_use_premium_code__premium_only() || Options::pro_version_demo_active() ) {
                // add fields for the Google GA4 API secret
                add_settings_field(
                    'wpm_setting_google_analytics_4_api_secret',
                    esc_html__( 'GA4 API secret', 'woocommerce-google-adwords-conversion-tracking-tag' ) . $this->html_beta(),
                    [$this, 'setting_html_google_analytics_4_api_secret'],
                    'wpm_plugin_options_page',
                    $section_ids['settings_name']
                );
                // Add fields for the GA4 Data API property ID
                add_settings_field(
                    'pmw_setting_ga4_property_id',
                    esc_html__( 'GA4 Property ID', 'woocommerce-google-adwords-conversion-tracking-tag' ),
                    [$this, 'setting_html_ga4_property_id'],
                    'wpm_plugin_options_page',
                    $section_ids['settings_name']
                );
                // Add fields for the GA4 Data API credentials upload
                add_settings_field(
                    'pmw_setting_ga4_data_api_credentials',
                    esc_html__( 'GA4 Data API Credentials', 'woocommerce-google-adwords-conversion-tracking-tag' ),
                    [$this, 'setting_html_g4_data_api_credentials'],
                    'wpm_plugin_options_page',
                    $section_ids['settings_name']
                );
            }
        }
        if ( wpm_fs()->can_use_premium_code__premium_only() || Options::pro_version_demo_active() ) {
            // Add fields for the GA4 Page Load Time Tracking
            add_settings_field(
                'pmw_setting_ga4_page_load_time_tracking',
                esc_html__( 'GA4 Page Load Time Tracking', 'woocommerce-google-adwords-conversion-tracking-tag' ),
                [$this, 'setting_html_g4_page_load_time_tracking'],
                'wpm_plugin_options_page',
                $section_ids['settings_name']
            );
        }
        if ( wpm_fs()->can_use_premium_code__premium_only() || Options::pro_version_demo_active() ) {
            // add user_id for the Google
            add_settings_field(
                'wpm_setting_google_user_id',
                esc_html__( 'Google User ID', 'woocommerce-google-adwords-conversion-tracking-tag' ),
                [$this, 'setting_html_google_user_id'],
                'wpm_plugin_options_page',
                $section_ids['settings_name']
            );
            if ( Environment::is_woocommerce_active() ) {
                // Add Google Enhanced Conversions
                add_settings_field(
                    'pmw_setting_google_enhanced_conversions',
                    esc_html__( 'Google Enhanced Conversions', 'woocommerce-google-adwords-conversion-tracking-tag' ),
                    [$this, 'setting_html_google_enhanced_conversions'],
                    'wpm_plugin_options_page',
                    $section_ids['settings_name']
                );
            }
        }
        if ( wpm_fs()->can_use_premium_code__premium_only() || Options::pro_version_demo_active() ) {
            // add fields for the Google Ads phone conversion number
            add_settings_field(
                'wpm_plugin_google_ads_phone_conversion_number',
                esc_html__( 'Google Ads Phone Conversion Number', 'woocommerce-google-adwords-conversion-tracking-tag' ),
                [$this, 'setting_html_google_ads_phone_conversion_number'],
                'wpm_plugin_options_page',
                $section_ids['settings_name']
            );
            // add fields for the Google Ads phone conversion label
            add_settings_field(
                'wpm_plugin_google_ads_phone_conversion_label',
                esc_html__( 'Google Ads Phone Conversion Label', 'woocommerce-google-adwords-conversion-tracking-tag' ),
                [$this, 'setting_html_google_ads_phone_conversion_label'],
                'wpm_plugin_options_page',
                $section_ids['settings_name']
            );
            if ( Environment::is_woocommerce_active() ) {
                // add fields for the Google Ads Conversion Adjustments Conversion Name
                add_settings_field(
                    'pmw_plugin_google_ads_conversion_adjustments_conversion_name',
                    esc_html__( 'Google Ads Conversion Adjustments: Conversion Name', 'woocommerce-google-adwords-conversion-tracking-tag' ),
                    [$this, 'setting_html_google_ads_conversion_adjustments_conversion_name'],
                    'wpm_plugin_options_page',
                    $section_ids['settings_name']
                );
                // add fields for the Google Ads Conversion Adjustments Feed
                add_settings_field(
                    'pmw_plugin_google_ads_conversion_adjustments_feed',
                    esc_html__( 'Google Ads Conversion Adjustments: Feed', 'woocommerce-google-adwords-conversion-tracking-tag' ),
                    [$this, 'setting_html_google_ads_conversion_adjustments_feed'],
                    'wpm_plugin_options_page',
                    $section_ids['settings_name']
                );
            }
        }
        if ( wpm_fs()->can_use_premium_code__premium_only() || Options::pro_version_demo_active() ) {
            // add info for automatic email link click tracking
            add_settings_field(
                'pmw_info_automatic_email_link_tracking',
                esc_html__( 'Automatic Email Link Tracking', 'woocommerce-google-adwords-conversion-tracking-tag' ),
                [$this, 'info_html_automatic_email_link_tracking'],
                'wpm_plugin_options_page',
                $section_ids['settings_name']
            );
            // add info for automatic phone link click tracking
            add_settings_field(
                'pmw_info_automatic_phone_link_click_tracking',
                esc_html__( 'Automatic Phone Link Click Tracking', 'woocommerce-google-adwords-conversion-tracking-tag' ),
                [$this, 'info_html_automatic_phone_link_click_tracking'],
                'wpm_plugin_options_page',
                $section_ids['settings_name']
            );
        }
    }

    public function add_section_advanced_subsection_facebook( $section_ids ) {
        $sub_section_ids = [
            'title' => 'Meta (Facebook)',
            'slug'  => 'facebook',
        ];
        self::add_subsection_div( $section_ids, $sub_section_ids );
        // add field for the Facebook CAPI token
        add_settings_field(
            'wpm_setting_facebook_capi_token',
            esc_html__( 'Meta (Facebook) CAPI: token', 'woocommerce-google-adwords-conversion-tracking-tag' ),
            [$this, 'setting_html_facebook_capi_token'],
            'wpm_plugin_options_page',
            $section_ids['settings_name']
        );
        // Add field for the Facebook CAPI test event code
        add_settings_field(
            'pmw_setting_facebook_capi_test_event_code',
            esc_html__( 'Meta (Facebook) CAPI: test event code', 'woocommerce-google-adwords-conversion-tracking-tag' ),
            [$this, 'setting_html_facebook_capi_test_event_code'],
            'wpm_plugin_options_page',
            $section_ids['settings_name']
        );
        // add field for the Facebook CAPI user transparency process anonymous hits
        add_settings_field(
            'wpm_setting_facebook_capi_user_transparency_process_anonymous_hits',
            esc_html__( 'Meta (Facebook) CAPI: process anonymous hits', 'woocommerce-google-adwords-conversion-tracking-tag' ),
            [$this, 'setting_facebook_capi_user_transparency_process_anonymous_hits'],
            'wpm_plugin_options_page',
            $section_ids['settings_name']
        );
        // add field for the Facebook CAPI user transparency send additional client identifiers
        add_settings_field(
            'wpm_setting_facebook_capi_user_transparency_send_additional_client_identifiers',
            esc_html__( 'Meta (Facebook): Advanced Matching', 'woocommerce-google-adwords-conversion-tracking-tag' ),
            [$this, 'setting_facebook_advanced_matching'],
            'wpm_plugin_options_page',
            $section_ids['settings_name']
        );
        // add field for the domain verification meta tag
        add_settings_field(
            'wpm_setting_facebook_domain_verification_meta_tag_id',
            esc_html__( 'Meta (Facebook): Domain Verification Meta Tag ID', 'woocommerce-google-adwords-conversion-tracking-tag' ) . $this->html_beta(),
            [$this, 'setting_html_facebook_domain_verification_id'],
            'wpm_plugin_options_page',
            $section_ids['settings_name']
        );
        // This feature is deprecated since 1.25.1
        // We keep it active for users who are currently using it.
        if ( Options::is_facebook_microdata_active() ) {
            // add fields for Facebook microdata
            add_settings_field(
                'wpm_setting_facebook_microdata_active',
                esc_html__( 'Meta (Facebook) Microdata Tags for Catalogues', 'woocommerce-google-adwords-conversion-tracking-tag' ),
                [$this, 'setting_html_facebook_microdata'],
                'wpm_plugin_options_page',
                $section_ids['settings_name']
            );
        }
    }

    public function add_section_advanced_subsection_pinterest( $section_ids ) {
        $sub_section_ids = [
            'title' => 'Pinterest',
            'slug'  => 'pinterest',
        ];
        self::add_subsection_div( $section_ids, $sub_section_ids );
        // Add field for the Pinterest ad account ID token
        add_settings_field(
            'pmw_setting_pinterest_ad_account_id',
            esc_html__( 'Pinterest Ad Account ID', 'woocommerce-google-adwords-conversion-tracking-tag' ) . $this->html_beta(),
            [$this, 'setting_html_pinterest_ad_account_id'],
            'wpm_plugin_options_page',
            $section_ids['settings_name']
        );
        // Add field for the Pinterest APIC token
        add_settings_field(
            'pmw_setting_pinterest_apic_token',
            esc_html__( 'Pinterest Events API: token', 'woocommerce-google-adwords-conversion-tracking-tag' ) . $this->html_beta(),
            [$this, 'setting_html_pinterest_apic_token'],
            'wpm_plugin_options_page',
            $section_ids['settings_name']
        );
        // add field for the Pinterest APIC user transparency process anonymous hits
        add_settings_field(
            'pmw_setting_pinterest_apic_user_transparency_process_anonymous_hits',
            esc_html__( 'Pinterest Events API: process anonymous hits', 'woocommerce-google-adwords-conversion-tracking-tag' ) . $this->html_beta(),
            [$this, 'setting_pinterest_apic_process_anonymous_hits'],
            'wpm_plugin_options_page',
            $section_ids['settings_name']
        );
        // add the field for the Pinterest enhanced match
        add_settings_field(
            'wpm_plugin_pinterest_enhanced_match',
            esc_html__( 'Pinterest Enhanced Match', 'woocommerce-google-adwords-conversion-tracking-tag' ),
            [$this, 'option_html_pinterest_enhanced_match'],
            'wpm_plugin_options_page',
            $section_ids['settings_name']
        );
        // add field for the Pinterest Advanced Matching
        add_settings_field(
            'pmw_setting_pinterest_user_transparency_advanced_matching',
            esc_html__( 'Pinterest: Advanced Matching', 'woocommerce-google-adwords-conversion-tracking-tag' ) . $this->html_beta(),
            [$this, 'setting_pinterest_advanced_matching'],
            'wpm_plugin_options_page',
            $section_ids['settings_name']
        );
    }

    public function add_section_advanced_subsection_snapchat( $section_ids ) {
        $sub_section_ids = [
            'title' => 'Snapchat',
            'slug'  => 'snapchat',
        ];
        self::add_subsection_div( $section_ids, $sub_section_ids );
        // Add the field for the Snapchat CAPI token
        add_settings_field(
            'plugin_snapchat_capi_token',
            esc_html__( 'Snapchat CAPI Token', 'woocommerce-google-adwords-conversion-tracking-tag' ) . $this->html_beta(),
            [$this, 'option_html_snapchat_capi_token'],
            'wpm_plugin_options_page',
            $section_ids['settings_name']
        );
        // Add the field for the Snapchat advanced matching
        add_settings_field(
            'plugin_snapchat_advanced_matching',
            esc_html__( 'Snapchat Advanced Matching', 'woocommerce-google-adwords-conversion-tracking-tag' ),
            [$this, 'option_html_snapchat_advanced_matching'],
            'wpm_plugin_options_page',
            $section_ids['settings_name']
        );
    }

    public function add_section_advanced_subsection_reddit( $section_ids ) {
        $sub_section_ids = [
            'title' => 'Reddit',
            'slug'  => 'reddit',
        ];
        self::add_subsection_div( $section_ids, $sub_section_ids );
        // Add the field for the Reddit advanced matching
        add_settings_field(
            'plugin_reddit_advanced_matching',
            esc_html__( 'Reddit Advanced Matching', 'woocommerce-google-adwords-conversion-tracking-tag' ),
            [$this, 'option_html_reddit_advanced_matching'],
            'wpm_plugin_options_page',
            $section_ids['settings_name']
        );
    }

    public function add_section_advanced_subsection_tiktok( $section_ids ) {
        $sub_section_ids = [
            'title' => 'TikTok',
            'slug'  => 'tiktok',
        ];
        self::add_subsection_div( $section_ids, $sub_section_ids );
        // Add field for the TikTok Events API token
        add_settings_field(
            'pmw_setting_tiktok_eapi_token',
            esc_html__( 'TikTok Events API: token', 'woocommerce-google-adwords-conversion-tracking-tag' ),
            [$this, 'setting_html_tiktok_eapi_token'],
            'wpm_plugin_options_page',
            $section_ids['settings_name']
        );
        // Add field for the TikTok EAPI test event code
        add_settings_field(
            'pmw_setting_tiktok_eapi_test_event_code',
            esc_html__( 'TikTok EAPI: test event code', 'woocommerce-google-adwords-conversion-tracking-tag' ),
            [$this, 'setting_html_tiktok_eapi_test_event_code'],
            'wpm_plugin_options_page',
            $section_ids['settings_name']
        );
        // add field for the TikTok EAPI user transparency process anonymous hits
        add_settings_field(
            'pmw_setting_tiktok_eapi_user_transparency_process_anonymous_hits',
            esc_html__( 'TikTok Events API: process anonymous hits', 'woocommerce-google-adwords-conversion-tracking-tag' ),
            [$this, 'setting_tiktok_eapi_process_anonymous_hits'],
            'wpm_plugin_options_page',
            $section_ids['settings_name']
        );
        // add field for the TikTok Advanced Matching
        add_settings_field(
            'pmw_setting_tiktok_eapi_user_transparency_advanced_matching',
            esc_html__( 'TikTok: Advanced Matching', 'woocommerce-google-adwords-conversion-tracking-tag' ),
            [$this, 'setting_tiktok_advanced_matching'],
            'wpm_plugin_options_page',
            $section_ids['settings_name']
        );
    }

    public function add_section_advanced_subsection_bing( $section_ids ) {
        $sub_section_ids = [
            'title' => 'Microsoft',
            'slug'  => 'microsoft',
        ];
        self::add_subsection_div( $section_ids, $sub_section_ids );
        // Add the field for the Microsoft Enhanced Conversions matching
        add_settings_field(
            'plugin_microsoft_enhanced_conversions',
            esc_html__( 'Microsoft Enhanced Conversions', 'woocommerce-google-adwords-conversion-tracking-tag' ),
            [$this, 'option_html_bing_enhanced_conversions'],
            'wpm_plugin_options_page',
            $section_ids['settings_name']
        );
    }

    /**
     * Info:
     * Since the Lead events can be sent using the shortcodes,
     * we don't need to add those ase fields to the advanced settings page.
     *
     * @param $section_ids
     * @return void
     */
    public function add_section_advanced_subsection_linkedin( $section_ids ) {
        // Configuration for the LinkedIn section
        $sub_section_ids = [
            'title' => 'LinkedIn',
            'slug'  => 'linkedin',
        ];
        self::add_subsection_div( $section_ids, $sub_section_ids );
        // Add field for the LinkedIn search event
        add_settings_field(
            'pmw_setting_linkedin_search',
            esc_html__( 'Search event ID', 'woocommerce-google-adwords-conversion-tracking-tag' ) . $this->html_beta(),
            [$this, 'setting_linkedin_search'],
            'wpm_plugin_options_page',
            $section_ids['settings_name']
        );
        // Add field for the LinkedIn view_content event
        add_settings_field(
            'pmw_setting_linkedin_view_content',
            esc_html__( 'View Content event ID', 'woocommerce-google-adwords-conversion-tracking-tag' ) . $this->html_beta(),
            [$this, 'setting_linkedin_view_content'],
            'wpm_plugin_options_page',
            $section_ids['settings_name']
        );
        // Add field for the LinkedIn add_to_list event
        add_settings_field(
            'pmw_setting_linkedin_add_to_list',
            esc_html__( 'Add To List event ID', 'woocommerce-google-adwords-conversion-tracking-tag' ) . $this->html_beta(),
            [$this, 'setting_linkedin_add_to_list'],
            'wpm_plugin_options_page',
            $section_ids['settings_name']
        );
        // Add field for the LinkedIn add_to_cart event
        add_settings_field(
            'pmw_setting_linkedin_add_to_cart',
            esc_html__( 'Add-to-cart event ID', 'woocommerce-google-adwords-conversion-tracking-tag' ) . $this->html_beta(),
            [$this, 'setting_linkedin_add_to_cart'],
            'wpm_plugin_options_page',
            $section_ids['settings_name']
        );
        // Add field for the LinkedIn start_checkout event
        add_settings_field(
            'pmw_setting_linkedin_start_checkout',
            esc_html__( 'Start-checkout event ID', 'woocommerce-google-adwords-conversion-tracking-tag' ) . $this->html_beta(),
            [$this, 'setting_linkedin_start_checkout'],
            'wpm_plugin_options_page',
            $section_ids['settings_name']
        );
        // Add field for the LinkedIn purchase event
        add_settings_field(
            'pmw_setting_linkedin_purchase',
            esc_html__( 'Purchase event ID', 'woocommerce-google-adwords-conversion-tracking-tag' ) . $this->html_beta(),
            [$this, 'setting_linkedin_purchase'],
            'wpm_plugin_options_page',
            $section_ids['settings_name']
        );
    }

    public function add_section_advanced_subsection_twitter( $section_ids ) {
        // configuration for the Twitter section
        $sub_section_ids = [
            'title' => 'Twitter',
            'slug'  => 'twitter',
        ];
        self::add_subsection_div( $section_ids, $sub_section_ids );
        // Add field for the Twitter event add_to_cart
        add_settings_field(
            'pmw_setting_twitter_add_to_cart',
            esc_html__( 'Add To Cart Event ID', 'woocommerce-google-adwords-conversion-tracking-tag' ) . $this->html_beta(),
            [$this, 'setting_twitter_add_to_cart'],
            'wpm_plugin_options_page',
            $section_ids['settings_name']
        );
        // Add field for the Twitter event add_to_wishlist
        add_settings_field(
            'pmw_setting_twitter_add_to_wishlist',
            esc_html__( 'Add To Wishlist Event ID', 'woocommerce-google-adwords-conversion-tracking-tag' ) . $this->html_beta(),
            [$this, 'setting_twitter_add_to_wishlist'],
            'wpm_plugin_options_page',
            $section_ids['settings_name']
        );
        // Add field for the Twitter event view_content
        add_settings_field(
            'pmw_setting_twitter_view_content',
            esc_html__( 'Content View Event ID', 'woocommerce-google-adwords-conversion-tracking-tag' ) . $this->html_beta(),
            [$this, 'setting_twitter_view_content'],
            'wpm_plugin_options_page',
            $section_ids['settings_name']
        );
        // Add field for the Twitter event search
        add_settings_field(
            'pmw_setting_twitter_search',
            esc_html__( 'Search Event ID', 'woocommerce-google-adwords-conversion-tracking-tag' ) . $this->html_beta(),
            [$this, 'setting_twitter_search'],
            'wpm_plugin_options_page',
            $section_ids['settings_name']
        );
        // Add field for the Twitter event initiate_checkout
        add_settings_field(
            'pmw_setting_twitter_initiate_checkout',
            esc_html__( 'Checkout Initiated Event ID', 'woocommerce-google-adwords-conversion-tracking-tag' ) . $this->html_beta(),
            [$this, 'setting_twitter_initiate_checkout'],
            'wpm_plugin_options_page',
            $section_ids['settings_name']
        );
        // Add field for the Twitter event add_payment_info
        add_settings_field(
            'pmw_setting_twitter_add_payment_info',
            esc_html__( 'Add Payment Info Event ID', 'woocommerce-google-adwords-conversion-tracking-tag' ) . $this->html_beta(),
            [$this, 'setting_twitter_add_payment_info'],
            'wpm_plugin_options_page',
            $section_ids['settings_name']
        );
        // Add field for the Twitter event purchase
        add_settings_field(
            'pmw_setting_twitter_purchase',
            esc_html__( 'Purchase Event ID', 'woocommerce-google-adwords-conversion-tracking-tag' ) . $this->html_beta(),
            [$this, 'setting_twitter_purchase'],
            'wpm_plugin_options_page',
            $section_ids['settings_name']
        );
    }

    public static function add_section_consent_management() {
        $section_ids = [
            'title'         => esc_html__( 'Consent Management', 'woocommerce-google-adwords-conversion-tracking-tag' ),
            'slug'          => 'consent-management',
            'settings_name' => 'wpm_plugin_consent_management_section',
        ];
        self::output_section_data_field( $section_ids );
        add_settings_section(
            'wpm_plugin_consent_management_section',
            esc_html__( 'Consent Management', 'woocommerce-google-adwords-conversion-tracking-tag' ),
            [__CLASS__, 'plugin_section_consent_management_html'],
            'wpm_plugin_options_page'
        );
        // Add fields for the Google Consent Mode
        add_settings_field(
            'wpm_setting_google_consent_mode_active',
            esc_html__( 'Google Consent Mode v2', 'woocommerce-google-adwords-conversion-tracking-tag' ),
            [__CLASS__, 'setting_html_google_consent_mode_active'],
            'wpm_plugin_options_page',
            $section_ids['settings_name']
        );
        // add fields for explicit cookie consent mode
        add_settings_field(
            'setting_explicit_consent_mode',
            esc_html__( 'Explicit Consent Mode', 'woocommerce-google-adwords-conversion-tracking-tag' ),
            [__CLASS__, 'setting_html_explicit_consent_mode'],
            'wpm_plugin_options_page',
            $section_ids['settings_name']
        );
        // Add fields for the explicit consent regions
        add_settings_field(
            'setting_explicit_consent_regions',
            esc_html__( 'Explicit Consent Regions', 'woocommerce-google-adwords-conversion-tracking-tag' ),
            [__CLASS__, 'setting_html_explicit_consent_regions'],
            'wpm_plugin_options_page',
            $section_ids['settings_name']
        );
        if ( wpm_fs()->can_use_premium_code__premium_only() || Options::pro_version_demo_active() ) {
            // add fields for the Google TCF support
            add_settings_field(
                'setting_google_tcf_support',
                esc_html__( 'Google TCF Support', 'woocommerce-google-adwords-conversion-tracking-tag' ),
                [__CLASS__, 'setting_html_google_tcf_support'],
                'wpm_plugin_options_page',
                $section_ids['settings_name']
            );
        }
        if ( Environment::is_cookiebot_active() ) {
            // add fields for the Cookiebot support
            add_settings_field(
                'wpm_setting_cookiebot_support',
                esc_html__( 'Cookiebot Support', 'woocommerce-google-adwords-conversion-tracking-tag' ),
                [__CLASS__, 'setting_html_cookiebot_support'],
                'wpm_plugin_options_page',
                $section_ids['settings_name']
            );
        }
        if ( Environment::is_complianz_active() ) {
            // add fields for the Complianz GDPR support
            add_settings_field(
                'wpm_setting_complianz_support',
                esc_html__( 'Complianz GDPR Support', 'woocommerce-google-adwords-conversion-tracking-tag' ),
                [__CLASS__, 'setting_html_complianz_support'],
                'wpm_plugin_options_page',
                $section_ids['settings_name']
            );
        }
        if ( Environment::is_cookie_notice_active() ) {
            // add fields for the Cookie Notice by hu-manity.co support
            add_settings_field(
                'wpm_setting_cookie_notice_support',
                esc_html__( 'Cookie Notice Support', 'woocommerce-google-adwords-conversion-tracking-tag' ),
                [__CLASS__, 'setting_html_cookie_notice_support'],
                'wpm_plugin_options_page',
                $section_ids['settings_name']
            );
        }
        if ( Environment::is_cookie_script_active() ) {
            // add fields for the Cookie Script support
            add_settings_field(
                'wpm_setting_cookie_script_support',
                esc_html__( 'Cookie Script Support', 'woocommerce-google-adwords-conversion-tracking-tag' ),
                [__CLASS__, 'setting_html_cookie_script_support'],
                'wpm_plugin_options_page',
                $section_ids['settings_name']
            );
        }
        if ( Environment::is_moove_gdpr_active() ) {
            // add fields for the GDPR Cookie Compliance support
            add_settings_field(
                'wpm_setting_moove_gdpr_support',
                esc_html__( 'GDPR Cookie Compliance Support', 'woocommerce-google-adwords-conversion-tracking-tag' ),
                [__CLASS__, 'setting_html_moove_gdpr_support'],
                'wpm_plugin_options_page',
                $section_ids['settings_name']
            );
        }
        if ( Environment::is_cookieyes_active() ) {
            // Add fields for the CookieYes support
            add_settings_field(
                'wpm_setting_cookieyes_support',
                esc_html__( 'CookieYes Support', 'woocommerce-google-adwords-conversion-tracking-tag' ),
                [__CLASS__, 'setting_html_cookieyes_support'],
                'wpm_plugin_options_page',
                $section_ids['settings_name']
            );
        }
        if ( Environment::is_termly_active() ) {
            // add fields for the GDPR Cookie Consent support
            add_settings_field(
                'wpm_setting_cookie_law_info_support',
                esc_html__( 'Termly CMP Support', 'woocommerce-google-adwords-conversion-tracking-tag' ),
                [__CLASS__, 'setting_html_termly_support'],
                'wpm_plugin_options_page',
                $section_ids['settings_name']
            );
        }
    }

    // Add a tab for the logs
    public static function add_section_logs() {
        $section_ids = [
            'title'         => esc_html__( 'Logs', 'woocommerce-google-adwords-conversion-tracking-tag' ),
            'slug'          => 'logger',
            'settings_name' => 'wpm_plugin_log_section',
        ];
        self::output_section_data_field( $section_ids );
        // Add section for the logs
        add_settings_section(
            'wpm_plugin_log_section',
            esc_html__( 'Logs', 'woocommerce-google-adwords-conversion-tracking-tag' ),
            [__CLASS__, 'plugin_section_logger'],
            'wpm_plugin_options_page'
        );
        // add checkbox for logger
        add_settings_field(
            'wpm_plugin_option_logger',
            esc_html__( 'Logger', 'woocommerce-google-adwords-conversion-tracking-tag' ),
            [__CLASS__, 'html_logger_activation'],
            'wpm_plugin_options_page',
            'wpm_plugin_log_section'
        );
        // add dropdown for log level
        add_settings_field(
            'wpm_plugin_option_log_level',
            esc_html__( 'Log Level', 'woocommerce-google-adwords-conversion-tracking-tag' ),
            [__CLASS__, 'html_log_level'],
            'wpm_plugin_options_page',
            'wpm_plugin_log_section'
        );
        // add option to log outgoing http requests
        add_settings_field(
            'wpm_plugin_option_log_http_requests',
            esc_html__( 'Log HTTP Requests', 'woocommerce-google-adwords-conversion-tracking-tag' ),
            [__CLASS__, 'html_log_outgoing_http_requests'],
            'wpm_plugin_options_page',
            'wpm_plugin_log_section'
        );
        // The log files are only easily accessible in the user interface
        // if WooCommerce is active.
        if ( Environment::is_woocommerce_active() ) {
            // add dropdown for log level
            add_settings_field(
                'wpm_plugin_option_log_files',
                esc_html__( 'Log Files', 'woocommerce-google-adwords-conversion-tracking-tag' ),
                [__CLASS__, 'html_log_files'],
                'wpm_plugin_options_page',
                'wpm_plugin_log_section'
            );
        }
    }

    public function add_section_diagnostics() {
        $section_ids = [
            'title'         => esc_html__( 'Diagnostics', 'woocommerce-google-adwords-conversion-tracking-tag' ),
            'slug'          => 'diagnostics',
            'settings_name' => 'wpm_plugin_diagnostics_section',
        ];
        self::output_section_data_field( $section_ids );
        add_settings_section(
            'wpm_plugin_diagnostics_section',
            esc_html__( 'Diagnostics', 'woocommerce-google-adwords-conversion-tracking-tag' ),
            [$this, 'plugin_section_diagnostics_html'],
            'wpm_plugin_options_page'
        );
    }

    public static function add_section_opportunities() {
        $section_ids = [
            'title'         => esc_html__( 'Opportunities', 'woocommerce-google-adwords-conversion-tracking-tag' ),
            'slug'          => 'opportunities',
            'settings_name' => 'wpm_plugin_opportunities_section',
        ];
        self::output_section_data_field( $section_ids );
        add_settings_section(
            'wpm_plugin_opportunities_section',
            esc_html__( 'Opportunities', 'woocommerce-google-adwords-conversion-tracking-tag' ),
            [__CLASS__, 'plugin_section_opportunities_html'],
            'wpm_plugin_options_page'
        );
    }

    public static function add_section_support() {
        $section_ids = [
            'title'         => esc_html__( 'Support', 'woocommerce-google-adwords-conversion-tracking-tag' ),
            'slug'          => 'support',
            'settings_name' => 'wpm_plugin_support_section',
        ];
        self::output_section_data_field( $section_ids );
        add_settings_section(
            'wpm_plugin_support_section',
            esc_html__( 'Support', 'woocommerce-google-adwords-conversion-tracking-tag' ),
            [__CLASS__, 'plugin_section_support_description'],
            'wpm_plugin_options_page'
        );
    }

    private static function output_section_data_field( array $section_ids ) {
        add_settings_field(
            'wgact_plugin_section_' . $section_ids['slug'] . '_opening_div',
            '',
            function () use($section_ids) {
                self::section_generic_opening_div_html( $section_ids );
            },
            'wpm_plugin_options_page',
            $section_ids['settings_name']
        );
    }

    public static function section_generic_opening_div_html( $section_ids ) {
        echo '<div class="section" data-section-title="' . esc_js( $section_ids['title'] ) . '" data-section-slug="' . esc_js( $section_ids['slug'] ) . '"></div>';
    }

    public static function subsection_generic_opening_div_html( $section_ids, $sub_section_ids ) {
        echo '<div class="subsection" data-section-slug="' . esc_js( $section_ids['slug'] ) . '" data-subsection-title="' . esc_js( $sub_section_ids['title'] ) . '" data-subsection-slug="' . esc_js( $sub_section_ids['slug'] ) . '"></div>';
    }

    // display the admin options page
    public function plugin_options_page() {
        $freemius_data = [
            'active' => Environment::is_freemius_active(),
            'tabs'   => [
                'contact' => esc_html__( 'Contact', 'woocommerce-google-adwords-conversion-tracking-tag' ),
                'account' => esc_html__( 'Account', 'woocommerce-google-adwords-conversion-tracking-tag' ),
            ],
        ];
        ?>

		<script>
			const pmwFreemius = <?php 
        echo wp_json_encode( $freemius_data );
        ?>;
		</script>
		<?php 
        if ( wpm_fs()->is__premium_only() && !defined( 'WP_FS__SKIP_EMAIL_ACTIVATION' ) ) {
            add_action( 'admin_notices', function () {
                Notifications::license_expired_warning__premium_only();
            } );
        }
        ?>

		<div id="script-blocker-notice" style="
			 font-weight: bold;
			 width:90%;
			 float: left;
			 margin: 5px 15px 2px;
			 padding: 1px 12px;
			 background: #fff;
			 border: 1px solid #c3c4c7;
			 border-left-width: 4px;
			 border-left-color: #d63638;
			 box-shadow: 0 1px 1px rgb(0 0 0 / 4%);">
			<p>
				<?php 
        esc_html_e( 'It looks like you are using some sort of ad- or script-blocker in your browser which is blocking the script and CSS files of this plugin.
                    In order for the plugin to work properly you need to disable the script blocker in your browser.', 'woocommerce-google-adwords-conversion-tracking-tag' );
        ?>
			</p>
			<p>
				<a href="<?php 
        echo esc_url( Documentation::get_link( 'script_blockers' ) );
        ?>" target="_blank">
					<?php 
        esc_html_e( 'Learn more', 'woocommerce-google-adwords-conversion-tracking-tag' );
        ?>
				</a>
			</p>

			<script>
				if (typeof wpm_hide_script_blocker_warning === "function") {
					wpm_hide_script_blocker_warning();
				}
			</script>

		</div>

		<div style="width:90%; margin: 5px">

			<?php 
        // Only run if WooCommerce is active,
        // otherwise the settings_errors() function will be run twice.
        if ( Environment::is_woocommerce_active() ) {
            settings_errors();
        }
        ?>

			<h2 class="nav-tab-wrapper"></h2>

			<form id="wpm_settings_form" action="options.php" method="post">

				<?php 
        settings_fields( 'wpm_plugin_options_group' );
        do_settings_sections( 'wpm_plugin_options_page' );
        submit_button();
        $this->inject_developer_banner();
        ?>

			</form>
		</div>
		<?php 
    }

    private function inject_developer_banner() {
        ?>

		<div class="pmw dev-banner">
			<div class="text left">
				<?php 
        esc_html_e( 'Profit Driven Marketing by SweetCode', 'woocommerce-google-adwords-conversion-tracking-tag' );
        ?>
			</div>
			<?php 
        if ( !wpm_fs()->can_use_premium_code__premium_only() && !Environment::is_on_playground_wordpress_net() ) {
            ?>
				<div class="text center" id="divTooltip"
					 data-tooltip="<?php 
            esc_html_e( "Enabling this will show you the pro settings in the user interface. It won't actually enable the pro features. If you want to try out the pro features head over to sweetcode.com and sign up for a trial.", 'woocommerce-google-adwords-conversion-tracking-tag' );
            ?>">
					<div style="padding-right: 6px">
						<?php 
            esc_html_e( 'Show Pro version settings:', 'woocommerce-google-adwords-conversion-tracking-tag' );
            ?>

					</div>
					<div>

						<label class="switch" id="pmw-pro-version-demo">

							<input type="hidden" value="0" name="wgact_plugin_options[general][pro_version_demo]">
							<input type="checkbox"
								   value="1"
								   name="wgact_plugin_options[general][pro_version_demo]"
								<?php 
            checked( Options::pro_version_demo_active() );
            ?>
							/>

							<span class="slider round"></span>

						</label>

					</div>
				</div>

			<?php 
        }
        ?>
			<div class="text right">
				<span style="padding-right: 6px">
					<?php 
        esc_html_e( 'Visit us here:', 'woocommerce-google-adwords-conversion-tracking-tag' );
        ?>
				</span>
				<span>
					<a href="https://sweetcode.com/?utm_source=plugin&utm_medium=banner&utm_campaign=wpm"
					   target="_blank">https://sweetcode.com</a>
				</span>
			</div>
		</div>

		<?php 
    }

    private function get_link_locale() {
        if ( substr( get_user_locale(), 0, 2 ) === 'de' ) {
            return 'de';
        } else {
            return 'en';
        }
    }

    /*
     * descriptions
     */
    public function plugin_section_main_description() {
        // do nothing
    }

    public function plugin_section_advanced_description() {
        // do nothing
    }

    public function plugin_section_add_cart_data_description() {
        //        echo '<div id="beta-description" style="margin-top:20px">';
        //        esc_html_e('Find out more about this new feature: ', 'woocommerce-google-adwords-conversion-tracking-tag');
        //        echo '<a href="https://support.google.com/google-ads/answer/9028254" target="_blank">https://support.google.com/google-ads/answer/9028254</a><br>';
        //        echo '</div>';
    }

    public static function plugin_section_shop_html() {
        // do nothing
    }

    public static function plugin_section_consent_management_html() {
        // do nothing
    }

    public static function plugin_section_logger() {
        // do nothing
    }

    public function plugin_section_diagnostics_html() {
        // output a text that explains that transients need to be enabled
        // then abort
        if ( !Environment::is_transients_enabled() ) {
            ?>
			<div style="margin-top:20px">
				<h2>
					<?php 
            esc_html_e( 'Diagnostics', 'woocommerce-google-adwords-conversion-tracking-tag' );
            ?>
				</h2>
				<div>
					<?php 
            esc_html_e( 'Transients are disabled on your site. Transients are required for the diagnostics report.', 'woocommerce-google-adwords-conversion-tracking-tag' );
            ?>
				</div>
				<div style="margin-top: 10px">
					<?php 
            esc_html_e( 'Please enable transients on your site.', 'woocommerce-google-adwords-conversion-tracking-tag' );
            ?>
				</div>
			</div>
			<?php 
            return;
        }
        ?>
		<div style="margin-top:20px">
			<h2>
				<?php 
        esc_html_e( 'Payment Gateway Tracking Accuracy Report', 'woocommerce-google-adwords-conversion-tracking-tag' );
        ?>
				<div class="pmw-status-icon beta"><?php 
        esc_html_e( 'beta', 'woocommerce-google-adwords-conversion-tracking-tag' );
        ?></div>
			</h2>

			<div style="margin-bottom: 20px">
				<?php 
        esc_html_e( "What's this? Follow this link to learn more", 'woocommerce-google-adwords-conversion-tracking-tag' );
        ?>
				:
				<?php 
        self::get_documentation_html_by_key( 'payment_gateway_tracking_accuracy' );
        ?>
			</div>

			<div>
				<div>

					<b><?php 
        esc_html_e( 'Available payment gateways', 'woocommerce-google-adwords-conversion-tracking-tag' );
        ?>
						:</b>
				</div>
				<div style="margin-left: 10px; font-family: Courier">
					<table>
						<thead style="align:left">
						<tr>
							<th style="text-align: left"><?php 
        esc_html_e( 'id', 'woocommerce-google-adwords-conversion-tracking-tag' );
        ?></th>
							<th style="text-align: left"><?php 
        esc_html_e( 'method_title', 'woocommerce-google-adwords-conversion-tracking-tag' );
        ?></th>
							<th style="text-align: left"><?php 
        esc_html_e( 'class', 'woocommerce-google-adwords-conversion-tracking-tag' );
        ?></th>
						</tr>
						</thead>
						<tbody>
						<?php 
        foreach ( Debug_Info::get_payment_gateways() as $gateway ) {
            ?>
							<tr>
								<td><?php 
            esc_html_e( $gateway->id );
            ?></td>
								<td><?php 
            esc_html_e( $gateway->method_title );
            ?></td>
								<td><?php 
            esc_html_e( get_class( $gateway ) );
            ?></td>
							</tr>
						<?php 
        }
        ?>
						</tbody>
					</table>
				</div>

			</div>
			<div style="margin-top: 10px">

				<b><?php 
        esc_html_e( 'Purchase confirmation page reached per gateway (active and inactive)', 'woocommerce-google-adwords-conversion-tracking-tag' );
        ?>
					:</b>

				<?php 
        if ( Debug_Info::get_gateway_analysis_array() === false ) {
            ?>
					<br>
					<?php 
            esc_html_e( Debug_Info::tracking_accuracy_loading_message() );
            $per_gateway_analysis = [];
        } else {
            $per_gateway_analysis = Debug_Info::get_gateway_analysis_array();
        }
        ?>

				<div style="margin-left: 10px; font-family: Courier;">
					<table>

						<?php 
        $order_count_total = 0;
        $order_count_measured = 0;
        ?>

						<tbody>
						<?php 
        foreach ( $per_gateway_analysis as $gateway_analysis ) {
            ?>
							<?php 
            $order_count_total += $gateway_analysis['order_count_total'];
            $order_count_measured += $gateway_analysis['order_count_measured'];
            ?>
							<tr>
								<td><?php 
            esc_html_e( $gateway_analysis['gateway_id'] );
            ?></td>
								<td><?php 
            esc_html_e( $gateway_analysis['order_count_measured'] );
            ?></td>
								<td>of</td>
								<td><?php 
            esc_html_e( $gateway_analysis['order_count_total'] );
            ?></td>
								<td>=</td>
								<td><?php 
            esc_html_e( $gateway_analysis['percentage'] );
            ?>%</td>
								<td><?php 
            $this->get_gateway_accuracy_warning_status( $gateway_analysis['percentage'] );
            ?></td>
							</tr>
						<?php 
        }
        ?>

						</tbody>
					</table>
				</div>

			</div>

			<div style="margin-top: 10px">

				<b><?php 
        esc_html_e( 'Purchase confirmation page reached per gateway (only active), weighted by frequency', 'woocommerce-google-adwords-conversion-tracking-tag' );
        ?>
					:</b>

				<?php 
        if ( Debug_Info::get_gateway_analysis_weighted_array() === false ) {
            ?>
					<br>
					<?php 
            esc_html_e( Debug_Info::tracking_accuracy_loading_message() );
            $per_gateway_analysis = [];
        } else {
            $per_gateway_analysis = Debug_Info::get_gateway_analysis_weighted_array();
        }
        ?>

				<div style="margin-left: 10px; font-family: Courier;">
					<table>
						<?php 
        $order_count_total = 0;
        $order_count_measured = 0;
        ?>

						<tbody>
						<?php 
        foreach ( $per_gateway_analysis as $gateway_analysis ) {
            ?>
							<?php 
            $order_count_total += $gateway_analysis['order_count_total'];
            $order_count_measured += $gateway_analysis['order_count_measured'];
            ?>
							<tr>
								<td><?php 
            esc_html_e( $gateway_analysis['gateway_id'] );
            ?></td>
								<td><?php 
            esc_html_e( $gateway_analysis['order_count_measured'] );
            ?></td>
								<td>of</td>
								<td><?php 
            esc_html_e( $gateway_analysis['order_count_total'] );
            ?></td>
								<td>=</td>
								<td><?php 
            esc_html_e( $gateway_analysis['percentage'] );
            ?>%</td>
								<td><?php 
            $this->get_gateway_accuracy_warning_status( $gateway_analysis['percentage'] );
            ?></td>
							</tr>
						<?php 
        }
        ?>
						<tr>
							<td>Total</td>
							<td><?php 
        esc_html_e( $order_count_measured );
        ?></td>
							<td>of</td>
							<td><?php 
        esc_html_e( $order_count_total );
        ?></td>
							<td>=</td>
							<td>
								<?php 
        $percent = Helpers::get_percentage( $order_count_measured, $order_count_total );
        if ( $order_count_total > 0 ) {
            esc_html_e( $percent . '%' );
        } else {
            echo '0%';
        }
        ?>
							</td>
							<td><?php 
        $this->get_gateway_accuracy_warning_status( $percent );
        ?></td>

						</tr>
						</tbody>
					</table>
				</div>
			</div>

		</div>

		<div style="margin-top: 10px">

			<b><?php 
        esc_html_e( 'Automatic Conversion Recovery (ACR)', 'woocommerce-google-adwords-conversion-tracking-tag' );
        ?>
				:</b>
			<?php 
        self::get_documentation_html_by_key( 'acr' );
        ?>
			<?php 
        ?>

				<div style="margin-top: 10px">

					<div style="margin-left: 10px">
						<p>
							<?php 
        esc_html_e( 'This feature is only available in the pro version of the plugin. Follow the link to learn more about it:', 'woocommerce-google-adwords-conversion-tracking-tag' );
        ?>
							<?php 
        self::get_documentation_html_by_key( 'acr' );
        ?></br>
							<?php 
        esc_html_e( 'Get the pro version of the Pixel Manager for WooCommerce over here', 'woocommerce-google-adwords-conversion-tracking-tag' );
        ?>
							: <a href="//sweetcode.com/pricing"
								 target="_blank"><?php 
        esc_html_e( 'Go Pro', 'woocommerce-google-adwords-conversion-tracking-tag' );
        ?></a>
						</p>
					</div>
				</div>
				<?php 
        ?>
		</div>
		<?php 
    }

    public static function plugin_section_opportunities_html() {
        Opportunities::html();
    }

    private function get_gateway_accuracy_warning_status( $percent ) {
        if ( 0 === intval( $percent ) ) {
            echo '';
        } elseif ( $percent > 0 && $percent < 90 ) {
            echo '<span style="color:red">warning</span>';
        } elseif ( $percent >= 90 && $percent < 95 ) {
            echo '<span style="color:orange">monitor</span>';
        } elseif ( 0 !== $percent ) {
            echo '<span style="color:green">good</span>';
        } else {
            echo '';
        }
    }

    public static function plugin_section_support_description() {
        ?>
		<!-- Contacting Support -->
		<div style="margin-top:20px">
			<h2><?php 
        esc_html_e( 'Contacting Support', 'woocommerce-google-adwords-conversion-tracking-tag' );
        ?></h2>
		</div>
		<?php 
        if ( PMW_DISTRO == 'fms' ) {
            self::support_info_for_freemius();
        } elseif ( PMW_DISTRO == 'wcm' ) {
            self::support_info_for_wc_market();
        }
        ?>
		<!-- Contacting Support -->

		<hr style="border: none;height: 1px; color: #333; background-color: #333;">

		<!-- Info for translators -->
		<?php 
        self::info_for_translators();
        ?>
		<!-- Info for translators -->

		<!-- Debug Info -->
		<div>
			<h2><?php 
        esc_html_e( 'Debug Information', 'woocommerce-google-adwords-conversion-tracking-tag' );
        ?></h2>

			<div>
				<textarea id="debug-info-textarea" class=""
						  style="display:block; margin-bottom: 10px; width: 100%;resize: none;color:dimgrey;font-family: Courier"
						  cols="100%" rows="30" readonly><?php 
        esc_html_e( Debug_Info::get_debug_info() );
        ?>
				</textarea>
				<button id="debug-info-button"
						type="button"><?php 
        esc_html_e( 'copy to clipboard', 'woocommerce-google-adwords-conversion-tracking-tag' );
        ?></button>
			</div>

		</div>
		<!-- Debug Info -->

		<hr class="pmw-hr">

		<!-- Export Settings -->
		<div>
			<h2><?php 
        esc_html_e( 'Export settings', 'woocommerce-google-adwords-conversion-tracking-tag' );
        ?></h2>

			<div>
				<textarea id="export-settings-json" class=""
						  style="display:block; margin-bottom: 10px; width: 100%;resize: none;color:dimgrey;"
						  cols="100%" rows="10" readonly><?php 
        echo wp_json_encode( Options::get_options() );
        ?>
				</textarea>
				<button id="debug-info-button"
						type="button"
						onclick="wpm.saveSettingsToDisk('<?php 
        esc_html_e( PMW_CURRENT_VERSION );
        ?>')">
					<?php 
        esc_html_e( 'Export to disk', 'woocommerce-google-adwords-conversion-tracking-tag' );
        ?>
				</button>
			</div>
		</div>
		<!-- Export Settings -->

		<hr class="pmw-hr">

		<!-- Import Settings -->
		<div>
			<h2><?php 
        esc_html_e( 'Import settings', 'woocommerce-google-adwords-conversion-tracking-tag' );
        ?></h2>
			<div>
				<input type="file" id="json-settings-file-input"/>
				<pre id="settings-upload-status-success" style="display: none; white-space: pre-line;">
					<span style="color: green; font-weight: bold">
						<?php 
        esc_html_e( 'Settings imported successfully!', 'woocommerce-google-adwords-conversion-tracking-tag' );
        ?>
					</span>
					<span>
						<?php 
        esc_html_e( 'Reloading...(in 1 second)!', 'woocommerce-google-adwords-conversion-tracking-tag' );
        ?>
					</span>
				</pre>

				<pre id="settings-upload-status-error" style="display: none; white-space: pre-line;">
					<span style="color: red; font-weight: bold">
						<?php 
        esc_html_e( 'There was an error importing that file! Please try again.', 'woocommerce-google-adwords-conversion-tracking-tag' );
        ?>
					</span>
					<span id="settings-upload-status-error-message" style="color: red; font-weight: bold"></span>
				</pre>
			</div>
		</div>
		<!-- Import Settings -->

		<hr class="pmw-hr">

		<?php 
    }

    private static function info_for_translators() {
        ?>

		<div style="margin-bottom: 20px">
			<h2><?php 
        esc_html_e( 'Translations', 'woocommerce-google-adwords-conversion-tracking-tag' );
        ?></h2>
			<?php 
        esc_html_e( 'If you want to participate improving the translations of this plugin into your language, please follow this link:', 'woocommerce-google-adwords-conversion-tracking-tag' );
        ?>
			<a href="https://translate.wordpress.org/projects/wp-plugins/woocommerce-google-adwords-conversion-tracking-tag/"
			   target="_blank">translate.wordpress.org</a>

		</div>
		<hr style="border: none;height: 1px; color: #333; background-color: #333;">
		<?php 
    }

    private static function support_info_for_freemius() {
        ?>
		<div style="margin-bottom: 30px;">
			<ul>

				<?php 
        if ( PMW_DISTRO == 'fms' ) {
            ?>
					<li id="pmw-chat-li" style="display: none;">
						<div style="display: flex; align-items: center;">
							<div style="margin-right: 10px;"> <!-- Add some margin for spacing -->
								<?php 
            esc_html_e( 'Chat with our fantastic AI bot Pixie (Pixie knows everything we do!):', 'woocommerce-google-adwords-conversion-tracking-tag' );
            ?>
							</div>
							<div>
								<button type="button" class="button button-primary" onclick="wpm.loadAiChatWindow()">
									<?php 
            esc_html_e( 'Chat', 'woocommerce-google-adwords-conversion-tracking-tag' );
            ?>
								</button>
							</div>
						</div>
					</li>

				<?php 
        }
        ?>
				<li>
					<?php 
        esc_html_e( 'Post a support request in the WordPress support forum here: ', 'woocommerce-google-adwords-conversion-tracking-tag' );
        ?>
					<a href="https://wordpress.org/support/plugin/woocommerce-google-adwords-conversion-tracking-tag/"
					   target="_blank">
						<?php 
        esc_html_e( 'Support forum', 'woocommerce-google-adwords-conversion-tracking-tag' );
        ?>
					</a>
					&nbsp;
					<span class="dashicons dashicons-info"></span>
					<?php 
        esc_html_e( '(Never post the debug or other sensitive information to the support forum. Instead send us the information by email.)', 'woocommerce-google-adwords-conversion-tracking-tag' );
        ?>
				</li>
				<li>
					<?php 
        esc_html_e( 'Send us an email to the following address:', 'woocommerce-google-adwords-conversion-tracking-tag' );
        ?>
					<a href="mailto:support@sweetcode.com" target="_blank">support@sweetcode.com</a>
				</li>
			</ul>
		</div>

		<?php 
    }

    private static function support_info_for_wc_market() {
        ?>
		<div style="margin-bottom: 30px;">
			<ul>
				<li>
					<?php 
        esc_html_e( 'Send us your support request through the WooCommerce.com dashboard: ', 'woocommerce-google-adwords-conversion-tracking-tag' );
        ?>
					<a href="https://woocommerce.com/my-account/create-a-ticket/" target="_blank">WooCommerce support
						dashboard</a>
				</li>
			</ul>
		</div>

		<?php 
    }

    public function option_html_google_analytics_4_id() {
        ?>
		<input class="pmw mono"
			   id="wpm_plugin_analytics_4_measurement_id"
			   name="wgact_plugin_options[google][analytics][ga4][measurement_id]"
			   size="40"
			   type="text"
			   value="<?php 
        esc_html_e( Options::get_ga4_measurement_id() );
        ?>"
		/>
		<?php 
        self::display_status_icon( Options::is_ga4_enabled() );
        self::get_documentation_html_by_key( 'google_analytics_4_id' );
        self::output_advanced_section_cog_html( 'google' );
        self::wistia_video_icon( 'rcc3qzb25l' );
        echo '<br><br>';
        esc_html_e( 'The Google Analytics 4 measurement ID looks like this:', 'woocommerce-google-adwords-conversion-tracking-tag' );
        echo '&nbsp;<i>G-R912ZZ1MHH0</i>';
    }

    public function option_html_google_ads_conversion_id() {
        ?>
		<input class="pmw mono"
			   id="wpm_plugin_conversion_id"
			   name="wgact_plugin_options[google][ads][conversion_id]"
			   size="40"
			   type="text"
			   value="<?php 
        esc_html_e( Options::get_google_ads_conversion_id() );
        ?>"
		/>
		<?php 
        self::display_status_icon( Options::is_google_ads_active() );
        self::get_documentation_html_by_key( 'google_ads_conversion_id' );
        self::output_advanced_section_cog_html( 'google' );
        self::wistia_video_icon( 'vcuj59kbm6' );
        self::wistia_video_icon( 'and34arrgg' );
        echo '<br><br>';
        esc_html_e( 'The conversion ID looks similar to this:', 'woocommerce-google-adwords-conversion-tracking-tag' );
        echo '&nbsp;<i>123456789</i>';
    }

    public function option_html_google_ads_conversion_label() {
        ?>
		<input class="pmw mono"
			   id="wpm_plugin_conversion_label"
			   name="wgact_plugin_options[google][ads][conversion_label]"
			   size="40"
			   type="text"
			   value="<?php 
        esc_html_e( Options::get_google_ads_conversion_label() );
        ?>"
		/>
		<?php 
        self::display_status_icon( Options::get_google_ads_conversion_label(), Options::get_google_ads_conversion_id() );
        self::get_documentation_html_by_key( 'google_ads_conversion_label' );
        self::output_advanced_section_cog_html( 'google' );
        self::wistia_video_icon( 'vcuj59kbm6' );
        self::wistia_video_icon( 'and34arrgg' );
        echo '<br><br>';
        esc_html_e( 'The purchase conversion label looks similar to this:', 'woocommerce-google-adwords-conversion-tracking-tag' );
        echo '&nbsp;<i>Xt19CO3axGAX0vg6X3gM</i>';
        if ( Options::get_google_ads_conversion_label() && !Options::get_google_ads_conversion_id() ) {
            echo '<p><span class="dashicons dashicons-info"></span>';
            esc_html_e( 'Requires an active Google Ads Conversion ID', 'woocommerce-google-adwords-conversion-tracking-tag' );
        }
        echo '</p>';
    }

    public function option_html_vwo_account_id() {
        ?>
		<input class="pmw mono"
			   id="pmw_plugin_vwo_account_id"
			   name="wgact_plugin_options[pixels][vwo][account_id]"
			   size="40"
			   type="text"
			   value="<?php 
        esc_html_e( Options::get_vwo_account_id() );
        ?>"
			<?php 
        esc_html_e( self::disable_if_demo() );
        ?>
		/>
		<?php 
        self::display_status_icon( Options::is_vwo_active(), true, true );
        self::get_documentation_html_by_key( 'vwo_account_id' );
        self::wistia_video_icon( '9besn6q7h0' );
        echo '<br><br>';
        esc_html_e( 'The VWO account ID looks like this:', 'woocommerce-google-adwords-conversion-tracking-tag' );
        echo '&nbsp;<i>737312</i>&nbsp;';
    }

    public function option_html_optimizely_project_id() {
        ?>
		<input class="pmw mono"
			   id="pmw_plugin_optimizely_project_id"
			   name="wgact_plugin_options[pixels][optimizely][project_id]"
			   size="40"
			   type="text"
			   value="<?php 
        esc_html_e( Options::get_optimizely_project_id() );
        ?>"
			<?php 
        esc_html_e( self::disable_if_demo() );
        ?>
		/>
		<?php 
        self::display_status_icon( Options::is_optimizely_active(), true, true );
        self::get_documentation_html_by_key( 'optimizely_project_id' );
        echo '<br><br>';
        esc_html_e( 'The Optimizely project ID looks like this:', 'woocommerce-google-adwords-conversion-tracking-tag' );
        echo '&nbsp;<i>20297535627</i>&nbsp;';
    }

    public function option_html_ab_tasty_account_id() {
        ?>
		<input class="pmw mono"
			   id="pmw_plugin_ab_tasty_account_id"
			   name="wgact_plugin_options[pixels][ab_tasty][account_id]"
			   size="40"
			   type="text"
			   value="<?php 
        esc_html_e( Options::get_ab_tasty_account_id() );
        ?>"
			<?php 
        esc_html_e( self::disable_if_demo() );
        ?>
		/>
		<?php 
        self::display_status_icon( Options::is_ab_tasty_active(), true, true );
        self::get_documentation_html_by_key( 'ab_tasty_account_id' );
        echo '<br><br>';
        esc_html_e( 'The AB Tasty account ID looks like this:', 'woocommerce-google-adwords-conversion-tracking-tag' );
        echo '&nbsp;<i>3d09baddc21a365b7da5ae4d0aa5cb95</i>&nbsp;';
    }

    public function option_html_facebook_pixel_id() {
        ?>
		<input class="pmw mono"
			   id="wpm_plugin_facebook_pixel_id"
			   name="wgact_plugin_options[facebook][pixel_id]"
			   size="40"
			   type="text"
			   value="<?php 
        esc_html_e( Options::get_facebook_pixel_id() );
        ?>"
		/>
		<?php 
        self::display_status_icon( Options::is_facebook_active() );
        self::get_documentation_html_by_key( 'facebook_pixel_id' );
        self::output_advanced_section_cog_html( 'facebook' );
        echo '<br><br>';
        esc_html_e( 'The Meta (Facebook) pixel ID looks similar to this:', 'woocommerce-google-adwords-conversion-tracking-tag' );
        echo '&nbsp;<i>765432112345678</i>';
    }

    public function option_html_adroll_advertiser_id() {
        ?>
		<input class="pmw mono"
			   id="pmw_adroll_advertiser_id"
			   name="wgact_plugin_options[pixels][adroll][advertiser_id]"
			   size="40"
			   type="text"
			   value="<?php 
        esc_html_e( Options::get_adroll_advertiser_id() );
        ?>"
			<?php 
        esc_html_e( self::disable_if_demo() );
        ?>
		/>
		<?php 
        self::display_status_icon( Options::is_adroll_advertiser_id_set(), Options::is_adroll_pixel_id_set() );
        self::get_documentation_html_by_key( 'adroll_advertiser_id' );
        self::wistia_video_icon( 'xwr0q08bk0' );
        self::html_pro_feature();
        echo '<br><br>';
        if ( Options::is_adroll_advertiser_id_set() && !Options::is_adroll_pixel_id_set() ) {
            ?>
			<p style="margin: 10px 0 10px 0">
				<span class="dashicons dashicons-info" style="padding-right: 10px"></span>
				<?php 
            esc_html_e( 'Requires an active Adroll pixel ID.', 'woocommerce-google-adwords-conversion-tracking-tag' );
            ?>
			</p>
			<?php 
        }
        esc_html_e( 'The Adroll advertiser ID looks similar to this:', 'woocommerce-google-adwords-conversion-tracking-tag' );
        echo '&nbsp;<i>ABCD1EF2GHIJKLMNOPQRS3</i>';
    }

    public function option_html_adroll_pixel_id() {
        ?>
		<input class="pmw mono"
			   id="pmw_adroll_pixel_id"
			   name="wgact_plugin_options[pixels][adroll][pixel_id]"
			   size="40"
			   type="text"
			   value="<?php 
        esc_html_e( Options::get_adroll_pixel_id() );
        ?>"
			<?php 
        esc_html_e( self::disable_if_demo() );
        ?>
		/>
		<?php 
        self::display_status_icon( Options::is_adroll_pixel_id_set(), Options::is_adroll_advertiser_id_set() );
        self::get_documentation_html_by_key( 'adroll_pixel_id' );
        self::wistia_video_icon( 'xwr0q08bk0' );
        self::html_pro_feature();
        echo '<br><br>';
        if ( Options::is_adroll_pixel_id_set() && !Options::is_adroll_advertiser_id_set() ) {
            ?>
			<p style="margin: 10px 0 10px 0">
				<span class="dashicons dashicons-info" style="padding-right: 10px"></span>
				<?php 
            esc_html_e( 'Requires an active Adroll advertiser ID.', 'woocommerce-google-adwords-conversion-tracking-tag' );
            ?>
			</p>
			<?php 
        }
        esc_html_e( 'The Adroll pixel ID looks similar to this:', 'woocommerce-google-adwords-conversion-tracking-tag' );
        echo '&nbsp;<i>ABCD1EFGHIJKLMN2O3PQR</i>';
    }

    public function option_html_bing_enhanced_conversions() {
        // adding the hidden input is a hack to make WordPress save the option with the value zero,
        // instead of not saving it and remove that array key entirely
        // https://stackoverflow.com/a/1992745/4688612
        ?>
		<label>
			<input type="hidden" value="0" name="wgact_plugin_options[bing][enhanced_conversions]">
			<input type="checkbox"
				   id="plugin_microsoft_enhanced_conversions"
				   name="wgact_plugin_options[bing][enhanced_conversions]"
				   value="1"
				<?php 
        checked( Options::is_bing_enhanced_conversions_enabled() );
        ?>
				<?php 
        esc_html_e( self::disable_if_demo() );
        ?>
			/>
			<?php 
        esc_html_e( 'Enable Microsoft Enhanced Conversions', 'woocommerce-google-adwords-conversion-tracking-tag' );
        ?>
		</label>
		<?php 
        self::display_status_icon( Options::is_bing_enhanced_conversions_enabled(), Options::is_bing_active(), true );
        self::get_documentation_html_by_key( 'bing_uet_tag_id' );
        self::html_pro_feature();
    }

    public function option_html_linkedin_partner_id() {
        ?>
		<input class="pmw mono"
			   id="pmw_linkedin_partner_id"
			   name="wgact_plugin_options[pixels][linkedin][partner_id]"
			   size="40"
			   type="text"
			   value="<?php 
        esc_html_e( Options::get_linkedin_partner_id() );
        ?>"
			<?php 
        esc_html_e( self::disable_if_demo() );
        ?>
		/>
		<?php 
        self::display_status_icon( Options::is_linkedin_active() );
        self::get_documentation_html_by_key( 'linkedin_partner_id' );
        self::output_advanced_section_cog_html( 'linkedin' );
        self::wistia_video_icon( 'vvyiav46ft' );
        self::wistia_video_icon( 'zrrp8aq4g0' );
        self::html_pro_feature();
        echo '<br><br>';
        esc_html_e( 'The LinkedIn partner ID looks similar to this:', 'woocommerce-google-adwords-conversion-tracking-tag' );
        echo '&nbsp;<i>1234567</i>';
    }

    public function setting_linkedin_search() {
        $text_length = max( strlen( Options::get_linkedin_conversion_id( 'search' ) ), 14 );
        ?>
		<input class="pmw mono"
			   id="pmw_setting_linkedin_search"
			   name="wgact_plugin_options[pixels][linkedin][conversion_ids][search]"
			   size="<?php 
        esc_html_e( $text_length );
        ?>"
			   type="text"
			   value="<?php 
        esc_html_e( Options::get_linkedin_conversion_id( 'search' ) );
        ?>"
			   style="width:<?php 
        esc_html_e( $text_length );
        ?>ch"
			<?php 
        esc_html_e( self::disable_if_demo() );
        ?>
		/>
		<?php 
        self::display_status_icon( Options::get_linkedin_conversion_id( 'search' ), Options::is_linkedin_active() );
        self::get_documentation_html_by_key( 'linkedin_event_ids' );
        self::wistia_video_icon( 'zrrp8aq4g0' );
        self::html_pro_feature();
    }

    public function setting_linkedin_view_content() {
        $text_length = max( strlen( Options::get_linkedin_conversion_id( 'view_content' ) ), 14 );
        ?>
		<input class="pmw mono"
			   id="pmw_setting_linkedin_view_content"
			   name="wgact_plugin_options[pixels][linkedin][conversion_ids][view_content]"
			   size="<?php 
        esc_html_e( $text_length );
        ?>"
			   type="text"
			   value="<?php 
        esc_html_e( Options::get_linkedin_conversion_id( 'view_content' ) );
        ?>"
			   style="width:<?php 
        esc_html_e( $text_length );
        ?>ch"
			<?php 
        esc_html_e( self::disable_if_demo() );
        ?>
		/>
		<?php 
        self::display_status_icon( Options::get_linkedin_conversion_id( 'view_content' ), Options::is_linkedin_active() );
        self::get_documentation_html_by_key( 'linkedin_event_ids' );
        self::wistia_video_icon( 'zrrp8aq4g0' );
        self::html_pro_feature();
    }

    public function setting_linkedin_add_to_list() {
        $text_length = max( strlen( Options::get_linkedin_conversion_id( 'add_to_list' ) ), 14 );
        ?>
		<input class="pmw mono"
			   id="pmw_setting_linkedin_add_to_list"
			   name="wgact_plugin_options[pixels][linkedin][conversion_ids][add_to_list]"
			   size="<?php 
        esc_html_e( $text_length );
        ?>"
			   type="text"
			   value="<?php 
        esc_html_e( Options::get_linkedin_conversion_id( 'add_to_list' ) );
        ?>"
			   style="width:<?php 
        esc_html_e( $text_length );
        ?>ch"
			<?php 
        esc_html_e( self::disable_if_demo() );
        ?>
		/>
		<?php 
        self::display_status_icon( Options::get_linkedin_conversion_id( 'add_to_list' ), Options::is_linkedin_active() );
        self::get_documentation_html_by_key( 'linkedin_event_ids' );
        self::wistia_video_icon( 'zrrp8aq4g0' );
        self::html_pro_feature();
    }

    public function setting_linkedin_add_to_cart() {
        $text_length = max( strlen( Options::get_linkedin_conversion_id( 'add_to_cart' ) ), 14 );
        ?>
		<input class="pmw mono"
			   id="pmw_setting_linkedin_add_to_cart"
			   name="wgact_plugin_options[pixels][linkedin][conversion_ids][add_to_cart]"
			   size="<?php 
        esc_html_e( $text_length );
        ?>"
			   type="text"
			   value="<?php 
        esc_html_e( Options::get_linkedin_conversion_id( 'add_to_cart' ) );
        ?>"
			   style="width:<?php 
        esc_html_e( $text_length );
        ?>ch"
			<?php 
        esc_html_e( self::disable_if_demo() );
        ?>
		/>
		<?php 
        self::display_status_icon( Options::get_linkedin_conversion_id( 'add_to_cart' ), Options::is_linkedin_active() );
        self::get_documentation_html_by_key( 'linkedin_event_ids' );
        self::wistia_video_icon( 'zrrp8aq4g0' );
        self::html_pro_feature();
    }

    public function setting_linkedin_start_checkout() {
        $text_length = max( strlen( Options::get_linkedin_conversion_id( 'start_checkout' ) ), 14 );
        ?>
		<input class="pmw mono"
			   id="pmw_setting_linkedin_start_checkout"
			   name="wgact_plugin_options[pixels][linkedin][conversion_ids][start_checkout]"
			   size="<?php 
        esc_html_e( $text_length );
        ?>"
			   type="text"
			   value="<?php 
        esc_html_e( Options::get_linkedin_conversion_id( 'start_checkout' ) );
        ?>"
			   style="width:<?php 
        esc_html_e( $text_length );
        ?>ch"
			<?php 
        esc_html_e( self::disable_if_demo() );
        ?>
		/>
		<?php 
        self::display_status_icon( Options::get_linkedin_conversion_id( 'start_checkout' ), Options::is_linkedin_active() );
        self::get_documentation_html_by_key( 'linkedin_event_ids' );
        self::wistia_video_icon( 'zrrp8aq4g0' );
        self::html_pro_feature();
    }

    public function setting_linkedin_purchase() {
        $text_length = max( strlen( Options::get_linkedin_conversion_id( 'purchase' ) ), 14 );
        ?>
		<input class="pmw mono"
			   id="pmw_setting_linkedin_purchase"
			   name="wgact_plugin_options[pixels][linkedin][conversion_ids][purchase]"
			   size="<?php 
        esc_html_e( $text_length );
        ?>"
			   type="text"
			   value="<?php 
        esc_html_e( Options::get_linkedin_conversion_id( 'purchase' ) );
        ?>"
			   style="width:<?php 
        esc_html_e( $text_length );
        ?>ch"
			<?php 
        esc_html_e( self::disable_if_demo() );
        ?>
		/>
		<?php 
        self::display_status_icon( Options::get_linkedin_conversion_id( 'purchase' ), Options::is_linkedin_active() );
        self::get_documentation_html_by_key( 'linkedin_event_ids' );
        self::wistia_video_icon( 'zrrp8aq4g0' );
        self::html_pro_feature();
    }

    public function option_html_bing_uet_tag_id() {
        ?>
		<input class="pmw mono"
			   id="wpm_plugin_bing_uet_tag_id"
			   name="wgact_plugin_options[bing][uet_tag_id]"
			   size="40"
			   type="text"
			   value="<?php 
        esc_html_e( Options::get_bing_uet_tag_id() );
        ?>"
			<?php 
        esc_html_e( self::disable_if_demo() );
        ?>
		/>
		<?php 
        self::display_status_icon( Options::is_bing_active() );
        self::get_documentation_html_by_key( 'bing_uet_tag_id' );
        self::html_pro_feature();
        echo '<br><br>';
        esc_html_e( 'The Microsoft Advertising UET tag ID looks similar to this:', 'woocommerce-google-adwords-conversion-tracking-tag' );
        echo '&nbsp;<i>12345678</i>';
    }

    public function option_html_twitter_pixel_id() {
        ?>
		<input class="pmw mono"
			   id="wpm_plugin_twitter_pixel_id"
			   name="wgact_plugin_options[twitter][pixel_id]"
			   size="40"
			   type="text"
			   value="<?php 
        esc_html_e( Options::get_twitter_pixel_id() );
        ?>"
			<?php 
        esc_html_e( self::disable_if_demo() );
        ?>
		/>
		<?php 
        self::display_status_icon( Options::is_twitter_active() );
        self::get_documentation_html_by_key( 'twitter_pixel_id' );
        self::output_advanced_section_cog_html( 'twitter' );
        self::html_pro_feature();
        echo '<br><br>';
        esc_html_e( 'The Twitter pixel ID looks similar to this:', 'woocommerce-google-adwords-conversion-tracking-tag' );
        echo '&nbsp;<i>a1cde</i>';
    }

    public function setting_twitter_add_to_cart() {
        $text_length = max( strlen( Options::get_twitter_event_id( 'add_to_cart' ) ), 14 );
        ?>
		<input class="pmw mono"
			   id="pmw_setting_twitter_add_to_cart"
			   name="wgact_plugin_options[twitter][event_ids][add_to_cart]"
			   size="<?php 
        esc_html_e( $text_length );
        ?>"
			   type="text"
			   value="<?php 
        esc_html_e( Options::get_twitter_event_id( 'add_to_cart' ) );
        ?>"
			   style="width:<?php 
        esc_html_e( $text_length );
        ?>ch"
			<?php 
        esc_html_e( self::disable_if_demo() );
        ?>
		/>
		<?php 
        self::display_status_icon( Options::get_twitter_event_id( 'add_to_cart' ), Options::is_twitter_active() );
        self::get_documentation_html_by_key( 'twitter_event_ids' );
        self::html_pro_feature();
    }

    public function setting_twitter_add_to_wishlist() {
        $text_length = max( strlen( Options::get_twitter_event_id( 'add_to_wishlist' ) ), 14 );
        ?>
		<input class="pmw mono"
			   id="pmw_setting_twitter_add_to_wishlist"
			   name="wgact_plugin_options[twitter][event_ids][add_to_wishlist]"
			   size="<?php 
        esc_html_e( $text_length );
        ?>"
			   type="text"
			   value="<?php 
        esc_html_e( Options::get_twitter_event_id( 'add_to_wishlist' ) );
        ?>"
			   style="width:<?php 
        esc_html_e( $text_length );
        ?>ch"
			<?php 
        esc_html_e( self::disable_if_demo() );
        ?>
		/>
		<?php 
        self::display_status_icon( Options::get_twitter_event_id( 'add_to_wishlist' ), Options::is_twitter_active() );
        self::get_documentation_html_by_key( 'twitter_event_ids' );
        self::html_pro_feature();
    }

    public function setting_twitter_view_content() {
        $text_length = max( strlen( Options::get_twitter_event_id( 'view_content' ) ), 14 );
        ?>
		<input class="pmw mono"
			   id="pmw_setting_twitter_view_content"
			   name="wgact_plugin_options[twitter][event_ids][view_content]"
			   size="<?php 
        esc_html_e( $text_length );
        ?>"
			   type="text"
			   value="<?php 
        esc_html_e( Options::get_twitter_event_id( 'view_content' ) );
        ?>"
			   style="width:<?php 
        esc_html_e( $text_length );
        ?>ch"
			<?php 
        esc_html_e( self::disable_if_demo() );
        ?>
		/>
		<?php 
        self::display_status_icon( Options::get_twitter_event_id( 'view_content' ), Options::is_twitter_active() );
        self::get_documentation_html_by_key( 'twitter_event_ids' );
        self::html_pro_feature();
    }

    public function setting_twitter_search() {
        $text_length = max( strlen( Options::get_twitter_event_id( 'search' ) ), 14 );
        ?>
		<input class="pmw mono"
			   id="pmw_setting_twitter_search"
			   name="wgact_plugin_options[twitter][event_ids][search]"
			   size="<?php 
        esc_html_e( $text_length );
        ?>"
			   type="text"
			   value="<?php 
        esc_html_e( Options::get_twitter_event_id( 'search' ) );
        ?>"
			   style="width:<?php 
        esc_html_e( $text_length );
        ?>ch"
			<?php 
        esc_html_e( self::disable_if_demo() );
        ?>
		/>
		<?php 
        self::display_status_icon( Options::get_twitter_event_id( 'search' ), Options::is_twitter_active() );
        self::get_documentation_html_by_key( 'twitter_event_ids' );
        self::html_pro_feature();
    }

    public function setting_twitter_initiate_checkout() {
        $text_length = max( strlen( Options::get_twitter_event_id( 'initiate_checkout' ) ), 14 );
        ?>
		<input class="pmw mono"
			   id="pmw_setting_twitter_initiate_checkout"
			   name="wgact_plugin_options[twitter][event_ids][initiate_checkout]"
			   size="<?php 
        esc_html_e( $text_length );
        ?>"
			   type="text"
			   value="<?php 
        esc_html_e( Options::get_twitter_event_id( 'initiate_checkout' ) );
        ?>"
			   style="width:<?php 
        esc_html_e( $text_length );
        ?>ch"
			<?php 
        esc_html_e( self::disable_if_demo() );
        ?>
		/>
		<?php 
        self::display_status_icon( Options::get_twitter_event_id( 'initiate_checkout' ), Options::is_twitter_active() );
        self::get_documentation_html_by_key( 'twitter_event_ids' );
        self::html_pro_feature();
    }

    public function setting_twitter_add_payment_info() {
        $text_length = max( strlen( Options::get_twitter_event_id( 'add_payment_info' ) ), 14 );
        ?>
		<input class="pmw mono"
			   id="pmw_setting_twitter_add_payment_info"
			   name="wgact_plugin_options[twitter][event_ids][add_payment_info]"
			   size="<?php 
        esc_html_e( $text_length );
        ?>"
			   type="text"
			   value="<?php 
        esc_html_e( Options::get_twitter_event_id( 'add_payment_info' ) );
        ?>"
			   style="width:<?php 
        esc_html_e( $text_length );
        ?>ch"
			<?php 
        esc_html_e( self::disable_if_demo() );
        ?>
		/>
		<?php 
        self::display_status_icon( Options::get_twitter_event_id( 'add_payment_info' ), Options::is_twitter_active() );
        self::get_documentation_html_by_key( 'twitter_event_ids' );
        self::html_pro_feature();
    }

    public function setting_twitter_purchase() {
        $text_length = max( strlen( Options::get_twitter_event_id( 'purchase' ) ), 14 );
        ?>
		<input class="pmw mono"
			   id="pmw_setting_twitter_purchase"
			   name="wgact_plugin_options[twitter][event_ids][purchase]"
			   size="<?php 
        esc_html_e( $text_length );
        ?>"
			   type="text"
			   value="<?php 
        esc_html_e( Options::get_twitter_event_id( 'purchase' ) );
        ?>"
			   style="width:<?php 
        esc_html_e( $text_length );
        ?>ch"
			<?php 
        esc_html_e( self::disable_if_demo() );
        ?>
		/>
		<?php 
        self::display_status_icon( Options::get_twitter_event_id( 'purchase' ), Options::is_twitter_active() );
        self::get_documentation_html_by_key( 'twitter_event_ids' );
        self::html_pro_feature();
    }

    public function option_html_outbrain_advertiser_id() {
        ?>
		<input class="pmw mono"
			   id="pmw_plugin_outbrain_advertiser_id"
			   name="wgact_plugin_options[pixels][outbrain][advertiser_id]"
			   size="40"
			   type="text"
			   value="<?php 
        esc_html_e( Options::get_outbrain_advertiser_id() );
        ?>"
			<?php 
        esc_html_e( self::disable_if_demo() );
        ?>
		/>
		<?php 
        self::display_status_icon( Options::is_outbrain_active() );
        self::get_documentation_html_by_key( 'outbrain_advertiser_id' );
        self::wistia_video_icon( '56u98s3rh6' );
        self::wistia_video_icon( 'sj8aig7b6q' );
        self::html_pro_feature();
        echo '<br><br>';
        esc_html_e( 'The Outbrain advertiser ID looks similar to this:', 'woocommerce-google-adwords-conversion-tracking-tag' );
        echo '&nbsp;<i>001a2b3cd4e5fgh6i789j01234k567890l</i>';
    }

    public function option_html_pinterest_pixel_id() {
        ?>
		<input class="pmw mono"
			   id="pmw_plugin_pinterest_pixel_id"
			   name="wgact_plugin_options[pinterest][pixel_id]"
			   size="40"
			   type="text"
			   value="<?php 
        esc_html_e( Options::get_pinterest_pixel_id() );
        ?>"
			<?php 
        esc_html_e( self::disable_if_demo() );
        ?>
		/>
		<?php 
        self::display_status_icon( Options::is_pinterest_active() );
        self::get_documentation_html_by_key( 'pinterest_pixel_id' );
        self::output_advanced_section_cog_html( 'pinterest' );
        self::html_pro_feature();
        echo '<br><br>';
        esc_html_e( 'The Pinterest pixel ID looks similar to this:', 'woocommerce-google-adwords-conversion-tracking-tag' );
        echo '&nbsp;<i>1234567890123</i>';
    }

    public function option_html_pinterest_enhanced_match() {
        // adding the hidden input is a hack to make WordPress save the option with the value zero,
        // instead of not saving it and remove that array key entirely
        // https://stackoverflow.com/a/1992745/4688612
        ?>
		<label>
			<input type="hidden" value="0" name="wgact_plugin_options[pinterest][enhanced_match]">
			<input type="checkbox"
				   id="wpm_plugin_pinterest_enhanced_match"
				   name="wgact_plugin_options[pinterest][enhanced_match]"
				   value="1"
				<?php 
        checked( Options::is_pinterest_enhanced_match_enabled() );
        ?>
				<?php 
        esc_html_e( self::disable_if_demo() );
        ?>
			/>

			<?php 
        esc_html_e( 'Enable Pinterest enhanced match', 'woocommerce-google-adwords-conversion-tracking-tag' );
        ?>
		</label>
		<?php 
        self::display_status_icon( Options::is_pinterest_enhanced_match_enabled(), Options::is_pinterest_active(), true );
        self::get_documentation_html_by_key( 'pinterest_enhanced_match' );
        self::html_pro_feature();
        ?>
		<?php 
        //		self::get_documentation_html_by_key('pinterest_enhanced_match');
    }

    public function option_html_snapchat_pixel_id() {
        ?>
		<input class="pmw mono"
			   id="wpm_plugin_snapchat_pixel_id"
			   name="wgact_plugin_options[snapchat][pixel_id]"
			   size="40"
			   type="text"
			   value="<?php 
        esc_html_e( Options::get_snapchat_pixel_id() );
        ?>"
			<?php 
        esc_html_e( self::disable_if_demo() );
        ?>
		/>
		<?php 
        self::display_status_icon( Options::is_snapchat_active() );
        self::get_documentation_html_by_key( 'snapchat_pixel_id' );
        self::html_pro_feature();
        echo '<br><br>';
        esc_html_e( 'The Snapchat pixel ID looks similar to this:', 'woocommerce-google-adwords-conversion-tracking-tag' );
        echo '&nbsp;<i>1a2345b6-cd78-9012-e345-fg6h7890ij12</i>';
    }

    public function option_html_taboola_account_id() {
        ?>
		<input class="pmw mono"
			   id="pmw_plugin_taboola_account_id"
			   name="wgact_plugin_options[pixels][taboola][account_id]"
			   size="40"
			   type="text"
			   value="<?php 
        esc_html_e( Options::get_taboola_account_id() );
        ?>"
			<?php 
        esc_html_e( self::disable_if_demo() );
        ?>
		/>
		<?php 
        self::display_status_icon( Options::is_taboola_active() );
        self::get_documentation_html_by_key( 'taboola_account_id' );
        self::wistia_video_icon( 'fw6rwcw1sf' );
        self::wistia_video_icon( 'q3hhvz9mgt' );
        self::html_pro_feature();
        echo '<br><br>';
        esc_html_e( 'The Taboola account ID looks similar to this:', 'woocommerce-google-adwords-conversion-tracking-tag' );
        echo '&nbsp;<i>1234567</i>';
    }

    public function option_html_tiktok_pixel_id() {
        ?>
		<input class="pmw mono"
			   id="wpm_plugin_tiktok_pixel_id"
			   name="wgact_plugin_options[tiktok][pixel_id]"
			   size="40"
			   type="text"
			   value="<?php 
        esc_html_e( Options::get_tiktok_pixel_id() );
        ?>"
			<?php 
        esc_html_e( self::disable_if_demo() );
        ?>
		/>
		<?php 
        self::display_status_icon( Options::is_tiktok_active() );
        self::get_documentation_html_by_key( 'tiktok_pixel_id' );
        self::output_advanced_section_cog_html( 'tiktok' );
        self::html_pro_feature();
        echo '<br><br>';
        esc_html_e( 'The TikTok pixel ID looks similar to this:', 'woocommerce-google-adwords-conversion-tracking-tag' );
        echo '&nbsp;<i>ABCD1E2FGH3IJK45LMN6</i>';
    }

    public function option_html_hotjar_site_id() {
        ?>
		<input class="pmw mono"
			   id="wpm_plugin_hotjar_site_id"
			   name="wgact_plugin_options[hotjar][site_id]"
			   size="40"
			   type="text"
			   value="<?php 
        esc_html_e( Options::get_hotjar_site_id() );
        ?>"
		/>
		<?php 
        self::display_status_icon( Options::is_hotjar_enabled() );
        self::get_documentation_html_by_key( 'hotjar_site_id' );
        self::wistia_video_icon( 'z9tjk5eia7' );
        echo '<br><br>';
        esc_html_e( 'The Hotjar site ID looks similar to this:', 'woocommerce-google-adwords-conversion-tracking-tag' );
        echo '&nbsp;<i>1234567</i>';
    }

    /**
     * 2023.09.11: Reddit renamed the advertiser ID to pixel ID.
     * Don't change the database key, because that would break the plugin for existing users.
     */
    public function option_html_reddit_pixel_id() {
        ?>
		<input class="pmw mono"
			   id="plugin_reddit_pixel_id"
			   name="wgact_plugin_options[pixels][reddit][advertiser_id]"
			   size="40"
			   type="text"
			   value="<?php 
        esc_html_e( Options::get_reddit_advertiser_id() );
        ?>"
			<?php 
        esc_html_e( self::disable_if_demo() );
        ?>
		/>
		<?php 
        self::display_status_icon( Options::get_reddit_advertiser_id(), true, true );
        ?>
		<?php 
        self::get_documentation_html_by_key( 'reddit_advertiser_id' );
        ?>
		<?php 
        self::output_advanced_section_cog_html( 'reddit' );
        ?>
		<?php 
        self::wistia_video_icon( '3cr5pwksrf' );
        ?>
		<?php 
        self::html_pro_feature();
        ?>
		<p style="margin-top:16px">
			<?php 
        esc_html_e( 'The Reddit pixel ID looks similar to this:', 'woocommerce-google-adwords-conversion-tracking-tag' );
        ?>
			<?php 
        echo '&nbsp;<i>t2_gvnawxpb</i>';
        ?>
		</p>
		<?php 
    }

    public function option_html_snapchat_capi_token() {
        ?>
		<textarea class="pmw mono"
				  id="plugin_snapchat_capi_token"
				  name="wgact_plugin_options[snapchat][capi][token]"
				  cols="60"
				  rows="6"
			<?php 
        esc_html_e( self::disable_if_demo() );
        ?>><?php 
        esc_html_e( Options::get_snapchat_capi_token() );
        ?></textarea>
		<?php 
        self::display_status_icon( Options::get_snapchat_capi_token(), Options::is_snapchat_active() );
        ?>
		<?php 
        self::get_documentation_html_by_key( 'snapchat_capi_token' );
        ?>
		<?php 
        self::html_pro_feature();
        ?>
		<?php 
        if ( !Options::is_snapchat_active() ) {
            ?>
			<p>
				<span class="dashicons dashicons-info"></span>
				<?php 
            esc_html_e( 'You need to activate the Snapchat pixel', 'woocommerce-google-adwords-conversion-tracking-tag' );
            ?>
			</p>
		<?php 
        }
        ?>
		<?php 
    }

    public function option_html_snapchat_advanced_matching() {
        // adding the hidden input is a hack to make WordPress save the option with the value zero,
        // instead of not saving it and remove that array key entirely
        // https://stackoverflow.com/a/1992745/4688612
        ?>
		<label>
			<input type="hidden" value="0" name="wgact_plugin_options[snapchat][advanced_matching]">
			<input type="checkbox"
				   id="plugin_snapchat_advanced_matching"
				   name="wgact_plugin_options[snapchat][advanced_matching]"
				   value="1"
				<?php 
        checked( Options::is_snapchat_advanced_matching_enabled() );
        ?>
				<?php 
        esc_html_e( self::disable_if_demo() );
        ?>
			/>
			<?php 
        esc_html_e( 'Enable Snapchat Advanced Matching', 'woocommerce-google-adwords-conversion-tracking-tag' );
        ?>
		</label>
		<?php 
        self::display_status_icon( Options::is_snapchat_advanced_matching_enabled(), Options::is_snapchat_active(), true );
        self::get_documentation_html_by_key( 'snapchat_advanced_matching' );
        self::html_pro_feature();
    }

    public function option_html_reddit_advanced_matching() {
        // adding the hidden input is a hack to make WordPress save the option with the value zero,
        // instead of not saving it and remove that array key entirely
        // https://stackoverflow.com/a/1992745/4688612
        ?>
		<label>
			<input type="hidden" value="0" name="wgact_plugin_options[pixels][reddit][advanced_matching]">
			<input type="checkbox"
				   id="plugin_reddit_advanced_matching"
				   name="wgact_plugin_options[pixels][reddit][advanced_matching]"
				   value="1"
				<?php 
        checked( Options::is_reddit_advanced_matching_enabled() );
        ?>
				<?php 
        esc_html_e( self::disable_if_demo() );
        ?>
			/>
			<?php 
        esc_html_e( 'Enable Reddit advanced matching', 'woocommerce-google-adwords-conversion-tracking-tag' );
        ?>
		</label>
		<?php 
        self::display_status_icon( Options::is_reddit_advanced_matching_enabled(), Options::is_reddit_active(), true );
        self::get_documentation_html_by_key( 'reddit_advanced_matching' );
        self::html_pro_feature();
    }

    public function html_marketing_value_logic() {
        ?>
		<label>
			<input type="radio"
				   id="pmw_plugin_marketing_value_logic_0"
				   name="<?php 
        esc_html_e( Options::get_marketing_value_logic_input_field_name() );
        ?>"
				   value="0"
				<?php 
        echo checked( 0, Options::get_marketing_value_logic(), false );
        ?>
			>
			<?php 
        esc_html_e( 'Order Subtotal: Doesn\'t include tax, shipping, and if available, fees like PayPal or Stripe fees (default)', 'woocommerce-google-adwords-conversion-tracking-tag' );
        ?>
			<?php 
        self::get_documentation_html_by_key( 'marketing_value_subtotal' );
        ?>
		</label>
		<br>
		<label>
			<input type="radio"
				   id="pmw_plugin_marketing_value_logic_1"
				   name="<?php 
        esc_html_e( Options::get_marketing_value_logic_input_field_name() );
        ?>"
				   value="1"
				<?php 
        echo checked( 1, Options::get_marketing_value_logic(), false );
        ?>
			>
			<?php 
        esc_html_e( 'Order Total: Includes tax and shipping', 'woocommerce-google-adwords-conversion-tracking-tag' );
        ?>
			<?php 
        self::get_documentation_html_by_key( 'marketing_value_total' );
        ?>

		</label>
		<?php 
        if ( wpm_fs()->can_use_premium_code__premium_only() || '2' === Options::get_marketing_value_logic() || Options::pro_version_demo_active() ) {
            ?>
			<br>
			<label>
				<input type="radio"
					   id="pmw_plugin_marketing_value_logic_2"
					   name="<?php 
            esc_html_e( Options::get_marketing_value_logic_input_field_name() );
            ?>"
					   value="2"
					<?php 
            echo checked( 2, Options::get_marketing_value_logic(), false );
            ?>
					<?php 
            if ( !Environment::is_a_cog_plugin_active() || !wpm_fs()->can_use_premium_code__premium_only() ) {
                ?>
						disabled
					<?php 
            }
            ?>
				>
				<?php 
            esc_html_e( 'Profit Margin: Only reports the profit margin. Excludes tax, shipping, and where possible, gateway fees.', 'woocommerce-google-adwords-conversion-tracking-tag' );
            ?>
				<?php 
            self::get_documentation_html_by_key( 'marketing_value_profit_margin' );
            ?>
				<?php 
            self::html_pro_feature();
            ?>
			</label>
		<?php 
        }
        ?>
		<div style="margin-top: 10px">
			<div>
				<span class="dashicons dashicons-info"></span>
				<?php 
        esc_html_e( 'This is the total value reported back to the marketing pixels (such as Google Ads, Meta (Facebook), etc.). It excludes statistics pixels.', 'woocommerce-google-adwords-conversion-tracking-tag' );
        ?>
				<?php 
        if ( wpm_fs()->can_use_premium_code__premium_only() && !Environment::is_a_cog_plugin_active() ) {
            ?>
			</div>
			<div style="margin-top: 10px">
				<span class="dashicons dashicons-info"></span>
				<?php 
            esc_html_e( 'To use the Profit Margin setting you will need to install one of the following two Cost of Goods plugins:', 'woocommerce-google-adwords-conversion-tracking-tag' );
            ?>
				<a href="https://woocommerce.com/products/woocommerce-cost-of-goods/" target="_blank">WooCommerce Cost
					of Goods (SkyVerge)</a>
				<?php 
            esc_html_e( 'or', 'woocommerce-google-adwords-conversion-tracking-tag' );
            ?>
				<a href="https://wordpress.org/plugins/cost-of-goods-for-woocommerce/" target="_blank">Cost of Goods for
					WooCommerce (WPFactory)</a>
				<?php 
        }
        ?>
			</div>
		</div>
		<?php 
    }

    private static function get_documentation_html_by_key( $key = 'default' ) {
        return self::get_documentation_html( Documentation::get_link( $key ) );
    }

    private static function get_documentation_html( $path ) {
        //		$html  = '<a class="pmw-documentation-icon" href="' . $path . '" target="_blank">';
        //		$html .= '<span style="vertical-align: top; margin-top: 0px" class="dashicons dashicons-info-outline tooltip"><span class="tooltiptext">';
        //		$html .= esc_html__('open the documentation', 'woocommerce-google-adwords-conversion-tracking-tag');
        //		$html .= '</span></span></a>';
        //
        //		return $html;
        ?>
		<a class="pmw-documentation-icon" href="<?php 
        echo esc_url( $path );
        ?>" target="_blank">
			<span style="vertical-align: top; margin-top: 0" class="dashicons dashicons-info-outline pmw-tooltip">
				<span class="tooltiptext">
					<?php 
        esc_html_e( 'open the documentation', 'woocommerce-google-adwords-conversion-tracking-tag' );
        ?>
				</span>
			</span>
		</a>

		<?php 
    }

    private static function output_advanced_section_cog_html( $subsection ) {
        ?>
		<a href="#" class="advanced-section-link pmw-tooltip" data-as-section="advanced"
		   data-as-subsection="<?php 
        esc_html_e( $subsection );
        ?>">
			<svg class="advanced-settings-icon" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24">
				<path d="M14 21h-4l-.551-2.48a6.991 6.991 0 0 1-1.819-1.05l-2.424.763-2-3.464 1.872-1.718a7.055 7.055 0 0 1 0-2.1L3.206 9.232l2-3.464 2.424.763A6.992 6.992 0 0 1 9.45 5.48L10 3h4l.551 2.48a6.992 6.992 0 0 1 1.819 1.05l2.424-.763 2 3.464-1.872 1.718a7.05 7.05 0 0 1 0 2.1l1.872 1.718-2 3.464-2.424-.763a6.99 6.99 0 0 1-1.819 1.052L14 21z"/>
				<circle cx="12" cy="12" r="3"/>
			</svg>
			<span class="tooltiptext">
				<?php 
        esc_html_e( 'advanced settings', 'woocommerce-google-adwords-conversion-tracking-tag' );
        ?>
			</span>
		</a>
		<?php 
    }

    private function get_documentation_html_e( $path ) {
        $html = '<a class="pmw-documentation-icon" href="' . $path . '" target="_blank">';
        $html .= '<span style="vertical-align: top; margin-top: 0px" class="dashicons dashicons-info-outline pmw-tooltip"><span class="tooltiptext">';
        $html .= esc_html__( 'open the documentation', 'woocommerce-google-adwords-conversion-tracking-tag' );
        $html .= '</span></span></a>';
        return $html;
    }

    public static function setting_html_google_consent_mode_active() {
        // adding the hidden input is a hack to make WordPress save the option with the value zero,
        // instead of not saving it and remove that array key entirely
        // https://stackoverflow.com/a/1992745/4688612
        ?>
		<label>
			<input type="hidden" value="0" name="wgact_plugin_options[google][consent_mode][active]">
			<input type="checkbox"
				   id="wpm_setting_google_consent_mode_active"
				   name="wgact_plugin_options[google][consent_mode][active]"
				   value="1"
				<?php 
        checked( Options::is_google_consent_mode_active() );
        ?>
			/>
			<?php 
        esc_html_e( 'Enable Google Consent Mode v2 with standard settings', 'woocommerce-google-adwords-conversion-tracking-tag' );
        ?>

		</label>
		<?php 
        self::display_status_icon( Options::is_google_consent_mode_active(), true, true );
        ?>
		<?php 
        self::get_documentation_html_by_key( 'google_consent_mode' );
    }

    public static function setting_html_explicit_consent_mode() {
        // adding the hidden input is a hack to make WordPress save the option with the value zero,
        // instead of not saving it and remove that array key entirely
        // https://stackoverflow.com/a/1992745/4688612
        ?>
		<label>
			<input type="hidden"
				   value="0"
				   name="<?php 
        esc_html_e( Options::get_cookie_consent_explicit_consent_input_field_name() );
        ?>"
			>
			<input type="checkbox"
				   id="setting_explicit_consent_mode"
				   name="<?php 
        esc_html_e( Options::get_cookie_consent_explicit_consent_input_field_name() );
        ?>"
				   value="1"
				<?php 
        checked( Options::is_consent_management_explicit_consent_active() );
        ?>
				<?php 
        disabled( Options::consent_management_is_explicit_consent_active_override() );
        ?>
			/>
			<?php 
        esc_html_e( 'Enable Explicit Consent Mode', 'woocommerce-google-adwords-conversion-tracking-tag' );
        ?>
		</label>
		<?php 
        self::display_status_icon( Options::is_consent_management_explicit_consent_active(), true, true );
        ?>
		<?php 
        if ( Options::consent_management_is_explicit_consent_active_override() ) {
            ?>
			<?php 
            self::html_status_icon_override();
            ?>
		<?php 
        }
        ?>
		<?php 
        self::get_documentation_html_by_key( 'explicit_consent_mode' );
        ?>
		<p style="margin-top:10px">
		<div>

			<?php 
        esc_html_e( 'While the Explicit Consent Mode is active no pixels will be fired until the visitor gives consent.', 'woocommerce-google-adwords-conversion-tracking-tag' );
        ?>
			<br>
			<?php 
        esc_html_e( 'With Google Consent Mode enabled, Google will instead use cookieless tracking.', 'woocommerce-google-adwords-conversion-tracking-tag' );
        ?>
			<br>
		</div>
		<div style="margin-top: 5px">
			<span class="dashicons dashicons-info"></span>
			<?php 
        esc_html_e( "Only activate the Explicit Consent Mode if you are also using a Consent Management Platform (a cookie banner) that is compatible with the Pixel Manager. Here's a list of compatible plugins:", 'woocommerce-google-adwords-conversion-tracking-tag' );
        ?>
			<a
					href="https://sweetcode.com/docs/wpm/consent-management/platforms"
					target="_blank"><?php 
        esc_html_e( 'Compatible Consent Management Platforms (CMPs)', 'woocommerce-google-adwords-conversion-tracking-tag' );
        ?></a>
			<br>
			<?php 
        esc_html_e( 'You can also use our Consent API to make your custom cookie banner compatible with the Pixel Manager:', 'woocommerce-google-adwords-conversion-tracking-tag' );
        ?>
			<a
					href="https://sweetcode.com/docs/wpm/consent-management/api"
					target="_blank"><?php 
        esc_html_e( 'Consent API', 'woocommerce-google-adwords-conversion-tracking-tag' );
        ?></a>
		</div>
		</p>
		<?php 
    }

    public static function setting_html_explicit_consent_regions() {
        // https://semantic-ui.com/modules/dropdown.html#multiple-selection
        // https://developer.woocommerce.com/2017/08/08/selectwoo-an-accessible-replacement-for-select2/
        // https://github.com/woocommerce/selectWoo
        ?>
		<select id="setting_explicit_consent_regions"
				name="wgact_plugin_options[google][consent_mode][regions][]"
				multiple="multiple"
				style="width:350px;"
				data-placeholder="<?php 
        esc_html_e( 'Choose countries', 'woocommerce-google-adwords-conversion-tracking-tag' );
        ?>&hellip;"
				aria-label="Country"
				class="wc-enhanced-select"
		>
			<?php 
        foreach ( Consent_Mode_Regions::get_consent_mode_regions() as $region_code => $region_name ) {
            ?>
				<option value="<?php 
            esc_html_e( $region_code );
            ?>"
					<?php 
            // Rarely Options::get_options_obj()->google->consent_mode->regions is null
            // The reason is a mystery. It happens in very rare cases and can't be reproduced.
            // So we have to check if it is an array before using in_array()
            if ( is_array( Options::get_restricted_consent_regions_raw() ) ) {
                esc_html_e( ( in_array( $region_code, Options::get_restricted_consent_regions_raw() ) ? 'selected' : '' ) );
            }
            ?>
				>
					<?php 
            esc_html_e( $region_name );
            ?>
				</option>
			<?php 
        }
        ?>

		</select>
		<script>
			jQuery("#setting_explicit_consent_regions").select2({
				// theme: "classic"
			});
		</script>
		<?php 
        self::get_documentation_html_by_key( 'restricted_consent_regions' );
        ?>
		<?php 
        self::display_status_icon( Options::is_consent_management_explicit_consent_active(), true, true );
        ?>
		<p>
			<?php 
        esc_html_e( 'Regions in which tracking with cookies will only be activated after the visitor gives consent.', 'woocommerce-google-adwords-conversion-tracking-tag' );
        ?>
			<br>
		<div style="margin-top: 5px">
			<?php 
        if ( !Options::is_consent_management_explicit_consent_active() ) {
            ?>
				<span class="dashicons dashicons-info"></span>
				<?php 
            esc_html_e( 'Requires the Explicit Consent Mode to be active.', 'woocommerce-google-adwords-conversion-tracking-tag' );
            ?>
				<br>
			<?php 
        }
        ?>
			<span class="dashicons dashicons-info"></span>
			<?php 
        esc_html_e( 'If no region is set, then the restrictions are enabled worldwide. If you specify one or more regions, then the restrictions only apply for the specified regions. For countries outside of those regions, tracking will work without restrictions until the visitor removes consent.', 'woocommerce-google-adwords-conversion-tracking-tag' );
        ?>
		</div>
		</p>
		<?php 
    }

    public static function setting_html_google_tcf_support() {
        // adding the hidden input is a hack to make WordPress save the option with the value zero,
        // instead of not saving it and remove that array key entirely
        // https://stackoverflow.com/a/1992745/4688612
        ?>
		<label>
			<input type="hidden" value="0" name="wgact_plugin_options[google][tcf_support]">
			<input type="checkbox"
				   id="setting_google_tcf_support"
				   name="wgact_plugin_options[google][tcf_support]"
				   value="1"
				<?php 
        checked( Options::is_google_tcf_support_active() );
        ?>
				<?php 
        esc_html_e( self::disable_if_demo() );
        ?>
			/>
			<?php 
        esc_html_e( 'Enable Google TCF support', 'woocommerce-google-adwords-conversion-tracking-tag' );
        ?>
		</label>
		<?php 
        self::display_status_icon( Options::is_google_tcf_support_active(), true, true );
        ?>
		<?php 
        self::get_documentation_html_by_key( 'google_tcf_support' );
        self::html_pro_feature();
    }

    public function info_html_google_analytics_eec() {
        esc_html_e( 'Google Analytics Enhanced E-Commerce is ', 'woocommerce-google-adwords-conversion-tracking-tag' );
        self::display_status_icon( Options::is_google_analytics_active() );
        //		self::get_documentation_html_by_key('eec');
    }

    public function setting_html_google_analytics_4_api_secret() {
        ?>
		<input class="pmw mono"
			   id="wpm_setting_google_analytics_4_api_secret"
			   name="wgact_plugin_options[google][analytics][ga4][api_secret]"
			   size="40"
			   type="text"
			   value="<?php 
        esc_html_e( Options::get_ga4_mp_api_secret() );
        ?>"
			<?php 
        esc_html_e( self::disable_if_demo() );
        ?>
		/>
		<?php 
        self::display_status_icon( Options::is_ga4_mp_active() );
        self::get_documentation_html_by_key( 'google_analytics_4_api_secret' );
        self::html_pro_feature();
        echo '<br><br>';
        if ( !Options::is_ga4_enabled() ) {
            echo '<p></p><span class="dashicons dashicons-info" style="margin-right: 10px"></span>';
            esc_html_e( 'Google Analytics 4 activation required', 'woocommerce-google-adwords-conversion-tracking-tag' );
            echo '</p>';
        }
        esc_html_e( 'If enabled, purchase and refund events will be sent to Google through the measurement protocol for increased accuracy.', 'woocommerce-google-adwords-conversion-tracking-tag' );
    }

    public function setting_html_ga4_property_id() {
        ?>
		<input class="pmw mono"
			   id="pmw_setting_ga4_property_id"
			   name="wgact_plugin_options[google][analytics][ga4][data_api][property_id]"
			   size="40"
			   type="text"
			   value="<?php 
        esc_html_e( Options::get_ga4_data_api_property_id() );
        ?>"
			<?php 
        esc_html_e( self::disable_if_demo() );
        ?>
		/>
		<?php 
        self::display_status_icon( Options::get_ga4_data_api_property_id(), count( Options::get_ga4_data_api_credentials() ) > 0 );
        self::get_documentation_html_by_key( 'ga4_data_api_property_id' );
        self::html_pro_feature();
        if ( Options::get_ga4_data_api_property_id() && !isset( Options::get_ga4_data_api_credentials()['client_email'] ) ) {
            echo '<p><span class="dashicons dashicons-info"></span>';
            esc_html_e( 'GA4 Data API Credentials need to be set.', 'woocommerce-google-adwords-conversion-tracking-tag' );
        }
    }

    public function setting_html_g4_data_api_credentials() {
        $client_email = ( isset( Options::get_ga4_data_api_credentials()['client_email'] ) ? Options::get_ga4_data_api_credentials()['client_email'] : '' );
        $text_length = max( strlen( $client_email ), 80 );
        ?>
		<div style="margin-top: 5px">

			<input class="pmw mono"
				   id="pmw_setting_ga4_data_api_client_email"
				   name="pmw_setting_ga4_data_api_client_email"
				   size="<?php 
        esc_html_e( $text_length );
        ?>"
				   type="text"
				   style="width:<?php 
        esc_html_e( $text_length );
        ?>ch"
				   value="<?php 
        esc_html_e( $client_email );
        ?>"
				   disabled
			/>

			<script>
				const pmwCopyGa4ClientEmailToClipboardCA = () => {
					navigator.clipboard.writeText(document.getElementById("pmw_setting_ga4_data_api_client_email").value);
					const pmwCaFeedTooltip     = document.getElementById("myPmwGa4ClientEmailTooltip");
					pmwCaFeedTooltip.innerHTML = "<?php 
        esc_html_e( 'Copied the account email to the clipboard', 'woocommerce-google-adwords-conversion-tracking-tag' );
        ?>";
				};

				const resetGa4ClientEmailCopyButton = () => {
					const pmwCaFeedTooltip     = document.getElementById("myPmwGa4ClientEmailTooltip");
					pmwCaFeedTooltip.innerHTML = "<?php 
        esc_html_e( 'Copy to clipboard', 'woocommerce-google-adwords-conversion-tracking-tag' );
        ?>";
				};
			</script>

			<div class="pmwCaTooltip">
				<a href="javascript:void(0)" class="pmw-copy-icon pmwCaTooltip"
				   onclick="pmwCopyGa4ClientEmailToClipboardCA()" onmouseout="resetGa4ClientEmailCopyButton()"></a>
				<span class="pmwCaTooltiptext"
					  id="myPmwGa4ClientEmailTooltip"><?php 
        esc_html_e( 'Copy to clipboard', 'woocommerce-google-adwords-conversion-tracking-tag' );
        ?></span>
			</div>


			<?php 
        self::display_status_icon( isset( Options::get_ga4_data_api_credentials()['client_email'] ), Options::get_ga4_data_api_property_id() );
        ?>
			<?php 
        self::get_documentation_html_by_key( 'ga4_data_api_credentials' );
        ?>
			<?php 
        self::html_pro_feature();
        ?>

			<div style="margin-top: 5px">

				<!-- Import Settings -->
				<div class="button">
					<div>
						<label for="ga4-data-api-credentials-upload-button">
							<?php 
        esc_html_e( 'Import credentials', 'woocommerce-google-adwords-conversion-tracking-tag' );
        ?>
							<input type="file" id="ga4-data-api-credentials-upload-button" style="display: none;"/>
						</label>
					</div>
				</div>
				<!-- Import Settings -->

				<!-- Delete Settings -->
				<div class="button">
					<div>
						<label for="ga4-data-api-credentials-delete-button">
							<?php 
        esc_html_e( 'Delete credentials', 'woocommerce-google-adwords-conversion-tracking-tag' );
        ?>
							<input id="ga4-data-api-credentials-delete-button" style="display: none;"/>
						</label>
					</div>
				</div>
				<!-- Delete Settings -->
			</div>

			<div>
				<pre id="ga4-api-credentials-upload-status-success" style="display: none; white-space: pre-line;">
					<span style="color: green; font-weight: bold">
						<?php 
        esc_html_e( 'Settings imported successfully!', 'woocommerce-google-adwords-conversion-tracking-tag' );
        ?>
					</span>
					<span>
						<?php 
        esc_html_e( 'Reloading...(in 5 seconds)!', 'woocommerce-google-adwords-conversion-tracking-tag' );
        ?>
					</span>
				</pre>

				<pre id="ga4-api-credentials-upload-status-error" style="display: none; white-space: pre-line;">
					<span style="color: red; font-weight: bold">
						<?php 
        esc_html_e( 'There was an error importing that file! Please try again.', 'woocommerce-google-adwords-conversion-tracking-tag' );
        ?>
					</span>
					<span id="ga4-api-credentials-upload-status-error-message"
						  style="color: red; font-weight: bold"></span>
				</pre>
			</div>
		</div>
		<?php 
        if ( isset( Options::get_ga4_data_api_credentials()['client_email'] ) && !Options::get_ga4_data_api_property_id() ) {
            echo '<p><span class="dashicons dashicons-info"></span>';
            esc_html_e( 'The GA4 Property ID needs to be set.', 'woocommerce-google-adwords-conversion-tracking-tag' );
        }
    }

    public function setting_html_g4_page_load_time_tracking() {
        // adding the hidden input is a hack to make WordPress save the option with the value zero,
        // instead of not saving it and remove that array key entirely
        // https://stackoverflow.com/a/1992745/4688612
        ?>
		<label>
			<input type="hidden" value="0" name="wgact_plugin_options[google][analytics][ga4][page_load_time_tracking]">
			<input type="checkbox"
				   id="pmw_setting_ga4_page_load_time_tracking"
				   name="wgact_plugin_options[google][analytics][ga4][page_load_time_tracking]"
				   value="1"
				<?php 
        checked( Options::is_ga4_page_load_time_tracking_active() );
        ?>
				<?php 
        esc_html_e( self::disable_if_demo() );
        ?>
			/>
			<?php 
        esc_html_e( 'GA4 page load time tracking.', 'woocommerce-google-adwords-conversion-tracking-tag' );
        ?>
		</label>
		<?php 
        self::display_status_icon( Options::is_ga4_page_load_time_tracking_active(), true, true );
        self::get_documentation_html_by_key( 'ga4_page_load_time_tracking' );
        self::html_pro_feature();
    }

    public function setting_html_google_user_id() {
        // adding the hidden input is a hack to make WordPress save the option with the value zero,
        // instead of not saving it and remove that array key entirely
        // https://stackoverflow.com/a/1992745/4688612
        ?>
		<label>
			<input type="hidden" value="0" name="wgact_plugin_options[google][user_id]">
			<input type="checkbox"
				   id="wpm_setting_google_user_id"
				   name="wgact_plugin_options[google][user_id]"
				   value="1"
				<?php 
        checked( Options::is_google_user_id_active() );
        ?>
				<?php 
        esc_html_e( self::disable_if_demo() );
        ?>
			/>
			<?php 
        esc_html_e( 'Enable Google user ID', 'woocommerce-google-adwords-conversion-tracking-tag' );
        ?>
		</label>
		<?php 
        self::display_status_icon( Options::is_google_user_id_active(), Options::is_google_active(), true );
        self::html_pro_feature();
        //        echo self::get_documentation_html('/wgact/?utm_source=woocommerce-plugin&utm_medium=documentation-link&utm_campaign=pixel-manager-for-woocommerce-docs&utm_content=google-consent-mode#/consent-mgmt/google-consent-mode');
        ?>
		<?php 
        if ( !Options::is_google_active() ) {
            echo '<p></p><span class="dashicons dashicons-info"></span>';
            esc_html_e( 'You need to activate GA4 and/or Google Ads', 'woocommerce-google-adwords-conversion-tracking-tag' );
            echo '</p><br>';
        }
    }

    public function setting_html_google_enhanced_conversions() {
        // adding the hidden input is a hack to make WordPress save the option with the value zero,
        // instead of not saving it and remove that array key entirely
        // https://stackoverflow.com/a/1992745/4688612
        ?>
		<label>
			<input type="hidden" value="0" name="wgact_plugin_options[google][ads][enhanced_conversions]">
			<input type="checkbox"
				   id="pmw_setting_google_enhanced_conversions"
				   name="wgact_plugin_options[google][ads][enhanced_conversions]"
				   value="1"
				<?php 
        checked( Options::is_google_enhanced_conversions_active() );
        ?>
				<?php 
        esc_html_e( self::disable_if_demo() );
        ?>
			/>
			<?php 
        esc_html_e( 'Enable Enhanced Conversions for Google Ads and GA4', 'woocommerce-google-adwords-conversion-tracking-tag' );
        ?>
		</label>
		<?php 
        self::display_status_icon( Options::is_google_enhanced_conversions_active(), Options::is_google_active(), false );
        self::get_documentation_html_by_key( 'google_enhanced_conversions' );
        self::wistia_video_icon( '73e5op37xg' );
        self::html_pro_feature();
        if ( !Options::is_google_active() ) {
            echo '<p></p><span class="dashicons dashicons-info"></span>';
            esc_html_e( 'You need to activate Google Ads and or GA4', 'woocommerce-google-adwords-conversion-tracking-tag' );
            echo '</p><br>';
        }
    }

    public function setting_html_google_ads_phone_conversion_number() {
        ?>
		<input class="pmw mono"
			   id="wpm_plugin_google_ads_phone_conversion_number"
			   name="wgact_plugin_options[google][ads][phone_conversion_number]"
			   size="40"
			   type="text"
			   value="<?php 
        esc_html_e( Options::get_google_ads_phone_conversion_number() );
        ?>"
			<?php 
        esc_html_e( self::disable_if_demo() );
        ?>
		/>
		<?php 
        self::display_status_icon( Options::get_google_ads_phone_conversion_number(), Options::get_google_ads_phone_conversion_label() && Options::get_google_ads_phone_conversion_number() );
        self::get_documentation_html_by_key( 'google_ads_phone_conversion_number' );
        self::html_pro_feature();
        echo '<br><br>';
        esc_html_e( 'The Google Ads phone conversion number must be in the same format as on the website.', 'woocommerce-google-adwords-conversion-tracking-tag' );
    }

    public function setting_html_google_ads_phone_conversion_label() {
        ?>
		<input class="pmw mono"
			   id="wpm_plugin_google_ads_phone_conversion_label"
			   name="wgact_plugin_options[google][ads][phone_conversion_label]"
			   size="40"
			   type="text"
			   value="<?php 
        esc_html_e( Options::get_google_ads_phone_conversion_label() );
        ?>"
			<?php 
        esc_html_e( self::disable_if_demo() );
        ?>
		/>
		<?php 
        self::display_status_icon( Options::get_google_ads_phone_conversion_label(), Options::get_google_ads_phone_conversion_label() && Options::get_google_ads_phone_conversion_number() );
        self::get_documentation_html_by_key( 'google_ads_phone_conversion_label' );
        self::html_pro_feature();
        echo '<br><br>';
        //        esc_html_e('The Google Ads phone conversion label must be in the same format as on the website.', 'woocommerce-google-adwords-conversion-tracking-tag');
    }

    public function setting_html_google_ads_conversion_adjustments_conversion_name() {
        ?>
		<input class="pmw mono"
			   id="pmw_plugin_google_ads_conversion_adjustments_conversion_name"
			   name="wgact_plugin_options[google][ads][conversion_adjustments][conversion_name]"
			   size="40"
			   type="text"
			   value="<?php 
        esc_html_e( Options::get_google_ads_conversion_adjustments_conversion_name() );
        ?>"
			<?php 
        esc_html_e( self::disable_if_demo() );
        ?>
		/>
		<?php 
        self::display_status_icon( Options::is_google_ads_conversion_adjustments_active(), Options::is_google_ads_active() );
        ?>
		<?php 
        self::get_documentation_html_by_key( 'google_ads_conversion_adjustments' );
        ?>
		<?php 
        self::html_pro_feature();
        ?>
		<div style="margin-top: 20px">
			<span class="dashicons dashicons-info" style="margin-right: 10px"></span>
			<?php 
        esc_html_e( 'The conversion name must match the conversion name in Google Ads exactly.', 'woocommerce-google-adwords-conversion-tracking-tag' );
        ?>
		</div>
		<?php 
        if ( !Options::is_google_ads_conversion_active() ) {
            ?>
			<span class="dashicons dashicons-info" style="padding-right: 10px"></span>
			<?php 
            esc_html_e( 'Requires an active Google Ads Conversion ID and Conversion Label.', 'woocommerce-google-adwords-conversion-tracking-tag' );
            echo '<br>';
        }
    }

    public function setting_html_google_ads_conversion_adjustments_feed() {
        $feed_url = get_site_url() . Pixel_Manager::get_instance()->get_google_ads_conversion_adjustments_endpoint();
        $text_length = strlen( $feed_url );
        ?>
		<div style="margin-top: 5px">

			<input class="pmw mono"
				   id="pmw_plugin_google_ads_conversion_adjustments_feed"
				   name="pmw_plugin_google_ads_conversion_adjustments_feed"
				   size="<?php 
        esc_html_e( $text_length );
        ?>"
				   type="text"
				   style="width:<?php 
        esc_html_e( $text_length );
        ?>ch"
				   value="<?php 
        echo esc_url( $feed_url );
        ?>"
				   disabled
			/>
			<script>
				const pmwCopyToClipboardCA = () => {

					// const feedUrlElement = document.getElementById("pmw_plugin_google_ads_conversion_adjustments_feed")
					// feedUrlElement.select()
					// feedUrlElement.setSelectionRange(0, 99999)
					navigator.clipboard.writeText(document.getElementById("pmw_plugin_google_ads_conversion_adjustments_feed").value);

					const pmwCaFeedTooltip     = document.getElementById("myPmwCaTooltip");
					pmwCaFeedTooltip.innerHTML = "<?php 
        esc_html_e( 'Copied feed URL to clipboard', 'woocommerce-google-adwords-conversion-tracking-tag' );
        ?>";
				};

				const resetCaCopyButton = () => {
					const pmwCaFeedTooltip     = document.getElementById("myPmwCaTooltip");
					pmwCaFeedTooltip.innerHTML = "<?php 
        esc_html_e( 'Copy to clipboard', 'woocommerce-google-adwords-conversion-tracking-tag' );
        ?>";
				};
			</script>
			<div class="pmwCaTooltip">
				<a href="javascript:void(0)" class="pmw-copy-icon pmwCaTooltip" onclick="pmwCopyToClipboardCA()"
				   onmouseout="resetCaCopyButton()"></a>
				<span class="pmwCaTooltiptext"
					  id="myPmwCaTooltip"><?php 
        esc_html_e( 'Copy to clipboard', 'woocommerce-google-adwords-conversion-tracking-tag' );
        ?></span>
			</div>
			<?php 
        self::display_status_icon( Options::is_google_ads_conversion_adjustments_active(), Options::is_google_ads_active() );
        ?>
			<?php 
        self::get_documentation_html_by_key( 'google_ads_conversion_adjustments' );
        ?>
			<?php 
        self::html_pro_feature();
        ?>
			<div style="margin-top: 20px">
				<?php 
        if ( !Options::is_google_ads_conversion_adjustments_active() ) {
            ?>
					<span class="dashicons dashicons-info" style="padding-right: 10px"></span>
					<?php 
            esc_html_e( 'The Conversion Name must be set.', 'woocommerce-google-adwords-conversion-tracking-tag' );
            ?>
				<?php 
        }
        ?>
			</div>
		</div>
		<?php 
    }

    public function info_html_automatic_email_link_tracking() {
        esc_html_e( 'Automatic email link click tracking is ', 'woocommerce-google-adwords-conversion-tracking-tag' );
        self::display_status_icon( Options::is_ga4_enabled() );
        self::html_pro_feature();
    }

    public function info_html_automatic_phone_link_click_tracking() {
        esc_html_e( 'Automatic phone link click tracking is ', 'woocommerce-google-adwords-conversion-tracking-tag' );
        self::display_status_icon( Options::is_ga4_enabled() );
        self::html_pro_feature();
    }

    public static function setting_html_cookiebot_support() {
        esc_html_e( 'Cookiebot detected. Automatic support is:', 'woocommerce-google-adwords-conversion-tracking-tag' );
        self::display_status_icon( true, true, true );
        self::html_pro_feature();
    }

    public static function setting_html_complianz_support() {
        esc_html_e( 'Complianz GDPR detected. Automatic support is:', 'woocommerce-google-adwords-conversion-tracking-tag' );
        self::display_status_icon( true, true, true );
        self::html_pro_feature();
    }

    public static function setting_html_cookie_notice_support() {
        esc_html_e( 'Cookie Notice (by hu-manity.co) detected. Automatic support is:', 'woocommerce-google-adwords-conversion-tracking-tag' );
        self::display_status_icon( true, true, true );
        self::html_pro_feature();
    }

    public static function setting_html_cookie_script_support() {
        esc_html_e( 'Cookie Script (by cookie-script.com) detected. Automatic support is:', 'woocommerce-google-adwords-conversion-tracking-tag' );
        self::display_status_icon( true, true, true );
        self::html_pro_feature();
    }

    public static function setting_html_moove_gdpr_support() {
        esc_html_e( 'GDPR Cookie Compliance (by Moove Agency) detected. Automatic support is:', 'woocommerce-google-adwords-conversion-tracking-tag' );
        self::display_status_icon( true, true, true );
        self::html_pro_feature();
    }

    public static function setting_html_cookieyes_support() {
        esc_html_e( 'CookieYes detected. Automatic support is', 'woocommerce-google-adwords-conversion-tracking-tag' );
        self::display_status_icon( true, true, true );
        self::html_pro_feature();
    }

    public static function setting_html_termly_support() {
        esc_html_e( 'Termly CMP detected. Automatic support is:', 'woocommerce-google-adwords-conversion-tracking-tag' );
        self::display_status_icon( true, true, true );
        self::html_pro_feature();
    }

    public function setting_html_facebook_capi_token() {
        ?>
		<textarea class="pmw mono"
				  id="wpm_setting_facebook_capi_token"
				  name="wgact_plugin_options[facebook][capi][token]"
				  cols="60"
				  rows="5"
			<?php 
        esc_html_e( self::disable_if_demo() );
        ?>><?php 
        esc_html_e( Options::get_facebook_capi_token() );
        ?></textarea>
		<?php 
        self::display_status_icon( Options::get_facebook_capi_token(), Options::is_facebook_active() );
        self::get_documentation_html_by_key( 'facebook_capi_token' );
        self::html_pro_feature();
        if ( !Options::is_facebook_active() ) {
            echo '<p></p><span class="dashicons dashicons-info"></span>';
            esc_html_e( 'You need to activate the Meta (Facebook) pixel', 'woocommerce-google-adwords-conversion-tracking-tag' );
            echo '</p><br>';
        }
        //        echo self::get_documentation_html('/wgact/?utm_source=woocommerce-plugin&utm_medium=documentation-link&utm_campaign=pixel-manager-for-woocommerce-docs&utm_content=google-ads-conversion-id#/pixels/google-ads?id=configure-the-plugin');
        echo '<br><br>';
        //        esc_html_e('The conversion ID looks similar to this:', 'woocommerce-google-adwords-conversion-tracking-tag');
        //        echo '&nbsp;<i>123456789</i>';
    }

    public function setting_html_facebook_capi_test_event_code() {
        $text_length = max( strlen( Options::get_facebook_capi_test_event_code() ), 9 );
        ?>
		<input class="pmw mono"
			   id="pmw_setting_facebook_capi_test_event_code"
			   name="wgact_plugin_options[facebook][capi][test_event_code]"
			   size="<?php 
        esc_html_e( $text_length );
        ?>"
			   type="text"
			   style="width:<?php 
        esc_html_e( $text_length );
        ?>ch"
			   value="<?php 
        esc_html_e( Options::get_facebook_capi_test_event_code() );
        ?>"
			<?php 
        esc_html_e( self::disable_if_demo() );
        ?>
		/>
		<?php 
        self::display_status_icon( Options::get_facebook_capi_test_event_code(), Options::get_facebook_capi_token() && Options::get_facebook_capi_test_event_code(), true );
        ?>
		<?php 
        //		self::get_documentation_html_by_key('facebook_capi_test_event_code');
        ?>
		<?php 
        self::html_pro_feature();
        ?>
		<div style="margin-top: 20px">
			<span class="dashicons dashicons-info" style="margin-right: 10px"></span>
			<?php 
        esc_html_e( "The test event code automatically rotates frequently within Facebook. If you don't see the server events flowing in, first make sure that you've set the latest test event code.", 'woocommerce-google-adwords-conversion-tracking-tag' );
        ?>
		</div>
		<?php 
    }

    public function setting_facebook_capi_user_transparency_process_anonymous_hits() {
        // adding the hidden input is a hack to make WordPress save the option with the value zero,
        // instead of not saving it and remove that array key entirely
        // https://stackoverflow.com/a/1992745/4688612
        ?>
		<label>
			<input type="hidden" value="0"
				   name="wgact_plugin_options[facebook][capi][user_transparency][process_anonymous_hits]">
			<input type="checkbox"
				   id="wpm_setting_facebook_capi_user_transparency_process_anonymous_hits"
				   name="wgact_plugin_options[facebook][capi][user_transparency][process_anonymous_hits]"
				   value="1"
				<?php 
        checked( Options::is_facebook_capi_user_transparency_process_anonymous_hits_active() );
        ?>
				<?php 
        esc_html_e( self::disable_if_demo() );
        ?>
			/>
			<?php 
        esc_html_e( 'Send CAPI hits for anonymous visitors who likely have blocked the Meta (Facebook) pixel.', 'woocommerce-google-adwords-conversion-tracking-tag' );
        ?>
		</label>
		<?php 
        self::display_status_icon( Options::is_facebook_capi_user_transparency_process_anonymous_hits_active(), Options::is_facebook_active(), true );
        self::get_documentation_html_by_key( 'facebook_capi_user_transparency_process_anonymous_hits' );
        self::html_pro_feature();
        if ( Options::is_facebook_capi_user_transparency_process_anonymous_hits_active() && !Options::is_facebook_active() ) {
            echo '<p></p><span class="dashicons dashicons-info"></span>';
            esc_html_e( 'You need to activate the Meta (Facebook) pixel', 'woocommerce-google-adwords-conversion-tracking-tag' );
            echo '</p><br>';
        }
    }

    public function setting_facebook_advanced_matching() {
        // adding the hidden input is a hack to make WordPress save the option with the value zero,
        // instead of not saving it and remove that array key entirely
        // https://stackoverflow.com/a/1992745/4688612
        ?>
		<label>
			<input type="hidden" value="0"
				   name="wgact_plugin_options[facebook][capi][user_transparency][send_additional_client_identifiers]">
			<input type="checkbox"
				   id="wpm_setting_facebook_capi_user_transparency_send_additional_client_identifiers"
				   name="wgact_plugin_options[facebook][capi][user_transparency][send_additional_client_identifiers]"
				   value="1"
				<?php 
        checked( Options::is_facebook_capi_advanced_matching_enabled() );
        ?>
				<?php 
        esc_html_e( self::disable_if_demo() );
        ?>
			/>
			<?php 
        esc_html_e( 'Send events with additional visitor identifiers, such as email and phone number, if available.', 'woocommerce-google-adwords-conversion-tracking-tag' );
        ?>
		</label>
		<?php 
        self::display_status_icon( Options::is_facebook_capi_advanced_matching_enabled(), Options::is_facebook_active(), true );
        self::get_documentation_html_by_key( 'facebook_advanced_matching' );
        self::html_pro_feature();
        if ( Options::is_facebook_capi_advanced_matching_enabled() && !Options::is_facebook_active() ) {
            echo '<p></p><span class="dashicons dashicons-info"></span>';
            esc_html_e( 'You need to activate the Meta (Facebook) pixel', 'woocommerce-google-adwords-conversion-tracking-tag' );
            echo '</p><br>';
        }
    }

    public function setting_html_facebook_domain_verification_id() {
        ?>
		<input class="pmw mono"
			   type="text"
			   id="wpm_setting_facebook_domain_verification_id"
			   name="wgact_plugin_options[facebook][domain_verification_id]"
			   size="40"
			   value="<?php 
        esc_html_e( Options::get_facebook_domain_verification_id() );
        ?>"
		/>
		<?php 
        self::display_status_icon( Options::get_facebook_domain_verification_id() );
        ?>
		<?php 
        self::get_documentation_html_by_key( 'facebook_domain_verification_id' );
        ?>
		<?php 
        self::html_pro_feature();
        ?>
		<br><br>
		<?php 
        esc_html_e( 'The Meta domain verification ID. It looks like this: uk6zwiftxsaywayn14x0aouhz4fhd', 'woocommerce-google-adwords-conversion-tracking-tag' );
    }

    // TODO: Added deprecated message on 26.04.2024
    public function setting_html_facebook_microdata() {
        // adding the hidden input is a hack to make WordPress save the option with the value zero,
        // instead of not saving it and remove that array key entirely
        // https://stackoverflow.com/a/1992745/4688612
        ?>
		<label>
			<input type="hidden" value="0" name="wgact_plugin_options[facebook][microdata]">
			<input type="checkbox"
				   id="wpm_setting_facebook_microdata_active"
				   name="wgact_plugin_options[facebook][microdata]"
				   value="1"
				<?php 
        checked( Options::is_facebook_microdata_active() );
        ?>
				<?php 
        esc_html_e( self::disable_if_demo() );
        ?>
			/>
			<?php 
        esc_html_e( 'Enable Meta (Facebook) product microdata output', 'woocommerce-google-adwords-conversion-tracking-tag' );
        ?>
		</label>
		<?php 
        self::display_status_icon( Options::is_facebook_microdata_active(), Options::is_facebook_active(), true );
        self::get_documentation_html_by_key( 'facebook_microdata' );
        self::html_status_icon_deprecated();
        self::html_pro_feature();
        echo '<p></p><span class="dashicons dashicons-info"></span>';
        esc_html_e( 'The Facebook Microdata feature in the Pixel Manager has been deprecated. Please use a dedicated feed plugin for this purpose. The feature will be removed in a future version.', 'woocommerce-google-adwords-conversion-tracking-tag' );
        echo '</p><br>';
    }

    public function setting_html_pinterest_ad_account_id() {
        $text_length = max( strlen( Options::get_pinterest_ad_account_id() ), 40 );
        ?>
		<input class="pmw mono"
			   id="pmw_setting_pinterest_ad_account_id"
			   name="wgact_plugin_options[pinterest][ad_account_id]"
			   size="<?php 
        esc_html_e( $text_length );
        ?>"
			   type="text"
			   value="<?php 
        esc_html_e( Options::get_pinterest_ad_account_id() );
        ?>"
			   style="width:<?php 
        esc_html_e( $text_length );
        ?>ch"
			<?php 
        esc_html_e( self::disable_if_demo() );
        ?>
		/>
		<?php 
        self::display_status_icon( Options::get_pinterest_ad_account_id(), Options::is_pinterest_active() );
        self::get_documentation_html_by_key( 'pinterest_ad_account_id' );
        self::html_pro_feature();
    }

    public function setting_html_pinterest_apic_token() {
        ?>
		<textarea class="pmw mono"
				  id="pmw_setting_pinterest_apic_token"
				  name="wgact_plugin_options[pinterest][apic][token]"
				  cols="50"
				  rows="2"
			<?php 
        esc_html_e( self::disable_if_demo() );
        ?>><?php 
        esc_html_e( Options::get_pinterest_apic_token() );
        ?></textarea>

		<?php 
        self::display_status_icon( Options::get_pinterest_apic_token(), Options::is_pinterest_active() );
        self::get_documentation_html_by_key( 'pinterest_apic_token' );
        self::html_pro_feature();
        if ( !Options::is_pinterest_active() ) {
            ?>
			<br><span class="dashicons dashicons-info"></span>
			<?php 
            esc_html_e( 'You need to activate the Pinterest pixel', 'woocommerce-google-adwords-conversion-tracking-tag' );
            ?>
			<?php 
        }
    }

    public function setting_pinterest_apic_process_anonymous_hits() {
        // adding the hidden input is a hack to make WordPress save the option with the value zero,
        // instead of not saving it and remove that array key entirely
        // https://stackoverflow.com/a/1992745/4688612
        ?>
		<label>
			<input type="hidden" value="0" name="wgact_plugin_options[pinterest][apic][process_anonymous_hits]">
			<input type="checkbox"
				   id="pmw_setting_pinterest_apic_user_transparency_process_anonymous_hits"
				   name="wgact_plugin_options[pinterest][apic][process_anonymous_hits]"
				   value="1"
				<?php 
        checked( Options::is_pinterest_apic_process_anonymous_hits_active() );
        ?>
				<?php 
        esc_html_e( self::disable_if_demo() );
        ?>
			/>
			<?php 
        esc_html_e( 'Send Events API hits for anonymous visitors who likely have blocked the Pinterest pixel.', 'woocommerce-google-adwords-conversion-tracking-tag' );
        ?>
		</label>
		<?php 
        self::display_status_icon( Options::is_pinterest_apic_process_anonymous_hits_active(), Options::is_pinterest_active() && Options::get_pinterest_apic_token(), true );
        self::get_documentation_html_by_key( 'pinterest_apic_process_anonymous_hits' );
        self::html_pro_feature();
        if ( Options::is_pinterest_apic_process_anonymous_hits_active() && !Options::is_pinterest_active() ) {
            echo '<p></p><span class="dashicons dashicons-info"></span>';
            esc_html_e( 'You need to activate the Pinterest pixel', 'woocommerce-google-adwords-conversion-tracking-tag' );
            echo '</p><br>';
        }
    }

    public function setting_pinterest_advanced_matching() {
        // adding the hidden input is a hack to make WordPress save the option with the value zero,
        // instead of not saving it and remove that array key entirely
        // https://stackoverflow.com/a/1992745/4688612
        ?>
		<label>
			<input type="hidden" value="0" name="wgact_plugin_options[pinterest][advanced_matching]">
			<input type="checkbox"
				   id="pmw_setting_pinterest_user_transparency_advanced_matching"
				   name="wgact_plugin_options[pinterest][advanced_matching]"
				   value="1"
				<?php 
        checked( Options::is_pinterest_advanced_matching_active() );
        ?>
				<?php 
        esc_html_e( self::disable_if_demo() );
        ?>
			/>
			<?php 
        esc_html_e( 'Send events with additional visitor identifiers, such as email and phone number, if available.', 'woocommerce-google-adwords-conversion-tracking-tag' );
        ?>
		</label>
		<?php 
        self::display_status_icon( Options::is_pinterest_advanced_matching_active(), Options::is_pinterest_active() && Options::get_pinterest_apic_token(), true );
        self::get_documentation_html_by_key( 'pinterest_advanced_matching' );
        self::html_pro_feature();
        if ( Options::is_pinterest_advanced_matching_active() && !Options::is_pinterest_active() ) {
            echo '<p></p><span class="dashicons dashicons-info"></span>';
            esc_html_e( 'You need to activate the Pinterest pixel', 'woocommerce-google-adwords-conversion-tracking-tag' );
            echo '</p><br>';
        }
    }

    public function setting_html_tiktok_eapi_token() {
        $text_length = max( strlen( Options::get_tiktok_eapi_token() ), 40 );
        ?>
		<input class="pmw mono"
			   id="pmw_setting_tiktok_eapi_token"
			   name="wgact_plugin_options[tiktok][eapi][token]"
			   size="<?php 
        esc_html_e( $text_length );
        ?>"
			   type="text"
			   value="<?php 
        esc_html_e( Options::get_tiktok_eapi_token() );
        ?>"
			   style="width:<?php 
        esc_html_e( $text_length );
        ?>ch"
			<?php 
        esc_html_e( self::disable_if_demo() );
        ?>
		/>
		<?php 
        self::display_status_icon( Options::get_tiktok_eapi_token(), Options::is_tiktok_active() );
        self::get_documentation_html_by_key( 'tiktok_eapi_token' );
        self::html_pro_feature();
        if ( !Options::is_tiktok_active() ) {
            ?>
			<br><span class="dashicons dashicons-info"></span>
			<?php 
            esc_html_e( 'You need to activate the TikTok pixel', 'woocommerce-google-adwords-conversion-tracking-tag' );
            ?>
			<?php 
        }
        //        echo self::get_documentation_html('/wgact/?utm_source=woocommerce-plugin&utm_medium=documentation-link&utm_campaign=pixel-manager-for-woocommerce-docs&utm_content=google-ads-conversion-id#/pixels/google-ads?id=configure-the-plugin');
        //        esc_html_e('The conversion ID looks similar to this:', 'woocommerce-google-adwords-conversion-tracking-tag');
        //        echo '&nbsp;<i>123456789</i>';
    }

    public function setting_html_tiktok_eapi_test_event_code() {
        $text_length = max( strlen( Options::get_tiktok_eapi_test_event_code() ), 9 );
        ?>
		<input class="pmw mono"
			   id="pmw_setting_tiktok_eapi_test_event_code"
			   name="wgact_plugin_options[tiktok][eapi][test_event_code]"
			   size="<?php 
        esc_html_e( $text_length );
        ?>"
			   type="text"
			   style="width:<?php 
        esc_html_e( $text_length );
        ?>ch"
			   value="<?php 
        esc_html_e( Options::get_tiktok_eapi_test_event_code() );
        ?>"
			<?php 
        esc_html_e( self::disable_if_demo() );
        ?>
		/>
		<?php 
        self::display_status_icon( Options::get_tiktok_eapi_test_event_code(), Options::get_tiktok_eapi_token() && Options::get_tiktok_eapi_test_event_code(), true );
        ?>

		<?php 
        //		self::get_documentation_html_by_key('facebook_capi_test_event_code');
        ?>
		<?php 
        self::html_pro_feature();
        ?>
		<div style="margin-top: 20px">
			<span class="dashicons dashicons-info" style="margin-right: 10px"></span>
			<?php 
        esc_html_e( "The test event code automatically rotates frequently within TikTok. If you don't see the server events flowing in, first make sure that you've set the latest test event code.", 'woocommerce-google-adwords-conversion-tracking-tag' );
        ?>
		</div>
		<?php 
    }

    public function setting_tiktok_eapi_process_anonymous_hits() {
        // adding the hidden input is a hack to make WordPress save the option with the value zero,
        // instead of not saving it and remove that array key entirely
        // https://stackoverflow.com/a/1992745/4688612
        ?>
		<label>
			<input type="hidden" value="0" name="wgact_plugin_options[tiktok][eapi][process_anonymous_hits]">
			<input type="checkbox"
				   id="pmw_setting_tiktok_eapi_process_anonymous_hits"
				   name="wgact_plugin_options[tiktok][eapi][process_anonymous_hits]"
				   value="1"
				<?php 
        checked( Options::is_tiktok_eapi_process_anonymous_hits_active() );
        ?>
				<?php 
        esc_html_e( self::disable_if_demo() );
        ?>
			/>
			<?php 
        esc_html_e( 'Send Events API hits for anonymous visitors who likely have blocked the TikTok pixel.', 'woocommerce-google-adwords-conversion-tracking-tag' );
        ?>
		</label>
		<?php 
        self::display_status_icon( Options::is_tiktok_eapi_process_anonymous_hits_active(), Options::is_tiktok_active() && Options::get_tiktok_eapi_token(), true );
        self::get_documentation_html_by_key( 'tiktok_eapi_process_anonymous_hits' );
        self::html_pro_feature();
        if ( Options::is_tiktok_eapi_process_anonymous_hits_active() && !Options::is_tiktok_active() ) {
            echo '<p></p><span class="dashicons dashicons-info"></span>';
            esc_html_e( 'You need to activate the TikTok pixel', 'woocommerce-google-adwords-conversion-tracking-tag' );
            echo '</p><br>';
        }
    }

    public function setting_tiktok_advanced_matching() {
        // adding the hidden input is a hack to make WordPress save the option with the value zero,
        // instead of not saving it and remove that array key entirely
        // https://stackoverflow.com/a/1992745/4688612
        ?>
		<label>
			<input type="hidden" value="0" name="wgact_plugin_options[tiktok][advanced_matching]">
			<input type="checkbox"
				   id="pmw_setting_tiktok_advanced_matching"
				   name="wgact_plugin_options[tiktok][advanced_matching]"
				   value="1"
				<?php 
        checked( Options::is_tiktok_advanced_matching_enabled() );
        ?>
				<?php 
        esc_html_e( self::disable_if_demo() );
        ?>
			/>
			<?php 
        esc_html_e( 'Send events with additional visitor identifiers, such as email and phone number, if available.', 'woocommerce-google-adwords-conversion-tracking-tag' );
        ?>
		</label>
		<?php 
        self::display_status_icon( Options::is_tiktok_advanced_matching_enabled(), Options::is_tiktok_active() && Options::get_tiktok_eapi_token(), true );
        self::get_documentation_html_by_key( 'tiktok_advanced_matching' );
        self::html_pro_feature();
        if ( Options::is_tiktok_advanced_matching_enabled() && !Options::is_tiktok_active() ) {
            echo '<p></p><span class="dashicons dashicons-info"></span>';
            esc_html_e( 'You need to activate the TikTok pixel', 'woocommerce-google-adwords-conversion-tracking-tag' );
            echo '</p><br>';
        }
    }

    public function setting_html_order_duplication_prevention() {
        // adding the hidden input is a hack to make WordPress save the option with the value zero,
        // instead of not saving it and remove that array key entirely
        // https://stackoverflow.com/a/1992745/4688612
        ?>
		<label>
			<input type="hidden" value="0" name="wgact_plugin_options[shop][order_deduplication]">
			<input type="checkbox"
				   id="wpm_setting_order_duplication_prevention"
				   name="wgact_plugin_options[shop][order_deduplication]"
				   value="1"
				<?php 
        checked( Options::is_order_duplication_prevention_option_active() );
        ?>
			/>
			<?php 
        $this->get_order_duplication_prevention_text();
        ?>
		</label>
		<?php 
        self::display_status_icon( Options::is_order_duplication_prevention_option_active() );
        ?>
		<?php 
        self::get_documentation_html_by_key( 'duplication_prevention' );
        ?>

		<br>
		<p>
			<span class="dashicons dashicons-info"></span>
			<?php 
        esc_html_e( 'Only disable order duplication prevention for testing.', 'woocommerce-google-adwords-conversion-tracking-tag' );
        ?>
		</p>
		<p>
			<span class="dashicons dashicons-info"></span>
			<?php 
        esc_html_e( 'Automatically reactivates 6 hours after disabling duplication prevention.', 'woocommerce-google-adwords-conversion-tracking-tag' );
        ?>
		</p>
		<?php 
    }

    public function setting_html_maximum_compatibility_mode() {
        // adding the hidden input is a hack to make WordPress save the option with the value zero,
        // instead of not saving it and remove that array key entirely
        // https://stackoverflow.com/a/1992745/4688612
        ?>
		<label>
			<input type="hidden" value="0" name="wgact_plugin_options[general][maximum_compatibility_mode]">
			<input type="checkbox"
				   id="wpm_setting_maximum_compatibility_mode"
				   name="wgact_plugin_options[general][maximum_compatibility_mode]"
				   value="1"
				<?php 
        checked( Options::is_maximum_compatiblity_mode_active() );
        ?>
			/>
			<?php 
        esc_html_e( 'Enable the maximum compatibility mode', 'woocommerce-google-adwords-conversion-tracking-tag' );
        ?>
		</label>
		<?php 
        self::display_status_icon( Options::is_maximum_compatiblity_mode_active(), true, true );
        self::get_documentation_html_by_key( 'maximum_compatibility_mode' );
    }

    public function setting_html_disable_tracking_for_user_roles() {
        // https://semantic-ui.com/modules/dropdown.html#multiple-selection
        // https://developer.woocommerce.com/2017/08/08/selectwoo-an-accessible-replacement-for-select2/
        // https://github.com/woocommerce/selectWoo
        ?>
		<select id="wpm_setting_disable_tracking_for_user_roles"
				multiple="multiple"
				name="wgact_plugin_options[shop][disable_tracking_for][]"
				style="width:350px; padding-left: 10px"
				data-placeholder="Choose roles&hellip;" aria-label="Roles"
				class="wc-enhanced-select"
			<?php 
        esc_html_e( self::disable_if_demo() );
        ?>
		>
			<?php 
        foreach ( get_editable_roles() as $role => $details ) {
            ?>
				<option value="<?php 
            esc_html_e( $role );
            ?>" <?php 
            esc_html_e( ( in_array( $role, Options::get_excluded_roles(), true ) ? 'selected' : '' ) );
            ?>><?php 
            esc_html_e( $details['name'] );
            ?></option>
			<?php 
        }
        ?>

		</select>
		<script>
			jQuery("#wpm_setting_disable_tracking_for_user_roles").select2({
				// theme: "classic"
			});
		</script>
		<?php 
        //		self::get_documentation_html_by_key('google_consent_regions');
        self::html_pro_feature();
    }

    public function info_html_acr() {
        esc_html_e( 'Automatic Conversion Recovery (ACR) is ', 'woocommerce-google-adwords-conversion-tracking-tag' );
        self::display_status_icon( wpm_fs()->can_use_premium_code__premium_only() );
        self::html_pro_feature();
        self::get_documentation_html_by_key( 'acr' );
    }

    public function info_html_order_list_info() {
        // adding the hidden input is a hack to make WordPress save the option with the value zero,
        // instead of not saving it and remove that array key entirely
        // https://stackoverflow.com/a/1992745/4688612
        ?>
		<label>
			<input type="hidden" value="0" name="wgact_plugin_options[shop][order_list_info]">
			<input type="checkbox"
				   id="pmw_setting_order_list_info"
				   name="wgact_plugin_options[shop][order_list_info]"
				   value="1"
				<?php 
        checked( Options::is_shop_order_list_info_enabled() );
        ?>
			/>

			<?php 
        esc_html_e( 'Display Pixel Manager related information on the order list page', 'woocommerce-google-adwords-conversion-tracking-tag' );
        ?>
		</label>
		<?php 
        self::display_status_icon( Options::is_shop_order_list_info_enabled() );
        ?>
		<?php 
        self::get_documentation_html_by_key( 'order_list_info' );
    }

    public function info_html_scroll_tracker_thresholds() {
        ?>
		<input class="pmw mono"
			   id="pmw_setting_scroll_tracker_thresholds"
			   name="wgact_plugin_options[general][scroll_tracker_thresholds]"
			   size="40"
			   type="text"
			   value="<?php 
        esc_html_e( implode( ',', Options::get_scroll_tracking_thresholds() ) );
        ?>"
			<?php 
        esc_html_e( self::disable_if_demo() );
        ?>
		/>
		<?php 
        self::display_status_icon( !empty( Options::get_scroll_tracking_thresholds() ), true, true );
        ?>
		<?php 
        self::get_documentation_html_by_key( 'scroll_tracker_threshold' );
        ?>
		<?php 
        self::html_pro_feature();
        ?>
		<div style="margin-top: 10px">
			<?php 
        esc_html_e( 'The Scroll Tracker thresholds. A comma separated list of scroll tracking thresholds in percent where the scroll tracker triggers its events.', 'woocommerce-google-adwords-conversion-tracking-tag' );
        ?>
		</div>
		<?php 
    }

    public function html_subscription_value_multiplier() {
        $field_width = max( strlen( Options::get_subscription_multiplier() ), 3 );
        ?>
		<input class="pmw mono"
			   id="pmw_setting_subscription_value_multiplier"
			   name="wgact_plugin_options[shop][subscription_value_multiplier]"
			   size="<?php 
        esc_html_e( $field_width );
        ?>"
			   type="text"
			   style="width:<?php 
        esc_html_e( $field_width );
        ?>ch"
			   value="<?php 
        esc_html_e( Options::get_subscription_multiplier() );
        ?>"
			<?php 
        esc_html_e( self::disable_if_demo() );
        ?>
		/>
		<?php 
        self::get_documentation_html_by_key( 'subscription_value_multiplier' );
        ?>
		<?php 
        self::html_pro_feature();
        ?>
		<div style="margin-top: 10px">
			<?php 
        esc_html_e( 'The multiplier multiplies the conversion value output for initial subscriptions to match the CLV of a subscription more closely.', 'woocommerce-google-adwords-conversion-tracking-tag' );
        ?>
		</div>
		<?php 
    }

    public function html_ltv_calculation_on_orders() {
        // adding the hidden input is a hack to make WordPress save the option with the value zero,
        // instead of not saving it and remove that array key entirely
        // https://stackoverflow.com/a/1992745/4688612
        ?>
		<label>
			<input type="hidden" value="0" name="wgact_plugin_options[shop][ltv][order_calculation][is_active]">
			<input type="checkbox"
				   id="pmw_setting_ltv_on_orders"
				   name="wgact_plugin_options[shop][ltv][order_calculation][is_active]"
				   value="1"
				<?php 
        checked( Options::is_order_level_ltv_calculation_active() );
        ?>
			/>

			<?php 
        esc_html_e( 'Enable the lifetime value calculation on new orders.', 'woocommerce-google-adwords-conversion-tracking-tag' );
        ?>
		</label>
		<?php 
        self::display_status_icon( Options::is_order_level_ltv_calculation_active() );
        ?>
		<?php 
        self::get_documentation_html_by_key( 'ltv_order_calculation' );
    }

    public function html_ltv_automatic_recalculation() {
        // adding the hidden input is a hack to make WordPress save the option with the value zero,
        // instead of not saving it and remove that array key entirely
        // https://stackoverflow.com/a/1992745/4688612
        ?>
		<label>
			<input type="hidden" value="0" name="wgact_plugin_options[shop][ltv][automatic_recalculation][is_active]">
			<input type="checkbox"
				   id="pmw_setting_ltv_automatic_recalculation"
				   name="wgact_plugin_options[shop][ltv][automatic_recalculation][is_active]"
				   value="1"
				<?php 
        checked( Options::is_automatic_ltv_recalculation_active() );
        ?>
			/>

			<?php 
        esc_html_e( 'Enable the automatic detection and recalculation of the lifetime value.', 'woocommerce-google-adwords-conversion-tracking-tag' );
        ?>
		</label>
		<?php 
        self::display_status_icon( Options::is_automatic_ltv_recalculation_active() );
        ?>
		<?php 
        self::get_documentation_html_by_key( 'ltv_recalculation' );
    }

    public function ltv_manual_recalculation() {
        // Add a button with the text "Schedule LTV recalculation" and a confirmation dialog
        // Add a hidden div, that will be shown after the button is clicked, with a confirmation message
        ?>
		<div style="display: flex;">
			<button id="wgact_ltv_recalculation" class="button button-primary" style="margin-top: 0">
			  <span id="ltv-schedule-recalculation-button-text"
					class="ltv-button-text"
					data-action="schedule_ltv_recalculation"
			  >
			   <?php 
        esc_html_e( 'Schedule LTV recalculation', 'woocommerce-google-adwords-conversion-tracking-tag' );
        ?>
			  </span>
				<span id="ltv-instant-recalculation-button-text"
					  class="ltv-button-text"
					  style="display: none;"
					  data-action="run_ltv_recalculation"
				>
				   <?php 
        esc_html_e( 'Instant LTV recalculation', 'woocommerce-google-adwords-conversion-tracking-tag' );
        ?>
				  </span>
			</button>
			<button id="pmw_stop_ltv_calculation"
					class="button button-primary"
					style="margin-top: 0; margin-left: 10px;"
					data-action="stop_ltv_recalculation"
			>
				<?php 
        esc_html_e( 'Stop all LTV calculations', 'woocommerce-google-adwords-conversion-tracking-tag' );
        ?>
			</button>
			<div id="ltv-schedule-recalculation-confirmation-message"
				 class="ltv-message"
				 style="display: none; margin-left: 10px;"
			>
				<?php 
        esc_html_e( 'Recalculation has been scheduled for a run over night. Click one more time to start the recalculation immediately.', 'woocommerce-google-adwords-conversion-tracking-tag' );
        ?>
			</div>
			<div id="ltv-running-recalculation-confirmation-message"
				 class="ltv-message"
				 style="display: none; margin-left: 10px;"
			>
				<?php 
        esc_html_e( 'The recalculation is running.', 'woocommerce-google-adwords-conversion-tracking-tag' );
        ?>
			</div>
			<div id="ltv-message-error"
				 class="ltv-message"
				 style="display: none; margin-left: 10px;"
			>
				  <span id="ltv-message-error-text">
				  </span>
			</div>
			<?php 
        self::get_documentation_html_by_key( 'ltv_recalculation' );
        ?>
		</div>
		<?php 
    }

    public function html_lazy_load_pmw() {
        // adding the hidden input is a hack to make WordPress save the option with the value zero,
        // instead of not saving it and remove that array key entirely
        // https://stackoverflow.com/a/1992745/4688612
        ?>
		<label>
			<input type="hidden" value="0" name="wgact_plugin_options[general][lazy_load_pmw]">
			<input type="checkbox"
				   id="pmw_setting_lazy_load_pmw"
				   name="wgact_plugin_options[general][lazy_load_pmw]"
				   value="1"
				<?php 
        checked( Options::is_lazy_load_pmw_active() );
        ?>
				<?php 
        esc_html_e( self::disable_if_demo() );
        ?>
			/>

			<?php 
        esc_html_e( 'Lazy load the Pixel Manager', 'woocommerce-google-adwords-conversion-tracking-tag' );
        ?>
		</label>
		<?php 
        self::display_status_icon( Options::is_lazy_load_pmw_active(), Options::lazy_load_requirements(), true );
        ?>
		<?php 
        self::get_documentation_html_by_key( 'lazy_load_pmw' );
        ?>
		<?php 
        self::html_pro_feature();
        ?>
		<?php 
        if ( Options::is_lazy_load_pmw_active() && !Options::lazy_load_requirements() ) {
            ?>
			<p>
				<span class="dashicons dashicons-info"></span>
				<?php 
            esc_html_e( 'Google Optimize is active, but the Google Optimize anti flicker snippet is not. You need to activate the Google Optimize anti flicker snippet, or deactivate Google Optimize.', 'woocommerce-google-adwords-conversion-tracking-tag' );
            ?>
			</p>
		<?php 
        }
        ?>

		<p>
			<span class="dashicons dashicons-info"></span>
			<?php 
        esc_html_e( 'Enabling this feature will give you better page speed scores. Please read the documentation to learn more about the full implications while using this feature.', 'woocommerce-google-adwords-conversion-tracking-tag' );
        ?>
		</p>

		<?php 
    }

    private function get_order_duplication_prevention_text() {
        esc_html_e( 'Basic order duplication prevention is ', 'woocommerce-google-adwords-conversion-tracking-tag' );
    }

    private function add_to_cart_requirements_fulfilled() {
        if ( Options::get_google_ads_conversion_id() && Options::get_google_ads_conversion_label() && Options::get_google_ads_merchant_id() ) {
            return true;
        }
        return false;
    }

    public function option_html_dynamic_remarketing() {
        esc_html_e( 'Dynamic Remarketing is', 'woocommerce-google-adwords-conversion-tracking-tag' );
        self::display_status_icon( true );
        self::get_documentation_html_by_key( 'dynamic_remarketing' );
    }

    public static function html_logger_activation() {
        // adding the hidden input is a hack to make WordPress save the option with the value zero,
        // instead of not saving it and remove that array key entirely
        // https://stackoverflow.com/a/1992745/4688612
        ?>
		<label>
			<input type="hidden" value="0" name="wgact_plugin_options[general][logger][is_active]">
			<input type="checkbox"
				   id="wpm_plugin_option_logger"
				   name="wgact_plugin_options[general][logger][is_active]"
				   value="1"
				<?php 
        checked( Options::is_logging_enabled() );
        ?>
			/>

			<?php 
        esc_html_e( 'Enable logger', 'woocommerce-google-adwords-conversion-tracking-tag' );
        ?>
		</label>
		<?php 
        self::display_status_icon( Options::is_logging_enabled(), true, true );
        ?>
		<?php 
        self::get_documentation_html_by_key( 'logger_activation' );
        ?>
		<?php 
        // self::wistia_video_icon('7fhtv2s94t');
        ?>
		<?php 
    }

    public static function html_log_level() {
        ?>
		<select id="wpm_plugin_option_log_level" name="wgact_plugin_options[general][logger][level]">
			<?php 
        foreach ( Logger::get_log_levels() as $log_level_number => $log_level_name ) {
            ?>
				<option value="<?php 
            esc_html_e( $log_level_name );
            ?>"
					<?php 
            selected( $log_level_name, Options::get_log_level() );
            ?>
				>
					<?php 
            esc_html_e( $log_level_number . ' - ' . $log_level_name );
            ?>
				</option>
			<?php 
        }
        ?>
		</select>
		<?php 
        self::get_documentation_html_by_key( 'log_level' );
        ?>
		<?php 
        // self::wistia_video_icon('7fhtv2s94t');
        ?>
		<?php 
    }

    public static function html_log_outgoing_http_requests() {
        // adding the hidden input is a hack to make WordPress save the option with the value zero,
        // instead of not saving it and remove that array key entirely
        // https://stackoverflow.com/a/1992745/4688612
        ?>
		<label>
			<input type="hidden" value="0" name="wgact_plugin_options[general][logger][log_http_requests]">
			<input type="checkbox"
				   id="wpm_plugin_option_log_http_requests"
				   name="wgact_plugin_options[general][logger][log_http_requests]"
				   value="1"
				<?php 
        checked( Options::is_http_request_logging_enabled() );
        ?>
			/>
			<?php 
        esc_html_e( 'Enable HTTP request logging', 'woocommerce-google-adwords-conversion-tracking-tag' );
        ?>
		</label>
		<?php 
        self::display_status_icon( Options::is_http_request_logging_enabled(), true, true );
        ?>
		<?php 
        self::get_documentation_html_by_key( 'log_http_requests' );
        ?>
		<?php 
        // self::wistia_video_icon('7fhtv2s94t');
        ?>
		<p>

		</p>
		<span class="dashicons dashicons-info"></span>
		<?php 
        esc_html_e( "This feature switches web requests from asynchronous (faster, non-blocking) to synchronous (slower, blocking) to record responses. It allows the responses to be analyzed, but also uses more server resources. It's meant for troubleshooting and will turn off automatically after 12 hours. You can extend this time if needed. See the user guide for more details.", 'woocommerce-google-adwords-conversion-tracking-tag' );
        ?>
		</p>
		<?php 
    }

    public static function html_log_files() {
        $source = 'pmw';
        $admin_url_link_recent_wc_log = Helpers::get_admin_url_link_to_recent_wc_log( $source );
        ?>

		<button id="wgact_show_recent_log_file"
				class="button button-primary"
				style="margin-top: 0"
				onclick="window.open('<?php 
        echo esc_url( $admin_url_link_recent_wc_log );
        ?>')"
			<?php 
        disabled( !$admin_url_link_recent_wc_log );
        ?>
		>
			<?php 
        if ( $admin_url_link_recent_wc_log ) {
            ?>
				<?php 
            esc_html_e( 'Show recent log file', 'woocommerce-google-adwords-conversion-tracking-tag' );
            ?>
			<?php 
        } else {
            ?>
				<?php 
            esc_html_e( 'No log file found to view', 'woocommerce-google-adwords-conversion-tracking-tag' );
            ?>
			<?php 
        }
        ?>

		</button>
		<?php 
        // Add a button with which the most recent log file can be downloaded
        $external_url_to_most_recent_log = Helpers::get_external_url_to_most_recent_log( $source );
        ?>

		<button id="wgact_download_log_file"
				class="button button-primary"
				style="margin-top: 0"
				onclick="window.open('<?php 
        echo esc_url( $external_url_to_most_recent_log );
        ?>')"
			<?php 
        disabled( !$external_url_to_most_recent_log );
        ?>
		>
			<?php 
        if ( $external_url_to_most_recent_log ) {
            ?>
				<?php 
            esc_html_e( 'Download recent log file', 'woocommerce-google-adwords-conversion-tracking-tag' );
            ?>
			<?php 
        } else {
            ?>
				<?php 
            esc_html_e( 'No log file found to download', 'woocommerce-google-adwords-conversion-tracking-tag' );
            ?>
			<?php 
        }
        ?>
		</button>

		<?php 
        // Button to copy all log file links to the clipboard
        $all_external_log_file_urls = Helpers::get_all_external_log_file_urls( $source );
        ?>

		<button id="wgact_copy_log_file_links"
				class="button button-primary"
				style="margin-top: 0"
				data-links="<?php 
        echo wc_esc_json( $all_external_log_file_urls );
        ?>"
				data-text-copied="<?php 
        esc_html_e( 'Copied!', 'woocommerce-google-adwords-conversion-tracking-tag' );
        ?>"
			<?php 
        disabled( !$all_external_log_file_urls );
        ?>
		>
			<?php 
        if ( $all_external_log_file_urls ) {
            ?>
				<?php 
            esc_html_e( 'Copy log file links', 'woocommerce-google-adwords-conversion-tracking-tag' );
            ?>
			<?php 
        } else {
            ?>
				<?php 
            esc_html_e( 'No log file links found to copy', 'woocommerce-google-adwords-conversion-tracking-tag' );
            ?>
			<?php 
        }
        ?>
		</button>
		<?php 
        self::get_documentation_html_by_key( 'log_files' );
        ?>
		<?php 
    }

    public function option_html_variations_output() {
        // adding the hidden input is a hack to make WordPress save the option with the value zero,
        // instead of not saving it and remove that array key entirely
        // https://stackoverflow.com/a/1992745/4688612
        ?>
		<label>
			<input type="hidden" value="0" name="wgact_plugin_options[general][variations_output]">
			<input type="checkbox"
				   id="wpm_plugin_option_variations_output"
				   name="wgact_plugin_options[general][variations_output]"
				   value="1"
				<?php 
        checked( Options::is_shop_variations_output_active() );
        ?>
			/>
			<?php 
        esc_html_e( 'Enable variations output', 'woocommerce-google-adwords-conversion-tracking-tag' );
        ?>
		</label>
		<?php 
        self::display_status_icon( Options::is_shop_variations_output_active(), true, true );
        ?>
		<?php 
        self::get_documentation_html_by_key( 'variations_output' );
        ?>
		<p>
			<span class="dashicons dashicons-info"></span>
			<?php 
        esc_html_e( 'In order for this to work you need to upload your product feed including product variations and the item_group_id. Disable it, if you choose only to upload the parent product for variable products.', 'woocommerce-google-adwords-conversion-tracking-tag' );
        ?>
		</p>
		<?php 
    }

    public function plugin_option_google_business_vertical() {
        ?>
		<div style="display: inline-block;">

			<label>
				<input type="radio"
					   id="wpm_plugin_google_business_vertical_0"
					   name="wgact_plugin_options[google][ads][google_business_vertical]"
					   value="0"
					<?php 
        echo checked( 0, Options::get_google_ads_business_vertical_id(), false );
        ?>
					<?php 
        esc_html_e( self::disable_if_demo() );
        ?>
				/>
				<?php 
        esc_html_e( 'Retail', 'woocommerce-google-adwords-conversion-tracking-tag' );
        ?>
			</label>
			<br>
			<label>
				<input type="radio"
					   id="wpm_plugin_google_business_vertical_1"
					   name="wgact_plugin_options[google][ads][google_business_vertical]"
					   value="1"
					<?php 
        echo checked( 1, Options::get_google_ads_business_vertical_id(), false );
        ?>
					<?php 
        esc_html_e( self::disable_if_demo() );
        ?>
				/>
				<?php 
        esc_html_e( 'Education', 'woocommerce-google-adwords-conversion-tracking-tag' );
        ?>
			</label>
			<br>
			<label>
				<input type="radio"
					   id="wpm_plugin_google_business_vertical_3"
					   name="wgact_plugin_options[google][ads][google_business_vertical]"
					   value="3"
					<?php 
        echo checked( 3, Options::get_google_ads_business_vertical_id(), false );
        ?>
					<?php 
        esc_html_e( self::disable_if_demo() );
        ?>
				/>
				<?php 
        esc_html_e( 'Hotels and rentals', 'woocommerce-google-adwords-conversion-tracking-tag' );
        ?>
			</label>
			<br>
			<label>
				<input type="radio"
					   id="wpm_plugin_google_business_vertical_4"
					   name="wgact_plugin_options[google][ads][google_business_vertical]"
					   value="4"
					<?php 
        echo checked( 4, Options::get_google_ads_business_vertical_id(), false );
        ?>
					<?php 
        esc_html_e( self::disable_if_demo() );
        ?>
				/>
				<?php 
        esc_html_e( 'Jobs', 'woocommerce-google-adwords-conversion-tracking-tag' );
        ?>
			</label>
			<br>
			<label>
				<input type="radio"
					   id="wpm_plugin_google_business_vertical_5"
					   name="wgact_plugin_options[google][ads][google_business_vertical]"
					   value="5"
					<?php 
        echo checked( 5, Options::get_google_ads_business_vertical_id(), false );
        ?>
					<?php 
        esc_html_e( self::disable_if_demo() );
        ?>
				/>
				<?php 
        esc_html_e( 'Local deals', 'woocommerce-google-adwords-conversion-tracking-tag' );
        ?>
			</label>
			<br>
			<label>
				<input type="radio"
					   id="wpm_plugin_google_business_vertical_6"
					   name="wgact_plugin_options[google][ads][google_business_vertical]"
					   value="6"
					<?php 
        echo checked( 6, Options::get_google_ads_business_vertical_id(), false );
        ?>
					<?php 
        esc_html_e( self::disable_if_demo() );
        ?>
				/>
				<?php 
        esc_html_e( 'Real estate', 'woocommerce-google-adwords-conversion-tracking-tag' );
        ?>
			</label>
			<br>
			<label>
				<input type="radio"
					   id="wpm_plugin_google_business_vertical_8"
					   name="wgact_plugin_options[google][ads][google_business_vertical]"
					   value="8"
					<?php 
        echo checked( 8, Options::get_google_ads_business_vertical_id(), false );
        ?>
					<?php 
        esc_html_e( self::disable_if_demo() );
        ?>
				/>
				<?php 
        esc_html_e( 'Custom', 'woocommerce-google-adwords-conversion-tracking-tag' );
        ?>
			</label>
		</div>

		<div style="display: inline-block;vertical-align: top;">
			<?php 
        self::html_pro_feature();
        ?>
		</div>

		<?php 
    }

    public function plugin_setting_aw_merchant_id() {
        ?>
		<input class="pmw mono"
			   type="text"
			   id="wpm_plugin_aw_merchant_id"
			   name="wgact_plugin_options[google][ads][aw_merchant_id]"
			   size="40"
			   value="<?php 
        esc_html_e( Options::get_google_ads_merchant_id() );
        ?>"
		/>
		<?php 
        self::display_status_icon( Options::get_google_ads_merchant_id() );
        ?>
		<?php 
        self::get_documentation_html_by_key( 'aw_merchant_id' );
        ?>
		<br><br>
		<?php 
        esc_html_e( 'ID of your Google Merchant Center account. It looks like this: 12345678', 'woocommerce-google-adwords-conversion-tracking-tag' );
    }

    public function plugin_option_product_identifier() {
        ?>
		<label>
			<input type="radio"
				   id="wpm_plugin_option_product_identifier_0"
				   name="wgact_plugin_options[google][ads][product_identifier]"
				   value="0"
				<?php 
        echo checked( 0, Options::get_google_ads_product_identifier(), false );
        ?>
			/>
			<?php 
        esc_html_e( 'post ID (default)', 'woocommerce-google-adwords-conversion-tracking-tag' );
        ?></label>
		<br>
		<label>
			<input type="radio"
				   id="wpm_plugin_option_product_identifier_2"
				   name="wgact_plugin_options[google][ads][product_identifier]"
				   value="2"
				<?php 
        echo checked( 2, Options::get_google_ads_product_identifier(), false );
        ?>
			/>
			<?php 
        esc_html_e( 'SKU', 'woocommerce-google-adwords-conversion-tracking-tag' );
        ?>
		</label>
		<br>
		<label>
			<input type="radio"
				   id="wpm_plugin_option_product_identifier_1"
				   name="wgact_plugin_options[google][ads][product_identifier]"
				   value="1"
				<?php 
        echo checked( 1, Options::get_google_ads_product_identifier(), false );
        ?>
			/>
			<?php 
        esc_html_e( 'ID for the WooCommerce Google Product Feed. Outputs the post ID with woocommerce_gpf_ prefix *', 'woocommerce-google-adwords-conversion-tracking-tag' );
        ?>
		</label>
		<br>
		<label>
			<input type="radio"
				   id="wpm_plugin_option_product_identifier_3"
				   name="wgact_plugin_options[google][ads][product_identifier]"
				   value="3"
				<?php 
        echo checked( 3, Options::get_google_ads_product_identifier(), false );
        ?>
			/>
			<?php 
        esc_html_e( 'ID for the WooCommerce Google Listings & Ads Plugin. Outputs the post ID with gla_ prefix **', 'woocommerce-google-adwords-conversion-tracking-tag' );
        ?>
		</label>
		<br>
		<p style="margin-top:10px">
			<?php 
        esc_html_e( 'Choose a product identifier.', 'woocommerce-google-adwords-conversion-tracking-tag' );
        ?>
		</p>
		<br>
		<?php 
        esc_html_e( '* This is for users of the WooCommerce Google Product Feed Plugin', 'woocommerce-google-adwords-conversion-tracking-tag' );
        ?>
		<a href="https://woocommerce.com/products/google-product-feed/" target="_blank">WooCommerce Google Product Feed
			Plugin</a>
		<br>
		<?php 
        esc_html_e( '** This is for users of the WooCommerce Google Listings & Ads Plugin', 'woocommerce-google-adwords-conversion-tracking-tag' );
        ?>
		<a href="https://woocommerce.com/products/google-listings-and-ads/" target="_blank">WooCommerce Google Listings
			& Ads Plugin
			Plugin</a>

		<?php 
    }

    private function html_beta() {
        return '<div class="pmw-status-icon beta">' . esc_html__( 'beta', 'woocommerce-google-adwords-conversion-tracking-tag' ) . '</div>';
    }

    private function html_experiment() {
        return '<div class="pmw-status-icon beta">' . esc_html__( 'experiment', 'woocommerce-google-adwords-conversion-tracking-tag' ) . '</div>';
    }

    private function html_beta_e( $margin_top = '1px' ) {
        ?>
		<div class="pmw-status-icon beta" style="margin-top: <?php 
        esc_html_e( $margin_top );
        ?>">
			<?php 
        esc_html_e( 'beta', 'woocommerce-google-adwords-conversion-tracking-tag' );
        ?>
		</div>
		<?php 
    }

    private static function html_status_icon_active() {
        ?>
		<div class="pmw-status-icon active"><?php 
        esc_html_e( 'active', 'woocommerce-google-adwords-conversion-tracking-tag' );
        ?></div>
		<?php 
    }

    private static function html_status_icon_inactive() {
        ?>
		<div class="pmw-status-icon inactive"><?php 
        esc_html_e( 'inactive', 'woocommerce-google-adwords-conversion-tracking-tag' );
        ?></div>
		<?php 
    }

    private static function html_status_icon_partially_active() {
        ?>
		<div class="pmw-status-icon partially-active"><?php 
        esc_html_e( 'partially active', 'woocommerce-google-adwords-conversion-tracking-tag' );
        ?></div>
		<?php 
    }

    private static function html_status_icon_override() {
        ?>
		<div class="pmw-status-icon partially-active"><?php 
        esc_html_e( 'override', 'woocommerce-google-adwords-conversion-tracking-tag' );
        ?></div>
		<?php 
    }

    private static function html_status_icon_deprecated() {
        ?>
		<div class="pmw-status-icon inactive"><?php 
        esc_html_e( 'deprecated', 'woocommerce-google-adwords-conversion-tracking-tag' );
        ?></div>
		<?php 
    }

    private static function html_deprecated() {
        return '<div class="pmw-status-icon inactive">' . esc_html__( 'deprecated', 'woocommerce-google-adwords-conversion-tracking-tag' ) . '</div>';
    }

    private static function html_pro_feature() {
        if ( !wpm_fs()->can_use_premium_code__premium_only() && Options::pro_version_demo_active() ) {
            //            if (1===1) {
            //			return '<div class="pmw-pro-feature">' . esc_html__('Pro Feature', 'woocommerce-google-adwords-conversion-tracking-tag') . '</div>';
            ?>
			<span class="pmw-pro-feature">
				<?php 
            esc_html_e( 'Pro Feature', 'woocommerce-google-adwords-conversion-tracking-tag' );
            ?>
			</span>

			<a href="https://sweetcode.com/plugins/pmw/?open-checkout=&trial=&billing-cycle=annual&utm_source=plugin&utm_medium=start-free-trial-button&utm_campaign=show-pro-version-settings#pricing-section"
			   target="_blank" style="box-shadow: none;">
				<div id="proVersionButton" class="button">
					<?php 
            esc_html_e( 'Start a free trial', 'woocommerce-google-adwords-conversion-tracking-tag' );
            ?>
				</div>
			</a>

			<?php 
        }
    }

    private static function display_status_icon( $status, $requirements = true, $inactive_silent = false ) {
        if ( $status && $requirements ) {
            self::html_status_icon_active();
        } elseif ( $status && !$requirements ) {
            self::html_status_icon_partially_active();
        } elseif ( !$inactive_silent ) {
            self::html_status_icon_inactive();
        }
    }

    private static function disable_if_demo() {
        if ( !wpm_fs()->can_use_premium_code__premium_only() && Options::pro_version_demo_active() ) {
            return 'disabled';
        } else {
            return '';
        }
    }

    private static function wistia_video_icon( $wistia_id, $tooltip_text = '' ) {
        ?>

		<script>
			var script   = document.createElement("script");
			script.async = true;
			script.src   = 'https://fast.wistia.com/embed/medias/<?php 
        esc_html_e( $wistia_id );
        ?>.jsonp';
			document.getElementsByTagName("head")[0].appendChild(script);
		</script>

		<div class="pmw wistia_embed wistia_async_<?php 
        esc_html_e( $wistia_id );
        ?> popover=true popoverContent=link videoFoam=false"
			 style="display:inline-block;position:relative;text-decoration: none; vertical-align: top;">
			<span class="dashicons dashicons-video-alt3"></span>
		</div>
		<?php 
    }

}
