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

		$workspace = (string) get_option( 'ovebotai_workspace', '' );
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

		if (!empty($_GET['ocw-fab-open']) && is_admin()) {
			$params['auto_open'] = true;
		}

		$widget_host = esc_url( 'https://' . $workspace . '.ovebot.ai/widget/chat-loader.js' );
		?>
<script>
var ovebot_ai = ovebot_ai || [];
ovebot_ai.push(['chat', <?php echo wp_json_encode( $params ?: (object) array() ); ?>]);
</script>
<script src="<?php echo $widget_host; ?>" defer></script>
		<?php
	}

	public function inject_purchase_event( int $order_id ) {
		if ( ! $order_id || ! Ovebotai::is_setup_complete() ) return;

		$order = wc_get_order( $order_id );
		if ( ! $order ) return;

		// Guard against re-firing on refresh/re-visit of the thank-you page.
		if ( 'yes' === $order->get_meta( '_ovebotai_purchase_tracked' ) ) return;

		$order->update_meta_data( '_ovebotai_purchase_tracked', 'yes' );
		$order->save();

		$workspace = (string) get_option( 'ovebotai_workspace', '' );
		$event_host = esc_url( 'https://' . $workspace . '.ovebot.ai/widget/event.js' );

		$payload = array(
			'transaction_id' => $order->get_order_number(),
			'total'          => round( (float) $order->get_total(), 2 ),
			'currency'       => $order->get_currency(),
		);
		?>
<script type="text/javascript">
var ovebot_ai = ovebot_ai || [];
ovebot_ai.push(['purchase', <?php echo wp_json_encode( $payload ); ?>]);
</script>
<script src="<?php echo $event_host; ?>"></script>
		<?php
	}
}
