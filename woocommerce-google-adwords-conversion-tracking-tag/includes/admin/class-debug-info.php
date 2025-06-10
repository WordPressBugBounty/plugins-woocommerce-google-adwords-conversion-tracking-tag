<?php

namespace SweetCode\Pixel_Manager\Admin;

use Exception;
use SweetCode\Pixel_Manager\Geolocation;
use SweetCode\Pixel_Manager\Logger;
use SweetCode\Pixel_Manager\Pixels\Pixel_Manager;
use SweetCode\Pixel_Manager\Helpers;
use WC_Payment_Gateways;
use WP_Query;
defined( 'ABSPATH' ) || exit;
// Exit if accessed directly
class Debug_Info {
    public static function get_debug_info() {
        try {
            if ( Environment::is_woocommerce_active() ) {
                global $woocommerce;
            }
            global $wp_version, $current_user, $hook_suffix;
            $html = '### Debug Information ###' . PHP_EOL . PHP_EOL;
            $html .= '## Pixel Manager Info ##' . PHP_EOL . PHP_EOL;
            $html .= 'Version: ' . PMW_CURRENT_VERSION . PHP_EOL;
            $tier = ( wpm_fs()->can_use_premium_code__premium_only() ? 'pro' : 'free' );
            $html .= 'Tier:    ' . $tier . PHP_EOL;
            $html .= 'Distro:  ' . PMW_DISTRO . PHP_EOL;
            $html .= 'Plugin basename: ' . PMW_PLUGIN_BASENAME . PHP_EOL;
            // if Freemius is active, show the debug info
            $html = self::add_freemius_account_details( $html );
            $html .= PHP_EOL . '## System Environment ##' . PHP_EOL . PHP_EOL;
            $html .= 'WordPress version:   ' . $wp_version . PHP_EOL;
            if ( Environment::is_woocommerce_active() ) {
                $html .= 'WooCommerce version: ' . $woocommerce->version . PHP_EOL;
            }
            $html .= 'PHP version:         ' . phpversion() . PHP_EOL;
            $html .= PHP_EOL;
            $html .= 'Server max execution time: ' . ini_get( 'max_execution_time' ) . ' seconds' . PHP_EOL;
            // show the php.ini file that is being used with full path location
            //			$html .= 'php.ini file: ' . php_ini_loaded_file() . PHP_EOL;
            $html .= 'WordPress memory limit:    ' . Environment::get_wp_memory_limit() . PHP_EOL;
            $curl_available = ( Environment::is_curl_active() ? 'yes' : 'no' );
            $html .= 'curl available:            ' . $curl_available . PHP_EOL;
            $transients_enabled = ( Environment::is_transients_enabled() ? 'yes' : 'no' );
            $html .= 'transients enabled:        ' . $transients_enabled . PHP_EOL;
            $html .= PHP_EOL;
            $html .= 'wp_remote_get to Cloudflare:           ' . self::pmw_remote_get_response( 'https://www.cloudflare.com/cdn-cgi/trace' ) . PHP_EOL;
            //			$html .= 'wp_remote_get to Google Analytics API: ' . self::pmw_remote_get_response('https://www.google-analytics.com/debug/collect') . PHP_EOL;
            $html .= 'wp_remote_post to GA4 Measurement Protocol API: ' . self::pmw_remote_post_response( 'https://www.google-analytics.com/mp/collect' ) . PHP_EOL;
            $html .= 'wp_remote_get to Facebook Graph API:   ' . self::pmw_remote_get_response( 'https://graph.facebook.com/facebook/picture?redirect=false' ) . PHP_EOL;
            //        $html           .= 'wp_remote_post to Facebook Graph API: ' . self::wp_remote_get_response('https://graph.facebook.com/') . PHP_EOL;
            $html .= PHP_EOL;
            // is server behind Cloudflare
            $is_server_behind_cloudflare = ( Environment::is_server_behind_cloudflare() ? 'yes' : 'no' );
            $html .= 'Server is behind Cloudflare: ' . $is_server_behind_cloudflare . PHP_EOL;
            $html .= PHP_EOL;
            $multisite_enabled = ( is_multisite() ? 'yes' : 'no' );
            $html .= 'Multisite enabled:            ' . $multisite_enabled . PHP_EOL;
            $wp_debug = 'no';
            if ( defined( 'WP_DEBUG' ) && true === WP_DEBUG ) {
                $wp_debug = 'yes';
            }
            $html .= 'WordPress debug mode enabled: ' . $wp_debug . PHP_EOL;
            //        wp_get_current_user();
            $html .= 'Logged in user login name:    ' . $current_user->user_login . PHP_EOL;
            $html .= 'Logged in user display name:  ' . $current_user->display_name . PHP_EOL;
            $html .= 'hook_suffix:                  ' . $hook_suffix . PHP_EOL;
            $html .= PHP_EOL;
            $html .= 'Hosting provider: ' . Environment::get_hosting_provider() . PHP_EOL;
            if ( Environment::is_woocommerce_active() ) {
                $html .= PHP_EOL . '## WooCommerce ##' . PHP_EOL . PHP_EOL;
                $html .= 'Default currency: ' . get_woocommerce_currency() . PHP_EOL . PHP_EOL;
                $html .= 'Shop URL:         ' . get_home_url() . PHP_EOL;
                $html .= 'Cart URL:         ' . wc_get_cart_url() . PHP_EOL;
                $html .= 'Checkout URL:     ' . wc_get_checkout_url() . PHP_EOL;
                $html .= PHP_EOL;
                $html .= 'Purchase confirmation endpoint: ' . wc_get_endpoint_url( 'order-received' ) . PHP_EOL;
                $order_received_page_url = wc_get_checkout_url() . ltrim( wc_get_endpoint_url( 'order-received' ), '/' );
                $html .= 'is_order_received_page():       ' . $order_received_page_url . PHP_EOL . PHP_EOL;
                if ( Environment::does_one_order_exist() ) {
                    $last_order_url = Environment::get_last_order_url();
                    $html .= 'Last order URL:   ' . $last_order_url . '&nodedupe&pmwloggeron' . PHP_EOL;
                    $html .= 'Last order email: ' . Environment::get_last_order()->get_billing_email() . PHP_EOL;
                    $html .= PHP_EOL;
                    $last_order_url_contains_order_received_page_url = ( strpos( Environment::get_last_order_url(), $order_received_page_url ) !== false ? 'yes' : 'no' );
                    $html .= 'Purchase confirmation uses is_order_received(): ' . $last_order_url_contains_order_received_page_url . PHP_EOL;
                    $url_response = self::pmw_remote_get_response( $last_order_url );
                    if ( 200 === $url_response ) {
                        $html .= 'Purchase confirmation page redirect:            ' . $url_response . ' (OK)' . PHP_EOL;
                    } elseif ( $url_response >= 300 && $url_response < 400 ) {
                        $html .= self::show_warning( true ) . 'Purchase confirmation redirect:            ' . $url_response . ' (ERROR)' . PHP_EOL;
                        $html .= self::show_warning( true ) . 'Redirect URL:                              ' . self::pmw_get_final_url( Environment::get_last_order_url() ) . PHP_EOL;
                    } else {
                        $html .= 'Purchase confirmation redirect:            ' . $url_response . ' (ERROR)' . PHP_EOL;
                    }
                }
                //        $html                                .= 'wc_get_page_permalink(\'checkout\'): ' . wc_get_page_permalink('checkout') . PHP_EOL;
                $html .= PHP_EOL . '## WooCommerce Payment Gateways ##' . PHP_EOL . PHP_EOL;
                $html .= 'Available payment gateways: ' . PHP_EOL;
                $pg = self::get_payment_gateways();
                // Get the longest string from the array of payment gateways
                $len_id = strlen( 'id:' );
                $len_method_title = strlen( 'method_title:' );
                $len_class_name = strlen( 'class_name:' );
                foreach ( $pg as $p ) {
                    $len_id = max( strlen( $p->id ), $len_id );
                    $len_method_title = max( strlen( $p->method_title ), $len_method_title );
                    $len_class_name = max( strlen( get_class( $p ) ), $len_class_name );
                }
                $len_id = $len_id + 2;
                $len_method_title = $len_method_title + 2;
                $len_class_name = $len_class_name + 2;
                $html .= '  ';
                $html .= str_pad( 'id:', $len_id );
                $html .= str_pad( 'method_title:', $len_method_title );
                $html .= str_pad( 'class:', $len_class_name );
                $html .= PHP_EOL;
                foreach ( self::get_payment_gateways() as $gateway ) {
                    $html .= '  ';
                    $html .= str_pad( $gateway->id, $len_id );
                    $html .= str_pad( $gateway->method_title, $len_method_title );
                    $html .= str_pad( get_class( $gateway ), $len_class_name );
                    $html .= PHP_EOL;
                }
                $html .= PHP_EOL . 'Purchase confirmation page reached per gateway (active and inactive):' . PHP_EOL;
                $html .= self::get_gateway_analysis_for_debug_info();
                $html .= PHP_EOL . 'Purchase confirmation page reached per gateway only active and weighted by frequency:' . PHP_EOL;
                $html .= self::get_gateway_analysis_weighted_for_debug_info();
                if ( Environment::is_transients_enabled() ) {
                    // Time it took to run the payment gateway analysis
                    if ( get_transient( 'pmw_tracking_accuracy_analysis_date' ) ) {
                        $html .= 'Date of the last payment gateway analysis run: ' . get_transient( 'pmw_tracking_accuracy_analysis_date' ) . PHP_EOL;
                    }
                    // Time it took to run the payment gateway analysis
                    if ( get_transient( 'pmw_tracking_accuracy_analysis_time' ) ) {
                        $html .= 'Time to generate the payment gateway analysis: ' . round( get_transient( 'pmw_tracking_accuracy_analysis_time' ), 2 ) . ' seconds' . PHP_EOL;
                    }
                }
            }
            //        $html .= PHP_EOL;
            $html .= PHP_EOL . '## Theme ##' . PHP_EOL . PHP_EOL;
            $is_child_theme = ( is_child_theme() ? 'yes' : 'no' );
            $html .= 'Is child theme:      ' . $is_child_theme . PHP_EOL;
            $theme_support = ( current_theme_supports( 'woocommerce' ) ? 'yes' : 'no' );
            $html .= 'WooCommerce support: ' . $theme_support . PHP_EOL;
            $html .= PHP_EOL;
            // using the double check prevents problems with some themes that have not implemented
            // the child state correctly
            // https://wordpress.org/support/topic/debug-error-33/
            $theme_description_prefix = ( is_child_theme() && wp_get_theme()->parent() ? 'Child theme ' : 'Theme ' );
            $html .= $theme_description_prefix . 'Name:       ' . wp_get_theme()->get( 'Name' ) . PHP_EOL;
            $html .= $theme_description_prefix . 'ThemeURI:   ' . wp_get_theme()->get( 'ThemeURI' ) . PHP_EOL;
            $html .= $theme_description_prefix . 'Author:     ' . wp_get_theme()->get( 'Author' ) . PHP_EOL;
            $html .= $theme_description_prefix . 'AuthorURI:  ' . wp_get_theme()->get( 'AuthorURI' ) . PHP_EOL;
            $html .= $theme_description_prefix . 'Version:    ' . wp_get_theme()->get( 'Version' ) . PHP_EOL;
            $html .= $theme_description_prefix . 'Template:   ' . wp_get_theme()->get( 'Template' ) . PHP_EOL;
            $html .= $theme_description_prefix . 'Status:     ' . wp_get_theme()->get( 'Status' ) . PHP_EOL;
            $html .= $theme_description_prefix . 'TextDomain: ' . wp_get_theme()->get( 'TextDomain' ) . PHP_EOL;
            $html .= $theme_description_prefix . 'DomainPath: ' . wp_get_theme()->get( 'DomainPath' ) . PHP_EOL;
            $html .= PHP_EOL;
            // using the double check prevents problems with some themes that have not implemented
            // the child state correctly
            if ( is_child_theme() && wp_get_theme()->parent() ) {
                $html .= 'Parent theme Name:       ' . wp_get_theme()->parent()->get( 'Name' ) . PHP_EOL;
                $html .= 'Parent theme ThemeURI:   ' . wp_get_theme()->parent()->get( 'ThemeURI' ) . PHP_EOL;
                $html .= 'Parent theme Author:     ' . wp_get_theme()->parent()->get( 'Author' ) . PHP_EOL;
                $html .= 'Parent theme AuthorURI:  ' . wp_get_theme()->parent()->get( 'AuthorURI' ) . PHP_EOL;
                $html .= 'Parent theme Version:    ' . wp_get_theme()->parent()->get( 'Version' ) . PHP_EOL;
                $html .= 'Parent theme Template:   ' . wp_get_theme()->parent()->get( 'Template' ) . PHP_EOL;
                $html .= 'Parent theme Status:     ' . wp_get_theme()->parent()->get( 'Status' ) . PHP_EOL;
                $html .= 'Parent theme TextDomain: ' . wp_get_theme()->parent()->get( 'TextDomain' ) . PHP_EOL;
                $html .= 'Parent theme DomainPath: ' . wp_get_theme()->parent()->get( 'DomainPath' ) . PHP_EOL;
            }
            // TODO maybe add all active plugins
            $html .= PHP_EOL . PHP_EOL . '### End of Information ###';
            return $html;
        } catch ( Exception $e ) {
            return $e->getMessage();
        }
    }

