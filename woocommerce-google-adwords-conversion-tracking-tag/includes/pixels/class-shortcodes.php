<?php

namespace SweetCode\Pixel_Manager\Pixels;

use SweetCode\Pixel_Manager\Logger;
use SweetCode\Pixel_Manager\Options;
use SweetCode\Pixel_Manager\Pixels\Facebook\Facebook;
use SweetCode\Pixel_Manager\Product;

defined('ABSPATH') || exit; // Exit if accessed directly

class Shortcodes {

	private static $did_init = false;

	public static function init() {

		// If already initialized, do nothing
		if (self::$did_init) {
			return;
		}

		self::$did_init = true;

		add_shortcode('view-item', [ __CLASS__, 'view_item' ]);
		add_shortcode('conversion-pixel', [ __CLASS__, 'conversion_pixel' ]);
	}

	public function __construct() {
		self::init();
	}

	/**
	 * The view-item shortcode fires all enabled pixels with their own view-item event,
	 * including all product information.
	 * The shortcode is necessary on custom-made product pages where WooCommerce can't detect that it is a product page
	 * and therefore the Pixel Manager can't automatically inject the necessary scripts for the view-item event.
	 */
	public static function view_item( $attributes ) {

		self::init();

		$shortcode_attributes = shortcode_atts(
			[ 'product-id' => null ],
			$attributes
		);

		if ($shortcode_attributes['product-id']) {

			$product = wc_get_product($shortcode_attributes['product-id']);

			if (Product::is_not_wc_product($product)) {
				Logger::debug('get_product_data_layer_script received an invalid product');
				return;
			}

			Product::print_product_data_layer_script($product, false, false);

			?>

			<script>
				jQuery(document).on("pmw:ready", function () {
					jQuery(document).trigger("pmw:view-item", wpm.getProductDetailsFormattedForEvent(<?php esc_html_e($shortcode_attributes['product-id']); ?>))
				})
			</script>
			<?php
		}
	}

	public static function conversion_pixel( $attributes ) {

		self::init();

		self::function_exists_script();

		$pairs = [
			'pixel'                 => 'all',
			'gads-conversion-id'    => Options::get_google_ads_conversion_id(),
			'gads-conversion-label' => '',
			'lintrk-event'          => null,
			'meta-event'            => 'Lead',
			'twc-event'             => 'CompleteRegistration',
			'pinc-event'            => 'lead',
			'pinc-lead-type'        => '',
			'ms-ads-event'          => 'submit',
			'ms-ads-event-category' => '',
			'ms-ads-event-label'    => 'lead',
			'ms-ads-event-value'    => 0,
			'outbrain-event'        => '',
			'reddit-event'          => 'Lead',
			'snap-event'            => 'SIGN_UP',
			'taboola-event'         => '',
			'tiktok-event'          => 'SubmitForm',
		];

		$shortcode_attributes = shortcode_atts($pairs, $attributes);

		// If $attributes['fbq-event'] is set, overwrite $attributes['meta-event'] with $attributes['fbq-event']
		// This is to maintain backwards compatibility with the old shortcode
		if (isset($attributes['fbq-event'])) {
			$shortcode_attributes['meta-event'] = $attributes['fbq-event'];
		}

		if (isset($attributes['fbc-event'])) {
			$shortcode_attributes['meta-event'] = $attributes['fbc-event'];
		}

		self::output_tracking_scripts($shortcode_attributes);
	}

