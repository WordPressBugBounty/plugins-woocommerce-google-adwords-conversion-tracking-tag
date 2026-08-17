<?php
/**
 * Social login integration
 *
 * Reads the Facebook app-scoped user ID out of whichever third party social
 * login plugin the shop already has installed, so it can be sent to Meta as
 * the fb_login_id CAPI parameter.
 *
 * PMW deliberately does not implement social login itself. Every provider
 * here is a plugin the shop installed and configured with its own Facebook
 * app, which means PMW never touches an OAuth flow or creates users.
 *
 * Important: fb_login_id is an app-scoped ID. Meta can only match it when the
 * Facebook app that issued it belongs to the same Business Manager as the
 * pixel receiving the event. Plugins that authenticate through a shared vendor
 * app (OneAll's "Social Login", for example) therefore cannot be supported at
 * all, because the ID they hold is scoped to the vendor's app rather than the
 * shop's.
 *
 * Every plugin on WordPress.org that offers Facebook login was surveyed. These
 * are supported because they persist the Facebook user ID somewhere readable:
 * Nextend Social Login, miniOrange Social Login, UsersWP Social Login,
 * Super Socializer, Wapu Auth, Heateor Login and Easy Social Login.
 *
 * The rest cannot be supported, for two different reasons.
 *
 * They never persist the provider's user ID, so there is nothing to read:
 * - Wp Social (wp-social), keeps only the provider name in xs_social_register_by
 * - Login & Register Forms (easy-login-woocommerce), social login is a paid addon
 * - Happy Social Login, Ventra Connect, Login Me Now, Titan Social Login,
 *   SocialAll, TomS Social Login, Rundiz OAuth, Social Login by BestWebSoft and
 *   WP Social AutoConnect
 *
 * Or the ID they hold is scoped to someone else's Facebook app:
 * - Social Login (oa-social-login), authenticates through OneAll's shared app
 *
 * Google-only plugins (Log in with Google, One Tap Google Sign In, oneTap,
 * Login With) are irrelevant here: Google Ads consumes a hashed email address,
 * which PMW already takes from the order, and there is no Google counterpart to
 * fb_login_id.
 *
 * This class is not premium-only even though only the CAPI consumes the ID.
 * The free build needs the plugin detection to render the Pro upsell for the
 * setting and the admin opportunity.
 *
 * @since 1.64.1
 */

namespace SweetCode\Pixel_Manager;

use SweetCode\Pixel_Manager\Admin\Environment;

defined('ABSPATH') || exit; // Exit if accessed directly


class Social_Login {

	/**
	 * Resolved Facebook login IDs, keyed by WordPress user ID.
	 *
	 * A single request can emit several CAPI events, and each one asks for the
	 * same user's ID. Caching keeps that to one database query per user. Null
	 * is a cached value too, so a user without a linked Facebook account is
	 * not looked up again.
	 *
	 * @var array<int, string|null>
	 */
	private static $facebook_login_ids = [];

	/**
	 * Get the Facebook app-scoped user ID for a WordPress user.
	 *
	 * Each supported plugin is tried in descending order of market share and
	 * the first usable ID wins. A shop running two social login plugins at
	 * once is misconfigured, but if it happens the ID is still valid whichever
	 * plugin supplied it, because both point at the same Facebook app.
	 *
	 * @param int $user_id WordPress user ID. Guests (0) always return null.
	 *
	 * @return string|null Numeric ID as a string, or null when unavailable.
	 * @since 1.64.1
	 */
	public static function get_facebook_login_id( $user_id ) {

		$user_id = (int) $user_id;

		// Guests have no WordPress user, so they can never have a linked
		// Facebook account.
		if ($user_id <= 0) {
			return null;
		}

		// array_key_exists() rather than isset(), so a cached null counts as a
		// hit instead of being re-queried on every event.
		if (array_key_exists($user_id, self::$facebook_login_ids)) {
			return self::$facebook_login_ids[$user_id];
		}

		$facebook_login_id = null;

		foreach (self::get_providers() as $provider) {

			if (!call_user_func($provider['is_active'])) {
				continue;
			}

			$candidate = call_user_func($provider['resolve'], $user_id);

			if (self::is_valid_facebook_login_id($candidate)) {
				$facebook_login_id = (string) $candidate;
				break;
			}
		}

		self::$facebook_login_ids[$user_id] = $facebook_login_id;

		return $facebook_login_id;
	}

