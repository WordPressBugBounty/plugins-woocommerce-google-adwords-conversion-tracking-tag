<?php
/**
 * Reddit Pixel Descriptor
 *
 * Browser pixel descriptor for the Reddit Pixel. The browser pixel is part of
 * the free plugin. The Reddit Conversions API lives in the premium-only adapter
 * and API classes, which only exist on Pro builds.
 *
 * @package SweetCode\Pixel_Manager
 * @since 1.68.0
 */

namespace SweetCode\Pixel_Manager\Pixels\Descriptors;

use SweetCode\Pixel_Manager\Options;
use SweetCode\Pixel_Manager\Pixels\Core\Abstract_Pixel_Descriptor;

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly
}

/**
 * Class Reddit_Descriptor
 */
class Reddit_Descriptor extends Abstract_Pixel_Descriptor {

	public function get_name() {
		return 'reddit';
	}

	public function get_label() {
		return 'Reddit';
	}

	public function get_category() {
		return 'marketing';
	}

	public function is_active() {
		return Options::is_reddit_active();
	}

	/**
	 * Server-side tracking exists only while the Conversions API is
	 * configured, which needs the Pro-only token.
	 *
	 * @return bool
	 */
	public function has_server_tracking() {
		return Options::is_reddit_capi_active();
	}
}

// Auto-instantiate to register with the registry
new Reddit_Descriptor();
