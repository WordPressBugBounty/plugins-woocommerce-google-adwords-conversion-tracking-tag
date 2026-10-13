<?php
/**
 * Admin action abilities for the PMW Abilities API integration.
 *
 * Everything a user can do in the Nova admin UI beyond editing settings:
 * backups and imports, diagnostics and logs, opportunities and notices, the
 * SweetCode Cloud server-side proxy, the Google and Meta account connections,
 * the GA4 Data API, the Google Ads Data Manager upload and the lifetime value
 * recalculation.
 *
 * Every ability dispatches internally to the exact REST route the admin UI
 * calls (rest_do_request()), so it runs the same permission check, validators,
 * Pro gates and backups as the UI, and a change to a route reaches its ability
 * without a second implementation. The few lists the UI receives preloaded
 * instead of through a route are read from the same PHP builders.
 *
 * @package SweetCode\Pixel_Manager
 * @since 1.69.3
 */

namespace SweetCode\Pixel_Manager;

use SweetCode\Pixel_Manager\Admin\Admin;
use SweetCode\Pixel_Manager\Admin\Admin_REST;
use SweetCode\Pixel_Manager\Admin\Ask_For_Rating;
use SweetCode\Pixel_Manager\Admin\Notifications\Trial_Promotion_Notification;
use SweetCode\Pixel_Manager\Admin\Onboarding;
use SweetCode\Pixel_Manager\Admin\Server_Load_Warning;

defined('ABSPATH') || exit; // Exit if accessed directly

class Abilities_Admin {

	/**
	 * JSON schema type for a value of any type (broker passthroughs and
	 * payloads that differ per state).
	 *
	 * @var string[]
	 */
	const ANY = [ 'string', 'integer', 'number', 'boolean', 'array', 'object', 'null' ];

	/**
	 * How many times, and how long apart, a pending Google Ads conversion tag
	 * is polled. The same as the Nova Google Ads wizard.
	 */
	const TAG_POLL_ATTEMPTS = 8;
	const TAG_POLL_DELAY_US = 2500000;

	/**
	 * Every admin action ability, available on this install.
	 *
	 * Each definition: name, label, description, input (JSON schema properties),
	 * required, output (JSON schema), annotations, write (registered only while
	 * pmw_abilities_allow_write allows writes), available (the same condition
	 * the route registers under) and execute (callable receiving the input).
	 *
	 * @return array[]
	 *
	 * @since 1.69.3
	 */
	public static function get_definitions() {

		$is_pro        = Helpers::is_pmw_pro_version_active();
		$is_experiment = Helpers::is_experiment();
		$has_ssp       = $is_pro && class_exists('\SweetCode\Pixel_Manager\Admin\SSP_REST');

		$definitions = array_merge(
			self::settings_backup_definitions(),
			self::diagnostics_definitions(),
			self::notice_definitions(),
			self::ltv_definitions(),
			$has_ssp ? self::ssp_definitions() : [],
			$is_pro ? self::ga4_data_api_definitions() : [],
			$is_pro && $is_experiment ? self::data_manager_definitions() : [],
			$is_experiment ? self::google_connection_definitions() : [],
			$is_experiment ? self::meta_connection_definitions() : []
		);

		return $definitions;
	}

	/*
	|--------------------------------------------------------------------------
	| Settings export, import and backups
	|--------------------------------------------------------------------------
	*/

	private static function settings_backup_definitions() {

		$backup_list_item = [
			'type'       => 'object',
			'properties' => [
				'timestamp' => [ 'type' => 'integer', 'description' => 'Unix timestamp that identifies the backup' ],
				'dbVersion' => [ 'type' => 'string', 'description' => 'Settings schema version the backup was written with' ],
				'active'    => [ 'type' => 'boolean', 'description' => 'The backup is the settings state currently in use' ],
			],
		];

		$redacted_tree = [
			'type'       => 'object',
			'properties' => [
				'settings'       => [ 'type' => 'object', 'description' => 'The full settings tree, with every secret replaced by null' ],
				'redacted_paths' => [ 'type' => 'array', 'items' => [ 'type' => 'string' ], 'description' => 'Dot-notation paths that held a secret and were replaced by null' ],
			],
		];

		return [
			[
				'name'        => 'pmw/list-settings-backups',
				'label'       => __('List Settings Backups', 'woocommerce-google-adwords-conversion-tracking-tag'),
				'description' => __('Lists the automatic settings backups of Pixel Manager for WooCommerce, newest first. Every settings save creates one. Use the timestamp with pmw/get-settings-backup to inspect a backup and with pmw/restore-settings-backup to roll back. The backup marked active is the state in use and cannot be restored.', 'woocommerce-google-adwords-conversion-tracking-tag'),
				'output'      => [
					'type'       => 'object',
					'properties' => [
						'backups' => [ 'type' => 'array', 'items' => $backup_list_item ],
					],
				],
				'annotations' => [ 'readonly' => true, 'idempotent' => true ],
				'execute'     => function () {
					return [ 'backups' => Admin::get_backups_for_wp_admin() ];
				},
			],
			[
				'name'        => 'pmw/get-settings-backup',
				'label'       => __('Get Settings Backup', 'woocommerce-google-adwords-conversion-tracking-tag'),
				'description' => __('Returns the settings tree stored in one automatic backup, to compare it with the current settings (pmw/export-settings) before restoring. Secrets such as API tokens are replaced by null and listed in redacted_paths. Call pmw/list-settings-backups first for the timestamps.', 'woocommerce-google-adwords-conversion-tracking-tag'),
				'input'       => [
					'timestamp' => [ 'type' => 'integer', 'description' => 'Backup timestamp from pmw/list-settings-backups' ],
				],
				'required'    => [ 'timestamp' ],
				'output'      => $redacted_tree,
				'annotations' => [ 'readonly' => true, 'idempotent' => true ],
				'execute'     => function ( $input ) {

					$backup = self::dispatch('GET', '/options-backup/' . absint($input['timestamp']));

					return is_wp_error($backup) ? $backup : self::redact_secrets($backup);
				},
			],
			[
				'name'        => 'pmw/export-settings',
				'label'       => __('Export Settings', 'woocommerce-google-adwords-conversion-tracking-tag'),
				'description' => __('Returns the complete current settings tree, including internal keys that pmw/get-settings does not list, like the settings export in the admin. Secrets such as API tokens are replaced by null and listed in redacted_paths, so the export cannot restore those secrets. Use pmw/get-settings to read individual settings.', 'woocommerce-google-adwords-conversion-tracking-tag'),
				'output'      => $redacted_tree,
				'annotations' => [ 'readonly' => true, 'idempotent' => true ],
				'execute'     => function () {

					$options = self::dispatch('GET', '/options');

					return is_wp_error($options) ? $options : self::redact_secrets($options);
				},
			],
			[
				'name'        => 'pmw/restore-settings-backup',
				'label'       => __('Restore Settings Backup', 'woocommerce-google-adwords-conversion-tracking-tag'),
				'description' => __('Replaces all current settings with an automatic backup, like the Restore button on the Support page. Refused for the currently active backup and for a backup that fails validation. The settings in use before the restore stay available as their own backup, so a restore is undone by restoring that one. Confirm with the user first.', 'woocommerce-google-adwords-conversion-tracking-tag'),
				'input'       => [
					'timestamp' => [ 'type' => 'integer', 'description' => 'Backup timestamp from pmw/list-settings-backups' ],
				],
				'required'    => [ 'timestamp' ],
				'output'      => [
					'type'       => 'object',
					'properties' => [
						'message'   => [ 'type' => 'string' ],
						'timestamp' => [ 'type' => [ 'integer', 'string' ], 'description' => 'The restored backup' ],
					],
				],
				'annotations' => [ 'destructive' => true, 'idempotent' => false ],
				'write'       => true,
				'execute'     => function ( $input ) {
					return self::dispatch('POST', '/options-backup/' . absint($input['timestamp']) . '/restore');
				},
			],
			[
				'name'        => 'pmw/import-settings',
				'label'       => __('Import Settings', 'woocommerce-google-adwords-conversion-tracking-tag'),
				'description' => __('Replaces all settings with a complete settings tree, like the settings import on the Support page. Use it only with a settings file the user provided. Every field runs its validator, unknown keys are dropped, and an invalid tree is refused with the failing paths. A backup of the previous settings is created first. To change individual settings use pmw/update-settings instead.', 'woocommerce-google-adwords-conversion-tracking-tag'),
				'input'       => [
					'settings' => [
						'type'                 => 'object',
						'description'          => 'The complete settings tree, as in a Pixel Manager settings export file',
						'additionalProperties' => true,
					],
				],
				'required'    => [ 'settings' ],
				'output'      => [
					'type'       => 'object',
					'properties' => [
						'message' => [ 'type' => 'string' ],
					],
				],
				'annotations' => [ 'destructive' => true, 'idempotent' => true ],
				'write'       => true,
				'execute'     => function ( $input ) {
					return self::dispatch('POST', '/settings/', (array) $input['settings']);
				},
			],
		];
	}

