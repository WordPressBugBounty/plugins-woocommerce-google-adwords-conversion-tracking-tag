<?php
/**
 * Plugin Name:          Pixel Manager for WooCommerce
 * Description:          Visitor and conversion value tracking for WooCommerce. Highly optimized for data accuracy.
 * Author:               SweetCode
 * Plugin URI:           https://wordpress.org/plugins/woocommerce-google-adwords-conversion-tracking-tag/
 * Author URI:           https://sweetcode.com
 * Developer:            SweetCode
 * Developer URI:        https://sweetcode.com
 * Text Domain:          woocommerce-google-adwords-conversion-tracking-tag
 * Domain path:          /languages
 * Version:              1.47.0
 *
 * WC requires at least: 3.7
 * WC tested up to:      9.6
 *
 * License:              GNU General Public License v3.0
 * License URI:          http://www.gnu.org/licenses/gpl-3.0.html
 *
  **/

defined('ABSPATH') || exit; // Exit if accessed directly

$pmw_version     = '1.47.0';
$plugin_basename = plugin_basename(__FILE__);

require_once 'freemius-loader.php';
