=== Ovebot.ai ===
Contributors: ovesio
Tags: chatbot, ai chat, woocommerce, product feed, order tracking
Requires at least: 5.9
Tested up to: 7.0
Requires PHP: 7.4
Stable tag: 1.0.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

AI chat widget, product feed and order tracking integration for Ovebot.ai.

== Description ==

Ovebot.ai connects your WordPress site to the Ovebot.ai AI chat assistant:

* Adds an on-site chat widget, configurable from Settings (theme, language, position, proactive message, and more).
* Publishes a WooCommerce product feed (with real-time stock/availability) for the assistant's product knowledge.
* Exposes an authenticated order-tracking endpoint so the assistant can answer "where is my order?" questions.
* Syncs selected pages into the assistant's knowledge base.
* Connects your store to your Ovebot.ai account via OAuth — no API keys to copy/paste by hand.

WooCommerce is required for the product feed and order tracking features; the chat widget and knowledge base sync work without it.

== Installation ==

1. Upload the plugin to `/wp-content/plugins/` or install it through the WordPress plugins screen.
2. Activate the plugin.
3. Go to Settings → Ovebot.ai and follow the setup wizard to connect your Ovebot.ai account.

== Frequently Asked Questions ==

= Does this work without WooCommerce? =

Yes — the chat widget and knowledge base sync work on any WordPress site. The product feed and order tracking endpoint require WooCommerce.

= Where do I manage the widget's appearance? =

Settings → Ovebot.ai → Settings → Appearance.

== Changelog ==

= 1.0.0 =
* Initial release.
