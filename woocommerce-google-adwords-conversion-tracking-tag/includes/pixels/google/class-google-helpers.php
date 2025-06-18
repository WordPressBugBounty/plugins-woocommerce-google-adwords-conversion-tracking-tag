<?php

namespace SweetCode\Pixel_Manager\Pixels\Google;

use SweetCode\Pixel_Manager\Admin\Environment;
use SweetCode\Pixel_Manager\Helpers;
use SweetCode\Pixel_Manager\Logger;
use SweetCode\Pixel_Manager\Options;
use SweetCode\Pixel_Manager\Product;
use SweetCode\Pixel_Manager\Shop;
defined( 'ABSPATH' ) || exit;
// Exit if accessed directly
class Google_Helpers {
    public static function is_ga4_debug_mode_active() {
        $debug_mode = apply_filters_deprecated(
            'wooptpm_enable_ga_4_mp_event_debug_mode',
            [false],
            '1.13.0',
            'pmw_enable_ga_4_mp_event_debug_mode'
        );
        $debug_mode = apply_filters_deprecated(
            'wpm_enable_ga_4_mp_event_debug_mode',
            [$debug_mode],
            '1.31.2',
            'pmw_enable_ga_4_mp_event_debug_mode'
        );
        return apply_filters( 'pmw_enable_ga_4_mp_event_debug_mode', $debug_mode );
    }

    public static function get_order_item_data( $order_item ) {
        $product = $order_item->get_product();
        if ( Product::is_not_wc_product( $product ) ) {
            return [];
        }
        $dyn_r_ids = Product::get_dyn_r_ids( $product );
        /**
         * Get the name of the product.
         * For Variations, fall back to the name in the parent product
         * because on some installs the name is not saved in the Variation.
         */
        if ( $product->get_type() === 'variation' ) {
            $parent_product = wc_get_product( $product->get_parent_id() );
            $name = $parent_product->get_name();
            $brand = Product::get_brand_name( $parent_product->get_id() );
        } else {
            $name = $product->get_name();
            $brand = Product::get_brand_name( $product->get_id() );
        }
        return [
            'id'             => (string) $dyn_r_ids[self::get_ga_id_type()],
            'name'           => (string) $name,
            'quantity'       => (int) $order_item['quantity'],
            'affiliation'    => (string) get_bloginfo( 'name' ),
            'brand'          => (string) $brand,
            'category'       => implode( ',', Product::get_product_category( $product->get_id() ) ),
            'category_array' => Product::get_product_category( $product->get_id() ),
            'variant'        => ( (string) ($product->get_type() === 'variation') ? Product::get_formatted_variant_text( $product ) : '' ),
            'price'          => self::pmw_get_order_item_price( $order_item ),
        ];
    }

    public static function get_ga_id_type() {
        $ga_id_type = 'post_id';
        $ga_id_type = apply_filters_deprecated(
            'wooptpm_product_id_type_for_google_analytics',
            [$ga_id_type],
            '1.13.0',
            'pmw_product_id_type_for_google_analytics'
        );
        $ga_id_type = apply_filters_deprecated(
            'wpm_product_id_type_for_google_analytics',
            [$ga_id_type],
            '1.31.2',
            'pmw_product_id_type_for_google_analytics'
        );
        // Change the output of the product ID type for Google Analytics
        return (string) apply_filters( 'pmw_product_id_type_for_google_analytics', $ga_id_type );
    }

    /**
     * Get the price of an order item.
     *
     * @param $order_item
     *
     * @return float
     */
    public static function pmw_get_order_item_price( $order_item ) {
        if ( Environment::is_woo_discount_rules_active() ) {
            $item_value = $order_item->get_meta( '_advanced_woo_discount_item_total_discount' );
            if ( isset( $item_value['discounted_price'] ) && 0 !== $item_value['discounted_price'] ) {
                return (float) $item_value['discounted_price'];
            }
            if ( isset( $item_value['initial_price'] ) && 0 !== $item_value['initial_price'] ) {
                return (float) $item_value['initial_price'];
            }
        }
        return (float) $order_item->get_order()->get_item_total( $order_item, Product::output_product_prices_with_tax() );
    }

