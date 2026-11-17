<?php
/**
 * Optimizely security gate after the November 2026 incident.
 *
 * On 16 November 2026 attackers injected a crypto clipper and card skimmer
 * into the Project JavaScript of some Optimizely Web projects, served from
 * cdn.optimizely.com. Pixel Manager only loads the Optimizely snippet; it was
 * not compromised itself. 1.74.0 stopped loading Optimizely altogether. This
 * class replaces that hard block with an opt-in (DD-092):
 *
 *  - Optimizely only loads once a shop manager confirmed that they checked
 *    their Optimizely Project JavaScript for unknown code. The confirmation is
 *    stored with its time and user in its own option row, so restoring a
 *    settings backup can never fake or remove it.
 *  - Sites that had Optimizely set up are paused once on upgrade and flagged,
 *    which drives the admin notice. The project ID and every other setting
 *    are kept, so re-enabling is a single action.
 *  - Resuming Optimizely without the confirmation is refused everywhere: the
 *    Nova settings route, the Abilities API and agents.
 *
 * To block Optimizely again for every site, flip the default of the
 * pmw_optimizely_allow_loading filter (Options::is_optimizely_loading_allowed)
 * back to false in a release. There is no remote kill switch.
 *
 * @package SweetCode\Pixel_Manager
 * @since 1.74.1
 */

namespace SweetCode\Pixel_Manager;

defined('ABSPATH') || exit; // Exit if accessed directly

class Optimizely_Security {

	/**
	 * Stores the confirmation: [ 'time' => int, 'user_id' => int ].
	 *
	 * @var string
	 */
	const ACK_OPTION = 'pmw_optimizely_security_ack';

	/**
	 * Set on sites that the upgrade paused, drives the admin notice.
	 *
	 * @var string
	 */
	const INCIDENT_FLAG_OPTION = 'pmw_optimizely_incident_2026_11_disabled';

	/**
	 * Marks the one-time upgrade step as done, so it never runs twice.
	 *
	 * @var string
	 */
	const MIGRATION_OPTION = 'pmw_optimizely_incident_2026_11_migrated';

	/**
	 * Option path of Optimizely's pause switch.
	 *
	 * @var string
	 */
	const PAUSE_PATH = 'paused_pixels.optimizely';

	/**
	 * Hook the one-time upgrade step.
	 *
	 * @return void
	 * @since 1.74.1
	 */
	public static function init() {
		add_action('admin_init', [ __CLASS__, 'maybe_migrate' ]);
	}

	/**
	 * Whether a shop manager confirmed the Project JavaScript check.
	 *
	 * @return bool
	 * @since 1.74.1
	 */
	public static function is_acknowledged() {

		$ack = get_option(self::ACK_OPTION);

		return is_array($ack) && !empty($ack['time']);
	}

	/**
	 * The stored confirmation, or null.
	 *
	 * @return array|null [ 'time' => int, 'user_id' => int ]
	 * @since 1.74.1
	 */
	public static function get_acknowledgement() {

		$ack = get_option(self::ACK_OPTION);

		return is_array($ack) && !empty($ack['time']) ? $ack : null;
	}

	/**
	 * Whether the upgrade paused this site and it has not been re-enabled yet.
	 *
	 * @return bool
	 * @since 1.74.1
	 */
	public static function is_incident_disabled() {
		return (bool) get_option(self::INCIDENT_FLAG_OPTION) && !self::is_acknowledged();
	}

	/**
	 * Record the confirmation and resume Optimizely.
	 *
	 * Callers must have checked that the person confirmed the check. Stores
	 * who confirmed it and when, clears the incident flag and resumes the
	 * pixel, keeping every other Optimizely setting.
	 *
	 * @param int $user_id The confirming user.
	 * @return void
	 * @since 1.74.1
	 */
	public static function acknowledge( $user_id ) {

		update_option(self::ACK_OPTION, [
			'time'    => time(),
			'user_id' => (int) $user_id,
		], false);

		delete_option(self::INCIDENT_FLAG_OPTION);

		$options = Options::get_options();

		if (!empty($options['paused_pixels']['optimizely'])) {
			$options['paused_pixels']['optimizely'] = 0;
			Options::save_options_with_timestamp($options, false);
		}
	}

	/**
	 * One-time upgrade step: pause Optimizely where it was set up.
	 *
	 * Idempotent: it runs once per site, and running it again changes nothing.
	 * A site that already confirmed the check (for example after a downgrade
	 * and upgrade) is left alone. Sites without a project ID get no flag and no
	 * notice.
	 *
	 * @return void
	 * @since 1.74.1
	 */
	public static function maybe_migrate() {

		if (get_option(self::MIGRATION_OPTION)) {
			return;
		}

		update_option(self::MIGRATION_OPTION, time(), true);

		if (self::is_acknowledged()) {
			return;
		}

		$options = Options::get_options();

		if (empty($options['pixels']['optimizely']['project_id'])) {
			return;
		}

		if (empty($options['paused_pixels']['optimizely'])) {
			$options['paused_pixels']['optimizely'] = time();
			Options::save_options_with_timestamp($options, false);
		}

		update_option(self::INCIDENT_FLAG_OPTION, true, true);
	}

	/**
	 * Whether a write would resume Optimizely without the confirmation.
	 *
	 * Only the transition from paused to tracking counts. Writing 0 while it is
	 * already 0 changes nothing, and Optimizely stays off anyway until the
	 * check is confirmed (Options::is_optimizely_active()).
	 *
	 * @param string $path  Dot-notation option path.
	 * @param mixed  $value New value.
	 * @return bool
	 * @since 1.74.1
	 */
	public static function is_unconfirmed_resume( $path, $value ) {

		if (self::PAUSE_PATH !== $path || self::is_acknowledged()) {
			return false;
		}

		$options = Options::get_options();
		$current = isset($options['paused_pixels']['optimizely']) ? (int) $options['paused_pixels']['optimizely'] : 0;

		return $current > 0 && 0 === (int) $value;
	}

	/**
	 * Refusal message for a resume without the confirmation.
	 *
	 * @return string
	 * @since 1.74.1
	 */
	public static function get_refusal_message() {
		return __('Optimizely can only be enabled after confirming that the Optimizely Project JavaScript was checked for unknown code. Use the "Enable Optimizely" dialog in the Optimizely settings.', 'woocommerce-google-adwords-conversion-tracking-tag');
	}

	/**
	 * State for the Nova admin UI (pmwAdminApi.optimizelySecurity).
	 *
	 * @return array
	 * @since 1.74.1
	 */
	public static function get_data_for_nova() {
		return [
			'acknowledged'     => self::is_acknowledged(),
			'incidentDisabled' => self::is_incident_disabled(),
		];
	}
}
