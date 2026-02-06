<?php

namespace SweetCode\Pixel_Manager\Admin;

use SweetCode\Pixel_Manager\Helpers;

defined('ABSPATH') || exit; // Exit if accessed directly

class Ask_For_Rating {

	private $option_name = PMW_DB_RATINGS;

	private static $instance;

	public static function get_instance() {
		if (is_null(self::$instance)) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	public function __construct() {

//	    $options = get_option($this->option_name);
//	    $options['conversions_count'] = 8;
//	    $options['rating_threshold'] = 10;
//	    unset($options['conversion_count']);
//	    $options['rating_done'] = false;
//	    update_option($this->option_name,$options);

		// ask for a rating in a plugin notice
		add_action('admin_enqueue_scripts', [ $this, 'wpm_rating_script' ]);
		add_action('wp_ajax_pmw_dismissed_notice_handler', [ $this, 'ajax_rating_notice_handler' ]);
		add_action('admin_notices', [ $this, 'ask_for_rating_notice' ]);
	}

	public function wpm_rating_script() {
		wp_enqueue_script(
			'pmw-ask-for-rating',
			PMW_PLUGIN_DIR_PATH . 'js/admin/ask-for-rating.js',
			[ 'jquery' ],
			PMW_CURRENT_VERSION,
			true
		);

		wp_localize_script(
			'pmw-ask-for-rating',
			'ajax_var', [
			'url'   => admin_url('admin-ajax.php'),
			'nonce' => wp_create_nonce('ajax-nonce'),
		]);
	}

	// server side php ajax handler for the admin rating notice
	public function ajax_rating_notice_handler() {

		if (!Environment::can_current_user_edit_options()) {
			wp_die();
		}

		$_post = Helpers::get_input_vars(INPUT_POST);

		// Verify nonce
		if (!isset($_post['nonce']) || !wp_verify_nonce($_post['nonce'], 'ajax-nonce')) {
			wp_die();
		}

		$set = $_post['set'];

		$options = get_option($this->option_name);

		if ('rating_done' === $set) {

			$options['rating_done'] = true;
			update_option($this->option_name, $options);

		} elseif ('later' === $set) {

			$options['rating_threshold'] = $this->get_next_threshold($options['conversions_count']);
			update_option($this->option_name, $options);
		}

		wp_die(); // this is required to terminate immediately and return a proper response
	}

	private function show_admin_notifications() {

		$show_admin_notifications = apply_filters_deprecated('wooptpm_show_admin_notifications', [ true ], '1.13.0', 'pmw_show_admin_notifications');
		$show_admin_notifications = apply_filters_deprecated('wpm_show_admin_notifications', [ $show_admin_notifications ], '1.31.2', 'pmw_show_admin_notifications');

		// Allow users to disable admin notifications for the plugin
		return apply_filters('pmw_show_admin_notifications', $show_admin_notifications);
	}

	public function ask_for_rating_notice() {

		// Don't show if were not an admin
		if (!Environment::get_user_edit_capability()) {
			return;
		}

		// Don't show if admin notifications have been deactivated.
		if (!$this->show_admin_notifications()) {
			return;
		}

		$pmw_ratings = get_option($this->option_name);

		// When this runs the first time, set the options and return.
		if (!isset($pmw_ratings['conversions_count'])) {

			// set default settings for wpm_ratings
			update_option($this->option_name, $this->get_default_settings());

			return;
		}

		$conversions_count = $pmw_ratings['conversions_count'];

		// in rare cases, this option has not been set
		// in those cases we set it to avoid further errors
		if (!isset($pmw_ratings['rating_done'])) {
			$pmw_ratings['rating_done'] = false;
			update_option($this->option_name, $pmw_ratings);
		}

		// in rare cases, this option has not been set
		// in those cases we set it to avoid further errors
		if (!isset($pmw_ratings['rating_threshold'])) {
			$pmw_ratings['rating_threshold'] = 10;
			update_option($this->option_name, $pmw_ratings);
		}

		// For testing purposes
		if (defined('PMW_ALWAYS_AKS_FOR_RATING') && PMW_ALWAYS_AKS_FOR_RATING) {
			$this->ask_for_rating_notices($conversions_count);
			return;
		}

		// If the rating has been given, don't show the notification.
		if (true === $pmw_ratings['rating_done']) {
			return;
		}

		// If the threshold has not been reached yet, don't show.
		if ($conversions_count < $pmw_ratings['rating_threshold']) {
			return;
		}

		$this->ask_for_rating_notices($conversions_count);
	}

	private function get_next_threshold( $conversions_count ) {
		return $conversions_count * 10;
	}

	private function get_default_settings() {
		return [
			'conversions_count' => 1,
			'rating_threshold'  => 10,
			'rating_done'       => false,
		];
	}

	// show an admin notice to ask for a plugin rating
	public function ask_for_rating_notices( $conversions_count ) {
		?>
		<div id="pmw-rating-notice"
			 class="notice notice-success pmw pmw-rating-notice"
			 style="padding: 12px 16px; display: none; flex-direction: row; justify-content: space-between; align-items: flex-start; border-left-color: #46b450;">

			<!-- Left side: Message and CTA -->
			<div style="flex: 1;">
				<div style="color: #1e1e1e; margin-bottom: 8px;">
					<strong style="font-size: 14px;">
						<?php
						printf(
							/* translators: %s: the amount of purchase conversions that have been measured (formatted with strong tags) */
							esc_html__('Thank you for using the Pixel Manager! You\'ve successfully tracked %s conversions 🎉', 'woocommerce-google-adwords-conversion-tracking-tag'),
							'<span style="color: #46b450;">' . esc_html(number_format_i18n($conversions_count)) . '</span>'
						);
						?>
					</strong>
				</div>

				<div style="color: #444; margin-bottom: 10px; line-height: 1.5;">
					<?php esc_html_e('If you have a moment, a quick review would help other WooCommerce store owners find a reliable tracking solution.', 'woocommerce-google-adwords-conversion-tracking-tag'); ?>
				</div>

				<div style="color: #666; font-size: 13px; font-style: italic; margin-bottom: 12px;">
					— Aleksandar, Lead Developer
				</div>

				<!-- Stars and CTA -->
				<div style="display: flex; align-items: center; gap: 12px; flex-wrap: wrap;">
					<a id="pmw-rate-it"
					   href="https://wordpress.org/support/plugin/woocommerce-google-adwords-conversion-tracking-tag/reviews/#new-post"
					   target="_blank"
					   style="text-decoration: none; box-shadow: none;">
						<div class="button button-primary" style="margin: 0;">
							<?php esc_html_e('Leave a Review', 'woocommerce-google-adwords-conversion-tracking-tag'); ?> ⭐
						</div>
					</a>
					<span style="font-size: 18px; letter-spacing: 2px;">⭐⭐⭐⭐⭐</span>
				</div>

				<div style="color: #888; font-size: 12px; margin-top: 8px;">
					<?php esc_html_e('Takes ~30 seconds · Join hundreds of store owners who\'ve reviewed', 'woocommerce-google-adwords-conversion-tracking-tag'); ?>
				</div>
			</div>

			<!-- Right side: Secondary actions -->
			<div style="text-align: right; display: flex; flex-direction: column; gap: 6px; margin-left: 20px;">
				<div id="pmw-already-did"
					 class="button pmw-rating-dismiss-button"
					 style="white-space: normal; text-align: center;"
					 data-action="rating_done">
					<?php esc_html_e('Already reviewed', 'woocommerce-google-adwords-conversion-tracking-tag'); ?>
				</div>
				<div id="pmw-maybe-later"
					 class="button pmw-rating-dismiss-button"
					 style="white-space: normal; text-align: center;"
					 data-action="later">
					<?php esc_html_e('Maybe later', 'woocommerce-google-adwords-conversion-tracking-tag'); ?>
				</div>
			</div>
		</div>
		<?php
	}
}
