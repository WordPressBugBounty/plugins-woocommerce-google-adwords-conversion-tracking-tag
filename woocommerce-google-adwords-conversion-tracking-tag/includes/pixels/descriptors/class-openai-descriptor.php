<?php
/**
 * OpenAI Pixel Descriptor
 *
 * Browser pixel descriptor for the OpenAI measurement pixel. The browser pixel
 * is part of the free plugin. The OpenAI Conversions API lives in the
 * premium-only adapter and API classes, which only exist on Pro builds.
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
 * Class OpenAI_Descriptor
 */
class OpenAI_Descriptor extends Abstract_Pixel_Descriptor {

	public function get_name() {
		return 'openai';
	}

	public function get_label() {
		return 'OpenAI';
	}

	public function get_category() {
		return 'marketing';
	}

	public function is_active() {
		return Options::is_openai_active();
	}

	/**
	 * Server-side tracking exists only while the Conversions API is
	 * configured, which needs the Pro-only token.
	 *
	 * @return bool
	 */
	public function has_server_tracking() {
		return Options::is_openai_capi_active();
	}
}

// Auto-instantiate to register with the registry
new OpenAI_Descriptor();
