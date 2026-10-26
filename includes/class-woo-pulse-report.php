<?php
/**
 * Builds the full dashboard payload for a date range: current period, comparison period, lifetime customer figures.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Woo_Pulse_Report {

	/**
	 * @param string $start   Y-m-d
	 * @param string $end     Y-m-d
	 * @param string $compare previous|year
	 * @param bool   $nocache
	 */
	public static function build( $start, $end, $compare = 'previous', $nocache = false ) {
		$settings = Woo_Pulse_Settings::get();
		$range    = Woo_Pulse_Dates::resolve( $start, $end, $compare );
		$args     = array( $range['start'], $range['end'], $range['compare'] );

		if ( ! $nocache ) {
			$cached = Woo_Pulse_Cache::get( 'report', $args );
			if ( is_array( $cached ) ) {
				$cached['cached'] = true;
				return $cached;
			}
		}

		$orders = new Woo_Pulse_Orders();

		$current = self::period(
			$orders,
			array(
				'start'       => $range['start'],
				'end'         => $range['end'],
				'gmt_start'   => $range['gmt_start'],
				'gmt_end'     => $range['gmt_end'],
				'granularity' => $range['granularity'],
				'timezone'    => $range['timezone'],
			),
			$settings,
			true
		);

		$previous = self::period(
			$orders,
			array(
				'start'       => $range['cmp_start'],
				'end'         => $range['cmp_end'],
				'gmt_start'   => $range['cmp_gmt_start'],
				'gmt_end'     => $range['cmp_gmt_end'],
				'granularity' => $range['granularity'],
				'timezone'    => $range['timezone'],
			),
			$settings,
			false
		);

		$payload = array(
			'range'        => $range,
			'periods'      => Woo_Pulse_Dates::periods( $range['start'], $range['end'], new DateTimeZone( $range['timezone'] ) ),
			'storage'      => $orders->storage_label(),
			'current'      => $current,
			'previous'     => $previous,
			'lifetime'     => self::lifetime( $orders, $settings, $nocache ),
			'generated_at' => gmdate( 'c' ),
			'cached'       => false,
		);
		// Database errors from any query in this build, so a failing report never looks like an empty store.
		$payload['errors'] = $orders->errors();
		if ( ! empty( $payload['errors'] ) ) {
			return $payload; // Do not cache a broken report.
		}

		Woo_Pulse_Cache::set( 'report', $args, $payload );
		return $payload;
	}

	/**
	 * Everything for one period. $full adds the item-level reports (products, coupons, ...).
	 */
	private static function period( Woo_Pulse_Orders $orders, array $p, array $settings, $full ) {
		$rows    = $orders->orders( $p['gmt_start'], $p['gmt_end'] );
		$refunds = $orders->refunds( $p['gmt_start'], $p['gmt_end'] );
		$keys    = array();
		foreach ( $rows as $r ) {
			$keys[] = $r['key'];
		}
		$prior  = $orders->prior_customers( $keys, $p['gmt_start'] );
		$extras = array(
			'items_sold'     => $orders->items_sold( $p['gmt_start'], $p['gmt_end'] ),
			'status_counts'  => $orders->status_counts( $p['gmt_start'], $p['gmt_end'] ),
			'gateway_labels' => self::gateway_labels(),
		);

		$m              = Woo_Pulse_Metrics::compute( $rows, $refunds, $prior, $p, $settings, $extras );
		$m['marketing'] = Woo_Pulse_Spend::marketing( $m['totals'], $m['series'], $p, $settings );
		$m['period']    = array(
			'start' => $p['start'],
			'end'   => $p['end'],
		);

		if ( $full ) {
			$m['products']         = $orders->products( $p['gmt_start'], $p['gmt_end'], 50 );
			$m['categories']       = $orders->terms_report( $p['gmt_start'], $p['gmt_end'], 'product_cat', 30 );
			$m['brands']           = $orders->terms_report( $p['gmt_start'], $p['gmt_end'], 'product_brand', 30 );
			$m['coupons']          = $orders->coupons( $p['gmt_start'], $p['gmt_end'], 30 );
			$m['shipping_methods'] = $orders->shipping_methods( $p['gmt_start'], $p['gmt_end'], 20 );
		} else {
			// Keep the comparison payload small: drop what the UI never compares.
			unset( $m['heatmap'], $m['payment_methods'], $m['payment_brands'] );
			$m['geo'] = array(
				'regions'   => $m['geo']['regions'],
				'countries' => $m['geo']['countries'],
			);
			$m['channels'] = array( 'source_type' => $m['channels']['source_type'] );
		}
		return $m;
	}

	/** Gateway id => checkout title, e.g. braintree_credit_card => "Credit Card". */
	private static function gateway_labels() {
		static $labels = null;
		if ( null !== $labels ) {
			return $labels;
		}
		$labels = array();
		if ( function_exists( 'WC' ) && WC()->payment_gateways() ) {
			foreach ( WC()->payment_gateways()->payment_gateways() as $id => $gateway ) {
				$title = is_object( $gateway ) && method_exists( $gateway, 'get_title' ) ? $gateway->get_title() : '';
				$labels[ $id ] = '' !== $title ? wp_strip_all_tags( $title ) : ucwords( str_replace( array( '_', '-' ), ' ', $id ) );
			}
		}
		return $labels;
	}

	/**
	 * All-time customer figures. Cached for an hour on their own key because they scan every order.
	 */
	private static function lifetime( Woo_Pulse_Orders $orders, array $settings, $nocache ) {
		$args = array( 'lifetime', $settings['revenue_basis'], $settings['active_days'] );
		if ( ! $nocache ) {
			$cached = Woo_Pulse_Cache::get( 'lifetime', $args );
			if ( is_array( $cached ) ) {
				return $cached;
			}
		}

		$tz           = wp_timezone();
		$offset       = $tz->getOffset( new DateTime( 'now', $tz ) );
		$since        = ( new DateTimeImmutable( 'first day of this month', $tz ) )->setTime( 0, 0 )->modify( '-24 months' );
		$since_gmt    = $since->setTimezone( new DateTimeZone( 'UTC' ) )->format( 'Y-m-d H:i:s' );
		$active_since = Woo_Pulse_Dates::gmt_days_ago( (int) $settings['active_days'] );

		$stats    = $orders->lifetime_stats( $settings['revenue_basis'], $active_since );
		$top      = $orders->top_customers( $settings['revenue_basis'], 25 );
		$timeline = $orders->customer_timeline( $since_gmt, $offset );
		$cohorts  = Woo_Pulse_Metrics::cohorts( $timeline, Woo_Pulse_Dates::today() );

		$out = array(
			'stats'         => $stats,
			'top_customers' => $top,
			'cohorts'       => $cohorts['cohorts'],
			'sequence'      => array(
				'median_days_to_second'  => $cohorts['median_days_to_second'],
				'avg_days_to_second'     => $cohorts['avg_days_to_second'],
				'second_order_customers' => $cohorts['second_order_customers'],
				'reached_2'              => $stats['reached_2'],
				'reached_3'              => $stats['reached_3'],
				'reached_4'              => $stats['reached_4'],
				'customers'              => $stats['customers'],
			),
			'active_days'   => (int) $settings['active_days'],
			'window_months' => 24,
		);
		Woo_Pulse_Cache::set( 'lifetime', $args, $out, HOUR_IN_SECONDS );
		return $out;
	}
}
