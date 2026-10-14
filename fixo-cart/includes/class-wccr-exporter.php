<?php
/**
 * CSV export of abandoned carts, triggered from the dashboard's "Export CSV" button.
 *
 * @package Fixo_Cart
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class WCCR_Exporter {

	public function __construct() {
		add_action( 'admin_post_wccr_export_csv', array( $this, 'export_csv' ) );
	}

	public function export_csv() {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die( esc_html__( 'Not allowed.', 'fixo-cart' ) );
		}
		check_admin_referer( 'wccr_export_csv' );

		// All dashboard filters are honoured now (the button used to ignore them).
		$filter_keys = array( 'status', 'search', 'date_from', 'date_to', 'min_total', 'max_total', 'source', 'device', 'conversion_type', 'presence', 'product_id', 'risk_status' );
		$args        = array();
		foreach ( $filter_keys as $key ) {
			$args[ $key ] = isset( $_GET[ $key ] ) ? sanitize_text_field( wp_unslash( $_GET[ $key ] ) ) : '';
		}
		if ( $args['status'] && false !== strpos( $args['status'], ',' ) ) {
			$args['status'] = array_filter( array_map( 'sanitize_key', explode( ',', $args['status'] ) ) );
		}

		nocache_headers();
		header( 'Content-Type: text/csv; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename=abandoned-carts-' . gmdate( 'Y-m-d' ) . '.csv' );

		$output = fopen( 'php://output', 'w' );
		fwrite( $output, "\xEF\xBB\xBF" ); // UTF-8 BOM so Excel shows Bangla / accented names correctly.
		fputcsv(
			$output,
			array(
				'ID', 'Status', 'First Name', 'Last Name', 'Email', 'Phone',
				'Address 1', 'Address 2', 'City', 'State', 'Postcode', 'Country',
				'Products', 'Cart Total', 'Currency', 'Source URL', 'Referrer', 'UTM Source', 'UTM Medium', 'UTM Campaign',
				'Device', 'Risk', 'Emails Sent', 'Order ID', 'Created At', 'Updated At',
			)
		);

		// WCCR_Cart::query() caps per_page at 100, so the old single call with per_page=10000
		// silently exported only the first 100 carts. Page through everything instead.
		$page = 1;
		do {
			$result = WCCR_Cart::query( array_merge( $args, array( 'per_page' => 100, 'page' => $page ) ) );
			foreach ( $result['items'] as $row ) {
				$items    = json_decode( (string) $row['cart_contents'], true );
				$products = array();
				if ( is_array( $items ) ) {
					foreach ( $items as $item ) {
						$products[] = ( isset( $item['name'] ) ? $item['name'] : '#' . ( $item['product_id'] ?? '' ) ) . ' x' . ( $item['quantity'] ?? 1 );
					}
				}
				fputcsv(
					$output,
					array_map(
						array( __CLASS__, 'csv_safe' ),
						array(
							$row['id'], $row['status'], $row['first_name'], $row['last_name'], $row['email'], $row['phone'],
							$row['billing_address_1'], $row['billing_address_2'], $row['billing_city'], WCCR_Cart::state_name( $row['billing_country'], $row['billing_state'] ), $row['billing_postcode'], WCCR_Cart::country_name( $row['billing_country'] ),
							implode( ' | ', $products ), $row['cart_total'], $row['currency'], $row['source_url'], $row['referrer'], $row['utm_source'], $row['utm_medium'], $row['utm_campaign'],
							$row['device_type'], $row['risk_status'], $row['emails_sent'], $row['order_id'], $row['created_at'], $row['updated_at'],
						)
					)
				);
			}
			$page++;
		} while ( $page <= (int) $result['total_pages'] && $page <= 1000 );

		fclose( $output );
		exit;
	}

	/**
	 * Prevent CSV/formula injection: a shopper could type "=HYPERLINK(...)" as their name and it
	 * would execute when the store owner opens the export in Excel / Google Sheets.
	 */
	public static function csv_safe( $value ) {
		$value = (string) $value;
		if ( '' !== $value && in_array( $value[0], array( '=', '+', '-', '@', "\t", "\r" ), true ) && ! is_numeric( $value ) ) {
			return "'" . $value;
		}
		return $value;
	}
}