    private static function add_freemius_account_details( $html ) {
        try {
            if ( !function_exists( 'wpm_fs' ) ) {
                return $html;
            }
            global $fs_active_plugins;
            $html .= PHP_EOL . '## Freemius ##' . PHP_EOL . PHP_EOL;
            if ( method_exists( wpm_fs(), 'get_user' ) ) {
                $fs_user = wpm_fs()->get_user();
                $fs_user_id = ( is_object( $fs_user ) && property_exists( $fs_user, 'id' ) ? $fs_user->id : 'not found' );
                $html .= 'Freemius User ID:     ' . $fs_user_id . PHP_EOL;
            }
            if ( method_exists( wpm_fs(), 'get_site' ) ) {
                $fs_site = wpm_fs()->get_site();
                $fs_site_id = ( is_object( $fs_site ) && property_exists( $fs_site, 'id' ) ? $fs_site->id : 'not found' );
                $html .= 'Freemius Site ID:     ' . $fs_site_id . PHP_EOL;
            }
            $fs_sdk_bundled_version = ( property_exists( wpm_fs(), 'version' ) ? wpm_fs()->version : 'not found' );
            $html .= 'Freemius SDK bundled: ' . $fs_sdk_bundled_version . PHP_EOL;
            $fs_sdk_active_version = ( property_exists( $fs_active_plugins, 'newest' ) && property_exists( $fs_active_plugins->newest, 'version' ) ? $fs_active_plugins->newest->version : 'not found' );
            $html .= 'Freemius SDK active:  ' . $fs_sdk_active_version . PHP_EOL;
            $html .= 'api.freemius.com:     ' . self::try_connect_to_server( 'https://api.freemius.com' ) . PHP_EOL;
            $html .= 'wp.freemius.com:      ' . self::try_connect_to_server( 'https://wp.freemius.com' ) . PHP_EOL;
            return $html;
        } catch ( Exception $e ) {
            $html .= PHP_EOL . 'Freemius error: ' . $e->getMessage() . PHP_EOL;
        }
        return $html;
    }

