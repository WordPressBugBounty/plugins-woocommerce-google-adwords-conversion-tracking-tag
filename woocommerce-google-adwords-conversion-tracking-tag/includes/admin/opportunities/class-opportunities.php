<?php

/**
 * Class Opportunities
 *
 * Show opportunities in a PMW tab
 *
 * @package PMW
 * @since   1.27.11
 *
 * Available opportunities
 *          pro
 *            Meta CAPI
 *            Google Ads Enhanced Conversions
 *            Google Ads Conversion Adjustments
 *            Pinterest Enhanced Match
 *            Subscription Multiplier
 *
 *          free
 *            Dynamic Remarketing
 *            Dynamic Remarketing Variations Output
 *            Google Ads Conversion Cart Data
 *
 *  TODO: Newsletter subscription
 *  TODO: Upgrade to Premium version
 *  TODO: Detect MonsterInsights
 *  TODO: Detect Tatvic
 *  TODO: Detect WooCommerce Conversion Tracking
 *  TODO: Opportunity to use the SweetCode Google Automated Discounts plugin
 *
 */

namespace SweetCode\Pixel_Manager\Admin\Opportunities;

use SweetCode\Pixel_Manager\Helpers;

defined('ABSPATH') || exit; // Exit if accessed directly

/**
 * Class Opportunities
 *
 * Manages the opportunities tab.
 * Contains HTML templates.
 *
 * @package SweetCode\Pixel_Manager\Admin
 * @since   1.28.0
 */
class Opportunities {

	public static $pmw_opportunities_option = 'pmw_opportunities';

	public static function html() {
		?>
		<div>
			<div>
				<p>
					<?php esc_html_e('Opportunities show how you could tweak the plugin settings to get more out of the Pixel Manager.', 'woocommerce-google-adwords-conversion-tracking-tag'); ?>
				</p>
			</div>

			<?php self::html_header(); ?>

			<div>
				<h2>
					<?php esc_html_e('Available Opportunities', 'woocommerce-google-adwords-conversion-tracking-tag'); ?></h2>
			</div>

			<!-- Opportunities -->

			<?php self::opportunities_not_dismissed(); ?>

			<div>
				<h2>
					<?php esc_html_e('Dismissed Opportunities', 'woocommerce-google-adwords-conversion-tracking-tag'); ?></h2>
			</div>
			<div id="pmw-dismissed-opportunities">
				<?php self::opportunities_dismissed(); ?>
			</div>
		</div>
		<?php
	}

	private static function opportunities_not_dismissed() {

		$opportunities = [];

		foreach (self::get_opportunities() as $opportunity) {
			if ($opportunity::is_not_dismissed()) {
				$opportunities[] = $opportunity;
			}
		}

		// Sort by impact: high first, then medium, then low
		$opportunities = self::sort_opportunities_by_impact($opportunities);

		foreach ($opportunities as $opportunity) {
			$opportunity::output_card();
		}
	}

	private static function opportunities_dismissed() {

		$opportunities = [];

		foreach (self::get_opportunities() as $opportunity) {
			if ($opportunity::is_dismissed()) {
				$opportunities[] = $opportunity;
			}
		}

		// Sort by impact: high first, then medium, then low
		$opportunities = self::sort_opportunities_by_impact($opportunities);

		foreach ($opportunities as $opportunity) {
			$opportunity::output_card();
		}
	}

	/**
	 * Sort opportunities by impact level (high → medium → low).
	 *
	 * @param array $opportunities Array of opportunity class names.
	 * @return array Sorted array of opportunity class names.
	 * @since 1.48.0
	 */
	private static function sort_opportunities_by_impact( $opportunities ) {

		$impact_order = [
			'high'   => 1,
			'medium' => 2,
			'low'    => 3,
		];

		usort($opportunities, function ( $a, $b ) use ( $impact_order ) {
			$a_card_data = $a::card_data();
			$b_card_data = $b::card_data();

			$a_impact = strtolower(isset($a_card_data['impact']) ? $a_card_data['impact'] : 'low');
			$b_impact = strtolower(isset($b_card_data['impact']) ? $b_card_data['impact'] : 'low');

			$a_order = isset($impact_order[$a_impact]) ? $impact_order[$a_impact] : 4;
			$b_order = isset($impact_order[$b_impact]) ? $impact_order[$b_impact] : 4;

			return $a_order - $b_order;
		});

		return $opportunities;
	}

