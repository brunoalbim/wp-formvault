<?php
/**
 * Plugin Name: WP Attribution Tracker
 * Plugin URI:  https://github.com/brunoalbim/wp-formvault
 * Description: Captura atribuição de tráfego e a adiciona automaticamente aos formulários do Elementor Pro.
 * Version:     1.0.0
 * Author:      Bruno Albim
 * License:     GPL-2.0-or-later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: wp-attribution-tracker
 * Requires at least: 6.5
 * Requires PHP: 7.4
 * Elementor tested up to: 3.32
 * Elementor Pro tested up to: 3.32
 *
 * @package WPAttributionTracker
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'WPAT_VERSION', '1.0.0' );
define( 'WPAT_FILE', __FILE__ );
define( 'WPAT_DIR', plugin_dir_path( __FILE__ ) );
define( 'WPAT_URL', plugin_dir_url( __FILE__ ) );

require_once WPAT_DIR . 'includes/class-assets.php';
require_once WPAT_DIR . 'includes/class-elementor-adapter.php';
require_once WPAT_DIR . 'includes/class-plugin.php';

add_action( 'plugins_loaded', array( 'WPAT_Plugin', 'instance' ) );
