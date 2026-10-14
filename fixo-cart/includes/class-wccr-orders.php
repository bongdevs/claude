<?php
/**
 * Order dashboard endpoints (merged from "Woo Telegram Orders"): summary KPIs, a paginated
 * order list and order details. HPOS-safe, and the summary uses aggregate SQL instead of
 * loading every order into memory.
 *
 * Routes (namespace wccr/v1, manage_woocommerce only):
 *   GET /orders/summary?days=30
 *   GET /orders?page=1&per_page=20&search=&status=&recovered=   (recovered: any | admin | link)
 *   GET /orders/{id}
 *
 * @package Fixo_Cart
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class WCCR_Orders {

	const PAID_STATUSES = array( 'wc-processing', 'wc-completed', 'wc-on-hold' );

	public function __construct() {
		add_action( 'rest_api_init', array( $this, 'register_routes' ) );
	}

	public function register_routes() {
		$perm = function () {
			return current_user_can( 'manage_woocommerce' );
		};
		register_rest_route( 'wccr/v1', '/orders/summary', array( 'methods' => 'GET', 'callback' => array( $this, 'summary' ), 'permission_callback' => $perm ) );
		register_rest_route( 'wccr/v1', '/orders', array( 'methods' => 'GET', 'callback' => array( $this, 'orders' ), 'permission_callback' => $perm ) );
		register_rest_route( 'wccr/v1', '/orders/(?P<id>\d+)', array( 'methods' => 'GET', 'callback' => array( $this, 'order' ), 'permission_callback' => $perm ) );
	}

	private static function hpos() {
		return class_exists( '\Automattic\WooCommerce\Utilities\OrderUtil' ) && \Automattic\WooCommerce\Utilities\OrderUtil::custom_orders_table_usage_is_enabled();
	}

	/**
	 * Revenue + order count of paid orders per day since $since_gmt.
	 *
	 * @return array[] Rows of { day, orders, sales }.
	 */
	private static function daily_sales( $since_gmt ) {
		global $wpdb;
		$in = "'" . implode( "','", array_map( 'esc_sql', self::PAID_STATUSES ) ) . "'";
		if ( self::hpos() ) {
			$table = $wpdb->prefix . 'wc_orders';
			$sql   = "SELECT DATE(date_created_gmt) AS day, COUNT(*) AS orders, SUM(total_amount) AS sales
				FROM {$table} WHERE type = 'shop_order' AND status IN ({$in}) AND date_created_gmt >= %s
				GROUP BY DATE(date_created_gmt) ORDER BY day ASC";
		} else {
			$sql = "SELECT DATE(p.post_date_gmt) AS day, COUNT(*) AS orders, SUM(CAST(m.meta_value AS DECIMAL(19,4))) AS sales
				FROM {$wpdb->posts} p LEFT JOIN {$wpdb->postmeta} m ON m.post_id = p.ID AND m.meta_key = '_order_total'
				WHERE p.post_type = 'shop_order' AND p.post_status IN ({$in}) AND p.post_date_gmt >= %s
				GROUP BY DATE(p.post_date_gmt) ORDER BY day ASC";
		}
		$rows = $wpdb->get_results( $wpdb->prepare( $sql, $since_gmt ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL
		foreach ( (array) $rows as &$row ) {
			$row['orders'] = (int) $row['orders'];
			$row['sales']  = round( (float) $row['sales'], 2 );
		}
		return (array) $rows;
	}

	public function summary( WP_REST_Request $request ) {
		$days  = max( 1, min( 365, (int) ( $request->get_param( 'days' ) ?: 30 ) ) );
		$since = gmdate( 'Y-m-d H:i:s', time() - $days * DAY_IN_SECONDS );
		$prev  = gmdate( 'Y-m-d H:i:s', time() - 2 * $days * DAY_IN_SECONDS );

		$daily      = self::daily_sales( $since );
		$prev_daily = array_filter(
			self::daily_sales( $prev ),
			function ( $r ) use ( $since ) {
				return $r['day'] < substr( $since, 0, 10 );
			}
		);
		$sum = function ( $rows, $key ) {
			return array_sum( wp_list_pluck( $rows, $key ) );
		};

		$counts = array();
		foreach ( array( 'pending', 'processing', 'on-hold', 'completed', 'cancelled', 'failed', 'refunded' ) as $status ) {
			$counts[ $status ] = (int) wc_orders_count( $status );
		}
		$today_key = gmdate( 'Y-m-d' );
		$today     = array_values( array_filter( $daily, function ( $r ) use ( $today_key ) { return $r['day'] === $today_key; } ) );

		$sales  = $sum( $daily, 'sales' );
		$orders = $sum( $daily, 'orders' );
		return rest_ensure_response(
			array(
				'days'          => $days,
				'sales'         => round( $sales, 2 ),
				'orders'        => (int) $orders,
				'aov'           => $orders ? round( $sales / $orders, 2 ) : 0,
				'prev_sales'    => round( $sum( $prev_daily, 'sales' ), 2 ),
				'prev_orders'   => (int) $sum( $prev_daily, 'orders' ),
				'today_orders'  => $today ? (int) $today[0]['orders'] : 0,
				'today_sales'   => $today ? (float) $today[0]['sales'] : 0,
				'status_counts' => $counts,
				'daily'         => $daily,
			)
		);
	}

	public function orders( WP_REST_Request $request ) {
		$page     = max( 1, (int) $request->get_param( 'page' ) );
		$per_page = min( 50, max( 5, (int) $request->get_param( 'per_page' ) ?: 20 ) );
		$search   = sanitize_text_field( (string) $request->get_param( 'search' ) );
		$status   = sanitize_key( (string) $request->get_param( 'status' ) );
		$recovered = sanitize_key( (string) $request->get_param( 'recovered' ) );

		$args = array(
			'limit'    => $per_page,
			'paged'    => $page,
			'paginate' => true,
			'orderby'  => 'date',
			'order'    => 'DESC',
			'type'     => 'shop_order',
		);
		if ( $search ) {
			$number = ltrim( $search, '#' );
			$exact  = ctype_digit( $number ) ? wc_get_order( (int) $number ) : false;
			if ( $exact && 'shop_order' === $exact->get_type() && 1 === $page ) {
				return rest_ensure_response( array( 'orders' => array( self::shape( $exact, false ) ), 'total' => 1, 'pages' => 1 ) );
			}
			if ( self::hpos() ) {
				$args['s'] = $search; // HPOS searches name, email, phone, address and items.
			} else {
				$ids              = wc_order_search( $search );
				$args['post__in'] = $ids ? array_map( 'absint', $ids ) : array( 0 );
			}
		}
		if ( $status && 'all' !== $status ) {
			$args['status'] = array( 'wc-' . $status );
		} else {
			$args['status'] = array_diff( array_keys( wc_get_order_statuses() ), array( 'wc-checkout-draft' ) );
		}

		// Recovered orders: created by an admin from a cart, and/or completed by the customer after a recovery link.
		$admin_clause = array( 'key' => '_wccr_conversion_type', 'value' => 'admin_recovery' );
		$link_clause  = array(
			'relation' => 'AND',
			array( 'key' => '_wccr_recovered_cart_id', 'compare' => 'EXISTS' ),
			array( 'key' => '_wccr_conversion_type', 'value' => 'admin_recovery', 'compare' => '!=' ),
		);
		if ( 'admin' === $recovered ) {
			$args['meta_query'] = array( $admin_clause ); // phpcs:ignore WordPress.DB.SlowDBQuery
		} elseif ( 'link' === $recovered ) {
			$args['meta_query'] = array( $link_clause ); // phpcs:ignore WordPress.DB.SlowDBQuery
		} elseif ( 'any' === $recovered ) {
			$args['meta_query'] = array( 'relation' => 'OR', $admin_clause, $link_clause ); // phpcs:ignore WordPress.DB.SlowDBQuery
		}

		// Legacy (posts) order storage ignores a custom meta_query passed to wc_get_orders(), so add it
		// to the underlying WP_Query instead. HPOS supports meta_query natively.
		$legacy_filter = null;
		if ( ! empty( $args['meta_query'] ) && ! self::hpos() ) {
			$meta_query    = $args['meta_query'];
			$legacy_filter = function ( $query ) use ( $meta_query ) {
				$query['meta_query'] = empty( $query['meta_query'] ) ? $meta_query : array( 'relation' => 'AND', $query['meta_query'], $meta_query ); // phpcs:ignore WordPress.DB.SlowDBQuery
				return $query;
			};
			add_filter( 'woocommerce_order_data_store_cpt_get_orders_query', $legacy_filter, 10, 1 );
		}

		$result = wc_get_orders( $args );

		if ( $legacy_filter ) {
			remove_filter( 'woocommerce_order_data_store_cpt_get_orders_query', $legacy_filter, 10 );
		}
		$out    = array();
		foreach ( $result->orders as $order ) {
			$out[] = self::shape( $order, false );
		}
		return rest_ensure_response(
			array(
				'orders' => $out,
				'total'  => (int) $result->total,
				'pages'  => (int) $result->max_num_pages,
			)
		);
	}

	public function order( WP_REST_Request $request ) {
		$order = wc_get_order( (int) $request['id'] );
		if ( ! $order || 'shop_order' !== $order->get_type() ) {
			return new WP_Error( 'wccr_not_found', __( 'Order not found', 'fixo-cart' ), array( 'status' => 404 ) );
		}
		return rest_ensure_response( self::shape( $order, true ) );
	}

	/** 'admin' (created from a cart in the dashboard), 'link' (customer used a recovery link) or ''. */
	public static function recovery_type( $order ) {
		if ( 'admin_recovery' === $order->get_meta( '_wccr_conversion_type' ) ) {
			return 'admin';
		}
		return $order->get_meta( '_wccr_recovered_cart_id' ) ? 'link' : '';
	}

	private static function recovered_by( $order ) {
		$user_id = (int) $order->get_meta( '_wccr_converted_by_user_id' );
		$user    = $user_id ? get_user_by( 'id', $user_id ) : false;
		return $user ? $user->display_name : '';
	}

	/**
	 * @param WC_Order $order
	 * @param bool     $full Include addresses, line items and notes.
	 */
	public static function shape( $order, $full ) {
		$items = array();
		foreach ( $order->get_items() as $item ) {
			$product = $item->get_product();
			$items[] = array(
				'name'     => $item->get_name(),
				'quantity' => (int) $item->get_quantity(),
				'total'    => (float) $order->get_line_total( $item, true ),
				'sku'      => $product ? $product->get_sku() : '',
				'image'    => $product ? wp_get_attachment_image_url( $product->get_image_id(), 'thumbnail' ) : '',
			);
		}
		$created = $order->get_date_created();
		$data    = array(
			'id'              => $order->get_id(),
			'number'          => $order->get_order_number(),
			'status'          => $order->get_status(),
			'status_label'    => wc_get_order_status_name( $order->get_status() ),
			'date'            => $created ? $created->date_i18n( 'Y-m-d H:i:s' ) : '',
			'customer'        => trim( $order->get_billing_first_name() . ' ' . $order->get_billing_last_name() ),
			'phone'           => $order->get_billing_phone(),
			'email'           => $order->get_billing_email(),
			'items'           => $items,
			'total'           => (float) $order->get_total(),
			'currency'        => $order->get_currency(),
			'payment'         => $order->get_payment_method_title(),
			'source'          => (string) $order->get_meta( '_wccr_source_label' ),
			'conversion_type' => (string) $order->get_meta( '_wccr_conversion_type' ),
			'cart_id'         => (int) $order->get_meta( '_wccr_recovered_cart_id' ),
			'recovery_type'   => self::recovery_type( $order ),
			'recovered_by'    => self::recovered_by( $order ),
			'edit_link'       => WCCR_REST_API::order_edit_url( $order->get_id() ),
		);
		if ( $full ) {
			$address = function ( $type ) use ( $order ) {
				$country = $order->{"get_{$type}_country"}();
				return array_values(
					array_filter(
						array(
							trim( $order->{"get_{$type}_first_name"}() . ' ' . $order->{"get_{$type}_last_name"}() ),
							$order->{"get_{$type}_address_1"}(),
							$order->{"get_{$type}_address_2"}(),
							trim( $order->{"get_{$type}_city"}() . ' ' . $order->{"get_{$type}_postcode"}() ),
							WCCR_Cart::state_name( $country, $order->{"get_{$type}_state"}() ),
							WCCR_Cart::country_name( $country ),
						)
					)
				);
			};
			$data['billing']  = $address( 'billing' );
			$data['shipping'] = $order->has_shipping_address() ? $address( 'shipping' ) : array();
			$data['subtotal'] = (float) $order->get_subtotal();
			$data['shipping_total'] = (float) $order->get_shipping_total();
			$data['discount'] = (float) $order->get_discount_total();
			$data['note']     = $order->get_customer_note();
		}
		return $data;
	}
}
