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
		add_action( 'wp_footer', array( $this, 'maybe_inject_autoopen' ) );
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

		$widget_host = esc_url( 'https://' . $workspace . '.ovebot.ai/widget/chat-loader.js' );
		?>
<script>
var ovebot_ai = ovebot_ai || [];
ovebot_ai.push(['chat', <?php echo wp_json_encode( $params ?: (object) array() ); ?>]);
</script>
<script src="<?php echo $widget_host; ?>" defer></script>
		<?php
	}

	public function maybe_inject_autoopen() {
		if ( is_admin() ) return;
		if ( ! isset( $_GET['ocw-fab-open'] ) || 'true' !== $_GET['ocw-fab-open'] ) return;
		?>
<script>
  (function () {
    var KEY = 'ovebot_autoopened';
    if (localStorage.getItem(KEY)) return;   // vizitatorul a mai fost -> nu deschidem

    var MAX_MS = 15000;
    var t0 = Date.now();

    // Caută butonul flotant în interiorul oricărui shadow root de pe pagină.
    function findFab() {
      var hosts = document.body.querySelectorAll('*');
      for (var i = 0; i < hosts.length; i++) {
        var sr = hosts[i].shadowRoot;
        if (sr) {
          var fab = sr.getElementById('ocw-fab');
          if (fab) return fab;
        }
      }
      return null;
    }

    var timer = setInterval(function () {
      var fab = findFab();
      if (fab) {
        // fab e vizibil doar când chat-ul e închis; ascuns (display:none) când e deja deschis
        var visible = window.getComputedStyle(fab).display !== 'none';
        if (visible) {
          localStorage.setItem(KEY, '1');
          fab.click();
        }
        clearInterval(timer);   // widget-ul e prezent -> oprim polling-ul oricum
      } else if (Date.now() - t0 > MAX_MS) {
        clearInterval(timer);
      }
    }, 200);
  })();
  </script>
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
