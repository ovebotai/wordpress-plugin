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
		// Wizard "Website pages" step — syncs the checked pages to the knowledge
		// base on its own "Next", reporting per-page results (item 10).
		add_action( 'wp_ajax_ovebotai_sync_kb', array( $this, 'ajax_sync_kb' ) );
		// Wizard "Finish" step — pushes the remaining config (widget, product
		// source, order-lookup credentials) and marks setup complete. No longer
		// re-syncs the knowledge base, which the page step now handles (item 10).
		add_action( 'wp_ajax_ovebotai_sync', array( $this, 'ajax_finish' ) );
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
	 * Wizard "Website pages" step: sync the currently-checked pages to the
	 * knowledge base and report the outcome per page, so the wizard can
	 * auto-uncheck anything that didn't go active and show an inline message at
	 * that item (item 10). Always an HTTP-level success — the sync ran; the
	 * per-page detail in the payload is what the UI reacts to.
	 */
	public function ajax_sync_kb() {
		check_ajax_referer( 'ovebotai_setup', 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'Permission denied.', 'ovebotai' ) ) );
		}

		$page_ids = array_map( 'absint', (array) ( $_POST['page_ids'] ?? array() ) ); // phpcs:ignore WordPress.Security.NonceVerification.Missing -- verified above via check_ajax_referer
		update_option( 'ovebotai_kb_page_ids', $page_ids, false );

		$result = $this->sync_kb_pages( $page_ids, true );

		$clean = empty( $result['failed'] ) && empty( $result['quota_blocked'] );

		wp_send_json_success( array(
			'clean'         => $clean,
			'ok'            => array_map( 'strval', array_keys( $result['ok'] ) ),
			'failed'        => $result['failed'],        // page_id => inline message
			'quota_blocked' => $result['quota_blocked'], // page_id => static "limit reached" message
			'quota_message' => $result['quota_message'], // raw API quota text, for the summary notice
		) );
	}

	/**
	 * Wizard "Finish" step: push the remaining configuration (widget appearance/
	 * language, the product-source choice, order-lookup credentials + feed) in a
	 * single call and mark setup complete. Knowledge-base syncing already happened
	 * on the "Website pages" step, so it is not repeated here (item 10).
	 */
	public function ajax_finish() {
		check_ajax_referer( 'ovebotai_setup', 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'Permission denied.', 'ovebotai' ) ) );
		}

		// The product-source choice is only committed now — the wizard keeps it
		// local while stepping through, pushing nothing before Finish.
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- verified above via check_ajax_referer
		if ( isset( $_POST['products_source'] ) ) {
			// phpcs:ignore WordPress.Security.NonceVerification.Missing -- verified above via check_ajax_referer
			$source = 'own' === sanitize_text_field( wp_unslash( $_POST['products_source'] ) ) ? 'own' : 'auto';
			update_option( 'ovebotai_products_source', $source, false );
		}

		if ( ! Ovebotai::resync_setup() ) {
			wp_send_json_error( array(
				'message' => __( 'Could not sync settings with Ovebot.ai.', 'ovebotai' ),
			) );
		}

		update_option( 'ovebotai_chat_status',    '1', false );
		update_option( 'ovebotai_setup_complete', '1', false );

		wp_send_json_success( array( 'message' => __( 'Setup complete!', 'ovebotai' ) ) );
	}

	/**
	 * Create/update ($active = true) or soft-deactivate ($active = false) the KB
	 * entry for each given page, keyed by a deterministic slug 'page-{post_id}'
	 * (item 6).
	 *
	 * The API does NOT upsert by slug on create, so we pull the agent's whole KB
	 * once (fetch_remote_slug_map()) and match locally: a page whose slug already
	 * exists remotely is PUT to that id; a page with no match is POSTed with the
	 * slug set, so the next sync matches it. No local _ovebotai_kb_id map is kept.
	 *
	 * source_url is the page's public permalink (item 7), so the entry links back
	 * to the live page on the website.
	 *
	 * Returns a per-page breakdown:
	 *   array(
	 *     'ok'            => array( page_id => title ),   // successfully synced
	 *     'failed'        => array( page_id => message ), // hard API error OR an
	 *                                                     // intentional skip
	 *                                                     // (unpublished / too short)
	 *     'quota_blocked' => array( page_id => message ), // rejected purely by the
	 *                                                     // KB quota on a create
	 *     'quota_message' => string,                      // the API's own quota
	 *                                                     // message, once, verbatim
	 *   )
	 * The caller treats anything not in 'ok' uniformly — auto-unchecking it and
	 * showing its message inline. An update to an existing entry does not consume
	 * quota, so kb_limit_reached only ever surfaces for genuinely new entries; the
	 * batch is never aborted early (item 8).
	 */
	public function sync_kb_pages( array $page_ids, bool $active = true ): array {
		$empty = array( 'ok' => array(), 'failed' => array(), 'quota_blocked' => array(), 'quota_message' => '' );
		if ( ! $page_ids ) return $empty;

		$oauth         = Ovebotai_OAuth::instance();
		$ok            = array();
		$failed        = array();
		$quota_blocked = array();
		$quota_message = '';

		// The API does NOT upsert by slug on create, so we resolve existing entries
		// ourselves: pull the agent's whole knowledge base once, index it by slug,
		// then update the matching id in place or insert a fresh entry (item 6).
		$remote_by_slug = $this->fetch_remote_slug_map();

		foreach ( $page_ids as $page_id ) {
			$post = get_post( $page_id );
			if ( ! $post ) continue;

			if ( 'publish' !== $post->post_status ) {
				// Only report it when activating — deactivating an unpublished page
				// is a no-op with nothing to say.
				if ( $active ) {
					$failed[ $page_id ] = __( 'Page is not published.', 'ovebotai' );
				}
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
				if ( $active ) {
					$failed[ $page_id ] = __( 'Not enough text content to sync (minimum 10 characters).', 'ovebotai' );
				}
				continue;
			}

			// Deterministic slug from the resource id (item 6); source_url = the
			// public permalink (item 7).
			$slug = 'page-' . (int) $page_id;
			$base = array(
				'title'      => $title,
				'body'       => $body,
				'source_url' => get_permalink( $page_id ),
				'is_active'  => $active,
			);

			$existing_id = isset( $remote_by_slug[ $slug ] ) ? (int) $remote_by_slug[ $slug ] : 0;

			// Never synced and now being unchecked — nothing to deactivate.
			if ( ! $active && ! $existing_id ) continue;

			$result    = null;
			$is_create = false;

			if ( $existing_id ) {
				// Update in place — target the id, no need to resend the slug.
				$result = $oauth->api_request( 'PUT', $oauth->kb_api_path() . '/' . $existing_id, $base );
				// Entry gone on Ovebot's side — fall through to a create (when activating).
				if ( $active && 404 === ( $result['status'] ?? 0 ) ) {
					$existing_id = 0;
				}
			}

			if ( $active && ! $existing_id ) {
				// Insert — send the slug so future syncs match this entry.
				$is_create = true;
				$result    = $oauth->api_request( 'POST', $oauth->kb_api_path(), $base + array( 'slug' => $slug ) );
				$new_id    = isset( $result['body']['id'] ) ? (int) $result['body']['id'] : 0;
				if ( $new_id ) {
					$remote_by_slug[ $slug ] = $new_id;
				}
			}

			if ( ( $result['status'] ?? 0 ) < 200 || ( $result['status'] ?? 0 ) >= 300 ) {
				// A create blocked purely by the KB quota is surfaced separately so
				// the wizard can show the account's own quota message. An update to an
				// existing entry never consumes quota, so this only fires for genuine
				// creates; the batch keeps going regardless (item 8).
				if ( $is_create && 'kb_limit_reached' === ( $result['body']['error']['code'] ?? '' ) ) {
					if ( '' === $quota_message ) {
						$quota_message = (string) ( $result['body']['error']['message'] ?? '' );
					}
					$quota_blocked[ $page_id ] = __( 'Skipped — knowledge base limit reached.', 'ovebotai' );
					continue;
				}

				$failed[ $page_id ] = sprintf(
					/* translators: %s: the API's error reason */
					__( 'Sync failed: %s', 'ovebotai' ),
					Ovebotai_OAuth::error_message( $result )
				);
				continue;
			}

			$ok[ $page_id ] = $title;
		}

		return array( 'ok' => $ok, 'failed' => $failed, 'quota_blocked' => $quota_blocked, 'quota_message' => $quota_message );
	}

	// Pulls the agent's whole knowledge base (paginated, max per_page=100) and
	// returns a slug => id map, so sync_kb_pages() can update an existing entry in
	// place instead of creating a duplicate (the API does not upsert by slug).
	private function fetch_remote_slug_map(): array {
		$oauth   = Ovebotai_OAuth::instance();
		$map     = array();
		$page    = 1;
		$fetched = 0;
		$total   = 0;

		do {
			$result = $oauth->api_request( 'GET', $oauth->kb_api_path() . '?' . http_build_query( array(
				'page'     => $page,
				'per_page' => 100,
			) ) );

			if ( ( $result['status'] ?? 0 ) < 200 || ( $result['status'] ?? 0 ) >= 300 ) break;

			$entries = (array) ( $result['body']['entries'] ?? array() );
			foreach ( $entries as $entry ) {
				if ( isset( $entry['slug'], $entry['id'] ) ) {
					$map[ (string) $entry['slug'] ] = (int) $entry['id'];
				}
			}

			$total    = (int) ( $result['body']['total'] ?? 0 );
			$fetched += count( $entries );
			$page++;
		} while ( $entries && $fetched < $total );

		return $map;
	}
}
