<?php
/**
 * REST API for the React admin dashboard.
 * Namespace: wccr/v1
 *
 * @package Fixo_Cart
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class WCCR_REST_API {

	const NS = 'wccr/v1';

	public function __construct() {
		add_action( 'rest_api_init', array( $this, 'register_routes' ) );
	}

	public function register_routes() {
		register_rest_route(
			self::NS,
			'/carts',
			array(
				array(
					'methods'             => 'GET',
					'callback'            => array( $this, 'get_carts' ),
					'permission_callback' => array( $this, 'permissions_check' ),
				),
			)
		);

		register_rest_route(
			self::NS,
			'/carts/(?P<id>\d+)',
			array(
				array(
					'methods'             => 'GET',
					'callback'            => array( $this, 'get_cart' ),
					'permission_callback' => array( $this, 'permissions_check' ),
				),
				array(
					'methods'             => 'PATCH',
					'callback'            => array( $this, 'update_cart' ),
					'permission_callback' => array( $this, 'permissions_check' ),
				),
				array(
					'methods'             => 'DELETE',
					'callback'            => array( $this, 'delete_cart' ),
					'permission_callback' => array( $this, 'permissions_check' ),
				),
			)
		);

		// POST fallback for hosts/security layers that block HTTP DELETE requests.
		register_rest_route(
			self::NS,
			'/carts/(?P<id>\d+)/delete',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'delete_cart' ),
				'permission_callback' => array( $this, 'permissions_check' ),
			)
		);

		register_rest_route(
			self::NS,
			'/carts/(?P<id>\d+)/whatsapp',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'get_whatsapp_link' ),
				'permission_callback' => array( $this, 'permissions_check' ),
			)
		);

		register_rest_route(
			self::NS,
			'/carts/(?P<id>\d+)/send-email',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'send_email' ),
				'permission_callback' => array( $this, 'permissions_check' ),
			)
		);

		register_rest_route(
			self::NS,
			'/carts/(?P<id>\d+)/create-order',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'create_order' ),
				'permission_callback' => array( $this, 'permissions_check' ),
			)
		);

		register_rest_route(
			self::NS,
			'/carts/(?P<id>\d+)/note',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'add_note' ),
				'permission_callback' => array( $this, 'permissions_check' ),
			)
		);

		register_rest_route(
			self::NS,
			'/carts/(?P<id>\d+)/events',
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'get_events' ),
				'permission_callback' => array( $this, 'permissions_check' ),
			)
		);

		register_rest_route(
			self::NS,
			'/stats',
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'get_stats' ),
				'permission_callback' => array( $this, 'permissions_check' ),
			)
		);

		register_rest_route(
			self::NS,
			'/live-customers',
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'get_live_customers' ),
				'permission_callback' => array( $this, 'permissions_check' ),
			)
		);

		register_rest_route(
			self::NS,
			'/carts/(?P<id>\d+)/journey',
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'get_journey' ),
				'permission_callback' => array( $this, 'permissions_check' ),
			)
		);

		register_rest_route(
			self::NS,
			'/product-analytics',
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'get_product_analytics' ),
				'permission_callback' => array( $this, 'permissions_check' ),
			)
		);

		register_rest_route(
			self::NS,
			'/utm-analytics',
			array(
				'methods' => 'GET',
				'callback' => array( $this, 'get_utm_analytics' ),
				'permission_callback' => array( $this, 'permissions_check' ),
			)
		);

		register_rest_route(
			self::NS,
			'/revenue-analytics',
			array(
				'methods' => 'GET',
				'callback' => array( $this, 'get_revenue_analytics' ),
				'permission_callback' => array( $this, 'permissions_check' ),
			)
		);

		register_rest_route(
			self::NS,
			'/customer-segments',
			array(
				'methods' => 'GET',
				'callback' => array( $this, 'get_customer_segments' ),
				'permission_callback' => array( $this, 'permissions_check' ),
			)
		);

		register_rest_route(
			self::NS,
			'/alerts',
			array(
				'methods' => 'GET',
				'callback' => array( $this, 'get_alerts' ),
				'permission_callback' => array( $this, 'permissions_check' ),
			)
		);

		register_rest_route(
			self::NS,
			'/tracking/test',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'test_tracking' ),
				'permission_callback' => array( $this, 'permissions_check' ),
			)
		);

		register_rest_route(
			self::NS,
			'/settings',
			array(
				array(
					'methods'             => 'GET',
					'callback'            => array( $this, 'get_settings' ),
					'permission_callback' => array( $this, 'permissions_check' ),
				),
				array(
					'methods'             => 'POST',
					'callback'            => array( $this, 'save_settings' ),
					'permission_callback' => array( $this, 'permissions_check' ),
				),
			)
		);
	}

	public function permissions_check() {
		return current_user_can( 'manage_woocommerce' );
	}

	public function get_carts( WP_REST_Request $request ) {
		WCCR_Cron::run_if_stale();
		$args = array(
			'status'    => $request->get_param( 'status' ) ? explode( ',', $request->get_param( 'status' ) ) : '',
			'search'    => $request->get_param( 'search' ),
			'date_from' => $request->get_param( 'date_from' ),
			'date_to'   => $request->get_param( 'date_to' ),
			'min_total' => $request->get_param( 'min_total' ),
			'max_total' => $request->get_param( 'max_total' ),
			'source' => $request->get_param( 'source' ),
			'device' => $request->get_param( 'device' ),
			'conversion_type' => $request->get_param( 'conversion_type' ),
			'presence' => $request->get_param( 'presence' ),
			'product_id' => $request->get_param( 'product_id' ),
			'risk_status' => $request->get_param( 'risk_status' ),
			'page'      => $request->get_param( 'page' ) ?: 1,
			'per_page'  => $request->get_param( 'per_page' ) ?: 20,
			'orderby'   => $request->get_param( 'orderby' ) ?: 'updated_at',
			'order'     => $request->get_param( 'order' ) ?: 'DESC',
		);

		$result = WCCR_Cart::query( $args );

		foreach ( $result['items'] as &$item ) {
			$item = $this->prepare_cart_for_response( $item );
		}

		return rest_ensure_response( $result );
	}

	public function get_cart( WP_REST_Request $request ) {
		$cart = WCCR_Cart::get( (int) $request['id'] );
		if ( ! $cart ) {
			return new WP_Error( 'wccr_not_found', __( 'Cart not found', 'fixo-cart' ), array( 'status' => 404 ) );
		}
		return rest_ensure_response( $this->prepare_cart_for_response( $cart, true ) );
	}

	public function update_cart( WP_REST_Request $request ) {
		$id   = (int) $request['id'];
		$cart = WCCR_Cart::get( $id );
		if ( ! $cart ) {
			return new WP_Error( 'wccr_not_found', __( 'Cart not found', 'fixo-cart' ), array( 'status' => 404 ) );
		}

		$allowed = array( 'status', 'admin_note', 'first_name', 'last_name', 'email', 'phone' );
		$data    = array();
		foreach ( $allowed as $field ) {
			if ( $request->has_param( $field ) ) {
				$value = $request->get_param( $field );
				if ( 'admin_note' === $field ) {
					$data[ $field ] = sanitize_textarea_field( $value );
				} elseif ( 'email' === $field ) {
					$data[ $field ] = sanitize_email( $value );
				} else {
					$data[ $field ] = sanitize_text_field( $value );
				}
			}
		}
		// Any string used to be accepted as a status. Converted is reserved for real orders.
		if ( isset( $data['status'] ) && ! in_array( $data['status'], array( 'active', 'abandoned', 'recovered', 'emptied' ), true ) ) {
			return new WP_Error( 'wccr_bad_status', __( 'Invalid status.', 'fixo-cart' ), array( 'status' => 400 ) );
		}

		if ( empty( $data ) ) {
			return new WP_Error( 'wccr_no_fields', __( 'Nothing to update.', 'fixo-cart' ), array( 'status' => 400 ) );
		}

		WCCR_Cart::update( $id, $data, false ); // Admin edits are not shopper activity.

		if ( isset( $data['status'] ) ) {
			WCCR_Cart::log_event( $id, 'status_changed', array( 'to' => $data['status'], 'by_admin' => get_current_user_id() ) );
		}

		return rest_ensure_response( $this->prepare_cart_for_response( WCCR_Cart::get( $id ) ) );
	}

	public function delete_cart( WP_REST_Request $request ) {
		$id = (int) $request['id'];
		if ( ! WCCR_Cart::get( $id ) ) {
			return new WP_Error( 'wccr_not_found', __( 'Cart not found.', 'fixo-cart' ), array( 'status' => 404 ) );
		}

		$deleted = WCCR_Cart::delete( $id );
		if ( false === $deleted ) {
			return new WP_Error( 'wccr_delete_failed', __( 'Could not delete the cart record.', 'fixo-cart' ), array( 'status' => 500 ) );
		}

		return rest_ensure_response( array( 'deleted' => true, 'id' => $id ) );
	}

	public function get_whatsapp_link( WP_REST_Request $request ) {
		$id = (int) $request['id'];
		$cart = WCCR_Cart::get( $id );
		if ( ! $cart ) return new WP_Error( 'wccr_not_found', __( 'Cart not found', 'fixo-cart' ), array( 'status' => 404 ) );
		if ( empty( $cart['phone'] ) ) return new WP_Error( 'wccr_no_phone', __( 'This cart has no phone number.', 'fixo-cart' ), array( 'status' => 400 ) );

		$phone = preg_replace( '/[^0-9+]/', '', (string) $cart['phone'] );
		$phone = ltrim( $phone, '+' );
		if ( 0 === strpos( $phone, '0088' ) ) {
			$phone = substr( $phone, 2 );              // 00880 1XXXXXXXXX → 8801XXXXXXXXX
		}
		if ( strlen( $phone ) === 11 && 0 === strpos( $phone, '01' ) ) {
			$phone = '88' . $phone;                     // 01XXXXXXXXX → 8801XXXXXXXXX
		} elseif ( strlen( $phone ) === 10 && 0 === strpos( $phone, '1' ) ) {
			$phone = '880' . $phone;                    // 1XXXXXXXXX → 8801XXXXXXXXX
		}
		if ( strlen( $phone ) < 8 ) return new WP_Error( 'wccr_bad_phone', __( 'The phone number is not valid for WhatsApp.', 'fixo-cart' ), array( 'status' => 400 ) );

		$coupon = '';
		if ( 'yes' === get_option( 'wccr_smart_coupon_enabled', 'yes' ) && 'yes' === get_option( 'wccr_whatsapp_coupon_enabled', 'yes' ) ) {
			$coupon = WCCR_Coupons::create_for_cart( $cart );
		}
		$recovery_token = $cart['recovery_token'] ? $cart['recovery_token'] : WCCR_Cart::generate_recovery_token();
		if ( empty( $cart['recovery_token'] ) ) WCCR_Cart::update( $id, array( 'recovery_token' => $recovery_token ), false );
		$link = add_query_arg( 'wccr_recover', $recovery_token, home_url( '/' ) );
		$template = get_option( 'wccr_whatsapp_message', 'Hi {first_name}, you still have items in your cart. Complete your order here: {recovery_link}' );
		$message = strtr( $template, array(
			'{first_name}' => $cart['first_name'] ? $cart['first_name'] : __( 'there', 'fixo-cart' ),
			'{cart_total}' => wp_strip_all_tags( wc_price( $cart['cart_total'] ) ),
			'{site_name}' => get_bloginfo( 'name' ),
			'{recovery_link}' => $link,
			'{coupon_code}' => $coupon,
		) );
		WCCR_Cart::log_event( $id, 'whatsapp_recovery_opened', array( 'coupon' => $coupon, 'phone' => substr( $phone, -4 ) ) );
		return rest_ensure_response( array( 'url' => 'https://wa.me/' . $phone . '?text=' . rawurlencode( $message ), 'coupon_code' => $coupon ) );
	}

	public function send_email( WP_REST_Request $request ) {
		$id   = (int) $request['id'];
		$cart = WCCR_Cart::get( $id );
		if ( ! $cart ) {
			return new WP_Error( 'wccr_not_found', __( 'Cart not found', 'fixo-cart' ), array( 'status' => 404 ) );
		}
		if ( ! is_email( $cart['email'] ) ) {
			return new WP_Error( 'wccr_no_email', __( 'This cart has no email address on file.', 'fixo-cart' ), array( 'status' => 400 ) );
		}

		$emails = new WCCR_Emails();
		$sent   = $emails->send_manual( $cart );

		if ( ! $sent ) {
			return new WP_Error( 'wccr_send_failed', __( 'The email could not be sent.', 'fixo-cart' ), array( 'status' => 500 ) );
		}

		return rest_ensure_response( $this->prepare_cart_for_response( WCCR_Cart::get( $id ) ) );
	}

	public function create_order( WP_REST_Request $request ) {
		$id     = (int) $request['id'];
		$status = $request->get_param( 'order_status' ) ?: 'pending';
		$send   = rest_sanitize_boolean( $request->get_param( 'send_order_email' ) );

		$result = WCCR_Order::create_from_cart(
			$id,
			array(
				'order_status'     => sanitize_key( $status ),
				'send_order_email' => $send,
			)
		);

		if ( is_wp_error( $result ) ) {
			return new WP_Error( $result->get_error_code(), $result->get_error_message(), array( 'status' => 400 ) );
		}

		return rest_ensure_response(
			array(
				'order_id'  => $result,
				'edit_link' => self::order_edit_url( $result ),
				'cart'      => $this->prepare_cart_for_response( WCCR_Cart::get( $id ) ),
				'tracking'  => WCCR_Tracking::$last_results,
			)
		);
	}

	public function add_note( WP_REST_Request $request ) {
		$id   = (int) $request['id'];
		$note = sanitize_textarea_field( $request->get_param( 'note' ) );
		if ( ! $note ) {
			return new WP_Error( 'wccr_empty_note', __( 'Note cannot be empty.', 'fixo-cart' ), array( 'status' => 400 ) );
		}
		WCCR_Cart::log_event( $id, 'note_added', array( 'note' => $note, 'by_admin' => get_current_user_id(), 'author' => wp_get_current_user()->display_name ) );
		return rest_ensure_response( array( 'added' => true ) );
	}

	public function get_events( WP_REST_Request $request ) {
		return rest_ensure_response( WCCR_Cart::get_events( (int) $request['id'] ) );
	}

	public function get_stats( WP_REST_Request $request ) {
		WCCR_Cron::run_if_stale();
		$days = max( 1, min( 365, (int) ( $request->get_param( 'days' ) ?: 30 ) ) );
		$data = WCCR_Cart::get_stats( $days );
		$data['top_products'] = WCCR_Cart::get_top_abandoned_products( $days );
		$data['sources'] = WCCR_Cart::get_source_stats( $days );
		$data['live_count'] = WCCR_Cart::get_live_count();
		$data['analytics_days'] = $days;
		$data['last_cron_run']  = (int) get_option( 'wccr_last_cron_run', 0 );
		$data['wp_cron_disabled'] = defined( 'DISABLE_WP_CRON' ) && DISABLE_WP_CRON;
		return rest_ensure_response( $data );
	}

	public function get_live_customers( WP_REST_Request $request ) {
		global $wpdb;
		$table = $wpdb->prefix . FIXO_CART_TABLE_CARTS;
		$limit = max( 1, min( 100, (int) ( $request->get_param( 'limit' ) ?: 50 ) ) );
		$since = WCCR_Cart::mysql_ago( 10 * MINUTE_IN_SECONDS );
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT * FROM {$table} WHERE order_id IS NULL AND cart_contents <> '' AND cart_contents <> '[]' AND updated_at >= %s ORDER BY updated_at DESC LIMIT %d",
				$since,
				$limit
			),
			ARRAY_A
		);
		$out = array();
		foreach ( $rows as $row ) {
			$item = $this->prepare_cart_for_response( $row );
			$page_event = WCCR_Cart::get_latest_event( $row['id'], 'page_view' );
			$page_data = $page_event && ! empty( $page_event['event_data'] ) ? json_decode( $page_event['event_data'], true ) : array();
			$item['current_page'] = is_array( $page_data ) && ! empty( $page_data['page_url'] ) ? $page_data['page_url'] : '';
			$item['current_page_type'] = is_array( $page_data ) && ! empty( $page_data['page_type'] ) ? $page_data['page_type'] : '';
			$out[] = $item;
		}
		return rest_ensure_response( array( 'items' => $out, 'count' => count( $out ) ) );
	}

	public function get_journey( WP_REST_Request $request ) {
		$id = (int) $request['id'];
		$cart = WCCR_Cart::get( $id );
		if ( ! $cart ) {
			return new WP_Error( 'wccr_not_found', __( 'Cart not found', 'fixo-cart' ), array( 'status' => 404 ) );
		}
		$events = WCCR_Cart::get_events( $id );
		foreach ( $events as &$event ) {
			$event['event_data_decoded'] = ! empty( $event['event_data'] ) ? json_decode( $event['event_data'], true ) : array();
		}
		return rest_ensure_response( array( 'cart' => $this->prepare_cart_for_response( $cart ), 'events' => $events ) );
	}

	public function get_utm_analytics( WP_REST_Request $request ) {
		$days = max( 1, min( 365, (int) ( $request->get_param( 'days' ) ?: 30 ) ) );
		return rest_ensure_response( array( 'days' => $days, 'rows' => WCCR_Cart::get_utm_analytics( $days, 100 ) ) );
	}

	public function get_revenue_analytics( WP_REST_Request $request ) {
		$days = max( 1, min( 365, (int) ( $request->get_param( 'days' ) ?: 30 ) ) );
		return rest_ensure_response( array_merge( array( 'days' => $days ), WCCR_Cart::get_revenue_analytics( $days ) ) );
	}

	public function get_customer_segments( WP_REST_Request $request ) {
		$days = max( 1, min( 365, (int) ( $request->get_param( 'days' ) ?: 30 ) ) );
		return rest_ensure_response( array( 'days' => $days, 'segments' => WCCR_Cart::get_customer_segments( $days ) ) );
	}

	public function get_product_analytics( WP_REST_Request $request ) {
		$days = max( 1, min( 365, (int) ( $request->get_param( 'days' ) ?: 30 ) ) );
		$limit = max( 1, min( 200, (int) ( $request->get_param( 'limit' ) ?: 50 ) ) );
		return rest_ensure_response( array( 'days' => $days, 'products' => WCCR_Cart::get_product_analytics( $days, $limit ) ) );
	}

	public function get_alerts( WP_REST_Request $request ) { return rest_ensure_response( WCCR_Cart::get_alerts( (int) ( $request->get_param( 'limit' ) ?: 25 ) ) ); }

	/** Settings → "Send test event" buttons. */
	public function test_tracking( WP_REST_Request $request ) {
		$channel = sanitize_key( (string) $request->get_param( 'channel' ) );
		// Test with the values currently in the form, even before they are saved.
		$overrides = $request->get_param( 'settings' );
		if ( is_array( $overrides ) ) {
			WCCR_Settings::save_all( $overrides );
		}
		$result = WCCR_Tracking::send_test( $channel );
		if ( empty( $result['ok'] ) ) {
			return new WP_Error( 'wccr_tracking_test_failed', $result['message'], array( 'status' => 400 ) );
		}
		return rest_ensure_response( $result );
	}

	public function get_settings() {
		return rest_ensure_response( WCCR_Settings::get_all() );
	}

	public function save_settings( WP_REST_Request $request ) {
		WCCR_Settings::save_all( $request->get_json_params() );
		return rest_ensure_response( WCCR_Settings::get_all() );
	}

	/**
	 * Shape a raw DB row for the dashboard: decode JSON, add computed convenience fields.
	 */
	/** HPOS-safe order edit URL (post.php?post=ID is wrong when High-Performance Order Storage is on). */
	public static function order_edit_url( $order_id ) {
		$order = wc_get_order( $order_id );
		if ( $order && method_exists( $order, 'get_edit_order_url' ) ) {
			return $order->get_edit_order_url();
		}
		return admin_url( 'post.php?post=' . absint( $order_id ) . '&action=edit' );
	}

	private function prepare_cart_for_response( $cart, $with_events = false ) {
		$cart['cart_contents'] = json_decode( (string) $cart['cart_contents'], true ) ?: array();
		$cart['emails_sent']   = (int) $cart['emails_sent'];
		$cart['consent']       = (int) $cart['consent'];
		$cart['ship_to_different'] = (int) $cart['ship_to_different'];
		$cart['id']            = (int) $cart['id'];
		$cart['user_id']       = (int) $cart['user_id'];
		$cart['cart_total']    = (float) $cart['cart_total'];
		$cart['order_id']      = $cart['order_id'] ? (int) $cart['order_id'] : null;
		$cart['order_edit_link'] = $cart['order_id'] ? self::order_edit_url( $cart['order_id'] ) : null;
		$cart['tracking_data'] = WCCR_Tracking::decode( $cart['tracking_data'] ?? '' );
		$cart['source_label']  = WCCR_Tracking::source_label( array_merge( $cart, array( 'tracking_data' => wp_json_encode( $cart['tracking_data'] ) ) ) );
		foreach ( array( 'billing', 'shipping' ) as $type ) {
			$cart[ $type . '_state_name' ]   = WCCR_Cart::state_name( $cart[ $type . '_country' ] ?? '', $cart[ $type . '_state' ] ?? '' );
			$cart[ $type . '_country_name' ] = WCCR_Cart::country_name( $cart[ $type . '_country' ] ?? '' );
		}
		$cart['full_name']     = trim( ( $cart['first_name'] ?? '' ) . ' ' . ( $cart['last_name'] ?? '' ) );

		// Presence is calculated server-side so the dashboard does not depend on the
		// admin browser's timezone. Heartbeats update updated_at every 30 seconds.
		// $last_seen_ts is a real Unix timestamp, so compare it with time(). Comparing with
		// current_time('timestamp') (offset by the site timezone) made every cart look 6h old in Dhaka.
		$last_seen_ts = WCCR_Cart::to_timestamp( $cart['updated_at'] ?? '' );
		$now_ts       = time();
		$age          = $last_seen_ts ? max( 0, $now_ts - $last_seen_ts ) : PHP_INT_MAX;
		if ( $age <= 75 ) {
			$presence_status = 'live';
		} elseif ( $age >= 3 * HOUR_IN_SECONDS ) {
			$presence_status = 'inactive';
		} else {
			$presence_status = 'away';
		}
		$cart['presence_status'] = $presence_status;

		// Entry / Exit: entry = first page view of the visit (falls back to cart creation),
		// exit = last shopper activity (heartbeat / field typing / cart change).
		$created_ts = WCCR_Cart::to_timestamp( $cart['created_at'] ?? '' );
		$entry_ts   = $created_ts;
		$cookie_ts  = isset( $cart['tracking_data']['entry_ts'] ) ? (int) $cart['tracking_data']['entry_ts'] : 0;
		// The source cookie lives 30 days; only trust it when it belongs to this visit.
		if ( $cookie_ts && $created_ts && $cookie_ts <= $created_ts && ( $created_ts - $cookie_ts ) <= 6 * HOUR_IN_SECONDS ) {
			$entry_ts = $cookie_ts;
		}
		$exit_ts = $last_seen_ts ? max( $last_seen_ts, $entry_ts ) : 0;
		$cart['entry_at']     = $entry_ts ? wp_date( 'Y-m-d H:i:s', $entry_ts ) : null;
		$cart['exit_at']      = ( $exit_ts && 'live' !== $presence_status ) ? wp_date( 'Y-m-d H:i:s', $exit_ts ) : null;
		$cart['time_on_site'] = ( $entry_ts && $exit_ts ) ? (int) ( $exit_ts - $entry_ts ) : null;
		$cart['presence_age']    = $last_seen_ts ? (int) $age : null;

		if ( $with_events ) {
			$cart['events'] = WCCR_Cart::get_events( $cart['id'] );
		}

		return $cart;
	}
}
