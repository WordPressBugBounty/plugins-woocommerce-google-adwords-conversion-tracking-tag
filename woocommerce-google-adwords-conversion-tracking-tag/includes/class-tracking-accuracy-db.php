<?php

namespace SweetCode\Pixel_Manager;

defined('ABSPATH') || exit; // Exit if accessed directly

/**
 * Manages the pmw_tracking_accuracy custom table for event-driven
 * payment gateway accuracy tracking.
 *
 * Replaces the transient-based nightly batch analysis with per-order
 * UPSERT writes and fast aggregate reads.
 *
 * @since 1.58.5
 */
class Tracking_Accuracy_DB {

	const DB_VERSION                 = '1';
	const DB_VERSION_KEY             = 'pmw_tracking_accuracy_db_version';
	const TABLE_NAME                 = 'pmw_tracking_accuracy';
	const BACKFILL_HOOK              = 'pmw_tracking_accuracy_backfill';
	const BACKFILL_DONE              = 'pmw_tracking_accuracy_backfill_complete';
	const BACKFILL_CURSOR            = 'pmw_tracking_accuracy_backfill_cursor';
	const BACKFILL_LOCK              = 'pmw_tracking_accuracy_backfill_running';
	const BACKFILL_COUNT             = 'pmw_tracking_accuracy_backfill_continuations';
	const BACKFILL_CUTOFF            = 'pmw_tracking_accuracy_backfill_max_order_id';
	const BATCH_SIZE                 = 500;
	const BACKFILL_LIMIT             = 10000;
	const BACKFILL_MONTHS            = 3;
	const BACKFILL_TIME              = 20; // seconds per batch
	const BACKFILL_MAX_CONTINUATIONS = 50;

	/**
	 * Get the full table name with prefix.
	 *
	 * @return string
	 * @since 1.58.5
	 */
	public static function get_table_name() {
		global $wpdb;
		return $wpdb->prefix . self::TABLE_NAME;
	}

	/**
	 * Check if the table exists.
	 *
	 * @return bool
	 * @since 1.58.5
	 */
	public static function table_exists() {
		global $wpdb;

		$table = self::get_table_name();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$result = $wpdb->get_var(
			$wpdb->prepare('SHOW TABLES LIKE %s', $table)
		);

		return $result === $table;
	}

	/**
	 * Create or update the table schema.
	 *
	 * Safe to call multiple times — dbDelta handles diffs.
	 *
	 * @return void
	 * @since 1.58.5
	 */
	public static function create_table() {

		global $wpdb;

		$table           = self::get_table_name();
		$charset_collate = $wpdb->get_charset_collate();

		$sql = "CREATE TABLE {$table} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			date date NOT NULL,
			gateway_id varchar(100) NOT NULL,
			orders_total int unsigned NOT NULL DEFAULT 0,
			orders_measured int unsigned NOT NULL DEFAULT 0,
			orders_acr int unsigned NOT NULL DEFAULT 0,
			delay_sum int unsigned NOT NULL DEFAULT 0,
			PRIMARY KEY  (id),
			UNIQUE KEY date_gateway (date, gateway_id),
			KEY date_idx (date)
		) {$charset_collate};";

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		dbDelta($sql);

		if (!self::table_exists()) {
			Logger::error('Tracking accuracy DB: table creation failed');
			return;
		}

		update_option(self::DB_VERSION_KEY, self::DB_VERSION);

