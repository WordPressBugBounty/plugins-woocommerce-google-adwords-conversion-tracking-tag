<?php

namespace SweetCode\Pixel_Manager\Admin;

use Exception;
use SweetCode\Pixel_Manager\Geolocation;
use SweetCode\Pixel_Manager\Logger;
use SweetCode\Pixel_Manager\Pixels\Pixel_Manager;
use SweetCode\Pixel_Manager\Helpers;
use SweetCode\Pixel_Manager\Tracking_Accuracy_DB;
use WC_Payment_Gateways;
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
            //          $html .= 'php.ini file: ' . php_ini_loaded_file() . PHP_EOL;
            $html .= 'WordPress memory limit:    ' . Environment::get_wp_memory_limit() . PHP_EOL;
            $curl_available = ( Environment::is_curl_active() ? 'yes' : 'no' );
            $html .= 'curl available:                            ' . $curl_available . PHP_EOL;
            $transients_enabled = Environment::is_transients_enabled();
            $transients_warning = ( $transients_enabled ? '' : ' <--------- !!!!! TRANSIENTS DISABLED !!!!!' );
            $html .= 'Transients enabled:                        ' . (( $transients_enabled ? 'yes' : 'no' )) . $transients_warning . PHP_EOL;
            $external_object_cache = Environment::get_external_object_cache();
            $html .= 'External object cache (Redis/Memcached):   ' . $external_object_cache . PHP_EOL;
            $html .= PHP_EOL;
            $html .= 'wp_remote_get to Cloudflare:           ' . self::pmw_remote_get_response( 'https://www.cloudflare.com/cdn-cgi/trace' ) . PHP_EOL;
            //          $html .= 'wp_remote_get to Google Analytics API: ' . self::pmw_remote_get_response('https://www.google-analytics.com/debug/collect') . PHP_EOL;
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
        // Skip the nightly batch when the event-driven DB table has data
        if ( Tracking_Accuracy_DB::has_data() ) {
            return;
        }
        // Start measuring time
        $start_time = microtime( true );
        $maximum_orders_to_analyze = self::get_maximum_orders_to_analyze();
        // We want to at least analyze the count of active gateways * 100, or at least all orders in the past 30 days, whichever is larger.
        // And we don't want to exceed the maximum orders to analyze (default 6000).
        $amount_of_orders_to_analyze = min( $maximum_orders_to_analyze, max( count( self::get_enabled_payment_gateways() ) * 100, self::get_count_of_pmw_tracked_orders_for_one_month() ) );
        $analysis_stored = self::generate_gateway_analysis( $amount_of_orders_to_analyze );
        // set transient with date
        set_transient( 'pmw_tracking_accuracy_analysis_date', gmdate( 'Y-m-d H:i:s' ), MONTH_IN_SECONDS );
        // End measuring time
        $end_time = microtime( true );
        set_transient( 'pmw_tracking_accuracy_analysis_time', $end_time - $start_time, MONTH_IN_SECONDS );
        // Only clear the running guard if the analysis results were persisted successfully.
        // If transient storage failed, keep the guard set so the next run retries with a reduced cap.
        if ( $analysis_stored ) {
            delete_transient( 'pmw_tracking_accuracy_analysis_running' );
        }
    }

    // If the analysis runs into a timout we lower the amount of orders to analyze.
    protected static function get_maximum_orders_to_analyze() {
        if ( get_transient( 'pmw_tracking_accuracy_analysis_running' ) ) {
            // If available means that last run failed or timed out.
            $last_maximum_orders_to_analyze = ( get_transient( 'pmw_tracking_accuracy_analysis_max_orders' ) ? get_transient( 'pmw_tracking_accuracy_analysis_max_orders' ) : self::get_default_maximum_orders_to_analyse() );
            $maximum_orders_to_analyze = max( intval( $last_maximum_orders_to_analyze * 0.8 ), 100 );
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
            /**
             * Use the smaller of the two values. Either the user override or the calculated value.
             *
             * @since 1.58.5
             */
            apply_filters( 'pmw_tracking_accuracy_analysis_max_order_amount', $maximum_orders_to_analyze ),
            $maximum_orders_to_analyze
         );
        // Set the running lock with 60-minute expiration to prevent permanent locks
        // if the analysis crashes or times out, while giving enough headroom for
        // large shops with slow databases.
        set_transient( 'pmw_tracking_accuracy_analysis_running', true, HOUR_IN_SECONDS );
        // Store max orders with expiration to match the analysis data lifecycle
        set_transient( 'pmw_tracking_accuracy_analysis_max_orders', $maximum_orders_to_analyze, MONTH_IN_SECONDS );
        return $maximum_orders_to_analyze;
    }

    protected static function get_default_maximum_orders_to_analyse() {
        return 3000;
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
        // Prefer the custom DB table when it has data
        if ( Tracking_Accuracy_DB::has_data() ) {
            $rows = Tracking_Accuracy_DB::get_accuracy_data( 30 );
            if ( !empty( $rows ) ) {
                $result = [];
                foreach ( $rows as $row ) {
                    $measured = $row['orders_measured'] + $row['orders_acr'];
                    $result[] = [
                        'gateway_id'           => $row['gateway_id'],
                        'order_count_total'    => $row['orders_total'],
                        'order_count_measured' => $measured,
                        'percentage'           => floor( Helpers::get_percentage( $measured, $row['orders_total'] ) ),
                    ];
                }
                return $result;
            }
        }
        // Fall back to transient during transition period
        if ( get_transient( 'pmw_tracking_accuracy_analysis' ) ) {
            return get_transient( 'pmw_tracking_accuracy_analysis' );
        }
        return false;
    }

    public static function generate_gateway_analysis_array() {
        // Legacy method — now handled by generate_gateway_analysis()
        // Kept as no-op for backward compatibility if called externally
    }

    public static function get_gateway_analysis_weighted_array() {
        // Prefer the custom DB table when it has data
        if ( Tracking_Accuracy_DB::has_data() ) {
            $enabled_gateways = self::get_enabled_payment_gateways();
            $enabled_ids = array_map( function ( $gateway ) {
                return $gateway->id;
            }, $enabled_gateways );
            if ( !empty( $enabled_ids ) ) {
                $rows = Tracking_Accuracy_DB::get_accuracy_data( 30, $enabled_ids );
                if ( !empty( $rows ) ) {
                    $result = [];
                    foreach ( $rows as $row ) {
                        $entry = [
                            'gateway_id'           => $row['gateway_id'],
                            'order_count_total'    => $row['orders_total'],
                            'order_count_measured' => $row['orders_measured'],
                            'percentage'           => floor( Helpers::get_percentage( $row['orders_measured'], $row['orders_total'] ) ),
                        ];
                        $result[] = $entry;
                    }
                    return $result;
                }
            }
        }
        // Fall back to transient during transition period
        if ( get_transient( 'pmw_tracking_accuracy_analysis_weighted' ) ) {
            return get_transient( 'pmw_tracking_accuracy_analysis_weighted' );
        }
        return false;
    }

    /**
     * Generate both unweighted and weighted gateway analysis in a single pass.
     *
     * Fetches order IDs (not full objects) and bulk-loads only the meta fields
     * needed for the analysis. Computes per-gateway stats for:
     * - Unweighted: all gateways (active + inactive) with up to 100 orders each
     * - Weighted: only currently enabled gateways, all orders
     *
     * @param int $limit Maximum number of orders to analyze.
     * @return void
     * @since 1.58.5
     */
    public static function generate_gateway_analysis( $limit ) {
        $enabled_gateways = self::get_enabled_payment_gateways();
        $enabled_ids = array_map( function ( $gateway ) {
            return $gateway->id;
        }, $enabled_gateways );
        // Initialize weighted analysis for enabled gateways
        $weighted = [];
        foreach ( $enabled_ids as $gateway_id ) {
            $weighted[$gateway_id] = [
                'gateway_id'           => $gateway_id,
                'order_count_measured' => 0,
                'order_count_total'    => 0,
                'percentage'           => 0,
            ];
        }
        // Initialize unweighted analysis — tracks per-gateway counts capped at 100
        $unweighted = [];
        $unweighted_counts = [];
        // tracks how many orders counted per gateway for the 100-cap
        // Get order IDs (not full objects)
        $order_ids = self::get_pmw_tracked_orders( $limit );
        // Bulk-fetch all required meta in one query
        $orders_meta = self::get_bulk_order_meta( $order_ids );
        // Single pass over all orders
        foreach ( $order_ids as $order_id ) {
            if ( !isset( $orders_meta[$order_id] ) ) {
                continue;
            }
            $meta = $orders_meta[$order_id];
            $payment_method = $meta['payment_method'];
            if ( empty( $payment_method ) ) {
                continue;
            }
            // --- Weighted analysis (enabled gateways only) ---
            if ( in_array( $payment_method, $enabled_ids, true ) ) {
                ++$weighted[$payment_method]['order_count_total'];
                if ( $meta['conversion_pixel_fired'] ) {
                    ++$weighted[$payment_method]['order_count_measured'];
                }
            }
            // --- Unweighted analysis (all gateways, capped at 100 per gateway) ---
            if ( !isset( $unweighted_counts[$payment_method] ) ) {
                $unweighted_counts[$payment_method] = 0;
                $unweighted[$payment_method] = [
                    'gateway_id'           => $payment_method,
                    'order_count_total'    => 0,
                    'order_count_measured' => 0,
                    'percentage'           => 0,
                ];
            }
            if ( $unweighted_counts[$payment_method] < 100 ) {
                ++$unweighted[$payment_method]['order_count_total'];
                ++$unweighted_counts[$payment_method];
                if ( $meta['conversion_pixel_fired'] ) {
                    ++$unweighted[$payment_method]['order_count_measured'];
                }
            }
        }
        // Calculate percentages for weighted
        foreach ( $weighted as $gateway_id => $data ) {
            $weighted[$gateway_id]['percentage'] = floor( Helpers::get_percentage( $data['order_count_measured'], $data['order_count_total'] ) );
        }
        // Sort weighted by order_count_total descending
        usort( $weighted, function ( $a, $b ) {
            return $b['order_count_total'] - $a['order_count_total'];
        } );
        // Calculate percentages for unweighted
        foreach ( $unweighted as $gateway_id => $data ) {
            $unweighted[$gateway_id]['percentage'] = floor( Helpers::get_percentage( $data['order_count_measured'], $data['order_count_total'] ) );
        }
        // Convert unweighted to indexed array
        $unweighted = array_values( $unweighted );
        $result_unweighted = self::set_transient_with_verification( 'pmw_tracking_accuracy_analysis', $unweighted, MONTH_IN_SECONDS );
        $result_weighted = self::set_transient_with_verification( 'pmw_tracking_accuracy_analysis_weighted', $weighted, MONTH_IN_SECONDS );
        // If either transient failed to persist, keep the running guard set so the
        // next scheduled run treats this as a failure and retries.
        if ( !$result_unweighted || !$result_weighted ) {
            return false;
        }
        return true;
    }

    /**
     * Generate gateway analysis weighted array.
     *
     * @deprecated 1.58.5 Use generate_gateway_analysis() instead. Kept for backward compatibility.
     */
    public static function generate_gateway_analysis_weighted_array( $limit ) {
        self::generate_gateway_analysis( $limit );
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

    //  private static function try_connect_to_server( $server ) {
    //      if ($socket = @ fsockopen($server, 80)) {
    //          @fclose($socket);
    //          return 'online';
    //      } else {
    //          return 'offline';
    //      }
    //  }
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
            'return'       => 'ids',
        ] );
    }

    private static function get_count_of_pmw_tracked_orders_for_one_month() {
        $result = wc_get_orders( [
            'type'         => 'shop_order',
            'limit'        => 1,
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
            'paginate'     => true,
        ] );
        return $result->total;
    }

    /**
     * Fetch payment method and tracking meta for a batch of order IDs in bulk.
     *
     * Returns an associative array keyed by order ID:
     * [
     *   order_id => [
     *     'payment_method'              => string,
     *     'conversion_pixel_fired'      => bool,
     *     'conversion_pixel_trigger'    => string|null,
     *   ],
     * ]
     *
     * @param int[] $order_ids Array of order IDs.
     * @return array
     * @since 1.58.5
     */
    private static function get_bulk_order_meta( $order_ids ) {
        global $wpdb;
        if ( empty( $order_ids ) ) {
            return [];
        }
        $result = [];
        // Process in chunks to avoid excessively long IN clauses
        $chunks = array_chunk( $order_ids, 500 );
        foreach ( $chunks as $chunk ) {
            $placeholders = implode( ',', array_fill( 0, count( $chunk ), '%d' ) );
            if ( Helpers::is_wc_hpos_enabled() ) {
                // HPOS: payment_method is a column on wc_orders, meta is in wc_orders_meta
                // phpcs:disable WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
                $rows = $wpdb->get_results( $wpdb->prepare( "SELECT o.id AS order_id,\n\t\t\t\t\t\t\t\to.payment_method,\n\t\t\t\t\t\t\t\tMAX(CASE WHEN om.meta_key = '_wpm_conversion_pixel_fired' THEN om.meta_value END) AS pixel_fired,\n\t\t\t\t\t\t\t\tMAX(CASE WHEN om.meta_key = '_wpm_conversion_pixel_trigger' THEN om.meta_value END) AS pixel_trigger\n\t\t\t\t\t\tFROM {$wpdb->prefix}wc_orders o\n\t\t\t\t\t\tLEFT JOIN {$wpdb->prefix}wc_orders_meta om\n\t\t\t\t\t\t\tON o.id = om.order_id\n\t\t\t\t\t\t\tAND om.meta_key IN ('_wpm_conversion_pixel_fired', '_wpm_conversion_pixel_trigger')\n\t\t\t\t\t\tWHERE o.id IN ({$placeholders})\n\t\t\t\t\t\tGROUP BY o.id, o.payment_method", ...$chunk ) );
                // phpcs:enable WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
            } else {
                // Legacy postmeta
                // phpcs:disable WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
                $rows = $wpdb->get_results( $wpdb->prepare( "SELECT p.ID AS order_id,\n\t\t\t\t\t\t\t\tMAX(CASE WHEN pm.meta_key = '_payment_method' THEN pm.meta_value END) AS payment_method,\n\t\t\t\t\t\t\t\tMAX(CASE WHEN pm.meta_key = '_wpm_conversion_pixel_fired' THEN pm.meta_value END) AS pixel_fired,\n\t\t\t\t\t\t\t\tMAX(CASE WHEN pm.meta_key = '_wpm_conversion_pixel_trigger' THEN pm.meta_value END) AS pixel_trigger\n\t\t\t\t\t\tFROM {$wpdb->posts} p\n\t\t\t\t\t\tINNER JOIN {$wpdb->postmeta} pm\n\t\t\t\t\t\t\tON p.ID = pm.post_id\n\t\t\t\t\t\t\tAND pm.meta_key IN ('_payment_method', '_wpm_conversion_pixel_fired', '_wpm_conversion_pixel_trigger')\n\t\t\t\t\t\tWHERE p.ID IN ({$placeholders})\n\t\t\t\t\t\tGROUP BY p.ID", ...$chunk ) );
                // phpcs:enable WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
            }
            foreach ( $rows as $row ) {
                $result[$row->order_id] = [
                    'payment_method'           => ( isset( $row->payment_method ) ? $row->payment_method : '' ),
                    'conversion_pixel_fired'   => !empty( $row->pixel_fired ),
                    'conversion_pixel_trigger' => ( isset( $row->pixel_trigger ) ? $row->pixel_trigger : null ),
                ];
            }
        }
        return $result;
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

    /**
     * Set a transient with verification that it was stored correctly.
     *
     * This is important for sites using external object caches (Redis/Memcached)
     * where transients might fail to store due to:
     * - Memory limits being exceeded
     * - Data size limits per key
     * - Cache eviction policies
     * - Serialization issues with complex data
     *
     * If verification fails, we log the error for debugging purposes.
     *
     * @param string $transient  Transient name.
     * @param mixed  $value      Transient value.
     * @param int    $expiration Time until expiration in seconds.
     *
     * @return bool True if the transient was set and verified, false otherwise.
     *
     * @since 1.47.0
     */
    private static function set_transient_with_verification( $transient, $value, $expiration ) {
        // Attempt to set the transient
        $result = set_transient( $transient, $value, $expiration );
        if ( !$result ) {
            Logger::debug( 'Failed to set transient: ' . $transient );
            return false;
        }
        // Verify the transient was actually stored by reading it back
        $stored_value = get_transient( $transient );
        if ( false === $stored_value ) {
            Logger::debug( sprintf( 'Transient verification failed for %s. External object cache (Redis/Memcached) may have rejected the data. Cache type: %s', $transient, Environment::get_external_object_cache() ) );
            return false;
        }
        // For arrays, verify the count matches as a sanity check
        // This helps detect partial data corruption
        if ( is_array( $value ) && is_array( $stored_value ) ) {
            if ( count( $value ) !== count( $stored_value ) ) {
                Logger::debug( sprintf(
                    'Transient data mismatch for %s. Expected %d items, got %d. Possible data corruption.',
                    $transient,
                    count( $value ),
                    count( $stored_value )
                ) );
                return false;
            }
        }
        return true;
    }

}