	private static function output_tracking_scripts( $shortcode_attributes ) {

		// Google Ads
		if (
			self::should_tracking_event_be_injected($shortcode_attributes, 'google-ads')
			&& Options::is_google_ads_active()
		) {
			self::conversion_html_google_ads($shortcode_attributes);
		}

		// LinkedIn
		if (
			self::should_tracking_event_be_injected($shortcode_attributes, 'linkedin')
			&& Options::is_linkedin_active()
		) {
			self::conversion_html_linkedin($shortcode_attributes);
		}

		// Meta (Facebook)
		if (
			(
				self::should_tracking_event_be_injected($shortcode_attributes, 'facebook')
				|| self::should_tracking_event_be_injected($shortcode_attributes, 'meta')
			)
			&& Options::is_facebook_active()
		) {
			self::conversion_html_facebook($shortcode_attributes);
		}

		// Microsoft Ads
		if (
			self::should_tracking_event_be_injected($shortcode_attributes, 'ms-ads')
			&& Options::is_bing_active()
		) {
			self::conversion_html_microsoft_ads($shortcode_attributes);
		}

		// Outbrain
		if (
			self::should_tracking_event_be_injected($shortcode_attributes, 'outbrain')
			&& Options::is_outbrain_active()
		) {
			self::conversion_html_outbrain($shortcode_attributes);
		}

		// Pinterest
		if (
			self::should_tracking_event_be_injected($shortcode_attributes, 'pinterest')
			&& Options::is_pinterest_active()
		) {
			self::conversion_html_pinterest($shortcode_attributes);
		}

		// Reddit
		if (
			self::should_tracking_event_be_injected($shortcode_attributes, 'reddit')
			&& Options::is_reddit_active()
		) {
			self::conversion_html_reddit_ads($shortcode_attributes);
		}

		// Snapchat
		if (
			self::should_tracking_event_be_injected($shortcode_attributes, 'snapchat')
			&& Options::is_snapchat_active()
		) {
			self::conversion_html_snapchat($shortcode_attributes);
		}

		// Taboola
		if (
			self::should_tracking_event_be_injected($shortcode_attributes, 'taboola')
			&& Options::is_taboola_active()
		) {
			self::conversion_html_taboola($shortcode_attributes);
		}

		// TikTok
		if (
			self::should_tracking_event_be_injected($shortcode_attributes, 'tiktok')
			&& Options::is_tiktok_active()
		) {
			self::conversion_html_tiktok($shortcode_attributes);
		}

		// Twitter
		if (
			self::should_tracking_event_be_injected($shortcode_attributes, 'twitter')
			&& Options::is_twitter_active()
		) {
			self::conversion_html_twitter($shortcode_attributes);
		}
	}

	private static function should_tracking_event_be_injected( $shortcode_attributes, $pixel_id = null ) {

		if ('all' === $shortcode_attributes['pixel']) {
			return true;
		}

		if ($pixel_id === $shortcode_attributes['pixel']) {
			return true;
		}

		return false;
	}

	private static function conversion_html_snapchat( $shortcode_attributes ) {

		?>

		<script>
			wpmFunctionExists("snaptr").then(function () {
					snaptr("track", '<?php echo esc_js($shortcode_attributes['snap-event']); ?>')
				},
			)
		</script>
		<?php
	}

	private static function conversion_html_taboola( $shortcode_attributes ) {

		if (empty($shortcode_attributes['taboola-event'])) {
			return;
		}

		?>

		<script>
			wpmFunctionExists("_tfa").then(function () {
					_tfa.push({
						id    : <?php esc_html_e(Options::get_taboola_account_id()); ?>,
						notify: "event",
						name  : "<?php echo esc_js($shortcode_attributes['taboola-event']); ?>",
					})
				},
			)
		</script>
		<?php
	}

	private static function conversion_html_tiktok( $shortcode_attributes ) {

		?>

		<script>
			wpmFunctionExists("ttq").then(function () {
					ttq.track('<?php echo esc_js($shortcode_attributes['tiktok-event']); ?>')
				},
			)
		</script>
		<?php
	}

	private static function conversion_html_google_ads( $shortcode_attributes ) {

		?>

		<script>
			wpmFunctionExists("gtag").then(function () {
					if (wpm.googleConfigConditionsMet({type: "marketing"})) gtag("event", "conversion", {"send_to": 'AW-<?php echo esc_js($shortcode_attributes['gads-conversion-id']); ?>/<?php echo esc_js($shortcode_attributes['gads-conversion-label']); ?>'})
				},
			)
		</script>
		<?php
	}

	private static function conversion_html_linkedin( $shortcode_attributes ) {

		if (empty($shortcode_attributes['lintrk-event'])) {
			return;
		}

		?>

		<script>
			wpmFunctionExists("lintrk").then(function () {
					lintrk("track", {
						conversion_id: <?php echo esc_js($shortcode_attributes['lintrk-event']); ?>
					})
				},
			)
		</script>
		<?php
	}