		// Record the highest order ID at table creation time.
		// The backfill will only process orders up to this ID,
		// preventing double-counting with event-driven writes.
		if (!get_option(self::BACKFILL_CUTOFF)) {
			$latest_order_ids = wc_get_orders([
				'limit'   => 1,
				'orderby' => 'ID',
				'order'   => 'DESC',
				'return'  => 'ids',
				'type'    => 'shop_order',
			]);

			$max_id = !empty($latest_order_ids) ? max($latest_order_ids) : 0;
			update_option(self::BACKFILL_CUTOFF, $max_id, false);
		}
	}

	/**
	 * Create the table if it doesn't exist or if the version has changed.
	 *
	 * @return void
	 * @since 1.58.5
	 */
	public static function maybe_create_table() {

		$current_version = get_option(self::DB_VERSION_KEY);

		if (self::DB_VERSION === $current_version) {
			return;
		}

		self::create_table();
	}

	/**
	 * Drop the table entirely. For use in uninstall.php only.
	 *
	 * @return void
	 * @since 1.58.5
	 */
	public static function drop_table() {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange
		$wpdb->query( 'DROP TABLE IF EXISTS ' . esc_sql( self::get_table_name() ) );

		delete_option(self::DB_VERSION_KEY);
		delete_option(self::BACKFILL_DONE);
		delete_option(self::BACKFILL_CURSOR);
		delete_option(self::BACKFILL_COUNT);
		delete_option(self::BACKFILL_CUTOFF);
		delete_transient(self::BACKFILL_LOCK);
	}

	// ─── Event-Driven Writes ───────────────────────────────────────────

	/**
	 * Increment orders_total for a date+gateway.
	 *
	 * Called from pmw_woocommerce_new_order() when an order is placed.
	 *
	 * @param string $date       Date in Y-m-d format.
	 * @param string $gateway_id Payment method ID.
	 *
	 * @return void
	 * @since 1.58.5
	 */
	public static function increment_orders_total( $date, $gateway_id ) {

		if (empty($gateway_id) || empty($date)) {
			return;
		}

		if (!self::table_exists()) {
			return;
		}

		global $wpdb;

		try {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$wpdb->query(
				$wpdb->prepare(
					// phpcs:ignore WordPress.DB.PreparedSQLPlaceholders.UnquotedComplexPlaceholder
					'INSERT INTO ' . esc_sql( self::get_table_name() ) . ' (`date`, gateway_id, orders_total) VALUES (%s, %s, 1)
					ON DUPLICATE KEY UPDATE orders_total = orders_total + 1',
					$date,
					$gateway_id
				)
			);

			if ($wpdb->last_error) {
				Logger::debug('Tracking accuracy DB: increment_orders_total error: ' . $wpdb->last_error);
			}
		} catch (\Exception $e) {
			Logger::debug('Tracking accuracy DB: increment_orders_total exception: ' . $e->getMessage());
		}
	}

	/**
	 * Increment orders_measured (or orders_acr) for a date+gateway when a pixel fire is confirmed.
	 *
	 * @param string $date       Order creation date in Y-m-d format.
	 * @param string $gateway_id Payment method ID.
	 * @param string $source     Pixel trigger source ('thankyou_page' or ACR variant).
	 * @param int    $delay      Seconds between order creation and pixel fire.
	 *
	 * @return void
	 * @since 1.58.5
	 */
	public static function increment_orders_measured( $date, $gateway_id, $source, $delay = 0 ) {

		if (empty($gateway_id) || empty($date)) {
			return;
		}

		if (!self::table_exists()) {
			return;
		}

		$column = ( 'thankyou_page' === $source ) ? 'orders_measured' : 'orders_acr';
		$delay  = max(0, intval($delay));

		global $wpdb;

		try {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$wpdb->query(
				$wpdb->prepare(
					// phpcs:ignore WordPress.DB.PreparedSQLPlaceholders.UnquotedComplexPlaceholder
					'INSERT INTO ' . esc_sql( self::get_table_name() ) . ' (`date`, gateway_id, ' . esc_sql( $column ) . ', delay_sum) VALUES (%s, %s, 1, %d)'
					. ' ON DUPLICATE KEY UPDATE ' . esc_sql( $column ) . ' = ' . esc_sql( $column ) . ' + 1, delay_sum = delay_sum + %d',
					$date,
					$gateway_id,
					$delay,
					$delay
				)
			);

			if ($wpdb->last_error) {
				Logger::debug('Tracking accuracy DB: increment_orders_measured error: ' . $wpdb->last_error);
			}
		} catch (\Exception $e) {
			Logger::debug('Tracking accuracy DB: increment_orders_measured exception: ' . $e->getMessage());
		}
	}

	// ─── Read Layer ────────────────────────────────────────────────────

	/**
	 * Get aggregated accuracy data for the last N days, grouped by gateway.
	 *
	 * @param int        $days        Number of days to look back (default 30).
	 * @param array|null $gateway_ids Optional list of gateway IDs to filter. Null = all.
	 *
	 * @return array Array of gateway analysis rows.
	 * @since 1.58.5
	 */
	public static function get_accuracy_data( $days = 30, $gateway_ids = null ) {

		if (!self::table_exists()) {
			return [];
		}

		global $wpdb;

		$date_cutoff = gmdate('Y-m-d', strtotime("-{$days} days"));

		if (null !== $gateway_ids && is_array($gateway_ids) && !empty($gateway_ids)) {
			$placeholders = implode( ',', array_fill( 0, count( $gateway_ids ), '%s' ) );

			// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$results = $wpdb->get_results(
				$wpdb->prepare(
					'SELECT gateway_id,
						SUM(orders_total) AS orders_total,
						SUM(orders_measured) AS orders_measured,
						SUM(orders_acr) AS orders_acr,
						SUM(delay_sum) AS delay_sum
					FROM ' . esc_sql( self::get_table_name() ) . "
					WHERE `date` >= %s AND gateway_id IN ({$placeholders})
					GROUP BY gateway_id
					ORDER BY SUM(orders_total) DESC",
					$date_cutoff,
					...$gateway_ids
				),
				ARRAY_A
			);
			// phpcs:enable WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		} else {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$results = $wpdb->get_results(
				$wpdb->prepare(
					'SELECT gateway_id,
						SUM(orders_total) AS orders_total,
						SUM(orders_measured) AS orders_measured,
						SUM(orders_acr) AS orders_acr,
						SUM(delay_sum) AS delay_sum
					FROM ' . esc_sql( self::get_table_name() ) . '
					WHERE `date` >= %s
					GROUP BY gateway_id
					ORDER BY SUM(orders_total) DESC',
					$date_cutoff
				),
				ARRAY_A
			);
		}

		if (!is_array($results)) {
			return [];
		}

		// Cast string values to integers
		foreach ($results as &$row) {
			$row['orders_total']    = intval($row['orders_total']);
			$row['orders_measured'] = intval($row['orders_measured']);
			$row['orders_acr']      = intval($row['orders_acr']);
			$row['delay_sum']       = intval($row['delay_sum']);
		}

		return $results;
	}

	/**
	 * Check if the table has any data.
	 *
	 * @return bool
	 * @since 1.58.5
	 */
	public static function has_data() {

		if (!self::table_exists()) {
			return false;
		}

		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$count = $wpdb->get_var( 'SELECT COUNT(*) FROM ' . esc_sql( self::get_table_name() ) );

		return intval($count) > 0;
	}

	// ─── Backfill ──────────────────────────────────────────────────────

	/**
	 * Schedule the backfill to run at 2:00 AM if not already done.
	 *
	 * @return void
	 * @since 1.58.5
	 */
	public static function maybe_schedule_backfill() {

		if (get_option(self::BACKFILL_DONE)) {
			return;
		}

		if (!function_exists('as_schedule_single_action')) {
			return;
		}

		if (Helpers::pmw_as_has_scheduled_action(self::BACKFILL_HOOK)) {
			return;
		}

		$tomorrow_2am = strtotime('tomorrow 2:00am');

		as_schedule_single_action($tomorrow_2am, self::BACKFILL_HOOK);
	}

	/**
	 * Run the backfill process. Called by Action Scheduler.
	 *
	 * Processes orders in batches with time and continuation limits.
	 *
	 * @return void
	 * @since 1.58.5
	 */
	public static function run_backfill() {

		if (get_option(self::BACKFILL_DONE)) {
			return;
		}

		if (!self::table_exists()) {
			Logger::debug('Tracking accuracy DB: backfill skipped — table does not exist');
			return;
		}

		// Lock to prevent concurrent runs
		if (get_transient(self::BACKFILL_LOCK)) {
			return;
		}
		set_transient(self::BACKFILL_LOCK, true, 10 * MINUTE_IN_SECONDS);

		// Increment continuation count immediately so it's always tracked,
		// even if an exception occurs later in the process.
		$continuations = intval(get_option(self::BACKFILL_COUNT, 0));
		update_option(self::BACKFILL_COUNT, $continuations + 1, false);

		if ($continuations >= self::BACKFILL_MAX_CONTINUATIONS) {
			Logger::info('Tracking accuracy DB: backfill aborted after ' . $continuations . ' continuations');
			self::complete_backfill();
			return;
		}

		// Only backfill orders that existed before the table was created,
		// preventing double-counting with event-driven writes.
		$max_order_id = intval(get_option(self::BACKFILL_CUTOFF, 0));

		if ($max_order_id <= 0) {
			Logger::debug('Tracking accuracy DB: backfill skipped — no cutoff order ID set');
			self::complete_backfill();
			return;
		}

		try {
			self::run_backfill_inner($max_order_id);
		} catch (\Exception $e) {
			Logger::debug('Tracking accuracy DB: backfill exception: ' . $e->getMessage());
			delete_transient(self::BACKFILL_LOCK);
		}
	}

	/**
	 * Inner backfill loop, extracted for exception safety.
	 *
	 * @param int $max_order_id Only process orders with ID <= this value.
	 *
	 * @return void
	 * @since 1.58.5
	 */
	private static function run_backfill_inner( $max_order_id ) {

		$start_time       = time();
		$cursor           = intval(get_option(self::BACKFILL_CURSOR, 0));
		$orders_processed = 0;
		$date_cutoff      = gmdate('Y-m-d', strtotime('-' . self::BACKFILL_MONTHS . ' months'));
		$memory_limit     = self::get_memory_limit_bytes();

		while (true) {

			// Time limit check
			if (( time() - $start_time ) >= self::BACKFILL_TIME) {
				break;
			}

			// Memory check (80% of limit)
			if ($memory_limit > 0 && memory_get_usage(true) > ( $memory_limit * 0.8 )) {
				Logger::debug('Tracking accuracy DB: backfill paused — memory limit approaching');
				break;
			}

			// Total orders limit check
			if ($orders_processed >= self::BACKFILL_LIMIT) {
				self::complete_backfill();
				return;
			}

			// Fetch batch of order IDs
			$order_ids = wc_get_orders([
				'limit'        => self::BATCH_SIZE,
				'type'         => 'shop_order',
				'orderby'      => 'ID',
				'order'        => 'ASC',
				'status'       => [ 'completed', 'processing', 'on-hold', 'pending' ],
				'date_created' => '>=' . $date_cutoff,
				'meta_key'     => '_wpm_process_through_wpm',
				'meta_value'   => true,
				'return'       => 'ids',
				'id'           => $cursor > 0 ? [ '>' . $cursor ] : null,
			]);

			// Filter by cursor — wc_get_orders doesn't support > comparison directly
			if ($cursor > 0) {
				$order_ids = array_filter($order_ids, function ( $id ) use ( $cursor ) {
					return $id > $cursor;
				});
			}

			// Only backfill orders up to the cutoff ID
			$order_ids = array_filter($order_ids, function ( $id ) use ( $max_order_id ) {
				return $id <= $max_order_id;
			});

			// No more orders — backfill complete
			if (empty($order_ids)) {
				self::complete_backfill();
				return;
			}

			// Bulk-fetch meta for this batch
			$orders_meta = self::get_backfill_meta($order_ids);

			// Aggregate per date+gateway before inserting
			$aggregated = [];

			foreach ($order_ids as $order_id) {

				try {
					if (!isset($orders_meta[$order_id])) {
						continue;
					}

					$meta = $orders_meta[$order_id];

					if (empty($meta['payment_method']) || empty($meta['date_created'])) {
						continue;
					}

					$date       = $meta['date_created'];
					$gateway_id = $meta['payment_method'];
					$key        = $date . '|' . $gateway_id;

					if (!isset($aggregated[$key])) {
						$aggregated[$key] = [
							'date'            => $date,
							'gateway_id'      => $gateway_id,
							'orders_total'    => 0,
							'orders_measured' => 0,
							'orders_acr'      => 0,
							'delay_sum'       => 0,
						];
					}

					++$aggregated[$key]['orders_total'];

					if ($meta['pixel_fired']) {
						if (null !== $meta['pixel_trigger'] && 'thankyou_page' !== $meta['pixel_trigger']) {
							++$aggregated[$key]['orders_acr'];
						} else {
							++$aggregated[$key]['orders_measured'];
						}

						if ($meta['delay'] > 0) {
							$aggregated[$key]['delay_sum'] += intval($meta['delay']);
						}
					}
				} catch (\Exception $e) {
					Logger::debug('Tracking accuracy DB: backfill error for order ' . $order_id . ': ' . $e->getMessage());
				}
			}

			// Bulk upsert the aggregated data
			self::upsert_aggregated($aggregated);

			$orders_processed += count($order_ids);
			$cursor            = max($order_ids);
			update_option(self::BACKFILL_CURSOR, $cursor, false);
		}

		// Time/memory limit reached — schedule continuation
		update_option(self::BACKFILL_CURSOR, $cursor, false);
		delete_transient(self::BACKFILL_LOCK);

		if (function_exists('as_schedule_single_action')) {
			as_schedule_single_action(time() + ( 5 * MINUTE_IN_SECONDS ), self::BACKFILL_HOOK);
		}
	}

	/**
	 * Mark the backfill as complete and clean up.
	 *
	 * @return void
	 * @since 1.58.5
	 */
	private static function complete_backfill() {
		update_option(self::BACKFILL_DONE, true, false);
		delete_option(self::BACKFILL_CURSOR);
		delete_option(self::BACKFILL_COUNT);
		delete_option(self::BACKFILL_CUTOFF);
		delete_transient(self::BACKFILL_LOCK);

		Logger::info('Tracking accuracy DB: backfill complete');
	}

	/**
	 * Fetch order meta needed for backfill in bulk.
	 *
	 * Returns payment_method, date_created, pixel_fired, pixel_trigger, delay.
	 *
	 * @param array $order_ids Array of order IDs.
	 *
	 * @return array Associative array keyed by order ID.
	 * @since 1.58.5
	 */
	private static function get_backfill_meta( $order_ids ) {

		global $wpdb;

		$result = [];

		if (empty($order_ids)) {
			return $result;
		}

		if (Helpers::is_wc_hpos_enabled()) {
			$placeholders = implode( ',', array_fill( 0, count( $order_ids ), '%d' ) );

			// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$rows = $wpdb->get_results(
				$wpdb->prepare(
					"SELECT
						o.id AS order_id,
						o.payment_method,
						DATE(o.date_created_gmt) AS date_created,
						MAX(CASE WHEN om.meta_key = '_wpm_conversion_pixel_fired' THEN om.meta_value END) AS pixel_fired,
						MAX(CASE WHEN om.meta_key = '_wpm_conversion_pixel_trigger' THEN om.meta_value END) AS pixel_trigger,
						MAX(CASE WHEN om.meta_key = '_wpm_conversion_pixel_fired_delay' THEN om.meta_value END) AS delay_val
					FROM {$wpdb->prefix}wc_orders o
					LEFT JOIN {$wpdb->prefix}wc_orders_meta om
						ON o.id = om.order_id
						AND om.meta_key IN ('_wpm_conversion_pixel_fired', '_wpm_conversion_pixel_trigger', '_wpm_conversion_pixel_fired_delay')
					WHERE o.id IN ({$placeholders})
					GROUP BY o.id",
					...$order_ids
				)
			);
			// phpcs:enable WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		} else {
			$placeholders = implode( ',', array_fill( 0, count( $order_ids ), '%d' ) );

			// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$rows = $wpdb->get_results(
				$wpdb->prepare(
					"SELECT
						p.ID AS order_id,
						MAX(CASE WHEN pm.meta_key = '_payment_method' THEN pm.meta_value END) AS payment_method,
						DATE(p.post_date_gmt) AS date_created,
						MAX(CASE WHEN pm.meta_key = '_wpm_conversion_pixel_fired' THEN pm.meta_value END) AS pixel_fired,
						MAX(CASE WHEN pm.meta_key = '_wpm_conversion_pixel_trigger' THEN pm.meta_value END) AS pixel_trigger,
						MAX(CASE WHEN pm.meta_key = '_wpm_conversion_pixel_fired_delay' THEN pm.meta_value END) AS delay_val
					FROM {$wpdb->posts} p
					LEFT JOIN {$wpdb->postmeta} pm
						ON p.ID = pm.post_id
						AND pm.meta_key IN ('_payment_method', '_wpm_conversion_pixel_fired', '_wpm_conversion_pixel_trigger', '_wpm_conversion_pixel_fired_delay')
					WHERE p.ID IN ({$placeholders})
					GROUP BY p.ID",
					...$order_ids
				)
			);
			// phpcs:enable WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		}

		if (!is_array($rows)) {
			return $result;
		}

		foreach ($rows as $row) {
			$result[$row->order_id] = [
				'payment_method' => isset($row->payment_method) ? $row->payment_method : '',
				'date_created'   => isset($row->date_created) ? $row->date_created : '',
				'pixel_fired'    => !empty($row->pixel_fired),
				'pixel_trigger'  => isset($row->pixel_trigger) ? $row->pixel_trigger : null,
				'delay'          => intval(isset($row->delay_val) ? $row->delay_val : 0),
			];
		}

		return $result;
	}

	/**
	 * Upsert aggregated backfill data into the table.
	 *
	 * @param array $aggregated Aggregated data keyed by "date|gateway_id".
	 *
	 * @return void
	 * @since 1.58.5
	 */
	private static function upsert_aggregated( $aggregated ) {

		if (empty($aggregated)) {
			return;
		}

		global $wpdb;

		foreach ($aggregated as $data) {
			try {
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
				$wpdb->query(
					$wpdb->prepare(
						// phpcs:ignore WordPress.DB.PreparedSQLPlaceholders.UnquotedComplexPlaceholder
						'INSERT INTO ' . esc_sql( self::get_table_name() ) . ' (`date`, gateway_id, orders_total, orders_measured, orders_acr, delay_sum)
						VALUES (%s, %s, %d, %d, %d, %d)
						ON DUPLICATE KEY UPDATE
							orders_total = orders_total + VALUES(orders_total),
							orders_measured = orders_measured + VALUES(orders_measured),
							orders_acr = orders_acr + VALUES(orders_acr),
							delay_sum = delay_sum + VALUES(delay_sum)',
						$data['date'],
						$data['gateway_id'],
						$data['orders_total'],
						$data['orders_measured'],
						$data['orders_acr'],
						$data['delay_sum']
					)
				);

				if ($wpdb->last_error) {
					Logger::debug('Tracking accuracy DB: upsert error: ' . $wpdb->last_error);
				}
			} catch (\Exception $e) {
				Logger::debug('Tracking accuracy DB: upsert exception: ' . $e->getMessage());
			}
		}
	}

	/**
	 * Get the PHP memory limit in bytes.
	 *
	 * @return int Memory limit in bytes, or 0 if unlimited.
	 * @since 1.58.5
	 */
	private static function get_memory_limit_bytes() {
		$limit = ini_get('memory_limit');

		if ('-1' === $limit) {
			return 0;
		}

		$value = intval($limit);
		$unit  = strtolower(substr($limit, -1));

		switch ($unit) {
			case 'g':
				$value *= 1024 * 1024 * 1024;
				break;
			case 'm':
				$value *= 1024 * 1024;
				break;
			case 'k':
				$value *= 1024;
				break;
		}

		return $value;
	}
}
