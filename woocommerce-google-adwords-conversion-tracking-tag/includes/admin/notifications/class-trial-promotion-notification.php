<?php

namespace SweetCode\Pixel_Manager\Admin\Notifications;

use SweetCode\Pixel_Manager\Admin\Documentation;
use SweetCode\Pixel_Manager\Admin\Environment;
use SweetCode\Pixel_Manager\Helpers;

defined('ABSPATH') || exit; // Exit if accessed directly

/**
 * Trial Promotion Notification
 *
 * Displays a notification encouraging free users to start a trial.
 * Similar in design to the opportunities and license expired notifications.
 *
 * @since 1.55.0
 */
class Trial_Promotion_Notification extends Notification {

	/**
	 * Check if the notification should be shown.
	 *
	 * Requirements:
	 * - Not a development install
	 * - User is registered with Freemius
	 * - User has not used a trial before
	 * - User is not currently paying or on trial
	 * - Trial plan is available
	 * - Admin trial promo is active in options
	 * - Admin notifications are active in options
	 *
	 * @return bool
	 * @since 1.55.0
	 */
	public static function should_notify() {

		// Debug output when constant is set
		if (defined('PMW_DEBUG_TRIAL_NOTIFICATION') && PMW_DEBUG_TRIAL_NOTIFICATION) {
			add_action('admin_notices', [ __CLASS__, 'debug_output' ], 1);
		}

		// Force show for testing - bypass all other checks
		if (self::is_force_show()) {
			return true;
		}

		// Don't show on development installs
		if (Environment::is_development_install()) {
			return false;
		}

		// Only show on dashboard or PMW settings page
		if (!Helpers::is_dashboard() && !Environment::is_pmw_settings_page()) {
			return false;
		}

		// Check Freemius conditions
		if (!function_exists('wpm_fs')) {
			return false;
		}

		$fs = wpm_fs();

		// Don't show until 24 hours after first activation
		if (!self::has_first_show_delay_passed()) {
			return false;
		}

		// Must not have used a trial before
		// Only check if registered, otherwise they haven't used a trial
		if ($fs->is_registered() && $fs->get_site()->is_trial_utilized()) {
			return false;
		}

		// Must not be paying or on trial
		if ($fs->is_paying_or_trial()) {
			return false;
		}

		// Must have a trial plan available
		if (!$fs->has_trial_plan()) {
			return false;
		}

		// Check if admin trial promo is enabled in options
		if (!self::is_admin_trial_promo_active()) {
			return false;
		}

		// Check if admin notifications are enabled
		if (!self::is_admin_notifications_active()) {
			return false;
		}

		return true;
	}

	/**
	 * Debug output for trial notification conditions.
	 *
	 * @since 1.55.0
	 */
	public static function debug_output() {
		if (!Helpers::is_dashboard() && !Environment::is_pmw_settings_page()) {
			return;
		}

		$fs = wpm_fs();

		echo '<div class="notice notice-warning"><p><strong>PMW Trial Notification Debug:</strong><br>';
		echo 'is_development_install: ' . ( Environment::is_development_install() ? 'true' : 'false' ) . '<br>';
		echo 'is_force_show: ' . ( self::is_force_show() ? 'true' : 'false' ) . '<br>';
		echo 'is_dashboard: ' . ( Helpers::is_dashboard() ? 'true' : 'false' ) . '<br>';
		echo 'is_pmw_settings_page: ' . ( Environment::is_pmw_settings_page() ? 'true' : 'false' ) . '<br>';
		echo 'wpm_fs exists: ' . ( function_exists('wpm_fs') ? 'true' : 'false' ) . '<br>';
		echo 'has_first_show_delay_passed: ' . ( self::has_first_show_delay_passed() ? 'true' : 'false' ) . '<br>';
		echo 'is_registered: ' . ( $fs->is_registered() ? 'true' : 'false' ) . '<br>';
		echo 'is_trial_utilized: ' . ( $fs->is_registered() && $fs->get_site()->is_trial_utilized() ? 'true' : 'false' ) . '<br>';
		echo 'is_paying_or_trial: ' . ( $fs->is_paying_or_trial() ? 'true' : 'false' ) . '<br>';
		echo 'has_trial_plan: ' . ( $fs->has_trial_plan() ? 'true' : 'false' ) . '<br>';
		echo 'is_admin_trial_promo_active: ' . ( self::is_admin_trial_promo_active() ? 'true' : 'false' ) . '<br>';
		echo 'is_admin_notifications_active: ' . ( self::is_admin_notifications_active() ? 'true' : 'false' ) . '<br>';
		echo 'should_notify result: ' . ( self::should_notify() ? 'true' : 'false' ) . '<br>';
		echo '</p></div>';
	}