	/*
	|--------------------------------------------------------------------------
	| Diagnostics and logs
	|--------------------------------------------------------------------------
	*/

	private static function diagnostics_definitions() {

		return [
			[
				'name'        => 'pmw/get-gateway-accuracy',
				'label'       => __('Get Payment Gateway Accuracy', 'woocommerce-google-adwords-conversion-tracking-tag'),
				'description' => __('Returns the daily tracking accuracy per payment gateway, the data behind the chart on the Diagnostics page: for each day and gateway the number of orders, how many of them the browser measured, how many Automatic Conversion Recovery recovered, and how many were excluded for missing consent. Low measured shares point at gateways that skip the order confirmation page. Without dates it covers the last 30 days.', 'woocommerce-google-adwords-conversion-tracking-tag'),
				'input'       => [
					'start'    => [ 'type' => 'string', 'format' => 'date', 'description' => 'First day, Y-m-d. Optional.' ],
					'end'      => [ 'type' => 'string', 'format' => 'date', 'description' => 'Last day, Y-m-d. Optional.' ],
					'gateways' => [ 'type' => 'array', 'items' => [ 'type' => 'string' ], 'description' => 'Payment gateway IDs to include. Optional, all by default.' ],
				],
				'output'      => [
					'type'       => 'object',
					'properties' => [
						'bounds'        => [ 'type' => 'object', 'description' => 'min and max day that has data' ],
						'start'         => [ 'type' => [ 'string', 'null' ] ],
						'end'           => [ 'type' => [ 'string', 'null' ] ],
						'canUseAcr'     => [ 'type' => 'boolean', 'description' => 'Automatic Conversion Recovery is available (Pro)' ],
						'alwaysSendS2s' => [ 'type' => 'boolean' ],
						'gateways'      => [ 'type' => 'array', 'description' => 'Gateways with id, methodTitle and enabled' ],
						'rows'          => [ 'type' => 'array', 'description' => 'One row per day and gateway: date, gatewayId, total, measured, acr, delaySum, consentExcluded' ],
					],
				],
				'annotations' => [ 'readonly' => true, 'idempotent' => true ],
				'execute'     => function ( $input ) {

					$query = array_intersect_key((array) $input, [ 'start' => 1, 'end' => 1 ]);

					if (!empty($input['gateways'])) {
						$query['gateways'] = implode(',', array_map('strval', (array) $input['gateways']));
					}

					return self::dispatch('GET', '/diagnostics/gateway-accuracy', $query);
				},
			],
			[
				'name'        => 'pmw/get-logs',
				'label'       => __('Get Logs', 'woocommerce-google-adwords-conversion-tracking-tag'),
				'description' => __('Returns the Pixel Manager log files, the same files the log download on the Support page zips. Only the plugin\'s own log sources (starting with pmw) can be read. Each file is returned from its end, capped at max_bytes, which is where the newest entries are. Logs only fill while the logger is enabled (general.logger.* settings) and WooCommerce file logging is on.', 'woocommerce-google-adwords-conversion-tracking-tag'),
				'input'       => [
					'source'    => [ 'type' => 'string', 'description' => 'Log source, default pmw. Anything not starting with pmw reads pmw.' ],
					'max_bytes' => [ 'type' => 'integer', 'minimum' => 1024, 'maximum' => 1048576, 'description' => 'Maximum bytes returned per file, from the end. Default 65536.' ],
				],
				'output'      => [
					'type'       => 'object',
					'properties' => [
						'source' => [ 'type' => 'string' ],
						'files'  => [
							'type'  => 'array',
							'items' => [
								'type'       => 'object',
								'properties' => [
									'name'      => [ 'type' => 'string' ],
									'size'      => [ 'type' => 'integer', 'description' => 'File size in bytes' ],
									'modified'  => [ 'type' => 'string', 'description' => 'Last change, ISO 8601' ],
									'truncated' => [ 'type' => 'boolean', 'description' => 'Only the end of the file is included' ],
									'content'   => [ 'type' => 'string' ],
								],
							],
						],
					],
				],
				'annotations' => [ 'readonly' => true, 'idempotent' => true ],
				'execute'     => [ __CLASS__, 'execute_get_logs' ],
			],
			[
				'name'        => 'pmw/get-first-event-status',
				'label'       => __('Get First Event Status', 'woocommerce-google-adwords-conversion-tracking-tag'),
				'description' => __('Returns whether the storefront already confirmed that the pixels load (pixelsLive, set by the first visit after setup) and whether the first order was tracked (firstOrder), the status strip on the Dashboard. beaconPending true means no storefront visit has confirmed the setup yet; ask the user to open the shop once.', 'woocommerce-google-adwords-conversion-tracking-tag'),
				'output'      => [
					'type'       => 'object',
					'properties' => [
						'beaconPending' => [ 'type' => 'boolean' ],
						'pixelsLive'    => [ 'type' => [ 'object', 'null' ], 'description' => 'pixels, confirmed and timestamp' ],
						'firstOrder'    => [ 'type' => [ 'object', 'null' ], 'description' => 'orderId, timestamp and celebrationDismissed' ],
					],
				],
				'annotations' => [ 'readonly' => true, 'idempotent' => true ],
				'execute'     => function () {
					return self::dispatch('GET', '/onboarding/first-event/');
				},
			],
		];
	}

	/*
	|--------------------------------------------------------------------------
	| Opportunities and admin notices
	|--------------------------------------------------------------------------
	*/

	private static function notice_definitions() {

		$acknowledge_types = [ 'generic-notification', 'dismiss_notification', 'onboarding', 'first_event', 'server_load_warning', 'rating' ];

		return [
			[
				'name'        => 'pmw/list-opportunities',
				'label'       => __('List Opportunities', 'woocommerce-google-adwords-conversion-tracking-tag'),
				'description' => __('Lists the optimization opportunities the Opportunities page shows for this shop, sorted by impact (high first): features that would improve tracking quality or ad performance, with a description, setup and documentation links and whether the user dismissed the card. Most are resolved by changing settings with pmw/update-settings.', 'woocommerce-google-adwords-conversion-tracking-tag'),
				'output'      => [
					'type'       => 'object',
					'properties' => [
						'opportunities' => [
							'type'  => 'array',
							'items' => [
								'type'       => 'object',
								'properties' => [
									'id'          => [ 'type' => 'string' ],
									'title'       => [ 'type' => 'string' ],
									'description' => [ 'type' => 'array', 'items' => [ 'type' => 'string' ] ],
									'impact'      => [ 'type' => 'string', 'enum' => [ 'high', 'medium', 'low' ] ],
									'setupLink'   => [ 'type' => 'string' ],
									'learnMore'   => [ 'type' => 'string' ],
									'setupVideo'  => [ 'type' => 'string' ],
									'buttons'     => [ 'type' => 'array' ],
									'dismissed'   => [ 'type' => 'boolean' ],
								],
							],
						],
					],
				],
				'annotations' => [ 'readonly' => true, 'idempotent' => true ],
				'execute'     => function () {
					return [ 'opportunities' => Admin::get_opportunities_for_nova() ];
				},
			],
			[
				'name'        => 'pmw/dismiss-opportunity',
				'label'       => __('Dismiss Opportunity', 'woocommerce-google-adwords-conversion-tracking-tag'),
				'description' => __('Dismisses an opportunity card, as the Dismiss button on the Opportunities page does. It only hides the card and changes no setting. Undo with pmw/restore-opportunity.', 'woocommerce-google-adwords-conversion-tracking-tag'),
				'input'       => [
					'id' => [ 'type' => 'string', 'description' => 'Opportunity id from pmw/list-opportunities' ],
				],
				'required'    => [ 'id' ],
				'output'      => self::success_schema(),
				'annotations' => [ 'idempotent' => true ],
				'write'       => true,
				'execute'     => function ( $input ) {
					return self::dispatch('POST', '/notifications/', [ 'type' => 'dismiss_opportunity', 'id' => (string) $input['id'] ]);
				},
			],
			[
				'name'        => 'pmw/restore-opportunity',
				'label'       => __('Restore Opportunity', 'woocommerce-google-adwords-conversion-tracking-tag'),
				'description' => __('Shows a dismissed opportunity card again, as Restore on the Opportunities page does.', 'woocommerce-google-adwords-conversion-tracking-tag'),
				'input'       => [
					'id' => [ 'type' => 'string', 'description' => 'Opportunity id from pmw/list-opportunities' ],
				],
				'required'    => [ 'id' ],
				'output'      => self::success_schema(),
				'annotations' => [ 'idempotent' => true ],
				'write'       => true,
				'execute'     => function ( $input ) {
					return self::dispatch('POST', '/notifications/', [ 'type' => 'restore_opportunity', 'id' => (string) $input['id'] ]);
				},
			],
			[
				'name'        => 'pmw/get-admin-notices',
				'label'       => __('Get Admin Notices', 'woocommerce-google-adwords-conversion-tracking-tag'),
				'description' => __('Returns the state of the notices and cards the admin shows the user besides opportunities: the onboarding checklist, the one-time server load warning, the first-event confirmation, the rating request, the trial promotion and SweetCode Cloud delivery problems (failed destinations, exhausted quota). Use pmw/acknowledge-notice to answer one the way the user would in the admin.', 'woocommerce-google-adwords-conversion-tracking-tag'),
				'output'      => [
					'type'       => 'object',
					'properties' => [
						'onboarding'          => [ 'type' => self::ANY ],
						'server_load_warning' => [ 'type' => self::ANY ],
						'first_event'         => [ 'type' => self::ANY ],
						'rating'              => [ 'type' => self::ANY ],
						'trial_promotion'     => [ 'type' => self::ANY ],
						'ssp'                 => [ 'type' => 'object', 'description' => 'failed_destinations and quota_exceeded, while SweetCode Cloud is active' ],
					],
				],
				'annotations' => [ 'readonly' => true, 'idempotent' => true ],
				'execute'     => function () {

					$ssp_active = Options::is_ssp_active();

					return [
						'onboarding'          => Onboarding::get_data_for_nova(),
						'server_load_warning' => Server_Load_Warning::get_data_for_nova(),
						'first_event'         => First_Event_Confirmation::get_data_for_nova(),
						'rating'              => Ask_For_Rating::get_data_for_nova(),
						'trial_promotion'     => Trial_Promotion_Notification::get_data_for_nova(),
						'ssp'                 => [
							'failed_destinations' => $ssp_active ? array_values((array) Options::get_ssp_failed_destinations()) : [],
							'quota_exceeded'      => $ssp_active && Options::is_ssp_quota_exceeded(),
						],
					];
				},
			],
			[
				'name'        => 'pmw/acknowledge-notice',
				'label'       => __('Acknowledge Notice', 'woocommerce-google-adwords-conversion-tracking-tag'),
				'description' => __('Answers an admin notice or card the way the user would in the admin. type and id: generic-notification with the notification id (for example trial-promotion) or dismiss_notification with a Classic notification id dismiss it; onboarding with dismiss hides the checklist, with consent, diagnostics, pixie or pro marks that step done; first_event with dismiss-celebration hides the first order celebration; server_load_warning with capi acknowledges the server load warning; rating with rating_done or later answers the rating request. Only do this when the user asked for it.', 'woocommerce-google-adwords-conversion-tracking-tag'),
				'input'       => [
					'type' => [ 'type' => 'string', 'enum' => $acknowledge_types ],
					'id'   => [ 'type' => 'string', 'description' => 'Notice id or action, see the description' ],
				],
				'required'    => [ 'type', 'id' ],
				'output'      => self::success_schema(),
				'annotations' => [ 'idempotent' => true ],
				'write'       => true,
				'execute'     => function ( $input ) {
					return self::dispatch('POST', '/notifications/', [ 'type' => (string) $input['type'], 'id' => (string) $input['id'] ]);
				},
			],
		];
	}

