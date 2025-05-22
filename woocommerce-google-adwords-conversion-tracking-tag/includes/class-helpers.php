<?php

namespace SweetCode\Pixel_Manager;

use ActionScheduler_Store;
use libphonenumber\NumberParseException;
use libphonenumber\PhoneNumberFormat;
use libphonenumber\PhoneNumberUtil;
use stdClass;
use WC_Log_Handler_File;
use SweetCode\Pixel_Manager\Admin\Environment;
defined( 'ABSPATH' ) || exit;
// Exit if accessed directly
class Helpers {
    private static $user_data;

    private static $current_screen_id;

    private static $current_screen_post_type;

    /**
     * This function takes any of the input types and sanitizes them automatically.
     *
     * @param string $input_type
     *        The type of input to sanitize. Can be INPUT_GET, INPUT_POST, INPUT_COOKIE, INPUT_SERVER, or INPUT_ENV.
     *
     * @param bool   $raw
     *        Whether to apply raw sanitization or not. Default is false.
     *        If true, the function will apply FILTER_UNSAFE_RAW sanitization.
     *        This is potentially unsafe and should be used with caution.
     *
     * @return mixed
     */
    public static function get_input_vars( $input_type, $raw = false ) {
        $data = filter_input_array( $input_type, FILTER_UNSAFE_RAW );
        return self::generic_sanitization( $data, $raw );
    }

    private static function sanitize_string( $string, $raw = false ) {
        if ( $raw ) {
            return filter_var( $string, FILTER_UNSAFE_RAW );
        }
        // Don't use FILTER_SANITIZE_STRING as it is deprecated from PHP 8.x
        // Don't use FILTER_SANITIZE_FULL_SPECIAL_CHARS as it will reformat Umlauts like ä, ö, ü
        // Don't use strip_tags() as it will remove information.
        // https://stackoverflow.com/a/69207369/4688612
        // https://gist.github.com/alewolf/e9235adeaf26dc024bab4f53825ec6da
        return htmlspecialchars( $string, ENT_QUOTES, 'UTF-8' );
    }

    public static function generic_sanitization( $input, $raw = false ) {
        if ( is_array( $input ) ) {
            $sanitized_array = [];
            foreach ( $input as $key => $value ) {
                $sanitized_array[$key] = self::generic_sanitization( $value, $raw );
            }
            return $sanitized_array;
        }
        if ( is_object( $input ) ) {
            $sanitized_object = new stdClass();
            foreach ( $input as $key => $value ) {
                $sanitized_object->{$key} = self::generic_sanitization( $value, $raw );
            }
            return $sanitized_object;
        }
        if ( is_string( $input ) ) {
            return self::sanitize_string( $input, $raw );
        }
        if ( is_bool( $input ) ) {
            // No sanitization needed for boolean values
            return $input;
        }
        // If the input is of any other type (e.g., int, float), no sanitization is needed
        return $input;
    }

    public static function is_allowed_notification_page( $page ) {
        $allowed_pages = ['page_wpm', 'index.php', 'dashboard'];
        foreach ( $allowed_pages as $allowed_page ) {
            if ( strpos( $page, $allowed_page ) !== false ) {
                return true;
            }
        }
        return false;
    }

    public static function is_wc_hpos_enabled() {
        return class_exists( 'Automattic\\WooCommerce\\Utilities\\OrderUtil' ) && \Automattic\WooCommerce\Utilities\OrderUtil::custom_orders_table_usage_is_enabled();
    }

    /**
     * Checks if the current screen is the WooCommerce Orders page.
     *
     * @return bool
     *
     * @since 1.43.5
     */
    public static function is_orders_page() {
        if ( !is_admin() ) {
            return false;
        }
        $_get = self::get_input_vars( INPUT_GET );
        // Detects the order page in the admin on non-HPOS enabled shops
        if ( 'edit-shop_order' === self::pmw_current_screen( 'id' ) ) {
            return true;
        }
        // Detects the order page in the admin on HPOS enabled shops
        if ( 'woocommerce_page_wc-orders' === self::pmw_current_screen( 'id' ) && 'shop_order' === self::pmw_current_screen( 'post_type' ) && !isset( $_get['action'] ) ) {
            return true;
        }
        return false;
    }

    /**
     * Checks if the current screen is the WooCommerce Edit Order page.
     *
     * @return bool
     *
     * @since 1.43.5
     */
    public static function is_edit_order_page() {
        if ( !is_admin() ) {
            return false;
        }
        $_get = self::get_input_vars( INPUT_GET );
        // Detects the order page in the admin on non-HPOS enabled shops
        if ( 'shop_order' === self::pmw_current_screen( 'id' ) ) {
            return true;
        }
        // Detects the order page in the admin on HPOS enabled shops
        if ( 'woocommerce_page_wc-orders' === self::pmw_current_screen( 'id' ) && 'shop_order' === self::pmw_current_screen( 'post_type' ) && isset( $_get['action'] ) && isset( $_get['id'] ) ) {
            return true;
        }
        return false;
    }

