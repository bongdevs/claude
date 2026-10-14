<?php
/**
 * Data access helper for the wccr_carts table.
 * Every read/write to the cart record goes through this class.
 *
 * @package Fixo_Cart
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class WCCR_Cart {

	/**
	 * When true, storefront capture hooks do nothing. Used while a recovery link
	 * restores a cart so the restore itself does not create a duplicate row.
	 *
	 * @var bool
	 */
	public static $suspend_capture = false;

	/* ------------------------------------------------------------------
	 * Time helpers.
	 * All DATETIME columns are written with current_time( 'mysql' ), i.e. in the
	 * SITE timezone. Every comparison must therefore use site-local strings too.
	 * (Mixing gmdate()/time()/strtotime() with local strings shifted every
	 * threshold by the site's UTC offset — e.g. 6 hours in Dhaka.)
	 * ---------------------------------------------------------------- */

	/** Site-local MySQL datetime for "now minus N seconds". */
	public static function mysql_ago( $seconds ) {
		return wp_date( 'Y-m-d H:i:s', time() - (int) $seconds );
	}

	/** Convert a stored (site-local) MySQL datetime into a real Unix timestamp. */
	public static function to_timestamp( $mysql ) {
		if ( empty( $mysql ) ) {
			return 0;
		}
		try {
			return ( new DateTimeImmutable( $mysql, wp_timezone() ) )->getTimestamp();
		} catch ( Exception $e ) {
			return 0;
		}
	}

	/**
	 * Central gate: should we capture data for the current visitor at all?
	 */
	public static function should_capture() {
		if ( self::$suspend_capture ) {
			return false;
		}
		if ( 'yes' !== get_option( 'wccr_capture_enabled', 'yes' ) ) {
			return false;
		}
		if ( 'no' === get_option( 'wccr_capture_guests', 'yes' ) && ! is_user_logged_in() ) {
			return false;
		}
		if ( 'yes' === get_option( 'wccr_exclude_admin_ip', 'yes' ) && current_user_can( 'manage_woocommerce' ) ) {
			return false;
		}
		return true;
	}

	/**
	 * Get (or lazily create) the current visitor's cart row and return its ID.
	 * Keyed on the WooCommerce session customer id so guests and logged-in users both work.
	 *
	 * @return int|false
	 */
	public static function get_or_create_current( $extra = array() ) {
		if ( ! function_exists( 'WC' ) || ! WC()->session ) {
			return false;
		}

		$session_key = self::get_session_key();
		if ( ! $session_key ) {
			return false;
		}

		global $wpdb;
		$table = $wpdb->prefix . FIXO_CART_TABLE_CARTS;

		$existing = $wpdb->get_row( $wpdb->prepare( "SELECT id, order_id FROM {$table} WHERE session_key = %s", $session_key ), ARRAY_A );

		if ( $existing ) {
			if ( empty( $existing['order_id'] ) ) {
				return (int) $existing['id'];
			}
			// This session already produced an order. Logged-in users keep the same WooCommerce
			// customer id forever, so without this every later cart of a returning customer was
			// silently written into the old converted row and never tracked again.
			// Archive the converted row under a new key and start a fresh cart record.
			$wpdb->update(
				$table,
				array( 'session_key' => substr( $session_key, 0, 40 ) . '_o' . (int) $existing['order_id'] . '_' . (int) $existing['id'] ),
				array( 'id' => (int) $existing['id'] )
			);
		}

		$now = current_time( 'mysql' );

		$row = array_merge(
			array(
				'session_key' => $session_key,
				'user_id'     => get_current_user_id(),
				'status'      => 'active',
				'currency'    => get_woocommerce_currency(),
				'created_at'  => $now,
				'updated_at'  => $now,
			),
			$extra
		);

		$inserted = $wpdb->insert( $table, $row );

		if ( $inserted && $wpdb->insert_id ) {
			$created_id = (int) $wpdb->insert_id;
			self::apply_risk_detection( $created_id, true );
			self::log_event( $created_id, 'cart_created', array( 'source' => 'capture' ) );
			return $created_id;
		}

		// Race condition: heartbeat + field-save AJAX can arrive at the same time and the second
		// INSERT hits the UNIQUE(session_key) index. Re-read instead of losing the data.
		$id = $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$table} WHERE session_key = %s", $session_key ) );
		return $id ? (int) $id : false;
	}

	/**
	 * Build a stable per-visitor session key (guests use the WooCommerce/WordPress session cookie's customer id).
	 */
	/**
	 * "BD-15" -> "Chattogram", "BD" -> "Bangladesh" using WooCommerce's own state/country lists.
	 * Unknown codes (or free-text states typed by the customer) are returned unchanged.
	 */
	public static function state_name( $country, $state ) {
		$state = (string) $state;
		if ( '' === $state || ! function_exists( 'WC' ) || ! WC() || ! WC()->countries ) {
			return $state;
		}
		$country = strtoupper( (string) $country );
		if ( '' === $country && preg_match( '/^([A-Z]{2})-/', $state, $m ) ) {
			$country = $m[1]; // Country missing but the code itself says "BD-…".
		}
		$states = $country ? WC()->countries->get_states( $country ) : array();
		if ( is_array( $states ) && isset( $states[ $state ] ) ) {
			return html_entity_decode( $states[ $state ], ENT_QUOTES, 'UTF-8' );
		}
		return $state;
	}

	public static function country_name( $country ) {
		$country = strtoupper( (string) $country );
		if ( '' === $country || ! function_exists( 'WC' ) || ! WC() || ! WC()->countries ) {
			return $country;
		}
		$countries = WC()->countries->get_countries();
		return isset( $countries[ $country ] ) ? html_entity_decode( $countries[ $country ], ENT_QUOTES, 'UTF-8' ) : $country;
	}

	public static function get_session_key() {
		if ( function_exists( 'WC' ) && WC()->session && method_exists( WC()->session, 'get_customer_id' ) ) {
			$customer_id = WC()->session->get_customer_id();
			if ( $customer_id ) {
				return 'wc_' . $customer_id;
			}
		}
		return false;
	}

	/**
	 * Update fields on a cart row by id.
	 */
	/**
	 * @param bool $touch Bump updated_at. updated_at means "last shopper activity" (it drives the
	 *                    abandonment timer, email step-1 timing and the Live indicator), so system
	 *                    writes (email sent, token, risk flag, admin edits) must pass false.
	 */
	public static function update( $id, array $data, $touch = true ) {
		global $wpdb;
		$table = $wpdb->prefix . FIXO_CART_TABLE_CARTS;
		if ( $touch ) {
			$data['updated_at'] = current_time( 'mysql' );
		}
		return $wpdb->update( $table, $data, array( 'id' => (int) $id ) );
	}

	/**
	 * Update by session key (used from AJAX/JS field capture, before we necessarily know the row id).
	 */
	public static function update_by_session( $session_key, array $data ) {
		global $wpdb;
		$table = $wpdb->prefix . FIXO_CART_TABLE_CARTS;
		$data['updated_at'] = current_time( 'mysql' );
		return $wpdb->update( $table, $data, array( 'session_key' => $session_key ) );
	}

	/**
	 * Fetch a single cart row.
	 */
	public static function get( $id ) {
		global $wpdb;
		$table = $wpdb->prefix . FIXO_CART_TABLE_CARTS;
		return $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d", $id ), ARRAY_A );
	}

	public static function get_by_token( $token ) {
		global $wpdb;
		$table = $wpdb->prefix . FIXO_CART_TABLE_CARTS;
		return $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE recovery_token = %s", $token ), ARRAY_A );
	}

	public static function get_by_session( $session_key ) {
		global $wpdb;
		$table = $wpdb->prefix . FIXO_CART_TABLE_CARTS;
		return $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE session_key = %s", $session_key ), ARRAY_A );
	}

	/**
	 * Delete a cart row (and its events).
	 */
	public static function delete( $id ) {
		global $wpdb;
		$carts_table  = $wpdb->prefix . FIXO_CART_TABLE_CARTS;
		$events_table = $wpdb->prefix . FIXO_CART_TABLE_EVENTS;
		$wpdb->delete( $events_table, array( 'cart_id' => (int) $id ) );
		return $wpdb->delete( $carts_table, array( 'id' => (int) $id ) );
	}

	/**
	 * Log a lightweight event against a cart (email_sent, recovery_link_opened, phone_contacted, note_added...).
	 */
	public static function log_event( $cart_id, $type, $data = array() ) {
		global $wpdb;
		$table = $wpdb->prefix . FIXO_CART_TABLE_EVENTS;
		$wpdb->insert(
			$table,
			array(
				'cart_id'    => (int) $cart_id,
				'event_type' => sanitize_key( $type ),
				'event_data' => is_array( $data ) || is_object( $data ) ? wp_json_encode( $data ) : $data,
				'created_at' => current_time( 'mysql' ),
			)
		);
	}

	public static function get_events( $cart_id ) {
		global $wpdb;
		$table = $wpdb->prefix . FIXO_CART_TABLE_EVENTS; // Was "$$table" (variable-variable typo) — the query had no table name, so every timeline/journey/coupon lookup returned nothing.
		$rows  = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$table} WHERE cart_id = %d ORDER BY created_at DESC, id DESC", $cart_id ), ARRAY_A );
		return is_array( $rows ) ? $rows : array();
	}

	/** Get the latest event for a cart, optionally restricted to an event type. */
	public static function get_latest_event( $cart_id, $type = '' ) {
		global $wpdb;
		$table = $wpdb->prefix . FIXO_CART_TABLE_EVENTS;
		if ( $type ) {
			return $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE cart_id = %d AND event_type = %s ORDER BY created_at DESC, id DESC LIMIT 1", $cart_id, sanitize_key( $type ) ), ARRAY_A );
		}
		return $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE cart_id = %d ORDER BY created_at DESC, id DESC LIMIT 1", $cart_id ), ARRAY_A );
	}

	/**
	 * Snapshot the current WooCommerce cart contents into a compact array we can store as JSON
	 * and later re-hydrate (for recovery links) or turn into WC order line items.
	 */
	public static function build_cart_contents_snapshot() {
		if ( ! function_exists( 'WC' ) || ! WC()->cart ) {
			return array();
		}

		$items = array();
		foreach ( WC()->cart->get_cart() as $cart_item_key => $cart_item ) {
			$product = $cart_item['data'];
			if ( ! $product ) {
				continue;
			}
			$items[] = array(
				'product_id'   => $cart_item['product_id'],
				'variation_id' => $cart_item['variation_id'],
				'variation'    => $cart_item['variation'],
				'quantity'     => $cart_item['quantity'],
				'name'         => $product->get_name(),
				'sku'          => $product->get_sku(),
				'price'        => (float) $product->get_price(),
				'line_total'   => (float) $cart_item['line_total'],
				'image'        => wp_get_attachment_image_url( $product->get_image_id(), 'thumbnail' ),
				'permalink'    => $product->get_permalink(),
			);
		}
		return $items;
	}

	/**
	 * Generate a unique, hard-to-guess recovery token.
	 */
	public static function generate_recovery_token() {
		return wp_generate_password( 32, false, false );
	}

	/**
	 * Paginated, filterable list of carts for the REST API / dashboard.
	 *
	 * @param array $args status, search, date_from, date_to, page, per_page, orderby, order, min_total, max_total, source, device, conversion_type, presence, product_id.
	 */
	public static function query( array $args = array() ) {
		global $wpdb;
		$table = $wpdb->prefix . FIXO_CART_TABLE_CARTS;

		$defaults = array(
			'status'    => '',
			'search'    => '',
			'date_from' => '',
			'date_to'   => '',
			'min_total' => '',
			'max_total' => '',
			'source' => '',
			'device' => '',
			'conversion_type' => '',
			'presence' => '',
			'product_id' => '',
			'risk_status' => '',
			'page'      => 1,
			'per_page'  => 20,
			'orderby'   => 'updated_at',
			'order'     => 'DESC',
		);
		$args = wp_parse_args( $args, $defaults );

		// The REST API passes NULL for every filter that is not in the URL. "null !== ''" is true,
		// so a missing max_total turned into "cart_total <= 0" and the list came back EMPTY
		// (this bug came with the max_total filter in 2.1 — 2.0.1 did not have it).
		foreach ( $args as $key => $value ) {
			if ( null === $value ) {
				$args[ $key ] = isset( $defaults[ $key ] ) ? $defaults[ $key ] : '';
			}
		}
		$args['min_total'] = is_numeric( $args['min_total'] ) ? $args['min_total'] : '';
		$args['max_total'] = is_numeric( $args['max_total'] ) ? $args['max_total'] : '';

		// Orders that have already been created/placed are intentionally excluded from this list.
		// Their details remain attached to the WooCommerce order, while Cart Recovery only
		// shows carts that still need recovery/action.
		// Exception: when the admin explicitly filters for converted carts or a conversion type,
		// those rows must be returned — otherwise the "Converted" / "Conversion" filters were dead.
		$wants_converted = $args['conversion_type']
			|| ( is_array( $args['status'] ) ? in_array( 'converted', $args['status'], true ) : 'converted' === $args['status'] );
		$where = array( '1=1' );
		if ( ! $wants_converted ) {
			$where[] = 'order_id IS NULL';
		}

		// Default dashboard scope: show only real carts/checkout sessions.
		// The optional all_visitors mode exposes visitor-only records too.
		if ( 'all_visitors' !== get_option( 'wccr_capture_scope', 'cart_checkout' ) ) {
			$where[] = "(cart_contents IS NOT NULL AND cart_contents <> '' AND cart_contents <> '[]')";
		}
		$params = array();

		if ( $args['status'] ) {
			if ( is_array( $args['status'] ) ) {
				$placeholders = implode( ',', array_fill( 0, count( $args['status'] ), '%s' ) );
				$where[]      = "status IN ({$placeholders})";
				$params        = array_merge( $params, $args['status'] );
			} else {
				$where[]  = 'status = %s';
				$params[] = $args['status'];
			}
		}

		if ( $args['search'] ) {
			$like     = '%' . $wpdb->esc_like( $args['search'] ) . '%';
			$where[]  = '(email LIKE %s OR phone LIKE %s OR first_name LIKE %s OR last_name LIKE %s)';
			$params   = array_merge( $params, array( $like, $like, $like, $like ) );
		}

		if ( $args['date_from'] ) {
			$where[]  = 'updated_at >= %s';
			$params[] = $args['date_from'] . ' 00:00:00';
		}

		if ( $args['date_to'] ) {
			$where[]  = 'updated_at <= %s';
			$params[] = $args['date_to'] . ' 23:59:59';
		}

		if ( $args['min_total'] !== '' ) {
			$where[]  = 'cart_total >= %f';
			$params[] = (float) $args['min_total'];
		}

		if ( $args['max_total'] !== '' ) {
			$where[]  = 'cart_total <= %f';
			$params[] = (float) $args['max_total'];
		}

		if ( $args['source'] ) {
			$like = '%' . $wpdb->esc_like( $args['source'] ) . '%';
			$where[] = '(utm_source LIKE %s OR utm_medium LIKE %s OR utm_campaign LIKE %s OR source_url LIKE %s OR referrer LIKE %s)';
			$params = array_merge( $params, array( $like, $like, $like, $like, $like ) );
		}

		if ( $args['device'] ) {
			$where[] = 'device_type = %s';
			$params[] = sanitize_key( $args['device'] );
		}

		if ( $args['conversion_type'] ) {
			$where[] = 'conversion_type = %s';
			$params[] = sanitize_key( $args['conversion_type'] );
		}

		if ( $args['risk_status'] ) {
			$where[] = 'risk_status = %s';
			$params[] = sanitize_key( $args['risk_status'] );
		}

		if ( $args['product_id'] ) {
			$needle = '%' . $wpdb->esc_like( '"product_id":' . absint( $args['product_id'] ) ) . '%';
			$where[] = 'cart_contents LIKE %s';
			$params[] = $needle;
		}

		if ( 'live' === $args['presence'] || 'away' === $args['presence'] || 'inactive' === $args['presence'] ) {
			// current_time('timestamp') is already offset; passing it to wp_date() applied the offset twice.
			$threshold_live     = self::mysql_ago( 75 );
			$threshold_inactive = self::mysql_ago( 3 * HOUR_IN_SECONDS );
			if ( 'live' === $args['presence'] ) {
				$where[] = 'updated_at >= %s'; $params[] = $threshold_live;
			} elseif ( 'inactive' === $args['presence'] ) {
				$where[] = 'updated_at <= %s'; $params[] = $threshold_inactive;
			} else {
				$where[] = 'updated_at < %s AND updated_at > %s'; $params[] = $threshold_live; $params[] = $threshold_inactive;
			}
		}

		$allowed_orderby = array( 'updated_at', 'created_at', 'cart_total', 'email', 'status' );
		$orderby         = in_array( $args['orderby'], $allowed_orderby, true ) ? $args['orderby'] : 'updated_at';
		$order           = 'ASC' === strtoupper( $args['order'] ) ? 'ASC' : 'DESC';

		$where_sql = implode( ' AND ', $where );

		$per_page = max( 1, min( 100, (int) $args['per_page'] ) );
		$page     = max( 1, (int) $args['page'] );
		$offset   = ( $page - 1 ) * $per_page;

		$count_sql = "SELECT COUNT(*) FROM {$table} WHERE {$where_sql}";
		$total     = $params ? $wpdb->get_var( $wpdb->prepare( $count_sql, $params ) ) : $wpdb->get_var( $count_sql );

		$sql = "SELECT * FROM {$table} WHERE {$where_sql} ORDER BY {$orderby} {$order} LIMIT %d OFFSET %d";
		$query_params = array_merge( $params, array( $per_page, $offset ) );
		$rows = $wpdb->get_results( $wpdb->prepare( $sql, $query_params ), ARRAY_A );

		return array(
			'items'      => $rows,
			'total'      => (int) $total,
			'page'       => $page,
			'per_page'   => $per_page,
			'total_pages' => (int) ceil( $total / $per_page ),
		);
	}

	/**
	 * Aggregate stats for the dashboard header cards + charts.
	 */
	public static function get_stats( $days = 30 ) {
		global $wpdb;
		$table = $wpdb->prefix . FIXO_CART_TABLE_CARTS;
		$since = self::mysql_ago( max( 1, (int) $days ) * DAY_IN_SECONDS );

		$totals = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT
					COUNT(*) AS total_carts,
					SUM(CASE WHEN status = 'abandoned' THEN 1 ELSE 0 END) AS abandoned,
					SUM(CASE WHEN status = 'recovered' THEN 1 ELSE 0 END) AS recovered,
					SUM(CASE WHEN status = 'converted' THEN 1 ELSE 0 END) AS converted,
					SUM(CASE WHEN status = 'converted' AND conversion_type = 'admin_recovery' THEN 1 ELSE 0 END) AS admin_recovered,
					SUM(CASE WHEN status = 'converted' AND conversion_type = 'customer_checkout' THEN 1 ELSE 0 END) AS customer_converted,
					SUM(CASE WHEN status IN ('abandoned') THEN cart_total ELSE 0 END) AS potential_revenue,
					SUM(CASE WHEN status = 'converted' AND conversion_type = 'admin_recovery' THEN cart_total ELSE 0 END) AS recovered_revenue,
					SUM(CASE WHEN status = 'converted' AND conversion_type = 'customer_checkout' THEN cart_total ELSE 0 END) AS customer_checkout_revenue
				FROM {$table} WHERE created_at >= %s",
				$since
			),
			ARRAY_A
		);

		$daily = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT DATE(created_at) AS day,
					SUM(CASE WHEN status = 'abandoned' THEN 1 ELSE 0 END) AS abandoned,
					SUM(CASE WHEN status = 'converted' AND conversion_type = 'admin_recovery' THEN 1 ELSE 0 END) AS recovered,
					SUM(CASE WHEN status = 'converted' AND conversion_type = 'customer_checkout' THEN 1 ELSE 0 END) AS customer_checkout
				FROM {$table} WHERE created_at >= %s GROUP BY DATE(created_at) ORDER BY day ASC",
				$since
			),
			ARRAY_A
		);

		return array(
			'totals' => $totals,
			'daily'  => $daily,
		);
	}

	/**
	 * Source/UTM performance summary for the Pro dashboard.
	 */
	public static function get_source_stats( $days = 30, $limit = 10 ) {
		global $wpdb;
		$table = $wpdb->prefix . FIXO_CART_TABLE_CARTS;
		$since = self::mysql_ago( max( 1, (int) $days ) * DAY_IN_SECONDS );
		$rows  = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT utm_source, utm_medium, utm_campaign, source_url, status, conversion_type, cart_total FROM {$table} WHERE created_at >= %s",
				$since
			),
			ARRAY_A
		);
		$groups = array();
		foreach ( $rows as $row ) {
			$key = $row['utm_source'] ? $row['utm_source'] : ( $row['source_url'] ? wp_parse_url( $row['source_url'], PHP_URL_HOST ) : 'Direct / none' );
			$key = $key ?: 'Direct / none';
			if ( ! isset( $groups[ $key ] ) ) {
				$groups[ $key ] = array( 'source' => $key, 'carts' => 0, 'abandoned' => 0, 'converted' => 0, 'revenue' => 0 );
			}
			$groups[ $key ]['carts']++;
			if ( 'abandoned' === $row['status'] ) $groups[ $key ]['abandoned']++;
			if ( 'converted' === $row['status'] && 'admin_recovery' === $row['conversion_type'] ) {
				$groups[ $key ]['converted']++;
				$groups[ $key ]['revenue'] += (float) $row['cart_total'];
			}
		}
		usort( $groups, function( $a, $b ) { return $b['carts'] <=> $a['carts']; } );
		return array_slice( array_values( $groups ), 0, $limit );
	}

	/** Return count of currently live carts for the Pro live monitor. */
	public static function get_live_count() {
		global $wpdb;
		$table = $wpdb->prefix . FIXO_CART_TABLE_CARTS;
		$dt = self::mysql_ago( 75 );
		return (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$table} WHERE order_id IS NULL AND cart_contents <> '' AND cart_contents <> '[]' AND updated_at >= %s", $dt ) );
	}

	/**
	 * Top abandoned products in the last N days — parses the cart_contents JSON.
	 */
	public static function get_top_abandoned_products( $days = 30, $limit = 5 ) {
		global $wpdb;
		$table = $wpdb->prefix . FIXO_CART_TABLE_CARTS;
		$since = self::mysql_ago( max( 1, (int) $days ) * DAY_IN_SECONDS );

		$rows = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT cart_contents FROM {$table} WHERE status IN ('abandoned','active') AND created_at >= %s",
				$since
			)
		);

		$counter = array();
		foreach ( $rows as $json ) {
			$items = json_decode( $json, true );
			if ( ! is_array( $items ) ) {
				continue;
			}
			foreach ( $items as $item ) {
				$pid = isset( $item['product_id'] ) ? (int) $item['product_id'] : 0;
				if ( ! $pid ) {
					continue;
				}
				if ( ! isset( $counter[ $pid ] ) ) {
					$counter[ $pid ] = array(
						'product_id' => $pid,
						'name'       => isset( $item['name'] ) ? $item['name'] : '',
						'count'      => 0,
					);
				}
				$counter[ $pid ]['count'] += isset( $item['quantity'] ) ? (int) $item['quantity'] : 1;
			}
		}

		usort( $counter, function ( $a, $b ) {
			return $b['count'] <=> $a['count'];
		} );

		return array_slice( array_values( $counter ), 0, $limit );
	}

	/**
	 * Product-level performance analytics for the Pro dashboard.
	 * Aggregates cart snapshots into product carts, quantities, abandoned carts,
	 * admin-recovered carts and revenue attributed to admin recovery.
	 */
	public static function get_product_analytics( $days = 30, $limit = 50 ) {
		global $wpdb;
		$table = $wpdb->prefix . FIXO_CART_TABLE_CARTS;
		$since = self::mysql_ago( max( 1, (int) $days ) * DAY_IN_SECONDS );
		$rows  = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT id, status, conversion_type, cart_contents, cart_total FROM {$table} WHERE created_at >= %s AND cart_contents IS NOT NULL AND cart_contents <> '' AND cart_contents <> '[]'",
				$since
			),
			ARRAY_A
		);

		$products = array();
		foreach ( $rows as $row ) {
			$items = json_decode( $row['cart_contents'], true );
			if ( ! is_array( $items ) ) {
				continue;
			}
			$seen = array();
			foreach ( $items as $item ) {
				$pid = isset( $item['product_id'] ) ? absint( $item['product_id'] ) : 0;
				if ( ! $pid ) {
					continue;
				}
				$variation_id = isset( $item['variation_id'] ) ? absint( $item['variation_id'] ) : 0;
				$key = $pid . ':' . $variation_id;
				if ( isset( $seen[ $key ] ) ) {
					continue;
				}
				$seen[ $key ] = true;
				if ( ! isset( $products[ $key ] ) ) {
					$products[ $key ] = array(
						'product_id' => $pid,
						'variation_id' => $variation_id,
						'name' => isset( $item['name'] ) ? $item['name'] : ( get_the_title( $pid ) ?: ( '#' . $pid ) ),
						'image' => isset( $item['image'] ) ? $item['image'] : '',
						'carts' => 0,
						'quantity' => 0,
						'abandoned' => 0,
						'admin_recovered' => 0,
						'customer_checkout' => 0,
						'recovered_revenue' => 0,
						'cart_value' => 0,
					);
				}
				$products[ $key ]['carts']++;
				$qty = isset( $item['quantity'] ) ? max( 1, (int) $item['quantity'] ) : 1;
				$products[ $key ]['quantity'] += $qty;
				$products[ $key ]['cart_value'] += isset( $item['line_total'] ) ? (float) $item['line_total'] : 0;
				if ( 'abandoned' === $row['status'] ) {
					$products[ $key ]['abandoned']++;
				}
				if ( 'converted' === $row['status'] && 'admin_recovery' === $row['conversion_type'] ) {
					$products[ $key ]['admin_recovered']++;
					$products[ $key ]['recovered_revenue'] += isset( $item['line_total'] ) ? (float) $item['line_total'] : 0;
				}
				if ( 'converted' === $row['status'] && 'customer_checkout' === $row['conversion_type'] ) {
					$products[ $key ]['customer_checkout']++;
				}
			}
		}

		$products = array_values( $products );
		foreach ( $products as &$product ) {
			$eligible = $product['abandoned'] + $product['admin_recovered'];
			$product['recovery_rate'] = $eligible > 0 ? round( ( $product['admin_recovered'] / $eligible ) * 100, 1 ) : 0;
			$product['per_cart_value'] = $product['carts'] > 0 ? round( $product['cart_value'] / $product['carts'], 2 ) : 0;
		}
		unset( $product );
		usort( $products, function ( $a, $b ) { return $b['carts'] <=> $a['carts']; } );
		return array_slice( $products, 0, max( 1, min( 200, (int) $limit ) ) );
	}

	/** Count current abandoned carts for the wp-admin menu badge. */
	/** Detect obvious bots and duplicate cart sessions. */
	/**
	 * @param int  $id       Cart id.
	 * @param bool $check_ua Only true when called inside the shopper's own request. From WP-Cron the
	 *                       User-Agent belongs to the cron loopback, so it must not be used (it used
	 *                       to reset every detected bot back to "normal" 5 minutes later).
	 */
	public static function apply_risk_detection( $id, $check_ua = false ) {
		$row = self::get( $id );
		if ( ! $row ) return;

		$is_bot = ( 'bot' === $row['risk_status'] );
		if ( $check_ua ) {
			$ua = isset( $_SERVER['HTTP_USER_AGENT'] ) ? strtolower( (string) wp_unslash( $_SERVER['HTTP_USER_AGENT'] ) ) : '';
			$bot_tokens = array( 'bot', 'crawler', 'spider', 'slurp', 'bingpreview', 'headless', 'phantomjs', 'selenium', 'lighthouse', 'curl', 'wget', 'python-requests', 'facebookexternalhit' );
			$is_bot = ( '' === $ua );
			foreach ( $bot_tokens as $token ) { if ( false !== strpos( $ua, $token ) ) { $is_bot = true; break; } }
		}

		$duplicate_of = 0;
		global $wpdb;
		$table = $wpdb->prefix . FIXO_CART_TABLE_CARTS;
		$conditions = array(); $identity_params = array();
		if ( ! empty( $row['email'] ) ) { $conditions[] = 'email = %s'; $identity_params[] = $row['email']; }
		if ( ! empty( $row['phone'] ) ) { $conditions[] = 'phone = %s'; $identity_params[] = $row['phone']; }
		if ( $conditions ) {
			$cutoff = self::mysql_ago( DAY_IN_SECONDS );
			// Only a NEWER cart of the same person makes this one a duplicate. The old query used
			// "id <> %d", so two carts of the same customer marked EACH OTHER as duplicate and the
			// customer received no recovery email at all. Now the latest cart keeps working.
			$sql = "SELECT id FROM {$table} WHERE id > %d AND order_id IS NULL AND status IN ('active','abandoned','recovered') AND updated_at >= %s AND (" . implode( ' OR ', $conditions ) . ") ORDER BY id DESC LIMIT 1";
			$duplicate_of = (int) $wpdb->get_var( $wpdb->prepare( $sql, array_merge( array( (int) $id, $cutoff ), $identity_params ) ) );
		}
		$risk = $is_bot ? 'bot' : ( $duplicate_of ? 'duplicate' : 'normal' );

		// Write + log only when something actually changed (cron runs every 5 min; it used to
		// append a new "risk_detected" event on every single run).
		if ( $risk !== $row['risk_status'] || (int) $row['duplicate_of'] !== $duplicate_of ) {
			self::update( $id, array( 'risk_status' => $risk, 'duplicate_of' => $duplicate_of ? $duplicate_of : null ), false );
			if ( 'normal' !== $risk ) self::log_event( $id, 'risk_detected', array( 'risk' => $risk, 'duplicate_of' => $duplicate_of ) );
		}
		return $risk;
	}

	public static function get_alerts( $limit = 25 ) {
		global $wpdb;
		$events = $wpdb->prefix . FIXO_CART_TABLE_EVENTS;
		$limit = max( 1, min( 100, (int) $limit ) );
		$types = array( 'alert_abandoned', 'alert_high_value', 'alert_recovery' );
		$placeholders = implode( ',', array_fill( 0, count( $types ), '%s' ) );
		$sql = "SELECT e.*, c.first_name, c.last_name, c.email, c.phone, c.cart_total, c.status, c.risk_status FROM {$events} e LEFT JOIN {$wpdb->prefix}" . FIXO_CART_TABLE_CARTS . " c ON c.id=e.cart_id WHERE e.event_type IN ({$placeholders}) ORDER BY e.created_at DESC, e.id DESC LIMIT %d";
		$params = array_merge( $types, array( $limit ) );
		$rows = $wpdb->get_results( $wpdb->prepare( $sql, $params ), ARRAY_A );
		foreach ( $rows as &$row ) { $row['event_data_decoded'] = ! empty( $row['event_data'] ) ? json_decode( $row['event_data'], true ) : array(); }
		return $rows;
	}

	public static function get_abandoned_count() {
		global $wpdb;
		$table = $wpdb->prefix . FIXO_CART_TABLE_CARTS;
		// Menu badge = only abandoned carts the store can actually follow up (customer left a phone number).
		$where = "status = 'abandoned' AND order_id IS NULL AND cart_contents IS NOT NULL AND cart_contents <> '' AND cart_contents <> '[]' AND phone IS NOT NULL AND phone <> ''";
		return (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table} WHERE {$where}" );
	}

	/**
	 * UTM analytics grouped by source / medium / campaign.
	 */
	public static function get_utm_analytics( $days = 30, $limit = 50 ) {
		global $wpdb;
		$table = $wpdb->prefix . FIXO_CART_TABLE_CARTS;
		$since = self::mysql_ago( max( 1, (int) $days ) * DAY_IN_SECONDS );
		$rows = $wpdb->get_results( $wpdb->prepare(
			"SELECT utm_source, utm_medium, utm_campaign, status, conversion_type, cart_total FROM {$table} WHERE created_at >= %s",
			$since
		), ARRAY_A );

		$groups = array();
		foreach ( $rows as $row ) {
			$source   = $row['utm_source'] ?: 'Direct / none';
			$medium   = $row['utm_medium'] ?: '—';
			$campaign = $row['utm_campaign'] ?: '—';
			$key = strtolower( $source . '|' . $medium . '|' . $campaign );
			if ( ! isset( $groups[ $key ] ) ) {
				$groups[ $key ] = array(
					'source' => $source, 'medium' => $medium, 'campaign' => $campaign,
					'carts' => 0, 'abandoned' => 0, 'admin_recovered' => 0,
					'customer_checkout' => 0, 'recovered_revenue' => 0,
				);
			}
			$groups[ $key ]['carts']++;
			if ( 'abandoned' === $row['status'] ) $groups[ $key ]['abandoned']++;
			if ( 'converted' === $row['status'] && 'admin_recovery' === $row['conversion_type'] ) {
				$groups[ $key ]['admin_recovered']++;
				$groups[ $key ]['recovered_revenue'] += (float) $row['cart_total'];
			}
			if ( 'converted' === $row['status'] && 'customer_checkout' === $row['conversion_type'] ) {
				$groups[ $key ]['customer_checkout']++;
			}
		}
		foreach ( $groups as &$group ) {
			$base = $group['abandoned'] + $group['admin_recovered'];
			$group['recovery_rate'] = $base > 0 ? round( ( $group['admin_recovered'] / $base ) * 100, 1 ) : 0;
		}
		unset( $group );
		usort( $groups, function ( $a, $b ) { return $b['carts'] <=> $a['carts']; } );
		return array_slice( array_values( $groups ), 0, max( 1, min( 200, (int) $limit ) ) );
	}

	/** Revenue-focused daily analytics. Admin recovery is kept separate from normal checkout. */
	public static function get_revenue_analytics( $days = 30 ) {
		global $wpdb;
		$table = $wpdb->prefix . FIXO_CART_TABLE_CARTS;
		$since = self::mysql_ago( max( 1, (int) $days ) * DAY_IN_SECONDS );
		$rows = $wpdb->get_results( $wpdb->prepare(
			"SELECT DATE(created_at) AS day,
				SUM(CASE WHEN status = 'abandoned' THEN cart_total ELSE 0 END) AS potential_revenue,
				SUM(CASE WHEN status = 'converted' AND conversion_type = 'admin_recovery' THEN cart_total ELSE 0 END) AS recovered_revenue,
				SUM(CASE WHEN status = 'converted' AND conversion_type = 'customer_checkout' THEN cart_total ELSE 0 END) AS customer_checkout_revenue,
				SUM(CASE WHEN status = 'converted' AND conversion_type = 'admin_recovery' THEN 1 ELSE 0 END) AS admin_orders,
				SUM(CASE WHEN status = 'converted' AND conversion_type = 'customer_checkout' THEN 1 ELSE 0 END) AS customer_orders
			 FROM {$table} WHERE created_at >= %s GROUP BY DATE(created_at) ORDER BY day ASC",
			$since
		), ARRAY_A );

		$total = array( 'potential_revenue' => 0, 'recovered_revenue' => 0, 'customer_checkout_revenue' => 0, 'admin_orders' => 0, 'customer_orders' => 0 );
		foreach ( $rows as &$row ) {
			foreach ( array_keys( $total ) as $key ) {
				$row[ $key ] = (float) $row[ $key ];
				$total[ $key ] += $row[ $key ];
			}
			$row['recovery_roi_rate'] = $row['potential_revenue'] > 0 ? round( ( $row['recovered_revenue'] / $row['potential_revenue'] ) * 100, 1 ) : 0;
		}
		unset( $row );
		$total['recovery_rate'] = $total['potential_revenue'] > 0 ? round( ( $total['recovered_revenue'] / $total['potential_revenue'] ) * 100, 1 ) : 0;
		$total['admin_aov'] = $total['admin_orders'] > 0 ? round( $total['recovered_revenue'] / $total['admin_orders'], 2 ) : 0;
		$total['customer_aov'] = $total['customer_orders'] > 0 ? round( $total['customer_checkout_revenue'] / $total['customer_orders'], 2 ) : 0;
		return array( 'daily' => $rows, 'totals' => $total );
	}

	/** Customer behavior/value segments for the v2.2 dashboard. */
	public static function get_customer_segments( $days = 30 ) {
		global $wpdb;
		$table = $wpdb->prefix . FIXO_CART_TABLE_CARTS;
		$since = self::mysql_ago( max( 1, (int) $days ) * DAY_IN_SECONDS );
		$rows = $wpdb->get_results( $wpdb->prepare(
			"SELECT id, user_id, email, phone, status, conversion_type, cart_total, device_type FROM {$table} WHERE created_at >= %s AND cart_contents IS NOT NULL AND cart_contents <> '' AND cart_contents <> '[]'",
			$since
		), ARRAY_A );

		$customers = array();
		foreach ( $rows as $row ) {
			$key = $row['user_id'] ? 'u:' . $row['user_id'] : ( $row['email'] ? 'e:' . strtolower( $row['email'] ) : ( $row['phone'] ? 'p:' . preg_replace( '/\D+/', '', $row['phone'] ) : 'c:' . $row['id'] ) );
			if ( ! isset( $customers[ $key ] ) ) {
				$customers[ $key ] = array( 'carts' => 0, 'max_value' => 0, 'abandoned' => 0, 'admin_recovered' => 0, 'customer_checkout' => 0, 'device' => $row['device_type'] );
			}
			$c =& $customers[ $key ];
			$c['carts']++;
			$c['max_value'] = max( $c['max_value'], (float) $row['cart_total'] );
			if ( 'abandoned' === $row['status'] ) $c['abandoned']++;
			if ( 'converted' === $row['status'] && 'admin_recovery' === $row['conversion_type'] ) $c['admin_recovered']++;
			if ( 'converted' === $row['status'] && 'customer_checkout' === $row['conversion_type'] ) $c['customer_checkout']++;
			unset( $c );
		}

		$segments = array(
			'high_value' => array( 'key' => 'high_value', 'label' => 'High Value (৳5,000+)', 'customers' => 0, 'carts' => 0, 'recovered' => 0, 'revenue' => 0 ),
			'active' => array( 'key' => 'active', 'label' => 'Active / Recoverable', 'customers' => 0, 'carts' => 0, 'recovered' => 0, 'revenue' => 0 ),
			'abandoned' => array( 'key' => 'abandoned', 'label' => 'Abandoned', 'customers' => 0, 'carts' => 0, 'recovered' => 0, 'revenue' => 0 ),
			'recovered' => array( 'key' => 'recovered', 'label' => 'Recovered by Admin', 'customers' => 0, 'carts' => 0, 'recovered' => 0, 'revenue' => 0 ),
			'customer_checkout' => array( 'key' => 'customer_checkout', 'label' => 'Customer Checkout', 'customers' => 0, 'carts' => 0, 'recovered' => 0, 'revenue' => 0 ),
		);
		foreach ( $customers as $customer ) {
			$segment_keys = array();
			if ( $customer['max_value'] >= 5000 ) $segment_keys[] = 'high_value';
			if ( $customer['abandoned'] > 0 ) $segment_keys[] = 'abandoned';
			if ( $customer['admin_recovered'] > 0 ) $segment_keys[] = 'recovered';
			if ( $customer['customer_checkout'] > 0 ) $segment_keys[] = 'customer_checkout';
			if ( ! $customer['abandoned'] && ! $customer['admin_recovered'] && ! $customer['customer_checkout'] ) $segment_keys[] = 'active';
			foreach ( $segment_keys as $seg ) {
				$segments[ $seg ]['customers']++;
				$segments[ $seg ]['carts'] += $customer['carts'];
				$segments[ $seg ]['recovered'] += $customer['admin_recovered'];
				$segments[ $seg ]['revenue'] += $customer['max_value'];
			}
		}
		return array_values( $segments );
	}

}
