<?php

defined( 'ABSPATH' ) || exit;
// Exit if accessed directly
// This first part deactivates the free version of the plugin if the premium version is activated
if ( function_exists( 'wpm_fs' ) ) {
    wpm_fs()->set_basename( true, __FILE__ );
} else {
    // DO NOT REMOVE THIS IF, IT IS ESSENTIAL FOR THE `function_exists` CALL ABOVE TO PROPERLY WORK.
    // Activate multisite network integration.
    if ( !defined( 'WP_FS__PRODUCT_7498_MULTISITE' ) ) {
        define( 'WP_FS__PRODUCT_7498_MULTISITE', false );
    }
    // Include Freemius SDK.
    require_once __DIR__ . '/vendor/freemius/wordpress-sdk/start.php';
    /**
     * Make sure the Freemius SDK actually finished loading before using it.
     *
     * The SDK's start.php returns early, without ever defining fs_dynamic_init(),
     * whenever it decides that some other copy of the SDK is in charge: either the
     * Freemius class is already in scope, or the loading is handed over to the
     * newest SDK copy registered on the site and that copy has already been
     * included (or bailed out) earlier in the request. Both cases are triggered by
     * other Freemius powered plugins and themes, not by us, and both leave
     * fs_dynamic_init() undefined.
     *
     * Calling it anyway throws an uncaught Error on every single request, the front
     * end included, which takes the whole shop down. So bail out quietly instead
     * and tell the shop admin what is going on.
     *
     * @since 1.64.1
     */
    if ( !function_exists( 'fs_dynamic_init' ) ) {
        // Named, and only hooked once, so that the free and the premium version of
        // the plugin cannot print the notice twice when both of them are installed.
        if ( !function_exists( 'pmw_fs_sdk_conflict_notice' ) ) {
            function pmw_fs_sdk_conflict_notice() {
                if ( !current_user_can( 'activate_plugins' ) ) {
                    return;
                }
                echo '<div class="notice notice-error"><p>' . '<strong>Pixel Manager for WooCommerce</strong>: ' . esc_html__( 'the plugin could not start because the Freemius SDK it needs was not loaded. This usually means that another plugin or theme on this site ships a conflicting copy of the Freemius SDK. Deactivating the other Freemius powered plugins one by one identifies the culprit. Please get in touch with our support if the notice persists.', 'woocommerce-google-adwords-conversion-tracking-tag' ) . '</p></div>';
            }

            add_action( 'admin_notices', 'pmw_fs_sdk_conflict_notice' );
        }
        return;
    }
    if ( !function_exists( 'wpm_fs' ) ) {
        // Create a helper function for easy SDK access.
        function wpm_fs() {
            global $wpm_fs;
            if ( !isset( $wpm_fs ) ) {
                if ( !function_exists( 'pmw_is_woocommerce_active' ) ) {
                    function pmw_is_woocommerce_active() {
                        // is_plugin_active() lives in the admin and is not loaded on front end requests.
                        if ( !function_exists( 'is_plugin_active' ) ) {
                            require_once ABSPATH . 'wp-admin/includes/plugin.php';
                        }
                        return is_plugin_active( 'woocommerce/woocommerce.php' );
                        // return in_array('woocommerce/woocommerce.php', apply_filters('active_plugins', get_option('active_plugins')));
                    }

                }
                $wpm_fs = fs_dynamic_init( [
                    'navigation'       => 'tabs',
                    'id'               => '7498',
                    'slug'             => 'woocommerce-google-adwords-conversion-tracking-tag',
                    'premium_slug'     => 'pixel-manager-pro-for-woocommerce',
                    'type'             => 'plugin',
                    'public_key'       => 'pk_d4182c5e1dc92c6032e59abbfdb91',
                    'is_premium'       => false,
                    'premium_suffix'   => 'Pro',
                    'has_addons'       => false,
                    'has_paid_plans'   => true,
                    'is_org_compliant' => true,
                    'trial'            => [
                        'days'               => 14,
                        'is_require_payment' => true,
                    ],
                    'menu'             => [
                        'slug'           => 'pmw',
                        'override_exact' => true,
                        'contact'        => false,
                        'support'        => false,
                        'parent'         => [
                            'slug' => ( pmw_is_woocommerce_active() ? 'woocommerce' : 'options-general.php' ),
                        ],
                    ],
                    'is_live'          => true,
                ] );
            }
            return $wpm_fs;
        }

        // Init Freemius.
        wpm_fs();
        /**
         * Signal that SDK was initiated.
         *
         * @since 1.58.5
         */
        do_action( 'wpm_fs_loaded' );
        function pmw_fs_settings_url() {
            if ( pmw_is_woocommerce_active() ) {
                return admin_url( 'admin.php?page=pmw&section=main&subsection=google' );
            } else {
                return admin_url( 'options-general.php?page=pmw&section=main&subsection=google' );
            }
        }

        wpm_fs()->add_filter( 'connect_url', 'pmw_fs_settings_url' );
        wpm_fs()->add_filter( 'after_skip_url', 'pmw_fs_settings_url' );
        wpm_fs()->add_filter( 'after_connect_url', 'pmw_fs_settings_url' );
        wpm_fs()->add_filter( 'after_pending_connect_url', 'pmw_fs_settings_url' );
        wpm_fs()->add_filter( 'show_deactivation_subscription_cancellation', '__return_false' );
        /**
         * Keep the pricing page usable when it was opened in a background tab.
         *
         * The SDK renders one card per license tier (1 site, 5 sites, 10 sites,
         * 25 sites) and decides once, while mounting, whether they all fit next to
         * each other or whether it has to fall back to showing a single card at a
         * time. That decision reads window.outerWidth, and browsers report 0 for a
         * tab that has never been in the foreground, which is what a middle click,
         * a cmd/ctrl click or a link that opens in a new tab produces. The app then
         * locks itself into the one-card layout: the prev/next arrows stay hidden
         * because the viewport turns out to be wide after all, and so does the
         * package dropdown, which is only revealed by a max-width: 768px media
         * query. What is left is the first card, which for a customer on the
         * single site license is the license they already own, with every larger
         * license off screen and no control that reaches it. The page looks empty
         * and offers nothing to buy.
         *
         * The app re-measures on every window resize, so a synthetic resize event
         * restores the correct layout. It is dispatched once the window reports a
         * width at all, on load and again whenever the tab is brought forward,
         * which is the moment a background tab becomes measurable. A window width
         * of zero is exactly the state that produced the wrong layout, so nudging
         * the app while it still reads zero can only repeat it and is skipped.
         *
         * @since 1.65.1
         */
        function pmw_fs_pricing_page_layout_fix() {
            // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only page check, no state is changed.
            $page = ( isset( $_GET['page'] ) ? sanitize_text_field( wp_unslash( $_GET['page'] ) ) : '' );
            if ( wpm_fs()->get_menu_slug() . '-pricing' !== $page ) {
                return;
            }
            ?>
			<script>
				(function () {
					var remeasure = function () {
						if (window.outerWidth > 0) {
							window.dispatchEvent(new Event('resize'));
						}
					};

					window.addEventListener('load', remeasure);
					window.addEventListener('pageshow', remeasure);
					window.addEventListener('focus', remeasure);
					document.addEventListener('visibilitychange', remeasure);
				})();
			</script>
			<?php 
        }

        add_action( 'admin_print_footer_scripts', 'pmw_fs_pricing_page_layout_fix', 100 );
        /**
         * Uninstall cleanup for the Freemius distribution.
         *
         * Freemius does not allow an uninstall.php in the plugin root (it would
         * prevent the SDK from tracking the uninstall event), so the routine
         * runs through the SDK's after_uninstall hook instead.
         *
         * @since 1.59.0
         */
        function pmw_fs_uninstall_cleanup() {
            require_once __DIR__ . '/includes/uninstall-functions.php';
            pmw_run_uninstall();
        }

        wpm_fs()->add_action( 'after_uninstall', 'pmw_fs_uninstall_cleanup' );
    }
    // Run the PMW loader
    require_once 'pmw-loader.php';
}