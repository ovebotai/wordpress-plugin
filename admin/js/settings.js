/* global ovebotaiSettings, jQuery */
(function ($) {
	'use strict';

	var cfg = ovebotaiSettings;

	$(function () {

		// ── "Highlight this field" deep-link (item 12) ───────────────────────
		// Generic, keyed by any field identifier: a caller (e.g. the dashboard's
		// chat quick-link) links here with ?highlight=<field>. We point a short
		// finite pulse at the nearest visible control — not the whole field row —
		// and only scroll if it isn't already on screen. The parameter is left in
		// the URL as-is afterwards.
		if (cfg.highlight) {
			var target = document.querySelector('[name="' + cfg.highlight + '"]') ||
				document.getElementById(cfg.highlight);
			if (target) {
				// The clickable control itself (e.g. the switch), not its label/
				// description block.
				var wrap = target.closest('.ovebotai-switch') ||
					target.closest('.ovebotai-field') || target;

				var rect = wrap.getBoundingClientRect();
				var vh   = window.innerHeight || document.documentElement.clientHeight;
				// The fixed #wpadminbar (32px, 46px on mobile) sits on top of the
				// viewport in every wp-admin screen — without this, a field just
				// below it reads as "in viewport" by rect math while actually
				// being covered by the bar, so the pulse would fire off-screen.
				var adminBar  = document.getElementById('wpadminbar');
				var topOffset = adminBar ? adminBar.getBoundingClientRect().bottom : 0;
				if (rect.top < topOffset || rect.bottom > vh) {
					wrap.scrollIntoView({ behavior: 'smooth', block: 'center' });
				}

				// Finite pulse (CSS caps it at 3 repetitions); remove afterwards so
				// it can be retriggered and doesn't linger as state.
				wrap.classList.add('ovebotai-pulse');
				setTimeout(function () { wrap.classList.remove('ovebotai-pulse'); }, 3000);
			}
		}

		// ── Unsaved changes guard ────────────────────────────────────────────
		// No change-tracking state to keep in sync — just diff the form's
		// current serialization against its initial one at the moment the
		// browser actually asks, so an edit that's later undone (typed then
		// untyped) doesn't leave a stale "dirty" flag behind.

		var $oveForm  = $('#oveSettingsForm');
		var $oveSaveBtn = $('#oveSaveBtn');
		var initialSerialized = $oveForm.serialize();

		$(window).on('beforeunload', function (e) {
			if ($oveForm.serialize() !== initialSerialized) {
				e.preventDefault();
				e.returnValue = '';
				return '';
			}
		});

		// Pulse the Save button while there are unsaved changes, so it's
		// obvious there's something to do before leaving the page.
		function updateSaveAttention() {
			$oveSaveBtn.toggleClass('ovebotai-attn', $oveForm.serialize() !== initialSerialized);
		}

		$oveForm.on('input change', 'input, select', updateSaveAttention);

		// ── Save form ────────────────────────────────────────────────────────

		$oveForm.on('submit', function (e) {
			e.preventDefault();
			var $btn = $oveSaveBtn.prop('disabled', true).removeClass('ovebotai-attn').text(cfg.i18n.saving);
			var data = $(this).serialize() + '&action=ovebotai_save_settings&nonce=' + encodeURIComponent(cfg.nonce);

			$.post(cfg.ajaxUrl, data)
				.done(function (resp) {
					var msg = resp.data && resp.data.message ? resp.data.message : (resp.success ? cfg.i18n.saved : cfg.i18n.error);
					var warnings = resp.data && resp.data.warnings;
					// needs_reconnect has no warnings list of its own — it's a single
					// caveat on the save itself, so it still takes over the main notice.
					var needsReconnect = resp.success && resp.data && resp.data.partial && !(warnings && warnings.length);
					showNotice( ! needsReconnect && resp.success, msg, needsReconnect ? 'warning' : null );
					showWarnings( resp.success ? warnings : null );

					if (resp.success) {
						// Saved state is now the new baseline — further edits are
						// judged against it, not the page-load snapshot.
						initialSerialized = $oveForm.serialize();
					}

					if (resp.success && cfg.dashboardUrl) {
						setTimeout(function () { window.location.href = cfg.dashboardUrl; }, 1200);
					} else {
						updateSaveAttention();
						$btn.prop('disabled', false).text(ovebotaiSettingsText);
					}
				})
				.fail(function () {
					updateSaveAttention();
					showNotice(false, cfg.i18n.error);
					showWarnings(null);
					$btn.prop('disabled', false).text(ovebotaiSettingsText);
				});
		});

		var ovebotaiSettingsText = $('#oveSaveBtn').text();

		// ── Toggle chat status label ─────────────────────────────────────────

		$('#oveChatStatus').on('change', function () {
			$('#oveChatStatusLbl').text($(this).is(':checked') ? cfg.i18n.enabled : cfg.i18n.disabled);
		});

		// ── Toggle order-API status label (task 5) ───────────────────────────

		$('#oveOrderApiStatus').on('change', function () {
			$('#oveOrderApiStatusLbl').text($(this).is(':checked') ? cfg.i18n.enabled : cfg.i18n.disabled);
		});

		// ── Toggle product-recommendation status label ───────────────────────

		$('#oveProductsEnabled').on('change', function () {
			$('#oveProductsEnabledLbl').text($(this).is(':checked') ? cfg.i18n.enabled : cfg.i18n.disabled);
		});

		// ── Toggle built-in product-feed status label + Feed URL visibility ──

		var $oveFeedUrlField = $('#oveFeedUrlField');
		if (!$('#oveProductsSourceBuiltin').is(':checked')) {
			$oveFeedUrlField.hide();
		}

		$('#oveProductsSourceBuiltin').on('change', function () {
			var checked = $(this).is(':checked');
			$('#oveProductsSourceBuiltinLbl').text(checked ? cfg.i18n.enabled : cfg.i18n.disabled);
			if (checked) {
				$oveFeedUrlField.fadeIn(200);
			} else {
				$oveFeedUrlField.fadeOut(200);
			}
		});

		// ── Appearance panel toggle ──────────────────────────────────────────

		$('#oveAppearanceToggle').on('click', function () {
			var $p = $('#oveAppearancePanel');
			var open = $p.is(':visible');
			$p.slideToggle(180);
			$('#oveAppearanceToggleIcon').toggleClass('dashicons-arrow-down-alt2', open).toggleClass('dashicons-arrow-up-alt2', !open);
		});

		// Sync color picker ↔ text input.
		$('#ove_color_picker').on('input', function () {
			$('[name="widget_accent_color"]').val($(this).val());
			updateSaveAttention();
		});
		$('[name="widget_accent_color"]').on('input', function () {
			var v = $(this).val();
			if (/^#[0-9a-f]{6}$/i.test(v)) {
				$('#ove_color_picker').val(v);
			}
		});

		// ── Copy buttons ─────────────────────────────────────────────────────

		$(document).on('click', '.ovebotai-copy-btn', function () {
			var targetId = $(this).data('target');
			var val      = $('#' + targetId).val();
			navigator.clipboard.writeText(val).then(function () {
				// brief label swap
			}).catch(function () {
				var el = document.getElementById(targetId);
				el.select();
				document.execCommand('copy');
			});
			var $btn  = $(this);
			var orig  = $btn.text();
			$btn.text(cfg.i18n.copied);
			setTimeout(function () { $btn.text(orig); }, 1800);
		});

		// ── Regenerate feed hash ─────────────────────────────────────────────

		$('.ovebotai-regen-hash-btn').on('click', function () {
			if (!confirm(cfg.i18n.confirmRegenHash)) return;
			var $btn = $(this).prop('disabled', true);
			$.post(cfg.ajaxUrl, { action: 'ovebotai_regen_hash', nonce: cfg.nonce })
				.done(function (resp) {
					if (resp.success) {
						$('#oveFeedUrl').val(resp.data.url);
						showNotice(true, resp.data.message || cfg.i18n.saved);
					} else {
						showNotice(false, (resp.data && resp.data.message) || cfg.i18n.error);
					}
				})
				.always(function () { $btn.prop('disabled', false); });
		});

		// ── Regenerate API credentials ───────────────────────────────────────

		$('.ovebotai-regen-creds-btn').on('click', function () {
			if (!confirm(cfg.i18n.confirmRegen)) return;
			var $btn = $(this).prop('disabled', true);
			$.post(cfg.ajaxUrl, { action: 'ovebotai_regen_creds', nonce: cfg.nonce })
				.done(function (resp) {
					if (resp.success) {
						$('#oveApiUser').val(resp.data.user);
						$('#oveApiPass').val(resp.data.pass);
						showNotice(true, resp.data.message || cfg.i18n.saved);
					} else {
						showNotice(false, (resp.data && resp.data.message) || cfg.i18n.error);
					}
				})
				.always(function () { $btn.prop('disabled', false); });
		});

		// ── Notice helper ────────────────────────────────────────────────────

		function showNotice(ok, msg, type) {
			var cls = type === 'warning' ? 'ovebotai-notice-warning' : (ok ? 'ovebotai-notice-success' : 'ovebotai-notice-error');
			var $n  = $('#oveSettingsNotice');
			$n.removeClass('ovebotai-notice-success ovebotai-notice-error ovebotai-notice-warning')
				.addClass(cls)
				.html('<p>' + msg + '</p>')
				.slideDown(180);
			$('html, body').animate({ scrollTop: Math.max(0, $n.offset().top - 40) }, 300);
			clearTimeout($n.data('timer'));
			var delay = type === 'warning' ? 7000 : 4000;
			$n.data('timer', setTimeout(function () { $n.slideUp(180); }, delay));
		}

		function showWarnings(warnings) {
			var $w = $('#oveSettingsWarnings');
			clearTimeout($w.data('timer'));
			if (warnings && warnings.length) {
				$w.html('<p>' + warnings.join('<br>') + '</p>').slideDown(180);
				$w.data('timer', setTimeout(function () { $w.slideUp(180); }, 9000));
			} else {
				$w.slideUp(180);
			}
		}
	});

}(jQuery));