	/**
	 * Check if force show constant is set for testing.
	 *
	 * @return bool
	 * @since 1.55.0
	 */
	private static function is_force_show() {
		return defined('PMW_FORCE_SHOW_TRIAL_NOTIFICATION') && PMW_FORCE_SHOW_TRIAL_NOTIFICATION;
	}

	/**
	 * Get the notification data.
	 *
	 * @return array
	 * @since 1.55.0
	 */
	public static function notification_data() {
		return [
			'id'              => 'trial-promotion',
			'title'           => __('Unlock Premium Features', 'woocommerce-google-adwords-conversion-tracking-tag'),
			'description'     => [
				__('Try premium features free for 14 days - no credit card required.', 'woocommerce-google-adwords-conversion-tracking-tag'),
				__('Boost your ROAS by sending first-party data with server-side tracking.', 'woocommerce-google-adwords-conversion-tracking-tag'),
			],
			'importance'      => __('High', 'woocommerce-google-adwords-conversion-tracking-tag'),
			'learn_more_link' => Documentation::get_link('trial_promotion'),
			'repeat_interval' => MONTH_IN_SECONDS * 6, // Show again 6 months after dismissal
		];
	}

	/**
	 * Output the custom trial promotion notification HTML.
	 * Overrides parent to use custom design matching opportunities notification.
	 *
	 * @since 1.55.0
	 */
	public static function output_notification() {

		if (static::not_available()) {
			return;
		}

		$notification_data = static::notification_data();

		// Get the trial URL from Freemius
		$trial_url = wpm_fs()->get_trial_url();

		?>
		<div id="pmw-trial-promotion-notification"
			 class="notice notice-info pmw trial-promotion-notification"
			 style="padding: 12px 16px; display: flex; flex-direction: row; justify-content: space-between; align-items: flex-start; border-left-color: #2271b1;">
			<div>
				<div style="color: black; margin-bottom: 8px;">
					<strong style="font-size: 14px;">
						🚀 <?php esc_html_e('Pixel Manager for WooCommerce – Try Pro Free for 14 Days!', 'woocommerce-google-adwords-conversion-tracking-tag'); ?>
					</strong>
				</div>
				<div style="color: #444; margin-bottom: 10px;">
					<?php esc_html_e('Track up to 30% more conversions with server-side tracking. Capture sales that browser-only tracking misses and unlock 10+ additional ad platforms.', 'woocommerce-google-adwords-conversion-tracking-tag'); ?>
				</div>

				<div style="display: flex; flex-wrap: wrap; gap: 8px; margin-bottom: 12px;">
					<span style="display: inline-flex; align-items: center; padding: 4px 10px; border-radius: 3px; font-size: 12px; font-weight: 500; background: #e6fffa; border: 1px solid #48bb78; color: #276749;">
						<span class="dashicons dashicons-yes-alt" style="font-size: 14px; width: 14px; height: 14px; margin-right: 4px;"></span>
						<?php esc_html_e('Server-side APIs (CAPI)', 'woocommerce-google-adwords-conversion-tracking-tag'); ?>
					</span>
					<span style="display: inline-flex; align-items: center; padding: 4px 10px; border-radius: 3px; font-size: 12px; font-weight: 500; background: #e6fffa; border: 1px solid #48bb78; color: #276749;">
						<span class="dashicons dashicons-yes-alt" style="font-size: 14px; width: 14px; height: 14px; margin-right: 4px;"></span>
						<?php esc_html_e('Enhanced Conversions', 'woocommerce-google-adwords-conversion-tracking-tag'); ?>
					</span>
					<span style="display: inline-flex; align-items: center; padding: 4px 10px; border-radius: 3px; font-size: 12px; font-weight: 500; background: #e6fffa; border: 1px solid #48bb78; color: #276749;">
						<span class="dashicons dashicons-yes-alt" style="font-size: 14px; width: 14px; height: 14px; margin-right: 4px;"></span>
						<?php esc_html_e('10+ Ad Platforms', 'woocommerce-google-adwords-conversion-tracking-tag'); ?>
					</span>
					<span style="display: inline-flex; align-items: center; padding: 4px 10px; border-radius: 3px; font-size: 12px; font-weight: 500; background: #e6fffa; border: 1px solid #48bb78; color: #276749;">
						<span class="dashicons dashicons-yes-alt" style="font-size: 14px; width: 14px; height: 14px; margin-right: 4px;"></span>
						<?php esc_html_e('Refund Tracking', 'woocommerce-google-adwords-conversion-tracking-tag'); ?>
					</span>
				</div>

				<a href="<?php echo esc_url($trial_url); ?>"
				   style="text-decoration: none; box-shadow: none;">
					<div class="button button-primary" style="margin: 0;">
						<?php esc_html_e('Start Free Trial', 'woocommerce-google-adwords-conversion-tracking-tag'); ?>
					</div>
				</a>

				<?php if (!empty($notification_data['learn_more_link'])) : ?>
					<a href="<?php echo esc_url($notification_data['learn_more_link']); ?>"
					   target="_blank"
					   style="margin-left: 12px; color: #2271b1; text-decoration: none;">
						<?php esc_html_e('Learn more', 'woocommerce-google-adwords-conversion-tracking-tag'); ?>
					</a>
				<?php endif; ?>
			</div>

			<div style="text-align: right; display: flex; flex-direction: column;">
				<div id="pmw-dismiss-trial-promotion-button"
					 class="button pmw-notification-dismiss-button"
					 style="white-space: normal; margin-bottom: 6px; text-align: center;"
					 data-notification-id="<?php echo esc_attr($notification_data['id']); ?>"
				><?php esc_html_e('Dismiss', 'woocommerce-google-adwords-conversion-tracking-tag'); ?>
				</div>
			</div>
		</div>
		<?php
	}

