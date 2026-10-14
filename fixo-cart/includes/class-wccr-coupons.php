<?php
/**
 * Smart, single-use recovery coupons.
 *
 * @package Fixo_Cart
 */
if ( ! defined( 'ABSPATH' ) ) { exit; }

class WCCR_Coupons {
	public static function create_recovery_coupon( array $cart, $percent, $force_new = false ) {
		$percent = max( 0, min( 100, (float) $percent ) );
		if ( $percent <= 0 || empty( $cart['email'] ) || ! class_exists( 'WC_Coupon' ) ) return '';

		if ( ! $force_new ) {
			// Events come newest-first. The old code reversed them and returned the OLDEST code,
			// even if it had already expired or been used. Now: newest code that is still usable.
			$events = WCCR_Cart::get_events( (int) $cart['id'] );
			foreach ( $events as $event ) {
				if ( 'coupon_created' !== $event['event_type'] || empty( $event['event_data'] ) ) continue;
				$data = json_decode( $event['event_data'], true );
				if ( empty( $data['code'] ) ) continue;
				$existing = new WC_Coupon( $data['code'] );
				if ( ! $existing->get_id() ) continue;
				$expires = $existing->get_date_expires();
				$expired = $expires && $expires->getTimestamp() < time() + HOUR_IN_SECONDS;
				$used_up = $existing->get_usage_limit() > 0 && $existing->get_usage_count() >= $existing->get_usage_limit();
				if ( ! $expired && ! $used_up ) return $existing->get_code();
				break; // Newest one is unusable — create a fresh coupon below.
			}
		}

		// Uppercase BEFORE filtering — the old order stripped every lowercase letter ("save" → "").
		$prefix = preg_replace( '/[^A-Z0-9_-]/', '', strtoupper( (string) get_option( 'wccr_smart_coupon_prefix', 'RECOVER' ) ) );
		$prefix = $prefix ? substr( $prefix, 0, 18 ) : 'RECOVER';
		$code = $prefix . '-' . strtoupper( wp_generate_password( 8, false, false ) );
		$coupon = new WC_Coupon();
		$coupon->set_code( $code );
		$coupon->set_discount_type( 'percent' );
		$coupon->set_amount( $percent );
		$coupon->set_email_restrictions( array( sanitize_email( $cart['email'] ) ) );
		$coupon->set_usage_limit( 1 );
		$coupon->set_usage_limit_per_user( 1 );
		$coupon->set_individual_use( true );

		$expiry_days = max( 1, (int) get_option( 'wccr_smart_coupon_expiry_days', 7 ) );
		$coupon->set_date_expires( time() + ( $expiry_days * DAY_IN_SECONDS ) );
		$min_spend = max( 0, (float) get_option( 'wccr_smart_coupon_min_spend', 0 ) );
		if ( $min_spend > 0 ) $coupon->set_minimum_amount( wc_format_decimal( $min_spend ) );
		$max_discount = max( 0, (float) get_option( 'wccr_smart_coupon_max_discount', 0 ) );
		if ( $max_discount > 0 && is_callable( array( $coupon, 'set_maximum_amount' ) ) ) $coupon->set_maximum_amount( wc_format_decimal( $max_discount ) );
		$coupon->set_description( sprintf( __( 'Smart abandoned cart recovery coupon for cart #%d', 'fixo-cart' ), $cart['id'] ) );
		$coupon->save();

		WCCR_Cart::log_event( $cart['id'], 'coupon_created', array( 'code' => $code, 'percent' => $percent, 'expiry_days' => $expiry_days, 'min_spend' => $min_spend, 'max_discount' => $max_discount ) );
		return $code;
	}

	public static function create_for_cart( array $cart, $percent = null ) {
		if ( 'yes' !== get_option( 'wccr_smart_coupon_enabled', 'yes' ) ) return '';
		if ( null === $percent ) $percent = (float) get_option( 'wccr_email_2_coupon_percent', 10 );
		return self::create_recovery_coupon( $cart, $percent );
	}
}