    public static function run_tracking_accuracy_analysis() {
        // Start measuring time
        $start_time = microtime( true );
        $maximum_orders_to_analyze = self::get_maximum_orders_to_analyze();
        // We want to at least analyze the count of active gateways * 100, or at least all orders in the past 30 days, whichever is larger.
        // And we don't want to exceed the maximum orders to analyze (default 6000).
        $amount_of_orders_to_analyze = min( $maximum_orders_to_analyze, max( count( self::get_enabled_payment_gateways() ) * 100, self::get_count_of_pmw_tracked_orders_for_one_month() ) );
        self::generate_pmw_tracked_payment_methods();
        self::generate_gateway_analysis_array();
        self::generate_gateway_analysis_weighted_array( $amount_of_orders_to_analyze );
        // set transient with date
        set_transient( 'pmw_tracking_accuracy_analysis_date', gmdate( 'Y-m-d H:i:s' ), MONTH_IN_SECONDS );
        // End measuring time
        $end_time = microtime( true );
        set_transient( 'pmw_tracking_accuracy_analysis_time', $end_time - $start_time, MONTH_IN_SECONDS );
        delete_transient( 'pmw_tracking_accuracy_analysis_running' );
    }

    // If the analysis runs into a timout we lower the amount of orders to analyze.
    protected static function get_maximum_orders_to_analyze() {
        if ( get_transient( 'pmw_tracking_accuracy_analysis_running' ) ) {
            // If available means that last run failed or timed out.
            $last_maximum_orders_to_analyze = ( get_transient( 'pmw_tracking_accuracy_analysis_max_orders' ) ? get_transient( 'pmw_tracking_accuracy_analysis_max_orders' ) : self::get_default_maximum_orders_to_analyse() );
            $maximum_orders_to_analyze = intval( $last_maximum_orders_to_analyze * 0.8 );
        } elseif ( get_transient( 'pmw_tracking_accuracy_analysis_max_orders' ) ) {
            /**
             * We are increasing the max amount with every run a little
             * in order to counteract possible bailouts due to other reasons than timeouts,
             * that otherwise would only lower the max amount with each error until
             * the max amount reaches the minimum and stays at the minimum forever.
             * */
            $maximum_orders_to_analyze = min( intval( get_transient( 'pmw_tracking_accuracy_analysis_max_orders' ) * 1.01 ), self::get_default_maximum_orders_to_analyse() );
        } else {
            // Default value
            $maximum_orders_to_analyze = self::get_default_maximum_orders_to_analyse();
        }
        $maximum_orders_to_analyze = min( 
            // Use the smaller of the two values. Either the user override or the calculated value.
            apply_filters( 'pmw_tracking_accuracy_analysis_max_order_amount', $maximum_orders_to_analyze ),
            $maximum_orders_to_analyze
         );
        set_transient( 'pmw_tracking_accuracy_analysis_running', true );
        set_transient( 'pmw_tracking_accuracy_analysis_max_orders', $maximum_orders_to_analyze );
        return $maximum_orders_to_analyze;
    }