	/**
	 * Check if admin trial promo is active.
	 *
	 * @return bool
	 * @since 1.55.0
	 */
	private static function is_admin_trial_promo_active() {
		$pmw_options = get_option(PMW_DB_OPTIONS_NAME);

		if (isset($pmw_options['shop']['disable_admin_trial_promo']) && true === $pmw_options['shop']['disable_admin_trial_promo']) {
			return false;
		}

		return true;
	}

	/**
	 * Check if admin notifications are active.
	 *
	 * @return bool
	 * @since 1.55.0
	 */
	private static function is_admin_notifications_active() {
		$pmw_options = get_option(PMW_DB_OPTIONS_NAME);

		if (isset($pmw_options['shop']['admin_notifications_enabled']) && false === $pmw_options['shop']['admin_notifications_enabled']) {
			return false;
		}

		return true;
	}

	/**
	 * Check if the first show delay has passed (24 hours after plugin activation).
	 *
	 * Uses Freemius install timestamp to determine when the plugin was first activated.
	 *
	 * @return bool
	 * @since 1.55.0
	 */
	private static function has_first_show_delay_passed() {
		$fs      = wpm_fs();
		$storage = $fs->get_storage();

		// Use Freemius install timestamp if available
		if (isset($storage->install_timestamp)) {
			return time() > ( $storage->install_timestamp + DAY_IN_SECONDS );
		}

		// Fallback: assume delay has passed if we can't determine install time
		return true;
	}
}
