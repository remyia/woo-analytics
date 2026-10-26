<?php
/**
 * REST API: woo-pulse/v1
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Woo_Pulse_Rest {

	const NS = 'woo-pulse/v1';

	public function __construct() {
		add_action( 'rest_api_init', array( $this, 'register' ) );
	}

	public function permission() {
		return current_user_can( Woo_Pulse_Plugin::capability() );
	}

	public function register() {
		$perm = array( $this, 'permission' );

		register_rest_route(
			self::NS,
			'/report',
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'report' ),
				'permission_callback' => $perm,
				'args'                => array(
					'start'   => array( 'type' => 'string' ),
					'end'     => array( 'type' => 'string' ),
					'compare' => array( 'type' => 'string' ),
					'nocache' => array( 'type' => 'string' ),
				),
			)
		);

		register_rest_route(
			self::NS,
			'/spend',
			array(
				array(
					'methods'             => 'GET',
					'callback'            => array( $this, 'spend_list' ),
					'permission_callback' => $perm,
				),
				array(
					'methods'             => 'POST',
					'callback'            => array( $this, 'spend_create' ),
					'permission_callback' => $perm,
				),
			)
		);

		register_rest_route(
			self::NS,
			'/spend/bulk',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'spend_bulk' ),
				'permission_callback' => $perm,
			)
		);

		register_rest_route(
			self::NS,
			'/spend/(?P<id>\d+)',
			array(
				array(
					'methods'             => 'PUT',
					'callback'            => array( $this, 'spend_update' ),
					'permission_callback' => $perm,
				),
				array(
					'methods'             => 'DELETE',
					'callback'            => array( $this, 'spend_delete' ),
					'permission_callback' => $perm,
				),
			)
		);

		register_rest_route(
			self::NS,
			'/settings',
			array(
				array(
					'methods'             => 'GET',
					'callback'            => array( $this, 'settings_get' ),
					'permission_callback' => $perm,
				),
				array(
					'methods'             => 'POST',
					'callback'            => array( $this, 'settings_save' ),
					'permission_callback' => $perm,
				),
			)
		);

		register_rest_route(
			self::NS,
			'/cache/clear',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'cache_clear' ),
				'permission_callback' => $perm,
			)
		);

		register_rest_route(
			self::NS,
			'/digest/test',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'digest_test' ),
				'permission_callback' => $perm,
			)
		);

		// Signed pushes from the Woo Pulse Connect service. No cookie or capability: the HMAC is the credential.
		register_rest_route(
			self::NS,
			'/spend/sync',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'spend_sync' ),
				'permission_callback' => array( 'Woo_Pulse_Connector', 'verify_request' ),
			)
		);

		$connector = array(
			'/connector/status'                             => array( 'GET', 'connector_status' ),
			'/connector/register'                           => array( 'POST', 'connector_register' ),
			'/connector/connect'                            => array( 'POST', 'connector_connect' ),
			'/connector/ping'                               => array( 'POST', 'connector_ping' ),
			'/connector/regenerate-key'                     => array( 'POST', 'connector_regenerate' ),
			'/connector/connections/(?P<id>[A-Za-z0-9_\-]+)/resync' => array( 'POST', 'connector_resync' ),
			'/connector/connections/(?P<id>[A-Za-z0-9_\-]+)'        => array( 'DELETE', 'connector_disconnect' ),
		);
		foreach ( $connector as $route => $spec ) {
			register_rest_route(
				self::NS,
				$route,
				array(
					'methods'             => $spec[0],
					'callback'            => array( $this, $spec[1] ),
					'permission_callback' => $perm,
				)
			);
		}
	}

	/* ------------------------------------------------------------------ */
	/* Connector                                                            */
	/* ------------------------------------------------------------------ */

	public function spend_sync( WP_REST_Request $req ) {
		$body   = (array) $req->get_json_params();
		$source = isset( $body['source'] ) ? sanitize_key( $body['source'] ) : '';
		if ( 'ping' === $source ) {
			return rest_ensure_response(
				array(
					'ok'       => true,
					'inserted' => 0,
					'updated'  => 0,
					'message'  => 'ping accepted',
				)
			);
		}
		if ( ! in_array( $source, array( 'meta', 'google' ), true ) ) {
			return new WP_Error( 'woo_pulse_bad_source', __( 'Unknown spend source.', 'woo-pulse' ), array( 'status' => 400 ) );
		}
		if ( empty( $body['external_id'] ) || ! is_string( $body['external_id'] ) ) {
			return new WP_Error( 'woo_pulse_bad_account', __( 'external_id (the ad account) is required.', 'woo-pulse' ), array( 'status' => 400 ) );
		}
		$account_name = ( isset( $body['account_name'] ) && is_string( $body['account_name'] ) ) ? $body['account_name'] : $body['external_id'];
		$rows         = isset( $body['rows'] ) && is_array( $body['rows'] ) ? array_values( $body['rows'] ) : array();
		if ( count( $rows ) > 2000 ) {
			return new WP_Error( 'woo_pulse_too_many', __( 'At most 2,000 rows per push.', 'woo-pulse' ), array( 'status' => 400 ) );
		}
		$result       = Woo_Pulse_Spend::upsert_synced(
			$source,
			$body['external_id'],
			Woo_Pulse_Connector::channel_for_source( $source ),
			$account_name,
			$rows
		);
		$result['ok'] = true;
		return rest_ensure_response( $result );
	}

	private function connector_result( $res ) {
		if ( is_wp_error( $res ) ) {
			$data   = $res->get_error_data();
			$status = ( is_array( $data ) && isset( $data['status'] ) ) ? (int) $data['status'] : 502;
			$res->add_data( array( 'status' => $status >= 400 ? $status : 502 ) );
			return $res;
		}
		return rest_ensure_response( $res );
	}

	public function connector_status() {
		return rest_ensure_response( Woo_Pulse_Connector::status() );
	}

	public function connector_register() {
		return $this->connector_result( Woo_Pulse_Connector::register() );
	}

	public function connector_connect( WP_REST_Request $req ) {
		$body = (array) $req->get_json_params();
		return $this->connector_result( Woo_Pulse_Connector::connect_url( isset( $body['platform'] ) ? $body['platform'] : '' ) );
	}

	public function connector_ping() {
		return $this->connector_result( Woo_Pulse_Connector::ping() );
	}

	public function connector_regenerate() {
		Woo_Pulse_Connector::regenerate_key();
		return rest_ensure_response(
			array(
				'ok'               => true,
				'store_key_masked' => Woo_Pulse_Connector::masked_key(),
			)
		);
	}

	public function connector_resync( WP_REST_Request $req ) {
		$body = (array) $req->get_json_params();
		return $this->connector_result( Woo_Pulse_Connector::resync( $req['id'], isset( $body['days'] ) ? (int) $body['days'] : 30 ) );
	}

	public function connector_disconnect( WP_REST_Request $req ) {
		$res = Woo_Pulse_Connector::disconnect( $req['id'] );
		if ( ! is_wp_error( $res ) ) {
			$s = Woo_Pulse_Settings::get();
			if ( empty( $s['keep_synced_on_disconnect'] ) && ! empty( $req['source'] ) && ! empty( $req['external_id'] ) ) {
				global $wpdb;
				$wpdb->delete(
					Woo_Pulse_Spend::table(),
					array(
						'source'      => sanitize_key( $req['source'] ),
						'external_id' => sanitize_text_field( $req['external_id'] ),
					),
					array( '%s', '%s' )
				);
				Woo_Pulse_Cache::bump();
			}
		}
		return $this->connector_result( $res );
	}

	/* ------------------------------------------------------------------ */

	public function report( WP_REST_Request $req ) {
		$start   = sanitize_text_field( (string) $req->get_param( 'start' ) );
		$end     = sanitize_text_field( (string) $req->get_param( 'end' ) );
		$compare = 'year' === $req->get_param( 'compare' ) ? 'year' : 'previous';
		$nocache = in_array( (string) $req->get_param( 'nocache' ), array( '1', 'true' ), true );

		try {
			$data = Woo_Pulse_Report::build( $start, $end, $compare, $nocache );
		} catch ( Throwable $e ) {
			return new WP_Error( 'woo_pulse_report_failed', $e->getMessage(), array( 'status' => 500 ) );
		}
		return rest_ensure_response( $data );
	}

	public function spend_list( WP_REST_Request $req ) {
		$start = Woo_Pulse_Spend::normalize_date( $req->get_param( 'start' ) );
		$end   = Woo_Pulse_Spend::normalize_date( $req->get_param( 'end' ) );
		if ( ! $start || ! $end ) {
			$r     = Woo_Pulse_Dates::resolve( null, null );
			$start = $r['start'];
			$end   = $r['end'];
		}
		return rest_ensure_response( Woo_Pulse_Spend::entries( $start, $end ) );
	}

	public function spend_create( WP_REST_Request $req ) {
		$id = Woo_Pulse_Spend::insert( (array) $req->get_json_params() );
		if ( is_wp_error( $id ) ) {
			$id->add_data( array( 'status' => 400 ) );
			return $id;
		}
		return rest_ensure_response( array( 'id' => $id ) );
	}

	public function spend_update( WP_REST_Request $req ) {
		$ok = Woo_Pulse_Spend::update( (int) $req['id'], (array) $req->get_json_params() );
		if ( is_wp_error( $ok ) ) {
			$ok->add_data( array( 'status' => 400 ) );
			return $ok;
		}
		return rest_ensure_response( array( 'ok' => true ) );
	}

	public function spend_delete( WP_REST_Request $req ) {
		$ok = Woo_Pulse_Spend::delete( (int) $req['id'] );
		if ( is_wp_error( $ok ) ) {
			return $ok;
		}
		return rest_ensure_response( array( 'ok' => true ) );
	}

	/**
	 * Body: { csv: "text" } or { rows: [...] }, plus replace (bool) and preview (bool).
	 * Preview validates without saving and returns each row with its status.
	 */
	public function spend_bulk( WP_REST_Request $req ) {
		$body    = (array) $req->get_json_params();
		$replace = ! empty( $body['replace'] );
		$preview = ! empty( $body['preview'] );
		$errors  = array();

		if ( isset( $body['csv'] ) && '' !== trim( (string) $body['csv'] ) ) {
			$parsed = Woo_Pulse_Spend::parse_csv( (string) $body['csv'] );
			$rows   = $parsed['rows'];
			$errors = $parsed['errors'];
		} elseif ( isset( $body['rows'] ) && is_array( $body['rows'] ) ) {
			$rows = $body['rows'];
		} else {
			return new WP_Error( 'woo_pulse_bad_request', __( 'Send either csv text or an array of rows.', 'woo-pulse' ), array( 'status' => 400 ) );
		}

		if ( count( $rows ) > 5000 ) {
			return new WP_Error( 'woo_pulse_too_many', __( 'Import at most 5,000 rows at a time.', 'woo-pulse' ), array( 'status' => 400 ) );
		}

		$checked = array();
		$valid   = array();
		foreach ( $rows as $i => $row ) {
			$clean = Woo_Pulse_Spend::validate( is_array( $row ) ? $row : array() );
			$line  = isset( $row['line'] ) ? (int) $row['line'] : $i + 1;
			if ( is_wp_error( $clean ) ) {
				$checked[] = array(
					'line'  => $line,
					'ok'    => false,
					'error' => $clean->get_error_message(),
					'input' => $row,
				);
			} else {
				$checked[] = array_merge(
					array(
						'line' => $line,
						'ok'   => true,
					),
					$clean
				);
				$valid[]   = array_merge( $clean, array( 'line' => $line ) );
			}
		}

		if ( $preview ) {
			return rest_ensure_response(
				array(
					'rows'   => $checked,
					'valid'  => count( $valid ),
					'errors' => $errors,
				)
			);
		}

		$result            = Woo_Pulse_Spend::bulk_insert( $valid, $replace );
		$result['errors']  = array_merge( $errors, $result['errors'] );
		$result['skipped'] = count( $rows ) - count( $valid );
		return rest_ensure_response( $result );
	}

	public function settings_get() {
		return rest_ensure_response(
			array(
				'settings'    => Woo_Pulse_Settings::public_view( Woo_Pulse_Settings::get() ),
				'diagnostics' => self::diagnostics(),
			)
		);
	}

	public function settings_save( WP_REST_Request $req ) {
		$body = (array) $req->get_json_params();
		if ( empty( $body ) ) {
			return new WP_Error( 'woo_pulse_bad_request', __( 'No settings supplied.', 'woo-pulse' ), array( 'status' => 400 ) );
		}
		unset( $body['store_key'], $body['store_key_masked'] ); // the key is only ever changed through regenerate-key
		if ( ! empty( $body['connector_url'] ) ) {
			// Reject a bad service URL loudly instead of silently saving an empty one.
			$probe = Woo_Pulse_Settings::sanitize( array_merge( Woo_Pulse_Settings::get(), array( 'connector_url' => $body['connector_url'] ) ) );
			if ( '' === $probe['connector_url'] ) {
				return new WP_Error( 'woo_pulse_bad_url', __( 'The connector service URL must be a valid https:// address (http is allowed only for localhost).', 'woo-pulse' ), array( 'status' => 400 ) );
			}
		}
		$clean = Woo_Pulse_Settings::update( $body );
		return rest_ensure_response(
			array(
				'settings'    => Woo_Pulse_Settings::public_view( $clean ),
				'diagnostics' => self::diagnostics(),
			)
		);
	}

	public function cache_clear() {
		Woo_Pulse_Cache::bump();
		return rest_ensure_response( array( 'ok' => true ) );
	}

	public function digest_test( WP_REST_Request $req ) {
		$body = (array) $req->get_json_params();
		$to   = array();
		if ( ! empty( $body['to'] ) && is_string( $body['to'] ) && is_email( $body['to'] ) ) {
			$to[] = sanitize_email( $body['to'] );
		} else {
			$user = wp_get_current_user();
			if ( $user && $user->user_email ) {
				$to[] = $user->user_email;
			}
		}
		if ( empty( $to ) ) {
			return new WP_Error( 'woo_pulse_no_recipient', __( 'No recipient address available.', 'woo-pulse' ), array( 'status' => 400 ) );
		}
		$sent = Woo_Pulse_Digest::send( $to );
		return rest_ensure_response(
			array(
				'ok' => (bool) $sent,
				'to' => $to,
			)
		);
	}

	public static function diagnostics() {
		$orders = new Woo_Pulse_Orders();
		$roles  = array();
		foreach ( wp_roles()->get_names() as $slug => $name ) {
			if ( ! in_array( $slug, array( 'administrator', 'shop_manager' ), true ) ) {
				$roles[ $slug ] = translate_user_role( $name );
			}
		}
		return array(
			'storage'         => $orders->storage_label(),
			'timezone'        => wp_timezone_string(),
			'currency'        => get_woocommerce_currency(),
			'has_attribution' => $orders->has_meta( Woo_Pulse_Orders::META_SOURCE_TYPE ),
			'has_cogs'        => $orders->has_meta( Woo_Pulse_Orders::META_COGS ),
			'orders_last_30'  => $orders->recent_order_count( 30 ),
			'statuses'        => wc_get_order_statuses(),
			'roles'           => $roles,
			'digest_next_run' => Woo_Pulse_Digest::next_run(),
			'plugin_version'  => WOO_PULSE_VERSION,
			'woocommerce'     => defined( 'WC_VERSION' ) ? WC_VERSION : '',
		);
	}
}