    public static function add_categories_to_ga4_product_items( $item_details_array, $categories ) {
        // If $categories is not an array, return the $item_details_array as is
        if ( !is_array( $categories ) ) {
            return $item_details_array;
        }
        $categories = array_unique( $categories );
        // Remove empty categories and reindex the array
        $categories = array_values( array_filter( $categories ) );
        if ( count( $categories ) > 0 ) {
            $max_categories = 5;
            $item_details_array['item_category'] = $categories[0];
            $max = min( count( $categories ), $max_categories );
            for ($i = 1; $i < $max; $i++) {
                $item_details_array['item_category' . ($i + 1)] = $categories[$i];
            }
        }
        return $item_details_array;
    }

    public static function get_google_business_vertical_name_by_id( $id ) {
        $verticals = [
            0 => 'retail',
            1 => 'education',
            2 => 'flights',
            3 => 'hotel_rental',
            4 => 'jobs',
            5 => 'local',
            6 => 'real_estate',
            7 => 'travel',
            8 => 'custom',
        ];
        return $verticals[$id];
    }

    public static function get_google_ads_conversion_ids() {
        $google_ads_conversion_identifiers[Options::get_google_ads_conversion_id()] = Options::get_google_ads_conversion_label();
        $google_ads_conversion_identifiers = apply_filters_deprecated(
            'wgact_google_ads_conversion_identifiers',
            [$google_ads_conversion_identifiers],
            '1.10.2',
            'pmw_google_ads_conversion_identifiers'
        );
        $google_ads_conversion_identifiers = apply_filters_deprecated(
            'wooptpm_google_ads_conversion_identifiers',
            [$google_ads_conversion_identifiers],
            '1.13.0',
            'pmw_google_ads_conversion_identifiers'
        );
        $google_ads_conversion_identifiers = apply_filters_deprecated(
            'wpm_google_ads_conversion_identifiers',
            [$google_ads_conversion_identifiers],
            '1.31.2',
            'pmw_google_ads_conversion_identifiers'
        );
        $google_ads_conversion_identifiers = apply_filters( 'pmw_google_ads_conversion_identifiers', $google_ads_conversion_identifiers );
        $formatted_conversion_ids = [];
        foreach ( $google_ads_conversion_identifiers as $conversion_id => $conversion_label ) {
            $conversion_id = self::extract_google_ads_id( $conversion_id );
            if ( $conversion_id ) {
                $formatted_conversion_ids['AW-' . $conversion_id] = $conversion_label;
            }
        }
        return $formatted_conversion_ids;
    }

    private static function extract_google_ads_id( $string ) {
        $re = '/\\d{9,11}/';
        if ( $string ) {
            preg_match(
                $re,
                $string,
                $matches,
                PREG_OFFSET_CAPTURE,
                0
            );
            if ( is_array( $matches[0] ) ) {
                return $matches[0][0];
            }
        }
        return '';
    }

    /**
     * Address (first name, last name, postal code, and country are required).
     * You can optionally provide street address, city, and region as additional match keys.
     *
     * Source: https://support.google.com/google-ads/answer/9888145
     *
     * https://support.google.com/google-ads/answer/12785474?hl=en-AU&ref_topic=11337914#zippy=%2Cfind-enhanced-conversions-fields-on-your-conversion-page%2Cidentify-and-define-your-enhanced-conversions-fields
     *
     * @param $order
     * @return array
     */
    public static function get_google_enhanced_conversion_data( $order ) {
        $customer_data = [];
        if ( $order->get_billing_email() ) {
            $email = $order->get_billing_email();
            $email = self::normalize_for_enhanced_conversions( $email );
            $email = self::reformat_email_for_enhanced_conversions( $email );
            $email = self::hash_for_enhanced_conversions( $email );
            $customer_data['sha256_email_address'] = $email;
        }
        if ( $order->get_billing_phone() ) {
            $phone_number = (string) $order->get_billing_phone();
            $phone_number = Helpers::get_e164_formatted_phone_number( $phone_number, (string) $order->get_billing_country() );
            $phone_number = self::normalize_for_enhanced_conversions( $phone_number );
            $phone_number = self::hash_for_enhanced_conversions( $phone_number );
            $customer_data['sha256_phone_number'] = $phone_number;
        }
        $billing_address = self::get_billing_address_details( $order );
        if ( self::address_requirements_are_met( $billing_address ) ) {
            $customer_data['address'][] = $billing_address;
        }
        $shipping_address = self::get_shipping_address_details( $order );
        if ( self::address_requirements_are_met( $shipping_address ) ) {
            // Check if at least one field is different
            if ( array_diff_assoc( $shipping_address, $billing_address ) ) {
                $customer_data['address'][] = $shipping_address;
            }
        }
        return $customer_data;
    }