    protected static function get_default_maximum_orders_to_analyse() {
        /**
         * Make the maximum orders to analyze dependent on the max_execution_time.
         * The smaller it is the less maximum orders we want to analyze to avoid timeouts.
         * And we want to analyze at least 300 orders.
         * */
        $max_execution_time = ( ini_get( 'max_execution_time' ) ? ini_get( 'max_execution_time' ) : 30 );
        return max( $max_execution_time * 100, 300 );
    }

    public static function get_gateway_analysis_for_debug_info() {
        if ( !Environment::is_transients_enabled() ) {
            return PHP_EOL . self::return_transients_deactivated_text() . PHP_EOL;
        }
        if ( self::get_gateway_analysis_array() === false ) {
            return self::tracking_accuracy_loading_message() . PHP_EOL;
        }
        $per_gateway_analysis = self::get_gateway_analysis_array();
        $html = '';
        $order_count_total = 0;
        $order_count_measured = 0;
        foreach ( $per_gateway_analysis as $analysis ) {
            $order_count_total += $analysis['order_count_total'];
            $order_count_measured += $analysis['order_count_measured'];
            $html .= '  ';
            $html .= str_pad( $analysis['order_count_measured'], 3 ) . ' of ';
            $html .= str_pad( $analysis['order_count_total'], 3 ) . ' = ';
            $html .= str_pad( Helpers::get_percentage( $analysis['order_count_measured'], $analysis['order_count_total'] ) . '%', 5 );
            $html .= 'for ' . $analysis['gateway_id'];
            $html .= PHP_EOL;
        }
        return $html;
    }