	/**
	 * Get emoji based on impact level.
	 *
	 * @param string $impact The impact level (high, medium, low).
	 * @return string The corresponding emoji.
	 * @since 1.56.0
	 */
	private static function get_impact_emoji( $impact ) {
		$impact_lower = strtolower($impact);

		switch ($impact_lower) {
			case 'high':
				return '🚀';
			case 'medium':
				return '📈';
			case 'low':
				return '💡';
			default:
				return '💡';
		}
	}

	/**
	 * Get border color based on impact level.
	 *
	 * @param string $impact The impact level (high, medium, low).
	 * @return string The corresponding hex color.
	 * @since 1.56.0
	 */
	private static function get_impact_border_color( $impact ) {
		$impact_lower = strtolower($impact);

		switch ($impact_lower) {
			case 'high':
				return '#8b5cf6';
			case 'medium':
				return '#3b82f6';
			case 'low':
				return '#14b8a6';
			default:
				return '#2271b1';
		}
	}

	public static function card_html( $card_data, $custom_middle_html = null ) {

		$is_dismissed      = !empty($card_data['dismissed']);
		$impact            = isset($card_data['impact']) ? $card_data['impact'] : 'low';
		$impact_lower      = strtolower($impact);
		$emoji             = self::get_impact_emoji($impact);
		$border_color      = self::get_impact_border_color($impact);
		$dismissed_opacity = $is_dismissed ? 'opacity: 0.7;' : '';

		?>
		<div class="pmw">
			<div id="pmw-opportunity-<?php echo esc_attr($card_data['id']); ?>"
				class="notice notice-info inline opportunity-card-modern <?php echo $is_dismissed ? 'dismissed' : ''; ?>"
				style="padding: 0; display: flex; flex-direction: column; border-left-color: <?php echo esc_attr($border_color); ?>; margin: 10px 0; border-radius: 5px; <?php echo esc_attr($dismissed_opacity); ?>">

				<!-- Top: Title and Impact Badge -->
				<div style="display: flex; align-items: center; justify-content: space-between; flex-wrap: wrap; gap: 8px; padding: 12px 16px;">
					<strong style="font-size: 14px; color: #1e1e1e;">
						<?php echo esc_html($emoji); ?> <?php echo esc_html($card_data['title']); ?>
					</strong>
					<!-- Impact badge -->
					<span class="opportunity-card-top-impact-level impact-<?php echo esc_attr($impact_lower); ?>"
							style="display: inline-flex; align-items: center; padding: 4px 10px; border-radius: 3px; font-size: 12px; font-weight: 500;">
						<?php
						/* translators: %s: the impact level (High, Medium, Low) */
						printf(esc_html__('Impact: %s', 'woocommerce-google-adwords-conversion-tracking-tag'), esc_html(ucfirst($impact)));
						?>
					</span>
				</div>

				<hr style="margin: 0; border: none; border-top: 1px solid #ddd;">

				<!-- Middle: Description -->
				<div style="padding: 12px 16px; color: #444; line-height: 1.5;">
					<?php if (!empty($custom_middle_html)) : ?>
						<?php echo wp_kses_post($custom_middle_html); ?>
					<?php else : ?>
						<?php foreach ($card_data['description'] as $index => $description) : ?>
							<span><?php echo wp_kses_post($description); ?></span>
							<?php if ($index < count($card_data['description']) - 1) : ?>
								<br><br>
							<?php endif; ?>
						<?php endforeach; ?>
					<?php endif; ?>
				</div>

				<hr style="margin: 0; border: none; border-top: 1px solid #ddd;">

				<!-- Bottom: Action buttons (aligned right) -->
				<div style="display: flex; align-items: center; justify-content: flex-end; gap: 10px; flex-wrap: wrap; padding: 12px 16px;">
					<?php if (isset($card_data['setup_video'])) : ?>
						<!-- Video Link -->
						<script>
							var script   = document.createElement("script");
							script.async = true;
							script.src   = 'https://fast.wistia.com/embed/medias/<?php echo esc_attr($card_data['setup_video']); ?>.jsonp';
							document.getElementsByTagName("head")[0].appendChild(script);
						</script>
						<div class="opportunities wistia_embed wistia_async_<?php echo esc_attr($card_data['setup_video']); ?> popover=true popoverContent=link videoFoam=false"
							style="display: inline-flex; align-items: center; text-decoration: none; cursor: pointer;">
							<span class="dashicons dashicons-video-alt3" style="font-size: 20px; width: 20px; height: 20px; margin-right: 4px;"></span>
							<span style="color: #2271b1;"><?php esc_html_e('Watch Video', 'woocommerce-google-adwords-conversion-tracking-tag'); ?></span>
						</div>
					<?php endif; ?>

					<?php if (isset($card_data['setup_link'])) : ?>
						<!-- Setup Link -->
						<a href="<?php echo esc_url($card_data['setup_link']); ?>"
							target="_blank"
							style="text-decoration: none; box-shadow: none;">
							<div class="button button-primary" style="margin: 0;">
								<?php esc_html_e('Setup', 'woocommerce-google-adwords-conversion-tracking-tag'); ?> ⚙️
							</div>
						</a>
					<?php endif; ?>

					<?php if (isset($card_data['custom_buttons']) && is_array($card_data['custom_buttons'])) : ?>
						<?php foreach ($card_data['custom_buttons'] as $button) : ?>
							<!-- Custom Button -->
							<a class="<?php echo isset($button['class']) ? esc_attr($button['class']) : ''; ?>"
								href="<?php echo isset($button['url']) ? esc_url($button['url']) : '#'; ?>"
								<?php if (isset($button['target'])) : ?>
									target="<?php echo esc_attr($button['target']); ?>"
								<?php endif; ?>
								<?php if (isset($button['data_attributes']) && is_array($button['data_attributes'])) : ?>
									<?php foreach ($button['data_attributes'] as $attr_name => $attr_value) : ?>
										data-<?php echo esc_attr($attr_name); ?>="<?php echo esc_attr($attr_value); ?>"
									<?php endforeach; ?>
								<?php endif; ?>
								style="text-decoration: none; box-shadow: none;">
								<div class="button" style="margin: 0;">
									<?php echo esc_html($button['label']); ?>
								</div>
							</a>
						<?php endforeach; ?>
					<?php endif; ?>

					<?php if (isset($card_data['learn_more_link'])) : ?>
						<!-- Learn More Link -->
						<a href="<?php echo esc_url($card_data['learn_more_link']); ?>"
							target="_blank"
							style="text-decoration: none; box-shadow: none;">
							<div class="button" style="margin: 0;">
								<?php esc_html_e('Learn more', 'woocommerce-google-adwords-conversion-tracking-tag'); ?>
							</div>
						</a>
					<?php endif; ?>

					<?php if (!$is_dismissed) : ?>
						<!-- Dismiss Link -->
						<div class="button opportunity-dismiss"
							style="margin: 0;"
							data-opportunity-id="<?php echo esc_attr($card_data['id']); ?>">
							<?php esc_html_e('Dismiss', 'woocommerce-google-adwords-conversion-tracking-tag'); ?>
						</div>
					<?php endif; ?>
				</div>
			</div>
		</div>
		<?php
	}