    // Address (first name, last name, postal code, and country are required).
    private static function address_requirements_are_met( $billing_address ) {
        $required_keys = [
            'first_name',
            'last_name',
            'postal_code',
            'country'
        ];
        $required_keys_sha256 = [
            'sha256_first_name',
            'sha256_last_name',
            'postal_code',
            'country'
        ];
        // If $billing_address contains all keys in $required_keys or in $required_keys_sha256 return true, else false
        return empty( array_diff( $required_keys, array_keys( $billing_address ) ) ) || empty( array_diff( $required_keys_sha256, array_keys( $billing_address ) ) );
    }

    private static function get_billing_address_details( $order ) {
        $customer_data = [];
        if ( $order->get_billing_first_name() ) {
            $first_name = (string) $order->get_billing_first_name();
            $first_name = self::normalize_for_enhanced_conversions( $first_name );
            $first_name = self::hash_for_enhanced_conversions( $first_name );
            $customer_data['sha256_first_name'] = $first_name;
        }
        if ( $order->get_billing_last_name() ) {
            $last_name = (string) $order->get_billing_last_name();
            $last_name = self::normalize_for_enhanced_conversions( $last_name );
            $last_name = self::hash_for_enhanced_conversions( $last_name );
            $customer_data['sha256_last_name'] = $last_name;
        }
        if ( $order->get_billing_address_1() ) {
            $street = (string) $order->get_billing_address_1();
            $street = self::normalize_for_enhanced_conversions( $street );
            $street = self::hash_for_enhanced_conversions( $street );
            $customer_data['sha256_street'] = $street;
        }
        if ( $order->get_billing_city() ) {
            $city = (string) $order->get_billing_city();
            $city = self::normalize_for_enhanced_conversions( $city );
            $customer_data['city'] = $city;
        }
        if ( $order->get_billing_state() ) {
            $region = (string) $order->get_billing_state();
            $region = self::normalize_for_enhanced_conversions( $region );
            $customer_data['region'] = $region;
        }
        if ( $order->get_billing_postcode() ) {
            $postal_code = (string) $order->get_billing_postcode();
            $postal_code = self::normalize_for_enhanced_conversions( $postal_code );
            $customer_data['postal_code'] = $postal_code;
        }
        if ( $order->get_billing_country() ) {
            $country = (string) $order->get_billing_country();
            $country = self::normalize_for_enhanced_conversions( $country );
            $customer_data['country'] = $country;
        }
        return $customer_data;
    }

