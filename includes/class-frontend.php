<?php
defined( 'ABSPATH' ) || exit;

class Ovebotai_Frontend {

	private static $instance = null;

	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
			self::$instance->init();
		}
		return self::$instance;
	}

	private function init() {
		add_action( 'wp_footer', array( $this, 'inject_widget' ) );
		// Purchase event: hooked on wp_footer rather than woocommerce_thankyou.
		// The classic thank-you template fires woocommerce_thankyou, but the
		// block-based "Order confirmation" template only does so from its
		// optional "Additional information" block, which many stores don't
		// include - so the order-received page is detected directly instead.
		add_action( 'wp_footer', array( $this, 'inject_purchase_event' ) );
		// Cart endpoint for the chat's add-to-cart/cart-sync script, served
		// through WooCommerce's own front-end AJAX router (?wc-ajax=ovebotai_cart),
		// where the visitor's session and cart are already loaded.
		add_action( 'wc_ajax_ovebotai_cart', array( $this, 'serve_cart' ) );
	}

	// "Add to cart" from the chat is only wired up when everything it depends
	// on is actually on: WooCommerce, the chat itself, product recommendations
	// through the built-in feed (the widget needs the feed's 'ref' ids to add
	// anything), the setting, and a loaded WC cart for this request.
	private function cart_enabled(): bool {
		return Ovebotai::woocommerce_active()
			&& Ovebotai::chat_enabled()
			&& Ovebotai::products_enabled()
			&& ! Ovebotai::products_use_own_feed()
			&& Ovebotai::add_to_cart_enabled()
			&& function_exists( 'WC' ) && WC()->cart instanceof WC_Cart;
	}

	// The visitor's cart in the shape the widget expects for cart_count /
	// cart_items and for ovebot_ai.push(['cart', ...]): 'ref' is the product or
	// variation id (same as the feed), 'price' the unit price on the same basis
	// as the feed (the product's price as stored), 'sku' only when set.
	private function cart_data(): array {
		$currency = Ovebotai::store_currency();
		$items    = array();

		foreach ( WC()->cart->get_cart() as $cart_item ) {
			$product = $cart_item['data'] ?? null;
			if ( ! $product instanceof WC_Product ) continue;

			$item = array(
				'ref'      => (string) ( ! empty( $cart_item['variation_id'] ) ? $cart_item['variation_id'] : $cart_item['product_id'] ),
				'name'     => $product->get_name(),
				'price'    => round( (float) $product->get_price(), 2 ),
				'currency' => $currency,
				'quantity' => (int) $cart_item['quantity'],
			);

			$sku = trim( (string) $product->get_sku() );
			if ( '' !== $sku ) {
				$item['sku'] = $sku;
			}

			$items[] = $item;
		}

		return array(
			'count' => (int) WC()->cart->get_cart_contents_count(),
			'items' => $items,
		);
	}

	// POST ?wc-ajax=ovebotai_cart -> { count, items }. Forbidden while the chat
	// or the add-to-cart setting is off, so the script can't poll a dead feature.
	public function serve_cart() {
		if ( ! $this->cart_enabled() ) {
			wp_send_json( array( 'error' => 'forbidden' ), 403 );
		}

		wp_send_json( $this->cart_data(), 200 );
	}

	public function inject_widget() {
		if ( is_admin() ) return;
		if ( get_option( 'ovebotai_chat_status' ) !== '1' ) return;

		// Routed through Ovebotai_OAuth::get_workspace() rather than a raw
		// get_option() — it validates the slug format before we build a
		// script-src host out of it.
		$workspace = Ovebotai_OAuth::instance()->get_workspace();
		if ( ! $workspace ) return;

		$widget = (array) get_option( 'ovebotai_widget', array() );

		// Build only non-empty params.
		$params = array();
		$allowed = array(
			'subtitle', 'accent_color', 'proactive_message', 'proactive_delay',
			'theme', 'language', 'width', 'height', 'audio_beep', 'side',
			'offset_x', 'offset_y', 'z_index',
		);
		foreach ( $allowed as $key ) {
			if ( isset( $widget[ $key ] ) && '' !== $widget[ $key ] ) {
				$params[ $key ] = $widget[ $key ];
			}
		}

		// z_index is a numeric stacking-order option: forward it as an int when
		// set to a number, and omit it otherwise so the loader falls back to its
		// own default (2147483644) — same treatment as offset/delay.
		if ( isset( $params['z_index'] ) && is_numeric( $params['z_index'] ) ) {
			$params['z_index'] = (int) $params['z_index'];
		} else {
			unset( $params['z_index'] );
		}

		// Tell the loader which agent to use, but only for a non-default agent —
		// the default agent has no id, so omitting the key lets the loader fall
		// back to its own default (same convention as the other optional keys).
		$agent_id = Ovebotai_OAuth::instance()->get_agent_id();
		if ( '' !== $agent_id ) {
			$params['agent'] = $agent_id;
		}

		// Read-only UI toggle (auto-open the widget for an admin previewing
		// their own site) — not a state change, nothing to verify a nonce for.
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( ! empty( $_GET['ocw-fab-open'] ) && current_user_can( 'administrator' ) ) {
			$params['auto_open'] = true;
		}

		$widget_host = 'https://' . $workspace . '.ovebot.ai/widget/chat-loader.js';

		// "Add to cart" from the chat. chat-loader.js reads only the first
		// ['chat', ...] push, so the cart keys ride along with the appearance
		// options in the same push: the function the widget calls, the cart /
		// checkout URLs, and the cart as it is right now. assets/js/cart.js
		// (loaded before the chat loader) provides the function and keeps the
		// widget's copy of the cart in sync afterwards.
		$cart_enabled = $this->cart_enabled();
		$deps         = array();

		if ( $cart_enabled ) {
			$cart = $this->cart_data();

			$params['add_to_cart']  = 'ovebotaiAddToCart';
			$params['cart_url']     = wc_get_cart_url();
			$params['checkout_url'] = wc_get_checkout_url();
			$params['cart_count']   = $cart['count'];
			$params['cart_items']   = $cart['items'];

			wp_enqueue_script( 'ovebotai-cart', OVEBOTAI_URL . 'assets/js/cart.js', array(), OVEBOTAI_VERSION, true );
			wp_add_inline_script(
				'ovebotai-cart',
				'window.ovebotaiCart = ' . wp_json_encode( array(
					'cart_url' => WC_AJAX::get_endpoint( 'ovebotai_cart' ),
					'add_url'  => WC_AJAX::get_endpoint( 'add_to_cart' ),
					// Checkout pages already show the cart: after a successful add
					// from the chat the page is reloaded (cart.js also matches the
					// URL, for checkout pages WC doesn't flag as such).
					'checkout' => ( function_exists( 'is_checkout' ) && is_checkout() ) ? 1 : 0,
				) ) . ';',
				'before'
			);
			$deps[] = 'ovebotai-cart';
		}

		// wp_footer runs inject_widget() at its default priority (10), which is
		// before core's own wp_print_footer_scripts (priority 20) — so enqueuing
		// here still gets picked up and printed in the same footer pass.
		//
		// $ver is deliberately null: this is a remote script versioned and
		// cache-controlled by ovebot.ai, not by us. Appending ?ver=OVEBOTAI_VERSION
		// would key the browser cache to the plugin version — which says nothing
		// about the loader's actual contents — and some CDN configurations skip
		// caching for URLs carrying a query string entirely.
		// phpcs:ignore WordPress.WP.EnqueuedResourceParameters.MissingVersion
		wp_enqueue_script( 'ovebotai-chat-loader', $widget_host, $deps, null, true );

		// The options push goes before whichever of our scripts prints first:
		// cart.js reads the initial cart_count / cart_items off that push, so
		// when it's loaded the push must precede it rather than the loader.
		wp_add_inline_script(
			$cart_enabled ? 'ovebotai-cart' : 'ovebotai-chat-loader',
			'var ovebot_ai = ovebot_ai || []; ovebot_ai.push(["chat", ' . wp_json_encode( $params ?: (object) array() ) . ']);',
			'before'
		);
	}

	public function inject_purchase_event() {
		if ( is_admin() || ! Ovebotai::is_setup_complete() ) return;

		// Purchase tracking belongs with the on-site chat: only inject the event
		// script when the chat widget is enabled (same gate as inject_widget()).
		if ( '1' !== get_option( 'ovebotai_chat_status' ) ) return;

		// The order-received page (classic shortcode or block template alike):
		// /checkout/order-received/{id}/?key={order_key}. Same id resolution as
		// WooCommerce's own thank-you shortcode.
		if ( ! function_exists( 'is_order_received_page' ) || ! is_order_received_page() ) return;

		global $wp;
		$order_id = isset( $wp->query_vars['order-received'] ) ? absint( $wp->query_vars['order-received'] ) : 0;
		$order_id = (int) apply_filters( 'woocommerce_thankyou_order_id', $order_id );
		if ( ! $order_id ) return;

		$order = wc_get_order( $order_id );
		if ( ! $order ) return;

		// Only for the shopper who placed it: the order key in the URL must match
		// (the same check WooCommerce makes before showing the order details).
		// Read-only check on a public page, nothing to verify a nonce for.
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$key = isset( $_GET['key'] ) ? sanitize_text_field( wp_unslash( $_GET['key'] ) ) : '';
		if ( '' === $key || ! hash_equals( (string) $order->get_order_key(), $key ) ) return;

		// Guard against re-firing on refresh/re-visit of the thank-you page.
		if ( 'yes' === $order->get_meta( '_ovebotai_purchase_tracked' ) ) return;

		$order->update_meta_data( '_ovebotai_purchase_tracked', 'yes' );
		$order->save();

		$workspace = Ovebotai_OAuth::instance()->get_workspace();
		if ( ! $workspace ) return;

		$event_host = 'https://' . $workspace . '.ovebot.ai/widget/event.js';

		// One entry per order line. item_id is the product/variation id - the
		// same 'ref' the product feed publishes (variations are their own feed
		// rows). price is the unit price on the same tax basis as the feed:
		// the feed sends prices as entered in WooCommerce, so when the store
		// enters them tax-inclusive the line tax is added back in. Order line
		// amounts are already stored in the order's own currency.
		$items = array();
		foreach ( $order->get_items() as $item ) {
			if ( ! $item instanceof WC_Order_Item_Product ) continue;

			$quantity = max( 1, (int) $item->get_quantity() );
			$line     = (float) $item->get_total();
			if ( wc_prices_include_tax() ) {
				$line += (float) $item->get_total_tax();
			}

			$items[] = array(
				'item_id'   => (string) ( $item->get_variation_id() ?: $item->get_product_id() ),
				'item_name' => $item->get_name(),
				'price'     => round( $line / $quantity, 2 ),
				'quantity'  => $quantity,
			);
		}

		$payload = array(
			'transaction_id' => $order->get_order_number(),
			'total'          => round( (float) $order->get_total(), 2 ),
			'currency'       => $order->get_currency(),
			'items'          => $items,
		);

		// wp_footer runs this at its default priority (10), before core's own
		// wp_print_footer_scripts (priority 20) — safe to enqueue here.
		//
		// $ver is null for the same reason as the chat loader above: remote
		// script, versioned by ovebot.ai rather than by this plugin.
		// phpcs:ignore WordPress.WP.EnqueuedResourceParameters.MissingVersion
		wp_enqueue_script( 'ovebotai-purchase-event', $event_host, array(), null, true );
		wp_add_inline_script(
			'ovebotai-purchase-event',
			'var ovebot_ai = ovebot_ai || []; ovebot_ai.push(["purchase", ' . wp_json_encode( $payload ) . ']);',
			'before'
		);
	}
}
