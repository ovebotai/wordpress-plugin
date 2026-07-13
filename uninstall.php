<?php
/**
 * Uninstall cleanup. WordPress includes this file directly (not through the
 * plugin's main bootstrap), so none of the plugin's classes/constants are
 * available here — everything needed is self-contained.
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

// ── Best-effort remote revoke ────────────────────────────────────────────
// Revokes the token (and its OAuth family) on Ovebot's side so a leaked
// backup of this option can't still authenticate after uninstall. Must not
// block the local cleanup below if the request fails or times out.

$access_token = get_option( 'ovebotai_access_token' );
if ( $access_token ) {
	wp_remote_post( 'https://api.ovebot.ai/v1/disconnect', array(
		'headers' => array(
			'Authorization' => 'Bearer ' . $access_token,
			'Accept'        => 'application/json',
		),
		'timeout' => 5,
	) );
}

// ── Options ───────────────────────────────────────────────────────────────

$options = array(
	'ovebotai_access_token',
	'ovebotai_refresh_token',
	'ovebotai_token_expires',
	'ovebotai_workspace',
	'ovebotai_agent',
	'ovebotai_setup_complete',
	'ovebotai_activation_redirect',
	'ovebotai_chat_status',
	'ovebotai_widget',
	'ovebotai_feed_hash',
	'ovebotai_order_user',
	'ovebotai_order_pass',
	'ovebotai_cache_version',
	'ovebotai_kb_page_ids',
	'ovebotai_days_shipped_min',
	'ovebotai_days_shipped_max',
	'ovebotai_days_instock_min',
	'ovebotai_days_instock_max',
	'ovebotai_days_oos_min',
	'ovebotai_days_oos_max',
	'ovebotai_include_oos', // removed option, deleted defensively for older installs
);
foreach ( $options as $option ) {
	delete_option( $option );
}

delete_transient( 'ovebotai_pkce_verifier' );
delete_transient( 'ovebotai_oauth_state' );

// ── Per-page knowledge-base id mapping ───────────────────────────────────

delete_post_meta_by_key( '_ovebotai_kb_id' );

// ── Cached product feed pages (transient key includes a version number, so
//    match by prefix rather than tracking every version ever used) ────────

global $wpdb;
$wpdb->query(
	"DELETE FROM {$wpdb->options}
	 WHERE option_name LIKE '\_transient\_ovebotai\_feed\_v%'
	    OR option_name LIKE '\_transient\_timeout\_ovebotai\_feed\_v%'"
);
