<?php
/**
 * Weekly snapshot email: last 7 full days vs the 7 before, sent Monday morning via WP-Cron.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Woo_Pulse_Digest {

	const HOOK = 'woo_pulse_weekly_digest';

	public function __construct() {
		add_action( self::HOOK, array( __CLASS__, 'send_scheduled' ) );
	}

	/** Schedule (or clear) the weekly event according to settings. */
	public static function reschedule() {
		self::unschedule();
		$s = Woo_Pulse_Settings::get();
		if ( empty( $s['digest_enabled'] ) || empty( $s['digest_recipients'] ) ) {
			return;
		}
		$tz    = wp_timezone();
		$first = new DateTimeImmutable( 'next monday 07:00', $tz );
		wp_schedule_event( $first->getTimestamp(), 'weekly', self::HOOK );
	}

	public static function unschedule() {
		wp_clear_scheduled_hook( self::HOOK );
	}

	/** Next scheduled run as a site-local string, or empty. */
	public static function next_run() {
		$ts = wp_next_scheduled( self::HOOK );
		if ( ! $ts ) {
			return '';
		}
		return wp_date( 'Y-m-d H:i', $ts );
	}

	public static function send_scheduled() {
		$s = Woo_Pulse_Settings::get();
		if ( empty( $s['digest_enabled'] ) || empty( $s['digest_recipients'] ) ) {
			return;
		}
		self::send( $s['digest_recipients'] );
	}

	/**
	 * Build and send the digest to the given addresses.
	 */
	public static function send( array $to ) {
		$to = array_values( array_filter( array_map( 'sanitize_email', $to ), 'is_email' ) );
		if ( empty( $to ) ) {
			return false;
		}
		$days   = Woo_Pulse_Dates::last_full_days( 7 );
		$report = Woo_Pulse_Report::build( $days['start'], $days['end'], 'previous', false );
		$html   = self::render( $report );
		$name   = wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES );
		$subject = sprintf(
			/* translators: 1: store name, 2: start date, 3: end date */
			__( '%1$s weekly snapshot: %2$s to %3$s', 'woo-pulse' ),
			$name,
			$days['start'],
			$days['end']
		);
		$headers = array( 'Content-Type: text/html; charset=UTF-8' );
		return wp_mail( $to, $subject, $html, $headers );
	}

	/* ------------------------------------------------------------------ */

	private static function money( $v ) {
		if ( null === $v ) {
			return 'n/a';
		}
		return html_entity_decode( wp_strip_all_tags( wc_price( (float) $v ) ), ENT_QUOTES, 'UTF-8' );
	}

	private static function ratio( $v ) {
		return null === $v ? 'n/a' : number_format_i18n( (float) $v, 2 ) . 'x';
	}

	private static function delta( $cur, $prev ) {
		if ( null === $cur || null === $prev || 0 == $prev ) {
			return '';
		}
		$pct   = ( $cur - $prev ) / abs( $prev ) * 100;
		$sign  = $pct >= 0 ? '+' : '';
		$color = $pct >= 0 ? '#1a7f37' : '#b42318';
		return '<span style="color:' . $color . ';font-size:12px;">' . $sign . number_format_i18n( $pct, 1 ) . '%</span>';
	}

	private static function render( array $r ) {
		$c  = $r['current']['totals'];
		$p  = $r['previous']['totals'];
		$cm = $r['current']['marketing'];
		$pm = $r['previous']['marketing'];
		$s  = Woo_Pulse_Settings::get();

		$rows = array(
			array( __( 'Revenue', 'woo-pulse' ), self::money( $c['revenue'] ), self::delta( $c['revenue'], $p['revenue'] ) ),
			array( __( 'Orders', 'woo-pulse' ), number_format_i18n( $c['orders'] ), self::delta( $c['orders'], $p['orders'] ) ),
			array( __( 'Average order value', 'woo-pulse' ), self::money( $c['aov'] ), self::delta( $c['aov'], $p['aov'] ) ),
			array( __( 'New customers', 'woo-pulse' ), number_format_i18n( $c['new_customers'] ), self::delta( $c['new_customers'], $p['new_customers'] ) ),
			array( __( 'Refunds', 'woo-pulse' ), self::money( $c['refunds'] ), self::delta( $c['refunds'], $p['refunds'] ) ),
			array( __( 'Ad spend', 'woo-pulse' ), self::money( $cm['ad_spend'] ), self::delta( $cm['ad_spend'], $pm['ad_spend'] ) ),
			array( __( 'Blended ROAS', 'woo-pulse' ), self::ratio( $cm['blended_roas'] ), self::delta( $cm['blended_roas'], $pm['blended_roas'] ) ),
			array(
				__( 'MER', 'woo-pulse' ),
				'percent' === $s['mer_display'] ? ( null === $cm['mer_percent'] ? 'n/a' : number_format_i18n( $cm['mer_percent'], 1 ) . '%' ) : self::ratio( $cm['mer_ratio'] ),
				self::delta( $cm['mer_ratio'], $pm['mer_ratio'] ),
			),
			array( __( 'CAC', 'woo-pulse' ), self::money( $cm['cac'] ), self::delta( $cm['cac'], $pm['cac'] ) ),
		);

		$td  = 'padding:8px 10px;border-bottom:1px solid #e5e7eb;font-size:14px;';
		$th  = 'padding:8px 10px;border-bottom:2px solid #d1d5db;font-size:12px;text-transform:uppercase;letter-spacing:.04em;color:#6b7280;text-align:left;';
		$h   = '<div style="font-family:-apple-system,Segoe UI,Roboto,Helvetica,Arial,sans-serif;max-width:640px;margin:0 auto;color:#111827;">';
		$h  .= '<h2 style="margin:0 0 4px;">' . esc_html( get_bloginfo( 'name' ) ) . ' ' . esc_html__( 'weekly snapshot', 'woo-pulse' ) . '</h2>';
		$h  .= '<p style="margin:0 0 16px;color:#6b7280;">' . esc_html( $r['range']['start'] . ' to ' . $r['range']['end'] ) . ' ' . esc_html__( 'vs the 7 days before', 'woo-pulse' ) . '</p>';
		$h  .= '<table style="border-collapse:collapse;width:100%;">';
		$h  .= '<tr><th style="' . $th . '">' . esc_html__( 'Metric', 'woo-pulse' ) . '</th><th style="' . $th . 'text-align:right;">' . esc_html__( 'This week', 'woo-pulse' ) . '</th><th style="' . $th . 'text-align:right;">' . esc_html__( 'Change', 'woo-pulse' ) . '</th></tr>';
		foreach ( $rows as $row ) {
			$h .= '<tr><td style="' . $td . '">' . esc_html( $row[0] ) . '</td><td style="' . $td . 'text-align:right;font-weight:600;">' . esc_html( $row[1] ) . '</td><td style="' . $td . 'text-align:right;">' . $row[2] . '</td></tr>';
		}
		$h .= '</table>';

		$states = array_slice( $r['current']['geo']['regions'], 0, 5 );
		if ( $states ) {
			$h .= '<h3 style="margin:24px 0 8px;font-size:15px;">' . esc_html__( 'Top states / regions', 'woo-pulse' ) . '</h3><table style="border-collapse:collapse;width:100%;">';
			foreach ( $states as $g ) {
				$label = ( '??' === $g['state'] ? __( 'Unknown', 'woo-pulse' ) : $g['state'] ) . ' (' . $g['country'] . ')';
				$h    .= '<tr><td style="' . $td . '">' . esc_html( $label ) . '</td><td style="' . $td . 'text-align:right;">' . esc_html( self::money( $g['revenue'] ) ) . '</td><td style="' . $td . 'text-align:right;color:#6b7280;">' . esc_html( number_format_i18n( $g['orders'] ) . ' ' . __( 'orders', 'woo-pulse' ) ) . '</td></tr>';
			}
			$h .= '</table>';
		}

		$products = array_slice( $r['current']['products'], 0, 5 );
		if ( $products ) {
			$h .= '<h3 style="margin:24px 0 8px;font-size:15px;">' . esc_html__( 'Top products', 'woo-pulse' ) . '</h3><table style="border-collapse:collapse;width:100%;">';
			foreach ( $products as $pr ) {
				$h .= '<tr><td style="' . $td . '">' . esc_html( $pr['name'] ) . '</td><td style="' . $td . 'text-align:right;">' . esc_html( self::money( $pr['revenue'] ) ) . '</td><td style="' . $td . 'text-align:right;color:#6b7280;">' . esc_html( number_format_i18n( $pr['qty'] ) . ' ' . __( 'sold', 'woo-pulse' ) ) . '</td></tr>';
			}
			$h .= '</table>';
		}

		$url = admin_url( 'admin.php?page=woo-pulse' );
		$h  .= '<p style="margin:24px 0 0;"><a href="' . esc_url( $url ) . '" style="display:inline-block;background:#2563eb;color:#fff;text-decoration:none;padding:10px 16px;border-radius:6px;font-weight:600;">' . esc_html__( 'Open the dashboard', 'woo-pulse' ) . '</a></p>';
		$h  .= '<p style="margin:16px 0 0;color:#9ca3af;font-size:12px;">' . esc_html__( 'Sent by Woo Pulse. Change recipients or turn this off under Pulse > Settings.', 'woo-pulse' ) . '</p>';
		$h  .= '</div>';
		return $h;
	}
}
