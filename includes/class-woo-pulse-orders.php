<?php
/**
 * Order data source. Reads WooCommerce orders from either High-Performance Order Storage (custom tables)
 * or the legacy posts/postmeta storage and returns normalized rows for the metrics layer.
 *
 * Every public method returns plain PHP arrays. All SQL goes through $wpdb->prepare().
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Woo_Pulse_Orders {

	/** @var wpdb */
	private $db;

	/** @var bool */
	private $hpos;

	/** @var string[] counted statuses with the wc- prefix (posts) */
	private $statuses_prefixed;

	/** @var string[] counted statuses without the prefix (HPOS) */
	private $statuses_bare;

	/** @var string shipping|billing */
	private $location;

	// Attribution meta keys written by WooCommerce core (8.5+) on every order.
	const META_SOURCE_TYPE  = '_wc_order_attribution_source_type';
	const META_UTM_SOURCE   = '_wc_order_attribution_utm_source';
	const META_UTM_MEDIUM   = '_wc_order_attribution_utm_medium';
	const META_UTM_CAMPAIGN = '_wc_order_attribution_utm_campaign';
	const META_DEVICE       = '_wc_order_attribution_device_type';
	const META_COGS         = '_cogs_total_value';

	public function __construct() {
		global $wpdb;
		$this->db                = $wpdb;
		$this->hpos              = self::detect_hpos();
		$this->statuses_prefixed = Woo_Pulse_Settings::counted_statuses( true );
		// HPOS stores statuses with the wc- prefix; match both spellings so a store migrated either way still works.
		$this->statuses_bare = array_values( array_unique( array_merge( $this->statuses_prefixed, Woo_Pulse_Settings::counted_statuses( false ) ) ) );
		$settings                = Woo_Pulse_Settings::get();
		$this->location          = $settings['location_source'];
	}

	/** @var string[] database errors raised by this instance's queries, surfaced in the report payload. */
	private $errors = array();

	public function errors() {
		return $this->errors;
	}

	/** Record a database error (if any) after a query, tagged with the calling method. */
	private function note_error( $method ) {
		if ( $this->db->last_error ) {
			$this->errors[] = $method . ': ' . $this->db->last_error;
		}
	}

	public static function detect_hpos() {
		if ( class_exists( '\Automattic\WooCommerce\Utilities\OrderUtil' ) ) {
			return (bool) \Automattic\WooCommerce\Utilities\OrderUtil::custom_orders_table_usage_is_enabled();
		}
		return false;
	}

	public function is_hpos() {
		return $this->hpos;
	}

	public function storage_label() {
		return $this->hpos ? 'hpos' : 'posts';
	}

	/* ------------------------------------------------------------------ */
	/* Helpers                                                              */
	/* ------------------------------------------------------------------ */

	/** Customer identity: email first (covers guest and account orders), then user id, then the order itself. */
	public static function customer_key( $email, $customer_id, $order_id ) {
		$email = strtolower( trim( (string) $email ) );
		if ( '' !== $email ) {
			return 'e:' . $email;
		}
		if ( (int) $customer_id > 0 ) {
			return 'u:' . (int) $customer_id;
		}
		return 'o:' . (int) $order_id;
	}

	/** Placeholders and values for an IN (...) list. */
	private function in_list( array $values, $type = '%s' ) {
		$values = array_values( $values );
		if ( empty( $values ) ) {
			// Never matches, keeps the SQL valid.
			return array( "''", array() );
		}
		return array( implode( ',', array_fill( 0, count( $values ), $type ) ), $values );
	}

	private function t( $name ) {
		return $this->db->prefix . $name;
	}

	/**
	 * SQL fragment (with params) selecting the ids of counted orders in a GMT range.
	 * Used as a subquery by item-level reports so both storage modes share one query.
	 */
	private function order_ids_sql( $gmt_start, $gmt_end ) {
		if ( $this->hpos ) {
			list( $ph, $vals ) = $this->in_list( $this->statuses_bare );
			$sql               = "SELECT id FROM {$this->t('wc_orders')} WHERE type = 'shop_order' AND status IN ($ph) AND date_created_gmt >= %s AND date_created_gmt < %s";
		} else {
			list( $ph, $vals ) = $this->in_list( $this->statuses_prefixed );
			$sql               = "SELECT ID FROM {$this->db->posts} WHERE post_type = 'shop_order' AND post_status IN ($ph) AND post_date_gmt >= %s AND post_date_gmt < %s";
		}
		$vals[] = $gmt_start;
		$vals[] = $gmt_end;
		return array( $sql, $vals );
	}

	/** Same as order_ids_sql but for refund records whose parent order is counted. */
	private function refund_ids_sql( $gmt_start, $gmt_end ) {
		if ( $this->hpos ) {
			list( $ph, $vals ) = $this->in_list( $this->statuses_bare );
			$sql               = "SELECT r.id FROM {$this->t('wc_orders')} r JOIN {$this->t('wc_orders')} o ON o.id = r.parent_order_id AND o.type = 'shop_order' AND o.status IN ($ph) WHERE r.type = 'shop_order_refund' AND r.date_created_gmt >= %s AND r.date_created_gmt < %s";
		} else {
			list( $ph, $vals ) = $this->in_list( $this->statuses_prefixed );
			$sql               = "SELECT r.ID FROM {$this->db->posts} r JOIN {$this->db->posts} o ON o.ID = r.post_parent AND o.post_type = 'shop_order' AND o.post_status IN ($ph) WHERE r.post_type = 'shop_order_refund' AND r.post_date_gmt >= %s AND r.post_date_gmt < %s";
		}
		$vals[] = $gmt_start;
		$vals[] = $gmt_end;
		return array( $sql, $vals );
	}

	/** Pick state/country/city according to the location setting. */
	private function pick_location( $ship_state, $ship_country, $ship_city, $bill_state, $bill_country, $bill_city ) {
		if ( 'billing' === $this->location ) {
			return array( (string) $bill_state, (string) $bill_country, (string) $bill_city );
		}
		if ( '' !== (string) $ship_country || '' !== (string) $ship_state ) {
			return array( (string) $ship_state, (string) $ship_country, (string) $ship_city );
		}
		return array( (string) $bill_state, (string) $bill_country, (string) $bill_city );
	}

	/* ------------------------------------------------------------------ */
	/* Orders in range                                                      */
	/* ------------------------------------------------------------------ */

	/**
	 * Counted orders created in [gmt_start, gmt_end), oldest first.
	 *
	 * @return array[] rows: id, date_created_gmt, status, total, tax, shipping, discount, cogs, customer_id, email,
	 *                 first_name, last_name, payment_method, state, country, city, source_type, utm_source,
	 *                 utm_medium, utm_campaign, device, key
	 */
	public function orders( $gmt_start, $gmt_end ) {
		return $this->hpos ? $this->orders_hpos( $gmt_start, $gmt_end ) : $this->orders_posts( $gmt_start, $gmt_end );
	}

	private function orders_hpos( $gmt_start, $gmt_end ) {
		list( $ph, $vals ) = $this->in_list( $this->statuses_bare );
		$meta              = $this->t( 'wc_orders_meta' );
		$meta_cols         = array();
		$meta_map          = array(
			self::META_SOURCE_TYPE  => 'source_type',
			self::META_UTM_SOURCE   => 'utm_source',
			self::META_UTM_MEDIUM   => 'utm_medium',
			self::META_UTM_CAMPAIGN => 'utm_campaign',
			self::META_DEVICE       => 'device',
			self::META_COGS         => 'cogs',
		);
		foreach ( $meta_map as $key => $alias ) {
			$meta_cols[] = "(SELECT m.meta_value FROM {$meta} m WHERE m.order_id = o.id AND m.meta_key = '{$key}' LIMIT 1) AS {$alias}";
		}
		$meta_select = implode( ",\n\t\t\t\t", $meta_cols );

		$sql = "SELECT o.id, o.date_created_gmt, o.status, o.total_amount AS total, o.tax_amount AS tax,
				od.shipping_total_amount AS shipping, od.discount_total_amount AS discount,
				o.customer_id, o.billing_email AS email, o.payment_method_title AS payment_method, o.payment_method AS payment_method_id,
				sa.state AS ship_state, sa.country AS ship_country, sa.city AS ship_city,
				ba.state AS bill_state, ba.country AS bill_country, ba.city AS bill_city,
				ba.first_name, ba.last_name,
				{$meta_select}
			FROM {$this->t('wc_orders')} o
			LEFT JOIN {$this->t('wc_order_operational_data')} od ON od.order_id = o.id
			LEFT JOIN {$this->t('wc_order_addresses')} sa ON sa.order_id = o.id AND sa.address_type = 'shipping'
			LEFT JOIN {$this->t('wc_order_addresses')} ba ON ba.order_id = o.id AND ba.address_type = 'billing'
			WHERE o.type = 'shop_order' AND o.status IN ($ph) AND o.date_created_gmt >= %s AND o.date_created_gmt < %s
			ORDER BY o.date_created_gmt ASC, o.id ASC";
		$vals[] = $gmt_start;
		$vals[] = $gmt_end;

		$rows = $this->db->get_results( $this->db->prepare( $sql, $vals ), ARRAY_A );
		$this->note_error( 'orders_hpos' );
		return $this->normalize_orders( $rows ? $rows : array(), 'wc-' );
	}

	private function orders_posts( $gmt_start, $gmt_end ) {
		list( $ph, $vals ) = $this->in_list( $this->statuses_prefixed );
		$keys              = array(
			'_order_total'         => 'total',
			'_order_tax'           => 'order_tax',
			'_order_shipping_tax'  => 'shipping_tax',
			'_order_shipping'      => 'shipping',
			'_cart_discount'       => 'discount',
			'_customer_user'       => 'customer_id',
			'_billing_email'       => 'email',
			'_billing_first_name'  => 'first_name',
			'_billing_last_name'   => 'last_name',
			'_payment_method_title' => 'payment_method',
			'_payment_method'       => 'payment_method_id',
			'_shipping_state'      => 'ship_state',
			'_shipping_country'    => 'ship_country',
			'_shipping_city'       => 'ship_city',
			'_billing_state'       => 'bill_state',
			'_billing_country'     => 'bill_country',
			'_billing_city'        => 'bill_city',
			self::META_SOURCE_TYPE  => 'source_type',
			self::META_UTM_SOURCE   => 'utm_source',
			self::META_UTM_MEDIUM   => 'utm_medium',
			self::META_UTM_CAMPAIGN => 'utm_campaign',
			self::META_DEVICE       => 'device',
			self::META_COGS         => 'cogs',
		);
		$pivot = array();
		foreach ( $keys as $meta_key => $alias ) {
			$pivot[] = "MAX(CASE WHEN pm.meta_key = '{$meta_key}' THEN pm.meta_value END) AS {$alias}";
		}
		list( $kph, $kvals ) = $this->in_list( array_keys( $keys ) );

		$sql = "SELECT p.ID AS id, p.post_date_gmt AS date_created_gmt, p.post_status AS status, " . implode( ', ', $pivot ) . "
			FROM {$this->db->posts} p
			LEFT JOIN {$this->db->postmeta} pm ON pm.post_id = p.ID AND pm.meta_key IN ($kph)
			WHERE p.post_type = 'shop_order' AND p.post_status IN ($ph) AND p.post_date_gmt >= %s AND p.post_date_gmt < %s
			GROUP BY p.ID
			ORDER BY p.post_date_gmt ASC, p.ID ASC";
		$params = array_merge( $kvals, $vals, array( $gmt_start, $gmt_end ) );

		$rows = $this->db->get_results( $this->db->prepare( $sql, $params ), ARRAY_A );
		$this->note_error( 'orders_posts' );
		$rows = $rows ? $rows : array();
		foreach ( $rows as &$r ) {
			$r['tax'] = (float) $r['order_tax'] + (float) $r['shipping_tax'];
			unset( $r['order_tax'], $r['shipping_tax'] );
		}
		unset( $r );
		return $this->normalize_orders( $rows, '' );
	}

	private function normalize_orders( array $rows, $status_prefix ) {
		$out = array();
		foreach ( $rows as $r ) {
			list( $state, $country, $city ) = $this->pick_location(
				isset( $r['ship_state'] ) ? $r['ship_state'] : '',
				isset( $r['ship_country'] ) ? $r['ship_country'] : '',
				isset( $r['ship_city'] ) ? $r['ship_city'] : '',
				isset( $r['bill_state'] ) ? $r['bill_state'] : '',
				isset( $r['bill_country'] ) ? $r['bill_country'] : '',
				isset( $r['bill_city'] ) ? $r['bill_city'] : ''
			);
			$email  = strtolower( trim( (string) $r['email'] ) );
			$status = (string) $r['status'];
			if ( $status_prefix && 0 !== strpos( $status, 'wc-' ) ) {
				$status = $status_prefix . $status;
			}
			$out[] = array(
				'id'               => (int) $r['id'],
				'date_created_gmt' => (string) $r['date_created_gmt'],
				'status'           => $status,
				'total'            => (float) $r['total'],
				'tax'              => (float) $r['tax'],
				'shipping'         => (float) $r['shipping'],
				'discount'         => (float) $r['discount'],
				'cogs'             => ( isset( $r['cogs'] ) && '' !== $r['cogs'] && null !== $r['cogs'] ) ? (float) $r['cogs'] : null,
				'customer_id'      => (int) $r['customer_id'],
				'email'            => $email,
				'first_name'       => (string) $r['first_name'],
				'last_name'        => (string) $r['last_name'],
				'payment_method'   => (string) $r['payment_method'],
				'payment_method_id' => isset( $r['payment_method_id'] ) ? (string) $r['payment_method_id'] : '',
				'state'            => strtoupper( trim( $state ) ),
				'country'          => strtoupper( trim( $country ) ),
				'city'             => trim( $city ),
				'source_type'      => (string) $r['source_type'],
				'utm_source'       => (string) $r['utm_source'],
				'utm_medium'       => (string) $r['utm_medium'],
				'utm_campaign'     => (string) $r['utm_campaign'],
				'device'           => (string) $r['device'],
				'key'              => self::customer_key( $email, $r['customer_id'], $r['id'] ),
			);
		}
		return $out;
	}

	/**
	 * Refunds created in [gmt_start, gmt_end) whose parent order is counted.
	 *
	 * @return array[] rows: id, parent_id, date_created_gmt, amount, state, country
	 */
	public function refunds( $gmt_start, $gmt_end ) {
		if ( $this->hpos ) {
			list( $ph, $vals ) = $this->in_list( $this->statuses_bare );
			$sql               = "SELECT r.id, r.parent_order_id AS parent_id, r.date_created_gmt, ABS(r.total_amount) AS amount,
					sa.state AS ship_state, sa.country AS ship_country, ba.state AS bill_state, ba.country AS bill_country
				FROM {$this->t('wc_orders')} r
				JOIN {$this->t('wc_orders')} o ON o.id = r.parent_order_id AND o.type = 'shop_order' AND o.status IN ($ph)
				LEFT JOIN {$this->t('wc_order_addresses')} sa ON sa.order_id = o.id AND sa.address_type = 'shipping'
				LEFT JOIN {$this->t('wc_order_addresses')} ba ON ba.order_id = o.id AND ba.address_type = 'billing'
				WHERE r.type = 'shop_order_refund' AND r.date_created_gmt >= %s AND r.date_created_gmt < %s";
			$vals[] = $gmt_start;
			$vals[] = $gmt_end;
			$rows   = $this->db->get_results( $this->db->prepare( $sql, $vals ), ARRAY_A );
			$this->note_error( 'refunds' );
		} else {
			list( $ph, $vals ) = $this->in_list( $this->statuses_prefixed );
			$sql               = "SELECT r.ID AS id, r.post_parent AS parent_id, r.post_date_gmt AS date_created_gmt,
					MAX(pm.meta_value) AS amount,
					MAX(CASE WHEN po.meta_key = '_shipping_state' THEN po.meta_value END) AS ship_state,
					MAX(CASE WHEN po.meta_key = '_shipping_country' THEN po.meta_value END) AS ship_country,
					MAX(CASE WHEN po.meta_key = '_billing_state' THEN po.meta_value END) AS bill_state,
					MAX(CASE WHEN po.meta_key = '_billing_country' THEN po.meta_value END) AS bill_country
				FROM {$this->db->posts} r
				JOIN {$this->db->posts} o ON o.ID = r.post_parent AND o.post_type = 'shop_order' AND o.post_status IN ($ph)
				LEFT JOIN {$this->db->postmeta} pm ON pm.post_id = r.ID AND pm.meta_key = '_refund_amount'
				LEFT JOIN {$this->db->postmeta} po ON po.post_id = o.ID AND po.meta_key IN ('_shipping_state','_shipping_country','_billing_state','_billing_country')
				WHERE r.post_type = 'shop_order_refund' AND r.post_date_gmt >= %s AND r.post_date_gmt < %s
				GROUP BY r.ID";
			$vals[] = $gmt_start;
			$vals[] = $gmt_end;
			$rows   = $this->db->get_results( $this->db->prepare( $sql, $vals ), ARRAY_A );
			$this->note_error( 'refunds' );
		}

		$out = array();
		foreach ( $rows ? $rows : array() as $r ) {
			list( $state, $country ) = $this->pick_location( $r['ship_state'], $r['ship_country'], '', $r['bill_state'], $r['bill_country'], '' );
			$out[]                   = array(
				'id'               => (int) $r['id'],
				'parent_id'        => (int) $r['parent_id'],
				'date_created_gmt' => (string) $r['date_created_gmt'],
				'amount'           => abs( (float) $r['amount'] ),
				'state'            => strtoupper( trim( (string) $state ) ),
				'country'          => strtoupper( trim( (string) $country ) ),
			);
		}
		return $out;
	}

	/**
	 * Orders created in range grouped by status, regardless of whether the status is counted.
	 *
	 * @return array status (wc- prefixed) => count
	 */
	public function status_counts( $gmt_start, $gmt_end ) {
		if ( $this->hpos ) {
			$sql = "SELECT status, COUNT(*) AS n FROM {$this->t('wc_orders')} WHERE type = 'shop_order' AND date_created_gmt >= %s AND date_created_gmt < %s GROUP BY status";
		} else {
			$sql = "SELECT post_status AS status, COUNT(*) AS n FROM {$this->db->posts} WHERE post_type = 'shop_order' AND post_date_gmt >= %s AND post_date_gmt < %s GROUP BY post_status";
		}
		$rows = $this->db->get_results( $this->db->prepare( $sql, $gmt_start, $gmt_end ), ARRAY_A );
		$this->note_error( 'status_counts' );
		$out  = array();
		foreach ( $rows ? $rows : array() as $r ) {
			$status = (string) $r['status'];
			if ( 0 !== strpos( $status, 'wc-' ) ) {
				$status = 'wc-' . $status;
			}
			$out[ $status ] = (int) $r['n'];
		}
		return $out;
	}

	/** Total line-item quantity across counted orders in range. */
	public function items_sold( $gmt_start, $gmt_end ) {
		list( $ids_sql, $vals ) = $this->order_ids_sql( $gmt_start, $gmt_end );
		$sql                    = "SELECT COALESCE(SUM(im.meta_value), 0)
			FROM {$this->t('woocommerce_order_items')} oi
			JOIN {$this->t('woocommerce_order_itemmeta')} im ON im.order_item_id = oi.order_item_id AND im.meta_key = '_qty'
			WHERE oi.order_item_type = 'line_item' AND oi.order_id IN ($ids_sql)";
		$total = (float) $this->db->get_var( $this->db->prepare( $sql, $vals ) );
		$this->note_error( 'items_sold' );
		return $total;
	}

	/** Inner per-line-item pivot shared by product, category and brand reports. */
	private function line_items_sql( $gmt_start, $gmt_end ) {
		list( $ids_sql, $vals ) = $this->order_ids_sql( $gmt_start, $gmt_end );
		$sql                    = "SELECT oi.order_item_id, oi.order_id, oi.order_item_name AS name,
				MAX(CASE WHEN im.meta_key = '_product_id' THEN im.meta_value END) AS product_id,
				MAX(CASE WHEN im.meta_key = '_variation_id' THEN im.meta_value END) AS variation_id,
				MAX(CASE WHEN im.meta_key = '_qty' THEN im.meta_value END) AS qty,
				MAX(CASE WHEN im.meta_key = '_line_total' THEN im.meta_value END) AS revenue
			FROM {$this->t('woocommerce_order_items')} oi
			JOIN {$this->t('woocommerce_order_itemmeta')} im ON im.order_item_id = oi.order_item_id AND im.meta_key IN ('_product_id','_variation_id','_qty','_line_total')
			WHERE oi.order_item_type = 'line_item' AND oi.order_id IN ($ids_sql)
			GROUP BY oi.order_item_id, oi.order_id, oi.order_item_name";
		return array( $sql, $vals );
	}

	/**
	 * Top products by line revenue (excluding tax, after line discounts), variation-aware.
	 *
	 * @return array[] rows: product_id, variation_id, name, qty, revenue, orders, refunded
	 */
	public function products( $gmt_start, $gmt_end, $limit = 50 ) {
		list( $inner, $vals ) = $this->line_items_sql( $gmt_start, $gmt_end );
		$sql                  = "SELECT t.product_id, t.variation_id, MAX(t.name) AS name, SUM(t.qty) AS qty, SUM(t.revenue) AS revenue, COUNT(DISTINCT t.order_id) AS orders
			FROM ($inner) t
			GROUP BY t.product_id, t.variation_id
			ORDER BY revenue DESC
			LIMIT %d";
		$vals[]               = (int) $limit;
		$rows                 = $this->db->get_results( $this->db->prepare( $sql, $vals ), ARRAY_A );
		$this->note_error( 'products' );

		// Refunded amounts per product in the same window (refund line items carry negative totals).
		list( $rids_sql, $rvals ) = $this->refund_ids_sql( $gmt_start, $gmt_end );
		$rsql                     = "SELECT MAX(CASE WHEN im.meta_key = '_product_id' THEN im.meta_value END) AS product_id,
				MAX(CASE WHEN im.meta_key = '_variation_id' THEN im.meta_value END) AS variation_id,
				SUM(CASE WHEN im.meta_key = '_line_total' THEN -im.meta_value ELSE 0 END) AS refunded
			FROM {$this->t('woocommerce_order_items')} oi
			JOIN {$this->t('woocommerce_order_itemmeta')} im ON im.order_item_id = oi.order_item_id AND im.meta_key IN ('_product_id','_variation_id','_line_total')
			WHERE oi.order_item_type = 'line_item' AND oi.order_id IN ($rids_sql)
			GROUP BY oi.order_item_id";
		$refund_rows              = $this->db->get_results( $this->db->prepare( $rsql, $rvals ), ARRAY_A );
		$this->note_error( 'products' );
		$refunded                 = array();
		foreach ( $refund_rows ? $refund_rows : array() as $rr ) {
			$k              = (int) $rr['product_id'] . ':' . (int) $rr['variation_id'];
			$refunded[ $k ] = ( isset( $refunded[ $k ] ) ? $refunded[ $k ] : 0 ) + (float) $rr['refunded'];
		}

		$out = array();
		foreach ( $rows ? $rows : array() as $r ) {
			$k     = (int) $r['product_id'] . ':' . (int) $r['variation_id'];
			$out[] = array(
				'product_id'   => (int) $r['product_id'],
				'variation_id' => (int) $r['variation_id'],
				'name'         => (string) $r['name'],
				'qty'          => (float) $r['qty'],
				'revenue'      => (float) $r['revenue'],
				'orders'       => (int) $r['orders'],
				'refunded'     => isset( $refunded[ $k ] ) ? $refunded[ $k ] : 0.0,
			);
		}
		return $out;
	}

	/**
	 * Revenue by term for a product taxonomy (product_cat, product_brand). A product in several terms counts in each.
	 *
	 * @return array[] rows: term_id, name, qty, revenue, orders
	 */
	public function terms_report( $gmt_start, $gmt_end, $taxonomy, $limit = 30 ) {
		if ( ! taxonomy_exists( $taxonomy ) ) {
			return array();
		}
		list( $inner, $vals ) = $this->line_items_sql( $gmt_start, $gmt_end );
		$sql                  = "SELECT tt.term_id, tm.name, SUM(t.qty) AS qty, SUM(t.revenue) AS revenue, COUNT(DISTINCT t.order_id) AS orders
			FROM ($inner) t
			JOIN {$this->db->term_relationships} tr ON tr.object_id = t.product_id
			JOIN {$this->db->term_taxonomy} tt ON tt.term_taxonomy_id = tr.term_taxonomy_id AND tt.taxonomy = %s
			JOIN {$this->db->terms} tm ON tm.term_id = tt.term_id
			GROUP BY tt.term_id, tm.name
			ORDER BY revenue DESC
			LIMIT %d";
		$vals[]               = $taxonomy;
		$vals[]               = (int) $limit;
		$rows                 = $this->db->get_results( $this->db->prepare( $sql, $vals ), ARRAY_A );
		$this->note_error( 'terms_report' );
		$out                  = array();
		foreach ( $rows ? $rows : array() as $r ) {
			$out[] = array(
				'term_id' => (int) $r['term_id'],
				'name'    => (string) $r['name'],
				'qty'     => (float) $r['qty'],
				'revenue' => (float) $r['revenue'],
				'orders'  => (int) $r['orders'],
			);
		}
		return $out;
	}

	/** Join fragment and expression giving the order total for an order_items row, per storage mode. */
	private function order_total_join() {
		if ( $this->hpos ) {
			return array( "JOIN {$this->t('wc_orders')} ot ON ot.id = oi.order_id", 'ot.total_amount' );
		}
		return array( "LEFT JOIN {$this->db->postmeta} ot ON ot.post_id = oi.order_id AND ot.meta_key = '_order_total'", 'ot.meta_value' );
	}

	/**
	 * Coupon usage in range.
	 *
	 * @return array[] rows: code, orders, discount, revenue
	 */
	public function coupons( $gmt_start, $gmt_end, $limit = 30 ) {
		list( $ids_sql, $vals ) = $this->order_ids_sql( $gmt_start, $gmt_end );
		list( $join, $total )   = $this->order_total_join();
		$sql                    = "SELECT oi.order_item_name AS code, COUNT(DISTINCT oi.order_id) AS orders,
				COALESCE(SUM(im.meta_value), 0) AS discount, COALESCE(SUM($total), 0) AS revenue
			FROM {$this->t('woocommerce_order_items')} oi
			LEFT JOIN {$this->t('woocommerce_order_itemmeta')} im ON im.order_item_id = oi.order_item_id AND im.meta_key = 'discount_amount'
			$join
			WHERE oi.order_item_type = 'coupon' AND oi.order_id IN ($ids_sql)
			GROUP BY oi.order_item_name
			ORDER BY discount DESC
			LIMIT %d";
		$vals[]                 = (int) $limit;
		$rows                   = $this->db->get_results( $this->db->prepare( $sql, $vals ), ARRAY_A );
		$this->note_error( 'coupons' );
		$out                    = array();
		foreach ( $rows ? $rows : array() as $r ) {
			$out[] = array(
				'code'     => (string) $r['code'],
				'orders'   => (int) $r['orders'],
				'discount' => (float) $r['discount'],
				'revenue'  => (float) $r['revenue'],
			);
		}
		return $out;
	}

	/**
	 * Shipping methods used in range.
	 *
	 * @return array[] rows: name, orders, cost
	 */
	public function shipping_methods( $gmt_start, $gmt_end, $limit = 20 ) {
		list( $ids_sql, $vals ) = $this->order_ids_sql( $gmt_start, $gmt_end );
		$sql                    = "SELECT oi.order_item_name AS name, COUNT(DISTINCT oi.order_id) AS orders, COALESCE(SUM(im.meta_value), 0) AS cost
			FROM {$this->t('woocommerce_order_items')} oi
			LEFT JOIN {$this->t('woocommerce_order_itemmeta')} im ON im.order_item_id = oi.order_item_id AND im.meta_key = 'cost'
			WHERE oi.order_item_type = 'shipping' AND oi.order_id IN ($ids_sql)
			GROUP BY oi.order_item_name
			ORDER BY orders DESC
			LIMIT %d";
		$vals[]                 = (int) $limit;
		$rows                   = $this->db->get_results( $this->db->prepare( $sql, $vals ), ARRAY_A );
		$this->note_error( 'shipping_methods' );
		$out                    = array();
		foreach ( $rows ? $rows : array() as $r ) {
			$out[] = array(
				'name'   => (string) $r['name'],
				'orders' => (int) $r['orders'],
				'cost'   => (float) $r['cost'],
			);
		}
		return $out;
	}

	/* ------------------------------------------------------------------ */
	/* Customer history                                                     */
	/* ------------------------------------------------------------------ */

	/**
	 * Which of the given customer keys had a counted order before $gmt_before.
	 *
	 * @param string[] $keys customer keys as produced by customer_key().
	 * @return array key => true
	 */
	public function prior_customers( array $keys, $gmt_before ) {
		$emails = array();
		$users  = array();
		foreach ( array_unique( $keys ) as $k ) {
			if ( 0 === strpos( $k, 'e:' ) ) {
				$emails[] = substr( $k, 2 );
			} elseif ( 0 === strpos( $k, 'u:' ) ) {
				$users[] = (int) substr( $k, 2 );
			}
		}
		$found = array();
		foreach ( array_chunk( $emails, 500 ) as $chunk ) {
			foreach ( $this->prior_by_email( $chunk, $gmt_before ) as $e ) {
				$found[ 'e:' . $e ] = true;
			}
		}
		foreach ( array_chunk( $users, 500 ) as $chunk ) {
			foreach ( $this->prior_by_user( $chunk, $gmt_before ) as $u ) {
				$found[ 'u:' . $u ] = true;
			}
		}
		return $found;
	}

	private function prior_by_email( array $emails, $gmt_before ) {
		list( $eph, $evals ) = $this->in_list( $emails );
		if ( $this->hpos ) {
			list( $ph, $vals ) = $this->in_list( $this->statuses_bare );
			$sql               = "SELECT DISTINCT LOWER(billing_email) FROM {$this->t('wc_orders')} WHERE type = 'shop_order' AND status IN ($ph) AND date_created_gmt < %s AND LOWER(billing_email) IN ($eph)";
		} else {
			list( $ph, $vals ) = $this->in_list( $this->statuses_prefixed );
			$sql               = "SELECT DISTINCT LOWER(pm.meta_value) FROM {$this->db->posts} p JOIN {$this->db->postmeta} pm ON pm.post_id = p.ID AND pm.meta_key = '_billing_email'
				WHERE p.post_type = 'shop_order' AND p.post_status IN ($ph) AND p.post_date_gmt < %s AND LOWER(pm.meta_value) IN ($eph)";
		}
		$params = array_merge( $vals, array( $gmt_before ), $evals );
		$res    = $this->db->get_col( $this->db->prepare( $sql, $params ) );
		$this->note_error( 'prior_by_email' );
		return $res ? $res : array();
	}

	private function prior_by_user( array $users, $gmt_before ) {
		list( $uph, $uvals ) = $this->in_list( $users, '%d' );
		if ( $this->hpos ) {
			list( $ph, $vals ) = $this->in_list( $this->statuses_bare );
			$sql               = "SELECT DISTINCT customer_id FROM {$this->t('wc_orders')} WHERE type = 'shop_order' AND status IN ($ph) AND date_created_gmt < %s AND customer_id IN ($uph)";
		} else {
			list( $ph, $vals ) = $this->in_list( $this->statuses_prefixed );
			$sql               = "SELECT DISTINCT pm.meta_value FROM {$this->db->posts} p JOIN {$this->db->postmeta} pm ON pm.post_id = p.ID AND pm.meta_key = '_customer_user'
				WHERE p.post_type = 'shop_order' AND p.post_status IN ($ph) AND p.post_date_gmt < %s AND pm.meta_value IN ($uph)";
		}
		$params = array_merge( $vals, array( $gmt_before ), $uvals );
		$res    = $this->db->get_col( $this->db->prepare( $sql, $params ) );
		$this->note_error( 'prior_by_user' );
		return array_map( 'intval', $res ? $res : array() );
	}

	/**
	 * Per-customer lifetime derived table: one row per customer key over all counted orders.
	 * Columns: k, orders, rev (revenue basis after refunds), first_date, last_date, customer_id, email, first_name, last_name, state, country
	 */
	private function lifetime_sql( $basis ) {
		if ( $this->hpos ) {
			list( $ph, $vals ) = $this->in_list( $this->statuses_bare );
			$rev_expr          = 'net' === $basis ? '(o.total_amount - o.tax_amount - COALESCE(od.shipping_total_amount, 0))' : 'o.total_amount';
			$sql               = "SELECT
					CASE WHEN o.billing_email IS NOT NULL AND o.billing_email <> '' THEN CONCAT('e:', LOWER(o.billing_email))
						 WHEN o.customer_id > 0 THEN CONCAT('u:', o.customer_id)
						 ELSE CONCAT('o:', o.id) END AS k,
					COUNT(*) AS orders,
					SUM($rev_expr) - COALESCE(SUM(rf.r), 0) AS rev,
					MIN(o.date_created_gmt) AS first_date,
					MAX(o.date_created_gmt) AS last_date,
					MAX(o.customer_id) AS customer_id,
					MAX(o.billing_email) AS email,
					MAX(ba.first_name) AS first_name,
					MAX(ba.last_name) AS last_name,
					MAX(ba.state) AS state,
					MAX(ba.country) AS country
				FROM {$this->t('wc_orders')} o
				LEFT JOIN {$this->t('wc_order_operational_data')} od ON od.order_id = o.id
				LEFT JOIN {$this->t('wc_order_addresses')} ba ON ba.order_id = o.id AND ba.address_type = 'billing'
				LEFT JOIN (SELECT parent_order_id, SUM(ABS(total_amount)) AS r FROM {$this->t('wc_orders')} WHERE type = 'shop_order_refund' GROUP BY parent_order_id) rf ON rf.parent_order_id = o.id
				WHERE o.type = 'shop_order' AND o.status IN ($ph)
				GROUP BY k";
			return array( $sql, $vals );
		}

		list( $ph, $vals ) = $this->in_list( $this->statuses_prefixed );
		$rev_expr          = 'net' === $basis ? '(x.total - x.tax - x.shipping_tax - x.shipping)' : 'x.total';
		$sql               = "SELECT
				CASE WHEN x.email <> '' THEN CONCAT('e:', x.email)
					 WHEN x.uid > 0 THEN CONCAT('u:', x.uid)
					 ELSE CONCAT('o:', x.id) END AS k,
				COUNT(*) AS orders,
				SUM($rev_expr) - COALESCE(SUM(rf.r), 0) AS rev,
				MIN(x.d) AS first_date,
				MAX(x.d) AS last_date,
				MAX(x.uid) AS customer_id,
				MAX(x.email) AS email,
				MAX(x.first_name) AS first_name,
				MAX(x.last_name) AS last_name,
				MAX(x.state) AS state,
				MAX(x.country) AS country
			FROM (
				SELECT p.ID AS id, p.post_date_gmt AS d,
					LOWER(COALESCE(MAX(CASE WHEN pm.meta_key = '_billing_email' THEN pm.meta_value END), '')) AS email,
					COALESCE(MAX(CASE WHEN pm.meta_key = '_customer_user' THEN pm.meta_value END), 0) + 0 AS uid,
					COALESCE(MAX(CASE WHEN pm.meta_key = '_order_total' THEN pm.meta_value END), 0) + 0 AS total,
					COALESCE(MAX(CASE WHEN pm.meta_key = '_order_tax' THEN pm.meta_value END), 0) + 0 AS tax,
					COALESCE(MAX(CASE WHEN pm.meta_key = '_order_shipping_tax' THEN pm.meta_value END), 0) + 0 AS shipping_tax,
					COALESCE(MAX(CASE WHEN pm.meta_key = '_order_shipping' THEN pm.meta_value END), 0) + 0 AS shipping,
					MAX(CASE WHEN pm.meta_key = '_billing_first_name' THEN pm.meta_value END) AS first_name,
					MAX(CASE WHEN pm.meta_key = '_billing_last_name' THEN pm.meta_value END) AS last_name,
					MAX(CASE WHEN pm.meta_key = '_billing_state' THEN pm.meta_value END) AS state,
					MAX(CASE WHEN pm.meta_key = '_billing_country' THEN pm.meta_value END) AS country
				FROM {$this->db->posts} p
				LEFT JOIN {$this->db->postmeta} pm ON pm.post_id = p.ID AND pm.meta_key IN ('_billing_email','_customer_user','_order_total','_order_tax','_order_shipping_tax','_order_shipping','_billing_first_name','_billing_last_name','_billing_state','_billing_country')
				WHERE p.post_type = 'shop_order' AND p.post_status IN ($ph)
				GROUP BY p.ID
			) x
			LEFT JOIN (
				SELECT r.post_parent AS parent_id, SUM(pm.meta_value) AS r
				FROM {$this->db->posts} r JOIN {$this->db->postmeta} pm ON pm.post_id = r.ID AND pm.meta_key = '_refund_amount'
				WHERE r.post_type = 'shop_order_refund' GROUP BY r.post_parent
			) rf ON rf.parent_id = x.id
			GROUP BY k";
		return array( $sql, $vals );
	}

	/**
	 * All-time customer statistics: counts, average/median lifetime value, how many reached a 2nd/3rd/4th order, active customers.
	 */
	public function lifetime_stats( $basis, $active_since_gmt ) {
		list( $inner, $vals ) = $this->lifetime_sql( $basis );
		$sql                  = "SELECT COUNT(*) AS customers, COALESCE(AVG(t.rev), 0) AS avg_ltv, COALESCE(AVG(t.orders), 0) AS avg_orders,
				COALESCE(SUM(t.orders >= 2), 0) AS reached_2, COALESCE(SUM(t.orders >= 3), 0) AS reached_3, COALESCE(SUM(t.orders >= 4), 0) AS reached_4,
				COALESCE(SUM(t.last_date >= %s), 0) AS active
			FROM ($inner) t";
		// The active-date placeholder comes before the derived table's placeholders, so it must be the first value.
		array_unshift( $vals, $active_since_gmt );
		$row                  = $this->db->get_row( $this->db->prepare( $sql, $vals ), ARRAY_A );
		$this->note_error( 'lifetime_stats' );
		$stats                = array(
			'customers'  => $row ? (int) $row['customers'] : 0,
			'avg_ltv'    => $row ? (float) $row['avg_ltv'] : 0,
			'avg_orders' => $row ? (float) $row['avg_orders'] : 0,
			'reached_2'  => $row ? (int) $row['reached_2'] : 0,
			'reached_3'  => $row ? (int) $row['reached_3'] : 0,
			'reached_4'  => $row ? (int) $row['reached_4'] : 0,
			'active'     => $row ? (int) $row['active'] : 0,
			'median_ltv' => 0,
		);
		if ( $stats['customers'] > 0 ) {
			list( $inner2, $vals2 ) = $this->lifetime_sql( $basis );
			$offset                 = (int) floor( ( $stats['customers'] - 1 ) / 2 );
			$msql                   = "SELECT t.rev FROM ($inner2) t ORDER BY t.rev ASC LIMIT 1 OFFSET %d";
			$vals2[]                = $offset;
			$stats['median_ltv']    = (float) $this->db->get_var( $this->db->prepare( $msql, $vals2 ) );
			$this->note_error( 'lifetime_stats' );
		}
		return $stats;
	}

	/**
	 * Top customers by lifetime revenue.
	 */
	public function top_customers( $basis, $limit = 25 ) {
		list( $inner, $vals ) = $this->lifetime_sql( $basis );
		$sql                  = "SELECT t.* FROM ($inner) t ORDER BY t.rev DESC LIMIT %d";
		$vals[]               = (int) $limit;
		$rows                 = $this->db->get_results( $this->db->prepare( $sql, $vals ), ARRAY_A );
		$this->note_error( 'top_customers' );
		$out                  = array();
		foreach ( $rows ? $rows : array() as $r ) {
			$out[] = array(
				'key'         => (string) $r['k'],
				'orders'      => (int) $r['orders'],
				'revenue'     => (float) $r['rev'],
				'first_order' => (string) $r['first_date'],
				'last_order'  => (string) $r['last_date'],
				'customer_id' => (int) $r['customer_id'],
				'email'       => (string) $r['email'],
				'name'        => trim( (string) $r['first_name'] . ' ' . (string) $r['last_name'] ),
				'state'       => strtoupper( (string) $r['state'] ),
				'country'     => strtoupper( (string) $r['country'] ),
			);
		}
		return $out;
	}

	/**
	 * Streams (key, timestamp, local month) for counted orders since $gmt_since, oldest first, in pages,
	 * and reduces them into a per-customer structure for cohorts and order sequence analysis.
	 *
	 * @return array key => [ 'first' => ts, 'second' => ts|null, 'n' => int, 'months' => [ 'Y-m' => 1 ] ]
	 */
	public function customer_timeline( $gmt_since, $tz_offset_seconds ) {
		$page_size = 20000;
		$offset    = 0;
		$cust      = array();

		while ( true ) {
			if ( $this->hpos ) {
				list( $ph, $vals ) = $this->in_list( $this->statuses_bare );
				$sql               = "SELECT
						CASE WHEN o.billing_email IS NOT NULL AND o.billing_email <> '' THEN CONCAT('e:', LOWER(o.billing_email))
							 WHEN o.customer_id > 0 THEN CONCAT('u:', o.customer_id)
							 ELSE CONCAT('o:', o.id) END AS k,
						UNIX_TIMESTAMP(o.date_created_gmt) AS ts,
						DATE_FORMAT(DATE_ADD(o.date_created_gmt, INTERVAL %d SECOND), '%%Y-%%m') AS m
					FROM {$this->t('wc_orders')} o
					WHERE o.type = 'shop_order' AND o.status IN ($ph) AND o.date_created_gmt >= %s
					ORDER BY o.date_created_gmt ASC, o.id ASC
					LIMIT %d OFFSET %d";
				$params = array_merge( array( (int) $tz_offset_seconds ), $vals, array( $gmt_since, $page_size, $offset ) );
			} else {
				list( $ph, $vals ) = $this->in_list( $this->statuses_prefixed );
				$sql               = "SELECT
						CASE WHEN x.email <> '' THEN CONCAT('e:', x.email) WHEN x.uid > 0 THEN CONCAT('u:', x.uid) ELSE CONCAT('o:', x.id) END AS k,
						x.ts, x.m
					FROM (
						SELECT p.ID AS id, UNIX_TIMESTAMP(p.post_date_gmt) AS ts,
							DATE_FORMAT(DATE_ADD(p.post_date_gmt, INTERVAL %d SECOND), '%%Y-%%m') AS m,
							LOWER(COALESCE(MAX(CASE WHEN pm.meta_key = '_billing_email' THEN pm.meta_value END), '')) AS email,
							COALESCE(MAX(CASE WHEN pm.meta_key = '_customer_user' THEN pm.meta_value END), 0) + 0 AS uid
						FROM {$this->db->posts} p
						LEFT JOIN {$this->db->postmeta} pm ON pm.post_id = p.ID AND pm.meta_key IN ('_billing_email','_customer_user')
						WHERE p.post_type = 'shop_order' AND p.post_status IN ($ph) AND p.post_date_gmt >= %s
						GROUP BY p.ID
						ORDER BY p.post_date_gmt ASC, p.ID ASC
						LIMIT %d OFFSET %d
					) x";
				$params = array_merge( array( (int) $tz_offset_seconds ), $vals, array( $gmt_since, $page_size, $offset ) );
			}

			$rows = $this->db->get_results( $this->db->prepare( $sql, $params ), ARRAY_N );
			$this->note_error( 'customer_timeline' );
			if ( empty( $rows ) ) {
				break;
			}
			foreach ( $rows as $row ) {
				$k  = $row[0];
				$ts = (int) $row[1];
				$m  = $row[2];
				if ( ! isset( $cust[ $k ] ) ) {
					$cust[ $k ] = array(
						'first'   => $ts,
						'first_m' => $m,
						'second'  => null,
						'n'       => 1,
						'months'  => array( $m => 1 ),
					);
				} else {
					$c = &$cust[ $k ];
					$c['n']++;
					if ( null === $c['second'] ) {
						$c['second'] = $ts;
					}
					$c['months'][ $m ] = 1;
					unset( $c );
				}
			}
			if ( count( $rows ) < $page_size ) {
				break;
			}
			$offset += $page_size;
			if ( $offset > 2000000 ) {
				break; // Safety valve for enormous stores.
			}
		}
		return $cust;
	}

	/* ------------------------------------------------------------------ */
	/* Diagnostics                                                          */
	/* ------------------------------------------------------------------ */

	/** Whether any order carries a given meta key (attribution, COGS). Cached for an hour. */
	public function has_meta( $meta_key ) {
		$cache_key = 'woo_pulse_hasmeta_' . md5( $meta_key . $this->storage_label() );
		$cached    = get_transient( $cache_key );
		if ( false !== $cached ) {
			return (bool) $cached;
		}
		if ( $this->hpos ) {
			$sql = "SELECT 1 FROM {$this->t('wc_orders_meta')} WHERE meta_key = %s LIMIT 1";
		} else {
			$sql = "SELECT 1 FROM {$this->db->postmeta} WHERE meta_key = %s LIMIT 1";
		}
		$has = (bool) $this->db->get_var( $this->db->prepare( $sql, $meta_key ) );
		$this->note_error( 'has_meta' );
		set_transient( $cache_key, $has ? 1 : 0, HOUR_IN_SECONDS );
		return $has;
	}

	/** Number of orders (any status) created in the last N days. */
	public function recent_order_count( $days = 30 ) {
		$since = Woo_Pulse_Dates::gmt_days_ago( $days );
		if ( $this->hpos ) {
			$sql = "SELECT COUNT(*) FROM {$this->t('wc_orders')} WHERE type = 'shop_order' AND date_created_gmt >= %s";
		} else {
			$sql = "SELECT COUNT(*) FROM {$this->db->posts} WHERE post_type = 'shop_order' AND post_date_gmt >= %s";
		}
		$count = (int) $this->db->get_var( $this->db->prepare( $sql, $since ) );
		$this->note_error( 'recent_order_count' );
		return $count;
	}
}