    public static function is_email( $email ) {
        return filter_var( $email, FILTER_VALIDATE_EMAIL );
    }

    public static function is_url( $url ) {
        return filter_var( $url, FILTER_VALIDATE_URL );
    }

    public static function clean_product_name_for_output( $name ) {
        return html_entity_decode( $name, ENT_QUOTES | ENT_HTML5, 'UTF-8' );
    }

    public static function get_e164_formatted_phone_number( $number, $country ) {
        try {
            $phone_util = PhoneNumberUtil::getInstance();
            $number_parsed = $phone_util->parse( $number, $country );
            return $phone_util->format( $number_parsed, PhoneNumberFormat::E164 );
        } catch ( NumberParseException $e ) {
            /**
             * Don't error log the exception. It leads to more confusion than it helps:
             * https://wordpress.org/support/topic/php-errors-in-version-1-27-0/
             */
            return $number;
        }
    }

    // https://stackoverflow.com/a/60199374/4688612
    public static function is_iframe() {
        if ( isset( $_SERVER['HTTP_SEC_FETCH_DEST'] ) && 'iframe' === $_SERVER['HTTP_SEC_FETCH_DEST'] ) {
            return true;
        } else {
            return false;
        }
    }

    public static function get_percentage( $counter, $denominator ) {
        return ( $denominator > 0 ? round( $counter / $denominator * 100 ) : 0 );
    }

    public static function get_user_country_code( $user ) {
        // If the country code is set on the user, return it
        if ( isset( $user->billing_country ) ) {
            return $user->billing_country;
        }
        // Geolocate the user IP and return the country code
        return Geolocation::get_visitor_country();
    }

    public static function is_dashboard() {
        global $pagenow;
        // Don't check for the plugin settings page. Notifications have to be handled there.
        $allowed_pages = ['index.php', 'dashboard'];
        foreach ( $allowed_pages as $allowed_page ) {
            if ( strpos( $pagenow, $allowed_page ) !== false ) {
                return true;
            }
        }
        return false;
    }

    /**
     * Trim a settings page input string by removing all whitespace, newlines, tabs, double quotes, single quotes and backticks.
     *
     * @return string
     * @since 1.27.10
     */
    public static function trim_string( $string ) {
        $string = trim( $string );
        // Return the string with all newlines, tabs, double quotes, single quotes and backticks removed. Keep spaces within the string.
        $string = preg_replace( '/[\\n\\r\\t"\'`]/', '', $string );
        // Remove all quotes
        $string = str_replace( '"', '', $string );
        // Remove html quote entities
        $string = str_replace( '&quot;', '', $string );
        // Remove anything from the front and back of the string that looks like &#039; or similar
        $string = preg_replace( '/&[^;]+;/', '', $string );
        // Remove a ; at the end of the string
        return rtrim( $string, ';' );
    }

    private static function normalize_for_snapchat( $string ) {
        // to lower case
        $string = strtolower( $string );
        // remove punctuation
        $string = self::remove_punctuation( $string );
        return $string;
    }

    /**
     * Remove punctuation from a string.
     *
     * @param string $string
     * @return string
     *
     * @since 1.42.7
     */
    public static function remove_punctuation( $string ) {
        return str_replace( [
            '.',
            ',',
            ';',
            ':',
            '!',
            '?',
            '(',
            ')',
            '[',
            ']',
            '{',
            '}',
            '<',
            '>',
            '/',
            '\\',
            '|',
            '@',
            '#',
            '$',
            '%',
            '^',
            '&',
            '*',
            '+',
            '=',
            '~',
            '`',
            '"',
            "'"
        ], '', $string );
    }

    private static function normalize_google_email_address( $email ) {
        // If the email address is a @gmail.com or @googlemail.com address, remove dots and plus signs in the first part of the email address
        if ( preg_match( '/@gmail.com$/', $email ) || preg_match( '/@googlemail.com$/', $email ) ) {
            $email_parts = explode( '@', $email );
            // remove all dots in the first part
            $email_parts[0] = str_replace( '.', '', $email_parts[0] );
            // remove all plus signs in the first part
            $email_parts[0] = str_replace( '+', '', $email_parts[0] );
            $email = implode( '@', $email_parts );
        }
        return $email;
    }

