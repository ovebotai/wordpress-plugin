/* global ovebotaiSetup, jQuery */
(function ($) {
	'use strict';

	var cfg        = ovebotaiSetup;
	// e.g. [1,2,3,4] with WooCommerce, [1,2,4] (no Products KB step) without it.
	var stepsSeq   = cfg.stepsSequence || [1, 2, 3, 4];
	var current    = parseInt(cfg.initialStep, 10) || stepsSeq[0];
	var navigating = false;
	var inFlight   = false;

	// Appended in JS (not baked into the translated strings) so the arrow can be
	// handled per-locale. A real Unicode character, not "&rarr;": jQuery .text()
	// HTML-escapes its input, so an entity would render literally (item 1).
	var ARROW = ' →';

	// The step whose "Next" triggers the finish push + advances to the Go-live
	// panel — second-to-last in the sequence (last is always the Finish panel,
	// step 4). Note KB syncing now happens on step 2's own "Next" (item 10).
	var finishStep = stepsSeq[stepsSeq.length - 2];

	function posOf(step) {
		var i = stepsSeq.indexOf(step);
		return i === -1 ? 0 : i;
	}

	// ── Init ────────────────────────────────────────────────────────────────

	$(function () {
		renderStep(current);

		$('#oveNextBtn').on('click', handleNext);
		$('#ovePrevBtn').on('click', handlePrev);

		$('input[name="products_source"]').on('change', updateProductMsgOpacity);
		updateProductMsgOpacity();

		if (cfg.oauthError) {
			showOauthError(cfg.oauthError);
		}
	});

	// ── Step navigation ──────────────────────────────────────────────────────

	function renderStep(step) {
		current = step;
		var pos = posOf(step);

		// Panels.
		$('.ovebotai-panel').hide();
		$('.ovebotai-panel[data-panel="' + step + '"]').show();

		// Dots.
		$('.ovebotai-step-dot').each(function () {
			var n = parseInt($(this).data('step'), 10);
			$(this).toggleClass('is-active', n === step);
			$(this).toggleClass('is-done', posOf(n) < pos);
		});

		// Progress bar.
		var pct = stepsSeq.length > 1 ? (pos / (stepsSeq.length - 1)) * 100 : 100;
		$('#oveProgressBar').css('width', pct + '%');

		// Nav buttons.
		var isLast = pos === stepsSeq.length - 1;

		$('#oveSetupNav').toggle(!isLast);
		$('#ovePrevBtn').toggle(pos > 0);
		$('#oveNextBtn').toggle(true);

		// Step 1: hide Next if not yet connected.
		if (step === 1) {
			$('#oveNextBtn').toggle(parseInt(cfg.isConnected, 10) === 1);
		}

		// Last content step before Finish: change button label to "Finish setup".
		if (step === finishStep) {
			$('#oveNextBtn').text(cfg.i18n.sync + ARROW);
			if (step === 3) updateProductMessage();
		} else {
			$('#oveNextBtn').text(cfg.i18n.next + ARROW);
		}
	}

	function handleNext() {
		if (navigating || inFlight) return;
		navigating = true;
		setTimeout(function () { navigating = false; }, 500);

		if (current === 2) {
			// The "Website pages" step syncs the checked pages first and only
			// advances if that came back clean (item 10).
			syncKb();
		} else if (current === 4) {
			// Step 4 with Next visible only happens after a failed finish — retry.
			doFinish();
		} else if (current === finishStep) {
			renderStep(4);
			doFinish();
		} else {
			renderStep(stepsSeq[posOf(current) + 1]);
		}
	}

	function handlePrev() {
		if (navigating || inFlight) return;
		navigating = true;
		setTimeout(function () { navigating = false; }, 500);
		renderStep(stepsSeq[posOf(current) - 1]);
	}

	// ── Product count message ─────────────────────────────────────────────────

	function updateProductMessage() {
		var counts = cfg.productCounts;
		if (!counts) return;

		if (counts.total === 0) {
			$('#oveProductMsg').html(cfg.i18n.noProducts);
			return;
		}

		$('#oveProductMsg').html('<span class="ovebotai-count-badge">' + counts.feed_count + '</span> ' + cfg.i18n.productsWillBeIndexed);
	}

	function updateProductMsgOpacity() {
		var source = $('input[name="products_source"]:checked').val() || 'auto';
		$('#oveProductMsg').css('opacity', 'own' === source ? 0.5 : 1);
	}

	// ── Knowledge-base sync (step 2 "Next") ───────────────────────────────────

	function syncKb() {
		if (inFlight) return;
		inFlight = true;

		// The Next button is left clickable throughout (item 10); a re-click while
		// a sync is in flight is simply ignored by the inFlight guard.

		// Reset prior inline state.
		$('#oveKbNotice').hide().text('');
		$('.ovebotai-page-msg').text('').removeClass('is-ok is-error');

		var pageIds = [];
		$('input[name="kb_pages[]"]:checked').each(function () {
			pageIds.push($(this).val());
		});

		$.post(cfg.ajaxUrl, {
			action:   'ovebotai_sync_kb',
			nonce:    cfg.nonce,
			page_ids: pageIds
		})
			.done(function (resp) {
				var data = (resp && resp.data) || {};
				applyKbResults(data);

				// Clear the guard BEFORE advancing — the no-Products path calls
				// doFinish() below, which has its own inFlight guard and would
				// otherwise no-op while this one is still held.
				inFlight = false;

				if (data.clean) {
					// Fully clean — advance. If step 2 is itself the finish step
					// (no Products step, i.e. WooCommerce inactive), go straight to
					// the finish push.
					if (current === finishStep) {
						renderStep(4);
						doFinish();
					} else {
						renderStep(stepsSeq[posOf(current) + 1]);
					}
				}
				// Not clean: stay put. Failed items are already unchecked, so
				// clicking Next again simply retries with the reduced selection.
			})
			.fail(function () {
				inFlight = false;
				$('#oveKbNotice').text(cfg.i18n.error).show();
			});
	}

	// Paints per-page results inline and auto-unchecks anything that didn't go
	// active, so a repeat "Next" simply retries with the reduced selection.
	function applyKbResults(data) {
		$('.ovebotai-page-msg').text('').removeClass('is-ok is-error');

		// Positive confirmation is only shown when the attempt wasn't fully clean
		// — on a clean attempt we just advance, no per-item chrome needed.
		if (!data.clean && data.ok) {
			$.each(data.ok, function (i, id) {
				setPageMsg(id, cfg.i18n.updated, 'is-ok');
			});
		}

		if (data.failed) {
			$.each(data.failed, function (id, msg) {
				setPageMsg(id, msg, 'is-error');
				uncheckPage(id);
			});
		}

		if (data.quota_blocked) {
			$.each(data.quota_blocked, function (id, msg) {
				setPageMsg(id, msg, 'is-error');
				uncheckPage(id);
			});
		}

		// Summary notice: the API's own quota message, verbatim (item 9).
		if (data.quota_message) {
			$('#oveKbNotice').text(data.quota_message).show();
		}
	}

	function setPageMsg(id, msg, cls) {
		$('.ovebotai-page-item[data-page-id="' + id + '"] .ovebotai-page-msg')
			.removeClass('is-ok is-error')
			.addClass(cls)
			.text(msg);
	}

	function uncheckPage(id) {
		$('.ovebotai-page-item[data-page-id="' + id + '"] input[name="kb_pages[]"]').prop('checked', false);
	}

	// ── Finish (final step): push remaining config, mark complete ─────────────

	function doFinish() {
		if (inFlight) return;
		inFlight = true;

		$('#oveSetupNav').hide();
		$('#oveSyncIdle').hide();
		$('#oveSyncLoading').show();
		$('#oveSyncError').hide();

		var source = $('input[name="products_source"]:checked').val() || 'auto';

		$.post(cfg.ajaxUrl, {
			action:          'ovebotai_sync',
			nonce:           cfg.nonce,
			products_source: source
		})
			.done(function (resp) {
				$('#oveSyncLoading').hide();
				if (resp.success) {
					$('#oveSyncDone').show();
					// All done — every dot (including the last one) turns green.
					$('.ovebotai-step-dot').removeClass('is-active').addClass('is-done');
				} else {
					showSyncError(resp.data && resp.data.message ? resp.data.message : cfg.i18n.error);
				}
			})
			.fail(function () {
				$('#oveSyncLoading').hide();
				showSyncError(cfg.i18n.error);
			})
			.always(function () {
				inFlight = false;
			});
	}

	function showSyncError(msg) {
		$('#oveSyncErrorMsg').html('<p>' + msg + '</p>');
		$('#oveSyncError').show();
		$('#oveNextBtn').text(cfg.i18n.retry).show();
		$('#ovePrevBtn').show();
		$('#oveSetupNav').show();
	}

	function showOauthError(msg) {
		var $notice = $('.ovebotai-notice-error').first();
		if (!$notice.length) {
			$notice = $('<div class="ovebotai-notice ovebotai-notice-error"><p></p></div>');
			$('.ovebotai-connect-box').before($notice);
		}
		$notice.find('p').text(msg).end().show();
	}

}(jQuery));