	/*
	|--------------------------------------------------------------------------
	| Lifetime value recalculation
	|--------------------------------------------------------------------------
	*/

	private static function ltv_definitions() {

		$ltv_output = [
			'type'       => 'object',
			'properties' => [
				'message' => [ 'type' => 'string' ],
				'status'  => [
					'type'       => 'object',
					'properties' => [
						'is_running'   => [ 'type' => 'boolean' ],
						'is_scheduled' => [ 'type' => 'boolean' ],
					],
				],
			],
		];

		return [
			[
				'name'        => 'pmw/get-ltv-recalculation-status',
				'label'       => __('Get LTV Recalculation Status', 'woocommerce-google-adwords-conversion-tracking-tag'),
				'description' => __('Returns whether a lifetime value recalculation over the order history is running or scheduled. The lifetime value calculation requires an active Pro license; without one the call is refused with that reason.', 'woocommerce-google-adwords-conversion-tracking-tag'),
				'output'      => $ltv_output,
				'annotations' => [ 'readonly' => true, 'idempotent' => true ],
				'execute'     => function () {
					return self::dispatch('POST', '/ltv/', [ 'action' => 'get_ltv_recalculation_status' ]);
				},
			],
			[
				'name'        => 'pmw/run-ltv-recalculation',
				'label'       => __('Run LTV Recalculation', 'woocommerce-google-adwords-conversion-tracking-tag'),
				'description' => __('Recalculates the customer lifetime value of the whole order history through the Action Scheduler, like the recalculation control on the General page. mode run starts now, mode schedule queues it for the next quiet moment. It walks every order and loads the server for a while on large shops, so confirm with the user first. Requires an active Pro license and a recent Action Scheduler; refused with the reason otherwise. Stop with pmw/stop-ltv-recalculation.', 'woocommerce-google-adwords-conversion-tracking-tag'),
				'input'       => [
					'mode' => [ 'type' => 'string', 'enum' => [ 'run', 'schedule' ], 'description' => 'run starts now, schedule queues it. Default run.' ],
				],
				'output'      => $ltv_output,
				'annotations' => [],
				'write'       => true,
				'execute'     => function ( $input ) {

					$action = isset($input['mode']) && 'schedule' === $input['mode'] ? 'schedule_ltv_recalculation' : 'run_ltv_recalculation';

					return self::dispatch('POST', '/ltv/', [ 'action' => $action ]);
				},
			],
			[
				'name'        => 'pmw/stop-ltv-recalculation',
				'label'       => __('Stop LTV Recalculation', 'woocommerce-google-adwords-conversion-tracking-tag'),
				'description' => __('Cancels a running or scheduled lifetime value recalculation. Values already recalculated stay.', 'woocommerce-google-adwords-conversion-tracking-tag'),
				'output'      => $ltv_output,
				'annotations' => [ 'idempotent' => true ],
				'write'       => true,
				'execute'     => function () {
					return self::dispatch('POST', '/ltv/', [ 'action' => 'stop_ltv_recalculation' ]);
				},
			],
		];
	}

	/*
	|--------------------------------------------------------------------------
	| SweetCode Cloud server-side proxy (Pro)
	|--------------------------------------------------------------------------
	*/

