<?php
/**
 * Ad spend ledger (custom table), fixed-cost proration, CSV parsing and the marketing efficiency math.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Woo_Pulse_Spend {

	public static function table() {
		global $wpdb;
		return $wpdb->prefix . 'woo_pulse_spend';
	}

	/** Create or update the spend table. Safe to run repeatedly. */
	public static function install() {
		global $wpdb;
		$table   = self::table();
		$charset = $wpdb->get_charset_collate();
		// source: manual | csv | meta | google. external_id: the ad account a synced row came from.
		// The unique key lets connector pushes upsert one row per account per day; manual rows have NULL external_id and are never constrained.
		$sql     = "CREATE TABLE {$table} (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			spend_date DATE NOT NULL,
			channel VARCHAR(50) NOT NULL,
			amount DECIMAL(14,2) NOT NULL DEFAULT 0,
			reported_revenue DECIMAL(14,2) NULL,
			note VARCHAR(255) NULL,
			source VARCHAR(20) NOT NULL DEFAULT 'manual',
			external_id VARCHAR(100) NULL,
			currency VARCHAR(10) NULL,
			fx_rate DECIMAL(12,6) NULL,
			synced_at DATETIME NULL,
			created_at DATETIME NOT NULL,
			PRIMARY KEY  (id),
			KEY spend_date_channel (spend_date, channel),
			UNIQUE KEY synced_day (spend_date, source, external_id)
		) {$charset};";
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		dbDelta( $sql );
	}

	/* ------------------------------------------------------------------ */
	/* CRUD                                                                 */
	/* ------------------------------------------------------------------ */

	public static function entries( $start, $end ) {
		global $wpdb;
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				'SELECT id, spend_date, channel, amount, reported_revenue, note, source, external_id, currency, fx_rate, synced_at FROM ' . self::table() . ' WHERE spend_date >= %s AND spend_date <= %s ORDER BY spend_date DESC, channel ASC, id DESC',
				$start,
				$end
			),
			ARRAY_A
		);
		$out  = array();
		foreach ( $rows ? $rows : array() as $r ) {
			$out[] = self::format_row( $r );
		}
		return $out;
	}

	private static function format_row( array $r ) {
		return array(
			'id'               => (int) $r['id'],
			'spend_date'       => (string) $r['spend_date'],
			'channel'          => (string) $r['channel'],
			'amount'           => (float) $r['amount'],
			'reported_revenue' => ( null === $r['reported_revenue'] || '' === $r['reported_revenue'] ) ? null : (float) $r['reported_revenue'],
			'note'             => (string) $r['note'],
			'source'           => isset( $r['source'] ) && '' !== $r['source'] ? (string) $r['source'] : 'manual',
			'external_id'      => isset( $r['external_id'] ) ? (string) $r['external_id'] : '',
			'currency'         => isset( $r['currency'] ) ? (string) $r['currency'] : '',
			'fx_rate'          => ( isset( $r['fx_rate'] ) && null !== $r['fx_rate'] && '' !== $r['fx_rate'] ) ? (float) $r['fx_rate'] : null,
			'synced_at'        => isset( $r['synced_at'] ) ? (string) $r['synced_at'] : '',
			'readonly'         => Woo_Pulse_Connector::is_synced_source( isset( $r['source'] ) ? $r['source'] : 'manual' ),
		);
	}

	/** Source of one row, or null when it does not exist. */
	private static function row_source( $id ) {
		global $wpdb;
		$src = $wpdb->get_var( $wpdb->prepare( 'SELECT source FROM ' . self::table() . ' WHERE id = %d', (int) $id ) );
		return null === $src ? null : (string) $src;
	}

	/**
	 * Validate one entry. Returns clean data or a WP_Error.
	 */
	public static function validate( array $in ) {
		$date = isset( $in['spend_date'] ) ? self::normalize_date( $in['spend_date'] ) : null;
		if ( ! $date ) {
			return new WP_Error( 'woo_pulse_bad_date', __( 'Date must be a valid calendar date (YYYY-MM-DD).', 'woo-pulse' ) );
		}
		$channel = isset( $in['channel'] ) ? trim( sanitize_text_field( (string) $in['channel'] ) ) : '';
		if ( '' === $channel ) {
			return new WP_Error( 'woo_pulse_bad_channel', __( 'Channel is required.', 'woo-pulse' ) );
		}
		$amount = isset( $in['amount'] ) ? self::parse_money( $in['amount'] ) : null;
		if ( null === $amount || $amount < 0 ) {
			return new WP_Error( 'woo_pulse_bad_amount', __( 'Amount must be a number of zero or more.', 'woo-pulse' ) );
		}
		$reported = null;
		if ( isset( $in['reported_revenue'] ) && '' !== $in['reported_revenue'] && null !== $in['reported_revenue'] ) {
			$reported = self::parse_money( $in['reported_revenue'] );
			if ( null === $reported || $reported < 0 ) {
				return new WP_Error( 'woo_pulse_bad_reported', __( 'Reported revenue must be a number of zero or more, or empty.', 'woo-pulse' ) );
			}
		}
		$note = isset( $in['note'] ) ? mb_substr( trim( sanitize_text_field( (string) $in['note'] ) ), 0, 255 ) : '';
		return array(
			'spend_date'       => $date,
			'channel'          => mb_substr( $channel, 0, 50 ),
			'amount'           => round( $amount, 2 ),
			'reported_revenue' => null === $reported ? null : round( $reported, 2 ),
			'note'             => $note,
		);
	}

	public static function insert( array $in ) {
		global $wpdb;
		$clean = self::validate( $in );
		if ( is_wp_error( $clean ) ) {
			return $clean;
		}
		$clean['source']     = 'manual';
		$clean['created_at'] = current_time( 'mysql', true );
		$ok                  = $wpdb->insert( self::table(), $clean, array( '%s', '%s', '%f', '%f', '%s', '%s', '%s' ) );
		if ( false === $ok ) {
			return new WP_Error( 'woo_pulse_db', __( 'Could not save the spend entry.', 'woo-pulse' ) );
		}
		Woo_Pulse_Cache::bump();
		return (int) $wpdb->insert_id;
	}

	public static function update( $id, array $in ) {
		global $wpdb;
		$source = self::row_source( $id );
		if ( null === $source ) {
			return new WP_Error( 'woo_pulse_not_found', __( 'Spend entry not found.', 'woo-pulse' ) );
		}
		if ( Woo_Pulse_Connector::is_synced_source( $source ) ) {
			return new WP_Error( 'woo_pulse_readonly', __( 'Synced entries are read-only. Add a manual adjustment row instead.', 'woo-pulse' ) );
		}
		$clean = self::validate( $in );
		if ( is_wp_error( $clean ) ) {
			return $clean;
		}
		$ok = $wpdb->update( self::table(), $clean, array( 'id' => (int) $id ), array( '%s', '%s', '%f', '%f', '%s' ), array( '%d' ) );
		if ( false === $ok ) {
			return new WP_Error( 'woo_pulse_db', __( 'Could not update the spend entry.', 'woo-pulse' ) );
		}
		Woo_Pulse_Cache::bump();
		return true;
	}

	public static function delete( $id ) {
		global $wpdb;
		$source = self::row_source( $id );
		if ( null === $source ) {
			return new WP_Error( 'woo_pulse_not_found', __( 'Spend entry not found.', 'woo-pulse' ), array( 'status' => 404 ) );
		}
		if ( Woo_Pulse_Connector::is_synced_source( $source ) ) {
			return new WP_Error( 'woo_pulse_readonly', __( 'Synced entries are read-only. Disconnect the platform to remove them.', 'woo-pulse' ), array( 'status' => 403 ) );
		}
		$ok = $wpdb->delete( self::table(), array( 'id' => (int) $id ), array( '%d' ) );
		Woo_Pulse_Cache::bump();
		if ( false === $ok ) {
			return new WP_Error( 'woo_pulse_db', __( 'Could not delete the spend entry.', 'woo-pulse' ), array( 'status' => 500 ) );
		}
		return true;
	}

	/**
	 * Upsert rows pushed by a connector: one row per (date, source, account). Amounts arrive in the
	 * ad account's currency; fx_rate converts them to the store currency when the two differ.
	 *
	 * @return array inserted, updated, skipped, errors[]
	 */
	public static function upsert_synced( $source, $external_id, $channel, $account_name, array $rows ) {
		global $wpdb;
		$table  = self::table();
		$result = array(
			'inserted' => 0,
			'updated'  => 0,
			'skipped'  => 0,
			'errors'   => array(),
		);
		$now    = current_time( 'mysql', true );
		$source = sanitize_key( $source );
		$ext    = mb_substr( sanitize_text_field( (string) $external_id ), 0, 100 );
		$chan   = mb_substr( sanitize_text_field( (string) $channel ), 0, 50 );
		$note   = mb_substr( sanitize_text_field( (string) $account_name ), 0, 255 );

		$n = 0;
		foreach ( $rows as $row ) {
			$n++;
			if ( ! is_array( $row ) ) {
				$result['skipped']++;
				continue;
			}
			$date = self::normalize_date( isset( $row['spend_date'] ) ? $row['spend_date'] : '' );
			$amt  = isset( $row['amount'] ) ? self::parse_money( $row['amount'] ) : null;
			if ( ! $date || null === $amt || $amt < 0 ) {
				$result['errors'][] = array(
					'row'     => $n,
					'message' => __( 'Invalid date or amount.', 'woo-pulse' ),
				);
				continue;
			}
			$rep = null;
			if ( isset( $row['reported_revenue'] ) && null !== $row['reported_revenue'] && '' !== $row['reported_revenue'] ) {
				$rep = self::parse_money( $row['reported_revenue'] );
				if ( null === $rep || $rep < 0 ) {
					$rep = null;
				}
			}
			$fx  = ( isset( $row['fx_rate'] ) && is_numeric( $row['fx_rate'] ) && (float) $row['fx_rate'] > 0 ) ? (float) $row['fx_rate'] : null;
			$cur = isset( $row['currency'] ) ? mb_substr( sanitize_text_field( (string) $row['currency'] ), 0, 10 ) : '';
			if ( null !== $fx ) {
				$amt = $amt * $fx;
				if ( null !== $rep ) {
					$rep = $rep * $fx;
				}
			}
			// prepare() cannot emit SQL NULL, so each nullable value is prepared on its own and the list is assembled by hand.
			$values   = array(
				$wpdb->prepare( '%s', $date ),
				$wpdb->prepare( '%s', $chan ),
				$wpdb->prepare( '%f', round( $amt, 2 ) ),
				null === $rep ? 'NULL' : $wpdb->prepare( '%f', round( $rep, 2 ) ),
				$wpdb->prepare( '%s', $note ),
				$wpdb->prepare( '%s', $source ),
				$wpdb->prepare( '%s', $ext ),
				'' === $cur ? 'NULL' : $wpdb->prepare( '%s', $cur ),
				null === $fx ? 'NULL' : $wpdb->prepare( '%f', $fx ),
				$wpdb->prepare( '%s', $now ),
				$wpdb->prepare( '%s', $now ),
			);
			$prepared = "INSERT INTO {$table} (spend_date, channel, amount, reported_revenue, note, source, external_id, currency, fx_rate, synced_at, created_at)
				VALUES (" . implode( ', ', $values ) . ")
				ON DUPLICATE KEY UPDATE
					synced_at = IF(amount <> VALUES(amount) OR NOT (reported_revenue <=> VALUES(reported_revenue)) OR NOT (note <=> VALUES(note)) OR NOT (fx_rate <=> VALUES(fx_rate)), VALUES(synced_at), synced_at),
					channel = VALUES(channel), amount = VALUES(amount), reported_revenue = VALUES(reported_revenue), note = VALUES(note),
					currency = VALUES(currency), fx_rate = VALUES(fx_rate)";
			// synced_at only moves when the data changed, so MySQL's affected-rows value distinguishes insert (1), update (2) and unchanged (0).
			$affected = $wpdb->query( $prepared );
			if ( false === $affected ) {
				$result['errors'][] = array(
					'row'     => $n,
					'message' => $wpdb->last_error ? $wpdb->last_error : __( 'Database error.', 'woo-pulse' ),
				);
			} elseif ( 1 === (int) $affected ) {
				$result['inserted']++;
			} elseif ( 2 === (int) $affected ) {
				$result['updated']++;
			} else {
				$result['skipped']++; // identical row already present
			}
		}
		Woo_Pulse_Cache::bump();
		return $result;
	}

	/**
	 * Insert many rows. With $replace, existing rows for the same date + channel are deleted first.
	 */
	public static function bulk_insert( array $rows, $replace = false ) {
		global $wpdb;
		$result = array(
			'inserted' => 0,
			'replaced' => 0,
			'errors'   => array(),
		);
		$now    = current_time( 'mysql', true );
		foreach ( $rows as $i => $row ) {
			$line  = ( is_array( $row ) && isset( $row['line'] ) ) ? (int) $row['line'] : $i + 1;
			$clean = self::validate( is_array( $row ) ? $row : array() );
			if ( is_wp_error( $clean ) ) {
				$result['errors'][] = array(
					'row'     => $line,
					'message' => $clean->get_error_message(),
				);
				continue;
			}
			if ( $replace ) {
				// Only manual and CSV rows are replaced; synced rows belong to their connector.
				$deleted = $wpdb->query(
					$wpdb->prepare(
						'DELETE FROM ' . self::table() . " WHERE spend_date = %s AND channel = %s AND source IN ('manual','csv')",
						$clean['spend_date'],
						$clean['channel']
					)
				);
				if ( $deleted ) {
					$result['replaced'] += (int) $deleted;
				}
			}
			$clean['source']     = 'csv';
			$clean['created_at'] = $now;
			if ( false !== $wpdb->insert( self::table(), $clean, array( '%s', '%s', '%f', '%f', '%s', '%s', '%s' ) ) ) {
				$result['inserted']++;
			} else {
				$result['errors'][] = array(
					'row'     => $line,
					'message' => __( 'Database insert failed.', 'woo-pulse' ),
				);
			}
		}
		Woo_Pulse_Cache::bump();
		return $result;
	}

	/* ------------------------------------------------------------------ */
	/* Parsing                                                              */
	/* ------------------------------------------------------------------ */

	/** Accepts Y-m-d, Y/m/d, m/d/Y, d.m.Y, d-m-Y. Returns Y-m-d or null. */
	public static function normalize_date( $value ) {
		$v = trim( (string) $value );
		if ( '' === $v ) {
			return null;
		}
		$formats = array( 'Y-m-d', 'Y/m/d', 'm/d/Y', 'd.m.Y', 'd-m-Y', 'n/j/Y', 'j.n.Y' );
		foreach ( $formats as $f ) {
			$d = DateTimeImmutable::createFromFormat( '!' . $f, $v );
			if ( $d && $d->format( $f ) === $v ) {
				return $d->format( 'Y-m-d' );
			}
		}
		// Allow datetimes like "2026-10-01 00:00:00".
		if ( preg_match( '/^(\d{4}-\d{2}-\d{2})[ T]/', $v, $m ) ) {
			return self::normalize_date( $m[1] );
		}
		return null;
	}

	/** "$1,234.56" -> 1234.56; returns null when not numeric. */
	public static function parse_money( $value ) {
		if ( is_int( $value ) || is_float( $value ) ) {
			return (float) $value;
		}
		$v = trim( (string) $value );
		if ( '' === $v ) {
			return null;
		}
		$v = preg_replace( '/[^\d.,\-]/', '', $v );
		// If both separators appear, assume the last one is the decimal point.
		if ( false !== strpos( $v, ',' ) && false !== strpos( $v, '.' ) ) {
			if ( strrpos( $v, ',' ) > strrpos( $v, '.' ) ) {
				$v = str_replace( '.', '', $v );
				$v = str_replace( ',', '.', $v );
			} else {
				$v = str_replace( ',', '', $v );
			}
		} elseif ( false !== strpos( $v, ',' ) ) {
			// "1,50" (decimal comma) vs "1,500" (thousands): treat exactly two trailing digits as decimals.
			$v = preg_match( '/,\d{2}$/', $v ) ? str_replace( ',', '.', $v ) : str_replace( ',', '', $v );
		}
		if ( ! is_numeric( $v ) ) {
			return null;
		}
		return (float) $v;
	}

	/**
	 * Parse CSV text into candidate rows. Columns: date, channel, amount[, reported_revenue][, note].
	 * A header row is detected automatically. Returns rows (unvalidated) plus parse errors.
	 */
	public static function parse_csv( $text ) {
		$text  = str_replace( "\r\n", "\n", (string) $text );
		$text  = str_replace( "\r", "\n", $text );
		$lines = array_values( array_filter( array_map( 'trim', explode( "\n", $text ) ), 'strlen' ) );
		$rows  = array();
		$errors = array();
		if ( empty( $lines ) ) {
			return array(
				'rows'   => $rows,
				'errors' => array( __( 'No rows found.', 'woo-pulse' ) ),
			);
		}

		$delimiter = ( substr_count( $lines[0], ';' ) > substr_count( $lines[0], ',' ) ) ? ';' : ',';
		$first     = array_map( 'trim', str_getcsv( $lines[0], $delimiter, '"', '\\' ) );
		$map       = array(
			'date'     => 0,
			'channel'  => 1,
			'amount'   => 2,
			'reported' => 3,
			'note'     => 4,
		);
		$has_header = false;
		$lower      = array_map( 'strtolower', $first );
		if ( in_array( 'date', $lower, true ) || in_array( 'channel', $lower, true ) || in_array( 'amount', $lower, true ) || in_array( 'spend', $lower, true ) ) {
			$has_header = true;
			$map        = array(
				'date'     => null,
				'channel'  => null,
				'amount'   => null,
				'reported' => null,
				'note'     => null,
			);
			foreach ( $lower as $i => $h ) {
				$h = preg_replace( '/[^a-z_ ]/', '', $h );
				if ( in_array( $h, array( 'date', 'day', 'spend_date', 'spend date' ), true ) ) {
					$map['date'] = $i;
				} elseif ( in_array( $h, array( 'channel', 'platform', 'source', 'network' ), true ) ) {
					$map['channel'] = $i;
				} elseif ( in_array( $h, array( 'amount', 'spend', 'cost', 'ad spend', 'ad_spend', 'amount spent' ), true ) ) {
					$map['amount'] = $i;
				} elseif ( in_array( $h, array( 'reported_revenue', 'reported revenue', 'revenue', 'platform revenue', 'conversion value', 'purchase value' ), true ) ) {
					$map['reported'] = $i;
				} elseif ( in_array( $h, array( 'note', 'notes', 'comment' ), true ) ) {
					$map['note'] = $i;
				}
			}
			foreach ( array( 'date', 'channel', 'amount' ) as $req ) {
				if ( null === $map[ $req ] ) {
					$errors[] = sprintf( __( 'Header row is missing a "%s" column.', 'woo-pulse' ), $req );
				}
			}
			if ( $errors ) {
				return array(
					'rows'   => array(),
					'errors' => $errors,
				);
			}
		}

		$start = $has_header ? 1 : 0;
		for ( $i = $start; $i < count( $lines ); $i++ ) {
			$cols = array_map( 'trim', str_getcsv( $lines[ $i ], $delimiter, '"', '\\' ) );
			$get  = function ( $idx ) use ( $cols ) {
				return ( null !== $idx && isset( $cols[ $idx ] ) ) ? $cols[ $idx ] : '';
			};
			$rows[] = array(
				'line'             => $i + 1,
				'spend_date'       => $get( $map['date'] ),
				'channel'          => $get( $map['channel'] ),
				'amount'           => $get( $map['amount'] ),
				'reported_revenue' => $get( $map['reported'] ),
				'note'             => $get( $map['note'] ),
			);
		}
		return array(
			'rows'   => $rows,
			'errors' => $errors,
		);
	}

	/* ------------------------------------------------------------------ */
	/* Aggregates                                                           */
	/* ------------------------------------------------------------------ */

	/**
	 * Spend totals for a date range (inclusive, site-local dates).
	 */
	public static function totals( $start, $end ) {
		global $wpdb;
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				'SELECT spend_date, channel, SUM(amount) AS amount, SUM(reported_revenue) AS reported FROM ' . self::table() . ' WHERE spend_date >= %s AND spend_date <= %s GROUP BY spend_date, channel',
				$start,
				$end
			),
			ARRAY_A
		);
		$out  = array(
			'ad_spend'         => 0.0,
			'reported_revenue' => 0.0,
			'by_channel'       => array(),
			'by_date'          => array(),
		);
		foreach ( $rows ? $rows : array() as $r ) {
			$amt = (float) $r['amount'];
			$rep = (float) $r['reported'];
			$out['ad_spend']         += $amt;
			$out['reported_revenue'] += $rep;
			if ( ! isset( $out['by_channel'][ $r['channel'] ] ) ) {
				$out['by_channel'][ $r['channel'] ] = array(
					'channel'          => $r['channel'],
					'amount'           => 0.0,
					'reported_revenue' => 0.0,
				);
			}
			$out['by_channel'][ $r['channel'] ]['amount']           += $amt;
			$out['by_channel'][ $r['channel'] ]['reported_revenue'] += $rep;
			$out['by_date'][ $r['spend_date'] ] = ( isset( $out['by_date'][ $r['spend_date'] ] ) ? $out['by_date'][ $r['spend_date'] ] : 0 ) + $amt;
		}
		$out['by_channel'] = array_values( $out['by_channel'] );
		usort(
			$out['by_channel'],
			function ( $a, $b ) {
				if ( $a['amount'] == $b['amount'] ) {
					return 0;
				}
				return $a['amount'] > $b['amount'] ? -1 : 1;
			}
		);
		return $out;
	}

	/**
	 * Fixed monthly costs prorated per day across the range.
	 *
	 * @return array [ 'total' => float, 'by_date' => [ Y-m-d => float ], 'monthly' => float ]
	 */
	public static function fixed_costs( $start, $end, array $fixed, DateTimeZone $tz ) {
		$monthly = 0.0;
		foreach ( $fixed as $f ) {
			$monthly += (float) $f['amount'];
		}
		$out = array(
			'total'   => 0.0,
			'by_date' => array(),
			'monthly' => $monthly,
		);
		if ( $monthly <= 0 ) {
			return $out;
		}
		$cur = new DateTimeImmutable( $start, $tz );
		$e   = new DateTimeImmutable( $end, $tz );
		while ( $cur <= $e ) {
			$days_in_month = (int) $cur->format( 't' );
			$daily         = $monthly / $days_in_month;
			$out['by_date'][ $cur->format( 'Y-m-d' ) ] = $daily;
			$out['total'] += $daily;
			$cur = $cur->modify( '+1 day' );
		}
		return $out;
	}

	/**
	 * Marketing efficiency KPIs for a period.
	 *
	 * @param array $totals   Metrics totals for the period.
	 * @param array $series   Metrics series (buckets) for the period.
	 * @param array $period   start, end, granularity, timezone.
	 * @param array $settings Plugin settings.
	 */
	public static function marketing( array $totals, array $series, array $period, array $settings ) {
		$tz      = new DateTimeZone( $period['timezone'] );
		$spend   = self::totals( $period['start'], $period['end'] );
		$fixed   = self::fixed_costs( $period['start'], $period['end'], $settings['fixed_costs'], $tz );
		$revenue = (float) $totals['revenue'];
		$new_rev = (float) $totals['new_customer_revenue'];
		$ad      = (float) $spend['ad_spend'];
		$total   = $ad + $fixed['total'];
		$margin  = (float) $settings['gross_margin'];

		$div = function ( $a, $b ) {
			return ( $b > 0 ) ? $a / $b : null;
		};

		// Per-bucket spend and fixed cost so the UI can chart revenue vs spend and MER over time.
		$bucket_spend = array_fill_keys( $series['buckets'], 0.0 );
		$bucket_fixed = array_fill_keys( $series['buckets'], 0.0 );
		foreach ( $spend['by_date'] as $date => $amt ) {
			$b = Woo_Pulse_Dates::bucket_of( new DateTimeImmutable( $date, $tz ), $period['granularity'] );
			if ( isset( $bucket_spend[ $b ] ) ) {
				$bucket_spend[ $b ] += $amt;
			}
		}
		foreach ( $fixed['by_date'] as $date => $amt ) {
			$b = Woo_Pulse_Dates::bucket_of( new DateTimeImmutable( $date, $tz ), $period['granularity'] );
			if ( isset( $bucket_fixed[ $b ] ) ) {
				$bucket_fixed[ $b ] += $amt;
			}
		}

		$by_channel = array();
		foreach ( $spend['by_channel'] as $c ) {
			$by_channel[] = array(
				'channel'          => $c['channel'],
				'amount'           => $c['amount'],
				'share'            => $ad > 0 ? $c['amount'] / $ad : 0,
				'reported_revenue' => $c['reported_revenue'],
				'reported_roas'    => $div( $c['reported_revenue'], $c['amount'] ),
			);
		}

		$profit_margin_fraction = null;
		if ( 'cogs' === $totals['profit_method'] && $totals['net'] > 0 && null !== $totals['cogs'] ) {
			$profit_margin_fraction = ( $totals['net'] - $totals['cogs'] ) / $totals['net'];
		} elseif ( $margin > 0 ) {
			$profit_margin_fraction = $margin / 100;
		}

		return array(
			'ad_spend'                 => $ad,
			'fixed_costs'              => $fixed['total'],
			'fixed_monthly'            => $fixed['monthly'],
			'total_marketing'          => $total,
			'blended_roas'             => $div( $revenue, $ad ),
			'new_customer_roas'        => $div( $new_rev, $ad ),
			'mer_ratio'                => $div( $revenue, $total ),
			'mer_percent'              => $revenue > 0 ? $total / $revenue * 100 : null,
			'marketing_share'          => $revenue > 0 ? $total / $revenue : null,
			'cac'                      => $div( $total, (int) $totals['new_customers'] ),
			'cost_per_order'           => $div( $total, (int) $totals['orders'] ),
			'break_even_roas'          => ( null !== $profit_margin_fraction && $profit_margin_fraction > 0 ) ? 1 / $profit_margin_fraction : null,
			'contribution'             => ( null !== $profit_margin_fraction ) ? $revenue * $profit_margin_fraction - $total : null,
			'platform_reported_revenue' => (float) $spend['reported_revenue'],
			'platform_roas'            => $div( (float) $spend['reported_revenue'], $ad ),
			'platform_overclaim'       => ( $revenue > 0 && $spend['reported_revenue'] > 0 ) ? ( $spend['reported_revenue'] / $revenue ) : null,
			'by_channel'               => $by_channel,
			'series'                   => array(
				'buckets' => $series['buckets'],
				'spend'   => array_values( $bucket_spend ),
				'fixed'   => array_values( $bucket_fixed ),
			),
		);
	}
}
