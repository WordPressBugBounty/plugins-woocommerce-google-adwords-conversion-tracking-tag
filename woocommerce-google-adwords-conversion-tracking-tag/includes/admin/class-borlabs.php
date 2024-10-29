<?php

// TODO: Go through all comments and make sure they are correct
// TODO: Go through all function names and optimize them
// TODO: Add PHPDoc comments to all functions

namespace SweetCode\Pixel_Manager\Admin;

use SweetCode\Pixel_Manager\Helpers;
use SweetCode\Pixel_Manager\Options;

defined('ABSPATH') || exit; // Exit if accessed directly

/**
 * Class Borlabs
 *
 * This class is responsible for setting up Borlabs Cookie settings automatically.
 * No need to manually set up Borlabs Cookie settings anymore.
 *
 * @package SweetCode\Pixel_Manager\Admin
 * @since   1.31.0
 */
class Borlabs {

	private static $did_init = false;

	private static $cookie_groups_db     = [];
	private static $cookies_in_db        = [];
	private static $cookie_presets       = [];
	private static $cookie_group_presets = [];
	private static $group_ids            = [];

	public static function init() {

		// If already initialized, do nothing
		if (self::$did_init) {
			return;
		}

		self::$did_init = true;

		self::set_up_borlabs();
	}

	private static function set_up_borlabs() {

		if (!self::can_the_setup_be_run()) {
			return;
		}

		self::$cookie_groups_db     = self::get_cookie_groups_from_database();
		self::$cookies_in_db        = self::get_cookies_from_database();
		self::$cookie_presets       = Borlabs_Presets::get_cookie_presets();
		self::$cookie_group_presets = Borlabs_Presets::get_cookie_group_presets();
		self::$group_ids            = self::get_active_group_ids();

		self::add_cookies_for_remaining_languages_in_cookie_groups();
		self::enable_cookie_groups_if_necessary();
		self::enable_cookies_if_necessary();
		self::update_borlabs_cookie_settings();
	}

	private static function enable_cookies_if_necessary() {

		foreach (self::$cookies_in_db as $cookie) {

			// Don't continue if the cookie is not in the Borlabs Cookie presets
			if (!isset(self::$cookie_presets[$cookie['cookie_id']])) {
				continue;
			}

			// Don't continue if the cookie is not active in the Borlabs Cookie presets
			if (!self::$cookie_presets[$cookie['cookie_id']]['active']) {
				continue;
			}

			// If $cookie['status'] is true, continue with the next cookie
			if ($cookie['status']) {
				continue;
			}

			// Enable the cookie
			self::enable_cookie_id($cookie['id']);
		}
	}

	private static function enable_cookie_id( $cookie_id ) {

		// In the borlabs_cookie_cookies table,
		// set the field 'status' to true for the cookie with the given $cookie_id

		global $wpdb;

		$wpdb->update(
			$wpdb->prefix . 'borlabs_cookie_cookies',
			[
				'status' => true,
			],
			[
				'id' => $cookie_id,
			]
		);
	}

	private static function get_active_group_ids() {

		$group_ids = [];

		if (Options::is_at_least_one_statistics_pixel_active()) {
			$group_ids[] = 'statistics';
		}

		if (Options::is_at_least_one_marketing_pixel_active()) {
			$group_ids[] = 'marketing';
		}

		return $group_ids;
	}

	private static function enable_cookie_groups_if_necessary() {

		foreach (self::$cookie_groups_db as $cookie_group) {

			// If $cookie_group['group_id'] is not marketing or statistics,
			// or if $cookie_group['status'] is true,
			// continue with the next cookie group
			if (
				!in_array($cookie_group['group_id'], self::$group_ids)
				|| $cookie_group['status']
			) {
				continue;
			}

			// Enable the cookie group
			self::enable_cookie_group_id($cookie_group['id']);
		}
	}

	private static function enable_cookie_group_id( $cookie_group_id ) {

		// In the borlabs_cookie_groups table,
		// set the field 'status' to true for the cookie group with the given $cookie_group_id

		global $wpdb;

		$wpdb->update(
			$wpdb->prefix . 'borlabs_cookie_groups',
			[
				'status' => true,
			],
			[
				'id' => $cookie_group_id,
			]
		);
	}

