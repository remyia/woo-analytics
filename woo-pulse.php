<?php
/**
 * Plugin Name:       Woo Pulse
 * Description:       Snapshot analytics for WooCommerce inside wp-admin: sales by state on a map, blended ROAS, MER, channels, customers, products, and the numbers the stock reports leave out.
 * Version:           1.1.0
 * Author:            Metro Studios
 * Requires at least: 6.2
 * Requires PHP:      7.4
 * Requires Plugins:  woocommerce
 * WC requires at least: 7.1
 * WC tested up to:   9.9
 * Text Domain:       woo-pulse
 * License:           GPL-2.0-or-later
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'WOO_PULSE_VERSION', '1.1.0' );
define( 'WOO_PULSE_FILE', __FILE__ );
define( 'WOO_PULSE_DIR', plugin_dir_path( __FILE__ ) );
define( 'WOO_PULSE_URL', plugin_dir_url( __FILE__ ) );

require_once WOO_PULSE_DIR . 'includes/class-woo-pulse-settings.php';
require_once WOO_PULSE_DIR . 'includes/class-woo-pulse-cache.php';
require_once WOO_PULSE_DIR . 'includes/class-woo-pulse-dates.php';
require_once WOO_PULSE_DIR . 'includes/class-woo-pulse-orders.php';
require_once WOO_PULSE_DIR . 'includes/class-woo-pulse-metrics.php';
require_once WOO_PULSE_DIR . 'includes/class-woo-pulse-spend.php';
require_once WOO_PULSE_DIR . 'includes/class-woo-pulse-connector.php';
require_once WOO_PULSE_DIR . 'includes/class-woo-pulse-report.php';
require_once WOO_PULSE_DIR . 'includes/class-woo-pulse-rest.php';
require_once WOO_PULSE_DIR . 'includes/class-woo-pulse-digest.php';
require_once WOO_PULSE_DIR . 'includes/class-woo-pulse-admin.php';
require_once WOO_PULSE_DIR . 'includes/class-woo-pulse-plugin.php';

// Declare compatibility with High-Performance Order Storage (custom order tables).
add_action(
	'before_woocommerce_init',
	function () {
		if ( class_exists( '\Automattic\WooCommerce\Utilities\FeaturesUtil' ) ) {
			\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'custom_order_tables', __FILE__, true );
		}
	}
);

register_activation_hook( __FILE__, array( 'Woo_Pulse_Plugin', 'activate' ) );
register_deactivation_hook( __FILE__, array( 'Woo_Pulse_Plugin', 'deactivate' ) );

add_action(
	'plugins_loaded',
	function () {
		if ( ! class_exists( 'WooCommerce' ) ) {
			add_action(
				'admin_notices',
				function () {
					echo '<div class="notice notice-error"><p>' . esc_html__( 'Woo Pulse needs WooCommerce to be installed and active.', 'woo-pulse' ) . '</p></div>';
				}
			);
			return;
		}
		Woo_Pulse_Plugin::instance();
	}
);
