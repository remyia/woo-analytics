<?php
/**
 * Admin menu page, asset loading and the configuration handed to the JavaScript app.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Woo_Pulse_Admin {

	const PAGE = 'woo-pulse';

	public function __construct() {
		add_action( 'admin_menu', array( $this, 'menu' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'assets' ) );
	}

	public function menu() {
		add_menu_page(
			__( 'Pulse', 'woo-pulse' ),
			__( 'Pulse', 'woo-pulse' ),
			Woo_Pulse_Plugin::capability(),
			self::PAGE,
			array( $this, 'render' ),
			'dashicons-chart-area',
			56
		);
	}

	public function assets( $hook ) {
		if ( 'toplevel_page_' . self::PAGE !== $hook ) {
			return;
		}

		wp_enqueue_style( 'woo-pulse-admin', WOO_PULSE_URL . 'assets/css/admin.css', array(), WOO_PULSE_VERSION );

		// Map libraries load in the head from a CDN. If they fail, usmap.js falls back to the tile grid.
		wp_enqueue_script( 'woo-pulse-d3', 'https://cdnjs.cloudflare.com/ajax/libs/d3/7.9.0/d3.min.js', array(), '7.9.0', false );
		wp_enqueue_script( 'woo-pulse-topojson', 'https://cdn.jsdelivr.net/npm/topojson-client@3.1.0/dist/topojson-client.min.js', array(), '3.1.0', false );

		wp_enqueue_script( 'woo-pulse-charts', WOO_PULSE_URL . 'assets/js/charts.js', array(), WOO_PULSE_VERSION, true );
		wp_enqueue_script( 'woo-pulse-usmap', WOO_PULSE_URL . 'assets/js/usmap.js', array( 'woo-pulse-charts' ), WOO_PULSE_VERSION, true );
		wp_enqueue_script( 'woo-pulse-admin', WOO_PULSE_URL . 'assets/js/admin.js', array( 'woo-pulse-charts', 'woo-pulse-usmap' ), WOO_PULSE_VERSION, true );

		// Inline JSON keeps booleans and numbers typed; wp_localize_script would cast them to strings.
		wp_add_inline_script( 'woo-pulse-admin', 'var WooPulseConfig = ' . wp_json_encode( $this->config() ) . ';', 'before' );
	}

	private function config() {
		$settings  = Woo_Pulse_Settings::get();
		$orders    = new Woo_Pulse_Orders();
		$countries = WC()->countries;

		return array(
			'restUrl'        => esc_url_raw( rest_url( Woo_Pulse_Rest::NS . '/' ) ),
			'nonce'          => wp_create_nonce( 'wp_rest' ),
			'currency'       => get_woocommerce_currency(),
			'currencySymbol' => html_entity_decode( get_woocommerce_currency_symbol(), ENT_QUOTES, 'UTF-8' ),
			'decimals'       => (int) wc_get_price_decimals(),
			// WooCommerce's own formatting rules so amounts look exactly like they do in the order screens.
			'priceFormat'    => html_entity_decode( get_woocommerce_price_format(), ENT_QUOTES, 'UTF-8' ),
			'thousandSep'    => html_entity_decode( wc_get_price_thousand_separator(), ENT_QUOTES, 'UTF-8' ),
			'decimalSep'     => html_entity_decode( wc_get_price_decimal_separator(), ENT_QUOTES, 'UTF-8' ),
			'locale'         => str_replace( '_', '-', get_locale() ),
			'baseCountry'    => $countries->get_base_country(),
			'timezone'       => wp_timezone_string(),
			'today'          => Woo_Pulse_Dates::today(),
			'settings'       => Woo_Pulse_Settings::public_view( $settings ),
			'storage'        => $orders->storage_label(),
			'hasAttribution' => $orders->has_meta( Woo_Pulse_Orders::META_SOURCE_TYPE ),
			'hasCogs'        => $orders->has_meta( Woo_Pulse_Orders::META_COGS ),
			'statuses'       => wc_get_order_statuses(),
			'countries'      => $countries->get_countries(),
			'states'         => $countries->get_states(),
			'ordersUrl'      => $orders->is_hpos() ? admin_url( 'admin.php?page=wc-orders' ) : admin_url( 'edit.php?post_type=shop_order' ),
			'channelPresets' => array( 'Meta', 'Google', 'TikTok', 'Pinterest', 'Microsoft', 'Snapchat', 'Amazon', 'Affiliates', 'Influencers' ),
			'atlasUrl'       => 'https://cdn.jsdelivr.net/npm/us-atlas@3.0.1/states-10m.json',
			'storeName'      => wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES ),
		);
	}

	public function render() {
		if ( ! current_user_can( Woo_Pulse_Plugin::capability() ) ) {
			wp_die( esc_html__( 'You do not have permission to view this page.', 'woo-pulse' ) );
		}
		// The real <h1> must be the first heading in .wrap: WordPress moves every admin notice to sit right after it,
		// which keeps other plugins' notices above the dashboard instead of inside it.
		echo '<div class="wrap woo-pulse-wrap"><h1 class="wp-heading-inline woo-pulse-page-title">' . esc_html__( 'Pulse', 'woo-pulse' ) . '</h1><hr class="wp-header-end">';
		echo '<div id="woo-pulse-app" class="woo-pulse-app"><div class="woo-pulse-boot">' . esc_html__( 'Loading Pulse…', 'woo-pulse' ) . '</div></div>';
		echo '<noscript><div class="notice notice-error"><p>' . esc_html__( 'Woo Pulse needs JavaScript enabled.', 'woo-pulse' ) . '</p></div></noscript></div>';
	}
}
