<?php
/**
 * Builds and sends the abandoned-cart recovery emails (up to 3 steps),
 * each carrying a one-click recovery link back to a restored checkout.
 *
 * @package Fixo_Cart
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class WCCR_Emails {

	/**
	 * Decide whether the next sequence step is due for this cart, and send it if so.
	 */
	public function maybe_send_next_step( array $cart ) {
		$sent = (int) $cart['emails_sent'];
		$step = self::next_enabled_step( $sent );

		// Previously: if step 2 was disabled the sequence stopped there and step 3 was never sent
		// even when enabled. Now disabled steps are skipped.
		if ( ! $step ) {
			return;
		}

		$delay_minutes = max( 0, (int) get_option( "wccr_email_{$step}_delay_minutes", 60 ) );
		$base_time     = $sent > 0 && $cart['last_email_sent_at'] ? WCCR_Cart::to_timestamp( $cart['last_email_sent_at'] ) : WCCR_Cart::to_timestamp( $cart['updated_at'] );
		$due_at        = $base_time + ( $delay_minutes * 60 );

		if ( time() < $due_at ) {
			return;
		}

		// Never start a sequence for a stale cart: if the first email is more than 2 days overdue
		// (cron was not running, plugin just updated), a "you forgot your cart" mail is spam.
		if ( 0 === $sent && time() > $due_at + 2 * DAY_IN_SECONDS ) {
			return;
		}

		$this->send_step( $cart, $step );
	}

	/** First enabled sequence step after $sent (1..3), or 0 when the sequence is finished. */
	public static function next_enabled_step( $sent ) {
		for ( $step = (int) $sent + 1; $step <= 3; $step++ ) {
			// Step 1 has no toggle — it is always on while emails_enabled = yes.
			if ( 1 === $step || 'yes' === get_option( "wccr_email_{$step}_enabled", 'no' ) ) {
				return $step;
			}
		}
		return 0;
	}

	/**
	 * Compose + send one specific step of the sequence, generating a discount coupon for step 2 if configured.
	 */
	public function send_step( array $cart, $step ) {
		if ( ! is_email( $cart['email'] ) ) {
			return false;
		}

		$token = $cart['recovery_token'];
		if ( ! $token ) {
			$token = WCCR_Cart::generate_recovery_token();
			WCCR_Cart::update( $cart['id'], array( 'recovery_token' => $token ), false );
		}

		$coupon_code = '';
		if ( 2 === $step && 'yes' === get_option( 'wccr_email_2_coupon_enabled', 'yes' ) ) {
			$coupon_code = WCCR_Coupons::create_for_cart( $cart, (float) get_option( 'wccr_email_2_coupon_percent', 10 ) );
		}

		$recovery_link = add_query_arg( 'wccr_recover', $token, home_url( '/' ) );

		$subject = get_option( "wccr_email_{$step}_subject", '' );
		if ( '' === trim( (string) $subject ) ) {
			$subject = __( 'You left something in your cart', 'fixo-cart' );
		}
		$subject = $this->replace_placeholders( $subject, $cart, $recovery_link, $coupon_code );
		// {cart_total} renders via wc_price() (HTML) — an email subject must be plain text.
		$subject = html_entity_decode( wp_strip_all_tags( $subject ), ENT_QUOTES, 'UTF-8' );

		$body = $this->render_template( $cart, $recovery_link, $coupon_code, $step );

		$from_name  = get_option( 'wccr_from_name', '' );
		$from_name  = $from_name ? $from_name : get_bloginfo( 'name' );
		$from_email = get_option( 'wccr_from_email', '' );
		$from_email = is_email( $from_email ) ? $from_email : get_option( 'admin_email' );

		$headers = array(
			'Content-Type: text/html; charset=UTF-8',
			sprintf( 'From: %s <%s>', str_replace( array( "\r", "\n", '<', '>' ), '', $from_name ), $from_email ),
		);

		$sent = wp_mail( $cart['email'], $subject, $body, $headers );

		if ( $sent ) {
			WCCR_Cart::update(
				$cart['id'],
				array(
					'emails_sent'        => $step,
					'last_email_sent_at' => current_time( 'mysql' ),
				),
				false
			);
			WCCR_Cart::log_event( $cart['id'], 'email_sent', array( 'step' => $step, 'coupon' => $coupon_code ) );
		}

		return $sent;
	}

	/**
	 * Manual "Send recovery email now" trigger from the dashboard (always sends the next due step immediately).
	 */
	public function send_manual( array $cart ) {
		$step = self::next_enabled_step( (int) $cart['emails_sent'] );
		if ( ! $step ) {
			$step = 3; // Sequence finished — a manual resend re-uses the last template.
		}
		return $this->send_step( $cart, $step );
	}

	private function replace_placeholders( $text, $cart, $recovery_link, $coupon_code ) {
		$replacements = array(
			'{first_name}'     => $cart['first_name'] ? $cart['first_name'] : __( 'there', 'fixo-cart' ),
			'{recovery_link}'  => esc_url( $recovery_link ),
			'{coupon_code}'    => $coupon_code,
			'{site_name}'      => get_bloginfo( 'name' ),
			'{cart_total}'     => wc_price( $cart['cart_total'] ),
		);
		return strtr( $text, $replacements );
	}

	/**
	 * Simple, inlined-CSS HTML email — kept dependency-free (no external template engine).
	 */
	private function render_template( $cart, $recovery_link, $coupon_code, $step ) {
		$items = json_decode( $cart['cart_contents'], true );
		if ( ! is_array( $items ) ) {
			$items = array();
		}

		ob_start();
		?>
		<!DOCTYPE html>
		<html>
		<body style="margin:0;padding:0;background:#f5f5f5;font-family:Arial,Helvetica,sans-serif;">
			<table width="100%" cellpadding="0" cellspacing="0" style="background:#f5f5f5;padding:30px 0;">
				<tr><td align="center">
					<table width="600" cellpadding="0" cellspacing="0" style="background:#ffffff;border-radius:8px;overflow:hidden;">
						<tr><td style="background:#1d2327;padding:24px;text-align:center;">
							<span style="color:#fff;font-size:20px;font-weight:bold;"><?php echo esc_html( get_bloginfo( 'name' ) ); ?></span>
						</td></tr>
						<tr><td style="padding:32px;">
							<h2 style="margin:0 0 12px;color:#1d2327;">
								<?php
								echo esc_html(
									sprintf(
										/* translators: %s: customer first name */
										__( 'Hi %s, you left something behind!', 'fixo-cart' ),
										$cart['first_name'] ? $cart['first_name'] : __( 'there', 'fixo-cart' )
									)
								);
								?>
							</h2>
							<p style="color:#50575e;font-size:15px;line-height:1.6;">
								<?php esc_html_e( 'Your cart is still waiting for you. Complete your order before your items sell out.', 'fixo-cart' ); ?>
							</p>

							<table width="100%" cellpadding="8" cellspacing="0" style="margin:20px 0;border-collapse:collapse;">
								<?php foreach ( $items as $item ) : ?>
									<tr style="border-bottom:1px solid #eee;">
										<td width="60">
											<?php if ( ! empty( $item['image'] ) ) : ?>
												<img src="<?php echo esc_url( $item['image'] ); ?>" width="50" style="border-radius:4px;" alt="" />
											<?php endif; ?>
										</td>
										<td style="color:#1d2327;font-size:14px;">
											<?php echo esc_html( isset( $item['name'] ) ? $item['name'] : '' ); ?><br/>
											<span style="color:#777;font-size:12px;">
												<?php
												// wc_price() returns HTML; wrapping it in esc_html() printed raw <span> tags in the email.
												echo wp_kses_post(
													sprintf(
														/* translators: 1: quantity 2: price */
														__( 'Qty %1$d × %2$s', 'fixo-cart' ),
														(int) $item['quantity'],
														wc_price( isset( $item['price'] ) ? $item['price'] : 0, array( 'currency' => $cart['currency'] ) )
													)
												);
												?>
											</span>
										</td>
									</tr>
								<?php endforeach; ?>
							</table>

							<p style="font-size:16px;font-weight:bold;color:#1d2327;">
								<?php esc_html_e( 'Total:', 'fixo-cart' ); ?> <?php echo wp_kses_post( wc_price( $cart['cart_total'], array( 'currency' => $cart['currency'] ) ) ); ?>
							</p>

							<?php if ( $coupon_code ) : ?>
								<p style="background:#f0f6fc;border:1px dashed #2271b1;padding:12px;text-align:center;border-radius:6px;font-size:15px;">
									<?php
									echo esc_html(
										sprintf(
											/* translators: %s: coupon code */
											__( 'Use code %s at checkout for a discount.', 'fixo-cart' ),
											$coupon_code
										)
									);
									?>
								</p>
							<?php endif; ?>

							<p style="text-align:center;margin:28px 0;">
								<a href="<?php echo esc_url( $recovery_link ); ?>" style="background:#2271b1;color:#fff;padding:14px 28px;border-radius:4px;text-decoration:none;font-weight:bold;display:inline-block;">
									<?php esc_html_e( 'Complete My Order', 'fixo-cart' ); ?>
								</a>
							</p>
						</td></tr>
					</table>
				</td></tr>
			</table>
		</body>
		</html>
		<?php
		return ob_get_clean();
	}
}