    /**
     * Filter to return the Facebook fbevents.js script URL.
     *
     * It allows to either return the latest version or a specific version.
     *
     * @return string
     * @since 1.30.0
     */
    public static function get_facebook_fbevents_js_url() {
        $fbevents_standard_url = 'https://connect.facebook.net/en_US/fbevents.js';
        if ( apply_filters( 'pmw_facebook_fbevents_script_version', '' ) ) {
            return $fbevents_standard_url . '?v=' . apply_filters( 'pmw_facebook_fbevents_script_version', '' );
        }
        if ( apply_filters( 'pmw_facebook_fbevents_script_url', '' ) ) {
            return apply_filters( 'pmw_facebook_fbevents_script_url', '' );
        }
        return $fbevents_standard_url;
    }

    /**
     * Given a datetime string, return the unix timestamp for the local timezone.
     *
     * @param $datetime_string
     * @return false|string
     *
     * @since 1.30.8
     */
    public static function datetime_string_to_unix_timestamp_in_local_timezone( $datetime_string ) {
        return wp_date( 'U', strtotime( $datetime_string . ' ' . wp_timezone_string() ) );
    }

    public static function is_admin_page( $page_ids = [] ) {
        // If no page IDs are given, check if the current page is an admin page
        if ( empty( $page_ids ) ) {
            return false;
        }
        // Check if the current page is an admin page and if it is one of the given page IDs
        //		return is_admin() && in_array(get_current_screen()->id, $page_ids);
        $_get = self::get_input_vars( INPUT_GET );
        if ( !isset( $_get['page'] ) || !in_array( $_get['page'], $page_ids ) ) {
            return false;
        }
        return true;
    }

    public static function hash_string( $string, $algo = 'sha256' ) {
        // If the given algorithm is supported, hash the string
        if ( in_array( $algo, hash_algos(), true ) ) {
            return hash( $algo, $string );
        }
        return $string;
    }

    public static function get_random_ip() {
        $ip = '';
        // Generate a random IP address
        for ($i = 0; $i < 4; $i++) {
            $ip .= wp_rand( 0, 255 );
            if ( $i < 3 ) {
                $ip .= '.';
            }
        }
        return $ip;
    }

    /**
     * We are controlling the entire output in all formats from here. Why?
     * Because each pixel has different requirements for each data field.
     * Hashed, not hashed, lower case, not lower case, phone with + or without +,
     * etc.
     *
     * @return array
     */
    public static function get_user_data( $order = null ) {
        // if the user_data array is already set and not empty, return it
        if ( !empty( self::$user_data ) ) {
            return self::$user_data;
        }
        $user_data = [];
        $order = ( Environment::is_woocommerce_active() && !$order && Shop::pmw_is_order_received_page() && Shop::pmw_get_current_order() ? Shop::pmw_get_current_order() : $order );
        // If the order is not null,
        // get the $current_user from the order.
        if ( $order && $order->get_user() ) {
            $current_user = $order->get_user();
        } else {
            $current_user = ( is_user_logged_in() ? wp_get_current_user() : null );
        }
        // If the user is logged in, get the user data
        if ( $current_user ) {
            $user_data['id']['raw'] = get_current_user_id();
            $user_data['id']['sha256'] = self::hash_string( $user_data['id']['raw'] );
        }
        /**
         * Determine the details.
         *
         * If logged in use the logged-in user data.
         * On the order page, override the logged-in user data with the order data.
         */
        $raw_user_data = self::get_raw_user_data( $order, $current_user );
        // Add the details to the data array and return it
        // Only add the data if it exists
        //		$data = $email ? self::get_user_object_email($email) : $data;
        // If $email is not empty,
        // get the data from self::get_user_object_email($email) and add it to $data['email']
        if ( !empty( $raw_user_data['email'] ) ) {
            $user_data['email'] = self::get_user_object_email( $raw_user_data['email'] );
        }
        if ( !empty( $raw_user_data['first_name'] ) ) {
            $user_data['first_name'] = self::get_user_object_first_name( $raw_user_data['first_name'] );
        }
        if ( !empty( $raw_user_data['last_name'] ) ) {
            $user_data['last_name'] = self::get_user_object_last_name( $raw_user_data['last_name'] );
        }
        if ( !empty( $raw_user_data['phone'] ) ) {
            $user_data['phone'] = self::get_user_object_phone( $raw_user_data['phone'], $current_user );
        }
        if ( !empty( $raw_user_data['city'] ) ) {
            $user_data['city'] = self::get_user_object_city( $raw_user_data['city'] );
        }
        if ( !empty( $raw_user_data['state'] ) ) {
            $user_data['state'] = self::get_user_object_state( $raw_user_data['state'] );
        }
        if ( !empty( $raw_user_data['postcode'] ) ) {
            $user_data['postcode'] = self::get_user_object_postcode( $raw_user_data['postcode'] );
        }
        if ( !empty( $raw_user_data['country'] ) ) {
            $user_data['country'] = self::get_user_object_country( $raw_user_data['country'] );
        }
        if ( !empty( $raw_user_data['roles'] ) ) {
            $user_data['roles'] = $raw_user_data['roles'];
        }
        self::$user_data = $user_data;
        return $user_data;
    }

