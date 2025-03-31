<?php

namespace SweetCode\Pixel_Manager\Pixels;

use SweetCode\Pixel_Manager\Admin\Environment;
use SweetCode\Pixel_Manager\Admin\LTV;
use SweetCode\Pixel_Manager\Admin\Validations;
use SweetCode\Pixel_Manager\Data\GA4_Data_API;
use SweetCode\Pixel_Manager\Pixels\Facebook\Facebook_CAPI;
use SweetCode\Pixel_Manager\Pixels\Facebook\Facebook_Microdata;
use SweetCode\Pixel_Manager\Pixels\Google\Google_MP_GA4;
use SweetCode\Pixel_Manager\Pixels\Google\Google_Helpers;
use SweetCode\Pixel_Manager\Geolocation;
use SweetCode\Pixel_Manager\Helpers;
use SweetCode\Pixel_Manager\Logger;
use SweetCode\Pixel_Manager\Options;
use SweetCode\Pixel_Manager\Product;
use SweetCode\Pixel_Manager\Shop;
defined( 'ABSPATH' ) || exit;
// Exit if accessed directly
class Pixel_Manager {
    private $rest_namespace = 'pmw/v1';

    private $gads_conversion_adjustments_route = '/google-ads/conversion-adjustments.csv';

    private static $instance;

