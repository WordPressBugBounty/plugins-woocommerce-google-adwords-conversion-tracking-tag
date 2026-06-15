<?php

namespace SweetCode\Pixel_Manager\Admin\Notifications;

use SweetCode\Pixel_Manager\Admin\Admin;
use SweetCode\Pixel_Manager\Admin\Environment;

defined('ABSPATH') || exit; // Exit if accessed directly

/**
 * Nova WordPress Version Notification
 *
 * Shown on installs whose WordPress core is too old to run the Nova
 * interface. Those sites are pinned to the Classic UI by the version
 * gate in Admin::theme_with_build_fallback(), and new plugin releases
 * require WordPress 6.2 or newer, so this warning tells the merchant
 * to upgrade WordPress. Intentionally not dismissible: it disappears
 * on its own once WordPress is upgraded.
 *
 * @since 1.59.0
 */
class Nova_Wp_Version_Notification extends Notification {

	/**
	 * Check if the notification should be shown.
	 *
	 * Only on the PMW settings page (not the WP dashboard), and only
	 * when the WordPress core is too old to run Nova.
	 *
	 * @return bool
	 * @since 1.59.0
	 */
	public static function should_notify() {

		if (!Environment::is_pmw_settings_page()) {
			return false;
		}

		return !Admin::nova_wp_version_supported();
	}

	/**
	 * Get the notification data.
	 *
	 * @return array
	 * @since 1.59.0
	 */
	public static function notification_data() {
		return [
			'id'          => 'nova-wp-version-upgrade-required',
			'title'       => __('WordPress upgrade required for Pixel Manager updates', 'woocommerce-google-adwords-conversion-tracking-tag'),
			'description' => [
				__('The Pixel Manager will soon switch to the new Nova interface by default, which requires a newer WordPress version.', 'woocommerce-google-adwords-conversion-tracking-tag'),
			],
			'importance'  => __('High', 'woocommerce-google-adwords-conversion-tracking-tag'),
		];
	}

	/**
	 * Output the custom warning HTML.
	 *
	 * Overrides the parent card layout: this is a plain, always-visible
	 * warning without a dismiss button.
	 *
	 * @since 1.59.0
	 */
	public static function output_notification() {

		if (static::not_available()) {
			return;
		}

		?>
		<div id="pmw-nova-wp-version-notification"
			class="notice notice-warning pmw nova-wp-version-notification"
			style="padding: 12px 16px;">
			<div style="color: black; margin-bottom: 8px;">
				<strong style="font-size: 14px;">
					⚠️ <?php esc_html_e('Please upgrade WordPress to keep the Pixel Manager up to date', 'woocommerce-google-adwords-conversion-tracking-tag'); ?>
				</strong>
			</div>
			<div style="color: #444; margin-bottom: 10px;">
				<?php
				printf(
					/* translators: 1: minimum required WordPress version, 2: the site's current WordPress version */
					esc_html__('The Pixel Manager will soon make its new Nova interface the default for all installs. Nova, and all upcoming Pixel Manager releases, require WordPress %1$s or newer, but this site runs WordPress %2$s. Until WordPress is upgraded, this site keeps the Classic interface and will no longer receive Pixel Manager updates, including security and tracking fixes.', 'woocommerce-google-adwords-conversion-tracking-tag'),
					esc_html(Admin::NOVA_MIN_WP_VERSION),
					esc_html(get_bloginfo('version'))
				);
				?>
			</div>
			<a href="<?php echo esc_url(self_admin_url('update-core.php')); ?>"
				style="text-decoration: none; box-shadow: none;">
				<div class="button button-primary" style="margin: 2px 0 6px;">
					<?php esc_html_e('Update WordPress', 'woocommerce-google-adwords-conversion-tracking-tag'); ?>
				</div>
			</a>
		</div>
		<?php
	}
}
