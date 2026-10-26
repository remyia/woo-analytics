<?php
/**
 * Resolves a start/end date in the site's timezone into GMT bounds for SQL, plus the comparison period.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Woo_Pulse_Dates {

	const MAX_DAYS = 1830; // ~5 years

	/**
	 * @param string $start   Y-m-d, site timezone, inclusive.
	 * @param string $end     Y-m-d, site timezone, inclusive.
	 * @param string $compare 'previous' (equal-length period before) or 'year' (same dates last year).
	 * @return array
	 */
	public static function resolve( $start, $end, $compare = 'previous' ) {
		$tz  = wp_timezone();
		$utc = new DateTimeZone( 'UTC' );

		$today = new DateTimeImmutable( 'today', $tz );

		$s = self::parse( $start, $tz );
		$e = self::parse( $end, $tz );
		if ( ! $s || ! $e ) {
			$e = $today;
			$s = $today->modify( '-29 days' );
		}
		if ( $e < $s ) {
			$tmp = $s;
			$s   = $e;
			$e   = $tmp;
		}

		$days = (int) $s->diff( $e )->days + 1;
		if ( $days > self::MAX_DAYS ) {
			$s    = $e->modify( '-' . ( self::MAX_DAYS - 1 ) . ' days' );
			$days = self::MAX_DAYS;
		}

		$end_excl = $e->modify( '+1 day' );

		if ( 'year' === $compare ) {
			$cmp_start    = $s->modify( '-1 year' );
			$cmp_end_excl = $end_excl->modify( '-1 year' );
		} else {
			$compare      = 'previous';
			$cmp_end_excl = $s;
			$cmp_start    = $s->modify( '-' . $days . ' days' );
		}

		if ( $days <= 92 ) {
			$granularity = 'day';
		} elseif ( $days <= 740 ) {
			$granularity = 'week';
		} else {
			$granularity = 'month';
		}

		return array(
			'start'         => $s->format( 'Y-m-d' ),
			'end'           => $e->format( 'Y-m-d' ),
			'days'          => $days,
			'granularity'   => $granularity,
			'timezone'      => $tz->getName(),
			'compare'       => $compare,
			'gmt_start'     => $s->setTimezone( $utc )->format( 'Y-m-d H:i:s' ),
			'gmt_end'       => $end_excl->setTimezone( $utc )->format( 'Y-m-d H:i:s' ),
			'cmp_start'     => $cmp_start->format( 'Y-m-d' ),
			'cmp_end'       => $cmp_end_excl->modify( '-1 day' )->format( 'Y-m-d' ),
			'cmp_gmt_start' => $cmp_start->setTimezone( $utc )->format( 'Y-m-d H:i:s' ),
			'cmp_gmt_end'   => $cmp_end_excl->setTimezone( $utc )->format( 'Y-m-d H:i:s' ),
		);
	}

	/** Today's date in the site timezone. */
	public static function today() {
		return ( new DateTimeImmutable( 'today', wp_timezone() ) )->format( 'Y-m-d' );
	}

	/** Start/end for the last N full days ending yesterday (used by the digest). */
	public static function last_full_days( $n ) {
		$tz        = wp_timezone();
		$yesterday = ( new DateTimeImmutable( 'today', $tz ) )->modify( '-1 day' );
		return array(
			'start' => $yesterday->modify( '-' . ( $n - 1 ) . ' days' )->format( 'Y-m-d' ),
			'end'   => $yesterday->format( 'Y-m-d' ),
		);
	}

	/** GMT datetime string for "now minus N days" in the site timezone, used for the active-customer window. */
	public static function gmt_days_ago( $n ) {
		$tz = wp_timezone();
		$d  = ( new DateTimeImmutable( 'today', $tz ) )->modify( '-' . (int) $n . ' days' );
		return $d->setTimezone( new DateTimeZone( 'UTC' ) )->format( 'Y-m-d H:i:s' );
	}

	private static function parse( $ymd, DateTimeZone $tz ) {
		if ( ! is_string( $ymd ) || ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', $ymd ) ) {
			return null;
		}
		$d = DateTimeImmutable::createFromFormat( '!Y-m-d', $ymd, $tz );
		if ( ! $d || $d->format( 'Y-m-d' ) !== $ymd ) {
			return null;
		}
		return $d;
	}

	/**
	 * Convert a GMT datetime string to a DateTimeImmutable in the site timezone.
	 */
	public static function to_local( $gmt_datetime, DateTimeZone $tz ) {
		$d = new DateTimeImmutable( $gmt_datetime, new DateTimeZone( 'UTC' ) );
		return $d->setTimezone( $tz );
	}

	/**
	 * Bucket key for a local DateTimeImmutable.
	 *
	 * @return string Y-m-d for day, Y-m-d (Monday) for week, Y-m-01 for month.
	 */
	public static function bucket_of( DateTimeImmutable $local, $granularity ) {
		if ( 'week' === $granularity ) {
			return $local->modify( 'monday this week' )->format( 'Y-m-d' );
		}
		if ( 'month' === $granularity ) {
			return $local->format( 'Y-m-01' );
		}
		return $local->format( 'Y-m-d' );
	}

	/**
	 * All bucket keys covering start..end inclusive, so charts have zero-filled gaps.
	 */
	public static function buckets( $start, $end, $granularity, DateTimeZone $tz ) {
		$s   = new DateTimeImmutable( $start, $tz );
		$e   = new DateTimeImmutable( $end, $tz );
		$out = array();
		if ( 'week' === $granularity ) {
			$cur = $s->modify( 'monday this week' );
			while ( $cur <= $e ) {
				$out[] = $cur->format( 'Y-m-d' );
				$cur   = $cur->modify( '+1 week' );
			}
		} elseif ( 'month' === $granularity ) {
			$cur = $s->modify( 'first day of this month' );
			while ( $cur <= $e ) {
				$out[] = $cur->format( 'Y-m-01' );
				$cur   = $cur->modify( '+1 month' );
			}
		} else {
			$cur = $s;
			while ( $cur <= $e ) {
				$out[] = $cur->format( 'Y-m-d' );
				$cur   = $cur->modify( '+1 day' );
			}
		}
		return $out;
	}

	/**
	 * Month keys (Y-m) and quarter keys (Y-Qn) covering start..end, for the geography pivot.
	 */
	public static function periods( $start, $end, DateTimeZone $tz ) {
		$s        = ( new DateTimeImmutable( $start, $tz ) )->modify( 'first day of this month' );
		$e        = new DateTimeImmutable( $end, $tz );
		$months   = array();
		$quarters = array();
		$cur      = $s;
		while ( $cur <= $e ) {
			$months[] = $cur->format( 'Y-m' );
			$q        = $cur->format( 'Y' ) . '-Q' . ( (int) ceil( (int) $cur->format( 'n' ) / 3 ) );
			if ( ! in_array( $q, $quarters, true ) ) {
				$quarters[] = $q;
			}
			$cur = $cur->modify( '+1 month' );
		}
		return array(
			'months'   => $months,
			'quarters' => $quarters,
		);
	}

	public static function quarter_of( DateTimeImmutable $local ) {
		return $local->format( 'Y' ) . '-Q' . ( (int) ceil( (int) $local->format( 'n' ) / 3 ) );
	}
}
