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
		add_action( 'save_post_page', array( $this, 'maybe_schedule_resync' ), 10, 2 );
		add_action( 'ovebotai_resync_single_kb_page', array( $this, 'resync_single_kb_page' ) );
	}

	// ── Auto-resync a page's KB entry after it's edited ─────────────────────
	//
	// sync_kb_pages() only ever ran from the setup wizard's "Finish" step, so
	// a page picked there was otherwise a one-time snapshot — editing it in
	// WordPress afterwards never reached Ovebot.ai. This re-pushes just that
	// one page's content whenever it's saved, but only if it was actually
	// selected as a KB source during setup (get_option( 'ovebotai_kb_page_ids' ))
	// — pages never opted in are left alone.
	//
	// Deferred via wp-cron rather than done inline: sync_kb_pages() makes a
	// blocking HTTP call to Ovebot.ai, and doing that synchronously inside
	// save_post_page would make every page "Update" click wait on a
	// third-party API before the editor finishes saving.

	public function maybe_schedule_resync( int $post_id, WP_Post $post ) {
		if ( wp_is_post_autosave( $post_id ) || wp_is_post_revision( $post_id ) ) return;
		if ( 'publish' !== $post->post_status ) return;

		$kb_page_ids = (array) get_option( 'ovebotai_kb_page_ids', array() );
		if ( ! in_array( $post_id, $kb_page_ids, true ) ) return;

		if ( ! Ovebotai_OAuth::instance()->is_connected() ) return;

		if ( ! wp_next_scheduled( 'ovebotai_resync_single_kb_page', array( $post_id ) ) ) {
			wp_schedule_single_event( time() + 5, 'ovebotai_resync_single_kb_page', array( $post_id ) );
		}
	}

	public function resync_single_kb_page( int $post_id ) {
		$this->sync_kb_pages( array( $post_id ), true );
	}

	/**
	 * Step 4: send pages to knowledge-base + PUT setup, mark complete.
	 */
	public function ajax_sync() {
		check_ajax_referer( 'ovebotai_setup', 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'Permission denied.', 'ovebot-ai-chatbot-live-chat-ai-sales-agent-for-woocommerce' ) ) );
		}

		$page_ids = array_map( 'absint', (array) ( $_POST['page_ids'] ?? array() ) );
		update_option( 'ovebotai_kb_page_ids', $page_ids, false );

		$kb_result = $this->sync_kb_pages( $page_ids, true );
		$errors    = $kb_result['errors'];
		$warnings  = $kb_result['warnings'];

		// ── Setup: widget + order info + feed (if WooCommerce is active) ─────
		//
		// Delegates to Ovebotai::resync_setup() (also used by the Settings-save
		// flow and the reactivation resync) rather than building its own PUT
		// payload — this used to be a separate, drifted copy that still had
		// order_info.enabled hardcoded true and skipped it entirely disabling
		// products when WooCommerce was inactive, and never recorded whether
		// this sync included WooCommerce (see Ovebotai::needs_woocommerce_resync()).

		if ( ! Ovebotai::resync_setup() ) {
			$errors[] = __( 'Could not sync feed and order settings with Ovebot.ai.', 'ovebot-ai-chatbot-live-chat-ai-sales-agent-for-woocommerce' );
		}

		// ── Finalize ─────────────────────────────────────────────────────────

		if ( empty( $errors ) ) {
			update_option( 'ovebotai_chat_status',   '1', false );
			update_option( 'ovebotai_setup_complete', '1', false );

			$message = __( 'Setup complete!', 'ovebot-ai-chatbot-live-chat-ai-sales-agent-for-woocommerce' );
			if ( $warnings ) {
				$message .= '<br>' . implode( '<br>', $warnings );
			}

			wp_send_json_success( array( 'message' => $message, 'warnings' => $warnings ) );
		} else {
			$message = implode( '<br>', array_merge( $errors, $warnings ) );
			wp_send_json_error( array(
				'message'  => $message,
				'errors'   => $errors,
				'warnings' => $warnings,
			) );
		}
	}

	/**
	 * Create/update ($active = true) or soft-deactivate ($active = false) the
	 * KB entry for each given page. We always fetch the current remote entry
	 * list first (both on first connect and on every later save) and validate
	 * against it:
	 *  - a page with a local _ovebotai_kb_id whose id is no longer present
	 *    remotely is treated as never-synced (the mapping is stale — e.g. the
	 *    entry was deleted on Ovebot's side) and falls through to creation;
	 *  - a page with no local id (mapping never saved, or just invalidated
	 *    above) is matched against a remote entry with an identical title
	 *    before creating a new one, so a title collision is treated as the
	 *    same entry even without a locally stored id.
	 * Either way, a resolved id is PUT to instead of re-created, so re-running
	 * setup/reconnect (or an unrelated settings save) never duplicates entries.
	 *
	 * Ovebot's API has no DELETE for knowledge-base entries — only an
	 * `is_active` flag (.tasks/oauth-api.md §5) — so unchecking a page
	 * deactivates its entry rather than removing it. The _ovebotai_kb_id
	 * mapping is kept either way, so re-checking the page later reactivates
	 * the same entry instead of creating a duplicate.
	 *
	 * Returns array( 'errors' => [...], 'warnings' => [...] ) — human-readable
	 * strings. 'errors' are actual sync failures (API call rejected); 'warnings'
	 * are pages that were intentionally skipped (unpublished / not enough text)
	 * and shouldn't block the overall save from being reported as a success.
	 */
	public function sync_kb_pages( array $page_ids, bool $active = true ): array {
		if ( ! $page_ids ) return array( 'errors' => array(), 'warnings' => array() );

		$oauth    = Ovebotai_OAuth::instance();
		$errors   = array();
		$warnings = array();

		$remote_entries = $this->fetch_remote_kb_entries();
		$remote_by_id    = array(); // id    => title
		$remote_by_title = array(); // title => id
		foreach ( $remote_entries as $entry ) {
			if ( ! isset( $entry['id'], $entry['title'] ) ) continue;
			$remote_by_id[ (int) $entry['id'] ]          = (string) $entry['title'];
			$remote_by_title[ (string) $entry['title'] ] = (int) $entry['id'];
		}

		foreach ( $page_ids as $page_id ) {
			$kb_id = (int) get_post_meta( $page_id, '_ovebotai_kb_id', true );

			// Locally mapped id no longer exists remotely — stale mapping,
			// treat the page as never-synced.
			if ( $kb_id && ! isset( $remote_by_id[ $kb_id ] ) ) {
				$kb_id = 0;
			}

			// Never synced (or just invalidated above) and now being
			// unchecked — nothing to deactivate.
			if ( ! $active && ! $kb_id ) continue;

			$post = get_post( $page_id );
			if ( ! $post ) continue;

			if ( 'publish' !== $post->post_status ) {
				$warnings[] = sprintf(
					/* translators: %s: page title */
					__( 'Skipped "%s" — page is not published.', 'ovebot-ai-chatbot-live-chat-ai-sales-agent-for-woocommerce' ),
					esc_html( get_the_title( $post ) )
				);
				continue;
			}

			// strip_shortcodes() first: shortcode brackets like [gallery] or
			// [contact-form-7] aren't HTML tags, so wp_strip_all_tags() alone
			// would leave the raw "[shortcode attr=...]" text in the KB entry.
			$raw_content = wp_strip_all_tags( strip_shortcodes( $post->post_content ) );
			$content     = html_entity_decode( $raw_content, ENT_QUOTES | ENT_HTML5, 'UTF-8' );
			$content     = trim( (string) preg_replace( '/\s+/', ' ', $content ) );

			$title = get_the_title( $post );
			$body  = trim( $title . ( '' !== $content ? "\n\n" . $content : '' ) );

			// The API requires a body of at least 10 characters; skip pages with
			// too little text (e.g. a short title and no plain-text content, common
			// with page builders that don't store text in post_content).
			if ( mb_strlen( $body ) < 10 ) {
				$warnings[] = sprintf(
					/* translators: %s: page title */
					__( 'Skipped "%s" — not enough text content to sync (minimum 10 characters).', 'ovebot-ai-chatbot-live-chat-ai-sales-agent-for-woocommerce' ),
					esc_html( $title )
				);
				continue;
			}

			// No usable id — check for a remote entry with the same title
			// before creating a new one.
			if ( $active && ! $kb_id && isset( $remote_by_title[ $title ] ) ) {
				$kb_id = $remote_by_title[ $title ];
				update_post_meta( $page_id, '_ovebotai_kb_id', $kb_id );
			}

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
					__( 'Could not update knowledge base entry for "%s".', 'ovebot-ai-chatbot-live-chat-ai-sales-agent-for-woocommerce' ),
					esc_html( $title )
				);
			}
		}

		return array( 'errors' => $errors, 'warnings' => $warnings );
	}

	// Per .tasks/oauth-api.md §5: POST .../knowledge-base responds with the
	// created entry at the top level, e.g. {"id": 12, "slug": "...", ...}.
	private function extract_kb_id( array $body ): int {
		return isset( $body['id'] ) ? (int) $body['id'] : 0;
	}

	// Pages through GET .../knowledge-base (paginated, max per_page=100 per
	// .tasks/oauth-api.md §5) and returns every remote entry (id + title),
	// so callers can validate local ids and match on title.
	private function fetch_remote_kb_entries(): array {
		$oauth     = Ovebotai_OAuth::instance();
		$all       = array();
		$page      = 1;
		$fetched   = 0;
		$total     = 0;

		do {
			$result = $oauth->api_request( 'GET', $oauth->kb_api_path() . '?' . http_build_query( array(
				'page'     => $page,
				'per_page' => 100,
			) ) );

			if ( ( $result['status'] ?? 0 ) < 200 || ( $result['status'] ?? 0 ) >= 300 ) break;

			$entries = (array) ( $result['body']['entries'] ?? array() );
			$all     = array_merge( $all, $entries );

			$total    = (int) ( $result['body']['total'] ?? 0 );
			$fetched += count( $entries );
			$page++;
		} while ( $entries && $fetched < $total );

		return $all;
	}
}