    private static function get_shipping_address_details( $order ) {
        $customer_data = [];
        if ( $order->get_shipping_first_name() ) {
            $first_name = (string) $order->get_shipping_first_name();
            $first_name = self::normalize_for_enhanced_conversions( $first_name );
            $first_name = self::hash_for_enhanced_conversions( $first_name );
            $customer_data['sha256_first_name'] = $first_name;
        }
        if ( $order->get_shipping_last_name() ) {
            $last_name = (string) $order->get_shipping_last_name();
            $last_name = self::normalize_for_enhanced_conversions( $last_name );
            $last_name = self::hash_for_enhanced_conversions( $last_name );
            $customer_data['sha256_last_name'] = $last_name;
        }
        if ( $order->get_shipping_address_1() ) {
            $street = (string) $order->get_shipping_address_1();
            $street = self::normalize_for_enhanced_conversions( $street );
            $street = self::hash_for_enhanced_conversions( $street );
            $customer_data['sha256_street'] = $street;
        }
        if ( $order->get_shipping_city() ) {
            $city = (string) $order->get_shipping_city();
            $city = self::normalize_for_enhanced_conversions( $city );
            $customer_data['city'] = $city;
        }
        if ( $order->get_shipping_state() ) {
            $region = (string) $order->get_shipping_state();
            $region = self::normalize_for_enhanced_conversions( $region );
            $customer_data['region'] = $region;
        }
        if ( $order->get_shipping_postcode() ) {
            $postal_code = (string) $order->get_shipping_postcode();
            $postal_code = self::normalize_for_enhanced_conversions( $postal_code );
            $customer_data['postal_code'] = $postal_code;
        }
        if ( $order->get_shipping_country() ) {
            $country = (string) $order->get_shipping_country();
            $country = self::normalize_for_enhanced_conversions( $country );
            $customer_data['country'] = $country;
        }
        return $customer_data;
    }

    public static function get_gmc_language() {
        return strtoupper( substr( get_locale(), 0, 2 ) );
    }

    // https://developers.google.com/gtagjs/devguide/linker
    public static function get_google_linker_settings() {
        $linker_settings = apply_filters_deprecated(
            'wooptpm_google_cross_domain_linker_settings',
            [null],
            '1.13.0',
            'pmw_google_cross_domain_linker_settings'
        );
        $linker_settings = apply_filters_deprecated(
            'wpm_google_cross_domain_linker_settings',
            [$linker_settings],
            '1.31.2',
            'pmw_google_cross_domain_linker_settings'
        );
        return apply_filters( 'pmw_google_cross_domain_linker_settings', $linker_settings );
    }

    public static function get_google_consent_mode_ads_data_redaction_setting() {
        /**
         * As per Google advise 17.11.2022 (Google Snackable event), don't enable
         * ads_data_redaction by default while URL passthrough is enabled.
         */
        $ads_data_redaction = apply_filters_deprecated(
            'wooptpm_google_ads_data_redaction',
            [false],
            '1.13.0',
            'wpm_google_ads_data_redaction'
        );
        $ads_data_redaction = apply_filters_deprecated(
            'wooptpm_google_ads_data_redaction',
            [$ads_data_redaction],
            '1.31.0',
            'pmw_google_ads_data_redaction'
        );
        return (bool) apply_filters( 'pmw_google_ads_data_redaction', $ads_data_redaction );
    }

    public static function get_google_consent_mode_url_passthrough_setting() {
        $url_passthrough = apply_filters_deprecated(
            'wooptpm_google_url_passthrough',
            [true],
            '1.13.0',
            'wpm_google_url_passthrough'
        );
        $url_passthrough = apply_filters_deprecated(
            'wpm_google_url_passthrough',
            [$url_passthrough],
            '1.31.0',
            'pmw_google_url_passthrough'
        );
        return (bool) apply_filters( 'pmw_google_url_passthrough', $url_passthrough );
    }

    public static function get_ga4_parameters( $id ) {
        $ga_4_parameters = [];
        $user_id = Shop::get_user_id();
        if ( Options::is_google_user_id_active() && null !== $user_id ) {
            $ga_4_parameters['user_id'] = $user_id;
        }
        $ga_4_parameters = apply_filters_deprecated(
            'wooptpm_ga_4_parameters',
            [$ga_4_parameters, $id],
            '1.13.0',
            'pmw_ga_4_parameters'
        );
        $ga_4_parameters = apply_filters_deprecated(
            'wpm_ga_4_parameters',
            [$ga_4_parameters, $id],
            '1.31.2',
            'pmw_ga_4_parameters'
        );
        return apply_filters( 'pmw_ga_4_parameters', $ga_4_parameters, $id );
    }

