<?php

defined('ABSPATH') || exit; // Exit if accessed directly

if (!function_exists('pmw_autoload_candidate_paths')) {

	/**
	 * Map a fully qualified symbol name onto the files that could define it.
	 *
	 * Kept as a named function rather than inlined in the autoloader below so
	 * that the test suite can assert, without loading a single class, that every
	 * symbol the source imports has a file behind it. A call site left pointing
	 * at a class whose file is gone is a fatal error on a live shop, and the
	 * autoloader has no way to report it: it looks the file up, finds nothing and
	 * returns, and PHP raises "Class not found" at the call site.
	 *
	 * @param string $fully_qualified_class_name The symbol PHP is looking for.
	 * @param string $package_name               The namespace prefix this package owns.
	 * @param string $base_directory             Absolute path the package root maps to.
	 * @param string $target_directory           Directory the package prefix maps to.
	 *
	 * @return string[] Candidate absolute paths, most likely first. Empty when the
	 *                  symbol does not belong to this package.
	 */
	function pmw_autoload_candidate_paths( $fully_qualified_class_name, $package_name, $base_directory, $target_directory ) {

		// Abort if the $fully_qualified_class_name is not part of this package.
		if (strpos($fully_qualified_class_name, $package_name) === false) {
			return [];
		}

		// Replace the package name with the target directory.
		$fully_qualified_class_name = str_ireplace($package_name, $target_directory, $fully_qualified_class_name);

		// First, separate the components of the incoming file into an array.
		$fully_qualified_class_name_array = explode('\\', $fully_qualified_class_name);

		// Abort if the $fully_qualified_class_name_array array is empty or has only one element.
		if (empty($fully_qualified_class_name_array) || count($fully_qualified_class_name_array) === 1) {
			return [];
		}

		// The file name is the last element of the $fully_qualified_class_name_array.
		// Example file names: Admin, Doc_Link, Trait_Helper, Interface_Foo,
		$file_name = $fully_qualified_class_name_array[count($fully_qualified_class_name_array) - 1];

		// Make it lowercase.
		$file_name = strtolower($file_name);

		// Replace underscores with hyphens.
		$file_name = str_ireplace('_', '-', $file_name);

		// If the file name does not start with 'trait-', 'interface-', or 'abstract-' then it's a class.
		// In that case add 'class-' to the beginning of the file name.
		// Otherwise, leave the file name as is.

		$file_name_prefixes = [
			'trait',
			'interface',
			'abstract',
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

		/**
		 * The prefix above is guessed from the symbol name, and the guess is only
		 * right when the symbol carries the prefix itself, the way
		 * Abstract_Pixel_Adapter does. An interface named Pixel_Adapter lives in
		 * interface-pixel-adapter.php but resolves to class-pixel-adapter.php here,
		 * so the file is never found and the symbol stays undefined. Collect the
		 * other spellings as fall-backs, the guessed one first, so that nothing
		 * which resolves today resolves to a different file.
		 */
		$file_names = [ $file_name ];

		if (!$prefix_found) {
			$bare_file_name = substr($file_name, strlen('class-'));
			foreach ($file_name_prefixes as $prefix) {
				$file_names[] = $prefix . '-' . $bare_file_name;
			}
		}

		// Remove the last index from the $fully_qualified_class_name_array array as it's always the file name.
		array_pop($fully_qualified_class_name_array);

		$fully_qualified_path = rtrim($base_directory, '/\\') . '/';

		// Now add the remaining directories to the path.
		foreach ($fully_qualified_class_name_array as $path) {
			$fully_qualified_path .= strtolower($path) . '/';
		}

		/**
		 * Every candidate is listed twice: once as it is, and once with the
		 * __premium_only suffix Freemius appends to the files it strips out of the
		 * free build.
		 */
		$paths = [];
		foreach ($file_names as $candidate) {
			$paths[] = $fully_qualified_path . $candidate . '.php';
			$paths[] = $fully_qualified_path . $candidate . '__premium_only.php';
		}

		return $paths;
	}
}

$package_name     = 'SweetCode\Pixel_Manager';
$target_directory = 'includes';
$base_directory   = __DIR__;

spl_autoload_register(function ( $fully_qualified_class_name ) use ( $package_name, $base_directory, $target_directory ) {

	$candidate_paths = pmw_autoload_candidate_paths(
		$fully_qualified_class_name,
		$package_name,
		$base_directory,
		$target_directory
	);

	// Now include the first file that exists.
	foreach ($candidate_paths as $candidate_path) {
		if (stream_resolve_include_path($candidate_path)) {
			include_once $candidate_path;
			return;
		}
	}
});
