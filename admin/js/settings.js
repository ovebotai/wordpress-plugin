/* global ovebotaiSettings, jQuery */
(function ($) {
	'use strict';

	var cfg = ovebotaiSettings;

	$(function () {

		// ── Save form ────────────────────────────────────────────────────────

		$('#oveSettingsForm').on('submit', function (e) {
			e.preventDefault();
			var $btn = $('#oveSaveBtn').prop('disabled', true).text(cfg.i18n.saving);
			var data = $(this).serialize() + '&action=ovebotai_save_settings&nonce=' + encodeURIComponent(cfg.nonce);

			$.post(cfg.ajaxUrl, data)
				.done(function (resp) {
					var msg = resp.data && resp.data.message ? resp.data.message : (resp.success ? cfg.i18n.saved : cfg.i18n.error);
					// partial = saved locally but API sync skipped/failed — show as warning not error
					var ok  = resp.success && !(resp.data && resp.data.partial);
					showNotice(ok, msg, resp.success && resp.data && resp.data.partial ? 'warning' : null);
				})
				.fail(function () {
					showNotice(false, cfg.i18n.error);
				})
				.always(function () {
					$btn.prop('disabled', false).text(ovebotaiSettingsText);
				});
		});

		var ovebotaiSettingsText = $('#oveSaveBtn').text();

		// ── Toggle chat status label ─────────────────────────────────────────

		$('#oveChatStatus').on('change', function () {
			$('#oveChatStatusLbl').text($(this).is(':checked') ? 'Enabled' : 'Disabled');
		});

		// ── Appearance panel toggle ──────────────────────────────────────────

		$('#oveAppearanceToggle').on('click', function () {
			var $p = $('#oveAppearancePanel');
			var open = $p.is(':visible');
			$p.slideToggle(180);
			$(this).text(open ? 'Configure appearance ▾' : 'Configure appearance ▲');
		});

		// Sync color picker ↔ text input.
		$('#ove_color_picker').on('input', function () {
			$('[name="widget_accent_color"]').val($(this).val());
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

		// ── Clear feed cache ─────────────────────────────────────────────────

		$('.ovebotai-clear-cache-btn').on('click', function () {
			if (!confirm(cfg.i18n.confirmClearCache)) return;
			var $btn = $(this).prop('disabled', true);
			$.post(cfg.ajaxUrl, { action: 'ovebotai_clear_cache', nonce: cfg.nonce })
				.done(function (resp) {
					showNotice(resp.success, resp.data && resp.data.message ? resp.data.message : cfg.i18n.error);
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
	});

}(jQuery));
