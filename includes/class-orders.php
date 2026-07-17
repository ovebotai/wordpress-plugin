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

	// Auth failures only (never successes) count against this — the shared
	// credentials are used by Ovebot.ai's backend on behalf of every customer
	// chatting on the site, all from the same source IP, so throttling on
	// every request (or on ownership mismatches like a wrong email/phone,
	// which are expected/normal traffic) would risk locking out real
	// customers. A wrong Basic Auth pair, on the other hand, should never
	// happen from the legitimate caller, so it's safe to throttle hard.
	//
	// Past the 10th failure, each further failure — including ones made
	// while already blocked — pushes block_until another 15 minutes out
	// from wherever it currently stands, rather than resetting a fixed
	// 15-minute timer from "now". E.g. failure #11 blocks until +15m; if
	// failure #12 arrives 10m later (still inside that window), the wait
	// becomes +15m again from THAT block_until, i.e. 20m remaining at the
	// moment of failure #12 — continuing to hammer only digs the hole
	// deeper. Capped so a burst can't push the lockout out indefinitely.
	const AUTH_FAIL_LIMIT      = 10;
	const AUTH_FAIL_BLOCK_STEP = 15 * MINUTE_IN_SECONDS;
	const AUTH_FAIL_MAX_BLOCK  = 2 * HOUR_IN_SECONDS;
	const AUTH_FAIL_RECORD_TTL = 6 * HOUR_IN_SECONDS;

	public function check_auth(): bool {
		$ip = $this->client_ip();
		if ( $this->is_rate_limited( $ip ) ) {
			// Still counts as a failure — see the extension logic above.
			$this->record_auth_failure( $ip );
			return false;
		}

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
			$header = sanitize_text_field( wp_unslash( $_SERVER['HTTP_AUTHORIZATION'] ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? '' ) );
			if ( stripos( $header, 'Basic ' ) === 0 ) {
				$decoded = base64_decode( substr( $header, 6 ) );
				if ( false !== $decoded && strpos( $decoded, ':' ) !== false ) {
					list( $given_user, $given_pass ) = explode( ':', $decoded, 2 );
				}
			}
		}

		$ok = hash_equals( $stored_user, $given_user ) && hash_equals( $stored_pass, $given_pass );
		if ( ! $ok ) {
			$this->record_auth_failure( $ip );
		}

		return $ok;
	}

	private function client_ip(): string {
		// REMOTE_ADDR only — headers like X-Forwarded-For are trivially
		// spoofable and would let an attacker reset their own rate-limit
		// bucket on every request by rotating the header value.
		return isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : '';
	}

	private function is_rate_limited( string $ip ): bool {
		if ( '' === $ip ) return false;
		$rec = get_transient( 'ovebotai_orders_fails_' . md5( $ip ) );
		return is_array( $rec ) && time() < ( $rec['block_until'] ?? 0 );
	}

	private function record_auth_failure( string $ip ): void {
		if ( '' === $ip ) return;

		$key = 'ovebotai_orders_fails_' . md5( $ip );
		$rec = get_transient( $key );
		if ( ! is_array( $rec ) ) {
			$rec = array( 'count' => 0, 'block_until' => 0 );
		}

		$rec['count']++;

		if ( $rec['count'] > self::AUTH_FAIL_LIMIT ) {
			// Extend from the existing block_until if still inside it (the
			// cumulative-penalty behaviour), otherwise start a fresh
			// AUTH_FAIL_BLOCK_STEP from now.
			$base              = max( time(), $rec['block_until'] );
			$rec['block_until'] = min( $base + self::AUTH_FAIL_BLOCK_STEP, time() + self::AUTH_FAIL_MAX_BLOCK );
		}

		set_transient( $key, $rec, self::AUTH_FAIL_RECORD_TTL );
	}

	public function handle_request( WP_REST_Request $request ): WP_REST_Response {
		if ( ! Ovebotai::woocommerce_active() ) {
			return $this->error( __( 'WooCommerce is not active.', 'ovebot-ai-chatbot-live-chat-ai-sales-agent-for-woocommerce' ), 503 );
		}

		$input    = $request->get_json_params() ?: $request->get_body_params();
		$order_id = absint( $input['id'] ?? 0 );
		$email    = sanitize_email( (string) ( $input['email'] ?? '' ) );
		$phone    = sanitize_text_field( (string) ( $input['phone'] ?? '' ) );

		if ( $order_id <= 0 ) {
			return $this->error( __( 'Invalid order ID.', 'ovebot-ai-chatbot-live-chat-ai-sales-agent-for-woocommerce' ), 400 );
		}

		if ( ( '' === $email ) === ( '' === $phone ) ) {
			return $this->error( __( 'Provide either email or phone, not both and not neither.', 'ovebot-ai-chatbot-live-chat-ai-sales-agent-for-woocommerce' ), 400 );
		}

		$order = wc_get_order( $order_id );
		if ( ! $order || $order->get_status() === 'pending' ) {
			return $this->error( __( 'Order not found.', 'ovebot-ai-chatbot-live-chat-ai-sales-agent-for-woocommerce' ), 200 );
		}

		// Verify ownership.
		if ( '' !== $email ) {
			if ( ! is_email( $email ) ) {
				return $this->error( __( 'Invalid email.', 'ovebot-ai-chatbot-live-chat-ai-sales-agent-for-woocommerce' ), 400 );
			}
			if ( ! hash_equals( strtolower( $order->get_billing_email() ), strtolower( $email ) ) ) {
				return $this->error( __( 'Order not found.', 'ovebot-ai-chatbot-live-chat-ai-sales-agent-for-woocommerce' ), 200 );
			}
		} else {
			$stored_phone = $this->normalize_phone( $order->get_billing_phone() );
			$given_phone  = $this->normalize_phone( $phone );
			if ( strlen( $given_phone ) < 9 ) {
				return $this->error( __( 'Invalid phone number.', 'ovebot-ai-chatbot-live-chat-ai-sales-agent-for-woocommerce' ), 400 );
			}
			if ( '' === $stored_phone || substr( $stored_phone, -strlen( $given_phone ) ) !== $given_phone ) {
				return $this->error( __( 'Order not found.', 'ovebot-ai-chatbot-live-chat-ai-sales-agent-for-woocommerce' ), 200 );
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
		$tracking = $this->get_order_tracking( $order );

		return array(
			'id'                 => $order->get_order_number(),
			'date'               => $date_str,
			'status'             => wc_get_order_status_name( $status ),
			'total'              => round( (float) $order->get_total(), 2 ),
			'currency'           => $order->get_currency(),
			'awb'                => $tracking['awb'] ?? null,
			'awb_tracking_url'   => $tracking['tracking_url'] ?? null,
			'carrier'            => $tracking['carrier'] ?? null,
			'estimated_delivery' => $this->get_estimated_delivery( $order ),
		);
	}

	// ── AWB / carrier / tracking URL ────────────────────────────────────────
	//
	// We don't generate or own any of this data — it's read from whichever
	// shipping plugin the store actually uses to create labels/AWBs. Each
	// adapter below queries that plugin's own storage directly (never its
	// PHP classes/methods, which are often private, DI-wired, or would
	// re-run constructor side effects) and degrades to null if the plugin
	// isn't installed/active or simply has no data for this order yet.
	// See plans/order-tracking-adapters.md for the full research behind this.

	private function get_order_tracking( WC_Order $order ): ?array {
		$adapters = array(
			'get_tracking_from_a2z_fedex',
			'get_tracking_from_colissimo',
			'get_tracking_from_gls',
			'get_tracking_from_packeta',
			'get_tracking_from_sameday',
			'get_tracking_from_seur',
			'get_tracking_from_multishipping',
			'get_tracking_from_dpd_baltic',
			'get_tracking_from_fancourier',
		);

		foreach ( $adapters as $method ) {
			$result = $this->$method( $order );
			if ( $result ) return $result;
		}

		return null;
	}

	// Table names below are always $wpdb->prefix + a hardcoded literal (never
	// user input), so this only checks existence — no value to parameterize.
	// Direct query is unavoidable: these are third-party plugins' own custom
	// tables, outside any WP API, and their contents aren't ours to cache.
	private function table_exists( string $table ): bool {
		global $wpdb;
		return $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) === $table; // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
	}

	// a2z-fedex-shipping: {$wpdb->prefix}shipi_fedex_meta (order_id, meta_key,
	// meta_value), row meta_key='values' holds a JSON array of shipments.
	// shipi_get_meta() is a method on hitshippo_fedex_parent, not a global
	// function — instantiating that class would re-register its hooks as a
	// side effect, so we read the table directly instead.
	private function get_tracking_from_a2z_fedex( WC_Order $order ): ?array {
		global $wpdb;
		$table = esc_sql( $wpdb->prefix . 'shipi_fedex_meta' );
		if ( ! $this->table_exists( $table ) ) return null;

		// $table is never user input (see table_exists() above) — only the
		// %d/%s placeholders below carry values, which prepare() does escape.
		// Direct query is unavoidable: third-party plugin's own table, no WP API.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$raw = $wpdb->get_var( $wpdb->prepare(
			"SELECT meta_value FROM $table WHERE order_id = %d AND meta_key = %s", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$order->get_id(),
			'values'
		) );
		if ( ! $raw ) return null;

		$shipments = json_decode( maybe_unserialize( $raw ), true );
		if ( empty( $shipments ) || ! is_array( $shipments ) ) return null;

		// New shipments are appended, so the last element is the most recent
		// one — matters for orders shipped in multiple partial shipments.
		$shipment = end( $shipments );
		if ( empty( $shipment['tracking_num'] ) ) return null;

		return array(
			'awb'          => (string) $shipment['tracking_num'],
			'carrier'      => 'FedEx',
			'tracking_url' => 'https://track.myshipi.com/?no=' . rawurlencode( $shipment['tracking_num'] ) . '&track=1&embed=1',
		);
	}

	// colissimo-shipping-methods-for-woocommerce: {$wpdb->prefix}lpc_outward_label
	// holds one row per parcel (supports multi-parcel orders); prefer the
	// MASTER parcel if present, else the most recently created row. The
	// single order-meta 'lpc_outward_parcel_number' the plugin also writes
	// gets blanked when any one label on the order is deleted, even if other
	// parcels still exist — the table is the more reliable source.
	private function get_tracking_from_colissimo( WC_Order $order ): ?array {
		global $wpdb;
		$table = esc_sql( $wpdb->prefix . 'lpc_outward_label' );
		if ( ! $this->table_exists( $table ) ) return null;

		// Direct query is unavoidable: third-party plugin's own table, no WP API.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$row = $wpdb->get_row( $wpdb->prepare(
			"SELECT tracking_number FROM $table WHERE order_id = %d ORDER BY FIELD(label_type, 'MASTER') DESC, id DESC LIMIT 1", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$order->get_id()
		) );
		if ( ! $row || ! $row->tracking_number ) return null;

		$tracking_number = (string) $row->tracking_number;

		return array(
			'awb'          => $tracking_number,
			'carrier'      => 'Colissimo',
			'tracking_url' => 'https://www.laposte.fr/outils/suivre-vos-envois?code=' . rawurlencode( $tracking_number ),
		);
	}

	// gls-shipping-for-woocommerce: order meta '_gls_tracking_codes' (array,
	// current) with legacy singular '_gls_tracking_code' fallback.
	private function get_tracking_from_gls( WC_Order $order ): ?array {
		$codes = $order->get_meta( '_gls_tracking_codes' );
		if ( empty( $codes ) || ! is_array( $codes ) ) {
			$legacy = $order->get_meta( '_gls_tracking_code' );
			$codes  = $legacy ? array( $legacy ) : array();
		}
		if ( empty( $codes ) ) return null;

		$tracking_number = (string) reset( $codes );

		return array(
			'awb'          => $tracking_number,
			'carrier'      => 'GLS',
			'tracking_url' => 'https://gls-group.eu/GROUP/en/parcel-tracking/?match=' . rawurlencode( $tracking_number ),
		);
	}

	// packeta: {$wpdb->prefix}packetery_order (id = WC order id), columns
	// packet_id (AWB) + carrier_id. carrier_id is only a numeric foreign key
	// into {$wpdb->prefix}packetery_carrier for real (non pickup-point)
	// carriers, so the name lookup only runs when it's numeric.
	private function get_tracking_from_packeta( WC_Order $order ): ?array {
		global $wpdb;
		$table = esc_sql( $wpdb->prefix . 'packetery_order' );
		if ( ! $this->table_exists( $table ) ) return null;

		$row = $wpdb->get_row( $wpdb->prepare( "SELECT packet_id, carrier_id FROM $table WHERE id = %d", $order->get_id() ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter
		if ( ! $row || ! $row->packet_id ) return null;

		$carrier_label = 'Packeta';
		if ( $row->carrier_id !== null && is_numeric( $row->carrier_id ) ) {
			$carrier_table = esc_sql( $wpdb->prefix . 'packetery_carrier' );
			if ( $this->table_exists( $carrier_table ) ) {
				$carrier_name = $wpdb->get_var( $wpdb->prepare( "SELECT name FROM $carrier_table WHERE id = %d", (int) $row->carrier_id ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter
				if ( $carrier_name ) {
					$carrier_label = (string) $carrier_name;
				}
			}
		}

		return array(
			'awb'          => (string) $row->packet_id,
			'carrier'      => $carrier_label,
			'tracking_url' => 'https://tracking.packeta.com/Z' . rawurlencode( $row->packet_id ),
		);
	}

	// samedaycourier-shipping: {$wpdb->prefix}sameday_awb (order_id, awb_number).
	// Public tracking page confirmed by the user: sameday.ro/status-colet/.
	private function get_tracking_from_sameday( WC_Order $order ): ?array {
		global $wpdb;
		$table = esc_sql( $wpdb->prefix . 'sameday_awb' );
		if ( ! $this->table_exists( $table ) ) return null;

		// Direct query is unavoidable: third-party plugin's own table, no WP API.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$row = $wpdb->get_row( $wpdb->prepare(
			"SELECT awb_number FROM $table WHERE order_id = %d ORDER BY id DESC LIMIT 1", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$order->get_id()
		) );
		if ( ! $row || ! $row->awb_number ) return null;

		return array(
			'awb'          => (string) $row->awb_number,
			'carrier'      => 'Sameday',
			'tracking_url' => 'https://sameday.ro/status-colet/?awb=' . rawurlencode( $row->awb_number ),
		);
	}

	// seur: order meta '_seur_shipping_id_number'. The plugin's own tracking
	// URL includes a delivery-date param that's only known once delivered —
	// its own template still works with that param blank/absent, so we omit it.
	private function get_tracking_from_seur( WC_Order $order ): ?array {
		$tracking_number = $order->get_meta( '_seur_shipping_id_number' );
		if ( ! $tracking_number ) return null;

		return array(
			'awb'          => (string) $tracking_number,
			'carrier'      => 'SEUR',
			'tracking_url' => 'https://www.seur.com/livetracking/pages/seguimiento-online.do?segOnlineIdentificador=' . rawurlencode( $tracking_number ),
		);
	}

	// wc-multishipping: {$wpdb->prefix}wms_labels (order_id, shipping_provider,
	// outward_tracking_number), one row per parcel across UPS/Chronopost/
	// Mondial Relay sub-modules. Mondial Relay's URL needs a store-wide
	// customer/brand code pair, read from the same options the plugin itself
	// uses — falls back to null if the merchant never configured it.
	private function get_tracking_from_multishipping( WC_Order $order ): ?array {
		global $wpdb;
		$table = esc_sql( $wpdb->prefix . 'wms_labels' );
		if ( ! $this->table_exists( $table ) ) return null;

		// Direct query is unavoidable: third-party plugin's own table, no WP API.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$row = $wpdb->get_row( $wpdb->prepare(
			"SELECT shipping_provider, outward_tracking_number FROM $table WHERE order_id = %d AND outward_tracking_number != '' ORDER BY id DESC LIMIT 1", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$order->get_id()
		) );
		if ( ! $row || ! $row->outward_tracking_number ) return null;

		$carriers = array(
			'ups'           => array( 'UPS', 'https://www.ups.com/WebTracking/processInputRequest?tracknum=%s&loc=en_US&requester=ST/trackdetails' ),
			'chronopost'    => array( 'Chronopost', 'https://www.chronopost.fr/fr/chrono_suivi_search?listeNumerosLT=%s' ),
			'mondial_relay' => array( 'Mondial Relay', null ), // built below — needs store-wide ens code
		);
		[ $label, $url_template ] = $carriers[ $row->shipping_provider ] ?? array( ucfirst( $row->shipping_provider ), null );

		if ( 'mondial_relay' === $row->shipping_provider ) {
			$ens = get_option( 'wms_mondial_relay_customer_code', '' ) . get_option( 'wms_mondial_relay_brand_code', '' );
			if ( '' !== $ens ) {
				$url_template = 'https://www.mondialrelay.com/public/permanent/tracking.aspx?ens=' . rawurlencode( $ens ) . '&exp=%s&language=fr';
			}
		}

		return array(
			'awb'          => (string) $row->outward_tracking_number,
			'carrier'      => $label,
			'tracking_url' => $url_template ? sprintf( $url_template, rawurlencode( $row->outward_tracking_number ) ) : null,
		);
	}

	// woo-shipping-dpd-baltic: {$wpdb->prefix}dpd_barcodes (order_id, dpd_barcode).
	// dpdgroup.com is the URL the plugin actually shows the customer (via a
	// customer-facing order note); tracking.dpd.de is only used in an
	// admin-only note, so we don't use it here.
	private function get_tracking_from_dpd_baltic( WC_Order $order ): ?array {
		global $wpdb;
		$table = esc_sql( $wpdb->prefix . 'dpd_barcodes' );
		if ( ! $this->table_exists( $table ) ) return null;

		// Direct query is unavoidable: third-party plugin's own table, no WP API.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$row = $wpdb->get_row( $wpdb->prepare(
			"SELECT dpd_barcode FROM $table WHERE order_id = %d ORDER BY id DESC LIMIT 1", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$order->get_id()
		) );
		if ( ! $row || ! $row->dpd_barcode ) return null;

		switch ( get_option( 'dpd_api_service_provider' ) ) {
			case 'lt': $country = 'lt'; $lang = 'lt_lt'; break;
			case 'lv': $country = 'lv'; $lang = 'lv_lv'; break;
			case 'ee': $country = 'ee'; $lang = 'et_et'; break;
			default:   $country = 'lt'; $lang = 'en';    break;
		}

		return array(
			'awb'          => (string) $row->dpd_barcode,
			'carrier'      => 'DPD',
			'tracking_url' => 'https://www.dpdgroup.com/' . $country . '/mydpd/my-parcels/track?lang=' . $lang . '&parcelNumber=' . rawurlencode( $row->dpd_barcode ),
		);
	}

	// hge-zone-de-livrare-pentru-fan-courier-romania: order meta '_hgezlpfcr_awb_number',
	// with a legacy '_fc_awb_number' fallback for orders synced before v2.0.9.
	// The plugin only calls FanCourier's private status API internally — it never
	// builds a public tracking link — so we construct one ourselves.
	private function get_tracking_from_fancourier( WC_Order $order ): ?array {
		$awb = $order->get_meta( '_hgezlpfcr_awb_number' );
		if ( ! $awb ) {
			$awb = $order->get_meta( '_fc_awb_number' );
		}
		if ( ! $awb ) return null;

		return array(
			'awb'          => (string) $awb,
			'carrier'      => 'FanCourier',
			'tracking_url' => 'https://www.fancourier.ro/awb-tracking/?awb=' . rawurlencode( $awb ),
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