	// https://www.facebook.com/business/help/402791146561655?id=1205376682832142
	private static function conversion_html_facebook( $shortcode_attributes ) {

		if (in_array($shortcode_attributes['meta-event'], Facebook::get_standard_event_names())) {
			$track_instruction = 'track';
		} else {
			$track_instruction = 'trackCustom';
		}


		if (Options::is_facebook_capi_active()) {
			?>

			<script>
				jQuery(document).on("pmw:ready", function () {

					let eventId = wpm.getRandomEventId()

					wpmFunctionExists("fbq").then(function () {
							fbq("<?php esc_html_e($track_instruction); ?>", "<?php echo esc_js($shortcode_attributes['meta-event']); ?>", {}, {
								eventID: eventId,
							})
						},
					)

					wpm.sendEventPayloadToServer({
						facebook: {
							event_name      : "<?php echo esc_js($shortcode_attributes['meta-event']); ?>",
							event_id        : eventId,
							user_data       : wpm.getFbUserData(),
							event_source_url: window.location.href,
						},
					})
				})

			</script>
			<?php
		} else {
			?>

			<script>
				wpmFunctionExists("fbq").then(function () {
						fbq("<?php esc_html_e($track_instruction); ?>", "<?php echo esc_js($shortcode_attributes['meta-event']); ?>")
					},
				)
			</script>
			<?php
		}
	}

	// https://business.twitter.com/en/help/campaign-measurement-and-analytics/conversion-tracking-for-websites.html
	private static function conversion_html_twitter( $shortcode_attributes ) {

		?>

		<script>
			wpmFunctionExists("twq").then(function () {
					twq("track", '<?php echo esc_js($shortcode_attributes['twc-event']); ?>')
				},
			)
		</script>
		<?php
	}

	public static function conversion_html_outbrain( $shortcode_attributes ) {

		if (empty($shortcode_attributes['outbrain-event'])) {
			return;
		}

		?>

		<script>
			wpmFunctionExists("obApi").then(function () {
					obApi("track", '<?php echo esc_js($shortcode_attributes['outbrain-event']); ?>')
				},
			)
		</script>
		<?php
	}

	// https://help.pinterest.com/en/business/article/track-conversions-with-pinterest-tag
	// https://help.pinterest.com/en/business/article/add-event-codes
	private static function conversion_html_pinterest( $shortcode_attributes ) {

		if ('' === $shortcode_attributes['pinc-lead-type']) {
			?>

			<script>
				wpmFunctionExists("pintrk").then(function () {
						pintrk("track", '<?php echo esc_js($shortcode_attributes['pinc-event']); ?>')
					},
				)
			</script>
			<?php
		} else {
			?>

			<script>
				wpmFunctionExists("pintrk").then(function () {
						pintrk("track", '<?php echo esc_js($shortcode_attributes['pinc-event']); ?>', {
							lead_type: '<?php echo esc_js($shortcode_attributes['pinc-lead-type']); ?>',
						})
					},
				)
			</script>
			<?php
		}
	}

	// https://bingadsuet.azurewebsites.net/UETDirectOnSite_ReportCustomEvents.html
	private static function conversion_html_microsoft_ads( $shortcode_attributes ) {
		?>

		<script>
			wpmFunctionExists("uetq").then(function () {
					window.uetq = window.uetq || []
					window.uetq.push("event", '<?php echo esc_js($shortcode_attributes['ms-ads-event']); ?>', {
						"event_category": '<?php echo esc_js($shortcode_attributes['ms-ads-event-category']); ?>',
						"event_label"   : '<?php echo esc_js($shortcode_attributes['ms-ads-event-label']); ?>',
						"event_value"   : '<?php echo esc_js($shortcode_attributes['ms-ads-event-value']); ?>',
					})
				},
			)
		</script>
		<?php
	}

	private static function conversion_html_reddit_ads( $shortcode_attributes ) {
		?>

		<script>
			wpmFunctionExists("rdt").then(function () {
					rdt("track", '<?php echo esc_js($shortcode_attributes['reddit-event']); ?>')
				},
			)
		</script>
		<?php
	}

	protected static function function_exists_script() {
		?>

		<script>
			if (typeof wpmFunctionExists !== "function") {
				window.wpmFunctionExists = function (functionName) {
					return new Promise(function (resolve) {
						(function waitForVar() {
							if (typeof window[functionName] !== "undefined") return resolve()
							setTimeout(waitForVar, 1000)
						})()
					})
				}
			}
		</script>
		<?php
	}
}
