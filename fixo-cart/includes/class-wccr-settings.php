<?php
/**
 * All plugin settings live as single wp_options (wccr_*), read/written here
 * so both the classic admin page and the REST API (React dashboard) stay in sync.
 *
 * @package Fixo_Cart
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class WCCR_Settings {

	/**
	 * The full list of setting keys, their type (for sanitizing) and default.
	 */
	public static function schema() {
		return array(
			'capture_enabled'         => array( 'type' => 'bool', 'default' => 'yes' ),
			'capture_scope'           => array( 'type' => 'text', 'default' => 'cart_checkout' ),
			'capture_guests'          => array( 'type' => 'bool', 'default' => 'yes' ),
			'exclude_admin_ip'        => array( 'type' => 'bool', 'default' => 'yes' ),
			'require_consent'         => array( 'type' => 'bool', 'default' => 'no' ),
			'consent_text'            => array( 'type' => 'text', 'default' => '' ),
			'abandoned_after_minutes' => array( 'type' => 'int', 'default' => 20, 'min' => 5 ),
			'emails_enabled'          => array( 'type' => 'bool', 'default' => 'yes' ),
			'from_name'               => array( 'type' => 'text', 'default' => '' ),
			'from_email'              => array( 'type' => 'email', 'default' => '' ),
			'email_1_delay_minutes'   => array( 'type' => 'int', 'default' => 60 ),
			'email_1_subject'         => array( 'type' => 'text', 'default' => '' ),
			'email_2_enabled'         => array( 'type' => 'bool', 'default' => 'yes' ),
			'email_2_delay_minutes'   => array( 'type' => 'int', 'default' => 1440 ),
			'email_2_subject'         => array( 'type' => 'text', 'default' => '' ),
			'email_2_coupon_enabled'  => array( 'type' => 'bool', 'default' => 'yes' ),
			'email_2_coupon_percent'  => array( 'type' => 'int', 'default' => 10, 'max' => 100 ),
			'smart_coupon_enabled'    => array( 'type' => 'bool', 'default' => 'yes' ),
			'smart_coupon_expiry_days' => array( 'type' => 'int', 'default' => 7, 'min' => 1 ),
			'smart_coupon_min_spend'  => array( 'type' => 'int', 'default' => 0 ),
			'smart_coupon_max_discount' => array( 'type' => 'int', 'default' => 0 ),
			'smart_coupon_prefix'     => array( 'type' => 'text', 'default' => 'RECOVER' ),
			'email_3_enabled'         => array( 'type' => 'bool', 'default' => 'no' ),
			'email_3_delay_minutes'   => array( 'type' => 'int', 'default' => 4320 ),
			'email_3_subject'         => array( 'type' => 'text', 'default' => '' ),
			'data_retention_days'     => array( 'type' => 'int', 'default' => 90 ),
			'delete_data_on_uninstall' => array( 'type' => 'bool', 'default' => 'no' ),
			'whatsapp_enabled'        => array( 'type' => 'bool', 'default' => 'no' ),
			'whatsapp_message'        => array( 'type' => 'textarea', 'default' => 'Hi {first_name}, you still have items in your cart. Complete your order here: {recovery_link}' ),
			'whatsapp_coupon_enabled' => array( 'type' => 'bool', 'default' => 'yes' ),
			'live_alerts_enabled'     => array( 'type' => 'bool', 'default' => 'yes' ),
			'live_alert_min_total'    => array( 'type' => 'int', 'default' => 0 ),
			'smart_rules_enabled'      => array( 'type' => 'bool', 'default' => 'yes' ),
			'smart_rules_min_cart_total' => array( 'type' => 'int', 'default' => 0 ),
			'smart_rules_max_recovery_emails' => array( 'type' => 'int', 'default' => 3 ),
			'smart_rules_excluded_sources' => array( 'type' => 'text', 'default' => '' ),
			'smart_rules_skip_bots'     => array( 'type' => 'bool', 'default' => 'yes' ),
			'smart_rules_skip_duplicates' => array( 'type' => 'bool', 'default' => 'yes' ),
			'alerts_enabled'            => array( 'type' => 'bool', 'default' => 'yes' ),
			'alert_new_abandoned'       => array( 'type' => 'bool', 'default' => 'yes' ),
			'alert_high_value'          => array( 'type' => 'bool', 'default' => 'yes' ),
			'alert_high_value_threshold' => array( 'type' => 'int', 'default' => 2000 ),
			'alert_live_recovery'       => array( 'type' => 'bool', 'default' => 'yes' ),
			'analytics_days'          => array( 'type' => 'int', 'default' => 30, 'min' => 1, 'max' => 365 ),
			// Conversion tracking for admin-recovered orders (2.5.0).
			'tracking_trigger'        => array( 'type' => 'enum', 'default' => 'created', 'options' => array( 'created', 'paid' ) ),
			'meta_capi_enabled'       => array( 'type' => 'bool', 'default' => 'no' ),
			'meta_pixel_id'           => array( 'type' => 'text', 'default' => '' ),
			'meta_access_token'       => array( 'type' => 'secret', 'default' => '' ),
			'meta_test_event_code'    => array( 'type' => 'text', 'default' => '' ),
			'meta_use_test_code'      => array( 'type' => 'bool', 'default' => 'no' ),
			'meta_action_source'      => array( 'type' => 'enum', 'default' => 'website', 'options' => array( 'website', 'phone_call', 'chat', 'system_generated', 'other' ) ),
			'meta_content_id'         => array( 'type' => 'enum', 'default' => 'id', 'options' => array( 'id', 'sku', 'wc_post_id' ) ),
			'ga4_enabled'             => array( 'type' => 'bool', 'default' => 'no' ),
			'ga4_measurement_id'      => array( 'type' => 'text', 'default' => '' ),
			'ga4_api_secret'          => array( 'type' => 'secret', 'default' => '' ),
			'webhook_enabled'         => array( 'type' => 'bool', 'default' => 'no' ),
			'webhook_url'             => array( 'type' => 'url', 'default' => '' ),
			'webhook_secret'          => array( 'type' => 'secret', 'default' => '' ),
			// Telegram + WhatsApp notifications (3.0.0).
			'notify_store_name'       => array( 'type' => 'text', 'default' => '' ),
			'notify_telegram_enabled' => array( 'type' => 'bool', 'default' => 'no' ),
			'telegram_bot_token'      => array( 'type' => 'secret', 'default' => '' ),
			'telegram_chat_ids'       => array( 'type' => 'text', 'default' => '' ),
			'telegram_scope'          => array( 'type' => 'enum', 'default' => 'both', 'options' => array( 'cart', 'order', 'both' ) ),
			'notify_whatsapp_enabled' => array( 'type' => 'bool', 'default' => 'no' ),
			'whatsapp_scope'          => array( 'type' => 'enum', 'default' => 'both', 'options' => array( 'cart', 'order', 'both' ) ),
			'whatsapp_provider'       => array( 'type' => 'enum', 'default' => 'callmebot', 'options' => array( 'callmebot', 'cloud_api', 'custom' ) ),
			'whatsapp_callmebot_recipients' => array( 'type' => 'textarea', 'default' => '' ),
			'whatsapp_recipients'     => array( 'type' => 'text', 'default' => '' ),
			'whatsapp_cloud_phone_id' => array( 'type' => 'text', 'default' => '' ),
			'whatsapp_cloud_token'    => array( 'type' => 'secret', 'default' => '' ),
			'whatsapp_cloud_template' => array( 'type' => 'text', 'default' => '' ),
			'whatsapp_cloud_template_lang' => array( 'type' => 'text', 'default' => 'en_US' ),
			'whatsapp_custom_url'     => array( 'type' => 'template_url', 'default' => '' ),
			'whatsapp_custom_method'  => array( 'type' => 'enum', 'default' => 'get', 'options' => array( 'get', 'post' ) ),
			'whatsapp_custom_body'    => array( 'type' => 'raw_textarea', 'default' => '{"to":"{phone}","message":"{message}"}' ),
			'notify_events'           => array( 'type' => 'events', 'default' => self::default_events() ),
			'lead_trigger'            => array( 'type' => 'enum', 'default' => 'any', 'options' => array( 'any', 'phone', 'email', 'both' ) ),
			'lead_delay_minutes'      => array( 'type' => 'int', 'default' => 0, 'max' => 1440 ),
			'lead_min_phone_digits'   => array( 'type' => 'int', 'default' => 10, 'min' => 6, 'max' => 15 ),
			'table_columns'           => array( 'type' => 'columns', 'default' => array(
				'id' => true, 'customer' => true, 'address' => true, 'products' => true,
				'total' => true, 'source' => true, 'times' => true, 'live' => true, 'risk' => true,
			) ),
		);
	}

	/**
	 * Notification events → which channel receives them. Keys are the event ids used by
	 * WCCR_Notifications; values are 'yes'/'no' per channel.
	 */
	public static function default_events() {
		return array(
			'lead_captured'    => array( 'telegram' => 'yes', 'whatsapp' => 'yes' ),
			'new_order'        => array( 'telegram' => 'yes', 'whatsapp' => 'yes' ),
			'order_processing' => array( 'telegram' => 'no', 'whatsapp' => 'no' ),
			'order_completed'  => array( 'telegram' => 'no', 'whatsapp' => 'no' ),
			'order_cancelled'  => array( 'telegram' => 'yes', 'whatsapp' => 'no' ),
			'order_refunded'   => array( 'telegram' => 'no', 'whatsapp' => 'no' ),
			'cart_abandoned'   => array( 'telegram' => 'yes', 'whatsapp' => 'no' ),
			'cart_high_value'  => array( 'telegram' => 'yes', 'whatsapp' => 'yes' ),
			'cart_recovered'   => array( 'telegram' => 'yes', 'whatsapp' => 'no' ),
		);
	}

	public function __construct() {
		// Reserved for future use with the Settings API on the classic wp-admin fallback page.
	}

	public static function get_all() {
		$out = array();
		foreach ( self::schema() as $key => $meta ) {
			$out[ $key ] = get_option( 'wccr_' . $key, $meta['default'] );
			if ( 'events' === $meta['type'] ) {
				$out[ $key ] = self::sanitize( $out[ $key ], 'events' );
			}
		}
		return $out;
	}

	public static function save_all( $data ) {
		if ( ! is_array( $data ) ) {
			return;
		}
		foreach ( self::schema() as $key => $meta ) {
			if ( ! array_key_exists( $key, $data ) ) {
				continue;
			}
			$value = self::sanitize( $data[ $key ], $meta['type'] );
			if ( 'enum' === $meta['type'] && ! in_array( $value, $meta['options'], true ) ) {
				$value = $meta['default'];
			}
			if ( 'int' === $meta['type'] ) {
				// Negative delays / percentages / thresholds made no sense and broke logic.
				$value = max( isset( $meta['min'] ) ? $meta['min'] : 0, $value );
				if ( isset( $meta['max'] ) ) {
					$value = min( $meta['max'], $value );
				}
			}
			update_option( 'wccr_' . $key, $value );
		}
	}

	public static function sanitize( $value, $type ) {
		switch ( $type ) {
			case 'bool':
				return ( true === $value || 'yes' === $value || '1' === $value || 1 === $value ) ? 'yes' : 'no';
			case 'int':
				return (int) $value;
			case 'email':
				$value = sanitize_email( (string) $value );
				return is_email( $value ) ? $value : '';
			case 'url':
				return esc_url_raw( trim( (string) $value ) );
			case 'secret':
				// Tokens must not be altered by HTML filters; only strip whitespace / control chars.
				return preg_replace( '/[\s\x00-\x1F]/', '', (string) $value );
			case 'enum':
				return sanitize_key( (string) $value );
			case 'template_url':
				// A gateway URL with {phone} / {message} placeholders — esc_url_raw() would mangle the braces.
				$value = trim( wp_strip_all_tags( (string) $value ) );
				return preg_match( '#^https?://#i', $value ) ? $value : '';
			case 'raw_textarea':
				// JSON body template; must keep quotes and braces as typed.
				return trim( wp_check_invalid_utf8( (string) $value ) );
			case 'events':
				$defaults = self::default_events();
				$value    = is_array( $value ) ? $value : array();
				$out      = array();
				foreach ( $defaults as $event => $channels ) {
					foreach ( $channels as $channel => $default ) {
						$v = isset( $value[ $event ][ $channel ] ) ? $value[ $event ][ $channel ] : $default;
						$out[ $event ][ $channel ] = ( true === $v || 'yes' === $v || '1' === $v || 1 === $v ) ? 'yes' : 'no';
					}
				}
				return $out;
			case 'textarea':
				// sanitize_text_field() stripped line breaks from the WhatsApp template.
				return sanitize_textarea_field( (string) $value );
			case 'columns':
				$allowed = array( 'id', 'customer', 'address', 'products', 'total', 'source', 'times', 'live', 'risk' );
				$value = is_array( $value ) ? $value : array();
				$out = array();
				foreach ( $allowed as $column ) {
					$out[ $column ] = isset( $value[ $column ] ) ? (bool) $value[ $column ] : true;
				}
				return $out;
			case 'text':
			default:
				return sanitize_text_field( wp_kses_post( (string) $value ) );
		}
	}
}
