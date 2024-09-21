<?php

defined('ABSPATH') || exit; // Exit if accessed directly

$package_name     = 'SweetCode\Pixel_Manager';
$target_directory = 'includes';

spl_autoload_register(function ( $fully_qualified_class_name ) use ( $package_name, $target_directory ) {

	// Abort if the $fully_qualified_class_name is not part of this package.
	if (strpos($fully_qualified_class_name, $package_name) === false) {
		return;
	}

	// Replace the package name with the target directory.
	$fully_qualified_class_name = str_ireplace($package_name, $target_directory, $fully_qualified_class_name);

	// First, separate the components of the incoming file into an array.
	$fully_qualified_class_name_array = explode('\\', $fully_qualified_class_name);

	// Abort if the $fully_qualified_class_name_array array is empty or has only one element.
	if (empty($fully_qualified_class_name_array) || count($fully_qualified_class_name_array) === 1) {
		return;
	}

	// The file name is the last element of the $fully_qualified_class_name_array.
	// Example file names: Admin, Doc_Link, Trait_Helper, Interface_Foo,
	$file_name = $fully_qualified_class_name_array[count($fully_qualified_class_name_array) - 1];

	// Make it lowercase.
	$file_name = strtolower($file_name);

	// Replace underscores with hyphens.
	$file_name = str_ireplace('_', '-', $file_name);

	// If the file name does not start with 'trait-' or 'interface-' then it's a class.
	// In that case add 'class-' to the beginning of the file name.
	// Otherwise, leave the file name as is.

	$file_name_prefixes = [
		'trait',
		'interface',
	];

	$prefix_found = false;
	foreach ($file_name_prefixes as $prefix) {
		if (strpos($file_name, $prefix . '-') === 0) {
			$prefix_found = true;
			break;
		}
	}

	if (!$prefix_found) {
		$file_name = 'class-' . $file_name;
	}

	// Remove the last index from the $fully_qualified_class_name_array array as it's always the file name.
	array_pop($fully_qualified_class_name_array);

	$fully_qualified_path = trailingslashit(__DIR__);

	// Now add the remaining directories to the path.
	foreach ($fully_qualified_class_name_array as $path) {
		$fully_qualified_path .= strtolower($path) . '/';
	}

	$fully_qualified_path .= $file_name . '.php';

	// Now include the file.
//	if (stream_resolve_include_path( $fully_qualified_path )) {
//		include_once $fully_qualified_path;
//	}

	/**
	 * Now include the file.
	 *
	 * Make sure to include the __premium_only.php file if the original file is not found.
	 */
	if (stream_resolve_include_path($fully_qualified_path)) {
		include_once $fully_qualified_path;
	} else {
		// If the original file is not found, try to load the file with the __premium_only suffix
		$fully_qualified_path_premium = str_replace('.php', '__premium_only.php', $fully_qualified_path);
		if (stream_resolve_include_path($fully_qualified_path_premium)) {
			include_once $fully_qualified_path_premium;
		}
	}
});
