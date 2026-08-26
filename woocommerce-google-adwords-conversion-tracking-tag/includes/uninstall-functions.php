<?php
/**
 * Shared uninstall routines for the Pixel Manager for WooCommerce plugin.
 *
 * This file contains the actual data-deletion logic and is loaded from two
 * entry points, depending on the distribution:
 *
 * - WC Marketplace build: uninstall.php in the plugin root (WordPress runs it
 *   in isolation when the user deletes the plugin).
 * - Freemius build: the Freemius SDK 'after_uninstall' hook registered in
 *   freemius-loader.php. Freemius does not allow an uninstall.php in the
 *   plugin root because it would prevent the SDK from tracking the uninstall
 *   event.
 *
 * By default nothing is deleted — the user's settings are preserved so they
 * survive a reinstall. Data is only removed when the user has explicitly opted
 * in via the "Delete all plugin data on uninstall" toggle on the Support tab
 * (stored at general.delete_plugin_data_on_uninstall inside the main options).
 *
 * Because this can run without the rest of the plugin loaded (uninstall.php
 * context), every option name and the custom table name are hard-coded to
 * match the values defined in the plugin source.
 *
 * @since 1.59.0
 */

defined('ABSPATH') || exit; // Exit if accessed directly.

if (!function_exists('pmw_should_delete_plugin_data')) {

	/**
	 * Determine, for a given site, whether the user opted in to full data deletion.
	 *
	 * @return bool
	 */
	function pmw_should_delete_plugin_data() {

		$options = get_option('wgact_plugin_options');

		return is_array($options)
			&& isset($options['general']['delete_plugin_data_on_uninstall'])
			&& (bool) $options['general']['delete_plugin_data_on_uninstall'];
	}
}

if (!function_exists('pmw_delete_plugin_data')) {

	/**
	 * Remove all Pixel Manager data from the current site.
	 *
	 * Deletes every wp_options row the plugin creates, the plugin's transients,
	 * and drops the custom tracking-accuracy table.
	 *
	 * @return void
	 */
	function pmw_delete_plugin_data() {

		global $wpdb;

		// --- Broker connection (experimental) ---
		// Best-effort: tell the broker to revoke the Google connection and
		// purge its stored credentials before the site token is deleted below.
		$pmw_broker_state = get_option('pmw_broker');

		if (is_array($pmw_broker_state) && !empty($pmw_broker_state['site_token'])) {
			wp_remote_post(
				'https://pmw.sweetcode.com/v1/disconnect',
				[
					'timeout' => 5,
					'headers' => [
						'Authorization' => 'Bearer ' . $pmw_broker_state['site_token'],
						// JSON-typed so the broker's CSRF check accepts the POST.
						'Content-Type'  => 'application/json',
					],
					'body'    => '{}',
				]
			);
		}

		// --- Options ---
		$option_names = [
			// Core settings + backups
			'wgact_plugin_options',
			'wgact_options_backup',
			'wgact_notifications',
			'wgact_ratings',
			'pmw_opportunities',
			'pmw_gtg_handler_cache',
			'pmw_default_admin_theme',
			'pmw_broker',

			// Tracking accuracy
			'pmw_tracking_accuracy_db_version',
			'pmw_tracking_accuracy_backfill_complete',
			'pmw_tracking_accuracy_backfill_cursor',
			'pmw_tracking_accuracy_backfill_continuations',
			'pmw_tracking_accuracy_backfill_max_order_id',

			// Legacy options from earlier plugin versions
			'wgact_plugin_options_1',
			'wgact_plugin_options_2',
		];

		foreach ($option_names as $option_name) {
			delete_option($option_name);
		}

		// --- User meta ---
		// Per-user admin UI theme choice (Nova/Classic switcher).
		delete_metadata('user', 0, 'pmw_admin_theme', '', true);

		// Most recent Google Ads Data Manager upload outcome + backfill state (experimental feature)
		delete_option('pmw_google_dma_last_upload');
		delete_option('pmw_google_dma_backfill');

		// Universal click ID snapshot on orders (premium feature)
		delete_metadata('post', 0, '_pmw_click_ids', '', true);
		if (function_exists('wc_get_container')) {
			// HPOS order meta lives in its own table; the order-meta cleanup
			// below covers both storages through the WC data store.
			delete_metadata('order', 0, '_pmw_click_ids', '', true);
		}
		if (function_exists('as_unschedule_all_actions')) {
			as_unschedule_all_actions('pmw_google_dma_backfill_run');
		}

		// --- Transients (named) ---
		delete_transient('pmw_google_dma_delta_report');
		delete_transient('pmw_tracking_accuracy_backfill_running');
		delete_transient('pmw_tracking_accuracy_analysis');
		delete_transient('pmw_tracking_accuracy_analysis_running');
		delete_transient('pmw_tracking_accuracy_analysis_date');
		delete_transient('pmw_tracking_accuracy_analysis_time');
		delete_transient('pmw_tracking_accuracy_analysis_weighted');
		delete_transient('pmw_tracking_accuracy_analysis_max_orders');
		delete_transient('pmw_google_tag_id');
		delete_transient('pmw_google_tag_id_information');
		delete_transient('_pmw_pro_version_demo_active');
		delete_transient('pmw_test_transient');
		delete_transient('pmw_broker_challenge');

		// --- Transients (prefixed; cleaned up via direct query) ---
		// These are created with dynamic suffixes (per-key caches), so match by prefix.
		$transient_like_prefixes = [
			'pmw_ga4_data_api_access_token_',
			'pmw_google_sa_token_',
			'pmw_products_for_datalayer_',
			'pmw_geolocation_geoip_response_',
		];

		foreach ($transient_like_prefixes as $prefix) {
			$like = $wpdb->esc_like('_transient_' . $prefix) . '%';
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$wpdb->query($wpdb->prepare("DELETE FROM {$wpdb->options} WHERE option_name LIKE %s", $like));

			$timeout_like = $wpdb->esc_like('_transient_timeout_' . $prefix) . '%';
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$wpdb->query($wpdb->prepare("DELETE FROM {$wpdb->options} WHERE option_name LIKE %s", $timeout_like));
		}

		// --- Custom table ---
		$table = $wpdb->prefix . 'pmw_tracking_accuracy';
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange, WordPress.DB.PreparedSQL.NotPrepared
		$wpdb->query('DROP TABLE IF EXISTS ' . $table);
	}
}

if (!function_exists('pmw_run_uninstall')) {

	/**
	 * Run the uninstall routine, respecting the per-site opt-in.
	 *
	 * Multisite: run per-site so each blog's opt-in is respected.
	 *
	 * @return void
	 */
	function pmw_run_uninstall() {

		if (is_multisite()) {

			$site_ids = get_sites([
				'fields' => 'ids',
				'number' => 0,
			]);

			foreach ($site_ids as $site_id) {
				switch_to_blog($site_id);

				if (pmw_should_delete_plugin_data()) {
					pmw_delete_plugin_data();
				}

				restore_current_blog();
			}
		} elseif (pmw_should_delete_plugin_data()) {
			pmw_delete_plugin_data();
		}
	}
}