    public static function get_raw_user_data( $order = null, $current_user = null ) {
        // Email
        $email = ( is_user_logged_in() && $current_user->user_email ? $current_user->user_email : '' );
        $email = ( $order && $order->get_billing_email() ? $order->get_billing_email() : $email );
        // First name
        $first_name = ( is_user_logged_in() && $current_user->first_name ? $current_user->first_name : '' );
        $first_name = ( $order && $order->get_billing_first_name() ? $order->get_billing_first_name() : $first_name );
        // Last name
        $last_name = ( is_user_logged_in() && $current_user->last_name ? $current_user->last_name : '' );
        $last_name = ( $order && $order->get_billing_last_name() ? $order->get_billing_last_name() : $last_name );
        // Phone
        $phone = ( is_user_logged_in() && $current_user->billing_phone ? $current_user->billing_phone : '' );
        $phone = ( $order && $order->get_billing_phone() ? $order->get_billing_phone() : $phone );
        // City
        $city = ( is_user_logged_in() && $current_user->billing_city ? $current_user->billing_city : '' );
        $city = ( $order && $order->get_billing_city() ? $order->get_billing_city() : $city );
        // State
        $state = ( is_user_logged_in() && $current_user->billing_state ? $current_user->billing_state : '' );
        $state = ( $order && $order->get_billing_state() ? $order->get_billing_state() : $state );
        // Postcode
        $postcode = ( is_user_logged_in() && $current_user->billing_postcode ? $current_user->billing_postcode : '' );
        $postcode = ( $order && $order->get_billing_postcode() ? $order->get_billing_postcode() : $postcode );
        // Country
        $country = ( is_user_logged_in() && $current_user->billing_country ? $current_user->billing_country : '' );
        $country = ( $order && $order->get_billing_country() ? $order->get_billing_country() : $country );
        // Roles
        $roles = ( is_user_logged_in() && $current_user ? $current_user->roles : [] );
        return [
            'email'      => $email,
            'first_name' => $first_name,
            'last_name'  => $last_name,
            'phone'      => $phone,
            'city'       => $city,
            'state'      => $state,
            'postcode'   => $postcode,
            'country'    => $country,
            'roles'      => $roles,
        ];
    }

    public static function get_user_data_object( $order = null ) {
        $data = self::get_user_data( $order );
        return json_decode( wp_json_encode( $data ) );
    }

    private static function get_user_object_email( $email ) {
        $email = self::trim_string( $email );
        $email = strtolower( $email );
        //		$email = self::normalize_google_email_address($email);
        return [
            'raw'       => $email,
            'sha256'    => self::hash_string( $email ),
            'facebook'  => self::hash_string( $email ),
            'pinterest' => self::hash_string( $email ),
            'tiktok'    => self::hash_string( $email ),
        ];
    }

    private static function get_user_object_first_name( $first_name ) {
        $first_name = self::trim_string( $first_name );
        return [
            'raw'       => $first_name,
            'sha256'    => self::hash_string( $first_name ),
            'facebook'  => self::hash_string( strtolower( $first_name ) ),
            'pinterest' => self::hash_string( strtolower( $first_name ) ),
            'snapchat'  => self::hash_string( self::normalize_for_snapchat( $first_name ) ),
        ];
    }

    private static function get_user_object_last_name( $last_name ) {
        $last_name = self::trim_string( $last_name );
        return [
            'raw'       => $last_name,
            'sha256'    => self::hash_string( $last_name ),
            'facebook'  => self::hash_string( strtolower( $last_name ) ),
            'pinterest' => self::hash_string( strtolower( $last_name ) ),
            'snapchat'  => self::hash_string( self::normalize_for_snapchat( $last_name ) ),
        ];
    }

    private static function get_user_object_phone( $phone, $current_user ) {
        $phone = self::trim_string( $phone );
        $phone_e164 = self::get_e164_formatted_phone_number( strtolower( $phone ), self::get_user_country_code( $current_user ) );
        return [
            'raw'       => $phone,
            'e164'      => $phone_e164,
            'facebook'  => self::hash_string( str_replace( '+', '', strtolower( $phone ) ) ),
            'pinterest' => self::hash_string( preg_replace( '/[^0-9]/', '', $phone_e164 ) ),
            'snapchat'  => self::hash_string( str_replace( '+', '', $phone_e164 ) ),
            'tiktok'    => self::hash_string( $phone_e164 ),
            'sha256'    => [
                'raw'  => self::hash_string( $phone ),
                'e164' => self::hash_string( $phone_e164 ),
            ],
        ];
    }

