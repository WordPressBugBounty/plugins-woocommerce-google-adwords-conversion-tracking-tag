<?php
/**
 * First-run onboarding checklist for the Nova dashboard.
 *
 * Shows a short, achievable getting-started checklist to shops that install
 * the Pixel Manager for the first time: set up a pixel, review consent and
 * general settings, check the diagnostics, meet Pixie, and (on the free tier)
 * discover what Pro unlocks. Step completion and dismissal are persisted
 * server-side so the checklist survives reloads and disappears for good once
 * the user is done with it.
 *
 * Eligibility is limited to fresh installs: those are the only installs that
 * carry the default-admin-theme marker option, which is written exactly once
 * during the very first Options::init(). Existing shops never see the
 * checklist.
 *
 * @package SweetCode\Pixel_Manager
 * @since 1.59.0
 */

namespace SweetCode\Pixel_Manager\Admin;

use SweetCode\Pixel_Manager\Helpers;
use SweetCode\Pixel_Manager\Options;
use SweetCode\Pixel_Manager\Pixels\Core\Pixel_Registry;

defined('ABSPATH') || exit; // Exit if accessed directly

class Onboarding {

	/**
	 * Option that stores the checklist state:
	 * [ 'completed' => [ step => timestamp ], 'dismissed' => timestamp|false ]
	 *
	 * @var string
	 */
	public static $option_name = 'pmw_onboarding_checklist';

	/**
	 * Transient caching the last-known Pixie (chatbot) availability, written
	 * on the PMW settings page where the remote reachability check runs. The
	 * admin-menu badge renders on every admin page, where a remote check per
	 * request would be far too expensive, so it reads this instead. Unknown
	 * (never visited / expired) counts as unavailable.
	 *
	 * @var string
	 */
	public static $cody_available_transient = 'pmw_cody_available';

	/**
	 * The checklist steps that can be persisted.
	 *
	 * "pixel" (the first-pixel step) is intentionally not in this list: it is
	 * derived live from the settings on the client and never stored.
	 *
	 * @var array
	 */
	private static $steps = [
		'consent',     // reviewed the consent settings
		'diagnostics', // visited the diagnostics tab
		'pixie',       // opened the Pixie chat
		'pro',         // looked at what Pro unlocks (free tier only)
	];

	/**
	 * Whether this install is eligible for the onboarding checklist.
	 *
	 * Only fresh installs carry the default-admin-theme marker (written once
	 * in Options::init() when no stored options exist yet).
	 *
	 * @return bool
	 */
	public static function is_eligible() {
		return (bool) get_option(Options::$default_admin_theme_option_name);
	}

	/**
	 * Checklist state for the Nova admin UI (injected into pmwAdminApi).
	 *
	 * @return array { show: bool, completed?: string[] }
	 */
	public static function get_data_for_nova() {

		if (!self::is_eligible()) {
			return [ 'show' => false ];
		}

		$state = get_option(self::$option_name, []);

		if (!empty($state['dismissed'])) {
			return [ 'show' => false ];
		}

		$completed = isset($state['completed']) && is_array($state['completed'])
			? array_values(array_intersect(array_keys($state['completed']), self::$steps))
			: [];

		return [
			'show'      => true,
			'completed' => $completed,
		];
	}

	/**
	 * Number of getting-started steps still open, for the admin-menu badge.
	 *
	 * Mirrors the step composition of the Nova checklist (onboardingStepIds()
	 * in the admin UI): consent/diagnostics always, Pixie only when the
	 * chatbot was reachable on the last settings-page visit, Pro only on the
	 * free tier when an upgrade or trial URL exists, and the first-pixel step
	 * derived live from the settings. Returns 0 once the checklist is
	 * dismissed or completed, so the badge falls back to the pure
	 * opportunities count.
	 *
	 * @return int
	 * @since 1.61.2
	 */
	public static function get_open_steps_count() {

		if (!self::is_eligible()) {
			return 0;
		}

		$state = get_option(self::$option_name, []);

		if (!empty($state['dismissed'])) {
			return 0;
		}

		$completed = isset($state['completed']) && is_array($state['completed'])
			? $state['completed']
			: [];

		$steps = [ 'consent', 'diagnostics' ];

		if ('yes' === get_transient(self::$cody_available_transient)) {
			$steps[] = 'pixie';
		}

		// The Pro step only exists when there is a real URL to open (the
		// checklist hides it otherwise); mirror that so the badge count
		// matches what the user sees.
		if (
			!Helpers::is_pmw_pro_version_active()
			&& ( Commercial_Links::upgrade_url() || Notifications\Trial_Promotion_Notification::get_available_trial_url() )
		) {
			$steps[] = 'pro';
		}

		$open = 0;

		foreach ($steps as $step) {
			if (!isset($completed[$step])) {
				++$open;
			}
		}

		// The first-pixel step is never persisted; it is derived live.
		if (empty(Pixel_Registry::get_active_pixels())) {
			++$open;
		}

		return $open;
	}

	/**
	 * Mark a checklist step as completed.
	 *
	 * @param string $step One of the persistable steps.
	 * @return bool Whether the step was valid.
	 */
	public static function complete_step( $step ) {

		if (!in_array($step, self::$steps, true)) {
			return false;
		}

		$state = get_option(self::$option_name, []);

		if (!isset($state['completed']) || !is_array($state['completed'])) {
			$state['completed'] = [];
		}

		if (!isset($state['completed'][$step])) {
			$state['completed'][$step] = time();
			update_option(self::$option_name, $state, false);
		}

		return true;
	}

	/**
	 * Dismiss the checklist permanently (used by "Hide checklist").
	 *
	 * @return void
	 */
	public static function dismiss() {

		$state = get_option(self::$option_name, []);

		$state['dismissed'] = time();

		update_option(self::$option_name, $state, false);
	}
}
