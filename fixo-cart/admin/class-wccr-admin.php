<?php
/**
 * wp-admin integration: top-level menu + the page that hosts the React dashboard.
 *
 * @package Fixo_Cart
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class WCCR_Admin {

	const PAGE_SLUG = 'fixo-cart';

	public function __construct() {
		add_action( 'admin_menu', array( $this, 'register_menu' ) );
		add_filter( 'submenu_file', array( $this, 'highlight_submenu' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_assets' ) );
		add_filter( 'plugin_action_links_' . plugin_basename( FIXO_CART_PLUGIN_FILE ), array( $this, 'add_settings_link' ) );

		// Small "Recovered via cart" badge + meta box on the WooCommerce order screen.
		add_action( 'add_meta_boxes', array( $this, 'add_order_meta_box' ) );
	}

	public function register_menu() {
		// Cached for 2 minutes — this COUNT(*) used to run on every single wp-admin page load.
		$count = get_transient( 'wccr_abandoned_phone_count' );
		if ( false === $count ) {
			$count = WCCR_Cart::get_abandoned_count();
			set_transient( 'wccr_abandoned_phone_count', $count, 2 * MINUTE_IN_SECONDS );
		}
		$menu_title = __( 'Fixo Cart', 'fixo-cart' );
		if ( $count > 0 ) {
			$menu_title .= ' <span class="awaiting-mod" title="' . esc_attr__( 'Abandoned carts with a phone number', 'fixo-cart' ) . '"><span class="pending-count">' . (int) $count . '</span></span>';
		}

		add_menu_page(
			__( 'Fixo Cart', 'fixo-cart' ),
			$menu_title,
			'manage_woocommerce',
			self::PAGE_SLUG,
			array( $this, 'render_page' ),
			'dashicons-cart',
			55.1
		);

		// Shortcuts into the dashboard views (the React app reads ?view=).
		$views = array(
			'carts'         => __( 'Abandoned carts', 'fixo-cart' ),
			'recovered'     => __( 'Recovered orders', 'fixo-cart' ),
			'orders'        => __( 'Orders', 'fixo-cart' ),
			'live'          => __( 'Live customers', 'fixo-cart' ),
			'notifications' => __( 'Notifications', 'fixo-cart' ),
			'settings'      => __( 'Settings', 'fixo-cart' ),
		);
		foreach ( $views as $view => $label ) {
			add_submenu_page(
				self::PAGE_SLUG,
				$label,
				$label,
				'manage_woocommerce',
				'carts' === $view ? self::PAGE_SLUG : 'admin.php?page=' . self::PAGE_SLUG . '&view=' . $view
			);
		}
	}

	/** Highlight the submenu entry that matches ?view= (they all share one page). */
	public function highlight_submenu( $submenu_file ) {
		global $plugin_page;
		if ( self::PAGE_SLUG !== $plugin_page || empty( $_GET['view'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification
			return $submenu_file;
		}
		$view = sanitize_key( wp_unslash( $_GET['view'] ) ); // phpcs:ignore WordPress.Security.NonceVerification
		return 'carts' === $view ? $submenu_file : 'admin.php?page=' . self::PAGE_SLUG . '&view=' . $view;
	}

	public function add_settings_link( $links ) {
		$url = admin_url( 'admin.php?page=' . self::PAGE_SLUG . '&view=settings' );
		array_unshift( $links, '<a href="' . esc_url( $url ) . '">' . esc_html__( 'Dashboard', 'fixo-cart' ) . '</a>' );
		return $links;
	}

	/**
	 * The whole admin screen is one <div id="wccr-dashboard-root">, React takes over from there.
	 */
	public function render_page() {
		echo '<div id="wccr-dashboard-root" class="wrap"></div>';
	}

	public function enqueue_assets( $hook ) {
		if ( 'toplevel_page_' . self::PAGE_SLUG !== $hook ) {
			return;
		}

		wp_enqueue_style(
			'wccr-admin-dashboard',
			FIXO_CART_PLUGIN_URL . 'admin/css/dashboard.css',
			array(),
			FIXO_CART_VERSION
		);

		// wp-element / wp-components / wp-api-fetch ship with WordPress core — no build step needed.
		wp_enqueue_script(
			'wccr-admin-dashboard',
			FIXO_CART_PLUGIN_URL . 'admin/js/dashboard.js',
			array( 'wp-element', 'wp-api-fetch', 'wp-i18n' ),
			FIXO_CART_VERSION,
			true
		);

		wp_set_script_translations( 'wccr-admin-dashboard', 'fixo-cart', FIXO_CART_PLUGIN_DIR . 'languages' );

		wp_localize_script(
			'wccr-admin-dashboard',
			'WCCR_Admin',
			array(
				'rest_url'       => esc_url_raw( trailingslashit( rest_url() ) ),
				'nonce'          => wp_create_nonce( 'wp_rest' ),
				'currency_symbol' => get_woocommerce_currency_symbol(),
				'export_csv_url' => wp_nonce_url( admin_url( 'admin-post.php?action=wccr_export_csv' ), 'wccr_export_csv' ),
				'version'        => FIXO_CART_VERSION,
				'page_slug'      => self::PAGE_SLUG,
				'home_url'       => home_url( '/' ),
				'site_name'      => wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES ),
				'strings'        => array(
					'title' => __( 'Fixo Cart', 'fixo-cart' ),
				),
			)
		);
	}

	/**
	 * Show a small "Recovered from abandoned cart" box on orders that came from this plugin.
	 */
	public function add_order_meta_box() {
		$screen = class_exists( '\Automattic\WooCommerce\Utilities\OrderUtil' ) && \Automattic\WooCommerce\Utilities\OrderUtil::custom_orders_table_usage_is_enabled()
			? wc_get_page_screen_id( 'shop-order' )
			: 'shop_order';

		add_meta_box(
			'wccr_order_source',
			__( 'Fixo Cart', 'fixo-cart' ),
			array( $this, 'render_order_meta_box' ),
			$screen,
			'side'
		);
	}

	public function render_order_meta_box( $post_or_order ) {
		$order = $post_or_order instanceof WC_Order ? $post_or_order : ( isset( $post_or_order->ID ) ? wc_get_order( $post_or_order->ID ) : false );
		if ( ! $order ) {
			return;
		}

		$cart_id  = $order->get_meta( '_wccr_recovered_cart_id' );
		$source   = $order->get_meta( '_wccr_source_url' );
		$conversion_type = $order->get_meta( '_wccr_conversion_type' );
		$converted_by = $order->get_meta( '_wccr_converted_by_user_id' );
		$utm      = $order->get_meta( '_wccr_utm_source' );

		if ( ! $cart_id && ! $source && 'admin_recovery' !== $conversion_type ) {
			echo '<p>' . esc_html__( 'No cart recovery data for this order.', 'fixo-cart' ) . '</p>';
			return;
		}

		if ( 'admin_recovery' === $conversion_type ) {
			echo '<p><strong>' . esc_html__( 'Revenue attribution:', 'fixo-cart' ) . '</strong> ' . esc_html__( 'Admin recovery', 'fixo-cart' ) . '</p>';
			if ( $converted_by ) {
				$user = get_user_by( 'id', absint( $converted_by ) );
				if ( $user ) {
					printf( '<p><strong>%s</strong> %s</p>', esc_html__( 'Converted by:', 'fixo-cart' ), esc_html( $user->display_name ) );
				}
			}
		} elseif ( 'customer_checkout' === $conversion_type ) {
			echo '<p><strong>' . esc_html__( 'Revenue attribution:', 'fixo-cart' ) . '</strong> ' . esc_html__( 'Customer checkout', 'fixo-cart' ) . '</p>';
		}

		if ( $cart_id ) {
			printf(
				'<p><strong>%s</strong> <a href="%s">#%d</a></p>',
				esc_html__( 'Recovered cart:', 'fixo-cart' ),
				esc_url( admin_url( 'admin.php?page=' . self::PAGE_SLUG . '&view=carts&cart=' . (int) $cart_id ) ),
				(int) $cart_id
			);
		}

		$label = $order->get_meta( '_wccr_source_label' );
		if ( $label ) {
			printf( '<p><strong>%s</strong> %s</p>', esc_html__( 'Traffic source:', 'fixo-cart' ), esc_html( $label ) );
		}

		$rows = array(
			'_wccr_landing_page' => __( 'Landing page', 'fixo-cart' ),
			'_wccr_referrer'     => __( 'Referrer', 'fixo-cart' ),
			'_wccr_utm_source'   => 'utm_source',
			'_wccr_utm_medium'   => 'utm_medium',
			'_wccr_utm_campaign' => 'utm_campaign',
			'_wccr_utm_term'     => 'utm_term',
			'_wccr_utm_content'  => 'utm_content',
			'_wccr_fbclid'       => 'fbclid',
			'_wccr_gclid'        => 'gclid',
			'_wccr_ttclid'       => 'ttclid',
			'_wccr_device_type'  => __( 'Device', 'fixo-cart' ),
		);
		$out = '';
		foreach ( $rows as $key => $title ) {
			$value = $order->get_meta( $key );
			if ( ! $value && '_wccr_landing_page' === $key ) {
				$value = $source;
			}
			if ( ! $value ) {
				continue;
			}
			$is_url = in_array( $key, array( '_wccr_landing_page', '_wccr_referrer' ), true );
			$out   .= '<tr><th style="text-align:left;padding:3px 6px 3px 0;vertical-align:top;font-weight:600;white-space:nowrap">' . esc_html( $title ) . '</th><td style="padding:3px 0;word-break:break-all">'
				. ( $is_url ? '<a href="' . esc_url( $value ) . '" target="_blank" rel="noreferrer">' . esc_html( $value ) . '</a>' : esc_html( $value ) )
				. '</td></tr>';
		}
		if ( $out ) {
			echo '<table style="width:100%;font-size:12px;border-collapse:collapse">' . $out . '</table>'; // phpcs:ignore WordPress.Security.EscapeOutput -- escaped above.
		}

		$sent = array();
		foreach ( array( 'meta' => 'Meta CAPI', 'ga4' => 'GA4', 'webhook' => 'Webhook' ) as $key => $title ) {
			if ( $order->get_meta( '_wccr_' . $key . '_sent_at' ) ) {
				$sent[] = $title . ' ✓';
			}
		}
		if ( $sent ) {
			printf( '<p><strong>%s</strong> %s</p>', esc_html__( 'Events sent:', 'fixo-cart' ), esc_html( implode( ' · ', $sent ) ) );
		}
	}
}