    private static function get_user_object_city( $city ) {
        $city = self::trim_string( $city );
        return [
            'raw'       => $city,
            'sha256'    => self::hash_string( $city ),
            'facebook'  => self::hash_string( strtolower( $city ) ),
            'pinterest' => self::hash_string( strtolower( preg_replace( '/[^A-Za-z0-9\\-]/', '', $city ) ) ),
            'snapchat'  => self::hash_string( self::normalize_for_snapchat( $city ) ),
        ];
    }

    private static function get_user_object_state( $state ) {
        $state = self::trim_string( $state );
        return [
            'raw'       => $state,
            'sha256'    => self::hash_string( $state ),
            'facebook'  => self::hash_string( preg_replace( '/[a-zA-Z]{2}-/', '', strtolower( $state ) ) ),
            'pinterest' => self::hash_string( strtolower( $state ) ),
            'snapchat'  => self::hash_string( self::normalize_for_snapchat( $state ) ),
        ];
    }

    private static function get_user_object_postcode( $postcode ) {
        $postcode = self::trim_string( $postcode );
        return [
            'raw'       => $postcode,
            'sha256'    => self::hash_string( $postcode ),
            'facebook'  => self::hash_string( strtolower( $postcode ) ),
            'pinterest' => self::hash_string( preg_replace( '/[^0-9]/', '', $postcode ) ),
        ];
    }

    /**
     * This function takes a user data array and a country string and adds the country values to the user object.
     *
     * @param string $country
     * @return array
     */
    private static function get_user_object_country( $country ) {
        $country = self::trim_string( $country );
        return [
            'raw'       => $country,
            'sha256'    => self::hash_string( $country ),
            'facebook'  => self::hash_string( strtolower( $country ) ),
            'pinterest' => self::hash_string( strtolower( $country ) ),
            'snapchat'  => self::hash_string( self::normalize_for_snapchat( $country ) ),
        ];
    }

    /**
     * Filter to define if all s2s requests should be sent blocking
     *
     * @return bool
     */
    public static function should_all_s2s_requests_be_sent_blocking() {
        return (bool) apply_filters( 'pmw_send_all_s2s_requests_blocking', false ) || Options::is_http_request_logging_enabled();
    }

    /**
     * This function takes a number and formats it as a decimal.
     *
     * @param mixed $number
     *         The number to format. Can be a string or a float.
     *
     * @param mixed $dp
     *         The number of decimal places to round to. Default is false, which means no rounding is performed.
     *
     * @param bool  $trim_zeros
     *         Whether to trim trailing zeros and the decimal point. Default is false.
     *
     * @return float | null | string
     *         Returns the formatted decimal number as a string, or false if the input is not a valid number.
     */
    public static function format_decimal( $number, $dp = false, $trim_zeros = false ) {
        if ( function_exists( 'wc_format_decimal' ) ) {
            return wc_format_decimal( $number, $dp, $trim_zeros );
        } else {
            if ( is_string( $number ) ) {
                // Remove all spaces
                $number = str_replace( ' ', '', $number );
                // Convert multiple dots to one
                // $number = preg_replace('/\.{2,}/', '.', $number);
                $number = preg_replace( '/\\.(?![^.]+$)|[^0-9.-]/', '', $number );
                // echo $number . PHP_EOL;
                $number = (float) preg_replace( '/[^0-9\\.\\-]/', '', $number );
            }
            if ( !is_float( $number ) ) {
                return null;
            }
            if ( false !== $dp ) {
                $number = round( $number, $dp );
            }
            $number = (string) $number;
            if ( $trim_zeros ) {
                $number = rtrim( $number, '0' );
                $number = rtrim( $number, '.' );
            }
            return $number;
        }
    }

    /**
     * Checks if experimental feature EXPERIMENTAL_PMW is enabled.
     *
     * @return bool
     *        Returns true if EXPERIMENTAL_PMW or PMW_EXPERIMENTS are defined and have a truthy value, otherwise returns false.
     */
    public static function is_experiment() {
        return defined( 'EXPERIMENTAL_PMW' ) && EXPERIMENTAL_PMW || defined( 'PMW_EXPERIMENTS' ) && PMW_EXPERIMENTS;
    }

