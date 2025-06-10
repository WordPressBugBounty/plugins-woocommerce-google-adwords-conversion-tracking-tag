<?php
/**
 * Database functions
 */

// TODO Move Facebook Advanced Matching down to to ['facebook']['advanced_matching']
// TODO Move Facebook Send Anonymous Hits down one level to ['facebook']['capi']['process_anonymous_hits']

// TODO Strategy for saving backup versions:
// 1. Save version with version and timestamp ['db_version']['timestamp']
// On downgrade maybe take the latest version before the downgrade version from the backup
// Give the user the option to restore other timestamps of the same running version (if there are more than one)
// When upgrading again up to the newer version, either run the updater, or take the backup version for the higher version
// On downgrade, run pre install hook to save the current version as backup

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

		// TODO implement in Q1 2023
//		if (version_compare(4, $db_version, '>')) {
//			self::up_from_3_to_4();
//		}

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

		$options_old = Options::get_options();

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

		$options_old = Options::get_options();

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

		error_log('db up_from_3_to_4');

		self::save_options_backup('3');

		$options_old = Options::get_options();

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

//		error_log(print_r($options,true));

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

		$options_backup[$version][time()] = Options::get_options();

		update_option(Options::$options_backup_name, $options_backup, false);
	}
}
