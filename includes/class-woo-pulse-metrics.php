<?php
/**
 * Pure-PHP aggregation of normalized order rows into the numbers the dashboard shows.
 * No database access here; everything comes in as arrays from Woo_Pulse_Orders.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Woo_Pulse_Metrics {

	/**
	 * @param array $orders   Rows from Woo_Pulse_Orders::orders() for the period.
	 * @param array $refunds  Rows from Woo_Pulse_Orders::refunds() for the period.
	 * @param array $prior    key => true for customers who ordered before the period.
	 * @param array $period   start, end, granularity, timezone (Y-m-d strings, site timezone).
	 * @param array $settings Woo_Pulse_Settings::get().
	 * @param array $extras   items_sold (float), status_counts (array).
	 */
	public static function compute( array $orders, array $refunds, array $prior, array $period, array $settings, array $extras = array() ) {
		$tz          = new DateTimeZone( $period['timezone'] );
		$granularity = $period['granularity'];
		$basis       = $settings['revenue_basis'];
		$fee_pct     = (float) $settings['fee_percent'] / 100;
		$fee_fixed   = (float) $settings['fee_fixed'];

		$buckets = Woo_Pulse_Dates::buckets( $period['start'], $period['end'], $granularity, $tz );
		$series  = array();
		foreach ( array( 'revenue', 'gross', 'orders', 'new_customers', 'refunds', 'new_customer_revenue' ) as $m ) {
			$series[ $m ] = array_fill_keys( $buckets, 0.0 );
		}

		$t = array(
			'orders'               => 0,
			'gross'                => 0.0,
			'discounts'            => 0.0,
			'tax'                  => 0.0,
			'shipping'             => 0.0,
			'refunds'              => 0.0,
			'refund_count'         => 0,
			'net'                  => 0.0,
			'revenue'              => 0.0,
			'items'                => isset( $extras['items_sold'] ) ? (float) $extras['items_sold'] : 0.0,
			'customers'            => 0,
			'new_customers'        => 0,
			'returning_customers'  => 0,
			'new_customer_orders'  => 0,
			'new_customer_revenue' => 0.0,
			'returning_revenue'    => 0.0,
			'guest_orders'         => 0,
			'account_orders'       => 0,
			'fees'                 => 0.0,
			'cogs'                 => 0.0,
			'cogs_orders'          => 0,
		);

		$heat_orders  = array_fill( 0, 7, array_fill( 0, 24, 0 ) );
		$heat_revenue = array_fill( 0, 7, array_fill( 0, 24, 0.0 ) );

		$regions   = array(); // "US:CA" => aggregate
		$countries = array(); // "US" => aggregate
		$cities    = array(); // "US:CA" => [ city => [orders, revenue] ]
		$city_all  = array(); // "US:CA|Des Moines" => aggregate for the country-wide city table
		$payments  = array();
		$brands    = array();
		$gateway_labels = isset( $extras['gateway_labels'] ) && is_array( $extras['gateway_labels'] ) ? $extras['gateway_labels'] : array();
		$channels  = array(
			'source_type'   => array(),
			'source_medium' => array(),
			'campaign'      => array(),
			'device'        => array(),
		);

		$seen            = array(); // customer key => true (first seen within the period)
		$orders_per_cust = array();

		foreach ( $orders as $o ) {
			$local  = Woo_Pulse_Dates::to_local( $o['date_created_gmt'], $tz );
			$bucket = Woo_Pulse_Dates::bucket_of( $local, $granularity );
			$month  = $local->format( 'Y-m' );
			$qtr    = Woo_Pulse_Dates::quarter_of( $local );
			$dow    = ( (int) $local->format( 'N' ) ) - 1;
			$hour   = (int) $local->format( 'G' );

			$net = $o['total'] - $o['tax'] - $o['shipping'];
			$rev = ( 'net' === $basis ) ? $net : $o['total'];

			$key    = $o['key'];
			$is_new = ! isset( $prior[ $key ] ) && ! isset( $seen[ $key ] );
			$seen[ $key ] = true;
			$orders_per_cust[ $key ] = isset( $orders_per_cust[ $key ] ) ? $orders_per_cust[ $key ] + 1 : 1;

			$t['orders']++;
			$t['gross']     += $o['total'];
			$t['discounts'] += $o['discount'];
			$t['tax']       += $o['tax'];
			$t['shipping']  += $o['shipping'];
			$t['net']       += $net;
			$t['revenue']   += $rev;
			$t['fees']      += $o['total'] * $fee_pct + ( $o['total'] > 0 ? $fee_fixed : 0 );
			if ( null !== $o['cogs'] ) {
				$t['cogs'] += $o['cogs'];
				$t['cogs_orders']++;
			}
			if ( $o['customer_id'] > 0 ) {
				$t['account_orders']++;
			} else {
				$t['guest_orders']++;
			}
			if ( $is_new ) {
				$t['new_customers']++;
			}
			if ( ! isset( $prior[ $key ] ) ) {
				// Orders by customers acquired in this period (their first and any repeat orders inside it).
				$t['new_customer_orders']++;
				$t['new_customer_revenue'] += $rev;
			} else {
				$t['returning_revenue'] += $rev;
			}

			if ( isset( $series['revenue'][ $bucket ] ) ) {
				$series['revenue'][ $bucket ] += $rev;
				$series['gross'][ $bucket ]   += $o['total'];
				$series['orders'][ $bucket ]  += 1;
				if ( $is_new ) {
					$series['new_customers'][ $bucket ] += 1;
				}
				if ( ! isset( $prior[ $key ] ) ) {
					$series['new_customer_revenue'][ $bucket ] += $rev;
				}
			}

			$heat_orders[ $dow ][ $hour ]  += 1;
			$heat_revenue[ $dow ][ $hour ] += $rev;

			// Geography.
			$country = '' !== $o['country'] ? $o['country'] : '??';
			$state   = '' !== $o['state'] ? $o['state'] : '??';
			$rk      = $country . ':' . $state;
			if ( ! isset( $regions[ $rk ] ) ) {
				$regions[ $rk ] = self::blank_geo( $country, $state );
			}
			self::add_geo( $regions[ $rk ], $o, $rev, $net, $is_new, $key, $month, $qtr );
			if ( ! isset( $countries[ $country ] ) ) {
				$countries[ $country ] = self::blank_geo( $country, '' );
			}
			self::add_geo( $countries[ $country ], $o, $rev, $net, $is_new, $key, $month, $qtr );
			if ( '' !== $o['city'] ) {
				$city = ucwords( strtolower( $o['city'] ) );
				if ( ! isset( $cities[ $rk ][ $city ] ) ) {
					$cities[ $rk ][ $city ] = array( 'city' => $city, 'orders' => 0, 'revenue' => 0.0 );
				}
				$cities[ $rk ][ $city ]['orders']  += 1;
				$cities[ $rk ][ $city ]['revenue'] += $rev;

				// Country-wide city table.
				$ck = $rk . '|' . $city;
				if ( ! isset( $city_all[ $ck ] ) ) {
					$city_all[ $ck ] = array(
						'country'       => $country,
						'state'         => $state,
						'city'          => $city,
						'orders'        => 0,
						'gross'         => 0.0,
						'revenue'       => 0.0,
						'new_customers' => 0,
						'_keys'         => array(),
					);
				}
				$city_all[ $ck ]['orders']++;
				$city_all[ $ck ]['gross']   += $o['total'];
				$city_all[ $ck ]['revenue'] += $rev;
				if ( $is_new ) {
					$city_all[ $ck ]['new_customers']++;
				}
				$city_all[ $ck ]['_keys'][ $key ] = 1;
			}

			// Payment gateway (grouped by id, labelled with the checkout title) and card brand / wallet.
			$gw_id = isset( $o['payment_method_id'] ) ? $o['payment_method_id'] : '';
			if ( '' !== $gw_id ) {
				$gw_label = isset( $gateway_labels[ $gw_id ] ) ? $gateway_labels[ $gw_id ] : ucwords( str_replace( array( '_', '-' ), ' ', $gw_id ) );
			} else {
				$gw_label = '' !== $o['payment_method'] ? self::clean_payment_title( $o['payment_method'] ) : '(none)';
			}
			self::add_named( $payments, $gw_label, $rev, $is_new );
			$brand = self::clean_payment_title( $o['payment_method'] );
			self::add_named( $brands, '' !== $brand ? $brand : $gw_label, $rev, $is_new );

			// Channels from WooCommerce order attribution.
			$st = '' !== $o['source_type'] ? $o['source_type'] : '(no data)';
			self::add_named( $channels['source_type'], $st, $rev, $is_new );
			$src = '' !== $o['utm_source'] ? $o['utm_source'] : '(not set)';
			$med = '' !== $o['utm_medium'] ? $o['utm_medium'] : '(not set)';
			self::add_named( $channels['source_medium'], $src . ' / ' . $med, $rev, $is_new );
			if ( '' !== $o['utm_campaign'] ) {
				self::add_named( $channels['campaign'], $o['utm_campaign'], $rev, $is_new );
			}
			$dev = '' !== $o['device'] ? $o['device'] : '(no data)';
			self::add_named( $channels['device'], $dev, $rev, $is_new );
		}

		// Refunds: attributed by refund date, reduce revenue in totals, series and geography.
		foreach ( $refunds as $r ) {
			$t['refunds'] += $r['amount'];
			$t['refund_count']++;
			$local  = Woo_Pulse_Dates::to_local( $r['date_created_gmt'], $tz );
			$bucket = Woo_Pulse_Dates::bucket_of( $local, $granularity );
			if ( isset( $series['refunds'][ $bucket ] ) ) {
				$series['refunds'][ $bucket ] += $r['amount'];
				$series['revenue'][ $bucket ] -= $r['amount'];
			}
			$country = '' !== $r['country'] ? $r['country'] : '??';
			$state   = '' !== $r['state'] ? $r['state'] : '??';
			$rk      = $country . ':' . $state;
			if ( isset( $regions[ $rk ] ) ) {
				$regions[ $rk ]['refunds'] += $r['amount'];
				$regions[ $rk ]['revenue'] -= $r['amount'];
			}
			if ( isset( $countries[ $country ] ) ) {
				$countries[ $country ]['refunds'] += $r['amount'];
				$countries[ $country ]['revenue'] -= $r['amount'];
			}
		}
		$t['revenue'] -= $t['refunds'];
		$t['net']     -= $t['refunds'];

		// Derived totals.
		$t['customers']           = count( $seen );
		$t['returning_customers'] = $t['customers'] - $t['new_customers'];
		$t['aov']                 = $t['orders'] > 0 ? $t['gross'] / $t['orders'] : 0;
		$t['items_per_order']     = $t['orders'] > 0 ? $t['items'] / $t['orders'] : 0;
		$t['discount_rate']       = ( $t['gross'] + $t['discounts'] ) > 0 ? $t['discounts'] / ( $t['gross'] + $t['discounts'] ) : 0;
		$t['refund_rate']         = $t['gross'] > 0 ? $t['refunds'] / $t['gross'] : 0;
		$t['guest_share']         = $t['orders'] > 0 ? $t['guest_orders'] / $t['orders'] : 0;
		$t['revenue_per_customer'] = $t['customers'] > 0 ? $t['revenue'] / $t['customers'] : 0;
		$t['orders_per_customer'] = $t['customers'] > 0 ? $t['orders'] / $t['customers'] : 0;
		$repeat                   = 0;
		foreach ( $orders_per_cust as $n ) {
			if ( $n >= 2 ) {
				$repeat++;
			}
		}
		$t['repeat_customers_in_range'] = $repeat;
		$t['repeat_rate']               = $t['customers'] > 0 ? $repeat / $t['customers'] : 0;

		$t['cogs_coverage'] = $t['orders'] > 0 ? $t['cogs_orders'] / $t['orders'] : 0;
		$margin             = (float) $settings['gross_margin'];
		if ( $t['cogs_orders'] > 0 && $t['cogs_coverage'] >= 0.5 ) {
			$t['profit_method'] = 'cogs';
			$t['gross_profit']  = $t['net'] - $t['cogs'] - $t['fees'];
		} elseif ( $margin > 0 ) {
			$t['profit_method'] = 'margin';
			$t['gross_profit']  = $t['net'] * ( $margin / 100 ) - $t['fees'];
		} else {
			$t['profit_method'] = 'none';
			$t['gross_profit']  = null;
		}
		if ( $t['cogs_orders'] === 0 ) {
			$t['cogs'] = null;
		}

		// Finalize lists.
		$region_list = array_values( $regions );
		foreach ( $region_list as &$g ) {
			self::finish_geo( $g, $t['revenue'] );
		}
		unset( $g );
		usort( $region_list, array( __CLASS__, 'by_revenue_desc' ) );

		$country_list = array_values( $countries );
		foreach ( $country_list as &$g ) {
			self::finish_geo( $g, $t['revenue'] );
		}
		unset( $g );
		usort( $country_list, array( __CLASS__, 'by_revenue_desc' ) );

		$city_lists = array();
		foreach ( $cities as $rk => $list ) {
			$list = array_values( $list );
			usort( $list, array( __CLASS__, 'by_revenue_desc' ) );
			$city_lists[ $rk ] = array_slice( $list, 0, 15 );
		}

		// Top cities per country (up to 150 each), with share of the period's total revenue.
		$city_tables = array();
		foreach ( $city_all as $c ) {
			$c['customers']          = count( $c['_keys'] );
			unset( $c['_keys'] );
			$c['aov']                = $c['orders'] > 0 ? $c['gross'] / $c['orders'] : 0;
			$c['share']              = $t['revenue'] > 0 ? $c['revenue'] / $t['revenue'] : 0;
			$c['new_customer_share'] = $c['customers'] > 0 ? $c['new_customers'] / $c['customers'] : 0;
			$city_tables[ $c['country'] ][] = $c;
		}
		foreach ( $city_tables as $cc => &$list ) {
			usort( $list, array( __CLASS__, 'by_revenue_desc' ) );
			$list = array_slice( $list, 0, 150 );
		}
		unset( $list );

		$channel_lists = array();
		foreach ( $channels as $dim => $list ) {
			$channel_lists[ $dim ] = self::finish_named( $list, $t['revenue'], 30 );
		}

		return array(
			'totals'          => $t,
			'series'          => array(
				'buckets'              => $buckets,
				'revenue'              => array_values( $series['revenue'] ),
				'gross'                => array_values( $series['gross'] ),
				'orders'               => array_values( $series['orders'] ),
				'new_customers'        => array_values( $series['new_customers'] ),
				'new_customer_revenue' => array_values( $series['new_customer_revenue'] ),
				'refunds'              => array_values( $series['refunds'] ),
			),
			'heatmap'         => array(
				'orders'  => $heat_orders,
				'revenue' => $heat_revenue,
			),
			'status_counts'   => isset( $extras['status_counts'] ) ? $extras['status_counts'] : array(),
			'payment_methods' => self::finish_named( $payments, $t['revenue'], 20 ),
			'payment_brands'  => self::finish_named( $brands, $t['revenue'], 20 ),
			'geo'             => array(
				'regions'    => $region_list,
				'countries'  => $country_list,
				'cities'     => $city_lists,
				'top_cities' => $city_tables,
			),
			'channels'        => $channel_lists,
		);
	}

	/* ------------------------------------------------------------------ */

	/**
	 * Reduce a per-order payment title such as "Visa ending in 2696" or "PayPal - someone@example.com"
	 * to its brand or wallet: "Visa", "PayPal".
	 */
	public static function clean_payment_title( $title ) {
		$t = trim( (string) $title );
		if ( '' === $t ) {
			return '';
		}
		$t = preg_replace( '/\s*\(.*?\)\s*$/', '', $t );                     // trailing "(...)" notes
		$t = preg_replace( '/\s*[-–:]\s*\S+@\S+.*$/u', '', $t );                // " - email@host"
		$t = preg_replace( '/\s+(ending|end)\s+(in|with)\s+[x*\d]+.*$/i', '', $t ); // "ending in 1234"
		$t = preg_replace( '/\s*[x*]{2,}\s*\d{2,}.*$/', '', $t );               // "**** 1234"
		$t = preg_replace( '/\s+\d{4}$/', '', $t );                             // trailing last-four
		$t = preg_replace( '/\s*[-–:]\s*$/u', '', $t );
		$t = trim( $t );
		$map = array(
			'mastercard'       => 'Mastercard',
			'master card'      => 'Mastercard',
			'american express' => 'American Express',
			'amex'             => 'American Express',
			'visa'             => 'Visa',
			'discover'         => 'Discover',
			'paypal'           => 'PayPal',
			'apple pay'        => 'Apple Pay',
			'google pay'       => 'Google Pay',
			'venmo'            => 'Venmo',
		);
		$lower = strtolower( $t );
		foreach ( $map as $needle => $label ) {
			if ( false !== strpos( $lower, $needle ) ) {
				return $label;
			}
		}
		return $t;
	}

	private static function blank_geo( $country, $state ) {
		return array(
			'country'       => $country,
			'state'         => $state,
			'orders'        => 0,
			'gross'         => 0.0,
			'net'           => 0.0,
			'revenue'       => 0.0,
			'tax'           => 0.0,
			'shipping'      => 0.0,
			'refunds'       => 0.0,
			'new_customers' => 0,
			'_keys'         => array(),
			'months'        => array(),
			'quarters'      => array(),
		);
	}

	private static function add_geo( array &$g, array $o, $rev, $net, $is_new, $key, $month, $qtr ) {
		$g['orders']++;
		$g['gross']    += $o['total'];
		$g['net']      += $net;
		$g['revenue']  += $rev;
		$g['tax']      += $o['tax'];
		$g['shipping'] += $o['shipping'];
		if ( $is_new ) {
			$g['new_customers']++;
		}
		$g['_keys'][ $key ]  = 1;
		$g['months'][ $month ]  = ( isset( $g['months'][ $month ] ) ? $g['months'][ $month ] : 0 ) + $rev;
		$g['quarters'][ $qtr ]  = ( isset( $g['quarters'][ $qtr ] ) ? $g['quarters'][ $qtr ] : 0 ) + $rev;
	}

	private static function finish_geo( array &$g, $total_revenue ) {
		$g['customers'] = count( $g['_keys'] );
		unset( $g['_keys'] );
		$g['aov']               = $g['orders'] > 0 ? $g['gross'] / $g['orders'] : 0;
		$g['share']             = $total_revenue > 0 ? $g['revenue'] / $total_revenue : 0;
		$g['new_customer_share'] = $g['customers'] > 0 ? $g['new_customers'] / $g['customers'] : 0;
		$g['revenue_per_order'] = $g['orders'] > 0 ? $g['revenue'] / $g['orders'] : 0;
	}

	private static function add_named( array &$list, $name, $rev, $is_new ) {
		if ( ! isset( $list[ $name ] ) ) {
			$list[ $name ] = array(
				'name'          => $name,
				'orders'        => 0,
				'revenue'       => 0.0,
				'new_customers' => 0,
			);
		}
		$list[ $name ]['orders']++;
		$list[ $name ]['revenue'] += $rev;
		if ( $is_new ) {
			$list[ $name ]['new_customers']++;
		}
	}

	private static function finish_named( array $list, $total_revenue, $limit ) {
		$list = array_values( $list );
		foreach ( $list as &$row ) {
			$row['aov']                = $row['orders'] > 0 ? $row['revenue'] / $row['orders'] : 0;
			$row['share']              = $total_revenue > 0 ? $row['revenue'] / $total_revenue : 0;
			$row['new_customer_share'] = $row['orders'] > 0 ? $row['new_customers'] / $row['orders'] : 0;
		}
		unset( $row );
		usort( $list, array( __CLASS__, 'by_revenue_desc' ) );
		return array_slice( $list, 0, $limit );
	}

	public static function by_revenue_desc( $a, $b ) {
		if ( $a['revenue'] == $b['revenue'] ) {
			return 0;
		}
		return ( $a['revenue'] > $b['revenue'] ) ? -1 : 1;
	}

	/**
	 * Cohort retention and order-sequence figures from Woo_Pulse_Orders::customer_timeline().
	 *
	 * @param array  $timeline key => [first, second, n, months]
	 * @param string $today    Y-m-d in site timezone.
	 * @return array
	 */
	public static function cohorts( array $timeline, $today ) {
		$tz        = new DateTimeZone( 'UTC' );
		$this_m    = new DateTimeImmutable( substr( $today, 0, 7 ) . '-01', $tz );
		$last_full = $this_m->modify( '-1 month' );

		// Twelve cohort months ending with the last complete month.
		$cohort_months = array();
		for ( $i = 11; $i >= 0; $i-- ) {
			$cohort_months[] = $last_full->modify( '-' . $i . ' months' )->format( 'Y-m' );
		}
		$first_cohort = $cohort_months[0];

		$cohorts = array();
		foreach ( $cohort_months as $cm ) {
			$cohorts[ $cm ] = array(
				'month'     => $cm,
				'size'      => 0,
				'retained'  => array_fill( 0, 12, 0 ),
				'available' => 0, // number of offsets that have fully elapsed
			);
		}

		$days_to_second = array();
		foreach ( $timeline as $c ) {
			$first_m = isset( $c['first_m'] ) ? $c['first_m'] : gmdate( 'Y-m', $c['first'] );
			if ( $first_m < $first_cohort ) {
				continue; // acquired before the cohort window
			}
			if ( isset( $cohorts[ $first_m ] ) ) {
				$cohorts[ $first_m ]['size']++;
				$base = new DateTimeImmutable( $first_m . '-01', $tz );
				for ( $k = 1; $k <= 12; $k++ ) {
					$mk = $base->modify( '+' . $k . ' months' )->format( 'Y-m' );
					if ( isset( $c['months'][ $mk ] ) ) {
						$cohorts[ $first_m ]['retained'][ $k - 1 ]++;
					}
				}
				if ( null !== $c['second'] ) {
					$days_to_second[] = ( $c['second'] - $c['first'] ) / DAY_IN_SECONDS;
				}
			}
		}

		$rows = array();
		foreach ( $cohorts as $cm => $co ) {
			$base  = new DateTimeImmutable( $cm . '-01', $tz );
			$avail = 0;
			for ( $k = 1; $k <= 12; $k++ ) {
				if ( $base->modify( '+' . $k . ' months' ) <= $last_full ) {
					$avail = $k;
				}
			}
			$rates = array();
			for ( $k = 0; $k < 12; $k++ ) {
				$rates[] = ( $k < $avail && $co['size'] > 0 ) ? $co['retained'][ $k ] / $co['size'] : null;
			}
			$rows[] = array(
				'month'     => $cm,
				'size'      => $co['size'],
				'rates'     => $rates,
				'available' => $avail,
			);
		}

		$median_days = null;
		$avg_days    = null;
		if ( ! empty( $days_to_second ) ) {
			sort( $days_to_second );
			$n           = count( $days_to_second );
			$median_days = $days_to_second[ (int) floor( ( $n - 1 ) / 2 ) ];
			$avg_days    = array_sum( $days_to_second ) / $n;
		}

		return array(
			'cohorts'                => $rows,
			'median_days_to_second'  => $median_days,
			'avg_days_to_second'     => $avg_days,
			'second_order_customers' => count( $days_to_second ),
		);
	}
}
