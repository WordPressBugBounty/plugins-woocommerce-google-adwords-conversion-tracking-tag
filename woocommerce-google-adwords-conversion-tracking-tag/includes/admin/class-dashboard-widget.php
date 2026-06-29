<?php

namespace SweetCode\Pixel_Manager\Admin;

use SweetCode\Pixel_Manager\Abilities;
use SweetCode\Pixel_Manager\Tracking_Accuracy_DB;

defined('ABSPATH') || exit; // Exit if accessed directly

/**
 * Registers and renders the "Pixel Manager" WordPress dashboard widget.
 *
 * The widget gives shop owners an at-a-glance overview right from the WP
 * dashboard: which integrations are live, the recent tracking accuracy, and
 * a few quick health checks, with links straight into the relevant settings.
 *
 * @since 1.59.0
 */
class Dashboard_Widget {

	private static $did_init = false;

	const WIDGET_ID = 'pmw_dashboard_overview';

	public static function init() {

		if (self::$did_init) {
			return;
		}

		self::$did_init = true;

		add_action('wp_dashboard_setup', [ __CLASS__, 'register_widget' ]);
	}

	/**
	 * Register the dashboard widget for users who can manage the shop.
	 *
	 * @return void
	 * @since 1.59.0
	 */
	public static function register_widget() {

		// Only show to users who are allowed to edit the plugin settings.
		if (!current_user_can(Environment::get_user_edit_capability())) {
			return;
		}

		wp_add_dashboard_widget(
			self::WIDGET_ID,
			esc_html__('Pixel Manager', 'woocommerce-google-adwords-conversion-tracking-tag'),
			[ __CLASS__, 'render_widget' ]
		);
	}

	/**
	 * Render the widget body.
	 *
	 * @return void
	 * @since 1.59.0
	 */
	public static function render_widget() {

		$tracking     = Abilities::execute_get_tracking_status();
		$plugin_info  = Abilities::execute_get_plugin_info();
		$summary      = isset($tracking['summary']) ? $tracking['summary'] : [];
		$active       = isset($tracking['active_pixels']) ? $tracking['active_pixels'] : [];
		$accuracy     = self::get_overall_accuracy(30);
		$settings_url = admin_url('admin.php?page=pmw');

		self::print_styles();
		?>
		<div class="pmw-dashboard-widget">

			<div class="pmw-dw-header">
				<span class="pmw-dw-tier pmw-dw-tier--<?php echo esc_attr($plugin_info['tier']); ?>">
					<?php echo 'pro' === $plugin_info['tier'] ? esc_html__('Pro', 'woocommerce-google-adwords-conversion-tracking-tag') : esc_html__('Free', 'woocommerce-google-adwords-conversion-tracking-tag'); ?>
				</span>
				<span class="pmw-dw-version">v<?php echo esc_html($plugin_info['version']); ?></span>
			</div>

			<div class="pmw-dw-stats">
				<div class="pmw-dw-stat">
					<span class="pmw-dw-stat-value"><?php echo esc_html(number_format_i18n(isset($summary['total_active']) ? $summary['total_active'] : 0)); ?></span>
					<span class="pmw-dw-stat-label"><?php esc_html_e('Active integrations', 'woocommerce-google-adwords-conversion-tracking-tag'); ?></span>
				</div>
				<div class="pmw-dw-stat">
					<?php if (null === $accuracy) : ?>
						<span class="pmw-dw-stat-value pmw-dw-muted">&mdash;</span>
					<?php else : ?>
						<span class="pmw-dw-stat-value pmw-dw-accuracy--<?php echo esc_attr(self::accuracy_level($accuracy)); ?>"><?php echo esc_html(number_format_i18n($accuracy)); ?>%</span>
					<?php endif; ?>
					<span class="pmw-dw-stat-label"><?php esc_html_e('Tracking accuracy (30 days)', 'woocommerce-google-adwords-conversion-tracking-tag'); ?></span>
				</div>
				<div class="pmw-dw-stat">
					<span class="pmw-dw-stat-value">
						<?php echo !empty($summary['has_server_side_tracking']) ? esc_html__('On', 'woocommerce-google-adwords-conversion-tracking-tag') : esc_html__('Off', 'woocommerce-google-adwords-conversion-tracking-tag'); ?>
					</span>
					<span class="pmw-dw-stat-label"><?php esc_html_e('Server-side tracking', 'woocommerce-google-adwords-conversion-tracking-tag'); ?></span>
				</div>
			</div>

			<?php if (empty($active)) : ?>
				<div class="pmw-dw-empty">
					<p><?php esc_html_e('No tracking integrations are active yet.', 'woocommerce-google-adwords-conversion-tracking-tag'); ?></p>
					<a class="button button-primary" href="<?php echo esc_url($settings_url . '#pixels'); ?>"><?php esc_html_e('Set up your first pixel', 'woocommerce-google-adwords-conversion-tracking-tag'); ?></a>
				</div>
			<?php else : ?>
				<div class="pmw-dw-section-title"><?php esc_html_e('Active integrations', 'woocommerce-google-adwords-conversion-tracking-tag'); ?></div>
				<ul class="pmw-dw-pixels">
					<?php foreach ($active as $pixel) : ?>
						<li class="pmw-dw-pixel pmw-dw-pixel--<?php echo esc_attr(isset($pixel['category']) ? $pixel['category'] : 'marketing'); ?>">
							<span class="pmw-dw-pixel-label"><?php echo esc_html($pixel['label']); ?></span>
							<?php if (!empty($pixel['server_tracking'])) : ?>
								<span class="pmw-dw-pixel-tag" title="<?php esc_attr_e('Server-side tracking', 'woocommerce-google-adwords-conversion-tracking-tag'); ?>">S2S</span>
							<?php endif; ?>
						</li>
					<?php endforeach; ?>
				</ul>
			<?php endif; ?>

			<ul class="pmw-dw-checks">
				<?php
				self::render_check(
					!empty($summary['has_marketing_pixels']),
					esc_html__('Marketing pixels active', 'woocommerce-google-adwords-conversion-tracking-tag')
				);
				self::render_check(
					!empty($summary['has_statistics_pixels']),
					esc_html__('Statistics pixels active', 'woocommerce-google-adwords-conversion-tracking-tag')
				);
				self::render_check(
					!empty($plugin_info['woocommerce_active']),
					esc_html__('WooCommerce active', 'woocommerce-google-adwords-conversion-tracking-tag')
				);
				?>
			</ul>

			<div class="pmw-dw-footer">
				<a href="<?php echo esc_url($settings_url); ?>"><?php esc_html_e('Open settings', 'woocommerce-google-adwords-conversion-tracking-tag'); ?></a>
				<a href="<?php echo esc_url($settings_url . '#diagnostics'); ?>"><?php esc_html_e('Diagnostics', 'woocommerce-google-adwords-conversion-tracking-tag'); ?></a>
			</div>

		</div>
		<?php
	}

