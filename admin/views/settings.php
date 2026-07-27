<?php
defined( 'ABSPATH' ) || exit;

$ovebotai_oauth     = Ovebotai_OAuth::instance();
$ovebotai_workspace = $ovebotai_oauth->get_workspace();
$ovebotai_widget    = Ovebotai_Settings::get_widget();

$ovebotai_feed_hash = (string) get_option( 'ovebotai_feed_hash', '' );
$ovebotai_feed_url  = add_query_arg( 'hash', $ovebotai_feed_hash, home_url( '/wp-json/ovebotai/v1/feed' ) );
$ovebotai_order_url = home_url( '/wp-json/ovebotai/v1/orders' );

$ovebotai_wc_active   = Ovebotai::woocommerce_active();
// Live check (not just local state) — this view can be reached directly
// (bookmark) without ever making its own API call otherwise, so a lapsed
// refresh token would never get discovered until some later action.
$ovebotai_is_connected = $ovebotai_oauth->is_connected_live();

// Product-source choice + the connected agent's settings page on Ovebot.ai
// (linked from the "own feed" option).
$ovebotai_products_source    = get_option( 'ovebotai_products_source', 'auto' );
$ovebotai_agent_settings_url = $ovebotai_oauth->get_agent_settings_url();

if ( ! $ovebotai_is_connected ) {
	// Not connected — Settings assumes a working OAuth connection throughout
	// (KB sync, feed/order-info push, etc.), so send the user straight to the
	// dashboard's reconnect panel instead of rendering a half-usable form.
	?>
	<script>
		window.location.replace( <?php echo wp_json_encode( admin_url( 'admin.php?page=ovebotai' ) ); ?> );
	</script>
	<?php
	return;
}
?>
<hr class="wp-header-end">
<div class="wrap ovebotai-wrap">

	<div class="ovebotai-settings-header" style="margin-bottom: 10px;">
		<div class="ovebotai-logo">
			<a href="https://ovebot.ai" target="_blank" rel="noopener noreferrer">
				<img src="<?php echo esc_url( OVEBOTAI_URL . 'admin/img/logo.png' ); ?>" alt="Ovebot.ai" height="32">
			</a>
			<h1><?php esc_html_e( 'Settings', 'ovebotai' ); ?></h1>
			<?php require OVEBOTAI_DIR . 'admin/views/partials/version-badge.php'; ?>
		</div>
		<?php require OVEBOTAI_DIR . 'admin/views/partials/connection-badge.php'; ?>
	</div>

	<a href="<?php echo esc_url( admin_url( 'admin.php?page=ovebotai' ) ); ?>" class="ovebotai-back-link">
		<span class="dashicons dashicons-arrow-left-alt2" aria-hidden="true"></span>
		<?php esc_html_e( 'Dashboard', 'ovebotai' ); ?>
	</a>

	<div id="oveSettingsNotice" class="ovebotai-save-notice" style="display:none"></div>
	<div id="oveSettingsWarnings" class="ovebotai-save-notice ovebotai-notice-warning" style="display:none"></div>

	<!-- onsubmit="return false" is a safety net against a native submit firing
	     before settings.js attaches its own submit handler (no `action` set,
	     so that would GET the current URL and drop ?page=ovebotai&view=settings
	     from it). It never blocks the real AJAX handler, which listens for the
	     same submit event independently. -->
	<form id="oveSettingsForm" onsubmit="return false;">

		<!-- ── Chat Widget ──────────────────────────────────────────────── -->
		<div class="ovebotai-fieldset">
			<div class="ovebotai-fieldset-legend">
				<span class="dashicons dashicons-format-chat" aria-hidden="true"></span>
				<?php esc_html_e( 'On-site chat widget', 'ovebotai' ); ?>
			</div>
			<div class="ovebotai-fieldset-body">

				<div class="ovebotai-field ovebotai-field-switch">
					<label><?php esc_html_e( 'Show the chat bubble on your site', 'ovebotai' ); ?></label>
					<div class="ovebotai-switch-wrap">
						<label class="ovebotai-switch">
							<input type="checkbox" name="chat_status" id="oveChatStatus" value="1" <?php checked( get_option( 'ovebotai_chat_status' ), '1' ); ?>>
							<span class="ovebotai-switch-slider"></span>
						</label>
						<span class="ovebotai-switch-lbl" id="oveChatStatusLbl">
							<?php echo get_option( 'ovebotai_chat_status' ) === '1' ? esc_html__( 'Enabled', 'ovebotai' ) : esc_html__( 'Disabled', 'ovebotai' ); ?>
						</span>
					</div>
					<p class="description"><?php esc_html_e( 'When enabled, the AI agent\'s chat bubble appears on every page of your site so customers can start a conversation.', 'ovebotai' ); ?></p>
				</div>

			</div>
		</div>

		<?php if ( $ovebotai_wc_active ) : ?>

		<!-- ── Product Feed ─────────────────────────────────────────────── -->
		<div class="ovebotai-fieldset">
			<div class="ovebotai-fieldset-legend">
				<span class="dashicons dashicons-rss" aria-hidden="true"></span>
				<?php esc_html_e( 'Product feed', 'ovebotai' ); ?>
			</div>
			<div class="ovebotai-fieldset-body">

				<!-- Product-recommendation master switch. Saved locally and mirrored
				     into the account's products.enabled on Save; also reconciled back
				     from the account on each dashboard load (sync_settings()). -->
				<div class="ovebotai-field ovebotai-field-switch">
					<label><?php esc_html_e( 'Recommend products', 'ovebotai' ); ?></label>
					<div class="ovebotai-switch-wrap">
						<label class="ovebotai-switch">
							<input type="checkbox" name="products_enabled" id="oveProductsEnabled" value="1" <?php checked( Ovebotai::products_enabled() ); ?>>
							<span class="ovebotai-switch-slider"></span>
						</label>
						<span class="ovebotai-switch-lbl" id="oveProductsEnabledLbl">
							<?php echo Ovebotai::products_enabled() ? esc_html__( 'Enabled', 'ovebotai' ) : esc_html__( 'Disabled', 'ovebotai' ); ?>
						</span>
					</div>
					<p class="description"><?php esc_html_e( 'When off, your AI agent stops suggesting products to customers in the chat.', 'ovebotai' ); ?></p>
				</div>

				<!-- Product source (item 10): built-in automatic feed vs. products
				     managed directly on Ovebot.ai. When off, the products section
				     is omitted from the API push entirely (leaving Ovebot.ai's copy
				     untouched) and the local feed endpoint is served as forbidden. -->
				<div class="ovebotai-field ovebotai-field-switch">
					<label><?php esc_html_e( 'Use the built-in product feed', 'ovebotai' ); ?></label>
					<div class="ovebotai-switch-wrap">
						<label class="ovebotai-switch">
							<input type="checkbox" name="products_source_builtin" id="oveProductsSourceBuiltin" value="1" <?php checked( 'own' !== $ovebotai_products_source ); ?>>
							<span class="ovebotai-switch-slider"></span>
						</label>
						<span class="ovebotai-switch-lbl" id="oveProductsSourceBuiltinLbl">
							<?php echo 'own' !== $ovebotai_products_source ? esc_html__( 'Enabled', 'ovebotai' ) : esc_html__( 'Disabled', 'ovebotai' ); ?>
						</span>
					</div>
					<p class="description">
						<?php
						if ( $ovebotai_agent_settings_url ) {
							printf(
								/* translators: %s: link to the agent's product settings on Ovebot.ai, shown as the raw URL */
								wp_kses_post( __( 'When off, our own feed URL stops working (returns Forbidden) and Ovebot.ai won\'t be told about it. If you have a custom feed, configure it directly at: %s', 'ovebotai' ) ),
								sprintf(
									'<a href="%1$s" target="_blank" rel="noopener noreferrer">%1$s</a>',
									esc_url( $ovebotai_agent_settings_url )
								)
							);
						} else {
							esc_html_e( 'When off, our own feed URL stops working (returns Forbidden) and Ovebot.ai won\'t be told about it. Set up a custom feed directly in your Ovebot.ai account.', 'ovebotai' );
						}
						?>
					</p>
				</div>

				<div class="ovebotai-field" id="oveFeedUrlField">
					<label><?php esc_html_e( 'Feed URL', 'ovebotai' ); ?></label>
					<div class="ovebotai-url-row">
						<input type="text" class="regular-text ovebotai-readonly-url" id="oveFeedUrl"
							value="<?php echo esc_attr( $ovebotai_feed_url ); ?>" readonly>
						<button type="button" class="button ovebotai-copy-btn" data-target="oveFeedUrl">
							<?php esc_html_e( 'Copy', 'ovebotai' ); ?>
						</button>
						<button type="button" class="button ovebotai-regen-hash-btn" aria-label="<?php esc_attr_e( 'Regenerate hash', 'ovebotai' ); ?>" title="<?php esc_attr_e( 'Regenerate hash', 'ovebotai' ); ?>">
							<span class="dashicons dashicons-image-rotate" aria-hidden="true"></span>
						</button>
					</div>
					<p class="description"><?php esc_html_e( 'Ovebot.ai reads this URL periodically to keep your AI agent\'s product recommendations up to date with your catalog (stock, price, availability).', 'ovebotai' ); ?></p>
				</div>

			</div>
		</div>

		<!-- ── Order Tracking ───────────────────────────────────────────── -->
		<div class="ovebotai-fieldset">
			<div class="ovebotai-fieldset-legend">
				<span class="dashicons dashicons-location-alt" aria-hidden="true"></span>
				<?php esc_html_e( 'Order tracking API', 'ovebotai' ); ?>
			</div>
			<div class="ovebotai-fieldset-body">

				<p class="description ovebotai-fieldset-intro"><?php esc_html_e( 'Ovebot.ai calls this endpoint, authenticated with the credentials below, so your AI agent can answer "Where is my order?" questions with live tracking info.', 'ovebotai' ); ?></p>

				<!-- Order-lookup master switch (task 5). Saved locally and mirrored
				     into the account's order_info.enabled on Save. -->
				<div class="ovebotai-field ovebotai-field-switch">
					<label><?php esc_html_e( 'Enable order tracking', 'ovebotai' ); ?></label>
					<div class="ovebotai-switch-wrap">
						<label class="ovebotai-switch">
							<input type="checkbox" name="order_api_status" id="oveOrderApiStatus" value="1" <?php checked( Ovebotai::order_api_enabled() ); ?>>
							<span class="ovebotai-switch-slider"></span>
						</label>
						<span class="ovebotai-switch-lbl" id="oveOrderApiStatusLbl">
							<?php echo Ovebotai::order_api_enabled() ? esc_html__( 'Enabled', 'ovebotai' ) : esc_html__( 'Disabled', 'ovebotai' ); ?>
						</span>
					</div>
					<p class="description"><?php esc_html_e( 'When off, your AI agent stops answering order-status questions and this endpoint returns 403.', 'ovebotai' ); ?></p>
				</div>

				<div class="ovebotai-field">
					<label><?php esc_html_e( 'Endpoint URL', 'ovebotai' ); ?></label>
					<div class="ovebotai-url-row">
						<input type="text" class="regular-text ovebotai-readonly-url" id="oveOrderUrl"
							value="<?php echo esc_attr( $ovebotai_order_url ); ?>" readonly>
						<button type="button" class="button ovebotai-copy-btn" data-target="oveOrderUrl">
							<?php esc_html_e( 'Copy', 'ovebotai' ); ?>
						</button>
					</div>
				</div>

				<div class="ovebotai-field">
					<label><?php esc_html_e( 'API user', 'ovebotai' ); ?></label>
					<div class="ovebotai-url-row">
						<input type="text" class="regular-text ovebotai-readonly-url" id="oveApiUser"
							value="<?php echo esc_attr( (string) get_option( 'ovebotai_order_user', '' ) ); ?>" readonly>
						<button type="button" class="button ovebotai-copy-btn" data-target="oveApiUser">
							<?php esc_html_e( 'Copy', 'ovebotai' ); ?>
						</button>
					</div>
				</div>

				<div class="ovebotai-field">
					<label><?php esc_html_e( 'API password', 'ovebotai' ); ?></label>
					<div class="ovebotai-url-row">
						<input type="text" class="regular-text ovebotai-readonly-url" id="oveApiPass"
							value="<?php echo esc_attr( (string) get_option( 'ovebotai_order_pass', '' ) ); ?>" readonly>
						<button type="button" class="button ovebotai-copy-btn" data-target="oveApiPass">
							<?php esc_html_e( 'Copy', 'ovebotai' ); ?>
						</button>
					</div>
				</div>

				<div class="ovebotai-field">
					<button type="button" class="button ovebotai-regen-creds-btn">
						<?php esc_html_e( 'Regenerate credentials', 'ovebotai' ); ?>
					</button>
					<p class="description"><?php esc_html_e( 'Generates a new user/password pair. The old ones will stop working immediately.', 'ovebotai' ); ?></p>
				</div>

			</div>
		</div>

		<?php else : ?>
		<div class="ovebotai-fieldset">
			<div class="ovebotai-fieldset-body">
				<div class="ovebotai-notice ovebotai-notice-warning">
					<p><?php esc_html_e( 'WooCommerce is not active. Product feed and order tracking are unavailable.', 'ovebotai' ); ?></p>
				</div>
			</div>
		</div>
		<?php endif; ?>

		<!-- ── Appearance ───────────────────────────────────────────────── -->
		<div class="ovebotai-fieldset">
			<div class="ovebotai-fieldset-legend">
				<span class="dashicons dashicons-admin-appearance" aria-hidden="true"></span>
				<?php esc_html_e( 'Appearance', 'ovebotai' ); ?>
			</div>
			<div class="ovebotai-fieldset-body">

				<div class="ovebotai-field">
					<button type="button" class="button" id="oveAppearanceToggle">
						<?php esc_html_e( 'Configure appearance', 'ovebotai' ); ?>
						<span class="dashicons dashicons-arrow-down-alt2" id="oveAppearanceToggleIcon" aria-hidden="true"></span>
					</button>
				</div>

				<div class="ovebotai-appearance-panel" id="oveAppearancePanel" style="display:none">

					<div class="ovebotai-appearance-subhead"><?php esc_html_e( 'Look & feel', 'ovebotai' ); ?></div>
					<div class="ovebotai-appearance-grid">

						<div class="ovebotai-field">
							<label for="ove_accent_color"><?php esc_html_e( 'Accent colour', 'ovebotai' ); ?></label>
							<div class="ovebotai-color-wrap">
								<!-- Task 4: when no accent colour is set, both the swatch and the
								     text placeholder show Ovebot's default brand colour (#615ED6),
								     which is what the widget itself falls back to. -->
								<input type="color" id="ove_color_picker" value="<?php echo esc_attr( $ovebotai_widget['accent_color'] ?? '#615ED6' ); ?>">
								<input type="text" name="widget_accent_color" id="ove_accent_color" class="regular-text"
									value="<?php echo esc_attr( $ovebotai_widget['accent_color'] ?? '' ); ?>"
									placeholder="#615ED6" maxlength="7">
							</div>
						</div>

						<div class="ovebotai-field">
							<label for="ove_theme"><?php esc_html_e( 'Theme', 'ovebotai' ); ?></label>
							<select name="widget_theme" id="ove_theme">
								<option value="" <?php selected( $ovebotai_widget['theme'] ?? '', '' ); ?>><?php esc_html_e( 'Default (light)', 'ovebotai' ); ?></option>
								<option value="light" <?php selected( $ovebotai_widget['theme'] ?? '', 'light' ); ?>><?php esc_html_e( 'Light', 'ovebotai' ); ?></option>
								<option value="dark" <?php selected( $ovebotai_widget['theme'] ?? '', 'dark' ); ?>><?php esc_html_e( 'Dark', 'ovebotai' ); ?></option>
							</select>
						</div>

						<div class="ovebotai-field">
							<label for="ove_language"><?php esc_html_e( 'Language', 'ovebotai' ); ?></label>
							<select name="widget_language" id="ove_language">
								<option value=""     <?php selected( $ovebotai_widget['language'] ?? '', '' ); ?>><?php esc_html_e( 'Default', 'ovebotai' ); ?></option>
								<option value="auto" <?php selected( $ovebotai_widget['language'] ?? '', 'auto' ); ?>><?php esc_html_e( 'Auto (browser)', 'ovebotai' ); ?></option>
								<option value="en" <?php selected( $ovebotai_widget['language'] ?? '', 'en' ); ?>>English</option>
								<option value="ro" <?php selected( $ovebotai_widget['language'] ?? '', 'ro' ); ?>>Română</option>
								<option value="de" <?php selected( $ovebotai_widget['language'] ?? '', 'de' ); ?>>Deutsch</option>
								<option value="fr" <?php selected( $ovebotai_widget['language'] ?? '', 'fr' ); ?>>Français</option>
							</select>
						</div>

						<div class="ovebotai-field">
							<label for="ove_audio_beep"><?php esc_html_e( 'Audio beep', 'ovebotai' ); ?></label>
							<select name="widget_audio_beep" id="ove_audio_beep">
								<option value="" <?php selected( $ovebotai_widget['audio_beep'] ?? '', '' ); ?>><?php esc_html_e( 'Default (play)', 'ovebotai' ); ?></option>
								<option value="play" <?php selected( $ovebotai_widget['audio_beep'] ?? '', 'play' ); ?>><?php esc_html_e( 'Play', 'ovebotai' ); ?></option>
								<option value="none" <?php selected( $ovebotai_widget['audio_beep'] ?? '', 'none' ); ?>><?php esc_html_e( 'None', 'ovebotai' ); ?></option>
							</select>
						</div>

					</div><!-- /.ovebotai-appearance-grid -->

					<div class="ovebotai-appearance-subhead"><?php esc_html_e( 'Position on page', 'ovebotai' ); ?></div>
					<div class="ovebotai-field ovebotai-field-full">
						<label for="ove_side"><?php esc_html_e( 'Widget position', 'ovebotai' ); ?></label>
						<div class="ovebotai-combo-row">
							<select name="widget_side" id="ove_side">
								<option value="" <?php selected( $ovebotai_widget['side'] ?? '', '' ); ?>><?php esc_html_e( 'Default (right)', 'ovebotai' ); ?></option>
								<option value="right" <?php selected( $ovebotai_widget['side'] ?? '', 'right' ); ?>><?php esc_html_e( 'Bottom right', 'ovebotai' ); ?></option>
								<option value="left" <?php selected( $ovebotai_widget['side'] ?? '', 'left' ); ?>><?php esc_html_e( 'Bottom left', 'ovebotai' ); ?></option>
							</select>
							<div class="ovebotai-combo-sub">
								<input type="number" name="widget_offset_y" id="ove_offset_y" class="small-text"
									value="<?php echo esc_attr( $ovebotai_widget['offset_y'] ?? '' ); ?>" min="0" placeholder="20">
								<span class="ovebotai-combo-suffix"><?php esc_html_e( 'px offset from bottom', 'ovebotai' ); ?></span>
							</div>
						</div>
						<p class="description"><?php esc_html_e( 'Raise the offset to sit the widget above another floating button, e.g. WhatsApp.', 'ovebotai' ); ?></p>
					</div>

					<div class="ovebotai-field ovebotai-field-full">
						<label for="ove_z_index"><?php esc_html_e( 'Stacking order (z-index)', 'ovebotai' ); ?></label>
						<input type="number" name="widget_z_index" id="ove_z_index" class="regular-text"
							value="<?php echo esc_attr( $ovebotai_widget['z_index'] ?? '' ); ?>" placeholder="2147483644">
						<p class="description"><?php esc_html_e( 'Only change this if the chat widget appears behind another element on your site. Leave empty to use the default.', 'ovebotai' ); ?></p>
					</div>

					<div class="ovebotai-appearance-subhead"><?php esc_html_e( 'Messages', 'ovebotai' ); ?></div>
					<div class="ovebotai-field ovebotai-field-full">
						<label for="ove_subtitle"><?php esc_html_e( 'Subtitle', 'ovebotai' ); ?></label>
						<input type="text" name="widget_subtitle" id="ove_subtitle" class="regular-text"
							value="<?php echo esc_attr( $ovebotai_widget['subtitle'] ?? '' ); ?>"
							placeholder="<?php esc_attr_e( 'e.g. Usually replies in a few minutes', 'ovebotai' ); ?>">
						<p class="description"><?php esc_html_e( 'Shown under the assistant\'s name in the chat header.', 'ovebotai' ); ?></p>
					</div>

					<div class="ovebotai-field ovebotai-field-full">
						<label for="ove_proactive_message"><?php esc_html_e( 'Proactive message', 'ovebotai' ); ?></label>
						<div class="ovebotai-combo-row">
							<input type="text" name="widget_proactive_message" id="ove_proactive_message" class="regular-text"
								value="<?php echo esc_attr( $ovebotai_widget['proactive_message'] ?? '' ); ?>"
								placeholder="<?php esc_attr_e( 'e.g. Need help finding something?', 'ovebotai' ); ?>">
							<div class="ovebotai-combo-sub">
								<input type="number" name="widget_proactive_delay" id="ove_proactive_delay" class="small-text"
									value="<?php echo esc_attr( $ovebotai_widget['proactive_delay'] ?? '' ); ?>" min="0" max="300" placeholder="4">
								<span class="ovebotai-combo-suffix"><?php esc_html_e( 's after page load', 'ovebotai' ); ?></span>
							</div>
						</div>
						<p class="description"><?php esc_html_e( 'A short message that pops up on its own to invite visitors to chat. Leave empty to disable.', 'ovebotai' ); ?></p>
					</div>
				</div><!-- /.ovebotai-appearance-panel -->

			</div>
		</div>

		<!-- Save -->
		<div class="ovebotai-save-row">
			<button type="submit" class="button button-primary button-large" id="oveSaveBtn">
				<?php esc_html_e( 'Save settings', 'ovebotai' ); ?>
			</button>
		</div>

	</form>

</div><!-- /.wrap -->
