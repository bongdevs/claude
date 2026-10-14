<?php
/**
 * Handles a visitor clicking the recovery link from an email: restores the cart contents
 * into their (possibly new) session, pre-fills the checkout fields, and sends them to checkout.
 *
 * @package Fixo_Cart
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class WCCR_Recovery {

	public function __construct() {
		add_action( 'wp_loaded', array( $this, 'maybe_handle_recovery' ), 20 );
		add_filter( 'woocommerce_checkout_get_value', array( $this, 'prefill_checkout_field' ), 10, 2 );
	}

	/**
	 * Look for ?wccr_recover=TOKEN on any front-end request.
	 */
	public function maybe_handle_recovery() {
		if ( is_admin() || empty( $_GET['wccr_recover'] ) ) {
			return;
		}

		$token = preg_replace( '/[^A-Za-z0-9]/', '', wp_unslash( $_GET['wccr_recover'] ) );
		$cart  = strlen( $token ) >= 20 ? WCCR_Cart::get_by_token( $token ) : null;

		if ( ! $cart || 'converted' === $cart['status'] || ! empty( $cart['order_id'] ) ) {
			wc_add_notice( __( 'This recovery link is no longer valid.', 'fixo-cart' ), 'notice' );
			wp_safe_redirect( wc_get_page_permalink( 'shop' ) );
			exit;
		}

		// Guests arriving from an email often have no WooCommerce session yet — without a session
		// cookie the restored cart is lost on the very next page load.
		if ( function_exists( 'WC' ) && WC()->session && ! WC()->session->has_session() ) {
			WC()->session->set_customer_session_cookie( true );
		}

		// Restoring calls empty_cart() + add_to_cart(), which fire our own capture hooks. Without
		// this guard the restore created a brand-new duplicate row for the new session, the real
		// order was attached to that duplicate, and the original cart stayed "Recovering" forever.
		WCCR_Cart::$suspend_capture = true;
		$this->restore_cart_items( $cart );
		WCCR_Cart::$suspend_capture = false;
		$this->stash_prefill_data( $cart );

		$data = array(
			'status'       => 'recovered',
			'recovered_at' => current_time( 'mysql' ),
		);

		// Move the original cart record onto the visitor's current session so later activity
		// and the final checkout are recorded against it.
		$current_key = WCCR_Cart::get_session_key();
		if ( $current_key && $current_key !== $cart['session_key'] ) {
			$other = WCCR_Cart::get_by_session( $current_key );
			if ( $other && (int) $other['id'] !== (int) $cart['id'] ) {
				if ( empty( $other['order_id'] ) ) {
					WCCR_Cart::delete( (int) $other['id'] ); // Superseded: the restore replaced that cart's contents.
				} else {
					global $wpdb;
					$wpdb->update( $wpdb->prefix . FIXO_CART_TABLE_CARTS, array( 'session_key' => substr( $current_key, 0, 40 ) . '_o' . (int) $other['order_id'] . '_' . (int) $other['id'] ), array( 'id' => (int) $other['id'] ) );
				}
			}
			$data['session_key'] = $current_key;
		}

		WCCR_Cart::update( $cart['id'], $data );
		WCCR_Cart::log_event( $cart['id'], 'recovery_link_opened' );

		wp_safe_redirect( wc_get_checkout_url() );
		exit;
	}

	/**
	 * Empty the (new) session cart and add back every item from the stored snapshot.
	 */
	private function restore_cart_items( array $cart ) {
		if ( ! function_exists( 'WC' ) || ! WC()->cart ) {
			return;
		}

		$items = json_decode( $cart['cart_contents'], true );
		if ( ! is_array( $items ) || empty( $items ) ) {
			return;
		}

		WC()->cart->empty_cart();

		foreach ( $items as $item ) {
			$product_id   = absint( $item['product_id'] );
			$variation_id = ! empty( $item['variation_id'] ) ? absint( $item['variation_id'] ) : 0;
			$quantity     = ! empty( $item['quantity'] ) ? absint( $item['quantity'] ) : 1;
			$variation    = ! empty( $item['variation'] ) && is_array( $item['variation'] ) ? $item['variation'] : array();

			if ( ! $product_id || ! get_post_status( $product_id ) ) {
				continue; // Product deleted since abandonment — skip gracefully.
			}

			try {
				WC()->cart->add_to_cart( $product_id, $quantity, $variation_id, $variation );
			} catch ( Exception $e ) {
				continue; // Out of stock / not purchasable anymore — restore the rest.
			}
		}
	}

	/**
	 * Store the recovered contact/address details in the WC session so the checkout form
	 * loads pre-filled (this is what actually "kicks off" the resumed checkout experience).
	 */
	private function stash_prefill_data( array $cart ) {
		if ( ! function_exists( 'WC' ) || ! WC()->session ) {
			return;
		}

		$fields = array(
			'billing_first_name'  => $cart['first_name'],
			'billing_last_name'   => $cart['last_name'],
			'billing_email'       => $cart['email'],
			'billing_phone'       => $cart['phone'],
			'billing_address_1'   => $cart['billing_address_1'],
			'billing_address_2'   => $cart['billing_address_2'],
			'billing_city'        => $cart['billing_city'],
			'billing_state'       => $cart['billing_state'],
			'billing_postcode'    => $cart['billing_postcode'],
			'billing_country'     => $cart['billing_country'],
			'billing_company'     => $cart['billing_company'],
			'shipping_first_name' => $cart['shipping_first_name'],
			'shipping_last_name'  => $cart['shipping_last_name'],
			'shipping_address_1'  => $cart['shipping_address_1'],
			'shipping_address_2'  => $cart['shipping_address_2'],
			'shipping_city'       => $cart['shipping_city'],
			'shipping_state'      => $cart['shipping_state'],
			'shipping_postcode'   => $cart['shipping_postcode'],
			'shipping_country'    => $cart['shipping_country'],
		);

		WC()->session->set( 'wccr_prefill', array_filter( $fields ) );

		// Also push straight into the WC_Customer object, which is what most themes/blocks actually read from.
		if ( WC()->customer ) {
			foreach ( $fields as $key => $value ) {
				if ( '' === $value || null === $value ) {
					continue;
				}
				$setter = 'set_' . $key;
				if ( is_callable( array( WC()->customer, $setter ) ) ) {
					WC()->customer->$setter( $value );
				}
			}
			WC()->customer->save();
		}
	}

	/**
	 * Fallback pre-fill via the checkout field value filter (covers themes/plugins that read this filter
	 * instead of the WC_Customer object directly).
	 */
	public function prefill_checkout_field( $value, $input ) {
		if ( ! empty( $value ) || ! function_exists( 'WC' ) || ! WC()->session ) {
			return $value;
		}
		$prefill = WC()->session->get( 'wccr_prefill' );
		if ( is_array( $prefill ) && isset( $prefill[ $input ] ) ) {
			return $prefill[ $input ];
		}
		return $value;
	}
}
