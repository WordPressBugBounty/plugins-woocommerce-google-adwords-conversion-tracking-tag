<?php

namespace SweetCode\Pixel_Manager\Admin;

use SweetCode\Pixel_Manager\Admin\Notifications\Notifications;
use SweetCode\Pixel_Manager\Admin\Opportunities\Opportunities;
use SweetCode\Pixel_Manager\Helpers;
use SweetCode\Pixel_Manager\Logger;
use SweetCode\Pixel_Manager\Options;

defined('ABSPATH') || exit; // Exit if accessed directly

class Admin_REST {

	protected static $rest_namespace = 'pmw/v1';
	protected static $log_source     = 'pmw';

	private static $instance;

	public static function get_instance() {
		if (is_null(self::$instance)) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	public function __construct() {
		add_action('rest_api_init', [ $this, 'register_routes' ]);
	}

	// Extracted the code because the QIT semgrep rule was triggered
	public function can_current_user_edit_options() {
		return Environment::can_current_user_edit_options();
	}

	public function register_routes() {

		$this->register_settings_save_route();

		register_rest_route(self::$rest_namespace, '/notifications/', [
			'methods'             => 'POST',
			'callback'            => function ( $request ) {

				$data = Helpers::generic_sanitization($request->get_json_params());

				if (!array_key_exists('type', $data) || !array_key_exists('id', $data)) {
					wp_send_json_error('No type or id specified');
				}

				if ('generic-notification' === $data['type']) {
					$pmw_notifications = get_option(PMW_DB_NOTIFICATIONS_NAME);

					if (empty($pmw_notifications) || !is_array($pmw_notifications)) {
						$pmw_notifications = [];
					}

					if (!isset($pmw_notifications[$data['id']]) || !is_array($pmw_notifications[$data['id']])) {
						$pmw_notifications[$data['id']] = [];
					}

					$pmw_notifications[$data['id']]['dismissed'] = time();

					update_option(PMW_DB_NOTIFICATIONS_NAME, $pmw_notifications);
					wp_send_json_success();
				}

				if ('dismiss_opportunity' === $data['type']) {
					Opportunities::dismiss_opportunity($data['id']);
					wp_send_json_success();
				}

				if ('dismiss_notification' === $data['type']) {
					Notifications::dismiss_notification($data['id']);
					wp_send_json_success();
				}

				wp_send_json_error('Unknown notification action');
			},
			'permission_callback' => [ $this, 'can_current_user_edit_options' ],
		]);

		// Route for downloading options backup by timestamp
		register_rest_route(self::$rest_namespace, '/options-backup/(?P<timestamp>\d+)', [
			'methods'             => 'GET',
			'callback'            => function ( $request ) {
				$timestamp = $request->get_param('timestamp');

				// Validate the timestamp
				if (!$timestamp) {
					return new \WP_Error('invalid_timestamp', 'Invalid timestamp provided', [ 'status' => 400 ]);
				}

				// Get backup by timestamp
				$backup = Options::get_automatic_options_backup_by_timestamp($timestamp);

				if (empty($backup)) {
					return new \WP_Error('backup_not_found', 'Backup not found for the specified timestamp', [ 'status' => 404 ]);
				}

				// Prepare the response with proper headers for file download
				$response = new \WP_REST_Response($backup);
				$response->set_status(200);

				// Set headers for JSON download
				$response->header('Content-Type', 'application/json');

				// Format filename with local time: pixel-manager-settings-backup_{timestamp}_{YYYY.MM.DD}_{HH-MM-SS}.json
				$date_part = wp_date('Y.m.d', $timestamp);
				$time_part = wp_date('H-i-s', $timestamp);
				$filename  = sprintf('pixel-manager-settings-backup_%s_%s_%s.json', $timestamp, $date_part, $time_part);

				$response->header('Content-Disposition', 'attachment; filename="' . $filename . '"');
				$response->header('Cache-Control', 'no-store, no-cache, must-revalidate, max-age=0');
				$response->header('Pragma', 'no-cache');

				return $response;
			},
			'permission_callback' => [ $this, 'can_current_user_edit_options' ],
		]);

		// A route for the ltv recalculation
		register_rest_route(self::$rest_namespace, '/ltv/', [
			'methods'             => 'POST',
			'callback'            => function ( $request ) {

				$data = Helpers::generic_sanitization($request->get_json_params());

				if (!isset($data['action'])) {
					wp_send_json_error([
						'message' => 'No action specified',
						'status'  => LTV::get_ltv_recalculation_status(),
					]);
				}

				if ('stop_ltv_recalculation' === $data['action']) {
					LTV::stop_ltv_recalculation();
					Logger::debug('Stopped LTV recalculation');
					wp_send_json_success(
						[
							'message' => esc_html__('Stopped all LTV Action Scheduler tasks', 'woocommerce-google-adwords-conversion-tracking-tag'),
							'status'  => LTV::get_ltv_recalculation_status(),
						]
					);
				}

				if (Environment::cannot_run_action_scheduler()) {
					wp_send_json_error([
						'message' => 'LTV recalculation is not available in this environment. The active Action Scheduler version is ' . Environment::get_action_scheduler_version() . ' and the minimum required version is ' . Environment::get_action_scheduler_minimum_version(),
						'status'  => LTV::get_ltv_recalculation_status(),
					]);
				}

				if ('schedule_ltv_recalculation' === $data['action']) {
					LTV::schedule_complete_vertical_ltv_calculation();
					Logger::debug('Scheduled LTV recalculation');
					wp_send_json_success([
						'message' => esc_html__('LTV recalculation scheduled', 'woocommerce-google-adwords-conversion-tracking-tag'),
						'status'  => LTV::get_ltv_recalculation_status(),
					]);
				}

				if ('run_ltv_recalculation' === $data['action']) {
					LTV::run_complete_vertical_ltv_calculation();
					Logger::debug('Run LTV recalculation');
					wp_send_json_success([
						'message' => esc_html__('LTV recalculation running', 'woocommerce-google-adwords-conversion-tracking-tag'),
						'status'  => LTV::get_ltv_recalculation_status(),
					]);
				}

				if ('get_ltv_recalculation_status' === $data['action']) {
					Logger::debug('Get LTV recalculation status');
					wp_send_json_success(
						[
							'message' => esc_html__('Received LTV recalculation status', 'woocommerce-google-adwords-conversion-tracking-tag'),
							'status'  => LTV::get_ltv_recalculation_status(),
						]
					);
				}

				wp_send_json_error([
					'message' => 'Unknown action',
					'status'  => LTV::get_ltv_recalculation_status(),
				]);

				Logger::debug('Unknown LTV recalculation action: ' . $data['action']);
			},
			'permission_callback' => [ $this, 'can_current_user_edit_options' ],
		]);

		// Route for restoring options backup by timestamp
		register_rest_route(self::$rest_namespace, '/options-backup/(?P<timestamp>\d+)/restore', [
			'methods'             => 'POST',
			'callback'            => function ( $request ) {
				$timestamp = $request->get_param('timestamp');

				// Validate the timestamp
				if (!$timestamp) {
					wp_send_json_error([
						'message' => esc_html('Invalid timestamp provided'),
					]);
				}

				// Check if this backup is currently active
				$current_options   = Options::get_options();
				$current_timestamp = isset($current_options['timestamp']) ? $current_options['timestamp'] : null;

				if (null !== $current_timestamp && $timestamp == $current_timestamp) {
					wp_send_json_error([
						'message' => esc_html('Cannot restore the currently active backup'),
					]);
				}

				// Get backup by timestamp
				$backup = Options::get_automatic_options_backup_by_timestamp($timestamp);

				if (empty($backup)) {
					wp_send_json_error([
						'message' => esc_html('Backup not found for the specified timestamp'),
					]);
				}

				// Validate the backup options
				if (!Validations::validate_imported_options($backup)) {
					wp_send_json_error([
						'message' => esc_html('Backup validation failed'),
					]);
				}

				// All good, save options with timestamp and automatic backup
				Options::save_options_with_timestamp($backup, false, $timestamp);

				wp_send_json_success([
					'message'   => esc_html('Backup restored successfully'),
					'timestamp' => $timestamp,
				]);
			},
			'permission_callback' => [ $this, 'can_current_user_edit_options' ],
		]);

		// Route for downloading all plugin log files as a zip
		register_rest_route(self::$rest_namespace, '/logs/download', [
			'methods'             => 'POST',
			'callback'            => function ( $request ) {
				require_once ABSPATH . 'wp-admin/includes/class-wp-filesystem-base.php';
				require_once ABSPATH . 'wp-admin/includes/class-wp-filesystem-direct.php';

				$source = $request->get_param('source');
				if (empty($source)) {
					$source = self::$log_source;
				}
				$source = sanitize_text_field($source);

				$logs = \WC_Log_Handler_File::get_log_files();
				if (empty($logs)) {
					wp_send_json_error([ 'message' => esc_html__('No log files found.', 'woocommerce-google-adwords-conversion-tracking-tag') ]);
				}

				$filtered_logs = array_filter(
					$logs,
					function ( $key ) use ( $source ) {
						return strpos($key, $source . '-') === 0;
					},
					ARRAY_FILTER_USE_KEY
				);

				if (empty($filtered_logs)) {
					wp_send_json_error([ 'message' => esc_html__('No log files found for the specified source.', 'woocommerce-google-adwords-conversion-tracking-tag') ]);
				}

				$temp_dir = get_temp_dir() . $source . '_logs_' . time();
				if (!file_exists($temp_dir)) {
					mkdir($temp_dir, 0755, true);
				}

				$zip_filename = $source . '-logs-' . gmdate('Y-m-d-H-i-s') . '.zip';
				$zip_file     = $temp_dir . '/' . $zip_filename;
				$zip          = new \ZipArchive();

				if ($zip->open($zip_file, \ZipArchive::CREATE) !== true) {
					wp_send_json_error([ 'message' => esc_html__('Could not create ZIP archive.', 'woocommerce-google-adwords-conversion-tracking-tag') ]);
				}

				$log_dir = \WC_Log_Handler_File::get_log_file_path('dummy');
				$log_dir = dirname($log_dir);

				$files_added = 0;
				foreach ($filtered_logs as $log_key => $log_file) {
					$log_path = trailingslashit($log_dir) . $log_file;
					if (file_exists($log_path)) {
						$zip->addFile($log_path, basename($log_path));
						$files_added++;
					}
				}
				$zip->close();

				if (0 === $files_added) {
					wp_delete_file($zip_file);
					rmdir($temp_dir);
					wp_send_json_error([ 'message' => esc_html__('No files could be added to the ZIP archive.', 'woocommerce-google-adwords-conversion-tracking-tag') ]);
				}

				if (!headers_sent()) {
					header('Content-Type: application/zip');
					header('Content-Disposition: attachment; filename="' . basename($zip_file) . '"');
					header('Content-Length: ' . filesize($zip_file));
					header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
					header('Pragma: no-cache');
					header('Content-Transfer-Encoding: binary');
				}

				readfile($zip_file);

				wp_delete_file($zip_file);
				rmdir($temp_dir);
				exit;
			},
			'permission_callback' => [ $this, 'can_current_user_edit_options' ],
			'args'                => [
				'source' => [
					'description' => 'The log source to filter by',
					'type'        => 'string',
					'default'     => 'pmw',
					'required'    => false,
				],
			],
		]);
	}

	/**
	 * Register the REST route for saving all settings (PMW + add-ons) via AJAX.
	 *
	 * Replaces the WordPress Settings API form POST to options.php with a REST
	 * endpoint so that both PMW core and add-on settings can be saved in a single
	 * request, each to their own wp_options row.
	 *
	 * @since 1.57.0
	 */
	private function register_settings_save_route() {
		register_rest_route(self::$rest_namespace, '/options/save', [
			'methods'             => 'POST',
			'callback'            => [ $this, 'handle_settings_save' ],
			'permission_callback' => [ $this, 'can_current_user_edit_options' ],
		]);
	}

	/**
	 * Handle the settings save REST request.
	 *
	 * 1. Extracts PMW core options from POST data, runs them through the existing
	 *    Validations::options_validate() pipeline (sanitize, validate, merge defaults,
	 *    create backup), and saves to the PMW options record.
	 * 2. Fires the 'pmw_addon_save_settings' filter so add-on plugins can extract
	 *    their own prefixed data, validate, and save to their own wp_options rows.
	 * 3. Returns a JSON response with success/error status and validation messages.
	 *
	 * @param \WP_REST_Request $request The REST request containing form-serialized POST data.
	 *
	 * @return \WP_REST_Response
	 *
	 * @since 1.57.0
	 */
	public function handle_settings_save( $request ) {

		// Ensure settings error functions are available (not loaded during REST requests)
		if (!function_exists('get_settings_errors')) {
			require_once ABSPATH . 'wp-admin/includes/template.php';
		}

		$body = $request->get_body_params();

		$pmw_errors   = [];
		$addon_errors = [];

		// --- Save PMW core options ---
		if (isset($body['wgact_plugin_options']) && is_array($body['wgact_plugin_options'])) {

			$input = $body['wgact_plugin_options'];

			// Run through the existing validation pipeline.
			// This sanitizes, validates per-field, merges non_form_keys,
			// sets timestamp, fills defaults, and creates an automatic backup.
			$validated = Validations::options_validate($input);

			// Collect any settings errors that the validation pipeline added
			// (they use add_settings_error() which stores to a global).
			$settings_errors = get_settings_errors('wgact_plugin_options');

			if (!empty($settings_errors)) {
				foreach ($settings_errors as $error) {
					$pmw_errors[] = $error['message'];
				}
			}

			// Save the validated options
			update_option(PMW_DB_OPTIONS_NAME, $validated);
			Options::invalidate_cache();
		}

		// --- Let add-ons save their settings ---
		/**
		 * Filter for add-on plugins to process and save their own settings.
		 *
		 * Add-ons should:
		 * 1. Extract their data from $body using their unique key (e.g. $body['pmw_addon_myaddon'])
		 * 2. Validate and sanitize the data
		 * 3. Call update_option() with their own option name
		 * 4. Append any error messages to $addon_results
		 *
		 * @param array $addon_results Array of add-on save result arrays, each with 'slug', 'success', 'errors' keys.
		 * @param array $body          The full POST body params.
		 *
		 * @return array Modified addon_results array.
		 *
		 * @since 1.57.0
		 */
		$addon_results = apply_filters('pmw_addon_save_settings', [], $body);

		// Collect add-on errors
		foreach ($addon_results as $result) {
			if (!empty($result['errors'])) {
				$addon_errors = array_merge($addon_errors, $result['errors']);
			}
		}

		$all_errors = array_merge($pmw_errors, $addon_errors);

		if (!empty($all_errors)) {
			return new \WP_REST_Response([
				'success'       => false,
				'message'       => esc_html__('Settings saved with errors.', 'woocommerce-google-adwords-conversion-tracking-tag'),
				'errors'        => $all_errors,
				'addon_results' => $addon_results,
			], 200);
		}

		return new \WP_REST_Response([
			'success'       => true,
			'message'       => esc_html__('Settings saved.', 'woocommerce-google-adwords-conversion-tracking-tag'),
			'errors'        => [],
			'addon_results' => $addon_results,
		], 200);
	}
}