    public static function get_gateway_analysis_weighted_for_debug_info() {
        if ( !Environment::is_transients_enabled() ) {
            return PHP_EOL . self::return_transients_deactivated_text() . PHP_EOL;
        }
        if ( self::get_gateway_analysis_weighted_array() === false ) {
            return self::tracking_accuracy_loading_message() . PHP_EOL;
        }
        $per_gateway_analysis = self::get_gateway_analysis_weighted_array();
        $html = '';
        $order_count_total = 0;
        $order_count_measured = 0;
        foreach ( $per_gateway_analysis as $analysis ) {
            $order_count_total += $analysis['order_count_total'];
            $order_count_measured += $analysis['order_count_measured'];
            $html .= '  ';
            $html .= str_pad( $analysis['order_count_measured'], 4 ) . ' of ';
            $html .= str_pad( $analysis['order_count_total'], 4 ) . ' = ';
            $html .= str_pad( $analysis['percentage'] . '%', 4 );
            $html .= ' for ' . $analysis['gateway_id'];
            $html .= PHP_EOL;
        }
        $html .= '  ' . str_pad( $order_count_measured, 4 ) . ' of ' . str_pad( $order_count_total, 4 ) . ' = ';
        $html .= Helpers::get_percentage( $order_count_measured, $order_count_total ) . '%' . str_pad( '', 6 ) . 'total';
        $html .= PHP_EOL;
        return $html;
    }

