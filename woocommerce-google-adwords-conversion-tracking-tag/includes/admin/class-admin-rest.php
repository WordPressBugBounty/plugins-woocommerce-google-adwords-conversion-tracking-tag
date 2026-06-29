<?php

namespace SweetCode\Pixel_Manager\Admin;

use SweetCode\Pixel_Manager\Admin\Notifications\Notifications;
use SweetCode\Pixel_Manager\Admin\Opportunities\Opportunities;
use SweetCode\Pixel_Manager\Helpers;
use SweetCode\Pixel_Manager\Logger;
use SweetCode\Pixel_Manager\Options;
use SweetCode\Pixel_Manager\Tracking_Accuracy_DB;

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

				if ('restore_opportunity' === $data['type']) {
					Opportunities::restore_opportunity($data['id']);
					wp_send_json_success();
				}

				if ('dismiss_notification' === $data['type']) {
					Notifications::dismiss_notification($data['id']);
					wp_send_json_success();
				}

				// Onboarding checklist actions from the Nova dashboard.
				if ('onboarding' === $data['type']) {

					if ('dismiss' === $data['id']) {
						Onboarding::dismiss();
						wp_send_json_success();
					}

					if (Onboarding::complete_step($data['id'])) {
						wp_send_json_success();
					}

					wp_send_json_error('Unknown onboarding step');
				}

				// One-time server-load warning acknowledgement (CAPI / Tag Gateway).
				if ('server_load_warning' === $data['type']) {

					if (Server_Load_Warning::acknowledge($data['id'])) {
						wp_send_json_success();
					}

					wp_send_json_error('Unknown server load warning');
				}

				// Rating card actions from the Nova admin UI.
				if ('rating' === $data['type']) {

					if ('rating_done' === $data['id']) {
						Ask_For_Rating::mark_rating_done();
						wp_send_json_success();
					}

					if ('later' === $data['id']) {
						Ask_For_Rating::postpone_rating();
						wp_send_json_success();
					}

					wp_send_json_error('Unknown rating action');
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

		// ─── Nova Admin UI: Granular option update ───────────
		$this->register_nova_routes();
	}

	/**
	 * Register REST routes for the Nova admin UI.
	 *
	 * @since 1.59.0
	 */
	private function register_nova_routes() {

		// PATCH /options — update a single option by dot-notation path
		register_rest_route(self::$rest_namespace, '/options', [
			'methods'             => 'PATCH',
			'callback'            => [ $this, 'handle_option_patch' ],
			'permission_callback' => [ $this, 'can_current_user_edit_options' ],
		]);

		// GET /options — return the full options tree
		register_rest_route(self::$rest_namespace, '/options', [
			'methods'             => 'GET',
			'callback'            => function () {
				return new \WP_REST_Response(Options::get_options(), 200);
			},
			'permission_callback' => [ $this, 'can_current_user_edit_options' ],
		]);

		// GET /diagnostics/gateway-accuracy — daily payment-gateway tracking
		// accuracy time-series for the Diagnostics chart (history + comparison).
		register_rest_route(self::$rest_namespace, '/diagnostics/gateway-accuracy', [
			'methods'             => 'GET',
			'callback'            => [ $this, 'handle_gateway_accuracy_timeseries' ],
			'permission_callback' => [ $this, 'can_current_user_edit_options' ],
		]);

		// GET /diagnostics/debug-info — the copy-paste debug report for support.
		// Generated on demand (runs outbound connectivity tests), never on page load.
		register_rest_route(self::$rest_namespace, '/diagnostics/debug-info', [
			'methods'             => 'GET',
			'callback'            => function () {
				return new \WP_REST_Response([ 'text' => Debug_Info::get_debug_info() ], 200);
			},
			'permission_callback' => [ $this, 'can_current_user_edit_options' ],
		]);
	}

	/**
	 * Handle GET /pmw/v1/diagnostics/gateway-accuracy.
	 *
	 * Returns the daily, per-gateway tracking-accuracy rows within a date range,
	 * the available data bounds, and friendly gateway titles. All reads are cheap
	 * (indexed lookups on the pmw_tracking_accuracy table).
	 *
	 * Query params: start (Y-m-d), end (Y-m-d), gateways (comma-separated ids, optional).
	 *
	 * @param \WP_REST_Request $request
	 * @return \WP_REST_Response
	 *
	 * @since 1.59.0
	 */
	public function handle_gateway_accuracy_timeseries( $request ) {

		$bounds = Tracking_Accuracy_DB::get_data_date_bounds();

		// Default range: bounds (all available) when present, else last 30 days.
		$end   = $request->get_param('end');
		$start = $request->get_param('start');

		if (!is_string($end) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $end)) {
			$end = $bounds['max'] ? $bounds['max'] : gmdate('Y-m-d');
		}

		if (!is_string($start) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $start)) {
			$start = $bounds['min'] ? $bounds['min'] : gmdate('Y-m-d', strtotime('-30 days'));
		}

		$gateway_ids = null;
		$gw_param    = $request->get_param('gateways');
		if (is_string($gw_param) && '' !== $gw_param) {
			$gateway_ids = array_values(array_filter(array_map('sanitize_text_field', explode(',', $gw_param))));
			if (empty($gateway_ids)) {
				$gateway_ids = null;
			}
		}

		$rows = Tracking_Accuracy_DB::get_accuracy_timeseries($start, $end, $gateway_ids);

		// Friendly gateway titles: map stored ids → method_title where the gateway
		// still exists; fall back to the id for removed/legacy gateways.
		$titles = [];
		foreach (Debug_Info::get_payment_gateways() as $gateway) {
			$titles[(string) $gateway->id] = (string) $gateway->method_title;
		}

		// Currently-enabled gateways, so the UI can scope the trend to active ones.
		$enabled_ids = [];
		foreach (Debug_Info::get_enabled_payment_gateways() as $gateway) {
			$enabled_ids[(string) $gateway->id] = true;
		}

		$seen     = [];
		$gateways = [];
		$out_rows = [];
		foreach ($rows as $row) {
			$id = (string) $row['gateway_id'];

			if (!isset($seen[$id])) {
				$seen[$id]  = true;
				$gateways[] = [
					'id'          => $id,
					'methodTitle' => isset($titles[$id]) ? $titles[$id] : $id,
					'enabled'     => isset($enabled_ids[$id]),
				];
			}

			$out_rows[] = [
				'date'      => (string) $row['date'],
				'gatewayId' => $id,
				'total'     => (int) $row['orders_total'],
				'measured'  => (int) $row['orders_measured'],
				'acr'       => (int) $row['orders_acr'],
				'delaySum'  => (int) $row['delay_sum'],
			];
		}

		return new \WP_REST_Response([
			'bounds'    => $bounds,
			'start'     => $start,
			'end'       => $end,
			'canUseAcr' => function_exists('wpm_fs') && wpm_fs()->can_use_premium_code__premium_only(),
			'gateways'  => $gateways,
			'rows'      => $out_rows,
		], 200);
	}

	/**
	 * Handle PATCH /pmw/v1/options — update a single nested option value.
	 *
	 * Expects JSON body: { "path": "facebook.pixel_id", "value": "123456" }
	 *
	 * @param \WP_REST_Request $request Request object.
	 *
	 * @return \WP_REST_Response
	 *
	 * @since 1.59.0
	 */
	public function handle_option_patch( $request ) {

		$data = $request->get_json_params();

		// Batch form: { "patches": [ { "path": ..., "value": ... }, ... ] }.
		// The whole batch is applied in a single read-modify-write so concurrent
		// multi-field saves cannot clobber each other (last-writer-wins on the
		// full options tree).
		if (isset($data['patches']) && is_array($data['patches'])) {
			return $this->handle_option_patch_batch($data['patches']);
		}

		if (empty($data['path']) || !is_string($data['path'])) {
			return new \WP_REST_Response([
				'success' => false,
				'message' => 'Missing or invalid "path" parameter.',
			], 400);
		}

		if (!array_key_exists('value', $data)) {
			return new \WP_REST_Response([
				'success' => false,
				'message' => 'Missing "value" parameter.',
			], 400);
		}

		$path  = sanitize_text_field($data['path']);
		$value = Helpers::generic_sanitization($data['value']);

		$check = self::authorize_and_validate_option_patch($path, $value);

		if (!$check['ok']) {
			return new \WP_REST_Response([
				'success' => false,
				'message' => $check['message'],
			], $check['status']);
		}

		// Use the preprocessed value (trimmed, prefix-stripped, etc.)
		$value = $check['value'];

		$options = Options::get_options();
		$options = self::set_nested_value($options, $path, $value);

		$this->persist_option_changes($options, [ $path ]);

		return new \WP_REST_Response([
			'success' => true,
			'value'   => $value,
		], 200);
	}

	/**
	 * Apply a batch of option patches in a single read-modify-write.
	 *
	 * Each patch is authorized + validated independently; the valid ones are
	 * applied to one in-memory copy of the options tree, which is saved once.
	 * This makes a multi-field save atomic from the client's perspective, so
	 * overlapping single-field requests can no longer clobber each other.
	 *
	 * @param array $patches List of [ 'path' => string, 'value' => mixed ] entries.
	 *
	 * @return \WP_REST_Response
	 *
	 * @since 1.59.0
	 */
	private function handle_option_patch_batch( $patches ) {

		$options       = Options::get_options();
		$results       = [];
		$applied_paths = [];

		foreach ($patches as $patch) {

			if (!is_array($patch) || empty($patch['path']) || !is_string($patch['path']) || !array_key_exists('value', $patch)) {
				$results[] = [
					'path'    => ( isset($patch['path']) && is_string($patch['path']) ) ? sanitize_text_field($patch['path']) : '',
					'success' => false,
					'message' => 'Missing or invalid "path"/"value".',
				];
				continue;
			}

			$path  = sanitize_text_field($patch['path']);
			$value = Helpers::generic_sanitization($patch['value']);

			$check = self::authorize_and_validate_option_patch($path, $value);

			if (!$check['ok']) {
				$results[] = [
					'path'    => $path,
					'success' => false,
					'message' => $check['message'],
				];
				continue;
			}

			$options         = self::set_nested_value($options, $path, $check['value']);
			$applied_paths[] = $path;

			$results[] = [
				'path'    => $path,
				'success' => true,
				'value'   => $check['value'],
			];
		}

		if (!empty($applied_paths)) {
			$this->persist_option_changes($options, $applied_paths);
		}

		return new \WP_REST_Response([
			'success' => true,
			'results' => $results,
		], 200);
	}

	/**
	 * Persist a set of option changes: write once, coalesce backups, and run
	 * the per-field save side effects.
	 *
	 * Backups are coalesced to one per short editing window (instead of one per
	 * field save) so a burst of edits no longer churns the recent-backups list;
	 * a multi-field session still produces a restore point. The auto-revert
	 * timers that the Classic form-save arms (dedup re-enable, HTTP-log
	 * deactivate) are mirrored here so the Nova path stays at parity.
	 *
	 * @param array $options       The full, updated options tree.
	 * @param array $changed_paths Dot-notation paths included in this save.
	 *
	 * @return void
	 *
	 * @since 1.59.0
	 */
	private function persist_option_changes( $options, $changed_paths ) {

		$cooldown_key  = 'pmw_options_backup_cooldown';
		$create_backup = !get_transient($cooldown_key);

		Options::save_options_with_timestamp($options, $create_backup);

		if ($create_backup) {
			/**
			 * Filters the cooldown window during which further per-field option
			 * saves reuse the current automatic backup instead of creating a new
			 * one, coalescing a burst of edits into a single restore point.
			 *
			 * @since 1.59.0
			 *
			 * @param int $seconds Cooldown window in seconds. Default 5 minutes.
			 */
			$cooldown = apply_filters('pmw_options_backup_cooldown_seconds', 5 * MINUTE_IN_SECONDS);
			set_transient($cooldown_key, 1, $cooldown);
		}

		Validations::apply_setting_change_side_effects($options, $changed_paths);
	}

	/**
	 * Authorize and validate a single option patch before it is written.
	 *
	 * Enforces the gates the legacy form-POST path enforced implicitly:
	 *  - the path must be a real, known setting (present in the default options
	 *    tree) so callers cannot inject arbitrary keys into the options record;
	 *  - premium-only paths are rejected on the free tier (the UI renders them
	 *    disabled, and the legacy save discarded them via preserve_premium_only_options);
	 *  - the value must pass the field's format validator.
	 *
	 * @param string $path  Dot-notation option path.
	 * @param mixed  $value Sanitized value.
	 *
	 * @return array ['ok' => bool, 'value' => mixed, 'message' => string, 'status' => int]
	 *
	 * @since 1.59.0
	 */
	private static function authorize_and_validate_option_patch( $path, $value ) {

		// Reject unknown paths: every legitimate setting exists in the default
		// options tree (Options::update_with_defaults backfills it on init).
		if (!self::option_path_exists($path)) {
			return [
				'ok'      => false,
				'status'  => 400,
				'message' => __('Unknown setting.', 'woocommerce-google-adwords-conversion-tracking-tag'),
			];
		}

		// Reject premium-only paths on the free tier, mirroring the disabled UI
		// fields and the legacy preserve_premium_only_options() behavior.
		if (in_array($path, Validations::premium_only_option_paths(), true)
			&& !Helpers::is_pmw_pro_version_active()
			&& !Options::is_pro_version_demo_active()) {
			return [
				'ok'      => false,
				'status'  => 403,
				'message' => __('This setting requires an active Pro license.', 'woocommerce-google-adwords-conversion-tracking-tag'),
			];
		}

		// Run field-specific validation (trim, preprocess, regex).
		$validation = Validations::validate_single_option($path, $value);

		if (!$validation['valid']) {
			return [
				'ok'      => false,
				'status'  => 400,
				'message' => $validation['message'],
			];
		}

		return [
			'ok'    => true,
			'value' => $validation['value'],
		];
	}

	/**
	 * Whether a dot-notation path resolves to a key in the default options tree.
	 *
	 * @param string $path
	 *
	 * @return bool
	 *
	 * @since 1.59.0
	 */
	private static function option_path_exists( $path ) {

		$node = Options::get_default_options();

		foreach (explode('.', $path) as $key) {
			if (!is_array($node) || !array_key_exists($key, $node)) {
				return false;
			}
			$node = $node[ $key ];
		}

		return true;
	}

	/**
	 * Set a nested value in an associative array using dot-notation path.
	 *
	 * @param array  $array Array to modify.
	 * @param string $path  Dot-notation path (e.g. "facebook.pixel_id").
	 * @param mixed  $value Value to set.
	 *
	 * @return array Modified array.
	 *
	 * @since 1.59.0
	 */
	private static function set_nested_value( $array, $path, $value ) {
		$keys    = explode('.', $path);
		$current = &$array;

		foreach ($keys as $i => $key) {
			if ( count($keys) - 1 === $i ) {
				$current[$key] = $value;
			} else {
				if (!isset($current[$key]) || !is_array($current[$key])) {
					$current[$key] = [];
				}
				$current = &$current[$key];
			}
		}

		return $array;
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
