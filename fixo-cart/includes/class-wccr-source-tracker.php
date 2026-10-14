<?php
/**
 * Captures where the visitor came from — landing page, HTTP referrer and UTM parameters —
 * the first time they hit the site, and stores it against their cart row once one exists.
 * This is what answers "kon link theke asteche" (which link did they arrive from).
 *
 * @package Fixo_Cart
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class WCCR_Source_Tracker {

	const COOKIE_NAME = 'wccr_source';

	public function __construct() {
		add_action( 'wp', array( $this, 'maybe_capture_source' ) );
		add_action( 'woocommerce_add_to_cart', array( $this, 'attach_source_to_cart' ), 20 );
		add_action( 'woocommerce_cart_updated', array( $this, 'attach_source_to_cart' ), 20 );
	}

	/**
	 * On the very first pageview of a session (no cookie yet), store landing page + referrer + UTM
	 * in a cookie so we can attach it to the cart row as soon as one is created.
	 */
	public function maybe_capture_source() {
		if ( 'yes' !== get_option( 'wccr_capture_enabled', 'yes' ) ) {
			return;
		}
		if ( is_admin() || wp_doing_ajax() || wp_doing_cron() ) {
			return;
		}
		$click_ids = array();
		foreach ( WCCR_Tracking::click_id_params() as $param ) {
			if ( ! empty( $_GET[ $param ] ) ) {
				$click_ids[ $param ] = substr( sanitize_text_field( wp_unslash( $_GET[ $param ] ) ), 0, 255 );
			}
		}

		if ( ! empty( $_COOKIE[ self::COOKIE_NAME ] ) ) {
			// UTM/landing page stay first-touch, but a NEW ad click (fbclid/gclid…) is remembered
			// so Meta/Google can be credited for the click that actually brought the visitor back.
			if ( $click_ids && ! headers_sent() ) {
				$existing = json_decode( wp_unslash( $_COOKIE[ self::COOKIE_NAME ] ), true );
				$existing = is_array( $existing ) ? $existing : array();
				$merged   = array_merge( $existing, $click_ids );
				if ( $merged !== $existing ) {
					setcookie( self::COOKIE_NAME, wp_json_encode( $merged ), time() + ( DAY_IN_SECONDS * 30 ), COOKIEPATH ?: '/', COOKIE_DOMAIN, is_ssl(), true );
					$_COOKIE[ self::COOKIE_NAME ] = wp_json_encode( $merged );
				}
			}
			return;
		}

		$data = $click_ids + array(
			'entry_ts'     => time(), // Site entry time (first page view) for the Entry / Exit column.
			'landing_page' => esc_url_raw( home_url( add_query_arg( array(), $_SERVER['REQUEST_URI'] ?? '' ) ) ),
			'referrer'     => isset( $_SERVER['HTTP_REFERER'] ) ? esc_url_raw( wp_unslash( $_SERVER['HTTP_REFERER'] ) ) : '',
			'utm_source'   => isset( $_GET['utm_source'] ) ? sanitize_text_field( wp_unslash( $_GET['utm_source'] ) ) : '',
			'utm_medium'   => isset( $_GET['utm_medium'] ) ? sanitize_text_field( wp_unslash( $_GET['utm_medium'] ) ) : '',
			'utm_campaign' => isset( $_GET['utm_campaign'] ) ? sanitize_text_field( wp_unslash( $_GET['utm_campaign'] ) ) : '',
			'utm_term'     => isset( $_GET['utm_term'] ) ? sanitize_text_field( wp_unslash( $_GET['utm_term'] ) ) : '',
			'utm_content'  => isset( $_GET['utm_content'] ) ? sanitize_text_field( wp_unslash( $_GET['utm_content'] ) ) : '',
		);

		if ( ! headers_sent() ) {
			setcookie( self::COOKIE_NAME, wp_json_encode( $data ), time() + ( DAY_IN_SECONDS * 30 ), COOKIEPATH ?: '/', COOKIE_DOMAIN, is_ssl(), true );
		}

		// Also usable within this same request before the cookie is readable on reload.
		$_COOKIE[ self::COOKIE_NAME ] = wp_json_encode( $data );

		// Optional mode: create a visitor record even before a cart exists. The default
		// cart_checkout mode intentionally does not create visitor-only rows.
		if ( 'all_visitors' === get_option( 'wccr_capture_scope', 'cart_checkout' ) && function_exists( 'WC' ) && WC()->session && WCCR_Cart::should_capture() ) {
			$id = WCCR_Cart::get_or_create_current();
			if ( $id ) {
				WCCR_Cart::update(
					$id,
					array(
						'source_url'   => $data['landing_page'] ?? '',
						'landing_page' => $data['landing_page'] ?? '',
						'referrer'     => $data['referrer'] ?? '',
						'utm_source'   => $data['utm_source'] ?? '',
						'utm_medium'   => $data['utm_medium'] ?? '',
						'utm_campaign' => $data['utm_campaign'] ?? '',
						'utm_term'     => $data['utm_term'] ?? '',
						'utm_content'  => $data['utm_content'] ?? '',
					) + $this->visitor_meta()
				);
			}
		}
	}

	/**
	 * Whenever the cart changes, make sure the row has the source info attached (only fills empty fields
	 * once — we don't want a later add-to-cart to overwrite the *original* traffic source).
	 */
	public function attach_source_to_cart() {
		// Same gate as cart capture (admin exclusion, guest setting, restore-in-progress).
		// Before, this hook still created cart rows for store admins even with "Ignore admins" on.
		if ( ! WCCR_Cart::should_capture() ) {
			return;
		}

		// In the default cart+checkout mode, only attach/create a row when there is an actual cart.
		// This prevents empty WooCommerce session updates from appearing as fake carts.
		if ( 'cart_checkout' === get_option( 'wccr_capture_scope', 'cart_checkout' ) ) {
			if ( ! function_exists( 'WC' ) || ! WC()->cart || WC()->cart->is_empty() ) {
				return;
			}
		}

		$id = WCCR_Cart::get_or_create_current();
		if ( ! $id ) {
			return;
		}

		$row = WCCR_Cart::get( $id );
		if ( ! $row ) {
			return;
		}

		$update = array();

		// Device / IP / country are recorded even when the source cookie is missing (e.g. the page
		// was served from a full-page cache, so the "wp" hook never set the cookie).
		if ( empty( $row['device_type'] ) ) {
			$update = $this->visitor_meta();
		}

		if ( empty( $row['source_url'] ) && ! empty( $_COOKIE[ self::COOKIE_NAME ] ) ) {
			$data = json_decode( wp_unslash( $_COOKIE[ self::COOKIE_NAME ] ), true );
			if ( is_array( $data ) ) {
				$update = array_merge(
					$update,
					array(
						'source_url'   => esc_url_raw( $data['landing_page'] ?? '' ),
						'landing_page' => esc_url_raw( $data['landing_page'] ?? '' ),
						'referrer'     => esc_url_raw( $data['referrer'] ?? '' ),
						'utm_source'   => sanitize_text_field( $data['utm_source'] ?? '' ),
						'utm_medium'   => sanitize_text_field( $data['utm_medium'] ?? '' ),
						'utm_campaign' => sanitize_text_field( $data['utm_campaign'] ?? '' ),
						'utm_term'     => sanitize_text_field( $data['utm_term'] ?? '' ),
						'utm_content'  => sanitize_text_field( $data['utm_content'] ?? '' ),
					)
				);
			}
		}

		if ( $update ) {
			WCCR_Cart::update( $id, $update, false );
		}

		// Ad click ids, fbp/fbc, GA client id and user agent for server-side conversion events.
		WCCR_Tracking::refresh_for_cart( $id, $update ? null : $row );
	}

	/** IP, device and country for the current visitor. */
	private function visitor_meta() {
		$ip      = $this->get_client_ip();
		$country = '';
		if ( $ip && class_exists( 'WC_Geolocation' ) ) {
			// Uses Cloudflare/host country headers or WooCommerce's local MaxMind DB — no external API call.
			$geo     = WC_Geolocation::geolocate_ip( $ip, true, false );
			$country = ! empty( $geo['country'] ) ? $geo['country'] : '';
		}
		return array(
			'ip_address'   => $ip,
			'device_type'  => wp_is_mobile() ? 'mobile' : 'desktop',
			'country_code' => substr( sanitize_text_field( $country ), 0, 10 ), // Column existed but was never filled before.
		);
	}

	/**
	 * Prefer WooCommerce's own resolver (handles Cloudflare & proxies consistently). The old code
	 * blindly trusted the first X-Forwarded-For value, which any visitor can spoof.
	 */
	private function get_client_ip() {
		if ( class_exists( 'WC_Geolocation' ) ) {
			return sanitize_text_field( WC_Geolocation::get_ip_address() );
		}
		return isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : '';
	}
}