    public static function get_ga_ua_parameters( $id ) {
        $ga_ua_parameters = [
            'anonymize_ip'     => true,
            'link_attribution' => Options::is_google_link_attribution_active(),
        ];
        if ( Options::is_google_user_id_active() && is_user_logged_in() ) {
            $ga_ua_parameters['user_id'] = get_current_user_id();
        }
        $ga_ua_parameters = apply_filters_deprecated(
            'woopt_pm_analytics_parameters',
            [$ga_ua_parameters, $id],
            '1.10.10',
            'pmw_ga_ua_parameters'
        );
        $ga_ua_parameters = apply_filters_deprecated(
            'wooptpm_ga_ua_parameters',
            [$ga_ua_parameters, $id],
            '1.13.0',
            'pmw_ga_ua_parameters'
        );
        $ga_ua_parameters = apply_filters_deprecated(
            'wpm_ga_ua_parameters',
            [$ga_ua_parameters, $id],
            '1.31.2',
            'pmw_ga_ua_parameters'
        );
        return apply_filters( 'pmw_ga_ua_parameters', $ga_ua_parameters, $id );
    }

    public static function get_all_refund_products( $refund ) {
        $data = [];
        $item_index = 1;
        foreach ( $refund->get_items() as $item_id => $item ) {
            //            $product = new WC_Product($refund_item->get_product_id());
            $order_item_data = self::get_order_item_data( $item );
            $data['pr' . $item_index . 'id'] = $order_item_data['id'];
            $data['pr' . $item_index . 'qt'] = -1 * $order_item_data['quantity'];
            $data['pr' . $item_index . 'pr'] = $order_item_data['price'];
            $item_index++;
        }
        return $data;
    }

    /**
     * Normalize and hash for enhanced conversions
     * https://support.google.com/google-ads/answer/13258081
     * https://developers.google.com/google-ads/api/docs/conversions/enhanced-conversions/web#php_2
     *
     * @param $string
     *
     * @return string
     * @since 1.32.0
     */
    private static function normalize_for_enhanced_conversions( $string ) {
        $string = Helpers::trim_string( $string );
        return strtolower( $string );
    }

    /**
     * Hash for enhanced conversions
     *
     * Source: https://support.google.com/google-ads/answer/13258081
     *
     * @param $string
     *
     * @return string
     * @since 1.42.5
     */
    private static function hash_for_enhanced_conversions( $string ) {
        return Helpers::hash_string( $string );
    }

    /**
     * Prepares an email address for enhanced conversions.
     * https://docs.google.com/document/d/1NRgJZmaFlAEtZ6S9-rAsiUaiW-ie4rQUca5xjNYL1M8/mobilebasic
     *
     * @param string $email
     *
     * @return string
     * @since 1.42.5
     */
    private static function reformat_email_for_enhanced_conversions( $email ) {
        // If the email is a @gmail or a @googlemail address, we need to remove the dots from the local part.
        $parts = explode( '@', $email );
        if ( isset( $parts[1] ) && in_array( $parts[1], ['gmail.com', 'googlemail.com'] ) ) {
            $local_part = str_replace( '.', '', $parts[0] );
            $local_part = str_replace( '+', '', $local_part );
            $email = $local_part . '@' . $parts[1];
        }
        return $email;
    }

    /**
     * Check if wbraid or gbraid is valid
     *
     * @param $braid
     * @return bool
     */
    public static function is_valid_braid( $braid ) {
        // Regular expression pattern for gbraid and wbraid
        $pattern = '/^[a-zA-Z0-9]{1,128}$/';
        // Check if the braid matches the pattern
        return (bool) preg_match( $pattern, $braid );
    }

    /**
     * Check if gclid is valid
     *
     * @param $gclid
     * @return bool
     */
    public static function is_valid_gclid( $gclid ) {
        // Regular expression pattern for gclid
        $pattern = '/^[\\w-]{20,120}$/';
        // Check if the gclid matches the pattern
        return (bool) preg_match( $pattern, $gclid );
    }