    /**
     * This function checks if the WooCommerce compatibility declaration function exists.
     *
     * @return bool
     */
    public static function does_the_woocommerce_declare_compatibility_function_exist() {
        return class_exists( '\\Automattic\\WooCommerce\\Utilities\\FeaturesUtil' ) && method_exists( '\\Automattic\\WooCommerce\\Utilities\\FeaturesUtil', 'declare_compatibility' );
    }

    /**
     * Declare compatibility with a feature for WooCommerce.
     *
     * @param string $feature_id
     *        The ID of the feature to declare compatibility with.
     *
     * @param string $plugin_file
     *        Optional. The plugin file to specify the compatibility for. Default is PMW_PLUGIN_BASENAME.
     *
     * @param bool   $positive_compatibility
     *        Optional. Whether to declare positive compatibility or not. Default is true.
     */
    public static function declare_woocommerce_compatibility( $feature_id, $plugin_file = PMW_PLUGIN_BASENAME, $positive_compatibility = true ) {
        if ( !self::does_the_woocommerce_declare_compatibility_function_exist() ) {
            return;
        }
        \Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( $feature_id, $plugin_file, $positive_compatibility );
    }

    /**
     * Get the version information of the software.
     *
     * @return array The version information.
     *  - 'number' : string : The current version number.
     *  - 'pro' : bool : Whether the software is a premium version or not.
     *  - 'eligible_for_updates' : bool : Whether the software can receive updates or not.
     *  - 'distro' : string : The distribution identifier of the software.
     *  - 'beta' : bool : Whether the software is a beta version or not.
     */
    public static function get_version_info() {
        return [
            'number'               => PMW_CURRENT_VERSION,
            'pro'                  => wpm_fs()->is__premium_only(),
            'eligible_for_updates' => wpm_fs()->can_use_premium_code__premium_only(),
            'distro'               => PMW_DISTRO,
            'beta'                 => self::is_beta(),
            'show'                 => apply_filters( 'pmw_show_version_info', true ),
        ];
    }

    /**
     * This function checks if the application is running in beta mode.
     *
     * @return bool
     *
     * @see   self::is_experiment()
     * @see   wpm_fs()->is_beta()
     *
     * @since 1.35.1
     */
    private static function is_beta() {
        if ( self::is_experiment() ) {
            return true;
        }
        if ( PMW_DISTRO === 'fms' ) {
            return wpm_fs()->is_beta();
        }
        return false;
    }

    public static function is_valid_ipv6_address( $ip ) {
        return filter_var( $ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6 );
    }

    /**
     * Check if the WP_DEBUG constant is defined and set to true or false.
     *
     * This function essentially checks whether WordPress debugging mode
     * is active or not. The WP_DEBUG constant is an in-built WordPress
     * constant that can be used to trigger the 'debug' mode throughout
     * WordPress.
     *
     * @return bool Returns true if WP_DEBUG is defined and set to true,
     * otherwise it returns false.
     *
     * @since 1.35.1
     */
    public static function is_wp_debug_mode_active() {
        return defined( 'WP_DEBUG' ) && WP_DEBUG;
    }

    /**
     * Checks if the PMW_DEBUG_CONSTANT is defined and true.
     *
     * This function checks if the PHP constant 'PMW_DEBUG' is defined in the system. If it is defined,
     * this function further checks that the value of the constant is truthy. It essentially determines
     * if the PMW Debug Mode is active in the environment.
     *
     * @return bool Returns true if 'PMW_DEBUG' constant is defined and its value is true, else returns false.
     *
     * @since 1.36.0
     */
    public static function is_pmw_debug_mode_active() {
        return defined( 'PMW_DEBUG' ) && PMW_DEBUG;
    }

    /**
     * Retrieves the file name of the most recent WooCommerce log that starts with a specific source.
     *
     * This function fetches all log files, then filters them to retain only the ones
     * starting with the specified source string. It then returns the file name of the
     * most recent log matching this criteria.
     *
     * If there are no logs, or no logs match the source, an empty string is returned.
     *
     * @param string $source The source string that the log file name should start with.
     * @return string The file name of the most recent log that starts with $source. If no such log exists, returns an empty string.
     */
    private static function get_file_name_of_most_recent_wc_log( $source ) {
        // return if the class WC_Log_Handler_File does not exist
        if ( !class_exists( 'WC_Log_Handler_File' ) ) {
            return '';
        }
        $logs = WC_Log_Handler_File::get_log_files();
        if ( empty( $logs ) ) {
            return '';
        }
        // If $logs array contains a key that starts with $source . '-' then return the latest in the array
        $pmw_logs = array_filter( $logs, function ( $key ) use($source) {
            return strpos( $key, $source . '-' ) === 0;
        }, ARRAY_FILTER_USE_KEY );
        if ( empty( $pmw_logs ) ) {
            return '';
        }
        $last_key = array_key_last( $pmw_logs );
        return $pmw_logs[$last_key];
    }

