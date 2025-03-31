<?php

/**
 * This is the main priority loader for the plugin. It will load the highest priority version of the plugin that is active.
 */

defined('ABSPATH') || exit; // Exit if accessed directly

/**
 * This is the main priority loader for the plugin. It will load the highest priority version of the plugin that is active.
 *
 * @var string $plugin_basename
 * @var string $pmw_version
 **/
add_action('plugins_loaded', function () use ( $plugin_basename, $pmw_version ) {

	$pmw_load_priorities_by_basename = [
		'pixel-manager-pro-for-woocommerce/wgact.php'                       => 1,
		'woocommerce-pixel-manager/woocommerce-pixel-manager.php'           => 2,
		'woocommerce-google-adwords-conversion-tracking-tag/wgact.php'      => 3,
		'woocommerce-pixel-manager-free/woocommerce-pixel-manager-free.php' => 4,
	];

	$active_plugins = get_option('active_plugins');

	// Intersect the arrays and check if the result is not empty
	$intersected_array = array_intersect_key($pmw_load_priorities_by_basename, array_flip($active_plugins));

	if (!empty($intersected_array)) {
		// Get the highest active plugin priority
		$highest_active_plugin_priority = min($intersected_array);

		// If the current plugin is not the highest priority, return
		if ($pmw_load_priorities_by_basename[$plugin_basename] > $highest_active_plugin_priority) {

			// Deactivate the current plugin
			if (function_exists('deactivate_plugins')) {
				deactivate_plugins($plugin_basename);
			}
			return;
		}
	}

	require_once 'class-wgact.php';
});
