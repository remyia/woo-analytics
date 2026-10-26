<?php
/**
 * Plugin settings, including the answers from the measurement setup quiz.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Woo_Pulse_Settings {

	const OPTION = 'woo_pulse_settings';

	public static function defaults() {
		return array(
			'setup_complete'           => false,
			// net   = order totals minus tax, shipping and refunds (what you keep for product).
			// gross = order totals as charged, minus refunds.
			'revenue_basis'            => 'net',
			'statuses'                 => array( 'wc-completed', 'wc-processing', 'wc-refunded' ),
			// Where to read the order's state/country from: shipping (billing fallback) or billing.
			'location_source'          => 'shipping',
			// Paid channels shown in the spend ledger.
			'channels'                 => array( 'Meta', 'Google' ),
			// Fixed monthly marketing costs, prorated per day. [ [ 'label' => 'Agency', 'amount' => 1200 ], ... ]
			'fixed_costs'              => array(),
			// Blended gross margin percent after COGS, before marketing. 0 = unknown.
			'gross_margin'             => 0,
			// Payment processing fee estimate: percent of order total plus a fixed amount per order.
			'fee_percent'              => 0,
			'fee_fixed'                => 0,
			// How MER is displayed: ratio (4.2x revenue per 1 spent) or percent (spend as % of revenue).
			'mer_display'              => 'ratio',
			'target_mer'               => 0,
			'target_roas'              => 0,
			// A customer is "active" if they ordered within this many days.
			'active_days'              => 90,
			// Default comparison: previous period of equal length, or the same dates last year.
			'compare_default'          => 'previous',
			// Extra WordPress role allowed to view the dashboard (administrators and shop managers always can).
			'extra_role'               => '',
			// Weekly snapshot email.
			'digest_enabled'           => false,
			'digest_recipients'        => array(),
			// Drop the spend table and options when the plugin is deleted.
			'remove_data_on_uninstall' => false,
			// Woo Pulse Connect service (hosted ad-platform connectors).
			'connector_url'            => '',
			'store_key'                => '',
			'store_id'                 => '',
			'keep_synced_on_disconnect' => true,
		);
	}

	/** Settings safe to hand to the browser: the store key is masked. */
	public static function public_view( array $s ) {
		$s['store_key_masked'] = ! empty( $s['store_key'] ) ? substr( $s['store_key'], 0, 6 ) . '…' . substr( $s['store_key'], -4 ) : '';
		unset( $s['store_key'] );
		return $s;
	}

	public static function get() {
		$saved = get_option( self::OPTION, array() );
		if ( ! is_array( $saved ) ) {
			$saved = array();
		}
		return array_merge( self::defaults(), $saved );
	}

	/**
	 * Merge, sanitize and save. Returns the clean settings.
	 */
	public static function update( array $data ) {
		$old   = self::get();
		$clean = self::sanitize( array_merge( $old, $data ) );
		update_option( self::OPTION, $clean, false );
		Woo_Pulse_Cache::bump();

		if ( $old['extra_role'] !== $clean['extra_role'] ) {
			Woo_Pulse_Plugin::sync_extra_role( $old['extra_role'], $clean['extra_role'] );
		}
		if ( $old['digest_enabled'] !== $clean['digest_enabled'] || $old['digest_recipients'] !== $clean['digest_recipients'] ) {
			Woo_Pulse_Digest::reschedule();
		}
		return $clean;
	}

	public static function sanitize( array $in ) {
		$d   = self::defaults();
		$out = array();

		$out['setup_complete'] = ! empty( $in['setup_complete'] );
		$out['revenue_basis']  = ( isset( $in['revenue_basis'] ) && in_array( $in['revenue_basis'], array( 'net', 'gross' ), true ) ) ? $in['revenue_basis'] : $d['revenue_basis'];

		$statuses = array();
		if ( ! empty( $in['statuses'] ) && is_array( $in['statuses'] ) ) {
			$valid = function_exists( 'wc_get_order_statuses' ) ? array_keys( wc_get_order_statuses() ) : array();
			foreach ( $in['statuses'] as $s ) {
				$s = sanitize_key( $s );
				if ( 0 !== strpos( $s, 'wc-' ) ) {
					$s = 'wc-' . $s;
				}
				if ( empty( $valid ) || in_array( $s, $valid, true ) ) {
					$statuses[] = $s;
				}
			}
		}
		$out['statuses'] = $statuses ? array_values( array_unique( $statuses ) ) : $d['statuses'];

		$out['location_source'] = ( isset( $in['location_source'] ) && 'billing' === $in['location_source'] ) ? 'billing' : 'shipping';

		$channels = array();
		if ( ! empty( $in['channels'] ) && is_array( $in['channels'] ) ) {
			foreach ( $in['channels'] as $c ) {
				$c = trim( sanitize_text_field( (string) $c ) );
				if ( '' !== $c ) {
					$channels[] = mb_substr( $c, 0, 50 );
				}
			}
		}
		$out['channels'] = array_values( array_unique( $channels ) );

		$fixed = array();
		if ( ! empty( $in['fixed_costs'] ) && is_array( $in['fixed_costs'] ) ) {
			foreach ( $in['fixed_costs'] as $row ) {
				if ( ! is_array( $row ) ) {
					continue;
				}
				$label  = isset( $row['label'] ) ? trim( sanitize_text_field( (string) $row['label'] ) ) : '';
				$amount = isset( $row['amount'] ) ? (float) $row['amount'] : 0;
				if ( '' === $label || $amount <= 0 ) {
					continue;
				}
				$fixed[] = array(
					'label'  => mb_substr( $label, 0, 80 ),
					'amount' => round( $amount, 2 ),
				);
			}
		}
		$out['fixed_costs'] = $fixed;

		$margin              = isset( $in['gross_margin'] ) ? (float) $in['gross_margin'] : 0;
		$out['gross_margin'] = ( $margin > 0 && $margin < 100 ) ? round( $margin, 2 ) : 0;

		$fee_percent        = isset( $in['fee_percent'] ) ? (float) $in['fee_percent'] : 0;
		$out['fee_percent'] = ( $fee_percent > 0 && $fee_percent < 100 ) ? round( $fee_percent, 3 ) : 0;
		$fee_fixed          = isset( $in['fee_fixed'] ) ? (float) $in['fee_fixed'] : 0;
		$out['fee_fixed']   = $fee_fixed > 0 ? round( $fee_fixed, 2 ) : 0;

		$out['mer_display'] = ( isset( $in['mer_display'] ) && 'percent' === $in['mer_display'] ) ? 'percent' : 'ratio';
		$out['target_mer']  = isset( $in['target_mer'] ) ? max( 0, round( (float) $in['target_mer'], 2 ) ) : 0;
		$out['target_roas'] = isset( $in['target_roas'] ) ? max( 0, round( (float) $in['target_roas'], 2 ) ) : 0;

		$active             = isset( $in['active_days'] ) ? (int) $in['active_days'] : $d['active_days'];
		$out['active_days'] = ( $active >= 7 && $active <= 730 ) ? $active : $d['active_days'];

		$out['compare_default'] = ( isset( $in['compare_default'] ) && 'year' === $in['compare_default'] ) ? 'year' : 'previous';

		$extra = isset( $in['extra_role'] ) ? sanitize_key( $in['extra_role'] ) : '';
		if ( $extra && ! get_role( $extra ) ) {
			$extra = '';
		}
		$out['extra_role'] = $extra;

		$out['digest_enabled'] = ! empty( $in['digest_enabled'] );
		$recipients            = array();
		if ( ! empty( $in['digest_recipients'] ) ) {
			$list = is_array( $in['digest_recipients'] ) ? $in['digest_recipients'] : preg_split( '/[\s,;]+/', (string) $in['digest_recipients'] );
			foreach ( $list as $email ) {
				$email = sanitize_email( trim( (string) $email ) );
				if ( $email && is_email( $email ) ) {
					$recipients[] = $email;
				}
			}
		}
		$out['digest_recipients'] = array_values( array_unique( $recipients ) );

		$out['remove_data_on_uninstall'] = ! empty( $in['remove_data_on_uninstall'] );

		$url = isset( $in['connector_url'] ) ? trim( (string) $in['connector_url'] ) : '';
		if ( '' !== $url ) {
			if ( ! wp_parse_url( $url, PHP_URL_SCHEME ) ) {
				$url = 'https://' . ltrim( $url, '/' );
			}
			$url = esc_url_raw( $url, array( 'http', 'https' ) );
			$host = wp_parse_url( $url, PHP_URL_HOST );
			$is_local = in_array( $host, array( 'localhost', '127.0.0.1', '[::1]' ), true ) || ( is_string( $host ) && preg_match( '/\.(localhost|test)$/', $host ) );
			if ( 0 !== strpos( $url, 'https://' ) && ! $is_local ) {
				$url = ''; // Production connector traffic carries the store key; require TLS.
			}
		}
		$out['connector_url'] = rtrim( $url, '/' );

		$key              = isset( $in['store_key'] ) ? (string) $in['store_key'] : '';
		$out['store_key'] = preg_match( '/^[0-9a-f]{64}$/', $key ) ? $key : '';
		$out['store_id']  = isset( $in['store_id'] ) ? mb_substr( sanitize_text_field( (string) $in['store_id'] ), 0, 64 ) : '';
		$out['keep_synced_on_disconnect'] = ! isset( $in['keep_synced_on_disconnect'] ) || ! empty( $in['keep_synced_on_disconnect'] );

		return $out;
	}

	/**
	 * Order statuses that count as a sale. HPOS stores them without the "wc-" prefix, posts with it.
	 */
	public static function counted_statuses( $with_prefix = true ) {
		$s = self::get();
		if ( $with_prefix ) {
			return $s['statuses'];
		}
		return array_map(
			function ( $st ) {
				return preg_replace( '/^wc-/', '', $st );
			},
			$s['statuses']
		);
	}
}