    /**
     * Get the link to the most recent log file,
     * for the given slug. The slug must be exactly
     * the same as the source parameter in the log call.
     *
     * @param $source
     *
     * @return string|null
     *
     * @since 1.36.0
     */
    public static function get_admin_url_link_to_recent_wc_log( $source ) {
        $file_name = self::get_file_name_of_most_recent_wc_log( $source );
        if ( empty( $file_name ) ) {
            return null;
        }
        return admin_url( 'admin.php?page=wc-status&tab=logs&log_file=' . $file_name );
    }

    public static function get_external_url_to_most_recent_log( $source ) {
        $file_name = self::get_file_name_of_most_recent_wc_log( $source );
        if ( empty( $file_name ) ) {
            return null;
        }
        return self::get_url_to_log_dir() . $file_name;
    }

    private static function get_url_to_log_dir() {
        // return if WC_LOG_DIR is not defined
        if ( !defined( 'WC_LOG_DIR' ) ) {
            return '';
        }
        $wc_log_dir = substr( trailingslashit( WC_LOG_DIR ), strpos( trailingslashit( WC_LOG_DIR ), '/wp-content/' ) );
        $wc_log_dir = get_bloginfo( 'url' ) . $wc_log_dir;
        return trailingslashit( $wc_log_dir );
    }

    /**
     * Gets all external log file URLs based on a specific source.
     *
     * This function searches all log files in the WooCommerce logs directory,
     * selects those that start with the specified source or 'fatal-errors',
     * and returns their URL.
     *
     * @param string $source The source log files to search for.
     *
     * @return string A JSON-encoded array of URLs to the log files.
     *
     * @since 1.36.0
     */
    public static function get_all_external_log_file_urls( $source ) {
        $needles = ['fatal-errors', $source];
        $logs = WC_Log_Handler_File::get_log_files();
        $logs = array_filter( $logs, function ( $key ) use($needles) {
            foreach ( $needles as $needle ) {
                if ( strpos( $key, $needle . '-' ) === 0 ) {
                    return true;
                }
            }
            return false;
        }, ARRAY_FILTER_USE_KEY );
        $logs = array_values( array_map( function ( $log ) {
            return self::get_url_to_log_dir() . $log;
        }, $logs ) );
        if ( empty( $logs ) ) {
            return null;
        }
        return wp_json_encode( $logs );
    }

    /**
     * Checks if a scheduled action exists in Action Scheduler.
     *
     * This function checks if a scheduled action with a specific hook, arguments, and group exists in Action Scheduler.
     * If the function 'as_has_scheduled_action' exists, it uses it to check for the scheduled action.
     * If 'as_has_scheduled_action' does not exist, it uses 'as_next_scheduled_action' to check for the scheduled action.
     * This is necessary as installs that use plugins with versions of Action Scheduler older than 3.2.1 don't reliably load
     * the latest version of Action Scheduler which contains the 'as_has_scheduled_action' function.
     *
     * Read: https://developer.woo.com/2021/10/12/best-practices-for-deconflicting-different-versions-of-action-scheduler/
     *
     * @param string $hook             The hook of the scheduled action to check for.
     * @param array  $args             Optional. The arguments of the scheduled action to check for. Default is an empty array.
     * @param string $group            Optional. The group of the scheduled action to check for. Default is an empty string.
     * @param string $partial_matching Optional. Whether to perform partial matching on the arguments or not. Default is 'off'. Can be 'like' or 'json'.
     *
     * @return bool Returns true if a scheduled action with the specified hook, arguments, and group exists, otherwise returns false.
     */
    public static function pmw_as_has_scheduled_action(
        $hook,
        $args = [],
        $group = '',
        $partial_matching = 'off'
    ) {
        // If $partial_matching is true, set it to 'like'
        if ( $partial_matching ) {
            $partial_matching = 'like';
        }
        return (bool) as_get_scheduled_actions( [
            'hook'                  => $hook,
            'args'                  => $args,
            'group'                 => $group,
            'partial_args_matching' => $partial_matching,
            'status'                => self::get_action_scheduler_active_states(),
            'per_page'              => 1,
            'orderby'               => 'none',
        ], 'ids' );
    }

