<?php
/**
 * Bootstrap: wires admin, REST, digest cron, capabilities and cache invalidation together.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Woo_Pulse_Plugin {

	/** Custom capability granted to administrators, shop managers and the optional extra role. */
	const CAP = 'view_woo_pulse';

	/** @var Woo_Pulse_Plugin|null */
	private static $instance = null;

	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {
		// Keep the schema, capabilities and cron current if the plugin was updated without re-activation.
		if ( get_option( 'woo_pulse_db_version' ) !== WOO_PULSE_VERSION ) {
			self::activate();
		}

		new Woo_Pulse_Admin();
		new Woo_Pulse_Rest();
		new Woo_Pulse_Digest();

		// Any order change makes cached reports stale. Bumps are debounced inside the cache class.
		add_action( 'woocommerce_new_order', array( 'Woo_Pulse_Cache', 'maybe_bump' ) );
		add_action( 'woocommerce_order_status_changed', array( 'Woo_Pulse_Cache', 'maybe_bump' ) );
		add_action( 'woocommerce_order_refunded', array( 'Woo_Pulse_Cache', 'maybe_bump' ) );
		add_action( 'woocommerce_refund_created', array( 'Woo_Pulse_Cache', 'maybe_bump' ) );
	}

	/**
	 * Capability required to see the dashboard and use the REST routes.
	 */
	public static function capability() {
		return apply_filters( 'woo_pulse_capability', self::CAP );
	}

	public static function activate() {
		Woo_Pulse_Spend::install();
		self::grant_caps();
		Woo_Pulse_Digest::reschedule();
		Woo_Pulse_Cache::bump(); // A new version may change what reports contain; never serve stale ones.
		update_option( 'woo_pulse_db_version', WOO_PULSE_VERSION, false );
	}

	public static function deactivate() {
		Woo_Pulse_Digest::unschedule();
	}

	/**
	 * Give the viewing capability to the built-in roles plus the optional extra role from settings.
	 */
	public static function grant_caps() {
		$settings = Woo_Pulse_Settings::get();
		$roles    = array( 'administrator', 'shop_manager' );
		if ( ! empty( $settings['extra_role'] ) ) {
			$roles[] = $settings['extra_role'];
		}
		foreach ( array_unique( $roles ) as $role_name ) {
			$role = get_role( $role_name );
			if ( $role && ! $role->has_cap( self::CAP ) ) {
				$role->add_cap( self::CAP );
			}
		}
	}

	/**
	 * Called when the extra role changes so the old role loses access.
	 */
	public static function sync_extra_role( $old_role, $new_role ) {
		if ( $old_role && $old_role !== $new_role && ! in_array( $old_role, array( 'administrator', 'shop_manager' ), true ) ) {
			$role = get_role( $old_role );
			if ( $role ) {
				$role->remove_cap( self::CAP );
			}
		}
		self::grant_caps();
	}
}
