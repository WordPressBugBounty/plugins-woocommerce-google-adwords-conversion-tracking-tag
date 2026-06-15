<?php

namespace SweetCode\Pixel_Manager\Admin\Notifications;

use SweetCode\Pixel_Manager\Admin\Admin;
use SweetCode\Pixel_Manager\Admin\Environment;

defined('ABSPATH') || exit; // Exit if accessed directly

/**
 * Nova Promotion Notification
 *
 * Shown at the top of the Classic Pixel Manager UI during the Nova
 * transition phase. Existing installs keep the Classic UI by default,
 * and this notification invites them to try the new Nova interface
 * with a one-click switch.
 *
 * @since 1.59.0
 */
class Nova_Promotion_Notification extends Notification {

	/**
	 * Check if the notification should be shown.
	 *
	 * Requirements:
	 * - On the PMW settings page (this announces the new UI, right above the old one)
	 * - The Classic UI is the one being rendered
	 * - The Nova build output is available to switch to
	 *
	 * @return bool
	 * @since 1.59.0
	 */
	public static function should_notify() {

		if (!Environment::is_pmw_settings_page()) {
			return false;
		}

		// Already on Nova, nothing to promote.
		if (Admin::is_wp_admin_active()) {
			return false;
		}

		// Nova can't run on this WordPress core. The WP version upgrade
		// notification handles the messaging instead.
		if (!Admin::nova_wp_version_supported()) {
			return false;
		}

		// The switch button would silently fall back to Classic without the build.
		if (!file_exists(plugin_dir_path(PMW_PLUGIN_FILE) . 'js/admin/wp/pmw-admin-wp.js')) {
			return false;
		}

		return true;
	}

	/**
	 * Get the notification data.
	 *
	 * @return array
	 * @since 1.59.0
	 */
	public static function notification_data() {
		return [
			'id'              => 'nova-theme-promotion',
			'title'           => __('Meet Nova: the new Pixel Manager interface', 'woocommerce-google-adwords-conversion-tracking-tag'),
			'description'     => [
				__('We rebuilt the Pixel Manager interface from the ground up. Nova is faster, cleaner, and already the default on new installs.', 'woocommerce-google-adwords-conversion-tracking-tag'),
			],
			'importance'      => __('High', 'woocommerce-google-adwords-conversion-tracking-tag'),
			'learn_more_link' => 'https://sweetcode.com/blog/introducing-nova-new-pixel-manager-interface?utm_source=plugin&utm_medium=notification&utm_campaign=nova',
		];
	}

	/**
	 * Output the custom Nova promotion notification HTML.
	 * Overrides parent to use the custom design matching the trial promotion
	 * and opportunities notifications.
	 *
	 * @since 1.59.0
	 */
	public static function output_notification() {

		if (static::not_available()) {
			return;
		}

		$notification_data = static::notification_data();

		// One click in, one click out: the same query param the theme
		// switcher uses, persisted via the pmw_admin_theme cookie.
		$switch_url = add_query_arg('pmw_theme', 'wp');

		?>
		<div id="pmw-nova-promotion-notification"
			class="notice notice-info pmw nova-promotion-notification"
			style="padding: 12px 16px; display: flex; flex-direction: row; justify-content: space-between; align-items: flex-start; border-left-color: #5b3fd9;">
			<div>
				<div style="color: black; margin-bottom: 8px;">
					<strong style="font-size: 14px;">
						✨ <?php esc_html_e('Meet Nova: the new Pixel Manager interface', 'woocommerce-google-adwords-conversion-tracking-tag'); ?>
					</strong>
				</div>
				<div style="color: #444; margin-bottom: 10px;">
					<?php esc_html_e('We rebuilt the Pixel Manager interface from the ground up. Nova is faster, cleaner, and already the default on new installs, and you can switch to it right now.', 'woocommerce-google-adwords-conversion-tracking-tag'); ?>
				</div>

				<div style="display: flex; flex-wrap: wrap; gap: 8px; margin-bottom: 12px;">
					<span style="display: inline-flex; align-items: center; padding: 4px 10px; border-radius: 3px; font-size: 12px; font-weight: 500; background: #f3e8ff; border: 1px solid #8b5cf6; color: #5b21b6;">
						<span class="dashicons dashicons-yes-alt" style="font-size: 14px; width: 14px; height: 14px; margin-right: 4px;"></span>
						<?php esc_html_e('Clean, modern design', 'woocommerce-google-adwords-conversion-tracking-tag'); ?>
					</span>
					<span style="display: inline-flex; align-items: center; padding: 4px 10px; border-radius: 3px; font-size: 12px; font-weight: 500; background: #f3e8ff; border: 1px solid #8b5cf6; color: #5b21b6;">
						<span class="dashicons dashicons-yes-alt" style="font-size: 14px; width: 14px; height: 14px; margin-right: 4px;"></span>
						<?php esc_html_e('At-a-glance dashboard', 'woocommerce-google-adwords-conversion-tracking-tag'); ?>
					</span>
					<span style="display: inline-flex; align-items: center; padding: 4px 10px; border-radius: 3px; font-size: 12px; font-weight: 500; background: #f3e8ff; border: 1px solid #8b5cf6; color: #5b21b6;">
						<span class="dashicons dashicons-yes-alt" style="font-size: 14px; width: 14px; height: 14px; margin-right: 4px;"></span>
						<?php esc_html_e('Faster navigation, instant saves', 'woocommerce-google-adwords-conversion-tracking-tag'); ?>
					</span>
					<span style="display: inline-flex; align-items: center; padding: 4px 10px; border-radius: 3px; font-size: 12px; font-weight: 500; background: #f3e8ff; border: 1px solid #8b5cf6; color: #5b21b6;">
						<span class="dashicons dashicons-yes-alt" style="font-size: 14px; width: 14px; height: 14px; margin-right: 4px;"></span>
						<?php esc_html_e('Same settings, nothing to reconfigure', 'woocommerce-google-adwords-conversion-tracking-tag'); ?>
					</span>
				</div>

				<div style="color: #444; margin-bottom: 12px;">
					<?php esc_html_e('Trying Nova is completely risk-free: your settings stay exactly as they are, and a button in the Support section takes you back to the Classic interface anytime. Nova will become the default for all installs once the transition phase ends, so now is the perfect time to get ahead of it.', 'woocommerce-google-adwords-conversion-tracking-tag'); ?>
				</div>

				<a href="<?php echo esc_url($switch_url); ?>"
					style="text-decoration: none; box-shadow: none;">
					<div class="button button-primary" style="margin: 0; background: linear-gradient(135deg, #2271b1 0%, #5b3fd9 100%); border-color: #5b3fd9;">
						<?php esc_html_e('Try Nova now', 'woocommerce-google-adwords-conversion-tracking-tag'); ?> &rarr;
					</div>
				</a>

				<a href="<?php echo esc_url($notification_data['learn_more_link']); ?>"
					target="_blank"
					style="margin-left: 12px; color: #2271b1; text-decoration: none;">
					<?php esc_html_e('Read the announcement', 'woocommerce-google-adwords-conversion-tracking-tag'); ?>
				</a>
			</div>

			<div style="text-align: right; display: flex; flex-direction: column;">
				<div id="pmw-dismiss-nova-promotion-button"
					class="button pmw-notification-dismiss-button"
					style="white-space: normal; margin-bottom: 6px; text-align: center;"
					data-notification-id="<?php echo esc_attr($notification_data['id']); ?>"
				><?php esc_html_e('Dismiss', 'woocommerce-google-adwords-conversion-tracking-tag'); ?>
				</div>
			</div>
		</div>
		<?php
	}
}