    /**
     * Determine the Google tag ID
     *
     * @return string
     *
     * @since 1.46.1
     */
    public static function determine_google_tag_id() {
        $tag_id = '';
        $google_base_url = 'https://www.googletagmanager.com/gtag/js?id=';
        // If Google Ads is active and the URL is reachable, return the Google Ads conversion ID
        if ( Options::is_google_ads_active() ) {
            $conversion_id = Options::get_google_ads_conversion_id();
            $google_ads_tag_url = $google_base_url . 'AW-' . $conversion_id;
            if ( Helpers::is_url_accessible( $google_ads_tag_url ) ) {
                return 'AW-' . $conversion_id;
            }
            Logger::error( 'The Google Ads gtag script cannot be loaded through ' . $google_ads_tag_url . '. Please check your Google Ads settings.' );
        }
        // If Google Analytics is active and the URL is reachable, return the Google Analytics ID
        if ( Options::is_google_analytics_active() && Helpers::is_url_accessible( $google_base_url . Options::get_ga4_measurement_id() ) ) {
            return (string) Options::get_ga4_measurement_id();
        }
        // Fallback to base Google Ads conversion ID if the URL is not reachable
        if ( Options::is_google_ads_active() && Helpers::is_url_accessible( $google_base_url . Options::get_google_ads_conversion_id() ) ) {
            return (string) Options::get_google_ads_conversion_id();
        }
        return $tag_id;
    }

    /**
     * Get the Google tag ID
     *
     * @return string
     *
     * @since 1.46.1
     */
    public static function get_google_tag_id() {
        $transient_name = 'pmw_google_tag_id';
        // If saved in transient get from transient
        $google_tag_id = get_transient( $transient_name );
        if ( $google_tag_id ) {
            return $google_tag_id;
        }
        $google_tag_id = apply_filters_deprecated(
            'pmw_google_tracking_id',
            [self::determine_google_tag_id()],
            '1.48.0',
            'google_tag_id'
        );
        $google_tag_id = apply_filters( 'google_tag_id', $google_tag_id );
        set_transient( $transient_name, $google_tag_id, HOUR_IN_SECONDS );
        return $google_tag_id;
    }

    /**
     * Performs a health check for the Google Tag Gateway by sending a request to the gateway's health check endpoint.
     *
     * @return array An array containing the status of the health check and any error messages.
     *
     * @since 1.48.0
     */
    public static function gtag_gateway_health_check() {
        $gateway_url = get_site_url() . Options::get_google_tag_gateway_measurement_path() . '/healthy';
        $response = wp_remote_get( $gateway_url, [
            'timeout'   => 3,
            'headers'   => [
                'Accept' => 'application/json',
            ],
            'sslverify' => false,
        ] );
        if ( is_wp_error( $response ) ) {
            Logger::error( 'Google Tag Gateway Health Check: ' . $gateway_url . ' - ' . $response->get_error_message() );
            return [
                'status' => false,
                'error'  => 'Request error: ' . $response->get_error_message(),
            ];
        }
        $body = wp_remote_retrieve_body( $response );
        $code = wp_remote_retrieve_response_code( $response );
        if ( 200 !== $code ) {
            if ( strpos( $body, 'challenge' ) !== false ) {
                Logger::error( 'Google Tag Gateway Health Check: ' . $gateway_url . ' - Blocked by Cloudflare' );
                return [
                    'status' => false,
                    'error'  => 'Blocked by Cloudflare.',
                    'body'   => $body,
                ];
            }
            Logger::error( 'Google Tag Gateway Health Check: ' . $gateway_url . ' - Unexpected response code: ' . $code );
            return [
                'status' => false,
                'error'  => 'Unexpected response code: ' . $code,
                'body'   => $body,
            ];
        }
        if ( strpos( $body, 'ok' ) !== false ) {
            return [
                'status'  => true,
                'message' => 'Gateway is healthy.',
            ];
        }
        Logger::error( 'Google Tag Gateway Health Check: ' . $gateway_url . ' - Unexpected response body: ' . $body );
        return [
            'status' => false,
            'error'  => 'Unexpected response body.',
            'body'   => $body,
        ];
    }

}
