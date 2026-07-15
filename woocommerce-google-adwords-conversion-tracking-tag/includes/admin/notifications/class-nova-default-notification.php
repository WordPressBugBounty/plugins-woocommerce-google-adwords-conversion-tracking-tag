<?php

namespace SweetCode\Pixel_Manager\Admin\Notifications;

use SweetCode\Pixel_Manager\Admin\Admin;
use SweetCode\Pixel_Manager\Admin\Environment;
use SweetCode\Pixel_Manager\Options;

defined('ABSPATH') || exit; // Exit if accessed directly

/**
 * Nova Default Announcement
 *
 * Shown once, above the Nova UI, on installs that predate Nova (no
 * fresh-install marker). Since 1.62.0 Nova is the default interface for
 * every install, so these installs were switched from Classic to Nova on
 * update. The announcement explains that nothing about their settings or
 * tracking changed and offers a one-click way back to the Classic
 * interface, persisted per user.
 *
 * @since 1.62.0
 */
class Nova_Default_Notification extends Notification {

	/**
	 * Check if the notification should be shown.
	 *
	 * Requirements:
	 * - On the PMW settings page
	 * - The Nova UI is the one being rendered (if the user already switched
	 *   back to Classic, the announcement has done its job)
	 * - The install predates Nova (fresh installs started on Nova, nothing
	 *   changed for them)
	 *
	 * @return bool
	 * @since 1.62.0
	 */
	public static function should_notify() {

		if (!Environment::is_pmw_settings_page()) {
			return false;
		}

		if (!Admin::is_wp_admin_active()) {
			return false;
		}

		if (get_option(Options::$default_admin_theme_option_name)) {
			return false;
		}

		return true;
	}

	/**
	 * Get the notification data.
	 *
	 * @return array
	 * @since 1.62.0
	 */
	public static function notification_data() {
		return [
			'id'              => 'nova-default-announcement',
			'title'           => __('Nova is now the default Pixel Manager interface', 'woocommerce-google-adwords-conversion-tracking-tag'),
			'description'     => [
				__('Your settings and your tracking are exactly as they were; only the look of this page has changed.', 'woocommerce-google-adwords-conversion-tracking-tag'),
			],
			'importance'      => __('Medium', 'woocommerce-google-adwords-conversion-tracking-tag'),
			'learn_more_link' => 'https://sweetcode.com/blog/introducing-nova-new-pixel-manager-interface?utm_source=plugin&utm_medium=notification&utm_campaign=nova-default',
		];
	}

	/**
	 * Output the custom announcement HTML.
	 * Overrides parent to use the custom design matching the other PMW
	 * notifications.
	 *
	 * @since 1.62.0
	 */
	public static function output_notification() {

		if (static::not_available()) {
			return;
		}

		$notification_data = static::notification_data();

		// The same query param the theme switcher uses; the choice is
		// persisted per user (see Admin::persist_theme_choice()).
		$classic_url = add_query_arg('pmw_theme', 'classic');

		?>
		<div id="pmw-nova-default-notification"
			class="notice notice-info pmw nova-default-notification"
			style="padding: 12px 16px; display: flex; flex-direction: row; justify-content: space-between; align-items: flex-start; border-left-color: #5b3fd9;">
			<div>
				<div style="color: black; margin-bottom: 8px;">
					<strong style="font-size: 14px;">
						✨ <?php esc_html_e('Nova is now the default Pixel Manager interface', 'woocommerce-google-adwords-conversion-tracking-tag'); ?>
					</strong>
				</div>
				<div style="color: #444; margin-bottom: 12px; max-width: 720px;">
					<?php esc_html_e('The Pixel Manager settings now open in Nova, the rebuilt interface that is faster and cleaner. Your settings and your tracking are exactly as they were; only the look of this page has changed. If you prefer the previous interface, one click takes you back, and your choice is remembered for your user account.', 'woocommerce-google-adwords-conversion-tracking-tag'); ?>
				</div>

				<a href="<?php echo esc_url($classic_url); ?>"
					style="text-decoration: none; box-shadow: none;">
					<div class="button" style="margin: 0;">
						<?php esc_html_e('Switch back to Classic', 'woocommerce-google-adwords-conversion-tracking-tag'); ?>
					</div>
				</a>

				<a href="<?php echo esc_url($notification_data['learn_more_link']); ?>"
					target="_blank"
					style="margin-left: 12px; color: #2271b1; text-decoration: none;">
					<?php esc_html_e('Read the announcement', 'woocommerce-google-adwords-conversion-tracking-tag'); ?>
				</a>
			</div>

			<div style="text-align: right; display: flex; flex-direction: column;">
				<div id="pmw-dismiss-nova-default-button"
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
