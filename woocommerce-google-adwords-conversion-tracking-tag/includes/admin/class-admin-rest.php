<?php

namespace SweetCode\Pixel_Manager\Admin;

use SweetCode\Pixel_Manager\Admin\Notifications\Notifications;
use SweetCode\Pixel_Manager\Admin\Opportunities\Opportunities;
use SweetCode\Pixel_Manager\Helpers;
use SweetCode\Pixel_Manager\Logger;

defined('ABSPATH') || exit; // Exit if accessed directly

class Admin_REST {

	protected $rest_namespace = 'pmw/v1';

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

	public function register_routes() {

		register_rest_route($this->rest_namespace, '/notifications/', [
			'methods'             => 'POST',
			'callback'            => function ( $request ) {

				$data = Helpers::generic_sanitization($request->get_json_params());

				if (!array_key_exists('type', $data) || !array_key_exists('id', $data)) {
					wp_send_json_error('No type or id specified');
				}

				if ('generic-notification' === $data['type']) {
					$pmw_notifications              = get_option(PMW_DB_NOTIFICATIONS_NAME);
					$pmw_notifications[$data['id']] = time();

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
			'permission_callback' => function () {
				return Environment::can_current_user_edit_options();
			},
		]);

		// A route for the ltv recalculation
		register_rest_route($this->rest_namespace, '/ltv/', [
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
			'permission_callback' => function () {
				return Environment::can_current_user_edit_options();
			},
		]);
	}
}
