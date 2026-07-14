<?php
defined( 'ABSPATH' ) || exit;

$oauth     = Ovebotai_OAuth::instance();
$admin     = Ovebotai_Admin::instance();
$workspace = $oauth->get_workspace();
$agent     = $oauth->get_agent();
$widget    = Ovebotai_Settings::get_widget();
$kb_pages  = $admin->get_pages_for_kb();

$feed_hash = (string) get_option( 'ovebotai_feed_hash', '' );
$feed_url  = add_query_arg( 'hash', $feed_hash, home_url( '/wp-json/ovebotai/v1/feed' ) );
$order_url = home_url( '/wp-json/ovebotai/v1/orders' );

$wc_active   = Ovebotai::woocommerce_active();
$is_connected = $oauth->is_connected(); // false when refresh token missing/revoked
?>
<div class="wrap ovebotai-wrap">

	<div class="ovebotai-settings-header">
		<div class="ovebotai-logo">
			<img src="<?php echo esc_url( OVEBOTAI_URL . 'admin/img/logo.png' ); ?>" alt="Ovebot.ai" height="32">
			<h1><?php esc_html_e( 'Settings', 'ovebotai' ); ?></h1>
			<a href="<?php echo esc_url( admin_url( 'admin.php?page=ovebotai' ) ); ?>" class="ovebotai-back-link">
				<?php esc_html_e( '← Dashboard', 'ovebotai' ); ?>
			</a>
		</div>
		<?php require OVEBOTAI_DIR . 'admin/views/partials/connection-badge.php'; ?>
	</div>

	<?php if ( ! $is_connected ) : ?>
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

				<div class="ovebotai-field">
					<label for="ove_workspace"><?php esc_html_e( 'Workspace', 'ovebotai' ); ?></label>
					<input type="text" name="workspace" id="ove_workspace" class="regular-text" value="<?php echo esc_attr( $workspace ); ?>">
				</div>
				<div class="ovebotai-field">
					<label for="ove_agent"><?php esc_html_e( 'Agent', 'ovebotai' ); ?></label>
					<input type="text" name="agent" id="ove_agent" class="regular-text" value="<?php echo esc_attr( $agent ); ?>">
				</div>

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
								<input type="color" id="ove_color_picker" value="<?php echo esc_attr( $widget['accent_color'] ?? '#2271B1' ); ?>">
								<input type="text" name="widget_accent_color" id="ove_accent_color" class="regular-text"
									value="<?php echo esc_attr( $widget['accent_color'] ?? '' ); ?>"
									placeholder="#2271B1" maxlength="7">
							</div>
						</div>

						<div class="ovebotai-field">
							<label for="ove_theme"><?php esc_html_e( 'Theme', 'ovebotai' ); ?></label>
							<select name="widget_theme" id="ove_theme">
								<option value="" <?php selected( $widget['theme'] ?? '', '' ); ?>><?php esc_html_e( 'Default (light)', 'ovebotai' ); ?></option>
								<option value="light" <?php selected( $widget['theme'] ?? '', 'light' ); ?>><?php esc_html_e( 'Light', 'ovebotai' ); ?></option>
								<option value="dark" <?php selected( $widget['theme'] ?? '', 'dark' ); ?>><?php esc_html_e( 'Dark', 'ovebotai' ); ?></option>
							</select>
						</div>

						<div class="ovebotai-field">
							<label for="ove_language"><?php esc_html_e( 'Language', 'ovebotai' ); ?></label>
							<select name="widget_language" id="ove_language">
								<option value="auto" <?php selected( $widget['language'] ?? 'auto', 'auto' ); ?>><?php esc_html_e( 'Auto (browser)', 'ovebotai' ); ?></option>
								<option value="en" <?php selected( $widget['language'] ?? '', 'en' ); ?>>English</option>
								<option value="ro" <?php selected( $widget['language'] ?? '', 'ro' ); ?>>Română</option>
								<option value="de" <?php selected( $widget['language'] ?? '', 'de' ); ?>>Deutsch</option>
								<option value="fr" <?php selected( $widget['language'] ?? '', 'fr' ); ?>>Français</option>
							</select>
						</div>

						<div class="ovebotai-field">
							<label for="ove_side"><?php esc_html_e( 'Position', 'ovebotai' ); ?></label>
							<select name="widget_side" id="ove_side">
								<option value="" <?php selected( $widget['side'] ?? '', '' ); ?>><?php esc_html_e( 'Default (right)', 'ovebotai' ); ?></option>
								<option value="right" <?php selected( $widget['side'] ?? '', 'right' ); ?>><?php esc_html_e( 'Bottom right', 'ovebotai' ); ?></option>
								<option value="left" <?php selected( $widget['side'] ?? '', 'left' ); ?>><?php esc_html_e( 'Bottom left', 'ovebotai' ); ?></option>
							</select>
						</div>

						<div class="ovebotai-field">
							<label for="ove_subtitle"><?php esc_html_e( 'Subtitle', 'ovebotai' ); ?></label>
							<input type="text" name="widget_subtitle" id="ove_subtitle" class="regular-text"
								value="<?php echo esc_attr( $widget['subtitle'] ?? '' ); ?>">
						</div>

						<div class="ovebotai-field">
							<label for="ove_proactive_message"><?php esc_html_e( 'Proactive message', 'ovebotai' ); ?></label>
							<input type="text" name="widget_proactive_message" id="ove_proactive_message" class="regular-text"
								value="<?php echo esc_attr( $widget['proactive_message'] ?? '' ); ?>">
						</div>

						<div class="ovebotai-field ovebotai-field-short">
							<label for="ove_proactive_delay"><?php esc_html_e( 'Proactive delay (s)', 'ovebotai' ); ?></label>
							<input type="number" name="widget_proactive_delay" id="ove_proactive_delay" class="small-text"
								value="<?php echo esc_attr( $widget['proactive_delay'] ?? '' ); ?>" min="0" max="300" placeholder="4">
						</div>

						<div class="ovebotai-field">
							<label for="ove_audio_beep"><?php esc_html_e( 'Audio beep', 'ovebotai' ); ?></label>
							<select name="widget_audio_beep" id="ove_audio_beep">
								<option value="" <?php selected( $widget['audio_beep'] ?? '', '' ); ?>><?php esc_html_e( 'Default (play)', 'ovebotai' ); ?></option>
								<option value="play" <?php selected( $widget['audio_beep'] ?? '', 'play' ); ?>><?php esc_html_e( 'Play', 'ovebotai' ); ?></option>
								<option value="none" <?php selected( $widget['audio_beep'] ?? '', 'none' ); ?>><?php esc_html_e( 'None', 'ovebotai' ); ?></option>
							</select>
						</div>

						<div class="ovebotai-field ovebotai-field-short">
							<label for="ove_offset_y"><?php esc_html_e( 'Bottom offset (px)', 'ovebotai' ); ?></label>
							<input type="number" name="widget_offset_y" id="ove_offset_y" class="small-text"
								value="<?php echo esc_attr( $widget['offset_y'] ?? '' ); ?>" min="0" placeholder="20">
							<p class="description"><?php esc_html_e( 'Raise to sit above e.g. a WhatsApp button.', 'ovebotai' ); ?></p>
						</div>

					</div><!-- /.ovebotai-appearance-grid -->
				</div><!-- /.ovebotai-appearance-panel -->

			</div>
		</div>

		<?php if ( $is_connected ) : ?>

		<!-- ── Knowledge Bases ──────────────────────────────────────────── -->
		<div class="ovebotai-fieldset">
			<div class="ovebotai-fieldset-legend"><?php esc_html_e( 'Knowledge Bases', 'ovebotai' ); ?></div>
			<div class="ovebotai-fieldset-body">
				<p class="ovebotai-lead">
					<?php esc_html_e( 'The following pages are used by the chat to provide accurate information to your customers. Select the ones you want to include — pages you publish later will show up here too.', 'ovebotai' ); ?>
				</p>

				<?php if ( empty( $kb_pages ) ) : ?>
				<p class="ovebotai-muted"><?php esc_html_e( 'No published pages found.', 'ovebotai' ); ?></p>
				<?php else : ?>
				<div class="ovebotai-pages-list">
					<?php foreach ( $kb_pages as $page ) : ?>
					<label class="ovebotai-page-item">
						<input type="checkbox"
							name="kb_pages[]"
							value="<?php echo esc_attr( $page['id'] ); ?>"
							<?php checked( $page['checked'] ); ?>>
						<span class="ovebotai-checkbox-mark" aria-hidden="true"></span>
						<div class="ovebotai-page-info">
							<span class="ovebotai-page-title"><?php echo esc_html( $page['title'] ); ?></span>
							<a href="<?php echo esc_url( get_permalink( $page['id'] ) ); ?>"
								target="_blank"
								rel="noopener noreferrer"
								class="ovebotai-page-url"
								onclick="event.stopPropagation()">
								<?php echo esc_html( str_replace( home_url(), '', get_permalink( $page['id'] ) ) ); ?>
								<span class="ovebotai-ext-icon">↗</span>
							</a>
						</div>
					</label>
					<?php endforeach; ?>
				</div>
				<?php endif; ?>
			</div>
		</div>

		<?php else : ?>
		<div class="ovebotai-fieldset ovebotai-fieldset-locked">
			<div class="ovebotai-fieldset-legend">
				<?php esc_html_e( 'Knowledge Bases', 'ovebotai' ); ?>
				<span class="ovebotai-lock-badge"><?php esc_html_e( 'Requires connection', 'ovebotai' ); ?></span>
			</div>
		</div>
		<?php endif; ?>

		<?php if ( $wc_active && $is_connected ) : ?>

		<!-- ── Product Feed ─────────────────────────────────────────────── -->
		<div class="ovebotai-fieldset">
			<div class="ovebotai-fieldset-legend"><?php esc_html_e( 'Product feed', 'ovebotai' ); ?></div>
			<div class="ovebotai-fieldset-body">

				<div class="ovebotai-field">
					<label><?php esc_html_e( 'Feed URL', 'ovebotai' ); ?></label>
					<div class="ovebotai-url-row">
						<input type="text" class="regular-text ovebotai-readonly-url" id="oveFeedUrl"
							value="<?php echo esc_attr( $feed_url ); ?>" readonly>
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
							value="<?php echo esc_attr( $order_url ); ?>" readonly>
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
				$delivery_fields = array(
					array( 'shipped',  __( 'Shipped order', 'ovebotai' ),                       __( 'Counted from the ship date.', 'ovebotai' ),                          1, 2 ),
					array( 'instock', __( 'New / processing — all in stock', 'ovebotai' ),      __( 'Counted from the order date.', 'ovebotai' ),                         2, 4 ),
					array( 'oos',     __( 'New / processing — some out of stock', 'ovebotai' ), __( 'Counted from the order date.', 'ovebotai' ),                         5, 10 ),
				);
				foreach ( $delivery_fields as list( $key, $label, $help, $dmin, $dmax ) ) : ?>
				<div class="ovebotai-field ovebotai-field-delivery">
					<label><?php echo esc_html( $label ); ?></label>
					<div class="ovebotai-delivery-inputs">
						<input type="number" name="days_<?php echo esc_attr( $key ); ?>_min" class="small-text"
							value="<?php echo esc_attr( Ovebotai_Settings::get_delivery( 'days_' . $key . '_min', $dmin ) ); ?>" min="0" max="60">
						<span class="ovebotai-dash">&ndash;</span>
						<input type="number" name="days_<?php echo esc_attr( $key ); ?>_max" class="small-text"
							value="<?php echo esc_attr( Ovebotai_Settings::get_delivery( 'days_' . $key . '_max', $dmax ) ); ?>" min="0" max="60">
						<span class="ovebotai-unit"><?php esc_html_e( 'business days', 'ovebotai' ); ?></span>
					</div>
					<p class="description"><?php echo esc_html( $help ); ?></p>
				</div>
				<?php endforeach; ?>

			</div>
		</div>

		<?php elseif ( $wc_active && ! $is_connected ) : ?>
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

		<!-- Save -->
		<div class="ovebotai-save-row">
			<button type="submit" class="button button-primary button-large" id="oveSaveBtn">
				<?php esc_html_e( 'Save settings', 'ovebotai' ); ?>
			</button>
		</div>

	</form>

</div><!-- /.wrap -->
