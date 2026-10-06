/* Ovebot.ai - "Add to cart" from chat + cart sync (WooCommerce).
 *
 * Enqueued by Ovebotai_Frontend::inject_widget() only when the plugin's "Add to
 * cart button" setting is on. The chat options pushed right before this script
 * point the widget at window.ovebotaiAddToCart and carry the cart as it was when
 * the page rendered (cart_count / cart_items). window.ovebotaiCart (inline, before
 * this script) holds the plugin's cart endpoint, WooCommerce's add-to-cart
 * endpoint and whether this is a checkout page.
 */
(function (window) {
	'use strict';

	var cfg      = window.ovebotaiCart || {};
	var CART_URL = cfg.cart_url || '/?wc-ajax=ovebotai_cart';
	var ADD_URL  = cfg.add_url || '/?wc-ajax=add_to_cart';

	// Checkout pages already show the cart, so after a successful add from chat
	// the page is reloaded. A checkout page is one WooCommerce flagged as such
	// (is_checkout(), passed in as cfg.checkout) or whose URL contains "checkout".
	function onCheckoutPage() {
		if (String(cfg.checkout) === '1') {
			return true;
		}

		return /checkout/i.test(window.location.href);
	}

	function reloadIfCheckout(result) {
		if (result === true && onCheckoutPage()) {
			window.location.reload();
		}
	}

	function queue() {
		window.ovebot_ai = window.ovebot_ai || [];
		return window.ovebot_ai;
	}

	function parseJson(text) {
		try {
			return JSON.parse(text) || {};
		} catch (e) {
			return {};
		}
	}

	// Same-origin form POST without depending on the theme's jQuery. done(null) on HTTP errors.
	function post(url, body, done) {
		var xhr = new XMLHttpRequest();

		xhr.open('POST', url, true);
		xhr.setRequestHeader('Content-Type', 'application/x-www-form-urlencoded; charset=UTF-8');
		xhr.setRequestHeader('X-Requested-With', 'XMLHttpRequest');
		xhr.onreadystatechange = function () {
			if (xhr.readyState === 4) {
				done(xhr.status >= 200 && xhr.status < 300 ? parseJson(xhr.responseText) : null);
			}
		};
		xhr.send(body || '');
	}

	// ── Cart sync ────────────────────────────────────────────────────────────

	// The cart the widget already knows about: the one rendered with the page
	// (the 'chat' options pushed just before this script), then every one we send.
	var state = (function () {
		var q = queue();

		for (var i = (q.length || 0) - 1; i >= 0; i--) {
			if (q[i] && q[i][0] === 'chat' && q[i][1]) {
				return JSON.stringify({ count: q[i][1].cart_count || 0, items: q[i][1].cart_items || [] });
			}
		}

		return '';
	})();

	var syncTimer = null;

	function syncCart() {
		post(CART_URL, '', function (json) {
			if (!json || typeof json.count === 'undefined') {
				return;
			}

			var next = JSON.stringify(json);

			if (next === state) {
				return;
			}

			state = next;
			queue().push(['cart', json]);
		});
	}

	// Several cart requests usually fire together (add + fragments refresh).
	function scheduleSync() {
		clearTimeout(syncTimer);
		syncTimer = setTimeout(syncCart, 300);
	}

	var $ = window.jQuery;

	if ($) {
		// WooCommerce's own cart events: add-to-cart.js, cart-fragments.js
		// (mini-cart), cart.js (cart page) and checkout.js (order review).
		$(document.body).on(
			'added_to_cart removed_from_cart wc_fragments_refreshed wc_fragments_loaded ' +
			'updated_wc_div updated_cart_totals wc_cart_emptied item_removed_from_classic_cart updated_checkout',
			scheduleSync
		);

		// Catch-all for themes/plugins with their own cart AJAX (quantity
		// steppers, side carts): any completed request whose URL mentions the
		// cart - except our own endpoint.
		$(document).ajaxComplete(function (event, xhr, settings) {
			if (!settings || !settings.url || !/cart/i.test(settings.url) || settings.url.indexOf('ovebotai_cart') !== -1) {
				return;
			}

			scheduleSync();
		});
	}

	// WooCommerce Blocks (mini-cart / cart / checkout blocks) go through the
	// Store API with fetch, not jQuery; they announce cart changes with these
	// native events instead.
	['wc-blocks_added_to_cart', 'wc-blocks_removed_from_cart'].forEach(function (name) {
		document.body.addEventListener(name, scheduleSync);
	});

	// ── Add to cart ──────────────────────────────────────────────────────────

	// What the widget expects back: true, false, or { redirect: "..." }.
	// WooCommerce answers { error: true, product_url } when the product can't be
	// added as-is (e.g. a variable product without a chosen variation) and
	// expects the shopper to be sent to the product page.
	function outcome(json) {
		if (json && json.error) {
			return json.product_url ? { redirect: json.product_url } : false;
		}

		if (json && (json.fragments || json.cart_hash)) {
			return true;
		}

		return false;
	}

	// product = { ref, sku, quantity, name, url, price, currency }
	// ref is the WooCommerce product/variation id - the same 'ref' the product
	// feed publishes - and WooCommerce's add_to_cart endpoint accepts it
	// directly as product_id (a variation id adds that exact combination).
	window.ovebotaiAddToCart = function (product) {
		return new Promise(function (resolve) {
			product = product || {};

			var ref      = String(product.ref);
			var quantity = parseInt(product.quantity, 10) || 1;
			var params   = window.wc_add_to_cart_params;

			var settled = false;

			var finish = function (value) {
				if (settled) {
					return;
				}

				settled = true;
				scheduleSync();
				resolve(value);
				reloadIfCheckout(value);
			};

			// Preferred: WooCommerce's own AJAX add-to-cart protocol (what
			// add-to-cart.js does for a click on an "Add to cart" button), so the
			// theme's notifications, the mini-cart fragments refresh and any
			// tracking listening on adding_to_cart / added_to_cart run exactly as
			// for a normal click. The request itself is sent from here rather than
			// by triggering a click, because add-to-cart.js navigates away on an
			// error response where the widget expects { redirect } instead.
			if ($ && params && params.wc_ajax_url) {
				var $button = $('<a class="add_to_cart_button ajax_add_to_cart ovebotai-add-to-cart" />')
					.attr('href', product.url || '#')
					.attr('data-product_id', ref)
					.attr('data-quantity', quantity);

				var data = { product_id: ref, quantity: quantity };

				// Same early-exit hook WooCommerce offers third parties.
				if (false === $(document.body).triggerHandler('should_send_ajax_request.adding_to_cart', [$button])) {
					$(document.body).trigger('ajax_request_not_sent.adding_to_cart', [false, false, $button]);
					finish(false);
					return;
				}

				$(document.body).trigger('adding_to_cart', [$button, data]);

				$.ajax({
					type:     'POST',
					url:      String(params.wc_ajax_url).replace('%%endpoint%%', 'add_to_cart'),
					data:     data,
					dataType: 'json',
					timeout:  20000,
					success: function (response) {
						var value = outcome(response);

						if (value === true) {
							$(document.body).trigger('added_to_cart', [response.fragments, response.cart_hash, $button]);

							// Store set to "redirect to the cart page after
							// successful addition": send the shopper there.
							if (params.cart_redirect_after_add === 'yes' && params.cart_url) {
								value = { redirect: params.cart_url };
							}
						}

						finish(value);
					},
					error: function () {
						finish(false);
					}
				});

				return;
			}

			// Fallback for pages without jQuery/WooCommerce's add-to-cart script:
			// post to WooCommerce directly.
			post(ADD_URL, 'product_id=' + encodeURIComponent(ref) + '&quantity=' + quantity, function (json) {
				var value = outcome(json);

				if (value === true && $) {
					$(document.body).trigger('wc_fragment_refresh');
				}

				finish(value);
			});
		});
	};

}(window));