	/**
	 * Load all opportunity classes.
	 *
	 * =================== IMPORTANT ===================
	 * Don't make it public, as this could be used for file inclusion attacks.
	 * ================================================
	 */
	private static function load_all_opportunity_classes() {
		// Base directories to scan
		$dirs = [
			__DIR__ . '/free',
			__DIR__ . '/pro',
		];

		foreach ($dirs as $dir) {
			$scan = glob("$dir/*");
			foreach ($scan as $path) {
				if (preg_match('/\.php$/', $path)) {
					require_once $path;
				}
				// No recursive calls to subdirectories
			}
		}
	}

	/**
	 * Public accessor for the Mantine admin UI to trigger class loading.
	 *
	 * This is safe because it delegates to the private loader that only
	 * scans hardcoded directories (free/ and pro/ under this file's dir).
	 *
	 * @since 1.58.8
	 */
	public static function load_all_opportunity_classes_for_mantine() {
		self::load_all_opportunity_classes();
	}


	private static function get_opportunities() {

		self::load_all_opportunity_classes();

		$classes = get_declared_classes();

		$opportunities = [];

		foreach ($classes as $class) {
			if (is_subclass_of($class, 'SweetCode\Pixel_Manager\Admin\Opportunities\Opportunity')) {
				$opportunities[] = $class;
			}
		}

		return $opportunities;
	}