	private static function can_the_setup_be_run() {

		if (!Environment::is_borlabs_cookie_active()) {
			return false;
		}

		// Only run while in the backend
		if (!is_admin()) {
			return false;
		}

		// Only run if admin
		if (!Environment::get_user_edit_capability()) {
			return false;
		}

		// Only run on the Borlabs Cookie settings page or on the Pixel Manager settings page
		if (!Helpers::is_admin_page([ 'borlabs-cookie', 'borlabs-cookie-cookies', 'borlabs-cookie-settings', 'wpm', 'pmw', 'wgact' ])) {
			return false;
		}

		if (!self::does_borlabs_table_exist('borlabs_cookie_groups')) {
			return false;
		}

		if (!self::does_borlabs_table_exist('borlabs_cookie_cookies')) {
			return false;
		}

		return true;
	}

	private static function add_cookies_for_remaining_languages_in_cookie_groups() {

		// Get all the languages codes from the Borlabs cookie groups table that are not en or de
		$cookie_group_languages = self::get_filtered_languages_from_cookie_group_db([ 'en', 'de' ]);

		// Check if for each language there is marketing and statistics group.
		// If not, create the group in the database using the preset for that language and fall back to en
		foreach ($cookie_group_languages as $language) {

			foreach (self::$group_ids as $group_id) {

				// Check if the cookie group exists in the database
				$cookie_group_exists = self::does_cookie_group_exist_in_database(
					$group_id,
					$language
				);

				// If the cookie group exists, continue with the next cookie group
				// If the cookie group doesn't exist, create the cookie group in the database using the preset for that language and fall back to en
				if ($cookie_group_exists) {
					continue;
				}

				// Create the cookie group in the database using the preset for that language and fall back to en
				self::create_group_in_database_and_return_id($group_id, $language);
			}
		}

		// Check if for each language and the cookie_groups marketing and statistics, there is a cookie in the database
		// If not, create the cookie in the database using the present for en
		foreach ($cookie_group_languages as $language) {

			foreach (self::$group_ids as $check_group_id) {

				// Check if the cookie group exists in the database
				$cookie_group_exists = self::does_cookie_group_exist_in_database(
					$check_group_id,
					$language
				);

				// If the cookie group doesn't exist, continue with the next language
				if (!$cookie_group_exists) {
					continue;
				}

				// Loop through the $cookie_presets array
				// Check if the cookies for the same group ID and language exist in the database
				// If not, create the cookies in the database using the preset for en
				foreach (self::$cookie_presets as $cookie_id => $cookie_value) {

					// Check if the cookie group ID match, and the language exists as key in the $cookie_value['name'] array
					// If not, continue with the next cookie
					if ($cookie_value['group_id'] !== $check_group_id || !array_key_exists($language, $cookie_value['name'])) {
						continue;
					}

					// Check if the cookie exists in the database
					$cookie_exists = self::does_cookie_exist_in_database(
						$cookie_id,
						$language
					);

					// If the cookie exists, continue with the next cookie
					// If the cookie doesn't exist, create the cookie in the database using the preset for en
					if ($cookie_exists) {
						continue;
					}

					// Create the cookie in the database using the preset for en
					self::create_cookie_in_database(
						$cookie_id,
						$language
					);
				}
			}
		}
	}

	private static function get_two_letter_active_wp_language_code() {

		// Get the active WordPress language
		$active_wp_language = get_locale();

		// Get the two-letter code of the active WordPress language
		return substr($active_wp_language, 0, 2);
	}

	private static function does_cookie_group_exist_in_database( $cookie_group_id, $language ) {

		// Loop through the $cookie_groups_db array
		// Check if the $cookie_group_id and $language match
		// If yes, return true
		// If no, return false
		foreach (self::$cookie_groups_db as $cookie_group_db) {

			if (
				$cookie_group_db['group_id'] === $cookie_group_id
				&& $cookie_group_db['language'] === $language
			) {
				return true;
			}
		}

		return false;
	}

