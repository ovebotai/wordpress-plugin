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
			<h1><?php esc_html_e( 'Settings', 'ovebot-ai-chatbot-live-chat-ai-sales-agent-for-woocommerce' ); ?></h1>
		</div>
		<?php require OVEBOTAI_DIR . 'admin/views/partials/connection-badge.php'; ?>
	</div>

	<a href="<?php echo esc_url( admin_url( 'admin.php?page=ovebotai' ) ); ?>" class="ovebotai-back-link">
		<span class="dashicons dashicons-arrow-left-alt2" aria-hidden="true"></span>
		<?php esc_html_e( 'Dashboard', 'ovebot-ai-chatbot-live-chat-ai-sales-agent-for-woocommerce' ); ?>
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
				<?php esc_html_e( 'On-site chat widget', 'ovebot-ai-chatbot-live-chat-ai-sales-agent-for-woocommerce' ); ?>
			</div>
			<div class="ovebotai-fieldset-body">

				<div class="ovebotai-field ovebotai-field-switch">
					<label><?php esc_html_e( 'Show the chat bubble on your site', 'ovebot-ai-chatbot-live-chat-ai-sales-agent-for-woocommerce' ); ?></label>
					<div class="ovebotai-switch-wrap">
						<label class="ovebotai-switch">
							<input type="checkbox" name="chat_status" id="oveChatStatus" value="1" <?php checked( get_option( 'ovebotai_chat_status' ), '1' ); ?>>
							<span class="ovebotai-switch-slider"></span>
						</label>
						<span class="ovebotai-switch-lbl" id="oveChatStatusLbl">
							<?php echo get_option( 'ovebotai_chat_status' ) === '1' ? esc_html__( 'Enabled', 'ovebot-ai-chatbot-live-chat-ai-sales-agent-for-woocommerce' ) : esc_html__( 'Disabled', 'ovebot-ai-chatbot-live-chat-ai-sales-agent-for-woocommerce' ); ?>
						</span>
					</div>
					<p class="description"><?php esc_html_e( 'When enabled, the AI agent\'s chat bubble appears on every page of your site so customers can start a conversation.', 'ovebot-ai-chatbot-live-chat-ai-sales-agent-for-woocommerce' ); ?></p>
				</div>

			</div>
		</div>

		<?php if ( $ovebotai_wc_active ) : ?>

		<!-- ── Product Feed ─────────────────────────────────────────────── -->
		<div class="ovebotai-fieldset">
			<div class="ovebotai-fieldset-legend">
				<span class="dashicons dashicons-rss" aria-hidden="true"></span>
				<?php esc_html_e( 'Product feed', 'ovebot-ai-chatbot-live-chat-ai-sales-agent-for-woocommerce' ); ?>
			</div>
			<div class="ovebotai-fieldset-body">

				<p class="description ovebotai-fieldset-intro"><?php esc_html_e( 'Ovebot.ai reads this URL periodically to keep your AI agent\'s product recommendations up to date with your catalog (stock, price, availability).', 'ovebot-ai-chatbot-live-chat-ai-sales-agent-for-woocommerce' ); ?></p>

				<div class="ovebotai-field">
					<label><?php esc_html_e( 'Feed URL', 'ovebot-ai-chatbot-live-chat-ai-sales-agent-for-woocommerce' ); ?></label>
					<div class="ovebotai-url-row">
						<input type="text" class="regular-text ovebotai-readonly-url" id="oveFeedUrl"
							value="<?php echo esc_attr( $ovebotai_feed_url ); ?>" readonly>
						<button type="button" class="button ovebotai-copy-btn" data-target="oveFeedUrl">
							<?php esc_html_e( 'Copy', 'ovebot-ai-chatbot-live-chat-ai-sales-agent-for-woocommerce' ); ?>
						</button>
						<button type="button" class="button ovebotai-regen-hash-btn" aria-label="<?php esc_attr_e( 'Regenerate hash', 'ovebot-ai-chatbot-live-chat-ai-sales-agent-for-woocommerce' ); ?>" title="<?php esc_attr_e( 'Regenerate hash', 'ovebot-ai-chatbot-live-chat-ai-sales-agent-for-woocommerce' ); ?>">
							<span class="dashicons dashicons-image-rotate" aria-hidden="true"></span>
						</button>
					</div>
				</div>

				<div class="ovebotai-field">
					<button type="button" class="button ovebotai-clear-cache-btn">
						<?php esc_html_e( 'Clear feed cache', 'ovebot-ai-chatbot-live-chat-ai-sales-agent-for-woocommerce' ); ?>
					</button>
					<p class="description"><?php esc_html_e( 'The feed is cached for performance. Clear it after major catalog changes.', 'ovebot-ai-chatbot-live-chat-ai-sales-agent-for-woocommerce' ); ?></p>
				</div>

			</div>
		</div>

		<!-- ── Order Tracking ───────────────────────────────────────────── -->
		<div class="ovebotai-fieldset">
			<div class="ovebotai-fieldset-legend">
				<span class="dashicons dashicons-location-alt" aria-hidden="true"></span>
				<?php esc_html_e( 'Order tracking API', 'ovebot-ai-chatbot-live-chat-ai-sales-agent-for-woocommerce' ); ?>
			</div>
			<div class="ovebotai-fieldset-body">

				<p class="description ovebotai-fieldset-intro"><?php esc_html_e( 'Ovebot.ai calls this endpoint, authenticated with the credentials below, so your AI agent can answer "Where is my order?" questions with live tracking info.', 'ovebot-ai-chatbot-live-chat-ai-sales-agent-for-woocommerce' ); ?></p>

				<div class="ovebotai-field">
					<label><?php esc_html_e( 'Endpoint URL', 'ovebot-ai-chatbot-live-chat-ai-sales-agent-for-woocommerce' ); ?></label>
					<div class="ovebotai-url-row">
						<input type="text" class="regular-text ovebotai-readonly-url" id="oveOrderUrl"
							value="<?php echo esc_attr( $ovebotai_order_url ); ?>" readonly>
						<button type="button" class="button ovebotai-copy-btn" data-target="oveOrderUrl">
							<?php esc_html_e( 'Copy', 'ovebot-ai-chatbot-live-chat-ai-sales-agent-for-woocommerce' ); ?>
						</button>
					</div>
				</div>

				<div class="ovebotai-field">
					<label><?php esc_html_e( 'API user', 'ovebot-ai-chatbot-live-chat-ai-sales-agent-for-woocommerce' ); ?></label>
					<div class="ovebotai-url-row">
						<input type="text" class="regular-text ovebotai-readonly-url" id="oveApiUser"
							value="<?php echo esc_attr( (string) get_option( 'ovebotai_order_user', '' ) ); ?>" readonly>
						<button type="button" class="button ovebotai-copy-btn" data-target="oveApiUser">
							<?php esc_html_e( 'Copy', 'ovebot-ai-chatbot-live-chat-ai-sales-agent-for-woocommerce' ); ?>
						</button>
					</div>
				</div>

				<div class="ovebotai-field">
					<label><?php esc_html_e( 'API password', 'ovebot-ai-chatbot-live-chat-ai-sales-agent-for-woocommerce' ); ?></label>
					<div class="ovebotai-url-row">
						<input type="text" class="regular-text ovebotai-readonly-url" id="oveApiPass"
							value="<?php echo esc_attr( (string) get_option( 'ovebotai_order_pass', '' ) ); ?>" readonly>
						<button type="button" class="button ovebotai-copy-btn" data-target="oveApiPass">
							<?php esc_html_e( 'Copy', 'ovebot-ai-chatbot-live-chat-ai-sales-agent-for-woocommerce' ); ?>
						</button>
					</div>
				</div>

				<div class="ovebotai-field">
					<button type="button" class="button ovebotai-regen-creds-btn">
						<?php esc_html_e( 'Regenerate credentials', 'ovebot-ai-chatbot-live-chat-ai-sales-agent-for-woocommerce' ); ?>
					</button>
					<p class="description"><?php esc_html_e( 'Generates a new user/password pair. The old ones will stop working immediately.', 'ovebot-ai-chatbot-live-chat-ai-sales-agent-for-woocommerce' ); ?></p>
				</div>

			</div>
		</div>

		<!-- ── Delivery estimate ────────────────────────────────────────── -->
		<div class="ovebotai-fieldset">
			<div class="ovebotai-fieldset-legend">
				<span class="dashicons dashicons-clock" aria-hidden="true"></span>
				<?php esc_html_e( 'Delivery estimate', 'ovebot-ai-chatbot-live-chat-ai-sales-agent-for-woocommerce' ); ?>
			</div>
			<div class="ovebotai-fieldset-body">

				<p class="description ovebotai-fieldset-intro"><?php esc_html_e( 'Your AI agent uses these ranges to answer "When will my order arrive?" - counted in business days, Sundays excluded. Set a realistic min–max range for each of the three order situations below.', 'ovebot-ai-chatbot-live-chat-ai-sales-agent-for-woocommerce' ); ?></p>

				<?php
				$ovebotai_delivery_fields = array(
					array(
						'shipped',
						__( 'Orders that have already shipped', 'ovebot-ai-chatbot-live-chat-ai-sales-agent-for-woocommerce' ),
						__( 'Counted from the day the order was shipped, until it reaches the customer.', 'ovebot-ai-chatbot-live-chat-ai-sales-agent-for-woocommerce' ),
						1,
						2,
					),
					array(
						'instock',
						__( 'New orders - every item in stock', 'ovebot-ai-chatbot-live-chat-ai-sales-agent-for-woocommerce' ),
						__( 'Counted from the day the order was placed - nothing to wait on before it ships.', 'ovebot-ai-chatbot-live-chat-ai-sales-agent-for-woocommerce' ),
						2,
						4,
					),
					array(
						'oos',
						__( 'New orders - at least one item out of stock', 'ovebot-ai-chatbot-live-chat-ai-sales-agent-for-woocommerce' ),
						__( 'Counted from the day the order was placed - includes the wait for restock before it can ship.', 'ovebot-ai-chatbot-live-chat-ai-sales-agent-for-woocommerce' ),
						5,
						10,
					),
				);
				foreach ( $ovebotai_delivery_fields as list( $ovebotai_key, $ovebotai_label, $ovebotai_help, $ovebotai_dmin, $ovebotai_dmax ) ) : ?>
				<div class="ovebotai-field ovebotai-field-delivery">
					<label><?php echo esc_html( $ovebotai_label ); ?></label>
					<div class="ovebotai-delivery-inputs">
						<input type="number" name="days_<?php echo esc_attr( $ovebotai_key ); ?>_min" class="small-text"
							value="<?php echo esc_attr( Ovebotai_Settings::get_delivery( 'days_' . $ovebotai_key . '_min', $ovebotai_dmin ) ); ?>" min="0" max="60">
						<span class="ovebotai-dash">&ndash;</span>
						<input type="number" name="days_<?php echo esc_attr( $ovebotai_key ); ?>_max" class="small-text"
							value="<?php echo esc_attr( Ovebotai_Settings::get_delivery( 'days_' . $ovebotai_key . '_max', $ovebotai_dmax ) ); ?>" min="0" max="60">
						<span class="ovebotai-unit"><?php esc_html_e( 'business days', 'ovebot-ai-chatbot-live-chat-ai-sales-agent-for-woocommerce' ); ?></span>
					</div>
					<p class="description"><?php echo esc_html( $ovebotai_help ); ?></p>
				</div>
				<?php endforeach; ?>

			</div>
		</div>

		<?php else : ?>
		<div class="ovebotai-fieldset">
			<div class="ovebotai-fieldset-body">
				<div class="ovebotai-notice ovebotai-notice-warning">
					<p><?php esc_html_e( 'WooCommerce is not active. Product feed and order tracking are unavailable.', 'ovebot-ai-chatbot-live-chat-ai-sales-agent-for-woocommerce' ); ?></p>
				</div>
			</div>
		</div>
		<?php endif; ?>

		<!-- ── Appearance ───────────────────────────────────────────────── -->
		<div class="ovebotai-fieldset">
			<div class="ovebotai-fieldset-legend">
				<span class="dashicons dashicons-admin-appearance" aria-hidden="true"></span>
				<?php esc_html_e( 'Appearance', 'ovebot-ai-chatbot-live-chat-ai-sales-agent-for-woocommerce' ); ?>
			</div>
			<div class="ovebotai-fieldset-body">

				<div class="ovebotai-field">
					<button type="button" class="button" id="oveAppearanceToggle">
						<?php esc_html_e( 'Configure appearance', 'ovebot-ai-chatbot-live-chat-ai-sales-agent-for-woocommerce' ); ?>
						<span class="dashicons dashicons-arrow-down-alt2" id="oveAppearanceToggleIcon" aria-hidden="true"></span>
					</button>
				</div>

				<div class="ovebotai-appearance-panel" id="oveAppearancePanel" style="display:none">

					<div class="ovebotai-appearance-subhead"><?php esc_html_e( 'Look & feel', 'ovebot-ai-chatbot-live-chat-ai-sales-agent-for-woocommerce' ); ?></div>
					<div class="ovebotai-appearance-grid">

						<div class="ovebotai-field">
							<label for="ove_accent_color"><?php esc_html_e( 'Accent colour', 'ovebot-ai-chatbot-live-chat-ai-sales-agent-for-woocommerce' ); ?></label>
							<div class="ovebotai-color-wrap">
								<input type="color" id="ove_color_picker" value="<?php echo esc_attr( $ovebotai_widget['accent_color'] ?? '#2271B1' ); ?>">
								<input type="text" name="widget_accent_color" id="ove_accent_color" class="regular-text"
									value="<?php echo esc_attr( $ovebotai_widget['accent_color'] ?? '' ); ?>"
									placeholder="#2271B1" maxlength="7">
							</div>
						</div>

						<div class="ovebotai-field">
							<label for="ove_theme"><?php esc_html_e( 'Theme', 'ovebot-ai-chatbot-live-chat-ai-sales-agent-for-woocommerce' ); ?></label>
							<select name="widget_theme" id="ove_theme">
								<option value="" <?php selected( $ovebotai_widget['theme'] ?? '', '' ); ?>><?php esc_html_e( 'Default (light)', 'ovebot-ai-chatbot-live-chat-ai-sales-agent-for-woocommerce' ); ?></option>
								<option value="light" <?php selected( $ovebotai_widget['theme'] ?? '', 'light' ); ?>><?php esc_html_e( 'Light', 'ovebot-ai-chatbot-live-chat-ai-sales-agent-for-woocommerce' ); ?></option>
								<option value="dark" <?php selected( $ovebotai_widget['theme'] ?? '', 'dark' ); ?>><?php esc_html_e( 'Dark', 'ovebot-ai-chatbot-live-chat-ai-sales-agent-for-woocommerce' ); ?></option>
							</select>
						</div>

						<div class="ovebotai-field">
							<label for="ove_language"><?php esc_html_e( 'Language', 'ovebot-ai-chatbot-live-chat-ai-sales-agent-for-woocommerce' ); ?></label>
							<select name="widget_language" id="ove_language">
								<option value=""     <?php selected( $ovebotai_widget['language'] ?? '', '' ); ?>><?php esc_html_e( 'Default', 'ovebot-ai-chatbot-live-chat-ai-sales-agent-for-woocommerce' ); ?></option>
								<option value="auto" <?php selected( $ovebotai_widget['language'] ?? '', 'auto' ); ?>><?php esc_html_e( 'Auto (browser)', 'ovebot-ai-chatbot-live-chat-ai-sales-agent-for-woocommerce' ); ?></option>
								<option value="en" <?php selected( $ovebotai_widget['language'] ?? '', 'en' ); ?>>English</option>
								<option value="ro" <?php selected( $ovebotai_widget['language'] ?? '', 'ro' ); ?>>Română</option>
								<option value="de" <?php selected( $ovebotai_widget['language'] ?? '', 'de' ); ?>>Deutsch</option>
								<option value="fr" <?php selected( $ovebotai_widget['language'] ?? '', 'fr' ); ?>>Français</option>
							</select>
						</div>

						<div class="ovebotai-field">
							<label for="ove_audio_beep"><?php esc_html_e( 'Audio beep', 'ovebot-ai-chatbot-live-chat-ai-sales-agent-for-woocommerce' ); ?></label>
							<select name="widget_audio_beep" id="ove_audio_beep">
								<option value="" <?php selected( $ovebotai_widget['audio_beep'] ?? '', '' ); ?>><?php esc_html_e( 'Default (play)', 'ovebot-ai-chatbot-live-chat-ai-sales-agent-for-woocommerce' ); ?></option>
								<option value="play" <?php selected( $ovebotai_widget['audio_beep'] ?? '', 'play' ); ?>><?php esc_html_e( 'Play', 'ovebot-ai-chatbot-live-chat-ai-sales-agent-for-woocommerce' ); ?></option>
								<option value="none" <?php selected( $ovebotai_widget['audio_beep'] ?? '', 'none' ); ?>><?php esc_html_e( 'None', 'ovebot-ai-chatbot-live-chat-ai-sales-agent-for-woocommerce' ); ?></option>
							</select>
						</div>

					</div><!-- /.ovebotai-appearance-grid -->

					<div class="ovebotai-appearance-subhead"><?php esc_html_e( 'Position on page', 'ovebot-ai-chatbot-live-chat-ai-sales-agent-for-woocommerce' ); ?></div>
					<div class="ovebotai-field ovebotai-field-full">
						<label for="ove_side"><?php esc_html_e( 'Widget position', 'ovebot-ai-chatbot-live-chat-ai-sales-agent-for-woocommerce' ); ?></label>
						<div class="ovebotai-combo-row">
							<select name="widget_side" id="ove_side">
								<option value="" <?php selected( $ovebotai_widget['side'] ?? '', '' ); ?>><?php esc_html_e( 'Default (right)', 'ovebot-ai-chatbot-live-chat-ai-sales-agent-for-woocommerce' ); ?></option>
								<option value="right" <?php selected( $ovebotai_widget['side'] ?? '', 'right' ); ?>><?php esc_html_e( 'Bottom right', 'ovebot-ai-chatbot-live-chat-ai-sales-agent-for-woocommerce' ); ?></option>
								<option value="left" <?php selected( $ovebotai_widget['side'] ?? '', 'left' ); ?>><?php esc_html_e( 'Bottom left', 'ovebot-ai-chatbot-live-chat-ai-sales-agent-for-woocommerce' ); ?></option>
							</select>
							<div class="ovebotai-combo-sub">
								<input type="number" name="widget_offset_y" id="ove_offset_y" class="small-text"
									value="<?php echo esc_attr( $ovebotai_widget['offset_y'] ?? '' ); ?>" min="0" placeholder="20">
								<span class="ovebotai-combo-suffix"><?php esc_html_e( 'px offset from bottom', 'ovebot-ai-chatbot-live-chat-ai-sales-agent-for-woocommerce' ); ?></span>
							</div>
						</div>
						<p class="description"><?php esc_html_e( 'Raise the offset to sit the widget above another floating button, e.g. WhatsApp.', 'ovebot-ai-chatbot-live-chat-ai-sales-agent-for-woocommerce' ); ?></p>
					</div>

					<div class="ovebotai-appearance-subhead"><?php esc_html_e( 'Messages', 'ovebot-ai-chatbot-live-chat-ai-sales-agent-for-woocommerce' ); ?></div>
					<div class="ovebotai-field ovebotai-field-full">
						<label for="ove_subtitle"><?php esc_html_e( 'Subtitle', 'ovebot-ai-chatbot-live-chat-ai-sales-agent-for-woocommerce' ); ?></label>
						<input type="text" name="widget_subtitle" id="ove_subtitle" class="regular-text"
							value="<?php echo esc_attr( $ovebotai_widget['subtitle'] ?? '' ); ?>"
							placeholder="<?php esc_attr_e( 'e.g. Usually replies in a few minutes', 'ovebot-ai-chatbot-live-chat-ai-sales-agent-for-woocommerce' ); ?>">
						<p class="description"><?php esc_html_e( 'Shown under the assistant\'s name in the chat header.', 'ovebot-ai-chatbot-live-chat-ai-sales-agent-for-woocommerce' ); ?></p>
					</div>

					<div class="ovebotai-field ovebotai-field-full">
						<label for="ove_proactive_message"><?php esc_html_e( 'Proactive message', 'ovebot-ai-chatbot-live-chat-ai-sales-agent-for-woocommerce' ); ?></label>
						<div class="ovebotai-combo-row">
							<input type="text" name="widget_proactive_message" id="ove_proactive_message" class="regular-text"
								value="<?php echo esc_attr( $ovebotai_widget['proactive_message'] ?? '' ); ?>"
								placeholder="<?php esc_attr_e( 'e.g. Need help finding something?', 'ovebot-ai-chatbot-live-chat-ai-sales-agent-for-woocommerce' ); ?>">
							<div class="ovebotai-combo-sub">
								<input type="number" name="widget_proactive_delay" id="ove_proactive_delay" class="small-text"
									value="<?php echo esc_attr( $ovebotai_widget['proactive_delay'] ?? '' ); ?>" min="0" max="300" placeholder="4">
								<span class="ovebotai-combo-suffix"><?php esc_html_e( 's after page load', 'ovebot-ai-chatbot-live-chat-ai-sales-agent-for-woocommerce' ); ?></span>
							</div>
						</div>
						<p class="description"><?php esc_html_e( 'A short message that pops up on its own to invite visitors to chat. Leave empty to disable.', 'ovebot-ai-chatbot-live-chat-ai-sales-agent-for-woocommerce' ); ?></p>
					</div>
				</div><!-- /.ovebotai-appearance-panel -->

			</div>
		</div>

		<!-- Save -->
		<div class="ovebotai-save-row">
			<button type="submit" class="button button-primary button-large" id="oveSaveBtn">
				<?php esc_html_e( 'Save settings', 'ovebot-ai-chatbot-live-chat-ai-sales-agent-for-woocommerce' ); ?>
			</button>
		</div>

	</form>

</div><!-- /.wrap -->