	public static function active_opportunities_available() {

		// get pmw_opportunities option
		$option = get_option(self::$pmw_opportunities_option);

		foreach (self::get_opportunities() as $opportunity) {
			if (class_exists($opportunity)) {
				if (
					$opportunity::available()
					&& $opportunity::is_not_dismissed()
					&& $opportunity::is_newer_than_dismissed_dashboard_time($option)
				) {
					return true;
				}
			}
		}

		return false;
	}

	/**
	 * Check if any available, non-dismissed opportunity has a `since`
	 * timestamp newer than the given timestamp.
	 *
	 * Used by the dashboard notification to re-show when new opportunities
	 * are added after a user previously dismissed the notification.
	 *
	 * @param int $timestamp Unix timestamp to compare against.
	 *
	 * @return bool True if at least one qualifying opportunity is newer.
	 * @since 1.57.1
	 */
	public static function has_opportunities_newer_than( $timestamp ) {

		foreach ( self::get_opportunities() as $opportunity ) {
			if ( class_exists( $opportunity ) ) {
				if (
					$opportunity::available()
					&& $opportunity::is_not_dismissed()
				) {
					$card_data = $opportunity::card_data();
					if ( isset( $card_data['since'] ) && $card_data['since'] > $timestamp ) {
						return true;
					}
				}
			}
		}

		return false;
	}

	/**
	 * Count the number of active (non-dismissed) opportunities.
	 *
	 * @return int The count of active opportunities.
	 * @since 1.53.0
	 */
	public static function get_active_opportunities_count() {

		$count = 0;

		foreach (self::get_opportunities() as $opportunity) {
			if (class_exists($opportunity)) {
				if (
					$opportunity::available()
					&& $opportunity::is_not_dismissed()
				) {
					++$count;
				}
			}
		}

		return $count;
	}

	/**
	 * Count active opportunities grouped by impact level.
	 *
	 * @return array Associative array with 'high', 'medium', 'low' counts.
	 * @since 1.53.0
	 */
	public static function get_active_opportunities_by_impact() {

		$counts = [
			'high'   => 0,
			'medium' => 0,
			'low'    => 0,
		];

		foreach (self::get_opportunities() as $opportunity) {
			if (class_exists($opportunity)) {
				if (
					$opportunity::available()
					&& $opportunity::is_not_dismissed()
				) {
					$card_data = $opportunity::card_data();
					$impact    = strtolower(isset($card_data['impact']) ? $card_data['impact'] : 'low');

					if (isset($counts[$impact])) {
						++$counts[$impact];
					} else {
						++$counts['low'];
					}
				}
			}
		}

		return $counts;
	}