    public static function get_gateway_analysis_array() {
        if ( get_transient( 'pmw_tracking_accuracy_analysis' ) ) {
            return get_transient( 'pmw_tracking_accuracy_analysis' );
        }
        return false;
    }

    public static function generate_gateway_analysis_array() {
        $analysis = [];
        if ( empty( self::get_pmw_tracked_payment_methods() ) ) {
            self::generate_pmw_tracked_payment_methods();
        }
        foreach ( self::get_pmw_tracked_payment_methods() as $gateway ) {
            $gateway_orders = self::get_last_orders_by_gateway_id( $gateway, 100 );
            //			$gateway_orders = self::get_last_orders_by_gateway_id_wp_query_new($gateway, 100);
            //			$gateway_orders = self::get_last_orders_by_gateway_id_wp_query($gateway, 100);
            $analysis[] = [
                'gateway_id'           => $gateway,
                'order_count_total'    => count( $gateway_orders ),
                'order_count_measured' => self::get_count_of_measured_orders( $gateway_orders ),
                'percentage'           => floor( Helpers::get_percentage( self::get_count_of_measured_orders( $gateway_orders ), count( $gateway_orders ) ) ),
            ];
        }
        set_transient( 'pmw_tracking_accuracy_analysis', $analysis, MONTH_IN_SECONDS );
    }

    public static function get_gateway_analysis_weighted_array() {
        if ( get_transient( 'pmw_tracking_accuracy_analysis_weighted' ) ) {
            return get_transient( 'pmw_tracking_accuracy_analysis_weighted' );
        }
        return false;
    }

    public static function generate_gateway_analysis_weighted_array( $limit ) {
        $analysis = [];
        $enabled_gateways = self::get_enabled_payment_gateways();
        // Prep array with all gateway IDs
        $gateway_ids = array_map( function ( $gateway ) {
            return $gateway->id;
        }, $enabled_gateways );
        // Prep analysis array with all gateways
        //		foreach ($gateway_ids as $gateway_id) {
        //			$analysis[$gateway_id] = [
        //				'gateway_id'           => $gateway_id,
        //				'order_count_total'    => count($this->get_last_orders_by_gateway_id($gateway_id, $limit)),
        //				'order_count_measured' => count($this->get_last_orders_by_gateway_id_pmw_measured_wp_query($gateway_id, $limit)),
        //				'percentage'           => 0,
        //			];
        //		}
        foreach ( $gateway_ids as $gateway_id ) {
            $analysis[$gateway_id] = [
                'gateway_id'           => $gateway_id,
                'order_count_measured' => 0,
                'order_count_total'    => 0,
                'percentage'           => 0,
            ];
        }
        $orders = self::get_pmw_tracked_orders( $limit );
        // Analyse all orders
        foreach ( $orders as $order ) {
            // Only analyse orders that were paid with one of the active payment gateways
            if ( in_array( $order->get_payment_method(), $gateway_ids ) ) {
                $analysis[$order->get_payment_method()]['order_count_total']++;
                if ( $order->meta_exists( '_wpm_conversion_pixel_fired' ) ) {
                    $analysis[$order->get_payment_method()]['order_count_measured']++;
                }
            }
        }
        // Calculate percentage for each gateway
        foreach ( $analysis as $gateway_id => $gateway_analysis ) {
            $analysis[$gateway_id]['percentage'] = floor( Helpers::get_percentage( $gateway_analysis['order_count_measured'], $gateway_analysis['order_count_total'] ) );
        }
        // Sort analysis by order_count_total descending
        usort( $analysis, function ( $a, $b ) {
            return $b['order_count_total'] - $a['order_count_total'];
        } );
        set_transient( 'pmw_tracking_accuracy_analysis_weighted', $analysis, MONTH_IN_SECONDS );
    }

    private static function get_count_of_measured_orders( $orders ) {
        $count = 0;
        foreach ( $orders as $order_id ) {
            $order = wc_get_order( $order_id );
            // Get meta data for post id and meta key _wpm_conversion_pixel_fired
            if ( $order->meta_exists( '_wpm_conversion_pixel_fired' ) ) {
                $count++;
            }
        }
        return $count;
    }

