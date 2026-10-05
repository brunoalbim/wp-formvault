<?php
/**
 * Plugin bootstrap.
 *
 * @package WPAttributionTracker
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class WPAT_Plugin {
	/** @var self|null */
	private static $instance = null;

	/**
	 * Return the plugin singleton.
	 *
	 * @return self
	 */
	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {
		new WPAT_Assets();
		new WPAT_Elementor_Adapter();
	}
}
