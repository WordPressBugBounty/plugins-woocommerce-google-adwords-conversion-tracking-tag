<?php
/**
 * One-time "server load" warnings for server-side tracking.
 *
 * Server-side tracking adds work to the store's own server: every Conversions
 * API (CAPI) call and every Google Tag Gateway request that is handled by the
 * WordPress/origin server (rather than a Cloudflare edge) consumes CPU and adds
 * latency. On busy stores this can be enough for a hosting provider to throttle
 * the site or even take it offline. Shop managers enable these features simply
 * by pasting a token (CAPI) or a measurement path (Tag Gateway) — there is no
 * toggle to attach a confirmation to — so we surface a one-time, dismissible
 * warning instead, offering the mitigations (use the Server-Side Proxy, move
 * the gateway behind Cloudflare) or the option to turn the feature back off.
 *
 * This class only carries the *acknowledgement* state and the server-side facts
 * the Nova UI needs to decide whether to show each warning. The CAPI warning is
 * triggered client-side at the moment the first server-side token is pasted (so
 * it appears instantly and only for new activations, never retroactively for
 * shops that were already using CAPI); the Tag Gateway warning is computed here
 * because whether the gateway path is genuinely served by a Cloudflare edge is
 * only known after the storefront JS has detected the live handler.
 *
 * Acknowledgement lives in its own option row (not the settings tree) so a
 * settings restore can never silently un-acknowledge it and re-nag the user.
 *
 * @package SweetCode\Pixel_Manager
 * @since 1.60.1
 */

namespace SweetCode\Pixel_Manager\Admin;

use SweetCode\Pixel_Manager\Helpers;
use SweetCode\Pixel_Manager\Options;
use SweetCode\Pixel_Manager\Pixels\Google\GTG_Config;

defined('ABSPATH') || exit; // Exit if accessed directly

class Server_Load_Warning {

	/**
	 * Option that stores the acknowledgement timestamps:
	 * [ 'capi' => timestamp|false, 'gtg' => timestamp|false ]
	 *
	 * @var string
	 */
	public static $option_name = 'pmw_server_load_warning';

	/**
	 * The warnings that carry a one-time acknowledgement. Only the CAPI warning
	 * is one-time; the Tag Gateway warning fires on every activation instead.
	 *
	 * @var array
	 */
	private static $kinds = [ 'capi' ];

	/**
	 * Warning state for the Nova admin UI (injected into pmwAdminApi).
	 *
	 * Shape:
	 * [
	 *   'capi' => [ 'acknowledged' => bool, 'sspActive' => bool, 'sspAvailable' => bool ],
	 *   'gtg'  => [ 'show' => bool, 'acknowledged' => bool ],
	 * ]
	 *
	 * @return array
	 */
	public static function get_data_for_nova() {

		return [
			'capi' => [
				// The client watches for the first server-side token being pasted
				// and shows the modal then; these flags only gate it.
				'acknowledged' => self::is_acknowledged('capi'),
				// When the Server-Side Proxy is active the CAPI calls are already
				// offloaded to the edge, so the load warning is moot.
				'sspActive'    => Options::is_ssp_active(),
				// Whether we can offer "use the Server-Side Proxy" as a mitigation
				// (Pro feature, and not available on the WooCommerce.com distro).
				'sspAvailable' => Helpers::is_pmw_pro_version_active() && !Helpers::is_pmw_wcm_distro(),
			],
			'gtg'  => self::get_gtg_data(),
		];
	}

	/**
	 * Tag Gateway warning state.
	 *
	 *  - warnOnEnable: the site is served by the origin rather than a Cloudflare
	 *                  edge, so the UI should pop the warning each time the
	 *                  measurement path is (re)activated. Unlike the CAPI warning
	 *                  this is intentionally NOT one-time — every deactivated→
	 *                  activated transition warns again, so there is no persisted
	 *                  acknowledgement to gate it.
	 *
	 * @return array
	 */
	private static function get_gtg_data() {

		return [
			'warnOnEnable' => self::gtg_is_origin_served(),
		];
	}

	/**
	 * Best available answer to "is the Tag Gateway path served by the origin
	 * server rather than a Cloudflare edge worker?".
	 *
	 * The browser is the source of truth for the live handler (the storefront JS
	 * sets the pmw_gtg_handler cookie, which GTG_Config reads):
	 *  - 'external'              => a CDN/edge worker (Cloudflare) answers — no warning.
	 *  - 'wordpress'/'standalone'=> the origin server answers — warn.
	 *  - null (not detected yet) => fall back to the request-header heuristic and
	 *    warn only when the site is clearly NOT behind Cloudflare, so a correctly
	 *    proxied site whose admin request happens to bypass the edge is never
	 *    warned by mistake.
	 *
	 * @return bool
	 */
	private static function gtg_is_origin_served() {

		$handler = GTG_Config::get_handler();

		if ('external' === $handler) {
			return false;
		}

		if (in_array($handler, [ 'wordpress', 'standalone' ], true)) {
			return true;
		}

		// Handler not detected yet: only warn when there is no sign of Cloudflare
		// at all (no CF request headers and no Cloudflare plugin). Treat any
		// uncertainty as "maybe behind Cloudflare" and stay quiet.
		return !Environment::is_server_behind_cloudflare() && !Environment::is_cloudflare_active();
	}

	/**
	 * Whether a given warning has been acknowledged.
	 *
	 * @param string $kind 'capi' or 'gtg'.
	 * @return bool
	 */
	public static function is_acknowledged( $kind ) {

		if (!in_array($kind, self::$kinds, true)) {
			return false;
		}

		$state = get_option(self::$option_name, []);

		return !empty($state[$kind]);
	}

	/**
	 * Acknowledge a warning permanently (it never shows again for this site).
	 *
	 * @param string $kind 'capi' or 'gtg'.
	 * @return bool Whether the kind was valid.
	 */
	public static function acknowledge( $kind ) {

		if (!in_array($kind, self::$kinds, true)) {
			return false;
		}

		$state = get_option(self::$option_name, []);

		if (!is_array($state)) {
			$state = [];
		}

		$state[$kind] = time();

		update_option(self::$option_name, $state, false);

		return true;
	}
}