	private static function ssp_definitions() {

		$status_schema = [
			'type'        => 'object',
			'description' => 'Connection state: enabled, has_token, proxy_hostname, routing_status, config_status, last_sync_at, last_sync_error and its details, requires_reauth, is_active, proxy_failure_behavior, quota_exceeded, plan_name, subscription_status, usage_percent, monthly_request_limit, billable_this_period, failed_destinations',
		];

		$message_status = [
			'type'       => 'object',
			'properties' => [
				'success' => [ 'type' => 'boolean' ],
				'message' => [ 'type' => 'string' ],
				'status'  => $status_schema,
			],
		];

		return [
			[
				'name'        => 'pmw/get-ssp-status',
				'label'       => __('Get SweetCode Cloud Status', 'woocommerce-google-adwords-conversion-tracking-tag'),
				'description' => __('Returns the state of the SweetCode Cloud server-side proxy connection shown on the Server-Side page: whether it is connected and active, the proxy hostname, the last configuration sync and its error, plan, usage and quota, and destinations that are failing. Call this first before changing anything about SweetCode Cloud. The sync token is never returned.', 'woocommerce-google-adwords-conversion-tracking-tag'),
				'output'      => [
					'type'       => 'object',
					'properties' => [
						'success' => [ 'type' => 'boolean' ],
						'status'  => $status_schema,
					],
				],
				'annotations' => [ 'readonly' => true, 'idempotent' => true ],
				'execute'     => function () {
					return self::dispatch('GET', '/ssp/status');
				},
			],
			[
				'name'        => 'pmw/get-ssp-diagnostics',
				'label'       => __('Get SweetCode Cloud Diagnostics', 'woocommerce-google-adwords-conversion-tracking-tag'),
				'description' => __('Returns the SweetCode Cloud diagnostic report for support: structured fields plus the same content as text. Tokens are redacted.', 'woocommerce-google-adwords-conversion-tracking-tag'),
				'output'      => [
					'type'       => 'object',
					'properties' => [
						'success'     => [ 'type' => 'boolean' ],
						'diagnostics' => [ 'type' => 'object', 'description' => 'fields (structured) and text (report)' ],
					],
				],
				'annotations' => [ 'readonly' => true, 'idempotent' => true ],
				'execute'     => function () {
					return self::dispatch('GET', '/ssp/diagnostics');
				},
			],
			[
				'name'        => 'pmw/test-ssp-connection',
				'label'       => __('Test SweetCode Cloud Connection', 'woocommerce-google-adwords-conversion-tracking-tag'),
				'description' => __('Checks from the shop\'s server whether the SweetCode Cloud proxy hostname is reachable, like the connection test on the Server-Side page. success false with details means it is not reachable; the call itself does not fail then.', 'woocommerce-google-adwords-conversion-tracking-tag'),
				'output'      => [
					'type'       => 'object',
					'properties' => [
						'success' => [ 'type' => 'boolean', 'description' => 'The proxy is reachable' ],
						'message' => [ 'type' => 'string' ],
						'details' => [ 'type' => 'object', 'description' => 'reachable, status_code and hostname' ],
					],
				],
				'annotations' => [ 'readonly' => true, 'idempotent' => true ],
				'execute'     => function () {
					return self::dispatch('POST', '/ssp/test');
				},
			],
			[
				'name'        => 'pmw/connect-ssp',
				'label'       => __('Connect SweetCode Cloud', 'woocommerce-google-adwords-conversion-tracking-tag'),
				'description' => __('Connects the shop to SweetCode Cloud with the sync token from the SweetCode Cloud portal, like Activate on the Server-Side page: stores the token, registers the shop and pushes the tracking configuration. Refused when the token is missing or malformed, or when the portal rejects it (for example a token issued for another domain). Ask the user for the token; never guess one.', 'woocommerce-google-adwords-conversion-tracking-tag'),
				'input'       => [
					'sync_token' => [ 'type' => 'string', 'pattern' => '^[a-fA-F0-9]{32,64}$', 'description' => 'Sync token from the SweetCode Cloud portal, 32 to 64 hex characters' ],
				],
				'required'    => [ 'sync_token' ],
				'output'      => [
					'type'       => 'object',
					'properties' => [
						'success'     => [ 'type' => 'boolean' ],
						'message'     => [ 'type' => 'string' ],
						'domain_info' => [ 'type' => self::ANY ],
						'status'      => $status_schema,
					],
				],
				'annotations' => [ 'idempotent' => true ],
				'write'       => true,
				'execute'     => function ( $input ) {
					return self::dispatch('POST', '/ssp/activate', [ 'sync_token' => (string) $input['sync_token'] ]);
				},
			],
			[
				'name'        => 'pmw/sync-ssp-config',
				'label'       => __('Sync SweetCode Cloud Configuration', 'woocommerce-google-adwords-conversion-tracking-tag'),
				'description' => __('Pushes the current tracking configuration to SweetCode Cloud now, like Sync on the Server-Side page. Settings changes are pushed automatically; use this after a failed sync (see last_sync_error in pmw/get-ssp-status).', 'woocommerce-google-adwords-conversion-tracking-tag'),
				'output'      => $message_status,
				'annotations' => [ 'idempotent' => true ],
				'write'       => true,
				'execute'     => function () {
					return self::dispatch('POST', '/ssp/sync');
				},
			],
			[
				'name'        => 'pmw/set-ssp-enabled',
				'label'       => __('Enable or Disable SweetCode Cloud', 'woocommerce-google-adwords-conversion-tracking-tag'),
				'description' => __('Turns routing through SweetCode Cloud on or off while keeping the connection, like the toggle on the Server-Side page. Disabled, server-side events are sent from the shop directly again. Enabling is refused while no sync token is stored; connect first with pmw/connect-ssp.', 'woocommerce-google-adwords-conversion-tracking-tag'),
				'input'       => [
					'enabled' => [ 'type' => 'boolean' ],
				],
				'required'    => [ 'enabled' ],
				'output'      => $message_status,
				'annotations' => [ 'idempotent' => true ],
				'write'       => true,
				'execute'     => function ( $input ) {
					return self::dispatch('POST', empty($input['enabled']) ? '/ssp/disable' : '/ssp/enable');
				},
			],
			[
				'name'        => 'pmw/update-ssp-setting',
				'label'       => __('Update SweetCode Cloud Setting', 'woocommerce-google-adwords-conversion-tracking-tag'),
				'description' => __('Changes what happens to server-side events while SweetCode Cloud is unreachable: fallback_to_wc sends them from the shop directly, drop_events discards them. The same control as on the Server-Side page.', 'woocommerce-google-adwords-conversion-tracking-tag'),
				'input'       => [
					'proxy_failure_behavior' => [ 'type' => 'string', 'enum' => [ 'fallback_to_wc', 'drop_events' ] ],
				],
				'required'    => [ 'proxy_failure_behavior' ],
				'output'      => [
					'type'       => 'object',
					'properties' => [
						'success' => [ 'type' => 'boolean' ],
						'message' => [ 'type' => 'string' ],
						'value'   => [ 'type' => 'string' ],
					],
				],
				'annotations' => [ 'idempotent' => true ],
				'write'       => true,
				'execute'     => function ( $input ) {
					return self::dispatch('POST', '/ssp/save-setting', [
						'key'   => 'proxy_failure_behavior',
						'value' => (string) $input['proxy_failure_behavior'],
					]);
				},
			],
			[
				'name'        => 'pmw/disconnect-ssp',
				'label'       => __('Disconnect SweetCode Cloud', 'woocommerce-google-adwords-conversion-tracking-tag'),
				'description' => __('Disconnects the shop from SweetCode Cloud, like Disconnect on the Server-Side page: deletes the sync token, proxy hostname and callback tokens and tells SweetCode Cloud. It cannot be undone; reconnecting needs the sync token from the portal again. To pause routing instead, use pmw/set-ssp-enabled with enabled false. Only do this when the user explicitly asked for it.', 'woocommerce-google-adwords-conversion-tracking-tag'),
				'output'      => $message_status,
				'annotations' => [ 'destructive' => true, 'idempotent' => true ],
				'write'       => true,
				'execute'     => function () {
					return self::dispatch('POST', '/ssp/disconnect');
				},
			],
		];
	}

	/*
	|--------------------------------------------------------------------------
	| GA4 Data API (Pro)
	|--------------------------------------------------------------------------
	*/

	private static function ga4_data_api_definitions() {

		return [
			[
				'name'        => 'pmw/update-ga4-data-api-credentials',
				'label'       => __('Update GA4 Data API Credentials', 'woocommerce-google-adwords-conversion-tracking-tag'),
				'description' => __('Stores the Google service account key (the JSON file from Google Cloud) that lets the plugin read order attribution from the GA4 Data API, like the credentials upload in the GA4 settings. Every field is validated; an empty object removes the stored credentials. The private key is stored as a secret and never returned. Only use a key file the user provided.', 'woocommerce-google-adwords-conversion-tracking-tag'),
				'input'       => [
					'credentials' => [
						'type'                 => 'object',
						'description'          => 'The service account key JSON: type service_account, project_id, private_key_id, private_key, client_email, client_id, auth_uri, token_uri, auth_provider_x509_cert_url, client_x509_cert_url',
						'additionalProperties' => true,
					],
				],
				'required'    => [ 'credentials' ],
				'output'      => [
					'type'       => 'object',
					'properties' => [
						'message' => [ 'type' => 'string' ],
					],
				],
				'annotations' => [ 'idempotent' => true ],
				'write'       => true,
				'execute'     => function ( $input ) {
					return self::dispatch('POST', '/ga4/data-api/credentials/', (array) $input['credentials']);
				},
			],
			[
				'name'        => 'pmw/get-order-attribution',
				'label'       => __('Get Order Attribution', 'woocommerce-google-adwords-conversion-tracking-tag'),
				'description' => __('Reads the GA4 attribution of one order (source, medium, campaign and the like) from the GA4 Data API, the table the order edit page shows. Requires GA4 Data API credentials (pmw/update-ga4-data-api-credentials). GA4 needs up to 48 hours before an order appears, so a recent order can return no rows. The result is the rendered table as HTML.', 'woocommerce-google-adwords-conversion-tracking-tag'),
				'input'       => [
					'order_id' => [ 'type' => 'integer', 'minimum' => 1 ],
				],
				'required'    => [ 'order_id' ],
				'output'      => [
					'type'       => 'object',
					'properties' => [
						'order_id' => [ 'type' => 'integer' ],
						'html'     => [ 'type' => 'string', 'description' => 'Attribution table as HTML' ],
					],
				],
				'annotations' => [ 'readonly' => true, 'idempotent' => true ],
				'execute'     => function ( $input ) {

					$html = self::dispatch('POST', '/ga4/data-api/get-order-attribution-data/', [ 'order_id' => absint($input['order_id']) ]);

					if (is_wp_error($html)) {
						return $html;
					}

					return [
						'order_id' => absint($input['order_id']),
						'html'     => is_string($html) ? $html : wp_json_encode($html),
					];
				},
			],
		];
	}

	/*
	|--------------------------------------------------------------------------
	| Google Ads Data Manager upload (Pro, experimental)
	|--------------------------------------------------------------------------
	*/

