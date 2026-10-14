<?php
/**
 * Telegram + WhatsApp notifications.
 *
 * Events (each can go to Telegram, WhatsApp, both or neither — see WCCR_Settings::default_events()):
 *   lead_captured     A shopper typed a phone number and/or email on checkout (rule: lead_trigger).
 *   new_order         A WooCommerce order was placed (classic, block checkout or created in wp-admin).
 *   order_processing / order_completed / order_cancelled (cancelled + failed) / order_refunded
 *   cart_abandoned    A cart with contact details became abandoned.
 *   cart_high_value   Same, but the cart total is above the high-value threshold (replaces cart_abandoned).
 *   cart_recovered    An admin turned a cart into an order from the dashboard.
 *
 * Messages are sent in the background (Action Scheduler when available, WP-Cron otherwise) so a
 * slow Telegram/WhatsApp API never delays the shopper's checkout.
 *
 * @package Fixo_Cart
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class WCCR_Notifications {

	const ASYNC_HOOK = 'wccr_send_notification';
	const LEAD_HOOK  = 'wccr_lead_notification';
	const LOG_OPTION = 'wccr_notify_log';
	const LOG_SIZE   = 40;

	/** @var array|null Per-request settings cache. */
	private static $settings = null;

	public function __construct() {
		add_action( self::ASYNC_HOOK, array( __CLASS__, 'run' ), 10, 2 );
		add_action( self::LEAD_HOOK, array( __CLASS__, 'run_lead' ), 10, 1 );

		// Orders.
		add_action( 'woocommerce_new_order', array( $this, 'on_new_order' ), 20, 1 );
		add_action( 'woocommerce_checkout_order_processed', array( $this, 'on_new_order' ), 20, 1 );
		add_action( 'woocommerce_store_api_checkout_order_processed', array( $this, 'on_block_order' ), 20, 1 );
		add_action( 'woocommerce_order_status_changed', array( $this, 'on_status_changed' ), 20, 3 );

		// Carts.
		add_action( 'wccr_cart_contact_captured', array( __CLASS__, 'on_contact_captured' ), 10, 1 );
		add_action( 'wccr_cart_abandoned', array( $this, 'on_cart_abandoned' ), 10, 1 );
		add_action( 'wccr_cart_converted_to_order', array( $this, 'on_cart_recovered' ), 20, 2 );

		add_action( 'rest_api_init', array( $this, 'register_routes' ) );
	}

	/* ------------------------------------------------------------------
	 * Settings helpers
	 * ---------------------------------------------------------------- */

	public static function settings( $fresh = false ) {
		if ( $fresh || null === self::$settings ) {
			self::$settings = WCCR_Settings::get_all();
		}
		return self::$settings;
	}

	/** Which group an event belongs to: 'cart' or 'order' (the per-channel "send for" scope). */
	public static function event_group( $event ) {
		return in_array( $event, array( 'lead_captured', 'cart_abandoned', 'cart_high_value', 'cart_recovered' ), true ) ? 'cart' : 'order';
	}

	/** Does a channel's scope setting (cart / order / both) allow this event? */
	public static function scope_allows( $scope, $event ) {
		return ! in_array( $scope, array( 'cart', 'order' ), true ) || self::event_group( $event ) === $scope;
	}

	/** Channels that are switched on, configured, in scope and enabled for this event. */
	public static function channels_for( $event ) {
		$s        = self::settings();
		$matrix   = isset( $s['notify_events'][ $event ] ) ? $s['notify_events'][ $event ] : array();
		$channels = array();
		if ( 'yes' === $s['notify_telegram_enabled'] && self::scope_allows( $s['telegram_scope'], $event ) && isset( $matrix['telegram'] ) && 'yes' === $matrix['telegram'] && self::telegram_configured( $s ) ) {
			$channels[] = 'telegram';
		}
		if ( 'yes' === $s['notify_whatsapp_enabled'] && self::scope_allows( $s['whatsapp_scope'], $event ) && isset( $matrix['whatsapp'] ) && 'yes' === $matrix['whatsapp'] && self::whatsapp_configured( $s ) ) {
			$channels[] = 'whatsapp';
		}
		return $channels;
	}

	public static function telegram_configured( $s ) {
		return '' !== trim( (string) $s['telegram_bot_token'] ) && self::split_list( $s['telegram_chat_ids'] );
	}

	public static function whatsapp_configured( $s ) {
		switch ( $s['whatsapp_provider'] ) {
			case 'callmebot':
				return (bool) self::callmebot_recipients( $s );
			case 'cloud_api':
				return $s['whatsapp_cloud_phone_id'] && $s['whatsapp_cloud_token'] && self::split_list( $s['whatsapp_recipients'] );
			case 'custom':
				return $s['whatsapp_custom_url'] && self::split_list( $s['whatsapp_recipients'] );
		}
		return false;
	}

	/** "a, b\nc" → array( 'a', 'b', 'c' ). */
	public static function split_list( $value ) {
		return array_values( array_filter( array_map( 'trim', preg_split( '/[\s,;]+/', (string) $value ) ) ) );
	}

	/** CallMeBot needs an API key per phone: one "phone apikey" pair per line. */
	public static function callmebot_recipients( $s ) {
		$out = array();
		foreach ( preg_split( '/\r\n|\r|\n/', (string) $s['whatsapp_callmebot_recipients'] ) as $line ) {
			$parts = array_values( array_filter( array_map( 'trim', preg_split( '/[\s,;|:]+/', $line ) ) ) );
			if ( count( $parts ) >= 2 ) {
				$out[] = array( 'phone' => self::normalize_phone( $parts[0] ), 'apikey' => $parts[1] );
			}
		}
		return array_values( array_filter( $out, function ( $r ) { return '' !== $r['phone']; } ) );
	}

	/**
	 * International digits without "+", e.g. 01712345678 → 8801712345678 (Bangladesh-local
	 * numbers are expanded; other numbers must already include their country code).
	 */
	public static function normalize_phone( $phone ) {
		$phone = ltrim( preg_replace( '/[^0-9+]/', '', (string) $phone ), '+' );
		if ( 0 === strpos( $phone, '00' ) ) {
			$phone = substr( $phone, 2 );
		}
		if ( 11 === strlen( $phone ) && 0 === strpos( $phone, '01' ) ) {
			$phone = '88' . $phone;
		} elseif ( 10 === strlen( $phone ) && 0 === strpos( $phone, '1' ) ) {
			$phone = '880' . $phone;
		}
		return strlen( $phone ) >= 8 ? $phone : '';
	}

	/* ------------------------------------------------------------------
	 * Queueing
	 * ---------------------------------------------------------------- */

	/**
	 * Queue an event for background delivery.
	 *
	 * @param string $event Event id.
	 * @param int    $ref   Order id or cart id, depending on the event.
	 */
	public static function dispatch( $event, $ref ) {
		if ( ! self::channels_for( $event ) ) {
			return;
		}
		$args = array( $event, (int) $ref );
		/**
		 * Return true to send right away (inside the current request) instead of in the background.
		 */
		if ( apply_filters( 'fixo_cart_send_notifications_immediately', false, $event, $ref ) ) {
			self::run( $event, (int) $ref );
			return;
		}
		if ( function_exists( 'as_enqueue_async_action' ) ) {
			as_enqueue_async_action( self::ASYNC_HOOK, $args, 'fixo-cart' );
		} else {
			wp_schedule_single_event( time(), self::ASYNC_HOOK, $args );
		}
	}

	/** Background worker: build the message now (freshest data) and send it. */
	public static function run( $event, $ref ) {
		self::settings( true );
		$message = self::build( $event, (int) $ref );
		if ( ! $message ) {
			return;
		}
		$channels = self::channels_for( $event );
		if ( ! empty( $message['skip_channels'] ) ) {
			$channels = array_diff( $channels, $message['skip_channels'] );
		}
		foreach ( $channels as $channel ) {
			$result = self::send( $channel, $message );
			self::log( $event, $channel, $result, $message['title'] );
		}
	}

	/* ------------------------------------------------------------------
	 * Event listeners
	 * ---------------------------------------------------------------- */

	public function on_new_order( $order_id ) {
		$order = wc_get_order( $order_id );
		// Block checkout creates a "checkout-draft" order while the shopper is still typing.
		if ( ! $order || 'checkout-draft' === $order->get_status() ) {
			return;
		}
		$key = 'wccr_ntf_order_' . (int) $order_id;
		if ( get_transient( $key ) ) {
			return; // new_order and checkout_order_processed both fire for one classic checkout.
		}
		set_transient( $key, 1, DAY_IN_SECONDS );
		self::dispatch( 'new_order', $order_id );
	}

	public function on_block_order( $order ) {
		if ( $order instanceof WC_Order ) {
			$this->on_new_order( $order->get_id() );
		}
	}

	public function on_status_changed( $order_id, $from, $to ) {
		$map = array(
			'processing' => 'order_processing',
			'completed'  => 'order_completed',
			'cancelled'  => 'order_cancelled',
			'failed'     => 'order_cancelled',
			'refunded'   => 'order_refunded',
		);
		if ( isset( $map[ $to ] ) && 'checkout-draft' !== $from ) {
			self::dispatch( $map[ $to ], $order_id );
		}
	}

	/**
	 * A checkout field with a phone or email was saved. Notify once per cart, as soon as the
	 * cart matches the "notify when" rule (phone / email / either / both).
	 */
	public static function on_contact_captured( $cart_id ) {
		if ( ! self::channels_for( 'lead_captured' ) ) {
			return;
		}
		$cart = WCCR_Cart::get( (int) $cart_id );
		if ( ! $cart || ! empty( $cart['order_id'] ) || 'converted' === $cart['status'] || 'bot' === $cart['risk_status'] ) {
			return;
		}
		if ( ! self::lead_qualifies( $cart, self::settings() ) ) {
			return;
		}
		if ( WCCR_Cart::get_latest_event( $cart['id'], 'lead_notified' ) ) {
			return;
		}
		$delay = (int) self::settings()['lead_delay_minutes'];
		WCCR_Cart::log_event( $cart['id'], 'lead_notified', array( 'delay_minutes' => $delay ) );
		if ( $delay > 0 ) {
			$when = time() + $delay * MINUTE_IN_SECONDS;
			if ( function_exists( 'as_schedule_single_action' ) ) {
				as_schedule_single_action( $when, self::LEAD_HOOK, array( (int) $cart['id'] ), 'fixo-cart' );
			} else {
				wp_schedule_single_event( $when, self::LEAD_HOOK, array( (int) $cart['id'] ) );
			}
			return;
		}
		self::dispatch( 'lead_captured', $cart['id'] );
	}

	/** Delayed lead check: only alert if the shopper still has not ordered. */
	public static function run_lead( $cart_id ) {
		$cart = WCCR_Cart::get( (int) $cart_id );
		if ( ! $cart ) {
			return;
		}
		if ( ! empty( $cart['order_id'] ) || 'converted' === $cart['status'] ) {
			self::log( 'lead_captured', '—', array( 'ok' => true, 'message' => __( 'Skipped: the customer completed the order before the delay ended.', 'fixo-cart' ) ), '#' . $cart['id'] );
			return;
		}
		self::run( 'lead_captured', (int) $cart_id );
	}

	/**
	 * @param array $cart Cart row.
	 * @param array $s    Settings.
	 */
	public static function lead_qualifies( $cart, $s ) {
		$digits    = strlen( preg_replace( '/\D+/', '', (string) $cart['phone'] ) );
		$has_phone = $digits >= max( 6, (int) $s['lead_min_phone_digits'] );
		$has_email = (bool) is_email( (string) $cart['email'] );
		switch ( $s['lead_trigger'] ) {
			case 'phone':
				return $has_phone;
			case 'email':
				return $has_email;
			case 'both':
				return $has_phone && $has_email;
			default:
				return $has_phone || $has_email;
		}
	}

	public function on_cart_abandoned( $cart ) {
		if ( ! is_array( $cart ) || 'duplicate' === $cart['risk_status'] || 'bot' === $cart['risk_status'] ) {
			return;
		}
		// An abandoned cart nobody can contact is noise on a phone.
		if ( '' === trim( (string) $cart['phone'] ) && ! is_email( (string) $cart['email'] ) ) {
			return;
		}
		$threshold = (float) self::settings()['alert_high_value_threshold'];
		if ( $threshold > 0 && (float) $cart['cart_total'] >= $threshold && self::channels_for( 'cart_high_value' ) ) {
			self::dispatch( 'cart_high_value', $cart['id'] );
		} else {
			self::dispatch( 'cart_abandoned', $cart['id'] );
		}
	}

	public function on_cart_recovered( $order_id, $cart ) {
		self::dispatch( 'cart_recovered', $order_id );
	}

	/* ------------------------------------------------------------------
	 * Message building
	 * ---------------------------------------------------------------- */

	public static function event_labels() {
		return array(
			'lead_captured'    => __( 'New checkout lead', 'fixo-cart' ),
			'new_order'        => __( 'New order', 'fixo-cart' ),
			'order_processing' => __( 'Order processing', 'fixo-cart' ),
			'order_completed'  => __( 'Order completed', 'fixo-cart' ),
			'order_cancelled'  => __( 'Order cancelled / failed', 'fixo-cart' ),
			'order_refunded'   => __( 'Order refunded', 'fixo-cart' ),
			'cart_abandoned'   => __( 'Cart abandoned', 'fixo-cart' ),
			'cart_high_value'  => __( 'High-value cart abandoned', 'fixo-cart' ),
			'cart_recovered'   => __( 'Cart recovered by admin', 'fixo-cart' ),
		);
	}

	/**
	 * A channel-neutral message:
	 * array( icon, title, fields => [ [label, value] ], items => [string], totals => [ [label, value] ], link => [label, url] )
	 *
	 * @return array|null Null when there is nothing to send.
	 */
	public static function build( $event, $ref ) {
		$labels = self::event_labels();
		if ( 0 === strpos( $event, 'order_' ) || 'new_order' === $event || 'cart_recovered' === $event ) {
			$order = wc_get_order( $ref );
			if ( ! $order ) {
				return null;
			}
			return self::build_order_message( $event, $order, $labels[ $event ] );
		}
		$cart = WCCR_Cart::get( $ref );
		if ( ! $cart ) {
			return null;
		}
		return self::build_cart_message( $event, $cart, $labels[ $event ] );
	}

	private static function money( $amount, $currency = '' ) {
		$text = wp_strip_all_tags( html_entity_decode( wc_price( (float) $amount, $currency ? array( 'currency' => $currency ) : array() ), ENT_QUOTES, 'UTF-8' ) );
		return trim( str_replace( "\xc2\xa0", ' ', $text ) );
	}

	private static function dashboard_url( $args = array() ) {
		return add_query_arg( $args, admin_url( 'admin.php?page=' . WCCR_Admin_Slug::SLUG ) );
	}

	public static function build_order_message( $event, WC_Order $order, $label ) {
		$icons = array(
			'new_order'        => '🛒',
			'order_processing' => '⚙️',
			'order_completed'  => '✅',
			'order_cancelled'  => '❌',
			'order_refunded'   => '↩️',
			'cart_recovered'   => '🎉',
		);
		$is_admin_recovery = 'admin_recovery' === $order->get_meta( '_wccr_conversion_type' );
		$skip              = array();
		// A recovered cart already produces a "Cart recovered" message on the same channel.
		if ( 'new_order' === $event && $is_admin_recovery ) {
			$skip = self::channels_for( 'cart_recovered' );
		}

		$name    = trim( $order->get_billing_first_name() . ' ' . $order->get_billing_last_name() );
		$address = self::address_line(
			array(
				$order->get_billing_address_1(),
				$order->get_billing_address_2(),
				$order->get_billing_city(),
				WCCR_Cart::state_name( $order->get_billing_country(), $order->get_billing_state() ),
				$order->get_billing_postcode(),
				WCCR_Cart::country_name( $order->get_billing_country() ),
			)
		);
		$title = 'new_order' === $event
			/* translators: %s: order number */
			? sprintf( __( 'New order #%s', 'fixo-cart' ), $order->get_order_number() )
			: ( 'cart_recovered' === $event
				/* translators: %s: order number */
				? sprintf( __( 'Cart recovered — order #%s', 'fixo-cart' ), $order->get_order_number() )
				/* translators: 1: order number, 2: status name */
				: sprintf( __( 'Order #%1$s is now %2$s', 'fixo-cart' ), $order->get_order_number(), wc_get_order_status_name( $order->get_status() ) ) );

		$fields = array(
			array( __( 'Customer', 'fixo-cart' ), $name ? $name : __( 'Guest', 'fixo-cart' ) ),
			array( __( 'Phone', 'fixo-cart' ), $order->get_billing_phone() ),
			array( __( 'Email', 'fixo-cart' ), $order->get_billing_email() ),
		);
		$items  = array();
		$totals = array();
		if ( in_array( $event, array( 'new_order', 'cart_recovered' ), true ) ) {
			$fields[] = array( __( 'Address', 'fixo-cart' ), $address );
			foreach ( $order->get_items() as $item ) {
				$items[] = $item->get_name() . ' × ' . $item->get_quantity() . ' — ' . self::money( $order->get_line_total( $item, true ), $order->get_currency() );
			}
			$totals[] = array( __( 'Subtotal', 'fixo-cart' ), self::money( $order->get_subtotal(), $order->get_currency() ) );
			if ( (float) $order->get_shipping_total() > 0 ) {
				$totals[] = array( __( 'Shipping', 'fixo-cart' ), self::money( $order->get_shipping_total(), $order->get_currency() ) );
			}
			if ( (float) $order->get_discount_total() > 0 ) {
				$totals[] = array( __( 'Discount', 'fixo-cart' ), '−' . self::money( $order->get_discount_total(), $order->get_currency() ) );
			}
		}
		$totals[] = array( __( 'Total', 'fixo-cart' ), self::money( $order->get_total(), $order->get_currency() ) );
		$fields[] = array( __( 'Payment', 'fixo-cart' ), $order->get_payment_method_title() );
		$fields[] = array( __( 'Status', 'fixo-cart' ), wc_get_order_status_name( $order->get_status() ) );
		$source   = $order->get_meta( '_wccr_source_label' );
		if ( $source ) {
			$fields[] = array( __( 'Source', 'fixo-cart' ), $source );
		}
		if ( $is_admin_recovery ) {
			$by       = get_user_by( 'id', (int) $order->get_meta( '_wccr_converted_by_user_id' ) );
			$fields[] = array( __( 'Recovered by', 'fixo-cart' ), $by ? $by->display_name : __( 'Admin', 'fixo-cart' ) );
		}
		if ( $order->get_customer_note() ) {
			$fields[] = array( __( 'Note', 'fixo-cart' ), $order->get_customer_note() );
		}

		return array(
			'event'         => $event,
			'icon'          => isset( $icons[ $event ] ) ? $icons[ $event ] : '🔔',
			'title'         => $title,
			'fields'        => $fields,
			'items'         => $items,
			'totals'        => $totals,
			'link'          => array( __( 'Open order', 'fixo-cart' ), WCCR_REST_API::order_edit_url( $order->get_id() ) ),
			'skip_channels' => $skip,
		);
	}

	public static function build_cart_message( $event, array $cart, $label ) {
		$icons = array(
			'lead_captured'   => '📝',
			'cart_abandoned'  => '⏳',
			'cart_high_value' => '🔥',
		);
		$name     = trim( $cart['first_name'] . ' ' . $cart['last_name'] );
		$contents = json_decode( (string) $cart['cart_contents'], true );
		$items    = array();
		foreach ( is_array( $contents ) ? $contents : array() as $it ) {
			$variation = '';
			if ( ! empty( $it['variation'] ) && is_array( $it['variation'] ) ) {
				$variation = ' (' . implode( ' / ', array_filter( array_map( 'strval', $it['variation'] ) ) ) . ')';
			}
			$items[] = ( isset( $it['name'] ) ? $it['name'] : '#' . ( isset( $it['product_id'] ) ? $it['product_id'] : '' ) ) . $variation . ' × ' . ( isset( $it['quantity'] ) ? (int) $it['quantity'] : 1 );
		}
		$address = self::address_line(
			array(
				$cart['billing_address_1'],
				$cart['billing_city'],
				WCCR_Cart::state_name( $cart['billing_country'], $cart['billing_state'] ),
				WCCR_Cart::country_name( $cart['billing_country'] ),
			)
		);

		$fields = array(
			array( __( 'Customer', 'fixo-cart' ), $name ? $name : __( 'Not entered yet', 'fixo-cart' ) ),
			array( __( 'Phone', 'fixo-cart' ), $cart['phone'] ),
			array( __( 'Email', 'fixo-cart' ), $cart['email'] ),
			array( __( 'Address', 'fixo-cart' ), $address ),
			array( __( 'Source', 'fixo-cart' ), WCCR_Tracking::source_label( $cart ) ),
		);
		if ( 'lead_captured' !== $event ) {
			$fields[] = array( __( 'Recovery emails sent', 'fixo-cart' ), (string) (int) $cart['emails_sent'] );
		}

		$titles = array(
			/* translators: %d: cart id */
			'lead_captured'   => __( 'New checkout lead · cart #%d', 'fixo-cart' ),
			/* translators: %d: cart id */
			'cart_abandoned'  => __( 'Cart #%d abandoned', 'fixo-cart' ),
			/* translators: %d: cart id */
			'cart_high_value' => __( 'High-value cart #%d abandoned', 'fixo-cart' ),
		);

		return array(
			'event'  => $event,
			'icon'   => isset( $icons[ $event ] ) ? $icons[ $event ] : '🔔',
			'title'  => sprintf( $titles[ $event ], (int) $cart['id'] ),
			'fields' => $fields,
			'items'  => $items,
			'totals' => array( array( __( 'Cart total', 'fixo-cart' ), self::money( $cart['cart_total'], $cart['currency'] ) ) ),
			'link'   => array( __( 'Open cart', 'fixo-cart' ), self::dashboard_url( array( 'cart' => (int) $cart['id'] ) ) ),
			'phone'  => $cart['phone'],
		);
	}

	private static function address_line( $parts ) {
		return implode( ', ', array_unique( array_filter( array_map( 'trim', array_map( 'strval', $parts ) ) ) ) );
	}

	private static function store_name() {
		$name = trim( (string) self::settings()['notify_store_name'] );
		return $name ? $name : wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES );
	}

	/** Telegram HTML. */
	public static function render_telegram( array $m ) {
		$e   = function ( $v ) { return htmlspecialchars( (string) $v, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8' ); };
		$out = $m['icon'] . ' <b>' . $e( $m['title'] ) . '</b>' . "\n" . '<i>' . $e( self::store_name() ) . '</i>' . "\n\n";
		foreach ( $m['fields'] as $f ) {
			if ( '' !== trim( (string) $f[1] ) ) {
				$out .= '<b>' . $e( $f[0] ) . ':</b> ' . $e( $f[1] ) . "\n";
			}
		}
		if ( ! empty( $m['items'] ) ) {
			$out .= "\n" . '<b>' . $e( __( 'Products', 'fixo-cart' ) ) . '</b>' . "\n";
			foreach ( $m['items'] as $item ) {
				$out .= '• ' . $e( $item ) . "\n";
			}
		}
		if ( ! empty( $m['totals'] ) ) {
			$out .= "\n";
			foreach ( $m['totals'] as $t ) {
				$out .= $e( $t[0] ) . ': <b>' . $e( $t[1] ) . '</b>' . "\n";
			}
		}
		if ( ! empty( $m['link'][1] ) ) {
			$out .= "\n" . '<a href="' . $e( $m['link'][1] ) . '">' . $e( $m['link'][0] ) . ' →</a>';
		}
		return self::truncate( $out, 4000 );
	}

	/** WhatsApp plain text with *bold*. */
	public static function render_whatsapp( array $m ) {
		$out = $m['icon'] . ' *' . $m['title'] . "*\n_" . self::store_name() . "_\n\n";
		foreach ( $m['fields'] as $f ) {
			if ( '' !== trim( (string) $f[1] ) ) {
				$out .= '*' . $f[0] . ':* ' . $f[1] . "\n";
			}
		}
		if ( ! empty( $m['items'] ) ) {
			$out .= "\n*" . __( 'Products', 'fixo-cart' ) . "*\n";
			foreach ( $m['items'] as $item ) {
				$out .= '• ' . $item . "\n";
			}
		}
		if ( ! empty( $m['totals'] ) ) {
			$out .= "\n";
			foreach ( $m['totals'] as $t ) {
				$out .= $t[0] . ': *' . $t[1] . "*\n";
			}
		}
		if ( ! empty( $m['link'][1] ) ) {
			$out .= "\n" . $m['link'][0] . ': ' . $m['link'][1];
		}
		return self::truncate( $out, 3800 );
	}

	private static function truncate( $text, $max ) {
		return function_exists( 'mb_strlen' ) && mb_strlen( $text ) > $max ? mb_substr( $text, 0, $max - 1 ) . '…' : $text;
	}

	/* ------------------------------------------------------------------
	 * Delivery
	 * ---------------------------------------------------------------- */

	/**
	 * @return array{ok: bool, message: string}
	 */
	public static function send( $channel, array $message ) {
		$s = self::settings();
		if ( 'telegram' === $channel ) {
			return self::send_telegram( self::render_telegram( $message ), $s );
		}
		if ( 'whatsapp' === $channel ) {
			return self::send_whatsapp( self::render_whatsapp( $message ), $s );
		}
		return array( 'ok' => false, 'message' => 'Unknown channel' );
	}

	public static function send_telegram( $html, $s ) {
		$token = trim( (string) $s['telegram_bot_token'] );
		$chats = self::split_list( $s['telegram_chat_ids'] );
		if ( ! $token || ! $chats ) {
			return array( 'ok' => false, 'message' => __( 'Add the bot token and at least one chat ID.', 'fixo-cart' ) );
		}
		if ( ! preg_match( '/^[0-9]+:[A-Za-z0-9_-]+$/', $token ) ) {
			return array( 'ok' => false, 'message' => __( 'The bot token does not look right. It should look like 123456789:AA…', 'fixo-cart' ) );
		}
		$errors = array();
		foreach ( $chats as $chat ) {
			$r = wp_remote_post(
				'https://api.telegram.org/bot' . $token . '/sendMessage',
				array(
					'timeout' => 15,
					'body'    => array(
						'chat_id'                  => $chat,
						'text'                     => $html,
						'parse_mode'               => 'HTML',
						'disable_web_page_preview' => 'true',
					),
				)
			);
			if ( is_wp_error( $r ) ) {
				$errors[] = $chat . ': ' . $r->get_error_message();
				continue;
			}
			$body = json_decode( wp_remote_retrieve_body( $r ), true );
			if ( 200 !== (int) wp_remote_retrieve_response_code( $r ) || empty( $body['ok'] ) ) {
				$errors[] = $chat . ': ' . ( isset( $body['description'] ) ? $body['description'] : 'HTTP ' . wp_remote_retrieve_response_code( $r ) );
			}
		}
		return self::result( count( $chats ), $errors );
	}

	public static function send_whatsapp( $text, $s ) {
		$errors = array();
		$count  = 0;
		switch ( $s['whatsapp_provider'] ) {
			case 'callmebot':
				$recipients = self::callmebot_recipients( $s );
				$count      = count( $recipients );
				foreach ( $recipients as $r ) {
					$url  = add_query_arg(
						array(
							'phone'  => '+' . $r['phone'],
							'text'   => rawurlencode( $text ),
							'apikey' => rawurlencode( $r['apikey'] ),
						),
						'https://api.callmebot.com/whatsapp.php'
					);
					$resp = wp_remote_get( $url, array( 'timeout' => 20 ) );
					$err  = self::http_error( $resp );
					// CallMeBot answers 200 with an HTML page even for a wrong API key.
					if ( ! $err && preg_match( '/(error|invalid|not\s+allowed)/i', wp_strip_all_tags( wp_remote_retrieve_body( $resp ) ) ) ) {
						$err = trim( wp_html_excerpt( wp_strip_all_tags( wp_remote_retrieve_body( $resp ) ), 160 ) );
					}
					if ( $err ) {
						$errors[] = $r['phone'] . ': ' . $err;
					}
				}
				break;

			case 'cloud_api':
				$recipients = array_filter( array_map( array( __CLASS__, 'normalize_phone' ), self::split_list( $s['whatsapp_recipients'] ) ) );
				$count      = count( $recipients );
				$endpoint   = 'https://graph.facebook.com/v20.0/' . rawurlencode( trim( $s['whatsapp_cloud_phone_id'] ) ) . '/messages';
				foreach ( $recipients as $phone ) {
					if ( $s['whatsapp_cloud_template'] ) {
						// Outside the 24-hour window Meta only delivers approved templates. The template
						// needs one body variable ({{1}}); parameters cannot contain line breaks.
						$payload = array(
							'messaging_product' => 'whatsapp',
							'to'                => $phone,
							'type'              => 'template',
							'template'          => array(
								'name'       => trim( $s['whatsapp_cloud_template'] ),
								'language'   => array( 'code' => $s['whatsapp_cloud_template_lang'] ? trim( $s['whatsapp_cloud_template_lang'] ) : 'en_US' ),
								'components' => array(
									array(
										'type'       => 'body',
										'parameters' => array( array( 'type' => 'text', 'text' => self::truncate( preg_replace( '/\s*\n+\s*/', ' · ', $text ), 1000 ) ) ),
									),
								),
							),
						);
					} else {
						$payload = array(
							'messaging_product' => 'whatsapp',
							'to'                => $phone,
							'type'              => 'text',
							'text'              => array( 'preview_url' => false, 'body' => $text ),
						);
					}
					$resp = wp_remote_post(
						$endpoint,
						array(
							'timeout' => 20,
							'headers' => array(
								'Authorization' => 'Bearer ' . trim( $s['whatsapp_cloud_token'] ),
								'Content-Type'  => 'application/json',
							),
							'body'    => wp_json_encode( $payload ),
						)
					);
					$err = self::http_error( $resp );
					if ( $err ) {
						$body = json_decode( wp_remote_retrieve_body( $resp ), true );
						if ( isset( $body['error']['message'] ) ) {
							$err = $body['error']['message'];
						}
						$errors[] = $phone . ': ' . $err;
					}
				}
				break;

			case 'custom':
				$recipients = array_filter( array_map( array( __CLASS__, 'normalize_phone' ), self::split_list( $s['whatsapp_recipients'] ) ) );
				$count      = count( $recipients );
				foreach ( $recipients as $phone ) {
					$url = strtr( $s['whatsapp_custom_url'], array( '{phone}' => rawurlencode( $phone ), '{message}' => rawurlencode( $text ) ) );
					if ( 'post' === $s['whatsapp_custom_method'] ) {
						$body = strtr(
							(string) $s['whatsapp_custom_body'],
							array(
								'{phone}'   => substr( wp_json_encode( $phone ), 1, -1 ),
								'{message}' => substr( wp_json_encode( $text, JSON_UNESCAPED_UNICODE ), 1, -1 ),
							)
						);
						$is_json = null !== json_decode( $body );
						$resp    = wp_remote_post(
							$url,
							array(
								'timeout' => 20,
								'headers' => array( 'Content-Type' => $is_json ? 'application/json' : 'application/x-www-form-urlencoded' ),
								'body'    => $body,
							)
						);
					} else {
						$resp = wp_remote_get( $url, array( 'timeout' => 20 ) );
					}
					$err = self::http_error( $resp );
					if ( $err ) {
						$errors[] = $phone . ': ' . $err;
					}
				}
				break;
		}
		if ( ! $count ) {
			return array( 'ok' => false, 'message' => __( 'No WhatsApp recipients are configured.', 'fixo-cart' ) );
		}
		return self::result( $count, $errors );
	}

	private static function http_error( $resp ) {
		if ( is_wp_error( $resp ) ) {
			return $resp->get_error_message();
		}
		$code = (int) wp_remote_retrieve_response_code( $resp );
		return ( $code >= 200 && $code < 300 ) ? '' : 'HTTP ' . $code . ' ' . wp_html_excerpt( wp_strip_all_tags( wp_remote_retrieve_body( $resp ) ), 160 );
	}

	private static function result( $count, $errors ) {
		if ( ! $errors ) {
			/* translators: %d: number of recipients */
			return array( 'ok' => true, 'message' => sprintf( _n( 'Delivered to %d recipient.', 'Delivered to %d recipients.', $count, 'fixo-cart' ), $count ) );
		}
		return array( 'ok' => false, 'message' => implode( ' | ', $errors ) );
	}

	/* ------------------------------------------------------------------
	 * Delivery log (last LOG_SIZE sends) — shown on the Notifications screen.
	 * ---------------------------------------------------------------- */

	public static function log( $event, $channel, $result, $title = '' ) {
		$log = get_option( self::LOG_OPTION, array() );
		$log = is_array( $log ) ? $log : array();
		array_unshift(
			$log,
			array(
				'time'    => time(),
				'event'   => $event,
				'channel' => $channel,
				'title'   => $title,
				'ok'      => ! empty( $result['ok'] ),
				'message' => isset( $result['message'] ) ? (string) $result['message'] : '',
			)
		);
		update_option( self::LOG_OPTION, array_slice( $log, 0, self::LOG_SIZE ), false );
	}

	/* ------------------------------------------------------------------
	 * REST: test + log
	 * ---------------------------------------------------------------- */

	public function register_routes() {
		$perm = function () {
			return current_user_can( 'manage_woocommerce' );
		};
		register_rest_route(
			'wccr/v1',
			'/notifications/test',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'rest_test' ),
				'permission_callback' => $perm,
			)
		);
		register_rest_route(
			'wccr/v1',
			'/notifications/log',
			array(
				array(
					'methods'             => 'GET',
					'callback'            => array( $this, 'rest_log' ),
					'permission_callback' => $perm,
				),
				array(
					'methods'             => 'DELETE',
					'callback'            => array( $this, 'rest_clear_log' ),
					'permission_callback' => $perm,
				),
			)
		);
	}

	/** Sends a sample message with the settings currently on screen (they are saved first). */
	public function rest_test( WP_REST_Request $request ) {
		$channel   = sanitize_key( (string) $request->get_param( 'channel' ) );
		$overrides = $request->get_param( 'settings' );
		if ( is_array( $overrides ) ) {
			WCCR_Settings::save_all( $overrides );
		}
		$s = self::settings( true );
		if ( ! in_array( $channel, array( 'telegram', 'whatsapp' ), true ) ) {
			return new WP_Error( 'wccr_bad_channel', __( 'Unknown channel.', 'fixo-cart' ), array( 'status' => 400 ) );
		}
		$message = array(
			'event'  => 'test',
			'icon'   => '✅',
			'title'  => __( 'Fixo Cart test message', 'fixo-cart' ),
			'fields' => array(
				array( __( 'Channel', 'fixo-cart' ), 'telegram' === $channel ? 'Telegram' : 'WhatsApp (' . $s['whatsapp_provider'] . ')' ),
				array( __( 'Sent', 'fixo-cart' ), wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ) ) ),
			),
			'items'  => array(),
			'totals' => array(),
			'link'   => array( __( 'Open dashboard', 'fixo-cart' ), self::dashboard_url() ),
		);
		$result = self::send( $channel, $message );
		self::log( 'test', $channel, $result, $message['title'] );
		if ( empty( $result['ok'] ) ) {
			return new WP_Error( 'wccr_notify_test_failed', $result['message'], array( 'status' => 400 ) );
		}
		return rest_ensure_response( $result );
	}

	public function rest_log() {
		$log = get_option( self::LOG_OPTION, array() );
		return rest_ensure_response( array( 'items' => is_array( $log ) ? $log : array(), 'labels' => self::event_labels() ) );
	}

	public function rest_clear_log() {
		delete_option( self::LOG_OPTION );
		return rest_ensure_response( array( 'cleared' => true ) );
	}
}

/** Admin page slug shared by notifications (links) and the admin screen. */
class WCCR_Admin_Slug {
	const SLUG = 'fixo-cart';
}