    public static function get_instance() {
        if ( is_null( self::$instance ) ) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    /**
     * Initializes the class and sets up options, states, additional classes, and pixel managers. Also registers actions and filters.
     */
    private function __construct() {
        /**
         * Inject optimization scripts
         */
        add_action( 'wp_head', function () {
            if ( Options::is_ab_tasty_active() ) {
                AB_Tasty::inject_script();
            }
        }, 1 );
        add_action( 'wp_enqueue_scripts', function () {
            if ( Options::is_optimizely_active() ) {
                Optimizely::enqueue_scripts();
            }
        }, 10 );
        /**
         * Inject PMW snippets in head
         */
        // Prepare Litespeed ESI injection
        if ( Environment::is_litespeed_esi_active() ) {
            add_action( 'litespeed_esi_load-pmw_data_layer', [$this, 'inject_data_layer_through_litespeed_esi'] );
        }
        add_action( 'wp_head', function () {
            self::inject_pmw_opening();
            // Inject Meta domain verification ID
            if ( wpm_fs()->can_use_premium_code__premium_only() && Options::get_facebook_domain_verification_id() ) {
                ?>
				<meta name="facebook-domain-verification"
					  content="<?php 
                echo esc_attr( Options::get_facebook_domain_verification_id() );
                ?>"/>
				<?php 
            }
            if ( wpm_fs()->can_use_premium_code__premium_only() && Environment::is_woocommerce_active() && is_product() ) {
                if ( Options::is_facebook_microdata_active() ) {
                    Facebook_Microdata::inject_schema( wc_get_product( get_the_ID() ) );
                }
            }
            // Add products to data layer from page transient
            if ( get_transient( 'pmw_products_for_datalayer_' . get_the_ID() ) ) {
                $products = get_transient( 'pmw_products_for_datalayer_' . get_the_ID() );
                $this->inject_products_from_transient_into_datalayer( $products );
            }
            // If user is logged in then run the following code
            // https://docs.litespeedtech.com/lscache/lscwp/api/#esi
            if ( is_user_logged_in() && Environment::is_litespeed_esi_active() ) {
                $this->inject_data_layer_litespeed_esi();
            } else {
                $this->inject_data_layer();
            }
        } );
        add_action( 'wp_head', function () {
            $this->inject_pmw_closing();
        } );
        /**
         * Front-end script section
         */
        if ( Shop::track_user() ) {
            add_action( 'wp_enqueue_scripts', [$this, 'front_end_scripts'] );
        }
        /**
         * Enqueue CSS for Elementor Pro
         *
         * This fixes an issue where Elementor widgets would show the PMW scripts as visible outputs.
         */
        if ( Environment::is_elementor_pro_active() ) {
            add_action( 'wp_enqueue_scripts', [$this, 'front_end_styles_elementor_fix'] );
        }
        add_action( 'wp_ajax_pmw_get_cart_items', [$this, 'ajax_pmw_get_cart_items'] );
        add_action( 'wp_ajax_nopriv_pmw_get_cart_items', [$this, 'ajax_pmw_get_cart_items'] );
        add_action( 'wp_ajax_pmw_get_product_ids', [$this, 'ajax_pmw_get_product_ids'] );
        add_action( 'wp_ajax_nopriv_pmw_get_product_ids', [$this, 'ajax_pmw_get_product_ids'] );
        add_action( 'wp_ajax_pmw_purchase_pixels_fired', [$this, 'ajax_purchase_pixels_fired_handler'] );
        add_action( 'wp_ajax_nopriv_pmw_purchase_pixels_fired', [$this, 'ajax_purchase_pixels_fired_handler'] );
        // Experimental filter ! Can be removed without further notification
        if ( $this->experimental_defer_scripts_activation() ) {
            add_filter(
                'script_loader_tag',
                [$this, 'experimental_defer_scripts'],
                10,
                2
            );
        }
        /**
         * Inject pixel snippets after <body> tag
         */
        if ( did_action( 'wp_body_open' ) ) {
            add_action( 'wp_body_open', function () {
                self::inject_body_pixels();
            } );
        }
        /**
         * Inject pixel snippets into wp_footer
         */
        add_action( 'wp_footer', [$this, 'pmw_wp_footer'] );
        /**
         * Process short codes
         */
        Shortcodes::init();
        if ( Environment::is_woocommerce_active() ) {
            add_action(
                'woocommerce_after_shop_loop_item',
                [$this, 'action_woocommerce_after_shop_loop_item'],
                10,
                1
            );
            add_filter(
                'woocommerce_blocks_product_grid_item_html',
                [$this, 'wc_add_data_to_gutenberg_block'],
                10,
                3
            );
            add_action( 'wp_head', [$this, 'woocommerce_inject_product_data_on_product_page'] );
            // do_action( 'woocommerce_after_cart_item_name', $cart_item, $cart_item_key );
            add_action(
                'woocommerce_after_cart_item_name',
                [$this, 'woocommerce_after_cart_item_name'],
                10,
                2
            );
            add_action(
                'woocommerce_after_mini_cart_item_name',
                [$this, 'woocommerce_after_cart_item_name'],
                10,
                2
            );
            add_action( 'woocommerce_mini_cart_contents', [$this, 'woocommerce_mini_cart_contents'] );
            add_action( 'woocommerce_new_order', [$this, 'pmw_woocommerce_new_order'] );
        }
        /**
         * Run background processes
         */
        add_action( 'template_redirect', [$this, 'run_background_processes'] );
        add_action( 'rest_api_init', [$this, 'register_rest_routes'] );
        // When updating a page, delete any transient data set by the Pixel Manager
        add_action(
            'save_post',
            [$this, 'delete_pmw_products_transient'],
            10,
            3
        );
    }

    private function experimental_defer_scripts_activation() {
        $defer_scripts = apply_filters_deprecated(
            'wpm_experimental_defer_scripts',
            [false],
            '1.31.2',
            'pmw_experimental_defer_scripts'
        );
        return apply_filters( 'pmw_experimental_defer_scripts', $defer_scripts );
    }

    public function delete_pmw_products_transient( $post_id, $post, $update ) {
        if ( $update ) {
            delete_transient( 'pmw_products_for_datalayer_' . $post_id );
        }
    }

    public function inject_products_from_transient_into_datalayer( $products ) {
        ?>
		<script<?php 
        echo wp_kses( Helpers::get_opening_script_string(), Helpers::get_script_string_allowed_html() );
        ?>>
			(window.wpmDataLayer = window.wpmDataLayer || {}).products = window.wpmDataLayer.products || {};
			window.wpmDataLayer.products                               = Object.assign(window.wpmDataLayer.products, <?php 
        echo wp_json_encode( (object) $products );
        ?>);
		</script>
		<?php 
    }

    public function get_google_ads_conversion_adjustments_endpoint() {
        /**
         * The regular /wp-json/ endpoint doesn't work if pretty permalinks are disabled.
         * https://developer.wordpress.org/rest-api/key-concepts/#routes-endpoints
         */
        return '/?rest_route=/' . $this->rest_namespace . $this->gads_conversion_adjustments_route;
    }

    /**
     * Prepares custom REST API handlers for specific routes.
     *
     * This function is used to short-circuit the default REST API behavior for specific routes.
     * In this case, it is used to output a CSV file for the Google Ads conversion adjustments.
     *
     * Input: https://wordpress.stackexchange.com/a/377954/68337
     *
     * @param bool|array       $served  The served result, used to short-circuit the default REST API behavior.
     * @param WP_REST_Response $result  The original result to be served by the REST endpoint.
     * @param WP_REST_Request  $request The current request instance containing request data.
     * @param WP_REST_Server   $server  The REST server instance for serving responses and managing headers.
     * @return bool|array  Returns the original $served result if the route doesn't match. Otherwise, processes the request and exits.
     */
    public function prepare_custom_rest_handlers(
        $served,
        $result,
        $request,
        $server
    ) {
        // If $request->get_route() is not /pmw/v1/google-ads/conversion-adjustments then return $served
        if ( strpos( $request->get_route(), $this->rest_namespace . $this->gads_conversion_adjustments_route ) === false ) {
            return $served;
        }
        // If $result->get_data() is an array, return $served
        // This is to prevent buggy behavior created by some third-party plugins
        // https://secure.helpscout.net/conversation/2827410362/4097/
        if ( is_array( $result->get_data() ) ) {
            return $served;
        }
        // For production
        $server->send_header( 'Content-Type', 'text/csv' );
        // For testing
        //		$server->send_header('Content-Type', 'text/html');
        //		$server->send_header('Content-Type', 'text/xml');
        //		$server->send_header('Content-Type', 'application/xml');
        // Echo the XML that's returned by smg_feed().
        esc_html_e( $result->get_data() );
        exit;
    }

    // Extracted the code because the QIT semgrep rule was triggered
    public function can_current_user_edit_options() {
        return Environment::can_current_user_edit_options();
    }

    public function register_rest_routes() {
        /**
         * Testing endpoint which helps to verify if the REST API is working
         */
        // nosemgrep: audit.php.wp.security.rest-route.permission-callback.return-true
        register_rest_route( $this->rest_namespace, '/test/', [
            'methods'             => 'POST',
            'callback'            => function () {
                wp_send_json_success();
            },
            'permission_callback' => function () {
                return true;
            },
        ] );
        /**
         * Testing endpoint which helps to verify if the REST API is working
         */
        // nosemgrep: audit.php.wp.security.rest-route.permission-callback.return-true
        register_rest_route( $this->rest_namespace, '/test/', [
            'methods'             => 'GET',
            'callback'            => function () {
                wp_send_json_success();
            },
            'permission_callback' => function () {
                return true;
            },
        ] );
        register_rest_route( $this->rest_namespace, '/settings/', [
            'methods'             => 'POST',
            'callback'            => [$this, 'pmw_save_imported_settings'],
            'permission_callback' => [$this, 'can_current_user_edit_options'],
        ] );
        /**
         * No nonce verification required as we only request public data
         * from the server.
         */
        // nosemgrep: audit.php.wp.security.rest-route.permission-callback.return-true
        register_rest_route( $this->rest_namespace, '/products/', [
            'methods'             => 'POST',
            'callback'            => function ( $request ) {
                $request_decoded = $request->get_json_params();
                $this->get_products_for_datalayer( $request_decoded );
            },
            'permission_callback' => function () {
                return true;
            },
        ] );
        // nosemgrep: audit.php.wp.security.rest-route.permission-callback.return-true
        register_rest_route( $this->rest_namespace, '/pixels-fired/', [
            'methods'             => 'POST',
            'callback'            => function ( $request ) {
                $data = $request->get_json_params();
                // TODO: Maybe remove the nonce verification. 1) Some merchants even cache parts of the purchase confirmation page, which lets the nonce fail. 2) Nonce checks in this endpoint are not really necessary as we CAN check for a valid order_key which is only known to the customer who purchased a specific order.
                //				if (!wp_verify_nonce($request->get_header('X-WP-Nonce'), 'wp_rest')) {
                //					wp_send_json_error('Invalid nonce');
                //				}
                $data = Helpers::generic_sanitization( $data );
                self::process_conversion_pixel_status( $data['order_id'], $data['order_key'], $data['source'] );
            },
            'permission_callback' => function () {
                return true;
            },
        ] );
    }

    private function get_products_for_datalayer( $data ) {
        $product_ids = Helpers::generic_sanitization( $data['product_ids'] );
        if ( !$product_ids ) {
            wp_send_json_error( 'No product IDs provided.' );
        }
        if ( !is_array( $product_ids ) ) {
            wp_send_json_error( 'Product IDs must be an array.' );
        }
        // if $data['page_id'] is not set, return error
        if ( !isset( $data['page_id'] ) ) {
            wp_send_json_error( 'No page ID provided' );
        }
        // if $data['page_type'] is not set, return error
        if ( !isset( $data['page_type'] ) ) {
            wp_send_json_error( 'No page type provided' );
        }
        // Prevent server overload if too many products are requested
        $product_ids = ( count( $product_ids ) > 50 ? array_slice( $product_ids, 0, 50 ) : $product_ids );
        $products = $this->get_products_for_datalayer_by_product_ids( $product_ids );
        // Check if a data layer products transient for this page exists
        // If it does, add the products from the transient to $products
        if ( get_transient( 'pmw_products_for_datalayer_' . $data['page_id'] ) ) {
            $products_in_transient = get_transient( 'pmw_products_for_datalayer_' . $data['page_id'] );
            // Merge the associative arrays with nested arrays $products and $products_in_transient preserving the keys
            $products = array_replace_recursive( $products, $products_in_transient );
        }
        // Set transient with products for $data['page_id']
        if ( 'cart' !== $data['page_type'] && 'checkout' !== $data['page_type'] && 'order_received_page' !== $data['page_type'] ) {
            set_transient( 'pmw_products_for_datalayer_' . $data['page_id'], $products, MONTH_IN_SECONDS );
        }
        wp_send_json_success( $products );
    }

    private function get_order_value_after_refunds( $order ) {
        $refunds = $order->get_refunds();
        $refunded_amount = 0;
        foreach ( $refunds as $refund ) {
            $refunded_amount -= $refund->get_total();
        }
        $order_total = $order->get_total();
        $adjusted_value = $order_total - $refunded_amount;
        // Calculate the new order value considering the order total logic that has been applied by the user
        $adjusted_value_percentage = $adjusted_value / $order_total;
        $adjusted_value = Shop::get_order_value_total_marketing( $order, true ) * $adjusted_value_percentage;
        return Helpers::format_decimal( $adjusted_value, 2 );
    }

    private function get_order_details_for_acr( $data ) {
        // If order ID or order key is not provided, return error
        if ( !isset( $data['order_id'] ) || !isset( $data['order_key'] ) ) {
            wp_send_json_error( 'No order ID or order key provided' );
        }
        $data = Helpers::generic_sanitization( $data );
        $order_id = $data['order_id'];
        $order_key = $data['order_key'];
        $order = wc_get_order( $order_id );
        // if order is not found, return error
        if ( !$order ) {
            wp_send_json_error( 'Order not found' );
        }
        // If order key doesn't match, return error
        if ( $order->get_order_key() !== $order_key ) {
            wp_send_json_error( 'Order key does not match' );
        }
        if ( !$this->is_order_eligible_for_acr( $order ) ) {
            wp_send_json_error( 'Order is not eligible for ACR' );
        }
        // Return the order details for the wpmDataLayer with the provided ID
        wp_send_json_success( $this->get_order_data( $order ) );
    }

    private function is_order_eligible_for_acr( $order ) {
        /**
         * If the order is not in a paid state, return false.
         *
         * It seems to be better to check for paid statuses instead of not-paid statuses,
         * as there are shops that may add unpaid statuses to the status list. Those statuses
         * then can trigger the ACR, which they shouldn't. Using wc_get_is_paid_statuses()
         * will make sure that only paid statuses are checked, even additional paid statuses
         * added through the filter in wc_get_is_paid_statuses().
         *
         * https://stackoverflow.com/a/59869889
         */
        if ( !in_array( $order->get_status(), wc_get_is_paid_statuses() ) ) {
            return false;
        }
        // If order has already fired the conversion pixel, return false
        if ( Shop::has_conversion_pixel_already_fired( $order ) ) {
            return false;
        }
        if ( !Shop::can_order_confirmation_be_processed( $order ) ) {
            return false;
        }
        return true;
    }

    public static function pmw_store_ipv6_in_server_session() {
        $_post = Helpers::get_input_vars( INPUT_POST );
        // return error if the ipv6 field is not set
        if ( !isset( $_post['data']['ipv6'] ) ) {
            wp_send_json_error( 'No IPv6 address provided' );
        }
        // return error if the ipv6 field is not a valid IPv6 address
        if ( !Helpers::is_valid_ipv6_address( $_post['data']['ipv6'] ) ) {
            wp_send_json_error( 'Invalid IPv6 address' );
        }
        // If WooCommerce is not active, return error
        if ( !Environment::is_woocommerce_active() ) {
            wp_send_json_error( 'WooCommerce not active' );
        }
        // If WC() is not available, return error
        if ( !function_exists( 'WC' ) ) {
            wp_send_json_error( 'WC() not available' );
        }
        // If a WooCommerce session is not available, return error
        if ( !WC()->session ) {
            wp_send_json_error( 'WooCommerce session not available' );
        }
        // Set the IPv6 address in the WooCommerce session
        WC()->session->set( 'client_ipv6', $_post['data']['ipv6'] );
        wp_send_json_success( [
            'ipv6'    => $_post['data']['ipv6'],
            'message' => 'IPv6 address stored in server session',
        ] );
    }

    public function pmw_save_imported_settings( $request ) {
        // Verify nonce
        if ( !wp_verify_nonce( $request->get_header( 'X-WP-Nonce' ), 'wp_rest' ) ) {
            wp_send_json_error( 'Invalid nonce' );
        }
        $options = $request->get_params();
        // Sanitize nested array $options
        $options = Helpers::generic_sanitization( $options );
        if ( !is_array( $options ) ) {
            wp_send_json_error( 'Invalid options. Not an array.' );
        }
        // Validate imported options
        if ( !Validations::validate_imported_options( $options ) ) {
            wp_send_json_error( [
                'message' => 'Invalid options. Didn\'t pass validation.',
            ] );
        }
        // All good, save options
        update_option( PMW_DB_OPTIONS_NAME, $options );
        wp_send_json_success( [
            'message' => 'Options saved',
        ] );
    }

    public function run_background_processes() {
        if ( wpm_fs()->can_use_premium_code__premium_only() && Environment::is_woocommerce_active() ) {
            if ( is_cart() || is_checkout() ) {
                if ( Options::is_ga4_mp_active() ) {
                    Google_MP_GA4::set_identifiers_on_session();
                }
                if ( Options::is_facebook_capi_active() ) {
                    Facebook_CAPI::set_identifiers_on_session();
                }
                if ( Options::is_tiktok_eapi_active() ) {
                    TikTok_EAPI::set_identifiers_on_session();
                }
                if ( Options::is_pinterest_apic_active() ) {
                    Pinterest_APIC::set_identifiers_on_session();
                }
                if ( Options::is_snapchat_capi_active() ) {
                    Snapchat_CAPI::set_identifiers_on_session();
                }
            }
            // TODO: That function should probably not go into the Google_MP_GA4 class
            if ( Shop::pmw_is_order_received_page() ) {
                if ( Shop::pmw_get_current_order() ) {
                    Google_Helpers::save_gclid_in_order__premium_only( Shop::pmw_get_current_order() );
                }
            }
        }
    }

    public function pmw_woocommerce_new_order( $order_id ) {
        $order = wc_get_order( $order_id );
        /**
         * All new orders should be marked as long PMW is active,
         * so that we know we can process them later through PMW,
         * and so that we know we should not touch orders that were
         * placed before PMW was active.
         */
        $order->add_meta_data( '_wpm_process_through_wpm', true, true );
        /**
         * Set a custom user ID on the order
         * because WC sets 0 on all order created
         * manually through the back-end.
         */
        $user_id = 0;
        if ( is_user_logged_in() ) {
            $user_id = get_current_user_id();
        }
        $order->add_meta_data( '_wpm_customer_user', $user_id, true );
        $order->save();
    }

    // Thanks to: https://gist.github.com/mishterk/6b7a4d6e5a91086a5a9b05ace304b5ce#file-mark-wordpress-scripts-as-async-or-defer-php
    public function experimental_defer_scripts( $tag, $handle ) {
        if ( 'wpm' !== $handle ) {
            return $tag;
        }
        return str_replace( ' src', ' defer src', $tag );
        // defer the script
    }

    public function woocommerce_mini_cart_contents() {
        foreach ( WC()->cart->get_cart() as $cart_item_key => $cart_item ) {
            $this->woocommerce_after_cart_item_name( $cart_item, $cart_item_key );
        }
    }

    public function woocommerce_after_cart_item_name( $cart_item, $cart_item_key ) {
        $data = [
            'product_id'   => $cart_item['product_id'],
            'variation_id' => $cart_item['variation_id'],
        ];
        $json_encode_options = JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE;
        // add JSON_PRETTY_PRINT
        //		$json_encode_options = $json_encode_options | JSON_PRETTY_PRINT;
        ?>
		<script>
			window.wpmDataLayer.cart_item_keys                                          = window.wpmDataLayer.cart_item_keys || {};
			window.wpmDataLayer.cart_item_keys['<?php 
        echo esc_js( $cart_item_key );
        ?>'] = <?php 
        echo wp_json_encode( $data, $json_encode_options );
        ?>;
		</script>

		<?php 
    }

    // on the product page
    public function woocommerce_inject_product_data_on_product_page() {
        if ( !is_product() ) {
            return;
        }
        $product = wc_get_product( get_the_id() );
        if ( Product::is_not_wc_product( $product ) ) {
            Logger::debug( 'woocommerce_inject_product_data_on_product_page provided no product on a product page: .' . get_the_id() );
            return;
        }
        Product::print_product_data_layer_script( $product, false, true );
        if ( $product->is_type( 'grouped' ) ) {
            foreach ( $product->get_children() as $product_id ) {
                $product = wc_get_product( $product_id );
                if ( Product::is_not_wc_product( $product ) ) {
                    Product::log_problematic_product_id( $product_id );
                    continue;
                }
                Product::print_product_data_layer_script( $product, false, true );
            }
        } elseif ( $product->is_type( 'variable' ) ) {
            /**
             * Stop inspection
             *
             * @noinspection PhpPossiblePolymorphicInvocationInspection
             */
            // Prevent processing of large amounts of variations
            // because get_available_variations() is very slow
            if ( 64 <= count( $product->get_children() ) ) {
                return;
            }
            foreach ( $product->get_available_variations() as $key => $variation ) {
                $variable_product = wc_get_product( $variation['variation_id'] );
                if ( !is_object( $variable_product ) ) {
                    Product::log_problematic_product_id( $variation['variation_id'] );
                    continue;
                }
                Product::print_product_data_layer_script( $variable_product, false, true );
            }
        }
    }

    // every product that's generated by the shop loop like shop page or a shortcode
    public function action_woocommerce_after_shop_loop_item() {
        global $product;
        Product::print_product_data_layer_script( $product );
    }

    /**
     * Product views generated by a Gutenberg block (instead of a shortcode)
     *
     * @param $html
     * @param $data
     * @param $product
     * @return mixed|string
     */
    public function wc_add_data_to_gutenberg_block( $html, $data, $product ) {
        // If the cart is empty, early return the html and don't add the additional product data layer.
        // This is to avoid a render blocking issue reported here: https://wordpress.org/support/topic/wc-8-4-empty-cart-error/
        // Also we need safeguards to avoid a bug reported here: https://wordpress.org/support/topic/fatal-error-4590/
        // IMO WooCommerce should not process the woocommerce_blocks_product_grid_item_html hook during a REST API request.
        if ( !is_object( WC()->cart ) ) {
            return $html;
        }
        if ( method_exists( WC()->cart, 'is_empty' ) && WC()->cart->is_empty() ) {
            return $html;
        }
        // The new cart block in WC 9.x errors out when emptying the cart
        if ( has_block( 'woocommerce/cart' ) ) {
            return $html;
        }
        return $html . Product::ob_print_get_product_data_layer_script( $product );
    }

    public function pmw_wp_footer() {
        // WP footer scripts
    }

    public function inject_data_layer_through_litespeed_esi() {
        do_action( 'litespeed_control_set_nocache', 'nocache for Pixel the Pixel Manager' );
        $this->inject_data_layer();
    }

    /**
     * Output the uncached data layer through ESI.
     *
     * TODO: Once the wp_kses filter is updated, refactor the below code.
     * TODO: Remove the wcm part and replace echo with echo wp_kses(...).
     * TODO: The wp_kses output will have to go through a WP version check to
     * TODO: make sure it's only used on WP installs with the updated wp_kses filter.
     * TODO: https://core.trac.wordpress.org/ticket/58921
     * TODO: Update the sweetcode.com docs after the refactor.
     *
     * @return void
     * @since 1.32.6
     **/
    private function inject_data_layer_litespeed_esi() {
        if ( 'wcm' === PMW_DISTRO ) {
            do_action( 'litespeed_control_set_nocache', 'logged in user: disable cache' );
            $this->inject_data_layer();
            return;
        }
        // phpcs:disable WordPress.Security.EscapeOutput.OutputNotEscaped
        echo apply_filters( 'litespeed_esi_url', 'pmw_data_layer', 'Inject data layer through ESI block' );
        // phpcs:enable WordPress.Security.EscapeOutput.OutputNotEscaped
    }

    /**
     * Injects the data layer into the page.
     *
     * Source: https://support.cloudflare.com/hc/en-us/articles/200169436-How-can-I-have-Rocket-Loader-ignore-specific-JavaScripts-
     *
     * @return void
     */
    private function inject_data_layer() {
        $json_encode_options = JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE;
        // add JSON_PRETTY_PRINT
        //		$json_encode_options = $json_encode_options | JSON_PRETTY_PRINT;
        // We add a script string with additional attributes to the script tag.
        // Those attributes are primarily to exclude the script from being processed
        // by various script loaders and script blockers.
        // Some exclusion techniques use HTML comments, but those comments may be stripped by HTML minifiers.
        ?>

		<script<?php 
        echo wp_kses( Helpers::get_opening_script_string(), Helpers::get_script_string_allowed_html() );
        ?>>

			window.wpmDataLayer = window.wpmDataLayer || {};
			window.wpmDataLayer = Object.assign(window.wpmDataLayer, <?php 
        echo wp_json_encode( $this->get_data_for_data_layer(), $json_encode_options );
        ?>);

		</script>

		<?php 
    }

    /**
     * Set up the wpmDataLayer
     *
     * @return mixed|void
     */
    private function get_data_for_data_layer() {
        /**
         * Load and set some defaults.
         */
        $data = [
            'cart'           => (object) [],
            'cart_item_keys' => (object) [],
            'version'        => Helpers::get_version_info(),
        ];
        /**
         * Load the pixels
         */
        $data['pixels'] = $this->get_pixel_data();
        /**
         * Load remaining settings
         */
        if ( Environment::is_woocommerce_active() ) {
            $data = $this->add_order_data( $data );
            //			$data         = array_merge($data, $this->get_order_data());
            $data['shop'] = $this->get_shop_data();
        }
        $data['page'] = self::get_page_data();
        $data['general'] = $this->get_general_data();
        /**
         * Load the experiment settings
         */
        //		$data['experiments'] = [
        //				'ga4_server_and_browser_tracking' => apply_filters('experimental_pmw_ga4_server_and_browser_tracking', false),
        //		];
        $data = apply_filters_deprecated(
            'wpm_experimental_data_layer',
            [$data],
            '1.31.2',
            'pmw_experimental_data_layer'
        );
        // Return and optionally modify the pmw data layer
        return apply_filters( 'pmw_experimental_data_layer', $data );
    }

    private function get_pixel_data() {
        $data = [];
        if ( Options::is_google_active() ) {
            $data['google'] = $this->get_google_pixel_data();
        }
        if ( Options::is_adroll_active() ) {
            $data['adroll'] = $this->get_adroll_pixel_data();
        }
        if ( Options::is_linkedin_active() ) {
            $data['linkedin'] = $this->get_linkedin_pixel_data();
        }
        if ( Options::is_bing_active() ) {
            $data['bing'] = self::get_bing_pixel_data();
        }
        if ( Options::is_facebook_active() ) {
            $data['facebook'] = $this->get_facebook_pixel_data();
        }
        if ( Options::is_hotjar_enabled() ) {
            $data['hotjar'] = $this->get_hotjar_pixel_data();
        }
        if ( Options::is_reddit_active() ) {
            $data['reddit'] = $this->get_reddit_pixel_data();
        }
        if ( Options::is_outbrain_active() ) {
            $data['outbrain'] = self::get_outbrain_pixel_data();
        }
        if ( Options::is_pinterest_active() ) {
            $data['pinterest'] = $this->get_pinterest_pixel_data();
        }
        if ( Options::is_snapchat_active() ) {
            $data['snapchat'] = $this->get_snapchat_pixel_data();
        }
        if ( Options::is_taboola_active() ) {
            $data['taboola'] = self::get_taboola_pixel_data();
        }
        if ( Options::is_tiktok_active() ) {
            $data['tiktok'] = $this->get_tiktok_pixel_data();
        }
        if ( Options::is_twitter_active() ) {
            $data['twitter'] = $this->get_twitter_pixel_data();
        }
        if ( Options::is_vwo_active() ) {
            $data['vwo'] = self::get_vwo_pixel_data();
        }
        return $data;
    }

    private function get_google_pixel_data() {
        $data = [
            'linker'  => [
                'settings' => Google_Helpers::get_google_linker_settings(),
            ],
            'user_id' => Options::is_google_user_id_active(),
        ];
        if ( Options::is_google_ads_active() ) {
            $data['ads'] = [
                'conversion_ids'           => (object) Google_Helpers::get_google_ads_conversion_ids(),
                'dynamic_remarketing'      => [
                    'status'                      => true,
                    'id_type'                     => Product::get_dyn_r_id_type( 'google_ads' ),
                    'send_events_with_parent_ids' => $this->send_events_with_parent_ids(),
                ],
                'google_business_vertical' => Google_Helpers::get_google_business_vertical_name_by_id( Options::get_google_ads_business_vertical_id() ),
                'phone_conversion_number'  => Options::get_google_ads_phone_conversion_number(),
                'phone_conversion_label'   => Options::get_google_ads_phone_conversion_label(),
            ];
        }
        if ( Options::is_google_analytics_active() ) {
            $data['analytics'] = [
                'ga4'     => [
                    'measurement_id'          => Options::get_ga4_measurement_id(),
                    'parameters'              => (object) Google_Helpers::get_ga4_parameters( Options::get_ga4_measurement_id() ),
                    'mp_active'               => Options::is_ga4_mp_active() && wpm_fs()->can_use_premium_code__premium_only(),
                    'debug_mode'              => Google_Helpers::is_ga4_debug_mode_active(),
                    'page_load_time_tracking' => Options::is_ga4_page_load_time_tracking_active(),
                ],
                'id_type' => Google_Helpers::get_ga_id_type(),
            ];
        }
        $data['tracking_id'] = Google_Helpers::get_google_tracking_id();
        $data['tcf_support'] = Options::is_google_tcf_support_active();
        $data['consent_mode'] = [
            'is_active'          => Options::is_google_consent_mode_active(),
            'wait_for_update'    => 500,
            'ads_data_redaction' => Google_Helpers::get_google_consent_mode_ads_data_redaction_setting(),
            'url_passthrough'    => Google_Helpers::get_google_consent_mode_url_passthrough_setting(),
        ];
        if ( Options::is_google_enhanced_conversions_active() ) {
            $data['enhanced_conversions']['is_active'] = Options::is_google_enhanced_conversions_active();
        }
        return $data;
    }

    private function send_events_with_parent_ids() {
        $events_with_parent_ids = apply_filters_deprecated(
            'wooptpm_send_events_with_parent_ids',
            [true],
            '1.13.0',
            'pmw_send_events_with_parent_ids'
        );
        $events_with_parent_ids = apply_filters_deprecated(
            'wpm_send_events_with_parent_ids',
            [$events_with_parent_ids],
            '1.31.2',
            'pmw_send_events_with_parent_ids'
        );
        return apply_filters( 'pmw_send_events_with_parent_ids', $events_with_parent_ids );
    }

    private function get_adroll_pixel_data() {
        return [
            'advertiser_id'       => Options::get_adroll_advertiser_id(),
            'pixel_id'            => Options::get_adroll_pixel_id(),
            'dynamic_remarketing' => [
                'id_type' => Product::get_dyn_r_id_type( 'adroll' ),
            ],
        ];
    }

    private function get_linkedin_pixel_data() {
        return [
            'partner_id'          => Options::get_linkedin_partner_id(),
            'conversion_ids'      => Options::get_linkedin_conversion_ids(),
            'dynamic_remarketing' => [
                'id_type' => Product::get_dyn_r_id_type( 'linkedin' ),
            ],
        ];
    }

    private static function get_bing_pixel_data() {
        return [
            'uet_tag_id'           => Options::get_bing_uet_tag_id(),
            'enhanced_conversions' => Options::is_bing_enhanced_conversions_enabled(),
            'dynamic_remarketing'  => [
                'id_type' => Product::get_dyn_r_id_type( 'bing' ),
            ],
            'consent_mode'         => [
                'is_active' => Options::is_bing_consent_mode_active(),
            ],
        ];
    }

    private function get_facebook_pixel_data() {
        $data = [
            'pixel_id'            => Options::get_facebook_pixel_id(),
            'dynamic_remarketing' => [
                'id_type' => Product::get_dyn_r_id_type( 'facebook' ),
            ],
            'capi'                => Options::is_facebook_capi_active(),
            'advanced_matching'   => Options::is_facebook_capi_advanced_matching_enabled(),
            'exclusion_patterns'  => apply_filters( 'pmw_facebook_tracking_exclusion_patterns', [] ),
            'fbevents_js_url'     => Helpers::get_facebook_fbevents_js_url(),
        ];
        if ( apply_filters( 'pmw_facebook_mobile_bridge_app_id', null ) ) {
            $data['mobile_bridge_app_id'] = apply_filters( 'pmw_facebook_mobile_bridge_app_id', null );
        }
        return $data;
    }

    private function get_hotjar_pixel_data() {
        return [
            'site_id' => Options::get_hotjar_site_id(),
        ];
    }

    private static function get_outbrain_pixel_data() {
        return [
            'advertiser_id'       => Options::get_outbrain_advertiser_id(),
            'dynamic_remarketing' => [
                'id_type' => Product::get_dyn_r_id_type( 'outbrain' ),
            ],
            'event_names'         => self::get_outbrain_event_name_mapping(),
        ];
    }

    private static function get_outbrain_event_name_mapping() {
        $mapping = [
            'search'         => 'search',
            'view_content'   => 'content_view',
            'add_to_cart'    => 'add_to_cart',
            'start_checkout' => 'checkout',
            'purchase'       => 'purchase',
        ];
        return (array) apply_filters( 'pmw_outbrain_event_name_mapping', $mapping );
    }

    private function get_pinterest_pixel_data() {
        $data = [
            'pixel_id'            => Options::get_pinterest_pixel_id(),
            'dynamic_remarketing' => [
                'id_type' => Product::get_dyn_r_id_type( 'pinterest' ),
            ],
            'advanced_matching'   => Options::is_pinterest_advanced_matching_active(),
        ];
        $enhanced_match = Options::is_pinterest_enhanced_match_enabled();
        $enhanced_match = apply_filters_deprecated(
            'wooptpm_pinterest_enhanced_match',
            [$enhanced_match],
            '1.13.0',
            'wpm_pinterest_enhanced_match'
        );
        $data['enhanced_match'] = apply_filters_deprecated(
            'wpm_pinterest_enhanced_match',
            [$enhanced_match],
            '1.22.0',
            null,
            'There is now an option in the Pinterest settings to enable/disable enhanced match.'
        );
        return $data;
    }

    private function get_reddit_pixel_data() {
        return [
            'advertiser_id'       => Options::get_reddit_advertiser_id(),
            'advanced_matching'   => Options::is_reddit_advanced_matching_enabled(),
            'dynamic_remarketing' => [
                'id_type' => Product::get_dyn_r_id_type( 'reddit' ),
            ],
        ];
    }

    private function get_snapchat_pixel_data() {
        return [
            'pixel_id'            => Options::get_snapchat_pixel_id(),
            'dynamic_remarketing' => [
                'id_type' => Product::get_dyn_r_id_type( 'snapchat' ),
            ],
            'advanced_matching'   => Options::is_snapchat_advanced_matching_enabled(),
        ];
    }

    private static function get_taboola_pixel_data() {
        return [
            'account_id'          => (int) Options::get_taboola_account_id(),
            'dynamic_remarketing' => [
                'id_type' => Product::get_dyn_r_id_type( 'taboola' ),
            ],
            'event_names'         => self::get_taboola_event_name_mapping(),
        ];
    }

    private static function get_taboola_event_name_mapping() {
        $mapping = [
            'search'          => 'search',
            'view_content'    => 'view_content',
            'add_to_wishlist' => 'add_to_wishlist',
            'add_to_cart'     => 'add_to_cart',
            'start_checkout'  => 'start_checkout',
            'purchase'        => 'make_purchase',
        ];
        return (array) apply_filters( 'pmw_taboola_event_name_mapping', $mapping );
    }

    private function get_tiktok_pixel_data() {
        return [
            'pixel_id'            => Options::get_tiktok_pixel_id(),
            'dynamic_remarketing' => [
                'id_type' => Product::get_dyn_r_id_type( 'tiktok' ),
            ],
            'eapi'                => Options::is_tiktok_eapi_active(),
            'advanced_matching'   => Options::is_tiktok_advanced_matching_enabled(),
        ];
    }

    private function get_twitter_pixel_data() {
        return [
            'pixel_id'            => Options::get_twitter_pixel_id(),
            'dynamic_remarketing' => [
                'id_type' => Product::get_dyn_r_id_type( 'twitter' ),
            ],
            'event_ids'           => Options::get_twitter_event_ids(),
        ];
    }

    private static function get_vwo_pixel_data() {
        return [
            'account_id' => Options::get_vwo_account_id(),
        ];
    }

    private function add_order_data( $data ) {
        if ( !Shop::pmw_is_order_received_page() ) {
            return array_merge( $data, [] );
        }
        if ( !Shop::pmw_get_current_order() ) {
            return array_merge( $data, [] );
        }
        if ( !Shop::can_order_confirmation_be_processed( Shop::pmw_get_current_order() ) ) {
            return array_merge( $data, [] );
        }
        return array_merge( $data, $this->get_order_data( Shop::pmw_get_current_order() ) );
    }

    /**
     * Get the shop order data.
     *
     * @param $order
     * @return array
     */
    private function get_order_data( $order ) {
        $data = [];
        if ( $order ) {
            $data['order'] = [
                'id'               => (int) $order->get_id(),
                'number'           => (string) $order->get_order_number(),
                'key'              => (string) $order->get_order_key(),
                'affiliation'      => (string) get_bloginfo( 'name' ),
                'currency'         => (string) Shop::get_order_currency( $order ),
                'value'            => [
                    'marketing' => Shop::get_order_value_total_marketing( $order, true ),
                    'total'     => Shop::get_order_value_total_statistics( $order ),
                    'subtotal'  => Shop::get_order_value_subtotal_statistics( $order ),
                ],
                'discount'         => (float) $order->get_total_discount(),
                'tax'              => (float) $order->get_total_tax(),
                'shipping'         => (float) $order->get_shipping_total(),
                'coupon'           => implode( ',', $order->get_coupon_codes() ),
                'aw_merchant_id'   => ( Options::get_google_ads_merchant_id() ? Options::get_google_ads_merchant_id() : '' ),
                'aw_feed_country'  => (string) Geolocation::get_visitor_country(),
                'aw_feed_language' => Google_Helpers::get_gmc_language(),
                'new_customer'     => Shop::is_new_customer( $order ),
                'quantity'         => (int) count( Product::pmw_get_order_items( $order ) ),
                'items'            => Product::get_front_end_order_items( $order ),
                'customer_id'      => $order->get_customer_id(),
                'user_id'          => $order->get_user_id(),
            ];
            // Filter to add custom order parameters
            $custom_parameters = Shop::get_custom_order_parameters( $order );
            if ( !empty( $custom_parameters ) ) {
                $data['order']['custom_parameters'] = $custom_parameters;
            }
            // Process customer lifetime value
            if ( Shop::can_ltv_be_processed_on_order( $order ) ) {
                if ( !LTV::are_all_pmw_order_values_set( $order ) ) {
                    LTV::calculate_pmw_order_values( $order );
                }
                $data['order']['value']['ltv']['marketing'] = (float) LTV::get_marketing_ltv_from_order( $order );
                $data['order']['value']['ltv']['total'] = (float) LTV::get_total_ltv_from_order( $order );
            }
            // set em (email)
            $data['order']['billing_email'] = trim( strtolower( $order->get_billing_email() ) );
            $data['order']['billing_email_hashed'] = hash( 'sha256', trim( strtolower( $order->get_billing_email() ) ) );
            if ( $order->get_billing_phone() ) {
                $phone = $order->get_billing_phone();
                $phone = Helpers::get_e164_formatted_phone_number( $phone, $order->get_billing_country() );
                $data['order']['billing_phone'] = $phone;
            }
            if ( $order->get_billing_first_name() ) {
                $data['order']['billing_first_name'] = trim( strtolower( $order->get_billing_first_name() ) );
            }
            if ( $order->get_billing_last_name() ) {
                $data['order']['billing_last_name'] = trim( strtolower( $order->get_billing_last_name() ) );
            }
            if ( $order->get_billing_city() ) {
                $data['order']['billing_city'] = str_replace( ' ', '', trim( strtolower( $order->get_billing_city() ) ) );
            }
            if ( $order->get_billing_state() ) {
                $data['order']['billing_state'] = trim( strtolower( $order->get_billing_state() ) );
            }
            if ( $order->get_billing_postcode() ) {
                $data['order']['billing_postcode'] = $order->get_billing_postcode();
            }
            if ( $order->get_billing_country() ) {
                $data['order']['billing_country'] = trim( strtolower( $order->get_billing_country() ) );
            }
            $data['products'] = $this->get_order_products( $order );
        }
        return $data;
    }

    /**
     * Return an array with custom variables for Google Ads.
     *
     * @param $order
     * @return array
     *
     * @since 1.43.5
     */
    private function get_google_ads_custom_variables( $order ) {
        return (array) apply_filters( 'pmw_google_ads_order_custom_variables', [], $order );
    }

    private function get_order_products( $order ) {
        $order_products = [];
        foreach ( (array) Product::pmw_get_order_items( $order ) as $order_item ) {
            $order_item_data = $order_item->get_data();
            if ( 0 !== $order_item_data['variation_id'] ) {
                // add variation
                $order_products[$order_item_data['variation_id']] = $this->get_product_data( $order_item_data['variation_id'] );
            }
            $order_products[$order_item_data['product_id']] = $this->get_product_data( $order_item_data['product_id'] );
        }
        return $order_products;
    }

    private function get_product_data( $product_id ) {
        $product = wc_get_product( $product_id );
        if ( Product::is_not_wc_product( $product ) ) {
            Product::log_problematic_product_id( $product_id );
            return [];
        }
        $data = [
            'product_id'          => $product->get_id(),
            'name'                => $product->get_name(),
            'type'                => $product->get_type(),
            'dyn_r_ids'           => Product::get_dyn_r_ids( $product ),
            'brand'               => (string) Product::get_brand_name( $product_id ),
            'category'            => (array) Product::get_product_category( $product_id ),
            'variant_description' => ( (string) ($product->get_type() === 'variation') ? Product::get_formatted_variant_text( $product ) : '' ),
        ];
        if ( $product->get_type() === 'variation' ) {
            $parent_product = wc_get_product( $product->get_parent_id() );
            $data['brand'] = Product::get_brand_name( $parent_product->get_id() );
            $data['name_variant'] = $data['name'];
            $data['name'] = $parent_product->get_name();
        }
        return $data;
    }

    private static function inject_pmw_opening() {
        echo PHP_EOL . '<!-- START Pixel Manager for WooCommerce -->' . PHP_EOL;
    }

    public function inject_pmw_closing() {
        if ( Environment::is_woocommerce_active() && Shop::pmw_is_order_received_page() && Shop::pmw_get_current_order() ) {
            $this->increase_conversion_count_for_ratings( Shop::pmw_get_current_order() );
        }
        echo PHP_EOL . '<!-- END Pixel Manager for WooCommerce -->' . PHP_EOL;
    }

    private function increase_conversion_count_for_ratings( $order ) {
        if ( Shop::can_order_confirmation_be_processed( $order ) ) {
            $ratings = get_option( PMW_DB_RATINGS );
            if ( !isset( $ratings['conversions_count'] ) ) {
                $ratings['conversions_count'] = 0;
            }
            $ratings['conversions_count'] = $ratings['conversions_count'] + 1;
            update_option( PMW_DB_RATINGS, $ratings );
        } else {
            Shop::conversion_pixels_already_fired_html();
        }
    }

    public function ajax_pmw_get_cart_items() {
        Logger::debug( 'ajax_pmw_get_cart_items()' );
        global $woocommerce;
        $cart_items = $woocommerce->cart->get_cart();
        $data = [];
        foreach ( $cart_items as $cart_item => $value ) {
            $product = wc_get_product( $value['data']->get_id() );
            if ( Product::is_not_wc_product( $product ) ) {
                Product::log_problematic_product_id( $value['data']->get_id() );
                continue;
            }
            $data['cart_item_keys'][$cart_item] = [
                'id'           => (string) $product->get_id(),
                'is_variation' => false,
            ];
            $data['cart'][$product->get_id()] = [
                'id'           => (string) $product->get_id(),
                'dyn_r_ids'    => Product::get_dyn_r_ids( $product ),
                'name'         => $product->get_name(),
                'brand'        => Product::get_brand_name( $product->get_id() ),
                'quantity'     => (int) $value['quantity'],
                'price'        => (float) $product->get_price(),
                'is_variation' => false,
            ];
            if ( 'variation' === $product->get_type() ) {
                $parent_product = wc_get_product( $product->get_parent_id() );
                if ( $parent_product ) {
                    $data['cart'][$product->get_id()]['name'] = $parent_product->get_name();
                    $data['cart'][$product->get_id()]['parent_id'] = (string) $parent_product->get_id();
                    $data['cart'][$product->get_id()]['parent_id_dyn_r_ids'] = Product::get_dyn_r_ids( $parent_product );
                    $data['cart'][$product->get_id()]['brand'] = Product::get_brand_name( $parent_product->get_id() );
                } else {
                    Logger::debug( 'Variation ' . $product->get_id() . ' doesn\'t link to a valid parent product.' );
                }
                $data['cart'][$product->get_id()]['is_variation'] = true;
                $data['cart'][$product->get_id()]['category'] = Product::get_product_category( $product->get_parent_id() );
                $variant_text_array = [];
                $attributes = $product->get_attributes();
                if ( $attributes ) {
                    foreach ( $attributes as $key => $value ) {
                        $key_name = str_replace( 'pa_', '', $key );
                        $variant_text_array[] = ucfirst( $key_name ) . ': ' . strtolower( $value );
                    }
                }
                $data['cart'][$product->get_id()]['variant'] = (string) implode( ' | ', $variant_text_array );
                $data['cart_item_keys'][$cart_item]['parent_id'] = (string) $product->get_parent_id();
                $data['cart_item_keys'][$cart_item]['is_variation'] = true;
            } else {
                $data['cart'][$product->get_id()]['category'] = Product::get_product_category( $product->get_id() );
            }
        }
        wp_send_json_success( $data );
    }

    public function ajax_pmw_get_product_ids() {
        $data = Helpers::get_input_vars( INPUT_POST );
        // Change product_ids back into an array
        $data['product_ids'] = explode( ',', $data['product_ids'] );
        $this->get_products_for_datalayer( $data );
    }

    public function get_products_for_datalayer_by_product_ids( $product_ids ) {
        $products = [];
        foreach ( $product_ids as $key => $product_id ) {
            // validate if a valid product ID has been passed in the array
            if ( !ctype_digit( $product_id ) ) {
                continue;
            }
            $product = wc_get_product( $product_id );
            if ( Product::is_not_wc_product( $product ) ) {
                continue;
            }
            $products[$product_id] = Product::get_product_details_for_datalayer( $product );
        }
        return $products;
    }

    public function ajax_purchase_pixels_fired_handler() {
        $_post = Helpers::get_input_vars( INPUT_POST );
        // Don't use the nonce check from the admin class, because some plugin users have even partial purchase confirmation pages cached,
        // which means the nonce will be invalid.
        //		if (!wp_verify_nonce($_post['nonce_ajax'], 'nonce-pmw-ajax')) {
        //			wp_send_json_error('Invalid nonce');
        //		}
        self::process_conversion_pixel_status( $_post['order_id'], $_post['order_key'], $_post['source'] );
    }

    private static function process_conversion_pixel_status( $order_id, $order_key, $order_source ) {
        if ( !$order_id || !$order_key || !$order_source ) {
            wp_send_json_error( 'Invalid data. Missing one or several of order_id, order_key or source.' );
        }
        $order = wc_get_order( $order_id );
        if ( !$order ) {
            wp_send_json_error( 'Invalid order ID' );
        }
        if ( $order->get_order_key() !== $order_key ) {
            wp_send_json_error( 'Invalid order key or wrong order ID' );
        }
        self::save_conversion_pixels_fired_status( $order, $order_source );
        wp_send_json_success( 'Successfully saved the order status.' );
    }

    public static function save_conversion_pixels_fired_status( $order, $source = 'thankyou_page' ) {
        $order->update_meta_data( '_wpm_conversion_pixel_trigger', $source );
        $order->update_meta_data( '_wpm_conversion_pixel_fired', true );
        // Get the time between when the order was created and now and save it in _wpm_conversion_pixel_fired_delay
        $time_diff = time() - strtotime( $order->get_date_created() );
        $order->update_meta_data( '_wpm_conversion_pixel_fired_delay', $time_diff );
        $order->save();
    }

    public function front_end_scripts() {
        $pmw_dependencies = ['jquery', 'wp-hooks'];
        wp_enqueue_script(
            'wpm',
            PMW_PLUGIN_DIR_PATH . 'js/public/wpm-public.p1.min.js',
            $pmw_dependencies,
            PMW_CURRENT_VERSION,
            $this->move_pmw_script_to_footer()
        );
        wp_localize_script( 
            'wpm',
            //            'ajax_object',
            'wpm',
            [
                'ajax_url'      => admin_url( 'admin-ajax.php' ),
                'root'          => esc_url_raw( rest_url() ),
                'nonce_wp_rest' => wp_create_nonce( 'wp_rest' ),
                'nonce_ajax'    => wp_create_nonce( 'nonce-pmw-ajax' ),
            ]
         );
    }

    public function front_end_styles_elementor_fix() {
        wp_enqueue_style(
            'pmw-public-elementor-fix',
            PMW_PLUGIN_DIR_PATH . 'css/public/elementor-fix.css',
            [],
            PMW_CURRENT_VERSION
        );
    }

    private function move_pmw_script_to_footer() {
        $move_pmw_script_to_footer_active = apply_filters_deprecated(
            'wpm_experimental_move_wpm_script_to_footer',
            [false],
            '1.31.2',
            'pmw_experimental_move_pmw_script_to_footer'
        );
        // this filter moves the PMW script to the footer
        return apply_filters( 'pmw_experimental_move_pmw_script_to_footer', $move_pmw_script_to_footer_active );
    }

    private function get_preset_version() {
        $version = apply_filters_deprecated(
            'wpm_script_optimization_preset_version',
            [1],
            '1.31.2',
            'pmw_script_optimization_preset_version'
        );
        return '.p' . apply_filters( 'pmw_script_optimization_preset_version', $version );
    }

    public function inject_order_received_page_dedupe( $order, $order_total, $is_new_customer ) {
        // nothing to do
    }

    private static function inject_body_pixels() {
        //        do something
    }

    private function get_shop_data() {
        $data = [];
        if ( is_product_category() ) {
            $data['list_name'] = 'Product Category' . Shop::get_list_name_suffix();
            $data['list_id'] = 'product_category' . Shop::get_list_id_suffix();
            $data['page_type'] = 'product_category';
        } elseif ( is_product_tag() ) {
            $data['list_name'] = 'Product Tag' . Shop::get_list_name_suffix();
            $data['list_id'] = 'product_tag' . Shop::get_list_id_suffix();
            $data['page_type'] = 'product_tag';
        } elseif ( is_search() ) {
            $data['list_name'] = 'Product Search';
            $data['list_id'] = 'search';
            $data['page_type'] = 'search';
        } elseif ( is_shop() ) {
            $data['list_name'] = 'Shop | page number: ' . $this->get_page_number();
            $data['list_id'] = 'product_shop_page_number_' . $this->get_page_number();
            $data['page_type'] = 'product_shop';
        } elseif ( is_product() ) {
            $data['list_name'] = 'Product | ' . Shop::pmw_get_the_title();
            $data['list_id'] = 'product_' . sanitize_title( get_the_title() );
            $data['page_type'] = 'product';
            $product = wc_get_product();
            $data['product_type'] = $product->get_type();
        } elseif ( is_front_page() ) {
            $data['list_name'] = 'Front Page';
            $data['list_id'] = 'front_page';
            $data['page_type'] = 'front_page';
        } elseif ( Shop::pmw_is_order_received_page() ) {
            $data['list_name'] = 'Order Received Page';
            $data['list_id'] = 'order_received_page';
            $data['page_type'] = 'order_received_page';
        } elseif ( is_cart() ) {
            $data['list_name'] = 'Cart';
            $data['list_id'] = 'cart';
            $data['page_type'] = 'cart';
        } elseif ( is_checkout() ) {
            $data['list_name'] = 'Checkout Page';
            $data['list_id'] = 'checkout';
            $data['page_type'] = 'checkout';
        } elseif ( is_page() ) {
            $data['list_name'] = 'Page | ' . Shop::pmw_get_the_title();
            $data['list_id'] = 'page_' . sanitize_title( get_the_title() );
            $data['page_type'] = 'page';
        } elseif ( is_home() ) {
            $data['list_name'] = 'Blog Home';
            $data['list_id'] = 'blog_home';
            $data['page_type'] = 'blog_post';
        } elseif ( 'post' === get_post_type() ) {
            $data['list_name'] = 'Blog Post | ' . Shop::pmw_get_the_title();
            $data['list_id'] = 'blog_post_' . sanitize_title( get_the_title() );
            $data['page_type'] = 'blog_post';
        } else {
            $data['list_name'] = '';
            $data['list_id'] = '';
            $data['page_type'] = '';
        }
        $data['currency'] = get_woocommerce_currency();
        $data['selectors'] = [
            'addToCart'     => (array) apply_filters( 'pmw_add_selectors_add_to_cart', [] ),
            'beginCheckout' => (array) apply_filters( 'pmw_add_selectors_begin_checkout', [] ),
        ];
        $data['order_duplication_prevention'] = Shop::is_order_duplication_prevention_active();
        $data['view_item_list_trigger'] = Shop::view_item_list_trigger_settings();
        $data['variations_output'] = Options::is_shop_variations_output_active();
        $data['session_active'] = Shop::is_woocommerce_session_active();
        return $data;
    }

    private static function get_page_data() {
        $data = [];
        $data['id'] = get_the_ID();
        $data['title'] = get_the_title();
        $data['type'] = get_post_type();
        $data['categories'] = get_the_category();
        //		$data['template']   = get_page_template_slug();
        $parent_id = wp_get_post_parent_id( $data['id'] );
        // Parent
        $data['parent'] = [
            'id'         => $parent_id,
            'title'      => get_the_title( $parent_id ),
            'type'       => get_post_type( $parent_id ),
            'categories' => get_the_category( $parent_id ),
        ];
        return $data;
    }

    private function get_page_number() {
        return ( get_query_var( 'paged' ) ? get_query_var( 'paged' ) : 1 );
    }

    private function get_general_data() {
        $data = [
            'user_logged_in'             => is_user_logged_in(),
            'scroll_tracking_thresholds' => Options::get_scroll_tracking_thresholds(),
            'page_id'                    => get_the_ID(),
            'exclude_domains'            => apply_filters( 'pmw_exclude_domains_from_tracking', [] ),
            'server_2_server'            => [
                'active'          => Options::server_2_server_enabled(),
                'ip_exclude_list' => apply_filters( 'pmw_exclude_ips_from_server_2_server_events', [] ),
            ],
            'consent_management'         => [
                'explicit_consent' => Options::is_consent_management_explicit_consent_active(),
            ],
            'lazy_load_pmw'              => Options::is_lazy_load_pmw_active(),
        ];
        if ( Options::are_restricted_consent_regions_set() ) {
            $data['consent_management']['restricted_regions'] = Options::get_restricted_consent_regions();
        }
        return $data;
    }

}