    /**
     * Possible way to use a proxy if necessary
     * https://deliciousbrains.com/php-curl-how-wordpress-makes-http-requests/
     * possible proxy list
     * https://www.us-proxy.org/
     * https://freemius.com/help/documentation/wordpress-sdk/license-activation-issues/#isp_blockage
     *
     * Google and Facebook might block free proxy requests
     */
    private static function pmw_remote_get_response( $url ) {
        $response = wp_remote_get( $url, [
            'timeout'             => 4,
            'sslverify'           => !Geolocation::is_localhost(),
            'limit_response_size' => 5000,
            'blocking'            => true,
            'redirection'         => 0,
        ] );
        if ( is_wp_error( $response ) ) {
            return self::show_warning( true ) . $response->get_error_message();
        } else {
            $response_code = wp_remote_retrieve_response_code( $response );
            if ( 200 === $response_code ) {
                return $response_code;
            } else {
                return self::show_warning( true ) . $response_code;
            }
        }
    }

    /**
     *  Test if a server is reachable, no matter what response code, using wp_remote_post
     *
     * @param $url
     * @return int|string
     */
    private static function pmw_remote_post_response( $url ) {
        $response = wp_remote_post( $url, [
            'timeout'             => 4,
            'sslverify'           => !Geolocation::is_localhost(),
            'limit_response_size' => 5000,
            'blocking'            => true,
            'redirection'         => 0,
        ] );
        if ( is_wp_error( $response ) ) {
            return self::show_warning( true ) . $response->get_error_message();
        }
        $response_code = wp_remote_retrieve_response_code( $response );
        if ( $response_code >= 200 && $response_code < 300 ) {
            return $response_code;
        }
        return self::show_warning( true ) . $response_code;
    }

    private static function pmw_get_final_url( $url ) {
        $response = wp_remote_get( $url, [
            'timeout'             => 4,
            'sslverify'           => !Geolocation::is_localhost(),
            'limit_response_size' => 5000,
            'blocking'            => true,
            'redirection'         => 10,
        ] );
        if ( is_wp_error( $response ) ) {
            return $response->get_error_message();
        } else {
            // If $response['http_response']->get_response_object()->url is set, return it, else return 'error'
            if ( isset( $response['http_response']->get_response_object()->url ) ) {
                return $response['http_response']->get_response_object()->url;
            }
            return 'error';
        }
    }

    private static function show_warning( $test = false ) {
        if ( $test ) {
            return '❗ ';
        } else {
            return '';
        }
    }

    //	private static function try_connect_to_server( $server ) {
    //		if ($socket = @ fsockopen($server, 80)) {
    //			@fclose($socket);
    //			return 'online';
    //		} else {
    //			return 'offline';
    //		}
    //	}
    /**
     * Test if a server is reachable, no matter what response code, using wp_remote_get
     *
     * @param $server
     * @return string
     */
    private static function try_connect_to_server( $server ) {
        $response = wp_remote_get( $server, [
            'timeout'             => 4,
            'sslverify'           => !Geolocation::is_localhost(),
            'limit_response_size' => 5000,
            'blocking'            => true,
            'redirection'         => 0,
        ] );
        if ( is_wp_error( $response ) ) {
            return 'offline';
        } else {
            return 'online';
        }
    }

    public static function get_enabled_payment_gateways() {
        $gateways = WC()->payment_gateways->get_available_payment_gateways();
        $enabled_gateways = [];
        if ( $gateways ) {
            foreach ( $gateways as $gateway ) {
                if ( 'yes' == $gateway->enabled ) {
                    $enabled_gateways[] = $gateway;
                }
            }
        }
        return $enabled_gateways;
    }

    /**
     * Get all payment gateways
     *
     * In rare cases, if a payment gateway is not following the WooCommerce standards,
     * it might not be available in the WC_Payment_Gateways class.
     *
     * Reference: https://secure.helpscout.net/conversation/2748380728/3697/
     *
     * @return array
     */
    public static function get_payment_gateways() {
        if ( !class_exists( 'WC_Payment_Gateways' ) ) {
            return [];
        }
        $gateways = ( new WC_Payment_Gateways() )->get_available_payment_gateways();
        return ( is_array( $gateways ) ? $gateways : [] );
    }

