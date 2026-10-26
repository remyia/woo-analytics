<?php
/**
 * Client for the Woo Pulse Connect service (hosted ad-platform connectors) and verifier for its signed pushes.
 *
 * Signature contract (must match the service's src/lib/crypto.ts):
 *   message   = "<unix seconds>.<raw body>"
 *   signature = "v1=" . hex( HMAC-SHA256( store_key, message ) )
 *   headers   X-WooPulse-Timestamp, X-WooPulse-Signature; timestamps older than 5 minutes are rejected.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Woo_Pulse_Connector {

	const MAX_SKEW = 300;

	/** Service base URL without a trailing slash, or '' when not configured. */
	public static function url() {
		$s = Woo_Pulse_Settings::get();
		return rtrim( (string) $s['connector_url'], '/' );
	}

	public static function configured() {
		return '' !== self::url();
	}

	/** The store key, generated on first use. 64 hex characters. */
	public static function store_key() {
		$s = Woo_Pulse_Settings::get();
		if ( ! empty( $s['store_key'] ) && preg_match( '/^[0-9a-f]{64}$/', $s['store_key'] ) ) {
			return $s['store_key'];
		}
		return self::regenerate_key();
	}

	public static function regenerate_key() {
		$key = bin2hex( random_bytes( 32 ) );
		Woo_Pulse_Settings::update(
			array(
				'store_key' => $key,
				'store_id'  => '',
			)
		);
		return $key;
	}

	public static function masked_key() {
		$s = Woo_Pulse_Settings::get();
		$k = (string) $s['store_key'];
		return $k ? substr( $k, 0, 6 ) . str_repeat( '•', 20 ) . substr( $k, -4 ) : '';
	}

	/**
	 * Authenticated request to the service. Returns decoded JSON array or WP_Error.
	 */
	public static function request( $method, $path, $body = null ) {
		$url = self::url();
		if ( '' === $url ) {
			return new WP_Error( 'woo_pulse_connector_unset', __( 'Connector service URL is not set. Add it under Pulse > Settings > Connectors.', 'woo-pulse' ) );
		}
		$args = array(
			'method'  => $method,
			'timeout' => 30,
			'headers' => array(
				'Authorization' => 'Bearer ' . self::store_key(),
				'Accept'        => 'application/json',
			),
		);
		if ( null !== $body ) {
			$args['headers']['Content-Type'] = 'application/json';
			$args['body']                    = wp_json_encode( $body );
		}
		$res = wp_remote_request( $url . '/' . ltrim( $path, '/' ), $args );
		if ( is_wp_error( $res ) ) {
			return $res;
		}
		$code = (int) wp_remote_retrieve_response_code( $res );
		$json = json_decode( wp_remote_retrieve_body( $res ), true );
		if ( $code >= 400 ) {
			$msg = is_array( $json ) && ! empty( $json['message'] ) ? $json['message'] : sprintf( 'Connector service returned HTTP %d', $code );
			return new WP_Error( 'woo_pulse_connector_http', $msg, array( 'status' => $code ) );
		}
		return is_array( $json ) ? $json : array();
	}

	/** Register (or refresh) this store with the service and remember the store id. */
	public static function register() {
		$tz  = wp_timezone_string();
		$res = self::request(
			'POST',
			'stores/register',
			array(
				'site_url'       => home_url(),
				'currency'       => get_woocommerce_currency(),
				'timezone'       => $tz,
				'plugin_version' => WOO_PULSE_VERSION,
			)
		);
		if ( is_wp_error( $res ) ) {
			return $res;
		}
		if ( ! empty( $res['store_id'] ) ) {
			Woo_Pulse_Settings::update( array( 'store_id' => sanitize_text_field( $res['store_id'] ) ) );
		}
		return $res;
	}

	/** Combined status for the Connections panel. Never throws; errors are returned in the payload. */
	public static function status() {
		$s   = Woo_Pulse_Settings::get();
		$out = array(
			'configured'      => self::configured(),
			'url'             => self::url(),
			'store_id'        => (string) $s['store_id'],
			'store_key_masked' => self::masked_key(),
			'registered'      => false,
			'service'         => null,
			'error'           => '',
		);
		if ( ! $out['configured'] ) {
			return $out;
		}
		$res = self::request( 'GET', 'stores/me' );
		if ( is_wp_error( $res ) ) {
			$data = $res->get_error_data();
			if ( is_array( $data ) && isset( $data['status'] ) && 401 === (int) $data['status'] ) {
				$out['registered'] = false; // unknown store: needs register
			} else {
				$out['error'] = $res->get_error_message();
			}
			return $out;
		}
		$out['registered'] = true;
		$out['service']    = $res;
		if ( ! empty( $res['store_id'] ) && $res['store_id'] !== $s['store_id'] ) {
			Woo_Pulse_Settings::update( array( 'store_id' => sanitize_text_field( $res['store_id'] ) ) );
			$out['store_id'] = $res['store_id'];
		}
		return $out;
	}

	public static function connect_url( $platform ) {
		return self::request(
			'POST',
			'stores/me/connect-token',
			array(
				'platform'   => sanitize_key( $platform ),
				'return_url' => admin_url( 'admin.php?page=woo-pulse#marketing' ),
			)
		);
	}

	public static function ping() {
		return self::request( 'POST', 'stores/me/ping', new stdClass() ); // encodes as {} rather than []
	}

	public static function resync( $connection_id, $days ) {
		return self::request( 'POST', 'connections/' . rawurlencode( $connection_id ) . '/resync', array( 'days' => (int) $days ) );
	}

	public static function disconnect( $connection_id ) {
		return self::request( 'DELETE', 'connections/' . rawurlencode( $connection_id ) );
	}

	/**
	 * Verify a signed push from the service. Returns true or a WP_Error with a 403 status.
	 */
	public static function verify_request( WP_REST_Request $req ) {
		$s   = Woo_Pulse_Settings::get();
		$key = (string) $s['store_key'];
		if ( '' === $key ) {
			return new WP_Error( 'woo_pulse_no_key', __( 'This store has no connector key.', 'woo-pulse' ), array( 'status' => 403 ) );
		}
		$ts  = (string) $req->get_header( 'x_woopulse_timestamp' );
		$sig = (string) $req->get_header( 'x_woopulse_signature' );
		if ( ! preg_match( '/^\d{9,11}$/', $ts ) || 0 !== strpos( $sig, 'v1=' ) ) {
			return new WP_Error( 'woo_pulse_bad_signature', __( 'Missing signature headers.', 'woo-pulse' ), array( 'status' => 403 ) );
		}
		if ( abs( time() - (int) $ts ) > self::MAX_SKEW ) {
			return new WP_Error( 'woo_pulse_stale', __( 'Signature timestamp is outside the allowed window.', 'woo-pulse' ), array( 'status' => 403 ) );
		}
		$expected = 'v1=' . hash_hmac( 'sha256', $ts . '.' . $req->get_body(), $key );
		if ( ! hash_equals( $expected, $sig ) ) {
			return new WP_Error( 'woo_pulse_bad_signature', __( 'Signature does not match.', 'woo-pulse' ), array( 'status' => 403 ) );
		}
		return true;
	}

	/** Ledger channel name for a connector source. */
	public static function channel_for_source( $source ) {
		$map = array(
			'meta'   => 'Meta',
			'google' => 'Google Ads',
		);
		return isset( $map[ $source ] ) ? $map[ $source ] : ucfirst( sanitize_text_field( $source ) );
	}

	public static function is_synced_source( $source ) {
		return ! in_array( (string) $source, array( '', 'manual', 'csv' ), true );
	}
}
