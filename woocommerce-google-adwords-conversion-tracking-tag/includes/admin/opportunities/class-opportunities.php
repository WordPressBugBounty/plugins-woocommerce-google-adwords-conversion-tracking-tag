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

	public static function card_html( $card_data, $custom_middle_html = null ) {

		$main_card_classes = [
			'opportunity-card',
		];

		if ($card_data['dismissed']) {
			$main_card_classes[] = 'dismissed';
		}

		?>
		<div class="pmw">
			<div id="pmw-opportunity-<?php echo esc_html($card_data['id']); ?>"
				 class="<?php echo esc_html(implode(' ', $main_card_classes)); ?>"
			>
				<!-- top -->
				<div class="opportunity-card-top">
					<div><b><?php echo esc_html($card_data['title']); ?></b></div>
					<div class="opportunity-card-top-right">
						<div class="opportunity-card-top-impact">
							<?php esc_html_e('Impact', 'woocommerce-google-adwords-conversion-tracking-tag'); ?>:
						</div>
						<div class="opportunity-card-top-impact-level impact-<?php echo esc_attr(strtolower($card_data['impact'])); ?>">
							<?php echo esc_html($card_data['impact']); ?>
						</div>
					</div>
				</div>

				<hr class="opportunity-card-hr">

				<!-- middle -->
				<div class="opportunity-card-middle">

					<?php if (!empty($custom_middle_html)) : ?>
						<?php echo esc_html($custom_middle_html); ?>
					<?php else : ?>
						<?php foreach ($card_data['description'] as $description) : ?>
							<p class="opportunity-card-description">
								<?php echo wp_kses_post($description); ?>
							</p>
						<?php endforeach; ?>
					<?php endif; ?>

				</div>

				<hr class="opportunity-card-hr">

				<!-- bottom -->
				<div class="opportunity-card-bottom">

					<?php if (isset($card_data['setup_video'])) : ?>
						<!-- Video Link-->
						<div>
							<script>
								var script   = document.createElement("script");
								script.async = true;
								script.src   = 'https://fast.wistia.com/embed/medias/<?php echo esc_html($card_data['setup_video']); ?>.jsonp';
								document.getElementsByTagName("head")[0].appendChild(script);
							</script>

							<div class="opportunities wistia_embed wistia_async_<?php echo esc_html($card_data['setup_video']); ?> popover=true popoverContent=link videoFoam=false"
								 style="display:inline-block;height:123;position:relative;width:150;text-decoration: none; vertical-align: top;">
								<span class="dashicons dashicons-video-alt3" style="font-size: 36px"></span>
							</div>
						</div>
					<?php endif; ?>

					<?php if (isset($card_data['setup_link'])) : ?>
						<!-- Setup Link-->
						<a class="opportunity-card-button-link"
						   href="<?php echo esc_html($card_data['setup_link']); ?>"
						   target="_blank"
						>
							<div class="opportunity-card-bottom-button">
								<?php esc_html_e('Setup', 'woocommerce-google-adwords-conversion-tracking-tag'); ?>
							</div>
						</a>
					<?php endif; ?>

					<?php if (isset($card_data['custom_buttons']) && is_array($card_data['custom_buttons'])) : ?>
						<?php foreach ($card_data['custom_buttons'] as $button) : ?>
							<!-- Custom Button -->
							<a class="opportunity-card-button-link <?php echo isset($button['class']) ? esc_attr($button['class']) : ''; ?>"
							   href="<?php echo isset($button['url']) ? esc_url($button['url']) : '#'; ?>"
								<?php if (isset($button['target'])) : ?>
									target="<?php echo esc_attr($button['target']); ?>"
								<?php endif; ?>
								<?php if (isset($button['data_attributes']) && is_array($button['data_attributes'])) : ?>
									<?php foreach ($button['data_attributes'] as $attr_name => $attr_value) : ?>
										data-<?php echo esc_attr($attr_name); ?>="<?php echo esc_attr($attr_value); ?>"
									<?php endforeach; ?>
								<?php endif; ?>
							>
								<div class="opportunity-card-bottom-button">
									<?php echo esc_html($button['label']); ?>
								</div>
							</a>
						<?php endforeach; ?>
					<?php endif; ?>


					<?php if (isset($card_data['learn_more_link'])) : ?>
						<!-- Learn More Link-->
						<a class="opportunity-card-button-link"
						   href="<?php echo esc_html($card_data['learn_more_link']); ?>"
						   target="_blank"
						>
							<div class="opportunity-card-bottom-button">
								<?php esc_html_e('Learn more', 'woocommerce-google-adwords-conversion-tracking-tag'); ?>
							</div>
						</a>
					<?php endif; ?>

					<?php if (empty($card_data['dismissed'])) : ?>
						<!-- Dismiss Link-->
						<a class="opportunity-card-button-link"
						   href="#"
						>
							<div class="opportunity-dismiss opportunity-card-bottom-button"
								 data-opportunity-id="<?php echo esc_html($card_data['id']); ?>">
								<?php esc_html_e('Dismiss', 'woocommerce-google-adwords-conversion-tracking-tag'); ?>
							</div>
						</a>
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
					$count++;
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
						$counts[$impact]++;
					} else {
						$counts['low']++;
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
					$count++;
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

