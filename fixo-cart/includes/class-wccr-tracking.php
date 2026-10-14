<?php
/**
 * Attribution + conversion events for orders created from an abandoned cart.
 *
 * Why this exists: when an admin converts a cart into an order (after a phone call / WhatsApp),
 * the customer never reaches the thank-you page, so the browser Meta Pixel / GA4 tag NEVER sees
 * the purchase and the ad campaign that brought the visitor gets no credit. This class:
 *
 *  1. Copies the full traffic source of the cart (landing page, referrer, every UTM, ad click IDs,
 *     device, IP, user agent) onto the WooCommerce order — both into our own _wccr_* meta and into
 *     WooCommerce's native Order Attribution fields (_wc_order_attribution_*), so the "Origin"
 *     column and the "Order attribution" box on the order screen show the real source.
 *  2. Sends a server-side Purchase event to Meta Conversions API (with fbp / fbc / hashed customer
 *     data so Meta can match it to the ad click), to GA4 Measurement Protocol and/or to any webhook
 *     (Zapier, Make, n8n, Google Sheets, your CRM …).
 *  3. Fires the `wccr_cart_converted_to_order` action so any other integration can hook in.
 *
 * @package Fixo_Cart
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class WCCR_Tracking {

	/** Results of the last dispatch in this request — returned to the dashboard for the toast. */
	public static $last_results = array();

	public function __construct() {
		add_action( 'wccr_cart_converted_to_order', array( $this, 'on_converted' ), 10, 3 );
		add_action( 'woocommerce_order_status_changed', array( $this, 'on_status_changed' ), 20, 4 );
	}

	/* ---------------------------------------------------------------------
	 * Browser identifiers (captured from the shopper's own requests)
	 * ------------------------------------------------------------------- */

	/** Click-id query parameters we keep from the landing URL. */
	public static function click_id_params() {
		return array( 'fbclid', 'gclid', 'gbraid', 'wbraid', 'ttclid', 'msclkid', 'twclid', 'li_fat_id' );
	}

	/**
	 * Merge the identifiers available in the current shopper request into the cart's tracking_data
	 * JSON (only writes when something changed, so it is cheap to call from the heartbeat).
	 *
	 * @param int        $cart_id Cart row id.
	 * @param array|null $row     Already-loaded row (saves a query).
	 */
	public static function refresh_for_cart( $cart_id, $row = null ) {
		if ( ! $cart_id ) {
			return;
		}
		if ( null === $row ) {
			$row = WCCR_Cart::get( $cart_id );
		}
		if ( ! $row ) {
			return;
		}

		$current = self::decode( $row['tracking_data'] ?? '' );
		$next    = $current;

		// Click ids from the first-touch source cookie (set on the landing page).
		$source = array();
		if ( ! empty( $_COOKIE[ WCCR_Source_Tracker::COOKIE_NAME ] ) ) {
			$source = json_decode( wp_unslash( $_COOKIE[ WCCR_Source_Tracker::COOKIE_NAME ] ), true );
			$source = is_array( $source ) ? $source : array();
		}
		foreach ( self::click_id_params() as $param ) {
			if ( empty( $next[ $param ] ) && ! empty( $source[ $param ] ) ) {
				$next[ $param ] = substr( sanitize_text_field( $source[ $param ] ), 0, 255 );
			}
		}

		// First page view of this visit (from the source cookie) = site entry time.
		if ( empty( $next['entry_ts'] ) && ! empty( $source['entry_ts'] ) ) {
			$next['entry_ts'] = (int) $source['entry_ts'];
		}

		// Meta browser ids. _fbp changes rarely; _fbc is the click id Meta needs for ad attribution.
		$fbp = self::cookie( '_fbp' );
		$fbc = self::cookie( '_fbc' );
		if ( $fbp ) {
			$next['fbp'] = $fbp;
		}
		if ( $fbc ) {
			$next['fbc'] = $fbc;
		} elseif ( empty( $next['fbc'] ) && ! empty( $next['fbclid'] ) ) {
			// No pixel on the site? Build fbc ourselves from fbclid (format documented by Meta).
			$next['fbc'] = 'fb.1.' . ( time() * 1000 ) . '.' . $next['fbclid'];
		}

		// GA4 client id from the _ga cookie: "GA1.1.123456789.1700000000" -> "123456789.1700000000".
		$ga = self::cookie( '_ga' );
		if ( $ga && preg_match( '/^GA\d\.\d\.(\d+\.\d+)$/', $ga, $m ) ) {
			$next['ga_client_id'] = $m[1];
		}

		$ua = isset( $_SERVER['HTTP_USER_AGENT'] ) ? substr( sanitize_text_field( wp_unslash( $_SERVER['HTTP_USER_AGENT'] ) ), 0, 500 ) : '';
		if ( $ua ) {
			$next['user_agent'] = $ua;
		}

		if ( $next !== $current ) {
			WCCR_Cart::update( $cart_id, array( 'tracking_data' => wp_json_encode( $next ) ), false );
		}
	}

	private static function cookie( $name ) {
		if ( empty( $_COOKIE[ $name ] ) ) {
			return '';
		}
		return substr( preg_replace( '/[^A-Za-z0-9._\-]/', '', wp_unslash( $_COOKIE[ $name ] ) ), 0, 255 );
	}

	public static function decode( $json ) {
		$data = json_decode( (string) $json, true );
		return is_array( $data ) ? $data : array();
	}

	/* ---------------------------------------------------------------------
	 * Attribution on the order
	 * ------------------------------------------------------------------- */

	/**
	 * Copy the cart's traffic source onto the order.
	 *
	 * @param WC_Order $order
	 * @param array    $cart      Cart row.
	 * @param bool     $overwrite_native For admin recoveries we own the attribution; for normal
	 *                            customer checkouts WooCommerce already wrote its own values, so
	 *                            those are only filled when empty.
	 */
	public static function apply_attribution( $order, array $cart, $overwrite_native = true ) {
		$tracking = self::decode( $cart['tracking_data'] ?? '' );
		$landing  = $cart['landing_page'] ?: $cart['source_url'];

		$own = array(
			'_wccr_source_url'    => $cart['source_url'],
			'_wccr_landing_page'  => $landing,
			'_wccr_referrer'      => $cart['referrer'],
			'_wccr_utm_source'    => $cart['utm_source'],
			'_wccr_utm_medium'    => $cart['utm_medium'],
			'_wccr_utm_campaign'  => $cart['utm_campaign'],
			'_wccr_utm_term'      => $cart['utm_term'],
			'_wccr_utm_content'   => $cart['utm_content'],
			'_wccr_device_type'   => $cart['device_type'],
			'_wccr_country_code'  => $cart['country_code'],
			'_wccr_cart_created'  => $cart['created_at'],
			'_wccr_source_label'  => self::source_label( $cart ),
		);
		foreach ( self::click_id_params() as $param ) {
			$own[ '_wccr_' . $param ] = $tracking[ $param ] ?? '';
		}
		$own['_wccr_fbp']          = $tracking['fbp'] ?? '';
		$own['_wccr_fbc']          = $tracking['fbc'] ?? '';
		$own['_wccr_ga_client_id'] = $tracking['ga_client_id'] ?? '';

		foreach ( $own as $key => $value ) {
			if ( '' !== (string) $value && null !== $value ) {
				$order->update_meta_data( $key, $value );
			}
		}

		// Customer IP + browser of the original shopper (shown on the order screen, used by fraud tools).
		if ( $overwrite_native ) {
			if ( ! empty( $cart['ip_address'] ) && is_callable( array( $order, 'set_customer_ip_address' ) ) ) {
				$order->set_customer_ip_address( $cart['ip_address'] );
			}
			if ( ! empty( $tracking['user_agent'] ) && is_callable( array( $order, 'set_customer_user_agent' ) ) ) {
				$order->set_customer_user_agent( $tracking['user_agent'] );
			}
		}

		// WooCommerce native Order Attribution (WC 8.5+): powers the "Origin" column + attribution box.
		$native = self::native_attribution( $cart, $tracking );
		foreach ( $native as $key => $value ) {
			$meta_key = '_wc_order_attribution_' . $key;
			if ( '' === (string) $value ) {
				continue;
			}
			if ( ! $overwrite_native && '' !== (string) $order->get_meta( $meta_key ) ) {
				continue;
			}
			$order->update_meta_data( $meta_key, $value );
		}
	}

	/**
	 * Translate our cart source into WooCommerce's attribution vocabulary
	 * (source_type: utm | organic | referral | typein).
	 */
	public static function native_attribution( array $cart, array $tracking = array() ) {
		$source = $cart['utm_source'];
		$medium = $cart['utm_medium'];
		$ref_host = $cart['referrer'] ? strtolower( (string) wp_parse_url( $cart['referrer'], PHP_URL_HOST ) ) : '';
		$ref_host = preg_replace( '/^(www|m|l|lm)\./', '', $ref_host );
		$site_host = preg_replace( '/^www\./', '', strtolower( (string) wp_parse_url( home_url(), PHP_URL_HOST ) ) );
		if ( $ref_host === $site_host ) {
			$ref_host = ''; // Internal referrer = no external source.
		}

		if ( $source ) {
			$type = 'utm';
		} elseif ( $ref_host && preg_match( '/(^|\.)(google|bing|yahoo|duckduckgo|yandex|baidu|ecosia)\./', $ref_host, $m ) ) {
			$type   = 'organic';
			$source = $m[2];
			$medium = 'organic';
		} elseif ( $ref_host ) {
			$type   = 'referral';
			$source = $ref_host;
			$medium = 'referral';
		} elseif ( ! empty( $tracking['fbclid'] ) ) {
			// In-app browsers (Facebook/Instagram) often strip the referrer but keep fbclid.
			$type   = 'referral';
			$source = 'facebook.com';
			$medium = 'referral';
		} else {
			$type   = 'typein';
			$source = '(direct)';
			$medium = '(none)';
		}

		$device = strtolower( (string) $cart['device_type'] );
		$pages  = 0;
		if ( ! empty( $cart['id'] ) ) {
			global $wpdb;
			$pages = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->prefix}" . FIXO_CART_TABLE_EVENTS . " WHERE cart_id = %d AND event_type = 'page_view'", (int) $cart['id'] ) );
		}

		return array(
			'source_type'        => $type,
			'utm_source'         => $source,
			'utm_medium'         => $medium,
			'utm_campaign'       => $cart['utm_campaign'],
			'utm_term'           => $cart['utm_term'],
			'utm_content'        => $cart['utm_content'],
			'referrer'           => $cart['referrer'],
			'session_entry'      => $cart['landing_page'] ?: $cart['source_url'],
			'session_start_time' => $cart['created_at'],
			'session_pages'      => $pages ? (string) $pages : '',
			'session_count'      => '1',
			'device_type'        => 'mobile' === $device ? 'Mobile' : ( 'tablet' === $device ? 'Tablet' : ( $device ? 'Desktop' : '' ) ),
			'user_agent'         => $tracking['user_agent'] ?? '',
		);
	}

	/** Human readable "Facebook / cpc / summer-sale" style label. */
	public static function source_label( array $cart ) {
		if ( $cart['utm_source'] ) {
			return implode( ' / ', array_filter( array( $cart['utm_source'], $cart['utm_medium'], $cart['utm_campaign'] ) ) );
		}
		if ( $cart['referrer'] ) {
			$host = wp_parse_url( $cart['referrer'], PHP_URL_HOST );
			return $host ? preg_replace( '/^www\./', '', $host ) : $cart['referrer'];
		}
		$tracking = self::decode( $cart['tracking_data'] ?? '' );
		if ( ! empty( $tracking['fbclid'] ) ) {
			return 'facebook (fbclid)';
		}
		if ( ! empty( $tracking['gclid'] ) ) {
			return 'google ads (gclid)';
		}
		return 'Direct';
	}

	/* ---------------------------------------------------------------------
	 * Event dispatch
	 * ------------------------------------------------------------------- */

	/** Fired right after an admin converts a cart. */
	public function on_converted( $order_id, $cart, $order ) {
		if ( 'paid' === get_option( 'wccr_tracking_trigger', 'created' ) ) {
			// Wait for processing/completed — unless the order was created directly in a paid status.
			$order = $order instanceof WC_Order ? $order : wc_get_order( $order_id );
			if ( ! $order || ! $order->has_status( self::paid_statuses() ) ) {
				self::$last_results = array( array( 'channel' => 'all', 'ok' => true, 'message' => __( 'Conversion events will be sent when the order is marked Processing/Completed.', 'fixo-cart' ), 'deferred' => true ) );
				return;
			}
		}
		self::$last_results = self::dispatch( $order_id );
	}

	/** "Only when paid" mode: pending payment orders fire once they become processing/completed. */
	public function on_status_changed( $order_id, $from, $to, $order ) {
		if ( 'paid' !== get_option( 'wccr_tracking_trigger', 'created' ) ) {
			return;
		}
		if ( ! in_array( $to, self::paid_statuses(), true ) ) {
			return;
		}
		$order = $order instanceof WC_Order ? $order : wc_get_order( $order_id );
		if ( ! $order || 'admin_recovery' !== $order->get_meta( '_wccr_conversion_type' ) ) {
			return;
		}
		// During order creation this hook runs before the cart is linked; on_converted handles that case.
		if ( ! $order->get_meta( '_wccr_recovered_cart_id' ) || doing_action( 'wccr_cart_converted_to_order' ) ) {
			return;
		}
		$cart = WCCR_Cart::get( (int) $order->get_meta( '_wccr_recovered_cart_id' ) );
		if ( ! $cart || (int) $cart['order_id'] !== (int) $order_id ) {
			return; // Still being created — on_converted will fire it.
		}
		self::dispatch( $order_id );
	}

	public static function paid_statuses() {
		return apply_filters( 'wccr_tracking_paid_statuses', array( 'processing', 'completed' ) );
	}

	/**
	 * Send the order to every enabled channel once (idempotent per channel via order meta).
	 *
	 * @return array List of { channel, ok, message }.
	 */
	public static function dispatch( $order_id, $force = false ) {
		$order = wc_get_order( $order_id );
		if ( ! $order ) {
			return array();
		}
		$cart    = WCCR_Cart::get( (int) $order->get_meta( '_wccr_recovered_cart_id' ) );
		$cart    = $cart ? $cart : array();
		$results = array();

		$channels = array(
			'meta'    => array( 'enabled' => 'yes' === get_option( 'wccr_meta_capi_enabled', 'no' ), 'label' => 'Meta CAPI', 'send' => array( __CLASS__, 'send_meta' ) ),
			'ga4'     => array( 'enabled' => 'yes' === get_option( 'wccr_ga4_enabled', 'no' ), 'label' => 'GA4', 'send' => array( __CLASS__, 'send_ga4' ) ),
			'webhook' => array( 'enabled' => 'yes' === get_option( 'wccr_webhook_enabled', 'no' ), 'label' => 'Webhook', 'send' => array( __CLASS__, 'send_webhook' ) ),
		);

		foreach ( $channels as $key => $channel ) {
			if ( ! $channel['enabled'] ) {
				continue;
			}
			$sent_key = '_wccr_' . $key . '_sent_at';
			if ( ! $force && $order->get_meta( $sent_key ) ) {
				continue;
			}
			$result = call_user_func( $channel['send'], $order, $cart );
			$result = wp_parse_args( $result, array( 'ok' => false, 'message' => '' ) );
			$result['channel'] = $channel['label'];

			if ( $result['ok'] ) {
				$order->update_meta_data( $sent_key, current_time( 'mysql' ) );
				/* translators: 1: channel, 2: details */
				$order->add_order_note( sprintf( __( 'Fixo Cart: Purchase event sent to %1$s. %2$s', 'fixo-cart' ), $channel['label'], $result['message'] ) );
			} else {
				/* translators: 1: channel, 2: error */
				$order->add_order_note( sprintf( __( 'Fixo Cart: %1$s Purchase event FAILED — %2$s', 'fixo-cart' ), $channel['label'], $result['message'] ) );
			}
			if ( ! empty( $cart['id'] ) ) {
				WCCR_Cart::log_event( (int) $cart['id'], 'tracking_sent', array( 'channel' => $channel['label'], 'ok' => (bool) $result['ok'], 'message' => $result['message'], 'order_id' => $order_id ) );
			}
			$results[] = $result;
		}
		$order->save();

		/**
		 * Generic hook for any other platform (TikTok, Google Ads offline conversions, CRM …).
		 *
		 * @param WC_Order $order
		 * @param array    $cart    Cart row the order was created from.
		 * @param array    $payload Normalised, ready-to-use event data.
		 */
		do_action( 'wccr_recovered_order_tracking', $order, $cart, self::build_payload( $order, $cart ) );

		return $results;
	}

	/* ---------------------------------------------------------------------
	 * Payload helpers
	 * ------------------------------------------------------------------- */

	/** Normalised event data shared by every channel + the generic action + webhook. */
	public static function build_payload( $order, array $cart ) {
		$tracking = self::decode( $cart['tracking_data'] ?? '' );
		$items    = array();
		foreach ( $order->get_items() as $item ) {
			$product = $item->get_product();
			$qty     = max( 1, (int) $item->get_quantity() );
			$items[] = array(
				'product_id'   => (int) $item->get_product_id(),
				'variation_id' => (int) $item->get_variation_id(),
				'content_id'   => self::content_id( $product, $item ),
				'sku'          => $product ? $product->get_sku() : '',
				'name'         => $item->get_name(),
				'quantity'     => $qty,
				'price'        => round( (float) $order->get_item_total( $item, false, false ), 2 ),
				'total'        => round( (float) $item->get_total(), 2 ),
			);
		}

		return array(
			'event'          => 'purchase',
			'event_id'       => 'wccr_purchase_' . $order->get_id(),
			'order_id'       => $order->get_id(),
			'order_number'   => $order->get_order_number(),
			'order_status'   => $order->get_status(),
			'order_edit_url' => WCCR_REST_API::order_edit_url( $order->get_id() ),
			'value'          => round( (float) $order->get_total(), 2 ),
			'currency'       => $order->get_currency(),
			'conversion_type'=> $order->get_meta( '_wccr_conversion_type' ),
			'cart_id'        => isset( $cart['id'] ) ? (int) $cart['id'] : 0,
			'customer'       => array(
				'first_name' => $order->get_billing_first_name(),
				'last_name'  => $order->get_billing_last_name(),
				'email'      => $order->get_billing_email(),
				'phone'      => $order->get_billing_phone(),
				'city'       => $order->get_billing_city(),
				'state'      => $order->get_billing_state(),
				'postcode'   => $order->get_billing_postcode(),
				'country'    => $order->get_billing_country(),
				'user_id'    => (int) $order->get_customer_id(),
			),
			'attribution'    => array(
				'label'        => $cart ? self::source_label( $cart ) : '',
				'landing_page' => $cart['landing_page'] ?? '',
				'referrer'     => $cart['referrer'] ?? '',
				'utm_source'   => $cart['utm_source'] ?? '',
				'utm_medium'   => $cart['utm_medium'] ?? '',
				'utm_campaign' => $cart['utm_campaign'] ?? '',
				'utm_term'     => $cart['utm_term'] ?? '',
				'utm_content'  => $cart['utm_content'] ?? '',
				'device_type'  => $cart['device_type'] ?? '',
				'click_ids'    => array_intersect_key( $tracking, array_flip( self::click_id_params() ) ),
			),
			'items'          => $items,
			'created_at'     => $order->get_date_created() ? $order->get_date_created()->date( 'c' ) : '',
		);
	}

	/** Catalog id format — must match the ids in your Meta catalog for Advantage+/DPA. */
	private static function content_id( $product, $item ) {
		$id = $item->get_variation_id() ? $item->get_variation_id() : $item->get_product_id();
		switch ( get_option( 'wccr_meta_content_id', 'id' ) ) {
			case 'sku':
				return ( $product && $product->get_sku() ) ? $product->get_sku() : (string) $id;
			case 'wc_post_id':
				// Format used by the official "Facebook for WooCommerce" plugin when no SKU is set.
				return ( $product && $product->get_sku() ) ? $product->get_sku() . '_' . $id : 'wc_post_id_' . $id;
			default:
				return (string) $id;
		}
	}

	private static function hash( $value ) {
		$value = strtolower( trim( (string) $value ) );
		return '' === $value ? '' : hash( 'sha256', $value );
	}

	/**
	 * E.164 digits without "+": 01712345678 (BD) -> 8801712345678.
	 */
	public static function normalize_phone( $phone, $country = '' ) {
		$digits = preg_replace( '/\D/', '', (string) $phone );
		if ( '' === $digits ) {
			return '';
		}
		if ( 0 === strpos( $digits, '00' ) ) {
			$digits = substr( $digits, 2 );
		}
		$country = strtoupper( substr( $country ? $country : (string) get_option( 'woocommerce_default_country', '' ), 0, 2 ) );
		$code    = '';
		if ( $country && function_exists( 'WC' ) && WC() && WC()->countries && method_exists( WC()->countries, 'get_country_calling_code' ) ) {
			$code = WC()->countries->get_country_calling_code( $country );
			$code = ltrim( is_array( $code ) ? (string) reset( $code ) : (string) $code, '+' );
		}
		if ( $code && 0 === strpos( $digits, $code ) && strlen( $digits ) > 10 ) {
			return $digits;
		}
		if ( $code && '0' === $digits[0] ) {
			return $code . ltrim( $digits, '0' );
		}
		if ( 'BD' === $country && 11 === strlen( $digits ) && 0 === strpos( $digits, '01' ) ) {
			return '88' . $digits;
		}
		if ( 'BD' === $country && 10 === strlen( $digits ) && '1' === $digits[0] ) {
			return '880' . $digits;
		}
		return $digits;
	}

	/* ---------------------------------------------------------------------
	 * Channels
	 * ------------------------------------------------------------------- */

	/** Meta Conversions API — server-side Purchase event. */
	public static function send_meta( $order, array $cart, $test = false ) {
		$pixel = preg_replace( '/\D/', '', (string) get_option( 'wccr_meta_pixel_id', '' ) );
		$token = trim( (string) get_option( 'wccr_meta_access_token', '' ) );
		if ( ! $pixel || ! $token ) {
			return array( 'ok' => false, 'message' => __( 'Pixel ID or access token is missing.', 'fixo-cart' ) );
		}

		$tracking = self::decode( $cart['tracking_data'] ?? '' );
		$payload  = self::build_payload( $order, $cart );
		$country  = $order->get_billing_country();

		$user_data = array_filter(
			array(
				'em'          => array_filter( array( self::hash( $order->get_billing_email() ) ) ),
				'ph'          => array_filter( array( self::hash( self::normalize_phone( $order->get_billing_phone(), $country ) ) ) ),
				'fn'          => array_filter( array( self::hash( $order->get_billing_first_name() ) ) ),
				'ln'          => array_filter( array( self::hash( $order->get_billing_last_name() ) ) ),
				'ct'          => array_filter( array( self::hash( preg_replace( '/[^\p{L}]/u', '', (string) $order->get_billing_city() ) ) ) ),
				'st'          => array_filter( array( self::hash( $order->get_billing_state() ) ) ),
				'zp'          => array_filter( array( self::hash( str_replace( ' ', '', (string) $order->get_billing_postcode() ) ) ) ),
				'country'     => array_filter( array( self::hash( $country ) ) ),
				'external_id' => array_filter( array( self::hash( $order->get_customer_id() ? 'user_' . $order->get_customer_id() : $order->get_billing_email() ) ) ),
				'client_ip_address' => $cart['ip_address'] ?? $order->get_customer_ip_address(),
				'client_user_agent' => $tracking['user_agent'] ?? $order->get_customer_user_agent(),
				'fbc'         => $tracking['fbc'] ?? '',
				'fbp'         => $tracking['fbp'] ?? '',
			)
		);

		$action_source = get_option( 'wccr_meta_action_source', 'website' );
		if ( 'website' === $action_source && empty( $user_data['client_user_agent'] ) ) {
			$action_source = 'system_generated'; // "website" events require a user agent.
		}

		$event = array(
			'event_name'    => 'Purchase',
			'event_time'    => time(),
			'event_id'      => $payload['event_id'],
			'action_source' => $action_source,
			'user_data'     => $user_data,
			'custom_data'   => array(
				'currency'     => $payload['currency'],
				'value'        => $payload['value'],
				'order_id'     => (string) $payload['order_id'],
				'content_type' => 'product',
				'content_ids'  => array_values( array_map( 'strval', wp_list_pluck( $payload['items'], 'content_id' ) ) ),
				'contents'     => array_map(
					function ( $it ) {
						return array( 'id' => (string) $it['content_id'], 'quantity' => $it['quantity'], 'item_price' => $it['price'] );
					},
					$payload['items']
				),
				'num_items'    => array_sum( wp_list_pluck( $payload['items'], 'quantity' ) ),
			),
		);
		if ( 'website' === $action_source ) {
			$event['event_source_url'] = $payload['attribution']['landing_page'] ? $payload['attribution']['landing_page'] : wc_get_checkout_url();
		}

		$body = array( 'data' => array( apply_filters( 'wccr_meta_capi_event', $event, $order, $cart ) ) );
		$test_code = trim( (string) get_option( 'wccr_meta_test_event_code', '' ) );
		if ( $test_code && ( $test || 'yes' === get_option( 'wccr_meta_use_test_code', 'no' ) ) ) {
			$body['test_event_code'] = $test_code;
		}

		$version  = apply_filters( 'wccr_meta_graph_version', 'v23.0' );
		$response = wp_remote_post(
			'https://graph.facebook.com/' . $version . '/' . $pixel . '/events?access_token=' . rawurlencode( $token ),
			array(
				'timeout' => 10,
				'headers' => array( 'Content-Type' => 'application/json' ),
				'body'    => wp_json_encode( $body ),
			)
		);
		if ( is_wp_error( $response ) ) {
			return array( 'ok' => false, 'message' => $response->get_error_message() );
		}
		$code = wp_remote_retrieve_response_code( $response );
		$json = json_decode( wp_remote_retrieve_body( $response ), true );
		if ( 200 === (int) $code && ! empty( $json['events_received'] ) ) {
			$matched = array_intersect( array_keys( $user_data ), array( 'em', 'ph', 'fbc', 'fbp', 'client_ip_address' ) );
			/* translators: 1: event id, 2: matched keys */
			return array( 'ok' => true, 'message' => sprintf( __( 'Event ID %1$s · match keys: %2$s', 'fixo-cart' ), $payload['event_id'], implode( ', ', $matched ) ) . ( isset( $body['test_event_code'] ) ? ' · TEST' : '' ) );
		}
		$error = isset( $json['error']['message'] ) ? $json['error']['message'] : ( 'HTTP ' . $code );
		return array( 'ok' => false, 'message' => $error );
	}

	/** GA4 Measurement Protocol — purchase event. */
	public static function send_ga4( $order, array $cart, $test = false ) {
		$mid    = trim( (string) get_option( 'wccr_ga4_measurement_id', '' ) );
		$secret = trim( (string) get_option( 'wccr_ga4_api_secret', '' ) );
		if ( ! $mid || ! $secret ) {
			return array( 'ok' => false, 'message' => __( 'Measurement ID or API secret is missing.', 'fixo-cart' ) );
		}
		$tracking  = self::decode( $cart['tracking_data'] ?? '' );
		$payload   = self::build_payload( $order, $cart );
		$client_id = ! empty( $tracking['ga_client_id'] ) ? $tracking['ga_client_id'] : wp_rand( 100000000, 999999999 ) . '.' . time();

		$body = array(
			'client_id' => $client_id,
			'events'    => array(
				array(
					'name'   => 'purchase',
					'params' => array(
						'transaction_id' => (string) $payload['order_id'],
						'value'          => $payload['value'],
						'currency'       => $payload['currency'],
						'campaign'       => $payload['attribution']['utm_campaign'],
						'source'         => $payload['attribution']['utm_source'],
						'medium'         => $payload['attribution']['utm_medium'],
						'items'          => array_map(
							function ( $it ) {
								return array( 'item_id' => (string) $it['content_id'], 'item_name' => $it['name'], 'price' => $it['price'], 'quantity' => $it['quantity'] );
							},
							$payload['items']
						),
					),
				),
			),
		);
		if ( $order->get_customer_id() ) {
			$body['user_id'] = (string) $order->get_customer_id();
		}

		$endpoint = $test ? 'https://www.google-analytics.com/debug/mp/collect' : 'https://www.google-analytics.com/mp/collect';
		$response = wp_remote_post(
			add_query_arg( array( 'measurement_id' => rawurlencode( $mid ), 'api_secret' => rawurlencode( $secret ) ), $endpoint ),
			array( 'timeout' => 10, 'headers' => array( 'Content-Type' => 'application/json' ), 'body' => wp_json_encode( $body ) )
		);
		if ( is_wp_error( $response ) ) {
			return array( 'ok' => false, 'message' => $response->get_error_message() );
		}
		$code = (int) wp_remote_retrieve_response_code( $response );
		if ( $test ) {
			$json = json_decode( wp_remote_retrieve_body( $response ), true );
			if ( ! empty( $json['validationMessages'] ) ) {
				return array( 'ok' => false, 'message' => $json['validationMessages'][0]['description'] ?? 'Validation error' );
			}
		}
		return $code >= 200 && $code < 300
			? array( 'ok' => true, 'message' => 'client_id ' . $client_id . ( empty( $tracking['ga_client_id'] ) ? ' (generated — no _ga cookie captured)' : '' ) )
			: array( 'ok' => false, 'message' => 'HTTP ' . $code );
	}

	/** Generic JSON webhook (Zapier, Make, n8n, Google Apps Script, CRM …). */
	public static function send_webhook( $order, array $cart, $test = false ) {
		$url = esc_url_raw( (string) get_option( 'wccr_webhook_url', '' ) );
		if ( ! $url || ! wp_http_validate_url( $url ) ) {
			return array( 'ok' => false, 'message' => __( 'Webhook URL is missing or invalid.', 'fixo-cart' ) );
		}
		$payload = self::build_payload( $order, $cart );
		if ( $test ) {
			$payload['test'] = true;
		}
		$json    = wp_json_encode( $payload );
		$headers = array( 'Content-Type' => 'application/json', 'X-WCCR-Event' => 'purchase' );
		$secret  = (string) get_option( 'wccr_webhook_secret', '' );
		if ( $secret ) {
			$headers['X-WCCR-Signature'] = 'sha256=' . hash_hmac( 'sha256', $json, $secret );
		}
		$response = wp_safe_remote_post( $url, array( 'timeout' => 10, 'headers' => $headers, 'body' => $json ) );
		if ( is_wp_error( $response ) ) {
			return array( 'ok' => false, 'message' => $response->get_error_message() );
		}
		$code = (int) wp_remote_retrieve_response_code( $response );
		return $code >= 200 && $code < 300 ? array( 'ok' => true, 'message' => 'HTTP ' . $code ) : array( 'ok' => false, 'message' => 'HTTP ' . $code );
	}

	/**
	 * "Send test event" button in Settings. Uses the most recent order so the payload is realistic;
	 * Meta tests are always sent with the Test Event Code so they never count as real purchases.
	 */
	public static function send_test( $channel ) {
		$orders = wc_get_orders( array( 'limit' => 1, 'orderby' => 'date', 'order' => 'DESC', 'type' => 'shop_order' ) );
		if ( empty( $orders ) ) {
			return array( 'ok' => false, 'message' => __( 'Create at least one order first — the test uses your latest order as sample data.', 'fixo-cart' ) );
		}
		$order = $orders[0];
		$cart  = WCCR_Cart::get( (int) $order->get_meta( '_wccr_recovered_cart_id' ) );
		$cart  = $cart ? $cart : array();

		switch ( $channel ) {
			case 'meta':
				if ( ! trim( (string) get_option( 'wccr_meta_test_event_code', '' ) ) ) {
					return array( 'ok' => false, 'message' => __( 'Add a Test Event Code (Events Manager → Test events) so the test does not count as a real purchase.', 'fixo-cart' ) );
				}
				return self::send_meta( $order, $cart, true );
			case 'ga4':
				return self::send_ga4( $order, $cart, true );
			case 'webhook':
				return self::send_webhook( $order, $cart, true );
		}
		return array( 'ok' => false, 'message' => 'Unknown channel' );
	}
}
