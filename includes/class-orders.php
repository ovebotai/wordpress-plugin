<?php
defined( 'ABSPATH' ) || exit;

class Ovebotai_Orders {

	private static $instance = null;

	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	public function register_routes() {
		add_action( 'rest_api_init', function () {
			register_rest_route( 'ovebotai/v1', '/orders', array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'handle_request' ),
				'permission_callback' => array( $this, 'check_auth' ),
			) );
		} );
	}

	public function check_auth(): bool {
		$stored_user = (string) get_option( 'ovebotai_order_user', '' );
		$stored_pass = (string) get_option( 'ovebotai_order_pass', '' );

		// If no credentials configured, deny by default.
		if ( '' === $stored_user || '' === $stored_pass ) {
			return false;
		}

		$given_user = '';
		$given_pass = '';

		if ( isset( $_SERVER['PHP_AUTH_USER'] ) ) {
			$given_user = $_SERVER['PHP_AUTH_USER']; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
			$given_pass = $_SERVER['PHP_AUTH_PW'] ?? ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
		} else {
			$header = $_SERVER['HTTP_AUTHORIZATION'] ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? '';
			if ( stripos( $header, 'Basic ' ) === 0 ) {
				$decoded = base64_decode( substr( $header, 6 ) );
				if ( false !== $decoded && strpos( $decoded, ':' ) !== false ) {
					list( $given_user, $given_pass ) = explode( ':', $decoded, 2 );
				}
			}
		}

		return hash_equals( $stored_user, $given_user ) && hash_equals( $stored_pass, $given_pass );
	}

	public function handle_request( WP_REST_Request $request ): WP_REST_Response {
		if ( ! Ovebotai::woocommerce_active() ) {
			return $this->error( __( 'WooCommerce is not active.', 'ovebotai' ), 503 );
		}

		$input    = $request->get_json_params() ?: $request->get_body_params();
		$order_id = absint( $input['id'] ?? 0 );
		$email    = sanitize_email( (string) ( $input['email'] ?? '' ) );
		$phone    = sanitize_text_field( (string) ( $input['phone'] ?? '' ) );

		if ( $order_id <= 0 ) {
			return $this->error( __( 'Invalid order ID.', 'ovebotai' ), 400 );
		}

		if ( ( '' === $email ) === ( '' === $phone ) ) {
			return $this->error( __( 'Provide either email or phone, not both and not neither.', 'ovebotai' ), 400 );
		}

		$order = wc_get_order( $order_id );
		if ( ! $order || $order->get_status() === 'pending' ) {
			return $this->error( __( 'Order not found.', 'ovebotai' ), 200 );
		}

		// Verify ownership.
		if ( '' !== $email ) {
			if ( ! is_email( $email ) ) {
				return $this->error( __( 'Invalid email.', 'ovebotai' ), 400 );
			}
			if ( ! hash_equals( strtolower( $order->get_billing_email() ), strtolower( $email ) ) ) {
				return $this->error( __( 'Order not found.', 'ovebotai' ), 200 );
			}
		} else {
			$stored_phone = $this->normalize_phone( $order->get_billing_phone() );
			$given_phone  = $this->normalize_phone( $phone );
			if ( strlen( $given_phone ) < 9 ) {
				return $this->error( __( 'Invalid phone number.', 'ovebotai' ), 400 );
			}
			if ( '' === $stored_phone || substr( $stored_phone, -strlen( $given_phone ) ) !== $given_phone ) {
				return $this->error( __( 'Order not found.', 'ovebotai' ), 200 );
			}
		}

		return new WP_REST_Response( array(
			'success' => true,
			'data'    => $this->format_order( $order ),
		), 200 );
	}

	private function format_order( WC_Order $order ): array {
		$status   = $order->get_status();
		$date_str = $order->get_date_created() ? $order->get_date_created()->format( 'Y-m-d H:i:s' ) : '';

		return array(
			'id'                 => $order->get_order_number(),
			'date'               => $date_str,
			'status'             => wc_get_order_status_name( $status ),
			'total'              => round( (float) $order->get_total(), 2 ),
			'currency'           => $order->get_currency(),
			'awb'                => null,
			'awb_tracking_url'   => null,
			'carrier'            => null,
			'estimated_delivery' => $this->get_estimated_delivery( $order ),
		);
	}

	private function get_estimated_delivery( WC_Order $order ): ?string {
		$status = $order->get_status();

		$date_created = $order->get_date_created();
		if ( ! $date_created ) return null;
		$date_str = $date_created->format( 'Y-m-d H:i:s' );

		if ( in_array( $status, array( 'completed', 'shipped' ), true ) ) {
			$min = (int) get_option( 'ovebotai_days_shipped_min', 1 );
			$max = (int) get_option( 'ovebotai_days_shipped_max', 2 );
			$from = $this->add_business_days( $date_str, $min );
			$to   = $this->add_business_days( $date_str, $max );
			return $from === $to ? $from : $from . ' - ' . $to;
		}

		if ( in_array( $status, array( 'processing', 'on-hold' ), true ) ) {
			$has_oos = false;
			foreach ( $order->get_items() as $item ) {
				$product = $item->get_product();
				if ( $product && ! $product->is_in_stock() ) {
					$has_oos = true;
					break;
				}
			}

			if ( $has_oos ) {
				$min = (int) get_option( 'ovebotai_days_oos_min', 5 );
				$max = (int) get_option( 'ovebotai_days_oos_max', 10 );
			} else {
				$min = (int) get_option( 'ovebotai_days_instock_min', 2 );
				$max = (int) get_option( 'ovebotai_days_instock_max', 4 );
			}

			$from = $this->add_business_days( $date_str, $min );
			$to   = $this->add_business_days( $date_str, $max );
			return $from === $to ? $from : $from . ' - ' . $to;
		}

		return null;
	}

	private function add_business_days( string $date, int $days ): string {
		$dt = new DateTime( $date );
		while ( $days > 0 ) {
			$dt->modify( '+1 day' );
			if ( (int) $dt->format( 'N' ) !== 7 ) { // Skip Sunday.
				$days--;
			}
		}
		return $dt->format( 'Y-m-d' );
	}

	private function normalize_phone( string $phone ): string {
		$p = preg_replace( '/[^\d+]/', '', $phone );
		if ( strpos( $p, '+4' ) === 0 )   $p = substr( $p, 2 );
		elseif ( strpos( $p, '004' ) === 0 ) $p = substr( $p, 3 );
		$p = preg_replace( '/\D/', '', $p );
		return ltrim( $p, '0' );
	}

	private function error( string $message, int $code ): WP_REST_Response {
		return new WP_REST_Response( array( 'success' => false, 'error' => $message ), $code );
	}
}