    private static function get_last_orders_by_gateway_id( $gateway_id, $limit ) {
        // Get most recent order IDs in date descending order, filtered by gateway_id.
        //		error_log('get_last_orders_by_gateway_id');
        // TODO include custom order statutes that have been added with a pmw filter
        return wc_get_orders( [
            'payment_method' => $gateway_id,
            'limit'          => $limit,
            'type'           => 'shop_order',
            'orderby'        => 'date',
            'order'          => 'DESC',
            'status'         => [
                'completed',
                'processing',
                'on-hold',
                'pending'
            ],
            'created_via'    => 'checkout',
            'meta_key'       => '_wpm_process_through_wpm',
            'meta_compare'   => '=',
            'meta_value'     => true,
            'return'         => 'ids',
        ] );
    }

    private static function get_last_orders_by_gateway_id_pmw_measured_wp_query( $gateway_id, $limit ) {
        // Get most recent order IDs in date descending order, filtered by gateway_id.
        // TODO include custom order statutes that have been added with a pmw filter
        $query = new WP_Query([
            'fields'         => 'ids',
            'post_type'      => 'shop_order',
            'posts_per_page' => $limit,
            'post_status'    => [
                'wc-completed',
                'wc-processing',
                'wc-on-hold',
                'wc-pending'
            ],
            'orderby'        => 'ID',
            'order'          => 'DESC',
            'meta_query'     => [[
                'relation' => 'AND',
                [
                    'key'     => '_payment_method',
                    'compare' => '=',
                    'value'   => $gateway_id,
                ],
                [
                    'key'     => '_wpm_process_through_wpm',
                    'compare' => '=',
                    'value'   => true,
                ],
                [
                    'key'     => '_wpm_conversion_pixel_fired',
                    'compare' => '=',
                    'value'   => true,
                ],
            ]],
        ]);
        return $query->get_posts();
    }

    private static function get_pmw_tracked_orders( $limit ) {
        // Get most recent order IDs in date descending order.
        // TODO include custom order statutes that have been added with a pmw filter
        return wc_get_orders( [
            'limit'        => $limit,
            'type'         => 'shop_order',
            'orderby'      => 'ID',
            'order'        => 'DESC',
            'status'       => [
                'completed',
                'processing',
                'on-hold',
                'pending'
            ],
            'created_via'  => 'checkout',
            'meta_key'     => '_wpm_process_through_wpm',
            'meta_value'   => true,
            'meta_compare' => '=',
            'return'       => 'objects',
        ] );
    }

    private static function get_count_of_pmw_tracked_orders_for_one_month() {
        return count( wc_get_orders( [
            'type'         => 'shop_order',
            'limit'        => -1,
            'date_created' => '>' . (time() - MONTH_IN_SECONDS),
            'status'       => [
                'completed',
                'processing',
                'on-hold',
                'pending'
            ],
            'created_via'  => 'checkout',
            'meta_key'     => '_wpm_process_through_wpm',
            'meta_value'   => true,
            'meta_compare' => '=',
            'return'       => 'ids',
        ] ) );
    }

    // Get payment methods that have been used on all orders directly from database
    private static function get_pmw_tracked_payment_methods() {
        if ( get_transient( 'pmw_tracked_payment_methods' ) ) {
            return get_transient( 'pmw_tracked_payment_methods' );
        }
        return [];
    }

    private static function generate_pmw_tracked_payment_methods() {
        global $wpdb;
        if ( Helpers::is_wc_hpos_enabled() ) {
            // HPOS tables in use
            $tracked_payment_methods = $wpdb->get_col( "SELECT DISTINCT payment_method FROM {$wpdb->prefix}wc_orders WHERE payment_method <> ''" );
        } else {
            // Traditional post tables are in use.
            $tracked_payment_methods = $wpdb->get_col( "SELECT DISTINCT meta_value FROM {$wpdb->prefix}postmeta WHERE `meta_key` = '_payment_method' AND meta_value != ''" );
        }
        set_transient( 'pmw_tracked_payment_methods', $tracked_payment_methods, MONTH_IN_SECONDS );
    }

    public static function tracking_accuracy_loading_message() {
        if ( !Environment::is_action_scheduler_active() ) {
            return esc_html__( "The Pixel Manager wasn't able to generate the analysis because the Action Scheduler could not be loaded.", 'woocommerce-google-adwords-conversion-tracking-tag' );
        }
        return __( 'The analysis is being generated. Please check back in 5 minutes.', 'woocommerce-google-adwords-conversion-tracking-tag' );
    }

    private static function return_transients_deactivated_text() {
        return __( 'Transients are deactivated. Please activate them to use this feature.', 'woocommerce-google-adwords-conversion-tracking-tag' );
    }

}