	// Get all languages from the Borlabs cookie groups table that are not any of the languages in the $exclude_languages array
	private static function get_filtered_languages_from_cookie_group_db( $exclude_languages = [] ) {

		// Don't exclude any languages
		$cookie_group_languages = [];

		// Loop through the $cookie_groups_db array
		// Add each language to the $cookie_group_languages array
		foreach (self::$cookie_groups_db as $cookie_group_db) {
			$cookie_group_languages[] = $cookie_group_db['language'];
		}

		// Add the two letter active WordPress language code to the $cookie_group_languages array
		$cookie_group_languages[] = self::get_two_letter_active_wp_language_code();

		// If WPML is active, add all active languages from WPML to the $cookie_group_languages array
		if (function_exists('wpml_active_languages')) {

			// Get the active languages from WPML
			$active_wpml_languages = wpml_active_languages();

			// Get just the keys of the $active_wpml_languages array
			$active_wpml_languages = array_keys($active_wpml_languages);

			// Add the active WPML languages to the $cookie_group_languages array
			$cookie_group_languages = array_merge($cookie_group_languages, $active_wpml_languages);
		}

		// If Polylang is active, add all active languages from Polylang to the $cookie_group_languages array
		if (function_exists('pll_languages_list')) {

			// Get the active languages from Polylang
			$active_polylang_languages = pll_languages_list();

			// Get just the values of the $active_polylang_languages array
			$active_polylang_languages = array_values($active_polylang_languages);

			// Add the active Polylang languages to the $cookie_group_languages array
			$cookie_group_languages = array_merge($cookie_group_languages, $active_polylang_languages);
		}

		// If GTranslate is active add the languages from GTranslate to the $cookie_group_languages array
		if (Environment::is_gtranslate_active()) {

			// Get the GTranslate options
			$gtranslate_options = get_option('GTranslate');

			// If $gtranslate_options['fincl_langs'] is set
			// and if it is an array
			// get all values from the $gtranslate_options['fincl_langs'] array
			// and add them to the $cookie_group_languages array
			if (
				isset($gtranslate_options['fincl_langs'])
				&& is_array($gtranslate_options['fincl_langs'])
			) {
				$cookie_group_languages = array_merge($cookie_group_languages, $gtranslate_options['fincl_langs']);
			}
		}

		// Add more languages to the $cookie_group_languages array using the filter pmw_borlabs_cookie_group_languages
		$cookie_group_languages = apply_filters('pmw_borlabs_cookie_group_languages', $cookie_group_languages);

		// Make the $cookie_group_languages array unique
		$cookie_group_languages = array_unique($cookie_group_languages);

		// Remove en and de from the $cookie_group_languages array
		$cookie_group_languages = array_diff($cookie_group_languages, $exclude_languages);

		// Make it unique
		return array_unique($cookie_group_languages);
	}

	private static function get_cookie_groups_from_database() {

		global $wpdb;

		$cookie_groups = $wpdb->get_results('SELECT * FROM ' . $wpdb->prefix . 'borlabs_cookie_groups');

		// Make $cookie_groups_in_db a nested array with the table ID as the key
		$cookie_groups_array = [];

		foreach ($cookie_groups as $cookie_group) {
			$cookie_groups_array[$cookie_group->id] = $cookie_group;
		}

		// This contains stdClass Objects. Make it an array.
		return json_decode(wp_json_encode($cookie_groups_array), true);
	}

	private static function update_borlabs_cookie_settings() {

		// Loop through the $cookie_presets array
		// Check if for each cookie and language, there is a cookie in the database
		// If not, create the cookie in the database
		foreach (self::$cookie_presets as $cookie_id => $cookie_value) {

			// Get all the keys for the $cookie_preset['name'] array
			// This will be the languages
			$cookie_languages_from_cookie = array_keys($cookie_value['name']);

			$cookie_languages_from_groups = self::get_filtered_languages_from_cookie_group_db([]);

			// Merge the $cookie_languages_from_cookie and $cookie_languages_from_groups arrays
			$cookie_languages = array_merge($cookie_languages_from_cookie, $cookie_languages_from_groups);

			// Make the $cookie_languages array unique
			$cookie_languages = array_unique($cookie_languages);

			// Loop through the languages
			foreach ($cookie_languages as $language) {

				// Check if the cookie exists in the database
				$cookie_exists = self::does_cookie_exist_in_database(
					$cookie_id,
					$language
				);

				// If the cookie does not exist, create it
				// If the cookie exists, do nothing
				if (!$cookie_exists) {
					self::create_cookie_in_database(
						$cookie_id,
						$language
					);
				}

				// If the cookie exists, check if it is linked to the correct cookie group
				// If not, update the cookie group table row ID in the cookie table
				if ($cookie_exists) {
					self::update_cookie_group_id_in_cookie_table(
						$cookie_id,
						$language
					);
				}
			}
		}
	}