	private static function data_manager_definitions() {

		return [
			[
				'name'        => 'pmw/get-google-data-manager-status',
				'label'       => __('Get Google Data Manager Status', 'woocommerce-google-adwords-conversion-tracking-tag'),
				'description' => __('Returns whether the Google Ads Data Manager conversion upload is active, the outcome of the last upload, and the browser-versus-upload summary shown on the Google card.', 'woocommerce-google-adwords-conversion-tracking-tag'),
				'output'      => [
					'type'       => 'object',
					'properties' => [
						'active'     => [ 'type' => 'boolean' ],
						'lastUpload' => [ 'type' => [ 'object', 'null' ] ],
						'delta'      => [ 'type' => [ 'object', 'null' ] ],
					],
				],
				'annotations' => [ 'readonly' => true, 'idempotent' => true ],
				'execute'     => function () {
					return self::dispatch('GET', '/google/data-manager/upload-status/');
				},
			],
			[
				'name'        => 'pmw/get-google-data-manager-diagnostics',
				'label'       => __('Get Google Data Manager Diagnostics', 'woocommerce-google-adwords-conversion-tracking-tag'),
				'description' => __('Returns the Google Ads Data Manager diagnostics of the Diagnostics page: the conversion action in use, the daily identifier coverage for 30 days, the recent orders with the identifiers each side had, and a text report for support.', 'woocommerce-google-adwords-conversion-tracking-tag'),
				'output'      => [
					'type'       => 'object',
					'properties' => [
						'active'     => [ 'type' => 'boolean' ],
						'actionName' => [ 'type' => self::ANY ],
						'actionId'   => [ 'type' => self::ANY ],
						'accountId'  => [ 'type' => self::ANY ],
						'lastUpload' => [ 'type' => [ 'object', 'null' ] ],
						'delta'      => [ 'type' => self::ANY ],
						'coverage'   => [ 'type' => self::ANY ],
						'recent'     => [ 'type' => self::ANY ],
						'debugText'  => [ 'type' => 'string' ],
					],
				],
				'annotations' => [ 'readonly' => true, 'idempotent' => true ],
				'execute'     => function () {
					return self::dispatch('GET', '/google/data-manager/diagnostics/');
				},
			],
			[
				'name'        => 'pmw/test-google-data-manager-upload',
				'label'       => __('Test Google Data Manager Upload', 'woocommerce-google-adwords-conversion-tracking-tag'),
				'description' => __('Sends the latest order to the Google Ads Data Manager as a validate-only upload, like the test button on the Google card: Google checks authentication, account access, conversion action and payload without recording a conversion. Refused until the upload is configured.', 'woocommerce-google-adwords-conversion-tracking-tag'),
				'output'      => [
					'type'       => 'object',
					'properties' => [
						'success'        => [ 'type' => 'boolean' ],
						'code'           => [ 'type' => self::ANY ],
						'request_id'     => [ 'type' => self::ANY ],
						'error_message'  => [ 'type' => self::ANY ],
						'order_number'   => [ 'type' => self::ANY ],
						'identifiers'    => [ 'type' => self::ANY ],
						'config_warning' => [ 'type' => self::ANY ],
					],
				],
				'annotations' => [ 'readonly' => true, 'idempotent' => true ],
				'execute'     => function () {
					return self::dispatch('POST', '/google/data-manager/test-upload/');
				},
			],
		];
	}

	/*
	|--------------------------------------------------------------------------
	| Google account connection (experimental)
	|--------------------------------------------------------------------------
	*/

