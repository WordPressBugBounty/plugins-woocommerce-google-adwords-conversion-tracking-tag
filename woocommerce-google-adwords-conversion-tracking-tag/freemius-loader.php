<?php

defined( 'ABSPATH' ) || exit;
// Exit if accessed directly
// This first part deactivates the free version of the plugin if the premium version is activated
if ( function_exists( 'wpm_fs' ) ) {
    wpm_fs()->set_basename( true, __FILE__ );
} else {
    // DO NOT REMOVE THIS IF, IT IS ESSENTIAL FOR THE `function_exists` CALL ABOVE TO PROPERLY WORK.
    if ( !function_exists( 'wpm_fs' ) ) {
        // Create a helper function for easy SDK access.
        function wpm_fs() {
            global $wpm_fs;
            if ( !isset( $wpm_fs ) ) {
                // Activate multisite network integration.
                if ( !defined( 'WP_FS__PRODUCT_7498_MULTISITE' ) ) {
                    define( 'WP_FS__PRODUCT_7498_MULTISITE', false );
                }
                // Include Freemius SDK.
                require_once __DIR__ . '/vendor/freemius/wordpress-sdk/start.php';
                if ( !function_exists( 'pmw_is_woocommerce_active' ) ) {
                    function pmw_is_woocommerce_active() {
                        return is_plugin_active( 'woocommerce/woocommerce.php' );
                        // return in_array('woocommerce/woocommerce.php', apply_filters('active_plugins', get_option('active_plugins')));
                    }

                }
                $wpm_fs = fs_dynamic_init( [
                    'navigation'     => 'tabs',
                    'id'             => '7498',
                    'slug'           => 'woocommerce-google-adwords-conversion-tracking-tag',
                    'premium_slug'   => 'pixel-manager-pro-for-woocommerce',
                    'type'           => 'plugin',
                    'public_key'     => 'pk_d4182c5e1dc92c6032e59abbfdb91',
                    'is_premium'     => false,
                    'premium_suffix' => 'Pro',
                    'has_addons'     => false,
                    'has_paid_plans' => true,
                    'trial'          => [
                        'days'               => 14,
                        'is_require_payment' => true,
                    ],
                    'menu'           => [
                        'slug'           => 'wpm',
                        'override_exact' => true,
                        'contact'        => false,
                        'support'        => false,
                        'parent'         => [
                            'slug' => ( pmw_is_woocommerce_active() ? 'woocommerce' : 'options-general.php' ),
                        ],
                    ],
                    'is_live'        => true,
                ] );
            }
            return $wpm_fs;
        }

        // Init Freemius.
        wpm_fs();
        // Signal that SDK was initiated.
        do_action( 'wpm_fs_loaded' );
        function wpm_fs_settings_url() {
            if ( pmw_is_woocommerce_active() ) {
                return admin_url( 'admin.php?page=wpm&section=main&subsection=google' );
            } else {
                return admin_url( 'options-general.php?page=wpm&section=main&subsection=google' );
            }
        }

        wpm_fs()->add_filter( 'connect_url', 'wpm_fs_settings_url' );
        wpm_fs()->add_filter( 'after_skip_url', 'wpm_fs_settings_url' );
        wpm_fs()->add_filter( 'after_connect_url', 'wpm_fs_settings_url' );
        wpm_fs()->add_filter( 'after_pending_connect_url', 'wpm_fs_settings_url' );
    }
    // Run the PMW loader
    require_once 'pmw-loader.php';
}