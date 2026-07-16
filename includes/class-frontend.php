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
		add_action( 'woocommerce_thankyou', array( $this, 'inject_purchase_event' ), 20 );
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

		// Read-only UI toggle (auto-open the widget for an admin previewing
		// their own site) — not a state change, nothing to verify a nonce for.
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( ! empty( $_GET['ocw-fab-open'] ) && current_user_can( 'administrator' ) ) {
			$params['auto_open'] = true;
		}

		$widget_host = 'https://' . $workspace . '.ovebot.ai/widget/chat-loader.js';

		// wp_footer runs inject_widget() at its default priority (10), which is
		// before core's own wp_print_footer_scripts (priority 20) — so enqueuing
		// here still gets picked up and printed in the same footer pass.
		wp_enqueue_script( 'ovebotai-chat-loader', $widget_host, array(), null, true );
		wp_add_inline_script(
			'ovebotai-chat-loader',
			'var ovebot_ai = ovebot_ai || []; ovebot_ai.push(["chat", ' . wp_json_encode( $params ?: (object) array() ) . ']);',
			'before'
		);
	}

	public function inject_purchase_event( int $order_id ) {
		if ( ! $order_id || ! Ovebotai::is_setup_complete() ) return;

		$order = wc_get_order( $order_id );
		if ( ! $order ) return;

		// Guard against re-firing on refresh/re-visit of the thank-you page.
		if ( 'yes' === $order->get_meta( '_ovebotai_purchase_tracked' ) ) return;

		$order->update_meta_data( '_ovebotai_purchase_tracked', 'yes' );
		$order->save();

		$workspace = Ovebotai_OAuth::instance()->get_workspace();
		if ( ! $workspace ) return;

		$event_host = 'https://' . $workspace . '.ovebot.ai/widget/event.js';

		$payload = array(
			'transaction_id' => $order->get_order_number(),
			'total'          => round( (float) $order->get_total(), 2 ),
			'currency'       => $order->get_currency(),
		);

		// woocommerce_thankyou fires while the page content is rendering, well
		// before wp_footer's wp_print_footer_scripts — safe to enqueue here.
		wp_enqueue_script( 'ovebotai-purchase-event', $event_host, array(), null, true );
		wp_add_inline_script(
			'ovebotai-purchase-event',
			'var ovebot_ai = ovebot_ai || []; ovebot_ai.push(["purchase", ' . wp_json_encode( $payload ) . ']);',
			'before'
		);
	}
}