	private static function google_connection_definitions() {

		$passthrough = [ 'type' => 'object', 'additionalProperties' => true ];

		$connect_start = [
			'type'       => 'object',
			'properties' => [
				'redirectUrl' => [ 'type' => 'string', 'description' => 'Consent page the user has to open in their browser' ],
			],
		];

		return [
			[
				'name'        => 'pmw/get-google-connection-status',
				'label'       => __('Get Google Connection Status', 'woocommerce-google-adwords-conversion-tracking-tag'),
				'description' => __('Returns whether the shop is connected to a Google account through the SweetCode connection service, which Google account, and the state of each product: ga4, ads and datamanager are ready, scope_missing (the user declined that permission on Google\'s consent screen) or not_connected. needsReconnect true means the grant expired or was revoked. Call this first before any other Google connection ability.', 'woocommerce-google-adwords-conversion-tracking-tag'),
				'output'      => $passthrough,
				'annotations' => [ 'readonly' => true, 'idempotent' => true ],
				'execute'     => function () {
					return self::dispatch('GET', '/broker/status');
				},
			],
			[
				'name'        => 'pmw/start-google-connection',
				'label'       => __('Start Google Connection', 'woocommerce-google-adwords-conversion-tracking-tag'),
				'description' => __('Starts connecting a Google account and returns the Google consent page URL. Consent needs the user: hand redirectUrl to them to open in their browser and sign in; Google then sends them back to return_url. An agent cannot complete this step itself. Afterwards call pmw/get-google-connection-status. return_url must be on this site, for example the Pixel Manager admin page.', 'woocommerce-google-adwords-conversion-tracking-tag'),
				'input'       => [
					'return_url' => [ 'type' => 'string', 'format' => 'uri', 'description' => 'Page on this site the user returns to after consent. Default: the Pixel Manager admin page.' ],
				],
				'output'      => $connect_start,
				'annotations' => [],
				'write'       => true,
				'execute'     => function ( $input ) {
					return self::dispatch('POST', '/broker/connect/start', [ 'returnUrl' => self::return_url($input) ]);
				},
			],
			[
				'name'        => 'pmw/disconnect-google',
				'label'       => __('Disconnect Google', 'woocommerce-google-adwords-conversion-tracking-tag'),
				'description' => __('Disconnects the Google account: revokes the plugin\'s Google grant and turns off the Data Manager upload. The GA4 and Google Ads IDs in the settings stay, so browser tracking continues. Reconnecting needs the user to go through Google\'s consent again. Only do this when the user explicitly asked for it.', 'woocommerce-google-adwords-conversion-tracking-tag'),
				'output'      => $passthrough,
				'annotations' => [ 'destructive' => true, 'idempotent' => true ],
				'write'       => true,
				'execute'     => function () {
					return self::dispatch('POST', '/broker/disconnect');
				},
			],
			[
				'name'        => 'pmw/list-google-ads-accounts',
				'label'       => __('List Google Ads Accounts', 'woocommerce-google-adwords-conversion-tracking-tag'),
				'description' => __('Lists the Google Ads accounts the connected Google account can access. An account reached through a manager account carries loginCustomerId; pass it on to the other Google Ads abilities. Requires ads ready in pmw/get-google-connection-status.', 'woocommerce-google-adwords-conversion-tracking-tag'),
				'output'      => [
					'type'       => 'object',
					'properties' => [
						'customers' => [ 'type' => 'array', 'description' => 'id, name and optional loginCustomerId' ],
					],
				],
				'annotations' => [ 'readonly' => true, 'idempotent' => true ],
				'execute'     => function () {
					return self::dispatch('GET', '/broker/ads/customers');
				},
			],
			[
				'name'        => 'pmw/list-google-ads-conversion-actions',
				'label'       => __('List Google Ads Conversion Actions', 'woocommerce-google-adwords-conversion-tracking-tag'),
				'description' => __('Lists the conversion actions of a Google Ads account, to pick the purchase conversion for pmw/connect-google-ads-conversion. By default only the actions that fit website purchase tracking are listed; all true lists every action.', 'woocommerce-google-adwords-conversion-tracking-tag'),
				'input'       => [
					'customer_id'       => [ 'type' => 'string', 'pattern' => '^\d+$', 'description' => 'Google Ads account id (digits only) from pmw/list-google-ads-accounts' ],
					'login_customer_id' => [ 'type' => 'string', 'pattern' => '^\d*$', 'description' => 'Manager account id, when the account has one' ],
					'all'               => [ 'type' => 'boolean', 'description' => 'List every conversion action' ],
				],
				'required'    => [ 'customer_id' ],
				'output'      => [
					'type'       => 'object',
					'properties' => [
						'conversions' => [ 'type' => 'array', 'description' => 'id, name, type, status, category and optional conversionLabel' ],
					],
				],
				'annotations' => [ 'readonly' => true, 'idempotent' => true ],
				'execute'     => function ( $input ) {

					$query = [];

					if (!empty($input['login_customer_id'])) {
						$query['login'] = (string) $input['login_customer_id'];
					}

					if (!empty($input['all'])) {
						$query['all'] = '1';
					}

					return self::dispatch('GET', '/broker/ads/customers/' . self::digits($input['customer_id']) . '/conversions', $query);
				},
			],
			[
				'name'        => 'pmw/connect-google-ads-conversion',
				'label'       => __('Connect Google Ads Conversion', 'woocommerce-google-adwords-conversion-tracking-tag'),
				'description' => __('Sets up Google Ads conversion tracking with a conversion action, the last step of the Google Ads wizard: either an existing action (conversion_action_id) or a new one created in the Google Ads account (create_name). It waits up to 20 seconds for Google to publish the conversion tag, then saves conversion ID and label and the Data Manager upload settings, as the wizard does. When Google has not published the tag in time the action exists already; call again later with its conversion_action_id. Creating an action changes the user\'s Google Ads account, so confirm the name with the user.', 'woocommerce-google-adwords-conversion-tracking-tag'),
				'input'       => [
					'customer_id'          => [ 'type' => 'string', 'pattern' => '^\d+$', 'description' => 'Google Ads account id (digits only)' ],
					'login_customer_id'    => [ 'type' => 'string', 'pattern' => '^\d*$', 'description' => 'Manager account id, when the account has one' ],
					'conversion_action_id' => [ 'type' => 'string', 'pattern' => '^\d+$', 'description' => 'Existing conversion action id from pmw/list-google-ads-conversion-actions' ],
					'create_name'          => [ 'type' => 'string', 'minLength' => 1, 'description' => 'Name for a new purchase conversion action. Use instead of conversion_action_id.' ],
				],
				'required'    => [ 'customer_id' ],
				'output'      => [
					'type'       => 'object',
					'properties' => [
						'conversion_id'        => [ 'type' => 'string' ],
						'conversion_label'     => [ 'type' => 'string' ],
						'conversion_action_id' => [ 'type' => 'string' ],
						'created'              => [ 'type' => 'boolean', 'description' => 'A new conversion action was created' ],
						'reused'               => [ 'type' => 'boolean', 'description' => 'create_name matched an existing action, which was used' ],
						'settings'             => [ 'type' => 'array', 'description' => 'Per-setting save result' ],
					],
				],
				'annotations' => [],
				'write'       => true,
				'execute'     => [ __CLASS__, 'execute_connect_google_ads_conversion' ],
			],
			[
				'name'        => 'pmw/get-google-data-manager-permission',
				'label'       => __('Get Google Data Manager Permission', 'woocommerce-google-adwords-conversion-tracking-tag'),
				'description' => __('Returns whether the connected Google account granted the Data Manager permission the server-side conversion upload needs.', 'woocommerce-google-adwords-conversion-tracking-tag'),
				'output'      => $passthrough,
				'annotations' => [ 'readonly' => true, 'idempotent' => true ],
				'execute'     => function () {
					return self::dispatch('GET', '/broker/ads/datamanager/status');
				},
			],
			[
				'name'        => 'pmw/list-ga4-accounts',
				'label'       => __('List GA4 Accounts', 'woocommerce-google-adwords-conversion-tracking-tag'),
				'description' => __('Lists the Google Analytics accounts and their GA4 properties the connected Google account can access. Requires ga4 ready in pmw/get-google-connection-status.', 'woocommerce-google-adwords-conversion-tracking-tag'),
				'output'      => [
					'type'       => 'object',
					'properties' => [
						'accounts' => [ 'type' => 'array', 'description' => 'account, displayName and properties (property, displayName)' ],
					],
				],
				'annotations' => [ 'readonly' => true, 'idempotent' => true ],
				'execute'     => function () {
					return self::dispatch('GET', '/broker/ga4/accounts');
				},
			],
			[
				'name'        => 'pmw/create-ga4-property',
				'label'       => __('Create GA4 Property', 'woocommerce-google-adwords-conversion-tracking-tag'),
				'description' => __('Creates a GA4 property in a Google Analytics account, with the shop\'s time zone and currency, as the GA4 wizard does. It changes the user\'s Google Analytics account, so confirm with the user first. A property with the same name is reused (existing true). Next, pmw/connect-ga4-stream.', 'woocommerce-google-adwords-conversion-tracking-tag'),
				'input'       => [
					'account'      => [ 'type' => 'string', 'description' => 'Account resource name from pmw/list-ga4-accounts, for example accounts/123' ],
					'display_name' => [ 'type' => 'string', 'description' => 'Property name. Default: the site name.' ],
				],
				'required'    => [ 'account' ],
				'output'      => [
					'type'       => 'object',
					'properties' => [
						'propertyId'  => [ 'type' => [ 'string', 'integer' ] ],
						'displayName' => [ 'type' => 'string' ],
						'existing'    => [ 'type' => 'boolean' ],
					],
				],
				'annotations' => [],
				'write'       => true,
				'execute'     => function ( $input ) {

					$params = [ 'account' => (string) $input['account'] ];

					if (!empty($input['display_name'])) {
						$params['displayName'] = (string) $input['display_name'];
					}

					return self::dispatch('POST', '/broker/ga4/properties', $params);
				},
			],
			[
				'name'        => 'pmw/list-ga4-streams',
				'label'       => __('List GA4 Streams', 'woocommerce-google-adwords-conversion-tracking-tag'),
				'description' => __('Lists the web data streams of a GA4 property with their measurement IDs.', 'woocommerce-google-adwords-conversion-tracking-tag'),
				'input'       => [
					'property_id' => [ 'type' => 'string', 'pattern' => '^\d+$', 'description' => 'Numeric GA4 property id' ],
				],
				'required'    => [ 'property_id' ],
				'output'      => [
					'type'       => 'object',
					'properties' => [
						'streams' => [ 'type' => 'array', 'description' => 'stream, displayName and measurementId' ],
					],
				],
				'annotations' => [ 'readonly' => true, 'idempotent' => true ],
				'execute'     => function ( $input ) {
					return self::dispatch('GET', '/broker/ga4/properties/' . self::digits($input['property_id']) . '/streams');
				},
			],
			[
				'name'        => 'pmw/connect-ga4-stream',
				'label'       => __('Connect GA4 Stream', 'woocommerce-google-adwords-conversion-tracking-tag'),
				'description' => __('Sets up GA4 tracking with a web data stream, the last step of the GA4 wizard: either an existing stream (stream_id) or a new one created in the property (create_name). Saves the stream\'s measurement ID as google.analytics.ga4.measurement_id. Creating a stream changes the user\'s GA4 property, so confirm with the user first.', 'woocommerce-google-adwords-conversion-tracking-tag'),
				'input'       => [
					'property_id' => [ 'type' => 'string', 'pattern' => '^\d+$', 'description' => 'Numeric GA4 property id' ],
					'stream_id'   => [ 'type' => 'string', 'description' => 'Existing stream from pmw/list-ga4-streams' ],
					'create_name' => [ 'type' => 'string', 'minLength' => 1, 'description' => 'Name for a new web stream for this shop. Use instead of stream_id.' ],
				],
				'required'    => [ 'property_id' ],
				'output'      => [
					'type'       => 'object',
					'properties' => [
						'measurement_id' => [ 'type' => 'string' ],
						'reused'         => [ 'type' => 'boolean' ],
						'settings'       => [ 'type' => 'array', 'description' => 'Per-setting save result' ],
					],
				],
				'annotations' => [],
				'write'       => true,
				'execute'     => [ __CLASS__, 'execute_connect_ga4_stream' ],
			],
		];
	}

	/*
	|--------------------------------------------------------------------------
	| Meta account connection (experimental)
	|--------------------------------------------------------------------------
	*/

