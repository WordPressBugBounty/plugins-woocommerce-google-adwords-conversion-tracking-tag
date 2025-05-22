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
 *  TODO: TikTok EAPI
 *  TODO: Newsletter subscription
 *  TODO: Upgrade to Premium version
 *  TODO: Gateway accuracy warning
 *  TODO: Detect WooCommerce GA Integration (rule, only if one, GA3 or GA4 are enabled)
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

		foreach (self::get_opportunities() as $opportunity) {
			if ($opportunity::is_not_dismissed()) {

				$opportunity::output_card();
			}
		}
	}

	private static function opportunities_dismissed() {
		foreach (self::get_opportunities() as $opportunity) {
			if ($opportunity::is_dismissed()) {
				$opportunity::output_card();
			}
		}
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
						<div class="opportunity-card-top-impact-level">
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
								<?php echo esc_html($description); ?>
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

