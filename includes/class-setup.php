<?php
defined( 'ABSPATH' ) || exit;

class Ovebotai_Setup {

	private static $instance = null;

	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
			self::$instance->init();
		}
		return self::$instance;
	}

	private function init() {
		add_action( 'wp_ajax_ovebotai_sync', array( $this, 'ajax_sync' ) );
	}

	/**
	 * Step 4: send pages to knowledge-base + PUT setup, mark complete.
	 */
	public function ajax_sync() {
		check_ajax_referer( 'ovebotai_setup', 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'Permission denied.', 'ovebotai' ) ) );
		}

		$page_ids = array_map( 'absint', (array) ( $_POST['page_ids'] ?? array() ) );
		update_option( 'ovebotai_kb_page_ids', $page_ids, false );

		$oauth  = Ovebotai_OAuth::instance();
		$errors = $this->sync_kb_pages( $page_ids, true );

		// ── Setup: feed (if WooCommerce is active) + order info ──────────────

		$order_url = home_url( '/wp-json/ovebotai/v1/orders' );

		$setup_payload = array(
			'order_info' => array(
				'enabled'       => true,
				'api_url'       => $order_url,
				'api_user'      => (string) get_option( 'ovebotai_order_user', '' ),
				'api_password'  => (string) get_option( 'ovebotai_order_pass', '' ),
				'lookup_method' => 'email',
			),
		);

		// Checked live — never cached — so the setup call always matches whether
		// WooCommerce is actually installed right now.
		if ( Ovebotai::woocommerce_active() ) {
			$feed_hash = (string) get_option( 'ovebotai_feed_hash', '' );
			$setup_payload['products'] = array(
				'enabled'  => true,
				'feed_url' => add_query_arg( 'hash', $feed_hash, home_url( '/wp-json/ovebotai/v1/feed' ) ),
				'currency' => Ovebotai::store_currency(),
			);
		}

		$setup_result = $oauth->api_request( 'PUT', $oauth->setup_api_path(), $setup_payload );

		if ( ( $setup_result['status'] ?? 0 ) < 200 || ( $setup_result['status'] ?? 0 ) >= 300 ) {
			$errors[] = __( 'Could not sync feed and order settings with Ovebot.ai.', 'ovebotai' );
		}

		// ── Finalize ─────────────────────────────────────────────────────────

		if ( empty( $errors ) ) {
			update_option( 'ovebotai_chat_status',   '1', false );
			update_option( 'ovebotai_setup_complete', '1', false );
			wp_send_json_success( array( 'message' => __( 'Setup complete!', 'ovebotai' ) ) );
		} else {
			wp_send_json_error( array(
				'message' => implode( ' ', $errors ),
				'errors'  => $errors,
			) );
		}
	}

	/**
	 * Create/update ($active = true) or soft-deactivate ($active = false) the
	 * KB entry for each given page. Pages already carrying an _ovebotai_kb_id
	 * are PUT to that id instead of re-created, so re-running setup/reconnect
	 * (or an unrelated settings save) never duplicates entries.
	 *
	 * Ovebot's API has no DELETE for knowledge-base entries — only an
	 * `is_active` flag (.tasks/oauth-api.md §5) — so unchecking a page
	 * deactivates its entry rather than removing it. The _ovebotai_kb_id
	 * mapping is kept either way, so re-checking the page later reactivates
	 * the same entry instead of creating a duplicate.
	 *
	 * Returns an array of human-readable error strings, one per page that
	 * failed to sync.
	 */
	public function sync_kb_pages( array $page_ids, bool $active = true ): array {
		$oauth  = Ovebotai_OAuth::instance();
		$errors = array();

		foreach ( $page_ids as $page_id ) {
			$kb_id = (int) get_post_meta( $page_id, '_ovebotai_kb_id', true );

			// Never synced and now being unchecked — nothing to deactivate.
			if ( ! $active && ! $kb_id ) continue;

			$post = get_post( $page_id );
			if ( ! $post || 'publish' !== $post->post_status ) continue;

			$raw_content = wp_strip_all_tags( $post->post_content );
			$content     = html_entity_decode( $raw_content, ENT_QUOTES | ENT_HTML5, 'UTF-8' );
			$content     = trim( (string) preg_replace( '/\s+/', ' ', $content ) );

			$title = get_the_title( $post );
			$body  = trim( $title . ( '' !== $content ? "\n\n" . $content : '' ) );

			// The API requires a body of at least 10 characters; skip pages with
			// too little text (e.g. a short title and no plain-text content).
			if ( mb_strlen( $body ) < 10 ) continue;

			$payload = array(
				'title'     => $title,
				'body'      => $body,
				'is_active' => $active,
			);
			$result = null;

			if ( $kb_id ) {
				$result = $oauth->api_request( 'PUT', $oauth->kb_api_path() . '/' . $kb_id, $payload );
				// Entry gone on Ovebot's side — recreate it below (only when activating).
				if ( $active && 404 === ( $result['status'] ?? 0 ) ) {
					$kb_id = 0;
				}
			}

			if ( $active && ! $kb_id ) {
				$result = $oauth->api_request( 'POST', $oauth->kb_api_path(), $payload );
				$new_id = $this->extract_kb_id( $result['body'] ?? array() );
				if ( $new_id ) {
					update_post_meta( $page_id, '_ovebotai_kb_id', $new_id );
				}
			}

			if ( ( $result['status'] ?? 0 ) < 200 || ( $result['status'] ?? 0 ) >= 300 ) {
				$errors[] = sprintf(
					/* translators: %s: page title */
					__( 'Could not update knowledge base entry for "%s".', 'ovebotai' ),
					esc_html( $title )
				);
			}
		}

		return $errors;
	}

	// Per .tasks/oauth-api.md §5: POST .../knowledge-base responds with the
	// created entry at the top level, e.g. {"id": 12, "slug": "...", ...}.
	private function extract_kb_id( array $body ): int {
		return isset( $body['id'] ) ? (int) $body['id'] : 0;
	}
}