	private static function meta_connection_definitions() {

		$passthrough = [ 'type' => 'object', 'additionalProperties' => true ];

		return [
			[
				'name'        => 'pmw/get-meta-connection-status',
				'label'       => __('Get Meta Connection Status', 'woocommerce-google-adwords-conversion-tracking-tag'),
				'description' => __('Returns whether the shop is connected to a Meta (Facebook) business account through the SweetCode connection service, and as whom. needsReconnect true means the grant expired or was revoked.', 'woocommerce-google-adwords-conversion-tracking-tag'),
				'output'      => $passthrough,
				'annotations' => [ 'readonly' => true, 'idempotent' => true ],
				'execute'     => function () {
					return self::dispatch('GET', '/broker/meta/status');
				},
			],
			[
				'name'        => 'pmw/start-meta-connection',
				'label'       => __('Start Meta Connection', 'woocommerce-google-adwords-conversion-tracking-tag'),
				'description' => __('Starts connecting a Meta business account and returns the Facebook Login for Business URL. Hand redirectUrl to the user to open in their browser; an agent cannot complete the login. Afterwards call pmw/get-meta-connection-status. return_url must be on this site.', 'woocommerce-google-adwords-conversion-tracking-tag'),
				'input'       => [
					'return_url' => [ 'type' => 'string', 'format' => 'uri', 'description' => 'Page on this site the user returns to. Default: the Pixel Manager admin page.' ],
				],
				'output'      => [
					'type'       => 'object',
					'properties' => [
						'redirectUrl' => [ 'type' => 'string' ],
					],
				],
				'annotations' => [],
				'write'       => true,
				'execute'     => function ( $input ) {
					return self::dispatch('POST', '/broker/meta/connect/start', [ 'returnUrl' => self::return_url($input) ]);
				},
			],
			[
				'name'        => 'pmw/disconnect-meta',
				'label'       => __('Disconnect Meta', 'woocommerce-google-adwords-conversion-tracking-tag'),
				'description' => __('Disconnects the Meta business account. The pixel ID in the settings stays. Reconnecting needs the user to log in again. Only do this when the user explicitly asked for it.', 'woocommerce-google-adwords-conversion-tracking-tag'),
				'output'      => $passthrough,
				'annotations' => [ 'destructive' => true, 'idempotent' => true ],
				'write'       => true,
				'execute'     => function () {
					return self::dispatch('POST', '/broker/meta/disconnect');
				},
			],
			[
				'name'        => 'pmw/list-meta-pixels',
				'label'       => __('List Meta Pixels', 'woocommerce-google-adwords-conversion-tracking-tag'),
				'description' => __('Lists the Meta pixels (datasets) the connected business account can use, with their ad accounts.', 'woocommerce-google-adwords-conversion-tracking-tag'),
				'output'      => [
					'type'       => 'object',
					'properties' => [
						'pixels' => [ 'type' => 'array', 'description' => 'id, name, adAccountId and adAccountName' ],
					],
				],
				'annotations' => [ 'readonly' => true, 'idempotent' => true ],
				'execute'     => function () {
					return self::dispatch('GET', '/broker/meta/pixels');
				},
			],
			[
				'name'        => 'pmw/connect-meta-pixel',
				'label'       => __('Connect Meta Pixel', 'woocommerce-google-adwords-conversion-tracking-tag'),
				'description' => __('Sets up Meta tracking with a pixel, the last step of the Meta wizard: verifies the pixel with Meta and saves it as facebook.pixel_id. For server-side events a Conversions API token is set separately (facebook.capi.token via pmw/update-settings).', 'woocommerce-google-adwords-conversion-tracking-tag'),
				'input'       => [
					'pixel_id' => [ 'type' => 'string', 'pattern' => '^\d+$', 'description' => 'Pixel id from pmw/list-meta-pixels' ],
				],
				'required'    => [ 'pixel_id' ],
				'output'      => [
					'type'       => 'object',
					'properties' => [
						'pixel_id'   => [ 'type' => 'string' ],
						'pixel_name' => [ 'type' => self::ANY ],
						'settings'   => [ 'type' => 'array', 'description' => 'Per-setting save result' ],
					],
				],
				'annotations' => [ 'idempotent' => true ],
				'write'       => true,
				'execute'     => function ( $input ) {

					$selected = self::dispatch('POST', '/broker/meta/select', [ 'pixelId' => (string) $input['pixel_id'] ]);

					if (is_wp_error($selected)) {
						return $selected;
					}

					$pixel_id = isset($selected['pixelId']) ? (string) $selected['pixelId'] : (string) $input['pixel_id'];
					$saved    = self::patch_options([ 'facebook.pixel_id' => $pixel_id ]);

					if (is_wp_error($saved)) {
						return $saved;
					}

					return [
						'pixel_id'   => $pixel_id,
						'pixel_name' => isset($selected['pixelName']) ? $selected['pixelName'] : null,
						'settings'   => $saved,
					];
				},
			],
		];
	}

	/*
	|--------------------------------------------------------------------------
	| Execute callbacks with more than one step
	|--------------------------------------------------------------------------
	*/

	/**
	 * Execute callback for pmw/get-logs.
	 *
	 * @param array $input { source?: string, max_bytes?: int }
	 * @return array|\WP_Error
	 *
	 * @since 1.69.3
	 */
	public static function execute_get_logs( $input ) {

		$selection = Admin_REST::get_plugin_log_files(isset($input['source']) ? $input['source'] : null);

		if (is_wp_error($selection)) {
			return $selection;
		}

		$max_bytes = isset($input['max_bytes']) ? max(1024, min(1048576, (int) $input['max_bytes'])) : 65536;
		$files     = [];

		foreach ($selection['files'] as $file_name) {

			$path = trailingslashit($selection['dir']) . $file_name;

			if (!is_readable($path)) {
				continue;
			}

			$size   = (int) filesize($path);
			$offset = max(0, $size - $max_bytes);

			// Read only the end of the file: logs grow at the end and can be large.
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
			$content = file_get_contents($path, false, null, $offset);

			$files[] = [
				'name'      => $file_name,
				'size'      => $size,
				'modified'  => gmdate('c', (int) filemtime($path)),
				'truncated' => $offset > 0,
				'content'   => false === $content ? '' : $content,
			];
		}

		return [
			'source' => $selection['source'],
			'files'  => $files,
		];
	}

	/**
	 * Execute callback for pmw/connect-google-ads-conversion.
	 *
	 * The confirm step of the Nova Google Ads wizard (GoogleBrokerConnect.tsx),
	 * in PHP: create or select the conversion action, wait for Google to publish
	 * the conversion tag, then save the same settings through the same PATCH
	 * /options route the wizard writes them with.
	 *
	 * @param array $input Ability input.
	 * @return array|\WP_Error
	 *
	 * @since 1.69.3
	 */
	public static function execute_connect_google_ads_conversion( $input ) {

		$customer_id = self::digits($input['customer_id']);
		$login_id    = isset($input['login_customer_id']) ? self::digits($input['login_customer_id']) : '';
		$action_id   = isset($input['conversion_action_id']) ? self::digits($input['conversion_action_id']) : '';
		$create_name = isset($input['create_name']) ? trim((string) $input['create_name']) : '';

		if (( '' === $action_id ) === ( '' === $create_name )) {
			return new \WP_Error(
				'pmw_invalid_input',
				'Pass either conversion_action_id (an existing conversion action) or create_name (a new one), not both and not neither.',
				[ 'status' => 400 ]
			);
		}

		$params = [ 'customerId' => $customer_id ];

		if ('' !== $login_id) {
			$params['loginCustomerId'] = $login_id;
		}

		$reused = false;

		if ('' !== $create_name) {
			$tag    = self::dispatch('POST', '/broker/ads/conversions', array_merge($params, [ 'name' => $create_name ]));
			$reused = is_array($tag) && !empty($tag['existing']);
		} else {
			$tag = self::dispatch('POST', '/broker/ads/select', array_merge($params, [ 'conversionActionId' => $action_id ]));
		}

		if (is_wp_error($tag)) {
			return $tag;
		}

		$conversion_action_id = isset($tag['conversionActionId']) ? (string) $tag['conversionActionId'] : $action_id;
		$label_route          = '/broker/ads/customers/' . $customer_id . '/conversions/' . self::digits($conversion_action_id) . '/label';
		$label_query          = '' !== $login_id ? [ 'login' => $login_id ] : [];

		for ($i = 0; !empty($tag['pending']) && ( empty($tag['conversionId']) || empty($tag['conversionLabel']) ) && $i < self::TAG_POLL_ATTEMPTS; $i++) {

			usleep(self::TAG_POLL_DELAY_US);

			$tag = self::dispatch('GET', $label_route, $label_query);

			if (is_wp_error($tag)) {
				return $tag;
			}
		}

		if (empty($tag['conversionId']) || empty($tag['conversionLabel'])) {
			return new \WP_Error(
				'pmw_google_ads_tag_pending',
				'Google has not published the conversion tag yet. Try again in a minute; the conversion action itself is ready. Call again with conversion_action_id ' . $conversion_action_id . '.',
				[ 'status' => 409, 'conversion_action_id' => $conversion_action_id ]
			);
		}

		$action_name = $create_name;

		if ('' === $action_name) {

			$actions = self::dispatch('GET', '/broker/ads/customers/' . $customer_id . '/conversions', $label_query);

			if (is_array($actions) && isset($actions['conversions']) && is_array($actions['conversions'])) {
				foreach ($actions['conversions'] as $action) {
					if (isset($action['id']) && (string) $action['id'] === $conversion_action_id && isset($action['name'])) {
						$action_name = (string) $action['name'];
					}
				}
			}
		}

		$saved = self::patch_options([
			'google.ads.conversion_id'                         => (string) $tag['conversionId'],
			'google.ads.conversion_label'                      => (string) $tag['conversionLabel'],
			'google.ads.data_manager.operating_account_id'     => $customer_id,
			'google.ads.data_manager.login_account_id'         => $login_id,
			'google.ads.data_manager.conversion_action_id'     => $conversion_action_id,
			'google.ads.data_manager.conversion_action_name'   => $action_name,
		]);

		if (is_wp_error($saved)) {
			return $saved;
		}

		return [
			'conversion_id'        => (string) $tag['conversionId'],
			'conversion_label'     => (string) $tag['conversionLabel'],
			'conversion_action_id' => $conversion_action_id,
			'created'              => '' !== $create_name && !$reused,
			'reused'               => $reused,
			'settings'             => $saved,
		];
	}

