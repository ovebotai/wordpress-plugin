<?php
defined( 'ABSPATH' ) || exit;

$ovebotai_oauth     = Ovebotai_OAuth::instance();
$ovebotai_workspace = $ovebotai_oauth->get_workspace();
$ovebotai_widget    = Ovebotai_Settings::get_widget();

$ovebotai_feed_hash = (string) get_option( 'ovebotai_feed_hash', '' );
$ovebotai_feed_url  = add_query_arg( 'hash', $ovebotai_feed_hash, home_url( '/wp-json/ovebotai/v1/feed' ) );
$ovebotai_order_url = home_url( '/wp-json/ovebotai/v1/orders' );

$ovebotai_wc_active   = Ovebotai::woocommerce_active();
$ovebotai_is_connected = $ovebotai_oauth->is_connected(); // false when refresh token missing/revoked
?>
<div class="wrap ovebotai-wrap">

	<div class="ovebotai-settings-header">
		<div class="ovebotai-logo">
			<a href="https://ovebot.ai" target="_blank" rel="noopener noreferrer">
				<img src="<?php echo esc_url( OVEBOTAI_URL . 'admin/img/logo.png' ); ?>" alt="Ovebot.ai" height="32">
			</a>
			<h1><?php esc_html_e( 'Settings', 'ovebotai' ); ?></h1>
			<a href="<?php echo esc_url( admin_url( 'admin.php?page=ovebotai' ) ); ?>" class="ovebotai-back-link">
				<?php esc_html_e( '← Dashboard', 'ovebotai' ); ?>
			</a>
		</div>
		<?php require OVEBOTAI_DIR . 'admin/views/partials/connection-badge.php'; ?>
	</div>

	<?php if ( ! $ovebotai_is_connected ) : ?>
	<div class="ovebotai-notice ovebotai-notice-warning ovebotai-reconnect-banner">
		<p>
			<strong><?php esc_html_e( 'Connection lost.', 'ovebotai' ); ?></strong>
			<?php esc_html_e( 'Chat widget settings can still be saved, but product feed, order tracking and delivery estimate require an active connection.', 'ovebotai' ); ?>
		</p>
	</div>
	<?php endif; ?>

	<div id="oveSettingsNotice" class="ovebotai-save-notice" style="display:none"></div>
	<div id="oveSettingsWarnings" class="ovebotai-save-notice ovebotai-notice-warning" style="display:none"></div>

	<form id="oveSettingsForm">

		<!-- ── Chat Widget ──────────────────────────────────────────────── -->
		<div class="ovebotai-fieldset">
			<div class="ovebotai-fieldset-legend"><?php esc_html_e( 'On-site chat widget', 'ovebotai' ); ?></div>
			<div class="ovebotai-fieldset-body">

				<div class="ovebotai-field ovebotai-field-switch">
					<label><?php esc_html_e( 'Chat widget status', 'ovebotai' ); ?></label>
					<div class="ovebotai-switch-wrap">
						<label class="ovebotai-switch">
							<input type="checkbox" name="chat_status" id="oveChatStatus" value="1" <?php checked( get_option( 'ovebotai_chat_status' ), '1' ); ?>>
							<span class="ovebotai-switch-slider"></span>
						</label>
						<span class="ovebotai-switch-lbl" id="oveChatStatusLbl">
							<?php echo get_option( 'ovebotai_chat_status' ) === '1' ? esc_html__( 'Enabled', 'ovebotai' ) : esc_html__( 'Disabled', 'ovebotai' ); ?>
						</span>
					</div>
				</div>

			</div>
		</div>

		<?php if ( $ovebotai_wc_active && $ovebotai_is_connected ) : ?>

		<!-- ── Product Feed ─────────────────────────────────────────────── -->
		<div class="ovebotai-fieldset">
			<div class="ovebotai-fieldset-legend"><?php esc_html_e( 'Product feed', 'ovebotai' ); ?></div>
			<div class="ovebotai-fieldset-body">

				<div class="ovebotai-field">
					<label><?php esc_html_e( 'Feed URL', 'ovebotai' ); ?></label>
					<div class="ovebotai-url-row">
						<input type="text" class="regular-text ovebotai-readonly-url" id="oveFeedUrl"
							value="<?php echo esc_attr( $ovebotai_feed_url ); ?>" readonly>
						<button type="button" class="button ovebotai-copy-btn" data-target="oveFeedUrl">
							<?php esc_html_e( 'Copy', 'ovebotai' ); ?>
						</button>
						<button type="button" class="button ovebotai-regen-hash-btn" title="<?php esc_attr_e( 'Regenerate hash', 'ovebotai' ); ?>">
							↻
						</button>
					</div>
				</div>

				<div class="ovebotai-field">
					<button type="button" class="button ovebotai-clear-cache-btn">
						<?php esc_html_e( 'Clear feed cache', 'ovebotai' ); ?>
					</button>
					<p class="description"><?php esc_html_e( 'The feed is cached for performance. Clear it after major catalog changes.', 'ovebotai' ); ?></p>
				</div>

			</div>
		</div>

		<!-- ── Order Tracking ───────────────────────────────────────────── -->
		<div class="ovebotai-fieldset">
			<div class="ovebotai-fieldset-legend"><?php esc_html_e( 'Order tracking API', 'ovebotai' ); ?></div>
			<div class="ovebotai-fieldset-body">

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

		<!-- ── Delivery estimate ────────────────────────────────────────── -->
		<div class="ovebotai-fieldset">
			<div class="ovebotai-fieldset-legend"><?php esc_html_e( 'Delivery estimate (business days, excludes Sunday)', 'ovebotai' ); ?></div>
			<div class="ovebotai-fieldset-body">

				<?php
				$ovebotai_delivery_fields = array(
					array( 'shipped',  __( 'Shipped order', 'ovebotai' ),                       __( 'Counted from the ship date.', 'ovebotai' ),                          1, 2 ),
					array( 'instock', __( 'New / processing — all in stock', 'ovebotai' ),      __( 'Counted from the order date.', 'ovebotai' ),                         2, 4 ),
					array( 'oos',     __( 'New / processing — some out of stock', 'ovebotai' ), __( 'Counted from the order date.', 'ovebotai' ),                         5, 10 ),
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
						<span class="ovebotai-unit"><?php esc_html_e( 'business days', 'ovebotai' ); ?></span>
					</div>
					<p class="description"><?php echo esc_html( $ovebotai_help ); ?></p>
				</div>
				<?php endforeach; ?>

			</div>
		</div>

		<?php elseif ( $ovebotai_wc_active && ! $ovebotai_is_connected ) : ?>
		<div class="ovebotai-fieldset ovebotai-fieldset-locked">
			<div class="ovebotai-fieldset-legend">
				<?php esc_html_e( 'Product feed', 'ovebotai' ); ?>
				<span class="ovebotai-lock-badge"><?php esc_html_e( 'Requires connection', 'ovebotai' ); ?></span>
			</div>
			<div class="ovebotai-fieldset-legend">
				<?php esc_html_e( 'Order tracking API', 'ovebotai' ); ?>
				<span class="ovebotai-lock-badge"><?php esc_html_e( 'Requires connection', 'ovebotai' ); ?></span>
			</div>
			<div class="ovebotai-fieldset-legend">
				<?php esc_html_e( 'Delivery estimate', 'ovebotai' ); ?>
				<span class="ovebotai-lock-badge"><?php esc_html_e( 'Requires connection', 'ovebotai' ); ?></span>
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
			<div class="ovebotai-fieldset-legend"><?php esc_html_e( 'Appearance', 'ovebotai' ); ?></div>
			<div class="ovebotai-fieldset-body">

				<div class="ovebotai-field">
					<button type="button" class="button" id="oveAppearanceToggle">
						<?php esc_html_e( 'Configure appearance ▾', 'ovebotai' ); ?>
					</button>
				</div>

				<div class="ovebotai-appearance-panel" id="oveAppearancePanel" style="display:none">
					<div class="ovebotai-appearance-grid">

						<div class="ovebotai-field">
							<label for="ove_accent_color"><?php esc_html_e( 'Accent colour', 'ovebotai' ); ?></label>
							<div class="ovebotai-color-wrap">
								<input type="color" id="ove_color_picker" value="<?php echo esc_attr( $ovebotai_widget['accent_color'] ?? '#2271B1' ); ?>">
								<input type="text" name="widget_accent_color" id="ove_accent_color" class="regular-text"
									value="<?php echo esc_attr( $ovebotai_widget['accent_color'] ?? '' ); ?>"
									placeholder="#2271B1" maxlength="7">
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
							<label for="ove_side"><?php esc_html_e( 'Position', 'ovebotai' ); ?></label>
							<select name="widget_side" id="ove_side">
								<option value="" <?php selected( $ovebotai_widget['side'] ?? '', '' ); ?>><?php esc_html_e( 'Default (right)', 'ovebotai' ); ?></option>
								<option value="right" <?php selected( $ovebotai_widget['side'] ?? '', 'right' ); ?>><?php esc_html_e( 'Bottom right', 'ovebotai' ); ?></option>
								<option value="left" <?php selected( $ovebotai_widget['side'] ?? '', 'left' ); ?>><?php esc_html_e( 'Bottom left', 'ovebotai' ); ?></option>
							</select>
						</div>

						<div class="ovebotai-field">
							<label for="ove_subtitle"><?php esc_html_e( 'Subtitle', 'ovebotai' ); ?></label>
							<input type="text" name="widget_subtitle" id="ove_subtitle" class="regular-text"
								value="<?php echo esc_attr( $ovebotai_widget['subtitle'] ?? '' ); ?>">
						</div>

						<div class="ovebotai-field">
							<label for="ove_proactive_message"><?php esc_html_e( 'Proactive message', 'ovebotai' ); ?></label>
							<input type="text" name="widget_proactive_message" id="ove_proactive_message" class="regular-text"
								value="<?php echo esc_attr( $ovebotai_widget['proactive_message'] ?? '' ); ?>">
						</div>

						<div class="ovebotai-field ovebotai-field-short">
							<label for="ove_proactive_delay"><?php esc_html_e( 'Proactive delay (s)', 'ovebotai' ); ?></label>
							<input type="number" name="widget_proactive_delay" id="ove_proactive_delay" class="small-text"
								value="<?php echo esc_attr( $ovebotai_widget['proactive_delay'] ?? '' ); ?>" min="0" max="300" placeholder="4">
						</div>

						<div class="ovebotai-field">
							<label for="ove_audio_beep"><?php esc_html_e( 'Audio beep', 'ovebotai' ); ?></label>
							<select name="widget_audio_beep" id="ove_audio_beep">
								<option value="" <?php selected( $ovebotai_widget['audio_beep'] ?? '', '' ); ?>><?php esc_html_e( 'Default (play)', 'ovebotai' ); ?></option>
								<option value="play" <?php selected( $ovebotai_widget['audio_beep'] ?? '', 'play' ); ?>><?php esc_html_e( 'Play', 'ovebotai' ); ?></option>
								<option value="none" <?php selected( $ovebotai_widget['audio_beep'] ?? '', 'none' ); ?>><?php esc_html_e( 'None', 'ovebotai' ); ?></option>
							</select>
						</div>

						<div class="ovebotai-field ovebotai-field-short">
							<label for="ove_offset_y"><?php esc_html_e( 'Bottom offset (px)', 'ovebotai' ); ?></label>
							<input type="number" name="widget_offset_y" id="ove_offset_y" class="small-text"
								value="<?php echo esc_attr( $ovebotai_widget['offset_y'] ?? '' ); ?>" min="0" placeholder="20">
							<p class="description"><?php esc_html_e( 'Raise to sit above e.g. a WhatsApp button.', 'ovebotai' ); ?></p>
						</div>

					</div><!-- /.ovebotai-appearance-grid -->
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
