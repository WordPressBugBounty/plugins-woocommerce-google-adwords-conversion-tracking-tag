<?php
/**
 * Database functions
 */

// TODO Move Facebook Advanced Matching down to to ['facebook']['advanced_matching']

namespace SweetCode\Pixel_Manager;

defined('ABSPATH') || exit; // Exit if accessed directly

class Database {

	public static function run_options_db_upgrade() {

		$db_version = self::get_mysql_db_version();

		// determine version and run version specific upgrade function
		// check if options db version zero by looking if the old entries are still there.
		if ('0' === $db_version) {
			self::up_from_zero_to_1();
		}

		if (version_compare(2, $db_version, '>')) {
			self::up_from_1_to_2();
		}

		if (version_compare(3, $db_version, '>')) {
			self::up_from_2_to_3();
		}

//      if (version_compare(4, $db_version, '>')) {
//          self::up_from_3_to_4();
//      }

		if (version_compare(PMW_DB_VERSION, $db_version, '<')) {
			self::downgrade_db();
		}
	}

	private static function downgrade_db() {

		self::save_options_backup();

		// Get the latest backup for version PMW_DB_VERSION
		$options_backup = get_option(Options::$options_backup_name);

		// Run this if on a downgrade there is no backup of the options for the version of this plugin.
		if (!isset($options_backup[PMW_DB_VERSION])) {

			/**
			 * Merge default options of this PMW version with the options from the db which are of a higher version during a downgrade.
			 * This way we can downgrade to a db version which has less options than the version in the db and avoid errors.
			 */
			$new_options               = Options::update_with_defaults(Options::get_options(), Options::get_default_options());
			$new_options['db_version'] = PMW_DB_VERSION;

			update_option(PMW_DB_OPTIONS_NAME, $new_options);
			return;
		}

		// Run this if on a downgrade there is a backup of the options for this plugin version that has no timestamp yet.
		if (is_string($options_backup[PMW_DB_VERSION])) {

			$new_options = $options_backup[PMW_DB_VERSION];
			update_option(PMW_DB_OPTIONS_NAME, $new_options);
			return;
		}

		// Run this if there is a backup of the options for this plugin version and has a timestamp.
		// Then take the version with the latest timestamp.
		if (is_array($options_backup[PMW_DB_VERSION])) {

			// $options_backup[PMW_DB_VERSION] is an array of backups for the same version.
			// Each key is a timestamp.
			// Get the latest timestamp
			$latest_timestamp = max(array_keys($options_backup[PMW_DB_VERSION]));
			$new_options      = $options_backup[PMW_DB_VERSION][$latest_timestamp];
			update_option(PMW_DB_OPTIONS_NAME, $new_options);
		}
	}

	private static function up_from_zero_to_1() {

		$option_name_old_1 = 'wgact_plugin_options_1';
		$option_name_old_2 = 'wgact_plugin_options_2';

		// db version place options into new array
		$options = [
			'conversion_id'    => self::get_option_value_v1($option_name_old_1),
			'conversion_label' => self::get_option_value_v1($option_name_old_2),
		];

		// store new option array into the options table
		update_option(PMW_DB_OPTIONS_NAME, $options);

		// delete old options
		// only on single site
		// we will run the multisite deletion only during uninstall
		delete_option($option_name_old_1);
		delete_option($option_name_old_2);
	}

	private static function up_from_1_to_2() {

		self::save_options_backup('1');

		// Read straight from the DB: Options::get_options() returns the array
		// cached before the upgrade chain started, so on a multi-step upgrade
		// (e.g. db 1 → 3 in one request) it would not contain this step's input.
		$options_old = get_option(PMW_DB_OPTIONS_NAME);

		$options_new = [
			'gads'       => [
				'conversion_id'      => $options_old['conversion_id'],
				'conversion_label'   => $options_old['conversion_label'],
				'order_total_logic'  => $options_old['order_total_logic'],
				'add_cart_data'      => $options_old['add_cart_data'],
				'aw_merchant_id'     => $options_old['aw_merchant_id'],
				'product_identifier' => $options_old['product_identifier'],
			],
			'gtag'       => [
				'deactivation' => $options_old['gtag_deactivation'],
			],
			'db_version' => '2',
		];

		update_option(PMW_DB_OPTIONS_NAME, $options_new);
	}

	private static function up_from_2_to_3() {

		self::save_options_backup('2');

		// Fresh DB read — see up_from_1_to_2(). Before this fix, a db 1 → 3
		// upgrade read the stale pre-chain array here: ['gads'] was undefined,
		// google.ads ended up empty (the user's conversion settings were lost),
		// and the leftover top-level keys made get_mysql_db_version() report
		// '1' forever, re-running this broken chain on every page load.
		$options_old = get_option(PMW_DB_OPTIONS_NAME);

		$options_new = $options_old;

		$options_new['shop']['order_total_logic'] = $options_old['gads']['order_total_logic'];

		$options_new['google']['ads']  = $options_old['gads'];
		$options_new['google']['gtag'] = $options_old['gtag'];


		unset($options_new['google']['ads']['order_total_logic']);
		unset($options_new['gads']);
		unset($options_new['gtag']);
		unset($options_new['google']['ads']['google_business_vertical']);

		$options_new['google']['ads']['google_business_vertical'] = 0;

		$options_new['db_version'] = '3';

		update_option(PMW_DB_OPTIONS_NAME, $options_new);
	}

	private static function up_from_3_to_4() {

		self::save_options_backup('3');

		// Fresh DB read — see up_from_1_to_2().
		$options_old = get_option(PMW_DB_OPTIONS_NAME);

		$options_new = $options_old;

		$options_new['facebook']['advanced_matching']              = $options_old['facebook']['capi']['user_transparency']['send_additional_client_identifiers'];
		$options_new['facebook']['capi']['process_anonymous_hits'] = $options_old['facebook']['capi']['user_transparency']['process_anonymous_hits'];

		unset($options_new['facebook']['capi']['user_transparency']['send_additional_client_identifiers']);
		unset($options_new['facebook']['capi']['user_transparency']['process_anonymous_hits']);

		$options_new['db_version'] = '4';

		update_option(PMW_DB_OPTIONS_NAME, $options_new);
	}

	private static function get_mysql_db_version() {

		$options = Options::get_options();

//      error_log(print_r($options,true));

		if (( get_option('wgact_plugin_options_1') ) || ( get_option('wgact_plugin_options_2') )) {
			return '0';
		} elseif (array_key_exists('conversion_id', $options)) {
			return '1';
		} else {
			return $options['db_version'];
		}
	}

	protected static function get_option_value_v1( $option_name ) {

		if (!get_option($option_name)) {
			$option_value = '';
		} else {
			$option       = get_option($option_name);
			$option_value = $option['text_string'];
		}

		return $option_value;
	}

	public static function save_options_backup( $version = null ) {

		if (is_null($version)) {
			$version = Options::get_db_version();
		}

		$options_backup = get_option(Options::$options_backup_name);

		// Upgrade from old method for saving versions to new one that also saves the timestamp.
		if (isset($options_backup[$version]) && is_string($options_backup[$version])) {
			$settings                         = $options_backup[$version];
			$options_backup[$version]         = [];
			$options_backup[$version][time()] = $settings;
		}

		// Back up what is actually stored, not the request-cached array — during
		// a multi-step upgrade the cache lags behind the chain's DB writes.
		$options_backup[$version][time()] = get_option(PMW_DB_OPTIONS_NAME);

		update_option(Options::$options_backup_name, $options_backup, false);
	}
}