    /**
     * This function returns the active states for the Action Scheduler.
     *
     * It checks if the class 'ActionScheduler_Store' exists. If it does, it returns an array with the constants
     * 'STATUS_PENDING' and 'STATUS_RUNNING' from the 'ActionScheduler_Store' class.
     *
     * If the 'ActionScheduler_Store' class does not exist, it returns an array with the strings 'Pending' and 'In-progress'.
     *
     * @return array An array containing the active states for the Action Scheduler.
     *
     * @since 1.37.1
     */
    private static function get_action_scheduler_active_states() {
        if ( class_exists( 'ActionScheduler_Store' ) ) {
            return [ActionScheduler_Store::STATUS_PENDING, ActionScheduler_Store::STATUS_RUNNING];
        }
        return ['Pending', 'In-progress'];
    }

    public static function can_order_modal_be_shown() {
        return Options::is_ga4_data_api_active() || Options::is_order_level_ltv_calculation_active();
    }

    public static function get_script_string_allowed_html() {
        return [];
    }

    /**
     * Generates an opening script string with its associated attributes.
     * The attributes may include those from active plugins like Iubenda or Cookiebot
     * and can be filtered using the 'pmw_opening_script_string_attributes' hook.
     *
     * @return string The formatted opening script string with attributes.
     */
    public static function get_opening_script_string() {
        $script_string = '';
        $attributes = [];
        // if the Iubenda plugin is active, add the Iubenda attributes
        if ( Environment::is_iubenda_active() ) {
            // add an attribute class and add a class name _iub_cs_skip to it
            $attributes['class'][] = '_iub_cs_skip';
        }
        if ( Environment::is_cookiebot_active() ) {
            $attributes['data-cookieconsent'] = ['ignore'];
        }
        // Elementor burger menus are not working with Cloudflare's Rocket Loader
        // source: https://secure.helpscout.net/conversation/2793909416/3974/
        if ( Environment::is_elementor_active() && Environment::is_server_behind_cloudflare() ) {
            $attributes['data-cfasync'] = ['false'];
        }
        $attributes = apply_filters( 'pmw_opening_script_string_attributes', $attributes );
        // Build the attribute string
        foreach ( $attributes as $attribute => $values ) {
            $script_string .= ' ' . $attribute . '="' . implode( ' ', $values ) . '"';
        }
        return $script_string;
    }

    public static function iubenda_script_exception_start() {
        if ( !Environment::is_iubenda_active() ) {
            return;
        }
        ?>

		<!--IUB-COOKIE-BLOCK-SKIP-START-->
		<?php 
    }

    public static function iubenda_script_exception_end() {
        if ( !Environment::is_iubenda_active() ) {
            return;
        }
        ?>

		<!--IUB-COOKIE-BLOCK-SKIP-END-->
		<?php 
    }

    public static function make_full_url( $url ) {
        // Trim any leading or trailing whitespace
        $url = trim( $url );
        // Check if the URL already contains a protocol
        if ( preg_match( '/^(http:\\/\\/|https:\\/\\/)/', $url ) ) {
            return $url;
        }
        // Remove leading slashes
        $url = ltrim( $url, '/' );
        // Add 'https://' as default protocol
        return 'https://' . $url;
    }

    /**
     * Get the current screen object.
     *
     * The reason for this function is that the get_current_screen() function is not available in all contexts,
     * and the properties of the current screen object are not always available.
     * This function makes sure that accessing the properties of the current screen object is safe.
     *
     * @param string $screen The screen property to return.
     * @return string
     *
     * @since 1.43.5
     */
    public static function pmw_current_screen( $screen ) {
        if ( !function_exists( 'get_current_screen' ) ) {
            return '';
        }
        $current_screen = get_current_screen();
        if ( !$current_screen ) {
            return '';
        }
        if ( !property_exists( $current_screen, $screen ) ) {
            return '';
        }
        return $current_screen->{$screen};
    }

    public static function is_url_accessible( $url ) {
        $response = wp_remote_get( $url );
        if ( is_wp_error( $response ) ) {
            return false;
        }
        $response_code = wp_remote_retrieve_response_code( $response );
        if ( 200 !== $response_code ) {
            return false;
        }
        return true;
    }

    public static function get_pmw_distro() {
        return PMW_DISTRO;
    }

    public static function is_pmw_wcm_distro() {
        return 'wcm' === PMW_DISTRO;
    }

    public static function is_pmw_pro_version_active() {
        if ( function_exists( 'wpm_fs' ) ) {
            return wpm_fs()->can_use_premium_code__premium_only();
        }
        if ( 'woocommerce-pixel-manager/woocommerce-pixel-manager.php' === PMW_PLUGIN_BASENAME ) {
            return true;
        }
        return false;
    }

    public static function is_pmw_pro_version_not_active() {
        return !self::is_pmw_pro_version_active();
    }

    public static function is_wcm_distro_free_version() {
        return self::is_pmw_wcm_distro() && self::is_pmw_pro_version_not_active();
    }

}
