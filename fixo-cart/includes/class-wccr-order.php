<?php
/**
 * Converts a saved cart record into a real WooCommerce order — this is the manual
 * "move to Woo order as recovered" action triggered from the dashboard (e.g. after
 * calling the customer on the phone and confirming the sale).
 *
 * @package Fixo_Cart
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class WCCR_Order {

	/**
	 * @param int   $cart_id
	 * @param array $options {
	 *     @type string $order_status  pending|processing|on-hold|completed. Default 'pending'.
	 *     @type bool   $send_order_email  Whether to email the customer the new order. Default false.
	 * }
	 * @return int|WP_Error New order ID, or WP_Error on failure.
	 */
	public static function create_from_cart( $cart_id, array $options = array() ) {
		// Recovery revenue is intentionally limited to orders created from the
		// dashboard by an authorized store manager/admin.
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			return new WP_Error( 'wccr_admin_required', __( 'Only an authorized WooCommerce manager/admin can convert an abandoned cart into a recovered order.', 'fixo-cart' ) );
		}

		$cart = WCCR_Cart::get( $cart_id );
		if ( ! $cart ) {
			return new WP_Error( 'wccr_no_cart', __( 'Cart not found.', 'fixo-cart' ) );
		}

		if ( ! empty( $cart['order_id'] ) ) {
			return new WP_Error( 'wccr_already_converted', __( 'This cart already has a linked order.', 'fixo-cart' ) );
		}

		$items = json_decode( $cart['cart_contents'], true );
		if ( ! is_array( $items ) || empty( $items ) ) {
			return new WP_Error( 'wccr_no_items', __( 'This cart has no recoverable items.', 'fixo-cart' ) );
		}

		$options = wp_parse_args(
			$options,
			array(
				'order_status'     => 'pending',
				'send_order_email' => false,
			)
		);

		try {
			$allowed_statuses = array( 'pending', 'processing', 'on-hold', 'completed' );
			if ( ! in_array( $options['order_status'], $allowed_statuses, true ) ) {
				$options['order_status'] = 'pending';
			}

			$order = wc_create_order(
				array(
					'customer_id' => $cart['user_id'] ? absint( $cart['user_id'] ) : 0,
					'created_via' => 'wccr_admin_recovery',
				)
			);
			if ( is_wp_error( $order ) ) {
				return $order;
			}

			foreach ( $items as $item ) {
				$product = wc_get_product( ! empty( $item['variation_id'] ) ? $item['variation_id'] : $item['product_id'] );
				if ( ! $product ) {
					continue;
				}
				$args = array();
				// Keep chosen attributes for "Any Size/Any Color" style variations — they were lost before.
				if ( ! empty( $item['variation'] ) && is_array( $item['variation'] ) ) {
					$args['variation'] = $item['variation'];
				}
				$order->add_product( $product, max( 1, absint( $item['quantity'] ) ), $args );
			}

			if ( 0 === $order->get_item_count() ) {
				$order->delete( true );
				return new WP_Error( 'wccr_no_valid_products', __( 'None of the products in this cart exist anymore.', 'fixo-cart' ) );
			}

			$order->set_address(
				array(
					'first_name' => $cart['first_name'],
					'last_name'  => $cart['last_name'],
					'company'    => $cart['billing_company'],
					'email'      => $cart['email'],
					'phone'      => $cart['phone'],
					'address_1'  => $cart['billing_address_1'],
					'address_2'  => $cart['billing_address_2'],
					'city'       => $cart['billing_city'],
					'state'      => $cart['billing_state'],
					'postcode'   => $cart['billing_postcode'],
					'country'    => $cart['billing_country'],
				),
				'billing'
			);

			if ( ! empty( $cart['ship_to_different'] ) ) {
				$order->set_address(
					array(
						'first_name' => $cart['shipping_first_name'] ?: $cart['first_name'],
						'last_name'  => $cart['shipping_last_name'] ?: $cart['last_name'],
						'address_1'  => $cart['shipping_address_1'],
						'address_2'  => $cart['shipping_address_2'],
						'city'       => $cart['shipping_city'],
						'state'      => $cart['shipping_state'],
						'postcode'   => $cart['shipping_postcode'],
						'country'    => $cart['shipping_country'],
					),
					'shipping'
				);
			} else {
				$order->set_address(
					array(
						'first_name' => $cart['first_name'],
						'last_name'  => $cart['last_name'],
						'address_1'  => $cart['billing_address_1'],
						'address_2'  => $cart['billing_address_2'],
						'city'       => $cart['billing_city'],
						'state'      => $cart['billing_state'],
						'postcode'   => $cart['billing_postcode'],
						'country'    => $cart['billing_country'],
					),
					'shipping'
				);
			}

			$order->set_currency( $cart['currency'] ? $cart['currency'] : get_woocommerce_currency() );
			$order->update_meta_data( '_wccr_recovered_cart_id', $cart['id'] );
			$order->update_meta_data( '_wccr_conversion_type', 'admin_recovery' );
			$order->update_meta_data( '_wccr_converted_by_user_id', get_current_user_id() );

			// Full traffic source → our meta + WooCommerce's native Order Attribution (Origin column),
			// plus the shopper's original IP / user agent.
			WCCR_Tracking::apply_attribution( $order, $cart, true );

			if ( ! empty( $cart['order_note'] ) ) {
				$order->set_customer_note( $cart['order_note'] );
			}

			$order->calculate_totals();
			$order->set_status( $options['order_status'], __( 'Order created manually from an abandoned cart via Fixo Cart.', 'fixo-cart' ) );
			$order->save();

			$label = WCCR_Tracking::source_label( $cart );
			$landing = $cart['landing_page'] ?: $cart['source_url'];
			/* translators: 1: cart id, 2: source label, 3: landing page */
			$order->add_order_note( sprintf( __( 'Recovered from abandoned cart #%1$d. Traffic source: %2$s%3$s', 'fixo-cart' ), (int) $cart['id'], $label, $landing ? ' — landing page: ' . esc_url_raw( $landing ) : '' ) );

			// Processing / on-hold / completed customer emails are sent automatically by WooCommerce on
			// the status transition. The old manual do_action() sent "completed" emails twice.
			// For "Pending payment", send the customer invoice — it contains the "Pay now" link.
			if ( $options['send_order_email'] && 'pending' === $options['order_status'] && is_email( $order->get_billing_email() ) && function_exists( 'WC' ) ) {
				$mailer = WC()->mailer();
				$mails  = $mailer->get_emails();
				if ( ! empty( $mails['WC_Email_Customer_Invoice'] ) ) {
					$mails['WC_Email_Customer_Invoice']->trigger( $order->get_id(), $order );
					$order->add_order_note( __( 'Payment link (invoice) emailed to the customer by Fixo Cart.', 'fixo-cart' ) );
				}
			}

			WCCR_Cart::update(
				$cart['id'],
				array(
					'status'              => 'converted',
					'order_id'            => $order->get_id(),
					'conversion_type'     => 'admin_recovery',
					'converted_by_user_id' => get_current_user_id(),
				)
			);
			WCCR_Cart::log_event( $cart['id'], 'converted_to_order', array( 'order_id' => $order->get_id(), 'by_admin' => get_current_user_id() ) );
			if ( 'yes' === get_option( 'wccr_alerts_enabled', 'yes' ) && 'yes' === get_option( 'wccr_alert_live_recovery', 'yes' ) ) { WCCR_Cart::log_event( $cart['id'], 'alert_recovery', array( 'message' => 'Cart recovered by admin', 'order_id' => $order->get_id() ) ); }

			/**
			 * A cart was converted into a WooCommerce order by an admin.
			 * Built-in listeners send Meta CAPI / GA4 / webhook events (see WCCR_Tracking);
			 * hook in here for anything else.
			 *
			 * @param int      $order_id
			 * @param array    $cart  Cart row (now linked to the order).
			 * @param WC_Order $order
			 */
			WCCR_Tracking::$last_results = array();
			do_action( 'wccr_cart_converted_to_order', $order->get_id(), WCCR_Cart::get( $cart['id'] ), wc_get_order( $order->get_id() ) );

			return $order->get_id();
		} catch ( Exception $e ) {
			return new WP_Error( 'wccr_order_error', $e->getMessage() );
		}
	}
}