	/**
	 * Render a single status check row with a tick or cross.
	 *
	 * @param bool   $passed Whether the check passed.
	 * @param string $label  Already-escaped label text.
	 *
	 * @return void
	 * @since 1.59.0
	 */
	private static function render_check( $passed, $label ) {
		?>
		<li class="pmw-dw-check pmw-dw-check--<?php echo $passed ? 'pass' : 'fail'; ?>">
			<span class="pmw-dw-check-icon" aria-hidden="true"><?php echo $passed ? '&#10003;' : '&#8211;'; ?></span>
			<span><?php echo esc_html($label); ?></span>
		</li>
		<?php
	}

	/**
	 * Compute the tracking accuracy across active (enabled) gateways for the last N days.
	 *
	 * Scoped to currently-enabled gateways so the widget matches the Pixel Manager
	 * dashboard's gateway-health view; inactive, off-site or legacy gateways that
	 * cannot reach the order-received page would otherwise drag the number down.
	 *
	 * @param int $days Number of days to look back.
	 *
	 * @return int|null Rounded accuracy percentage, or null when there is no data.
	 * @since 1.59.0
	 */
	private static function get_overall_accuracy( $days = 30 ) {

		if (!Tracking_Accuracy_DB::has_data()) {
			return null;
		}

		// Scope to enabled gateways; fall back to all gateways if none resolve.
		$enabled_ids = array_map(function ( $gateway ) {
			return $gateway->id;
		}, Debug_Info::get_enabled_payment_gateways());

		$rows = !empty($enabled_ids)
			? Tracking_Accuracy_DB::get_accuracy_data($days, $enabled_ids)
			: Tracking_Accuracy_DB::get_accuracy_data($days);

		if (empty($rows)) {
			return null;
		}

		$total    = 0;
		$measured = 0;

		foreach ($rows as $row) {
			$total    += isset($row['orders_total']) ? (int) $row['orders_total'] : 0;
			$measured += isset($row['orders_measured']) ? (int) $row['orders_measured'] : 0;
		}

		if ($total <= 0) {
			return null;
		}

		return (int) round(min(100, ( $measured / $total ) * 100));
	}

	/**
	 * Map an accuracy percentage to a qualitative level used for color tinting.
	 *
	 * @param int $accuracy Accuracy percentage (0-100).
	 *
	 * @return string One of 'good', 'ok', 'low'.
	 * @since 1.59.0
	 */
	private static function accuracy_level( $accuracy ) {

		if ($accuracy >= 90) {
			return 'good';
		}

		if ($accuracy >= 70) {
			return 'ok';
		}

		return 'low';
	}

