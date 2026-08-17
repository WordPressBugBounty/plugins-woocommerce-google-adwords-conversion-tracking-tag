<?php
/**
 * Platform manager: resolves and holds the active Shop_Provider.
 *
 * @since 1.65.0
 */

namespace SweetCode\Pixel_Manager\Platforms;

use SweetCode\Pixel_Manager\Admin\Environment;
use SweetCode\Pixel_Manager\Platforms\WooCommerce\WooCommerce_Provider;

defined('ABSPATH') || exit; // Exit if accessed directly

class Platform_Manager {

	/**
	 * The resolved provider for this request.
	 *
	 * @var Shop_Provider|null
	 */
	private static $provider = null;

	/**
	 * The active shop platform provider.
	 *
	 * Resolution is driven by Environment::get_active_shop_platform().
	 * Falls back to the WooCommerce provider for 'none' so existing
	 * call sites never receive null; its is_shop_active() then reports
	 * false. Part B registers the FluentCart and SureCart providers here.
	 *
	 * @return Shop_Provider
	 */
	public static function get_provider() {

		if (null !== self::$provider) {
			return self::$provider;
		}

		// Interfaces are not autoloadable in this codebase (the autoloader
		// maps class names to class-*.php); load it explicitly before the
		// implementing provider class is autoloaded. Mirrors the pattern in
		// class-server-event-processor.php.
		if (!interface_exists('SweetCode\Pixel_Manager\Platforms\Shop_Provider')) {
			require_once __DIR__ . '/interface-shop-provider.php';
		}

		// The platform cannot change within a request; resolve once.
		switch (Environment::get_active_shop_platform()) {
			case 'woocommerce':
			default:
				self::$provider = new WooCommerce_Provider();
				break;
		}

		return self::$provider;
	}

	/**
	 * Testing seam.
	 *
	 * @param Shop_Provider|null $provider
	 * @return void
	 */
	public static function set_provider( $provider ) {
		self::$provider = $provider;
	}
}