	// If the cookie exists, check if it is linked to the correct cookie group
	// If not, update the cookie group table row ID in the cookie table
	private static function update_cookie_group_id_in_cookie_table(
		$cookie_id,
		$language
	) {
		// Get the cookie group ID from the cookie table
		$cookie_details = self::get_cookie_details_from_cookie_table($cookie_id, $language);

		// Get the cookie group ID from the cookie preset for that cookie
		$cookie_group_id_in_cookie_preset = self::$cookie_presets[$cookie_id]['group_id'];

		$cookie_group_table_id_in_cookie_table = $cookie_details['cookie_group_id'];

		// Get the table ID from the $cookie_groups_in_db array for the $cookie_group_id_in_cookie_preset and $language

		$cookie_group_table_id = self::get_cookie_group_table_id(
			$cookie_group_id_in_cookie_preset,
			$language
		);

		// If the $cookie_group_table_id is not the same as the one as in $cookie_details['cookie_group_id'] then update the cookie group ID in the cookie table
		if ($cookie_group_table_id != $cookie_group_table_id_in_cookie_table) {
			self::set_new_cookie_group_id_on_the_cookie($cookie_details['id'], $cookie_group_table_id);
		}
	}

	private static function set_new_cookie_group_id_on_the_cookie( $cookie_table_id, $cookie_group_table_id ) {

		global $wpdb;

		$table_name = $wpdb->prefix . 'borlabs_cookie_cookies';

		$wpdb->update(
			$table_name,
			[
				'cookie_group_id' => $cookie_group_table_id,
			],
			[
				'id' => $cookie_table_id,
			]
		);
	}

	private static function get_cookie_details_from_cookie_table( $cookie_id, $language ) {

		// Return the cookie details
		foreach (self::$cookies_in_db as $id => $cookie_in_db) {
			if ($cookie_in_db['cookie_id'] == $cookie_id && $cookie_in_db['language'] == $language) {
				// Add the id to the array
				$cookie_in_db['id'] = $id;
				return $cookie_in_db;
			}
		}
	}

	private static function create_cookie_in_database(
		$cookie_preset_id,
		$language
	) {
		global $wpdb;

		$table_name = $wpdb->prefix . 'borlabs_cookie_cookies';

		// Get or set cookie group ID
		$cookie_group_id = self::get_cookie_group_table_id(
			self::$cookie_presets[$cookie_preset_id]['group_id'],
			$language
		);

		// If cookie is not active in the cookie preset, abort
		if (!self::$cookie_presets[$cookie_preset_id]['active']) {
			return;
		}

		$wpdb->insert(
			$table_name,
			[
				'cookie_id'          => $cookie_preset_id,
				'language'           => $language,
				'cookie_group_id'    => $cookie_group_id,
				'service'            => 'Custom',
				'name'               => isset(self::$cookie_presets[$cookie_preset_id]['name'][$language])
					? self::$cookie_presets[$cookie_preset_id]['name'][$language]
					: self::$cookie_presets[$cookie_preset_id]['name']['en'],
				'provider'           => self::$cookie_presets[$cookie_preset_id]['provider'],
				'purpose'            => isset(self::$cookie_presets[$cookie_preset_id]['purpose'][$language])
					? self::$cookie_presets[$cookie_preset_id]['purpose'][$language]
					: self::$cookie_presets[$cookie_preset_id]['purpose']['en'],
				'privacy_policy_url' => isset(self::$cookie_presets[$cookie_preset_id]['privacy_policy'])
					? self::$cookie_presets[$cookie_preset_id]['privacy_policy']
					: '',
				//				'hosts'           => isset(self::$cookie_presets[$cookie_preset_id]['hosts'])
				//					? self::$cookie_presets[$cookie_preset_id]['hosts']
				//					: 'a:0:{}',
				'hosts'              => isset(self::$cookie_presets[$cookie_preset_id]['hosts'])
					? serialize(self::$cookie_presets[$cookie_preset_id]['hosts'])
					: serialize([]),
				'cookie_name'        => isset(self::$cookie_presets[$cookie_preset_id]['cookie_name'])
					? self::$cookie_presets[$cookie_preset_id]['cookie_name']
					: '',
				'cookie_expiry'      => isset(self::$cookie_presets[$cookie_preset_id]['cookie_expiry'])
					? self::$cookie_presets[$cookie_preset_id]['cookie_expiry']
					: '',
				//				'opt_in_js'       => '',
				//				'opt_out_js'      => '',
				//				'fallback_js'     => '',
				//								'settings'        => 'a:2:{s:25:"blockCookiesBeforeConsent";s:1:"0";s:10:"prioritize";s:1:"0";}',
				'settings'           => serialize([
					'blockCookiesBeforeConsent' => '0',
					'prioritize'                => '0',
				]),
				'position'           => 1,
				'status'             => 1,
				'undeletable'        => 0,
			]
		);

		// Update the $cookies_in_db array
		self::$cookies_in_db = self::get_cookies_from_database();
	}