	/**
	 * Print the scoped widget styles once.
	 *
	 * Kept inline so the widget stays self-contained and does not depend on the
	 * admin asset build pipeline, which only loads on the PMW settings pages.
	 *
	 * @return void
	 * @since 1.59.0
	 */
	private static function print_styles() {
		?>
		<style>
			.pmw-dashboard-widget { font-size: 13px; line-height: 1.5; }
			.pmw-dashboard-widget a { text-decoration: none; }
			.pmw-dw-header { display: flex; align-items: center; gap: 8px; margin-bottom: 12px; }
			.pmw-dw-tier { font-size: 11px; font-weight: 600; letter-spacing: .03em; text-transform: uppercase; padding: 2px 8px; border-radius: 10px; }
			.pmw-dw-tier--pro { color: #1d6b3a; background: rgba(34, 153, 84, .12); box-shadow: inset 0 0 0 1px rgba(34, 153, 84, .35); }
			.pmw-dw-tier--free { color: #555; background: rgba(120, 120, 120, .12); box-shadow: inset 0 0 0 1px rgba(120, 120, 120, .3); }
			.pmw-dw-version { color: #787c82; font-size: 12px; }
			.pmw-dw-stats { display: flex; gap: 10px; margin-bottom: 16px; }
			.pmw-dw-stat { flex: 1; padding: 10px 12px; border-radius: 6px; background: rgba(30, 100, 200, .04); box-shadow: inset 0 0 0 1px rgba(30, 100, 200, .12); }
			.pmw-dw-stat-value { display: block; font-size: 22px; font-weight: 600; color: #1d2327; }
			.pmw-dw-stat-label { display: block; margin-top: 2px; font-size: 11px; color: #646970; }
			.pmw-dw-muted { color: #c3c4c7; }
			.pmw-dw-accuracy--good { color: #1d6b3a; }
			.pmw-dw-accuracy--ok { color: #997404; }
			.pmw-dw-accuracy--low { color: #b32d2e; }
			.pmw-dw-section-title { font-size: 11px; font-weight: 600; text-transform: uppercase; letter-spacing: .03em; color: #646970; margin-bottom: 8px; }
			.pmw-dw-pixels { display: flex; flex-wrap: wrap; gap: 6px; margin: 0 0 16px; }
			.pmw-dw-pixel { display: inline-flex; align-items: center; gap: 6px; padding: 3px 10px; border-radius: 12px; font-size: 12px; background: rgba(120, 120, 120, .08); box-shadow: inset 0 0 0 1px rgba(120, 120, 120, .25); }
			.pmw-dw-pixel--marketing { background: rgba(37, 99, 235, .08); box-shadow: inset 0 0 0 1px rgba(37, 99, 235, .3); }
			.pmw-dw-pixel--statistics { background: rgba(13, 148, 136, .08); box-shadow: inset 0 0 0 1px rgba(13, 148, 136, .3); }
			.pmw-dw-pixel--optimization { background: rgba(147, 51, 234, .08); box-shadow: inset 0 0 0 1px rgba(147, 51, 234, .3); }
			.pmw-dw-pixel-tag { font-size: 9px; font-weight: 700; letter-spacing: .04em; color: #1d6b3a; background: rgba(34, 153, 84, .18); border-radius: 3px; padding: 1px 4px; }
			.pmw-dw-checks { margin: 0 0 14px; }
			.pmw-dw-check { display: flex; align-items: center; gap: 8px; padding: 3px 0; color: #1d2327; }
			.pmw-dw-check-icon { display: inline-flex; align-items: center; justify-content: center; width: 18px; height: 18px; border-radius: 50%; font-size: 11px; font-weight: 700; }
			.pmw-dw-check--pass .pmw-dw-check-icon { color: #1d6b3a; background: rgba(34, 153, 84, .15); }
			.pmw-dw-check--fail { color: #646970; }
			.pmw-dw-check--fail .pmw-dw-check-icon { color: #8a8f94; background: rgba(120, 120, 120, .15); }
			.pmw-dw-empty { padding: 14px; margin-bottom: 14px; text-align: center; border-radius: 6px; background: rgba(37, 99, 235, .04); box-shadow: inset 0 0 0 1px rgba(37, 99, 235, .12); }
			.pmw-dw-empty p { margin: 0 0 10px; color: #646970; }
			.pmw-dw-footer { display: flex; gap: 16px; padding-top: 12px; border-top: 1px solid #f0f0f1; }
		</style>
		<?php
	}
}
