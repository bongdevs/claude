<?php
/**
 * Captures cart contents automatically (server side) and checkout field data
 * (via a small AJAX call from public/js/wccr-public.js as the customer types).
 *
 * @package Fixo_Cart
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class WCCR_Capture {

	public function __construct() {
		if ( 'yes' !== get_option( 'wccr_capture_enabled', 'yes' ) ) {
			return;
		}

		add_action( 'wp_enqueue_scripts', array( $this, 'enqueue_scripts' ) );

		// Snapshot the cart contents any time it changes (add/remove/update qty) — no field data needed for this part.
		add_action( 'woocommerce_cart_updated', array( $this, 'snapshot_cart' ) );
		add_action( 'woocommerce_add_to_cart', array( $this, 'snapshot_cart' ) );
		add_action( 'woocommerce_add_to_cart', array( $this, 'on_add_to_cart' ), 20, 6 );

		// AJAX endpoint the public JS calls (debounced) whenever a checkout field changes.
		add_action( 'wp_ajax_wccr_save_field', array( $this, 'ajax_save_field' ) );
		add_action( 'wp_ajax_nopriv_wccr_save_field', array( $this, 'ajax_save_field' ) );

		// Lightweight presence heartbeat. This keeps the cart's updated_at fresh while the
		// shopper is still on the storefront, allowing the dashboard to show "Live now".
		add_action( 'wp_ajax_wccr_heartbeat', array( $this, 'ajax_heartbeat' ) );
		add_action( 'wp_ajax_nopriv_wccr_heartbeat', array( $this, 'ajax_heartbeat' ) );

		// Mark the row converted once an order is actually placed through normal checkout.
		add_action( 'woocommerce_checkout_order_processed', array( $this, 'on_order_placed' ), 10, 3 );
		// Block (Store API) checkout — the default checkout on new WooCommerce installs — never fires
		// the hook above, so those orders were never marked converted and kept getting recovery emails.
		add_action( 'woocommerce_store_api_checkout_order_processed', array( $this, 'on_block_order_placed' ), 10, 1 );
		add_action( 'woocommerce_thankyou', array( $this, 'on_thankyou' ) );

		// Clear the row's "active" state if the cart becomes empty (customer emptied it on purpose).
		add_action( 'woocommerce_cart_emptied', array( $this, 'on_cart_emptied' ) );

		// Optional GDPR-style consent checkbox on checkout, required before we store any personal data.
		add_action( 'woocommerce_after_order_notes', array( $this, 'maybe_render_consent_field' ) );
	}

	/**
	 * Render the consent checkbox on checkout when "require consent" is enabled in settings.
	 */
	public function maybe_render_consent_field() {
		if ( 'yes' !== get_option( 'wccr_require_consent', 'no' ) ) {
			return;
		}
		?>
		<p class="form-row form-row-wide wccr-consent-row">
			<label for="wccr_consent">
				<input type="checkbox" id="wccr_consent" name="wccr_consent" />
				<?php echo esc_html( get_option( 'wccr_consent_text', __( 'I agree to be contacted about my order and cart via email or phone.', 'fixo-cart' ) ) ); ?>
			</label>
		</p>
		<?php
	}

	/**
	 * Load the small public capture script on the front-end (cart + checkout pages, and everywhere so we
	 * can catch add-to-cart from shop/product pages too).
	 */
	public function enqueue_scripts() {
		if ( is_admin() ) {
			return;
		}

		wp_enqueue_script(
			'wccr-public',
			FIXO_CART_PLUGIN_URL . 'public/js/wccr-public.js',
			array( 'jquery' ),
			FIXO_CART_VERSION,
			true
		);

		wp_localize_script(
			'wccr-public',
			'WCCR_Public',
			array(
				'ajax_url'        => admin_url( 'admin-ajax.php' ),
				'nonce'           => wp_create_nonce( 'wccr_public_nonce' ),
				'is_checkout'     => is_checkout() && ! is_wc_endpoint_url( 'order-received' ),
				'is_order_received' => is_wc_endpoint_url( 'order-received' ),
				'heartbeat_interval' => 30000,
				'require_consent' => get_option( 'wccr_require_consent', 'no' ),
			)
		);
	}

	/**
	 * Take a fresh snapshot of WC()->cart into the current session's row.
	 */
	public function snapshot_cart() {
		if ( is_admin() && ! wp_doing_ajax() ) {
			return;
		}
		if ( ! function_exists( 'WC' ) || ! WC()->cart ) {
			return;
		}
		if ( WC()->cart->is_empty() ) {
			return;
		}
		if ( ! $this->should_capture() ) {
			return;
		}

		$id = WCCR_Cart::get_or_create_current();
		if ( ! $id ) {
			return;
		}

		$contents = wp_json_encode( WCCR_Cart::build_cart_contents_snapshot() );
		$total    = (float) WC()->cart->get_total( 'edit' );
		$row      = WCCR_Cart::get( $id );

		$data = array(
			'cart_contents' => $contents,
			'cart_total'    => $total,
			'currency'      => get_woocommerce_currency(),
			'user_id'       => get_current_user_id(),
		);

		// A cart that was emptied/abandoned and is now being filled again is active again.
		// (Before, an "emptied" row stayed "emptied" forever, so a refilled cart was never
		// marked abandoned and never recovered.)
		if ( $row && in_array( $row['status'], array( 'emptied', 'abandoned' ), true ) ) {
			$data['status'] = 'active';
		}

		// woocommerce_cart_updated fires on every totals calculation (every cart/checkout page view).
		// Skip the write when nothing changed — the heartbeat already keeps presence fresh.
		if ( $row && ! isset( $data['status'] ) && $row['cart_contents'] === $contents && abs( (float) $row['cart_total'] - $total ) < 0.0001 && (int) $row['user_id'] === get_current_user_id() ) {
			return;
		}

		WCCR_Cart::update( $id, $data );
	}


	/** Log the product that triggered an add-to-cart action for the customer journey. */
	public function on_add_to_cart( $cart_item_key = '', $product_id = 0, $quantity = 1, $variation_id = 0, $variation = array(), $cart_item_data = array() ) {
		if ( ! $this->should_capture() ) {
			return;
		}
		$id = WCCR_Cart::get_or_create_current();
		if ( ! $id ) {
			return;
		}
		$product = wc_get_product( $variation_id ? $variation_id : $product_id );
		WCCR_Cart::log_event( $id, 'add_to_cart', array(
			'product_id' => absint( $product_id ),
			'variation_id' => absint( $variation_id ),
			'quantity' => max( 1, (int) $quantity ),
			'name' => $product ? $product->get_name() : '',
		) );
	}

	/**
	 * AJAX presence heartbeat. It intentionally does not create a cart for visitors with
	 * an empty cart. A heartbeat refreshes updated_at, which is also used by the abandonment
	 * cron and the admin dashboard's live/recent/inactive indicator.
	 */
	public function ajax_heartbeat() {
		check_ajax_referer( 'wccr_public_nonce', 'nonce' );

		if ( ! $this->should_capture() || ! function_exists( 'WC' ) || ! WC()->cart || WC()->cart->is_empty() ) {
			wp_send_json_success( array( 'tracked' => false ) );
		}

		$id = WCCR_Cart::get_or_create_current( array( 'status' => 'active' ) );
		if ( ! $id ) {
			wp_send_json_success( array( 'tracked' => false ) );
		}

		$row = WCCR_Cart::get( $id );
		if ( $row && empty( $row['order_id'] ) && 'converted' !== $row['status'] ) {
			// Real presence is also real cart activity: reactivate a stale row if the shopper returns.
			// A "recovered" row keeps its status (the Recovering badge) but still gets a fresh timestamp.
			$patch = in_array( $row['status'], array( 'abandoned', 'emptied' ), true ) ? array( 'status' => 'active' ) : array();
			WCCR_Cart::update( $id, $patch );
			$row = WCCR_Cart::get( $id );
		}
		// Keep fbp / fbc / _ga / user agent fresh for server-side conversion events.
		WCCR_Tracking::refresh_for_cart( $id, $row );

		$page_url  = isset( $_POST['page_url'] ) ? esc_url_raw( wp_unslash( $_POST['page_url'] ) ) : '';
		$page_type = isset( $_POST['page_type'] ) ? sanitize_key( wp_unslash( $_POST['page_type'] ) ) : 'page';
		if ( $page_url && $row ) {
			$latest = WCCR_Cart::get_latest_event( $id, 'page_view' );
			$latest_data = $latest && ! empty( $latest['event_data'] ) ? json_decode( $latest['event_data'], true ) : array();
			$latest_url = is_array( $latest_data ) && ! empty( $latest_data['page_url'] ) ? $latest_data['page_url'] : '';
			$latest_ts = $latest && ! empty( $latest['created_at'] ) ? strtotime( $latest['created_at'] ) : 0;
			if ( $page_url !== $latest_url || ! $latest_ts || ( current_time( 'timestamp' ) - $latest_ts ) >= 300 ) {
				WCCR_Cart::log_event( $id, 'page_view', array( 'page_url' => $page_url, 'page_type' => $page_type ) );
			}
		}

		wp_send_json_success(
			array(
				'tracked'   => true,
				 'cart_id'   => $id,
				'last_seen' => $row ? $row['updated_at'] : current_time( 'mysql' ),
			)
		);
	}

	/**
	 * AJAX: persist a single checkout field (or small batch) as the customer types/blurs a field.
	 * Payload is a flat map of already-whitelisted keys -> values, sent from wccr-public.js.
	 */
	public function ajax_save_field() {
		check_ajax_referer( 'wccr_public_nonce', 'nonce' );

		if ( ! $this->should_capture() ) {
			wp_send_json_success();
		}

		if ( 'yes' === get_option( 'wccr_require_consent', 'no' ) && empty( $_POST['consent'] ) ) {
			wp_send_json_success( array( 'skipped' => 'no_consent' ) );
		}

		$allowed_map = array(
			'billing_first_name'  => 'sanitize_text_field',
			'billing_last_name'   => 'sanitize_text_field',
			'billing_email'       => 'sanitize_email',
			'billing_phone'       => 'sanitize_text_field',
			'billing_address_1'   => 'sanitize_text_field',
			'billing_address_2'   => 'sanitize_text_field',
			'billing_city'        => 'sanitize_text_field',
			'billing_state'       => 'sanitize_text_field',
			'billing_postcode'    => 'sanitize_text_field',
			'billing_country'     => 'sanitize_text_field',
			'billing_company'     => 'sanitize_text_field',
			'shipping_first_name' => 'sanitize_text_field',
			'shipping_last_name'  => 'sanitize_text_field',
			'shipping_address_1'  => 'sanitize_text_field',
			'shipping_address_2'  => 'sanitize_text_field',
			'shipping_city'       => 'sanitize_text_field',
			'shipping_state'      => 'sanitize_text_field',
			'shipping_postcode'   => 'sanitize_text_field',
			'shipping_country'    => 'sanitize_text_field',
			'order_note'          => 'sanitize_textarea_field',
		);

		$fields    = isset( $_POST['fields'] ) ? (array) wp_unslash( $_POST['fields'] ) : array();
		$to_update = array();

		foreach ( $fields as $key => $value ) {
			$key = sanitize_key( $key );
			if ( isset( $allowed_map[ $key ] ) ) {
				$to_update[ $key ] = call_user_func( $allowed_map[ $key ], $value );
			}
		}

		// Map billing_* onto our storage columns; ship_to_different_address is sent separately.
		$column_map = array(
			'billing_first_name' => 'first_name',
			'billing_last_name'  => 'last_name',
			'billing_email'      => 'email',
			'billing_phone'      => 'phone',
		);
		foreach ( $column_map as $field_key => $column ) {
			if ( isset( $to_update[ $field_key ] ) ) {
				$to_update[ $column ] = $to_update[ $field_key ];
				unset( $to_update[ $field_key ] );
			}
		}

		if ( isset( $_POST['ship_to_different'] ) ) {
			$to_update['ship_to_different'] = ! empty( $_POST['ship_to_different'] ) ? 1 : 0;
		}
		if ( isset( $_POST['consent'] ) ) {
			$to_update['consent'] = ! empty( $_POST['consent'] ) ? 1 : 0;
		}

		if ( empty( $to_update ) ) {
			wp_send_json_success();
		}

		$id = WCCR_Cart::get_or_create_current( array( 'status' => 'active' ) );
		if ( ! $id ) {
			wp_send_json_error( array( 'message' => 'no_session' ) );
		}

		// Any real activity moves a previously "abandoned" cart back to "active" so we stop the email sequence.
		$existing = WCCR_Cart::get( $id );
		if ( $existing && in_array( $existing['status'], array( 'abandoned', 'emptied' ), true ) ) {
			$to_update['status'] = 'active';
		}

		WCCR_Cart::update( $id, $to_update );
		WCCR_Tracking::refresh_for_cart( $id );

		// Now that an email/phone may be known, re-check for duplicates of this person.
		if ( isset( $to_update['email'] ) || isset( $to_update['phone'] ) ) {
			WCCR_Cart::apply_risk_detection( $id, true );
			/**
			 * The shopper entered a phone number or email on checkout (Fixo Cart lead notifications listen here).
			 *
			 * @param int $id Cart id.
			 */
			do_action( 'wccr_cart_contact_captured', $id );
		}

		wp_send_json_success();
	}

	/**
	 * Order placed through normal checkout — capture the source/order link on the order itself too,
	 * then mark our row converted so it drops out of the abandoned list.
	 */
	/** Block checkout variant — receives only the WC_Order. */
	public function on_block_order_placed( $order ) {
		if ( $order instanceof WC_Order ) {
			$this->on_order_placed( $order->get_id(), array(), $order );
		}
	}

	public function on_order_placed( $order_id, $posted_data = array(), $order = null ) {
		if ( ! $order instanceof WC_Order ) {
			$order = wc_get_order( $order_id );
		}
		$session_key = WCCR_Cart::get_session_key();
		if ( ! $session_key ) {
			return;
		}
		$row = WCCR_Cart::get_by_session( $session_key );
		if ( ! $row || ! empty( $row['order_id'] ) ) {
			return;
		}

		WCCR_Cart::update(
			$row['id'],
			array(
				'status'          => 'converted',
				'order_id'        => $order_id,
				'conversion_type' => 'customer_checkout',
				'converted_by_user_id' => 0,
			)
		);

		if ( $order ) {
			$order->update_meta_data( '_wccr_conversion_type', 'customer_checkout' );
			// Same full attribution as admin recoveries. WooCommerce's own attribution values
			// (written by its checkout JS) are kept; ours only fill the gaps.
			WCCR_Tracking::refresh_for_cart( (int) $row['id'], $row );
			WCCR_Tracking::apply_attribution( $order, WCCR_Cart::get( (int) $row['id'] ), false );
			// Recovery-link attribution previously only happened when source_url existed.
			if ( ! empty( $row['recovery_token'] ) && ! empty( $row['recovered_at'] ) ) {
				$order->update_meta_data( '_wccr_recovered_cart_id', $row['id'] );
				$order->add_order_note( __( 'This order was completed after a cart recovery email/link.', 'fixo-cart' ) );
			}
			$order->save();
		}

		WCCR_Cart::log_event( $row['id'], 'order_placed', array( 'order_id' => $order_id ) );
	}

	public function on_thankyou( $order_id ) {
		// No-op placeholder kept for future use (e.g. triggering a "converted" webhook).
	}

	public function on_cart_emptied() {
		$session_key = WCCR_Cart::get_session_key();
		if ( ! $session_key ) {
			return;
		}
		if ( WCCR_Cart::$suspend_capture ) {
			return; // Recovery restore empties the cart first — that is not the shopper emptying it.
		}
		$row = WCCR_Cart::get_by_session( $session_key );
		if ( $row && empty( $row['order_id'] ) && in_array( $row['status'], array( 'active', 'recovered' ), true ) ) {
			WCCR_Cart::update( $row['id'], array( 'status' => 'emptied' ) );
		}
	}

	/**
	 * Central gate — shared with the source tracker via WCCR_Cart::should_capture().
	 */
	private function should_capture() {
		return WCCR_Cart::should_capture();
	}
}
