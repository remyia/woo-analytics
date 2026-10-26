<?php
/**
 * Transient cache for computed reports, with a global version that invalidates everything at once.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Woo_Pulse_Cache {

	const VERSION_OPTION = 'woo_pulse_cache_version';
	const BUMPED_OPTION  = 'woo_pulse_cache_bumped_at';
	const TTL            = 600; // seconds

	public static function version() {
		return (int) get_option( self::VERSION_OPTION, 1 );
	}

	public static function key( $name, $args ) {
		return 'woo_pulse_' . md5( $name . '|' . wp_json_encode( $args ) . '|' . self::version() );
	}

	public static function get( $name, $args ) {
		return get_transient( self::key( $name, $args ) );
	}

	public static function set( $name, $args, $value, $ttl = self::TTL ) {
		set_transient( self::key( $name, $args ), $value, $ttl );
	}

	/** Invalidate every cached report immediately. */
	public static function bump() {
		update_option( self::VERSION_OPTION, self::version() + 1, false );
		update_option( self::BUMPED_OPTION, time(), false );
	}

	/** Invalidate at most once a minute so busy stores do not thrash the cache on every order. */
	public static function maybe_bump() {
		$last = (int) get_option( self::BUMPED_OPTION, 0 );
		if ( time() - $last >= 60 ) {
			self::bump();
		}
	}
}