	/**
	 * Whether any supported social login plugin is active.
	 *
	 * Used by the admin opportunity to decide whether the feature is worth
	 * suggesting at all.
	 *
	 * @return bool
	 * @since 1.64.1
	 */
	public static function is_supported_plugin_active() {

		foreach (self::get_providers() as $provider) {
			if (call_user_func($provider['is_active'])) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Names of every supported plugin, for admin messaging.
	 *
	 * Derived from the provider registry so the admin copy, the debug info and
	 * the settings catalog cannot drift apart from what the code actually reads.
	 *
	 * @return string[]
	 * @since 1.64.1
	 */
	public static function get_supported_plugin_names() {
		return array_column(self::get_providers(), 'name');
	}

	/**
	 * Name of the active supported plugin, for admin messaging.
	 *
	 * @return string|null
	 * @since 1.64.1
	 */
	public static function get_active_plugin_name() {

		foreach (self::get_providers() as $provider) {
			if (call_user_func($provider['is_active'])) {
				return $provider['name'];
			}
		}

		return null;
	}

	/**
	 * Debug info section about the social login integration.
	 *
	 * The first support question about this feature will be why fb_login_id
	 * does not show up in Events Manager, and the answer is almost always one
	 * of three things: no supported plugin, the setting off, or the plugin's
	 * Facebook app living in a different Business Manager than the pixel. The
	 * first two are answered here. The third cannot be detected from inside
	 * WordPress, so it is spelled out as the remaining thing to check.
	 *
	 * Returns an empty string when there is nothing to report, so the section
	 * stays out of the debug info on shops that do not use social login.
	 *
	 * @return string
	 * @since 1.64.1
	 */
	public static function get_debug_info() {

		$detected = [];

		foreach (self::get_providers() as $provider) {
			if (call_user_func($provider['is_active'])) {
				$detected[] = $provider['name'];
			}
		}

		$is_enabled = Options::is_facebook_send_fb_login_id_enabled();

		// Nothing to say: no plugin and the setting was never turned on.
		if (empty($detected) && !$is_enabled) {
			return '';
		}

		$html  = 'Send Facebook Login ID setting: ' . ( $is_enabled ? 'on' : 'off' ) . PHP_EOL;
		$html .= 'Detected social login plugins: ' . ( empty($detected) ? 'none' : implode(', ', $detected) ) . PHP_EOL;

		if (empty($detected)) {
			$html .= 'No supported plugin, so no ID can be read. Supported: ' . implode(', ', self::get_supported_plugin_names()) . '.' . PHP_EOL;

			return $html;
		}

		// Resolving for the admin generating this report is the cheapest
		// end-to-end proof that the read path works at all.
		$current_user_id = get_current_user_id();

		if ($current_user_id) {
			$html .= 'Facebook Login ID for the current user (' . $current_user_id . '): '
				. ( self::get_facebook_login_id($current_user_id) ? 'found' : 'not linked' )
				. PHP_EOL;
		}

		if ($is_enabled) {
			$html .= 'Note: Meta only matches the ID when the Facebook app of the social login plugin is in the same Business Manager as the pixel. This cannot be verified from WordPress.' . PHP_EOL;
		}

		return $html;
	}

	/**
	 * Supported social login plugins, most installed first.
	 *
	 * Adding another plugin means adding one entry here. Nothing else in the
	 * class is provider specific.
	 *
	 * @return array<int, array>
	 * @since 1.64.1
	 */
	private static function get_providers() {

		return [
			[
				'name'      => 'Nextend Social Login',
				'is_active' => [ Environment::class, 'is_nextend_social_login_active' ],
				'resolve'   => [ self::class, 'get_id_from_nextend' ],
			],
			[
				'name'      => 'miniOrange Social Login',
				'is_active' => [ Environment::class, 'is_miniorange_social_login_active' ],
				'resolve'   => [ self::class, 'get_id_from_miniorange' ],
			],
			[
				'name'      => 'UsersWP Social Login',
				'is_active' => [ Environment::class, 'is_userswp_social_login_active' ],
				'resolve'   => [ self::class, 'get_id_from_userswp' ],
			],
			[
				'name'      => 'Super Socializer',
				'is_active' => [ Environment::class, 'is_super_socializer_active' ],
				'resolve'   => [ self::class, 'get_id_from_super_socializer' ],
			],
			[
				'name'      => 'Wapu Auth',
				'is_active' => [ Environment::class, 'is_wapu_auth_active' ],
				'resolve'   => [ self::class, 'get_id_from_wapu_auth' ],
			],
			[
				'name'      => 'Heateor Login',
				'is_active' => [ Environment::class, 'is_heateor_login_active' ],
				'resolve'   => [ self::class, 'get_id_from_heateor_login' ],
			],
			[
				'name'      => 'Easy Social Login',
				'is_active' => [ Environment::class, 'is_easy_social_login_active' ],
				'resolve'   => [ self::class, 'get_id_from_easy_social_login' ],
			],
		];
	}

	/**
	 * Nextend Social Login.
	 *
	 * Stores provider links in its own {prefix}social_users table, keyed by
	 * WordPress user ID plus a provider type.
	 *
	 * Note that Nextend's type value for Facebook is 'fb', not 'facebook'
	 * (see its providers/facebook/facebook.php, protected $dbID = 'fb').
	 *
	 * Its own getProviderIdentifierByUserID() is protected, so the table has
	 * to be read directly.
	 *
	 * @param int $user_id
	 *
	 * @return string|null
	 * @since 1.64.1
	 */
	private static function get_id_from_nextend( $user_id ) {

		global $wpdb;

		return self::get_var_quietly(
			$wpdb->prepare(
				'SELECT identifier FROM `' . $wpdb->prefix . 'social_users` WHERE type = %s AND ID = %d LIMIT 1',
				'fb',
				$user_id
			)
		);
	}

	/**
	 * The miniOrange Social Login plugin.
	 *
	 * Stores provider links in its own {prefix}mo_openid_linked_user table.
	 *
	 * @param int $user_id
	 *
	 * @return string|null
	 * @since 1.64.1
	 */
	private static function get_id_from_miniorange( $user_id ) {

		global $wpdb;

		return self::get_var_quietly(
			$wpdb->prepare(
				'SELECT identifier FROM `' . $wpdb->prefix . 'mo_openid_linked_user` WHERE linked_social_app = %s AND user_id = %d LIMIT 1',
				'facebook',
				$user_id
			)
		);
	}

	/**
	 * UsersWP Social Login.
	 *
	 * HybridAuth based, storing links in {base_prefix}uwp_social_profiles.
	 *
	 * Two traps here. The table lives on the base prefix, not the site prefix,
	 * so on a multisite network it is shared across all sites rather than
	 * per-site. And the provider value is whatever the login button submitted,
	 * which is the capitalised HybridAuth adapter name ('Facebook'), so the
	 * comparison is lowercased explicitly rather than relying on the table's
	 * collation being case insensitive.
	 *
	 * @param int $user_id
	 *
	 * @return string|null
	 * @since 1.64.1
	 */
	private static function get_id_from_userswp( $user_id ) {

		global $wpdb;

		return self::get_var_quietly(
			$wpdb->prepare(
				'SELECT identifier FROM `' . $wpdb->base_prefix . 'uwp_social_profiles` WHERE LOWER(provider) = %s AND user_id = %d LIMIT 1',
				'facebook',
				$user_id
			)
		);
	}

	/**
	 * Wapu Auth.
	 *
	 * Keeps a dedicated per-provider meta key, so no provider gate is needed.
	 *
	 * @param int $user_id
	 *
	 * @return string|null
	 * @since 1.64.1
	 */
	private static function get_id_from_wapu_auth( $user_id ) {
		return get_user_meta($user_id, 'wapu_auth_facebook_id', true);
	}

	/**
	 * Heateor Login.
	 *
	 * A Facebook-only plugin, so its single ID meta is always a Facebook one.
	 *
	 * @param int $user_id
	 *
	 * @return string|null
	 * @since 1.64.1
	 */
	private static function get_id_from_heateor_login( $user_id ) {
		return get_user_meta($user_id, 'heateor_fbl_id', true);
	}

	/**
	 * Easy Social Login.
	 *
	 * Keeps a dedicated per-provider meta key, so no provider gate is needed.
	 *
	 * @param int $user_id
	 *
	 * @return string|null
	 * @since 1.64.1
	 */
	private static function get_id_from_easy_social_login( $user_id ) {
		return get_user_meta($user_id, 'eslp_facebook_id', true);
	}

	/**
	 * Super Socializer.
	 *
	 * Keeps the provider ID in user meta, but in a single slot shared by every
	 * provider, so the last one used wins. The ID is therefore only usable
	 * when the accompanying provider meta says it came from Facebook.
	 * Otherwise thechamp_social_id holds a Google or X ID, which Meta would
	 * silently fail to match.
	 *
	 * @param int $user_id
	 *
	 * @return string|null
	 * @since 1.64.1
	 */
	private static function get_id_from_super_socializer( $user_id ) {

		if ('facebook' !== get_user_meta($user_id, 'thechamp_provider', true)) {
			return null;
		}

		return get_user_meta($user_id, 'thechamp_social_id', true);
	}

	/**
	 * Run a scalar query without logging errors.
	 *
	 * A social login plugin can be active before it has created its tables,
	 * for example between activation and the first login. Querying a missing
	 * table is an expected miss here rather than a fault worth surfacing, so
	 * errors are suppressed and the previous setting restored.
	 *
	 * @param string $query Prepared query.
	 *
	 * @return string|null
	 * @since 1.64.1
	 */
	private static function get_var_quietly( $query ) {

		global $wpdb;

		$previous_suppress = $wpdb->suppress_errors(true);

		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- Every caller passes a $wpdb->prepare() result, see the @param docs.
		$value = $wpdb->get_var($query);

		$wpdb->suppress_errors($previous_suppress);

		return $value;
	}

	/**
	 * Validate a candidate Facebook login ID.
	 *
	 * Meta expects an unhashed numeric app-scoped ID. Anything non-numeric
	 * means we read the wrong column or a different provider's identifier, and
	 * sending it would only pollute the payload.
	 *
	 * @param mixed $candidate
	 *
	 * @return bool
	 * @since 1.64.1
	 */
	private static function is_valid_facebook_login_id( $candidate ) {

		if (empty($candidate)) {
			return false;
		}

		return (bool) ctype_digit((string) $candidate);
	}
}
