<?php

namespace SweetCode\Pixel_Manager\Admin\Opportunities;

use SweetCode\Pixel_Manager\Admin\Commercial_Links;
use SweetCode\Pixel_Manager\Helpers;

defined('ABSPATH') || exit; // Exit if accessed directly

/**
 * Abstract class Opportunity
 *
 * @since 1.28.0
 */
abstract class Opportunity {

	/**
	 * Check if the opportunity is available.
	 *
	 * @return bool
	 * @since 1.28.0
	 */
	abstract public static function available();

	public static function not_available() {
		return !static::available();
	}

	/**
	 * Return the card data for this opportunity.
	 *
	 * IMPORTANT: the 'impact' value is a canonical machine key and MUST be one
	 * of the literal lowercase strings 'high', 'medium' or 'low'. Do NOT wrap it
	 * in esc_html__()/__(): the Nova admin UI groups and renders cards by exact
	 * impact key, so a translated value (e.g. 'hoch' on a German site) would be
	 * counted in the tab badge but never rendered. Human-readable, localized
	 * impact labels are derived for display by the consuming UI.
	 *
	 * @return array
	 */
	abstract public static function card_data();

	public static function custom_middle_cart_html() {
		return null;
	}

	/**
	 * Whether this card promotes a feature the free version cannot switch on.
	 *
	 * Derived from the file location rather than from the namespace, because a
	 * number of the classes under opportunities/pro/ declare the Free namespace.
	 * A new card dropped into that directory is covered without further wiring.
	 *
	 * @return bool
	 *
	 * @since 1.67.1
	 */
	public static function promotes_premium_feature() {

		try {
			$file = ( new \ReflectionClass(static::class) )->getFileName();
		} catch (\ReflectionException $e) {
			return false;
		}

		return false !== strpos(str_replace('\\', '/', (string) $file), '/opportunities/pro/');
	}

	/**
	 * The card data the admin interfaces render.
	 *
	 * A Pro card seen on an install that cannot use premium code says so and
	 * carries the upgrade path. Several of these cards became reachable on the
	 * free version once the browser pixels moved there: the pixel is configured,
	 * its Conversions API is not, so the card appears. Without this note it
	 * reads as a setting the shop simply has not switched on yet, and the shop
	 * goes looking for a field that is locked.
	 *
	 * card_data() itself stays untouched, so dismissal state and the tests keep
	 * working off the card's own definition.
	 *
	 * @return array
	 *
	 * @since 1.67.1
	 */
	public static function display_card_data() {

		$card_data = static::card_data();

		if (!static::promotes_premium_feature()) {
			return $card_data;
		}

		if (Helpers::is_pmw_pro_version_active()) {
			return $card_data;
		}

		$cta = Commercial_Links::premium_cta();

		$card_data['description']   = isset($card_data['description']) ? $card_data['description'] : [];
		$card_data['description'][] = $cta['trial']
			? esc_html__(
				'This is a Pixel Manager Pro feature. You can try it free for 14 days.',
				'woocommerce-google-adwords-conversion-tracking-tag'
			)
			: esc_html__(
				'This is a Pixel Manager Pro feature.',
				'woocommerce-google-adwords-conversion-tracking-tag'
			);

		$card_data['custom_buttons']   = isset($card_data['custom_buttons']) ? $card_data['custom_buttons'] : [];
		$card_data['custom_buttons'][] = [
			'label'  => $cta['label'],
			'class'  => 'pmw-opportunity-pro-cta',
			'url'    => $cta['url'],
			'target' => '_blank',
		];

		return $card_data;
	}

	public static function output_card() {

		if (static::not_available()) {
			return;
		}

		$card_data              = static::display_card_data();
		$card_data['dismissed'] = static::is_dismissed();

		Opportunities::card_html($card_data, static::custom_middle_cart_html());
	}

	public static function is_dismissed() {

		$option = get_option(Opportunities::$pmw_opportunities_option);

		if (empty($option)) {
			return false;
		}

		$card_data = static::card_data();

		// Check if dismissed key exists
		if (!isset($option[$card_data['id']]['dismissed'])) {
			return false;
		}

		// If no repeat_interval defined, stay dismissed forever
		if (!isset($card_data['repeat_interval'])) {
			return true;
		}

		// Check if current time is still within the repeat interval
		if (time() < ( $option[$card_data['id']]['dismissed'] + $card_data['repeat_interval'] )) {
			return true; // Still within cooldown period
		}

		// Cooldown expired, show opportunity again
		return false;
	}

	public static function is_not_dismissed() {
		return !static::is_dismissed();
	}

	public static function is_newer_than_dismissed_dashboard_time( $option ) {

		if (empty($option)) {
			return true;
		}

		if (!isset($option['dashboard_notification_dismissed'])) {
			return true;
		}

		if (static::card_data()['since'] > $option['dashboard_notification_dismissed']) {
			return true;
		}

		return false;
	}
}