	/**
	 * Count the number of dismissed opportunities.
	 *
	 * @return int The count of dismissed opportunities.
	 * @since 1.48.0
	 */
	public static function get_dismissed_opportunities_count() {

		$count = 0;

		foreach (self::get_opportunities() as $opportunity) {
			if (class_exists($opportunity)) {
				if (
					$opportunity::available()
					&& $opportunity::is_dismissed()
				) {
					++$count;
				}
			}
		}

		return $count;
	}

	/**
	 * Render the statistics header for the Opportunities tab.
	 *
	 * @since 1.48.0
	 */
	private static function html_header() {

		$impact_counts   = self::get_active_opportunities_by_impact();
		$total_active    = array_sum($impact_counts);
		$dismissed_count = self::get_dismissed_opportunities_count();

		?>
		<div class="pmw">
			<div class="pmw-opportunities-header">
				<?php if (0 === $total_active) : ?>
					<div class="pmw-opportunities-complete">
						<div class="pmw-opportunities-complete-icon">🎉</div>
						<div class="pmw-opportunities-complete-content">
							<div class="pmw-opportunities-complete-title">
								<?php esc_html_e('All caught up!', 'woocommerce-google-adwords-conversion-tracking-tag'); ?>
							</div>
							<div class="pmw-opportunities-complete-text">
								<?php esc_html_e('You have addressed all available opportunities. Great job optimizing your tracking setup!', 'woocommerce-google-adwords-conversion-tracking-tag'); ?>
							</div>
						</div>
						<?php if ($dismissed_count > 0) : ?>
							<div class="pmw-stat-card dismissed">
								<div class="pmw-stat-card-count"><?php echo esc_html($dismissed_count); ?></div>
								<div class="pmw-stat-card-label"><?php esc_html_e('Dismissed', 'woocommerce-google-adwords-conversion-tracking-tag'); ?></div>
							</div>
						<?php endif; ?>
					</div>
				<?php else : ?>
					<div class="pmw-stat-card total">
						<div class="pmw-stat-card-count"><?php echo esc_html($total_active); ?></div>
						<div class="pmw-stat-card-label"><?php esc_html_e('Available', 'woocommerce-google-adwords-conversion-tracking-tag'); ?></div>
					</div>
					<div class="pmw-stat-cards-impact">
						<div class="pmw-stat-card impact-high">
							<div class="pmw-stat-card-count"><?php echo esc_html($impact_counts['high']); ?></div>
							<div class="pmw-stat-card-label"><?php esc_html_e('High', 'woocommerce-google-adwords-conversion-tracking-tag'); ?></div>
						</div>
						<div class="pmw-stat-card impact-medium">
							<div class="pmw-stat-card-count"><?php echo esc_html($impact_counts['medium']); ?></div>
							<div class="pmw-stat-card-label"><?php esc_html_e('Medium', 'woocommerce-google-adwords-conversion-tracking-tag'); ?></div>
						</div>
						<div class="pmw-stat-card impact-low">
							<div class="pmw-stat-card-count"><?php echo esc_html($impact_counts['low']); ?></div>
							<div class="pmw-stat-card-label"><?php esc_html_e('Low', 'woocommerce-google-adwords-conversion-tracking-tag'); ?></div>
						</div>
					</div>
					<?php if ($dismissed_count > 0) : ?>
						<div class="pmw-stat-card dismissed">
							<div class="pmw-stat-card-count"><?php echo esc_html($dismissed_count); ?></div>
							<div class="pmw-stat-card-label"><?php esc_html_e('Dismissed', 'woocommerce-google-adwords-conversion-tracking-tag'); ?></div>
						</div>
					<?php endif; ?>
				<?php endif; ?>
			</div>
		</div>
		<?php
	}

	public static function dismiss_opportunity( $opportunity_id ) {

		$option = get_option(self::$pmw_opportunities_option);

		if (empty($option)) {
			$option = [];
		}

		$option[$opportunity_id]['dismissed'] = time();

		update_option(self::$pmw_opportunities_option, $option);

		wp_send_json_success();
	}
}