	private static function get_pmw_info_suffix() {

		$message = esc_html__('Automatically added by the Pixel Manager for WooCommerce', 'woocommerce-google-adwords-conversion-tracking-tag');
		return ' (' . $message . ')';
	}

	// Get,  or set cookie group and return the ID
	private static function get_cookie_group_table_id( $group_id, $language ) {

		// The $cookie_groups_in_db array contains all the cookie groups in the database
		// If the $group_id and $language is set in the $cookie_groups_in_db array then return the key
		foreach (self::$cookie_groups_db as $id => $cookie_group_in_db) {
			if ($cookie_group_in_db['group_id'] == $group_id && $cookie_group_in_db['language'] == $language) {
				return $id;
			}
		}

		// Create the cookie group in the database and return the ID
		return self::create_group_in_database_and_return_id($group_id, $language);
	}

	// If the $db_id is not set create a new cookie group
	private static function create_group_in_database_and_return_id( $group_id, $language ) {

		global $wpdb;

		$table_name = $wpdb->prefix . 'borlabs_cookie_groups';

		// Get the next position
		// Count all the cookie groups with the same language.
		// Get the one with the highest position and add 1
		$position = $wpdb->get_var(
				$wpdb->prepare(
					'SELECT MAX(position) FROM ' . $wpdb->prefix . 'borlabs_cookie_groups  WHERE language = %s',
					$language
				)
			) + 1;

		// Prepare the insert array
		$insert_array = [
			'group_id'     => $group_id,
			'language'     => $language,
			'name'         => isset(self::$cookie_group_presets[$group_id]['name'][$language])
				? self::$cookie_group_presets[$group_id]['name'][$language]
				: self::$cookie_group_presets[$group_id]['name']['en'],
			'description'  => isset(self::$cookie_group_presets[$group_id]['description'][$language])
				? self::$cookie_group_presets[$group_id]['description'][$language]
				: self::$cookie_group_presets[$group_id]['description']['en'],
			'pre_selected' => 1,
			'position'     => $position,
			'status'       => 1,
			'undeletable'  => 1,
		];

		// Insert the cookie group
		$wpdb->insert(
			$table_name,
			$insert_array
		);

		// Update the cookie groups in the static variable
		self::$cookie_groups_db = self::get_cookie_groups_from_database();

		// Get the ID of the inserted cookie group
		return $wpdb->insert_id;
	}

	private static function does_cookie_exist_in_database(
		$cookie_id,
		$language
	) {
		// Loop through the cookies in the database
		foreach (self::$cookies_in_db as $cookie_in_db) {

			// If the cookie preset ID and language match, return true
			if (
				$cookie_in_db['cookie_id'] === $cookie_id
				&& $cookie_in_db['language'] === $language
			) {
				return true;
			}
		}

		// If the cookie does not exist, return false
		return false;
	}

	private static function get_cookies_from_database() {

		global $wpdb;

		$cookies = $wpdb->get_results(
			'SELECT * FROM ' . $wpdb->prefix . 'borlabs_cookie_cookies'
		);

		// Make $cookies_in_db a nested array with the table ID as the key
		$cookies_array = [];

		foreach ($cookies as $cookie) {
			$cookies_array[$cookie->id] = $cookie;
		}

		// This contains stdClass Objects. Make it an array.
		return json_decode(wp_json_encode($cookies_array), true);
	}

	// Check if the database table exists
	private static function does_borlabs_table_exist( $table_name ) {
		global $wpdb;

		$wp_table_name = $wpdb->prefix . $table_name;

		$wpdb->query($wpdb->prepare('SHOW TABLES LIKE %s', $wp_table_name));
		return (bool) $wpdb->last_result;
	}
}