	/**
	 * Execute callback for pmw/connect-ga4-stream.
	 *
	 * The confirm step of the Nova GA4 wizard: create or select the web stream,
	 * then save its measurement ID through PATCH /options.
	 *
	 * @param array $input Ability input.
	 * @return array|\WP_Error
	 *
	 * @since 1.69.3
	 */
	public static function execute_connect_ga4_stream( $input ) {

		$property_id = self::digits($input['property_id']);
		$stream_id   = isset($input['stream_id']) ? trim((string) $input['stream_id']) : '';
		$create_name = isset($input['create_name']) ? trim((string) $input['create_name']) : '';

		if (( '' === $stream_id ) === ( '' === $create_name )) {
			return new \WP_Error(
				'pmw_invalid_input',
				'Pass either stream_id (an existing web stream) or create_name (a new one), not both and not neither.',
				[ 'status' => 400 ]
			);
		}

		if ('' !== $create_name) {
			$stream = self::dispatch('POST', '/broker/ga4/streams', [ 'propertyId' => $property_id, 'displayName' => $create_name ]);
		} else {
			$stream = self::dispatch('POST', '/broker/ga4/select', [ 'propertyId' => $property_id, 'streamId' => $stream_id ]);
		}

		if (is_wp_error($stream)) {
			return $stream;
		}

		if (empty($stream['measurementId'])) {
			return new \WP_Error('pmw_no_measurement_id', 'Google returned no measurement ID for this stream.', [ 'status' => 502 ]);
		}

		$saved = self::patch_options([ 'google.analytics.ga4.measurement_id' => (string) $stream['measurementId'] ]);

		if (is_wp_error($saved)) {
			return $saved;
		}

		return [
			'measurement_id' => (string) $stream['measurementId'],
			'reused'         => !empty($stream['existing']),
			'settings'       => $saved,
		];
	}

	/*
	|--------------------------------------------------------------------------
	| Internal dispatch
	|--------------------------------------------------------------------------
	*/

	/**
	 * Dispatch a request to one of the plugin's own REST routes, the one the
	 * admin UI calls for the same action, and turn the answer into ability output.
	 *
	 * The route runs its own permission callback for the current user, so an
	 * ability can never do more than that user can do in the admin.
	 *
	 * @param string $method HTTP method.
	 * @param string $route  Route below /pmw/v1, with a leading slash.
	 * @param array  $params Query parameters for GET, JSON body otherwise.
	 * @return mixed|\WP_Error The response data, or a WP_Error with the route's own code and message.
	 *
	 * @since 1.69.3
	 */
	public static function dispatch( $method, $route, $params = [] ) {

		// WordPress stores routes registered with a trailing slash without it,
		// and the internal dispatcher does not normalize the requested path.
		$request = new \WP_REST_Request($method, '/pmw/v1' . untrailingslashit($route));

		if ('GET' === $method) {
			$request->set_query_params($params);
		} else {
			$request->set_header('Content-Type', 'application/json');
			$request->set_body(wp_json_encode((object) $params));
		}

		// Some callbacks check the REST nonce themselves, which guards cookie
		// sessions against cross-site requests. An ability call is not a cookie
		// request: the caller is already authenticated as this user (application
		// password or WP-CLI), and the route's permission callback still runs.
		$request->set_header('X-WP-Nonce', wp_create_nonce('wp_rest'));

		$response = rest_do_request($request);
		$status   = $response->get_status();
		$data     = rest_get_server()->response_to_data($response, false);

		if ($response->is_error() || $status >= 400) {
			return self::to_error($data, $status);
		}

		// Routes that answer in the wp_send_json_*() envelope.
		if (self::is_envelope($data)) {

			$payload = array_key_exists('data', $data) ? $data['data'] : null;

			if (empty($data['success'])) {
				return self::to_error($payload, 400);
			}

			return null === $payload ? [ 'success' => true ] : $payload;
		}

		return $data;
	}

	/**
	 * Save settings through PATCH /options, the route the admin UI's wizards
	 * write their results with.
	 *
	 * @param array $values Map of dot-notation path to value.
	 * @return array|\WP_Error Per-path result, or an error when any path was refused.
	 *
	 * @since 1.69.3
	 */
	private static function patch_options( $values ) {

		$patches = [];

		foreach ($values as $path => $value) {
			$patches[] = [ 'path' => $path, 'value' => $value ];
		}

		$result = self::dispatch('PATCH', '/options', [ 'patches' => $patches ]);

		if (is_wp_error($result)) {
			return $result;
		}

		$results = isset($result['results']) && is_array($result['results']) ? $result['results'] : [];
		$refused = [];

		foreach ($results as $row) {
			if (empty($row['success'])) {
				$refused[] = ( isset($row['path']) ? $row['path'] : '' ) . ': ' . ( isset($row['message']) ? $row['message'] : 'refused' );
			}
		}

		if (!empty($refused)) {
			return new \WP_Error(
				'pmw_settings_refused',
				'The account step succeeded, but these settings were not saved: ' . implode('; ', $refused),
				[ 'status' => 400, 'results' => $results ]
			);
		}

		return $results;
	}

	/**
	 * Whether a response body is the wp_send_json_*() envelope: success plus
	 * optionally data, nothing else. Bodies that merely contain a success
	 * field, like the SweetCode Cloud responses, are left alone.
	 *
	 * @param mixed $data Response data.
	 * @return bool
	 */
	private static function is_envelope( $data ) {
		return is_array($data)
			&& array_key_exists('success', $data)
			&& [] === array_diff(array_keys($data), [ 'success', 'data' ]);
	}

	/**
	 * Turn an error body into a WP_Error an agent can act on: the route's own
	 * code and message, the HTTP status, and the full body as details.
	 *
	 * @param mixed $data   Error body: a WP_Error array, a broker error, an envelope payload or a string.
	 * @param int   $status HTTP status.
	 * @return \WP_Error
	 */
	private static function to_error( $data, $status ) {

		$code    = 'pmw_request_refused';
		$message = 'The request was refused.';

		if (is_string($data) && '' !== $data) {
			$message = $data;
		} elseif (is_array($data)) {

			if (!empty($data['code']) && is_string($data['code'])) {
				$code = $data['code'];
			}

			if (!empty($data['message']) && is_string($data['message'])) {
				$message = $data['message'];
			}
		}

		if (404 === $status && 'rest_no_route' === $code) {
			$message = 'This feature is not available on this site (its REST route is not registered). It may need the Pro version or an experimental flag.';
		}

		return new \WP_Error($code, wp_strip_all_tags($message), [ 'status' => $status, 'details' => $data ]);
	}

	/*
	|--------------------------------------------------------------------------
	| Small helpers
	|--------------------------------------------------------------------------
	*/

	/**
	 * Replace every secret in a settings tree with null.
	 *
	 * Secrets are the catalog's secret settings plus the stored GA4 service
	 * account key and the SweetCode Cloud connection tokens.
	 *
	 * @param array $options Settings tree.
	 * @return array { settings: array, redacted_paths: string[] }
	 */
	private static function redact_secrets( $options ) {

		$options = (array) $options;
		$paths   = [ 'google.analytics.ga4.data_api.credentials', 'ssp.sync_token', 'ssp.resync_callback_token', 'ssp.domain_token', 'ssp.verification_key', 'ssp.additional_domain_keys' ];

		foreach (Abilities_Settings::get_path_index() as $path => $entry) {
			if (!empty($entry['secret'])) {
				$paths[] = $path;
			}
		}

		$redacted = [];

		foreach ($paths as $path) {

			$keys   = explode('.', $path);
			$last   = array_pop($keys);
			$parent = &$options;

			foreach ($keys as $key) {
				if (!isset($parent[$key]) || !is_array($parent[$key])) {
					unset($parent);
					continue 2;
				}
				$parent = &$parent[$key];
			}

			if (array_key_exists($last, $parent) && !empty($parent[$last])) {
				$parent[$last] = null;
				$redacted[]    = $path;
			}

			unset($parent);
		}

		return [
			'settings'       => $options,
			'redacted_paths' => $redacted,
		];
	}

	/**
	 * The return URL for an account connection: the given one, or the
	 * Pixel Manager admin page. The route itself refuses other hosts.
	 *
	 * @param array $input Ability input.
	 * @return string
	 */
	private static function return_url( $input ) {

		if (!empty($input['return_url'])) {
			return esc_url_raw((string) $input['return_url']);
		}

		return admin_url('admin.php?page=pmw');
	}

	/**
	 * Keep only the digits of an id.
	 *
	 * @param mixed $value Id as given.
	 * @return string
	 */
	private static function digits( $value ) {
		return preg_replace('/\D/', '', (string) $value);
	}

	/**
	 * Output schema of an action that only reports success.
	 *
	 * @return array
	 */
	private static function success_schema() {
		return [
			'type'       => 'object',
			'properties' => [
				'success' => [ 'type' => 'boolean' ],
			],
		];
	}
}
