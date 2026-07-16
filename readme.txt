=== Ovebot.ai ===
Contributors: ovesio
Tags: chatbot, ai chat, woocommerce, product feed, order tracking
Requires at least: 5.9
Tested up to: 7.0
Requires PHP: 7.4
Stable tag: 1.0.0
License: MIT
License URI: https://opensource.org/licenses/MIT

AI chat widget, product feed and order tracking integration for Ovebot.ai — start with a free trial in minutes.

== Description ==

Ovebot.ai connects your WordPress site to the Ovebot.ai AI chat assistant. New to Ovebot.ai? You can create a free trial account directly from the setup wizard — no need to sign up on the website first.

The assistant, powered by your store's own data, can:

* Answer customer support questions using the knowledge base you set up from your site's pages.
* Recommend products to customers based on your live WooCommerce catalog.
* Help customers check their order's delivery status, right from the chat.

Behind the scenes, the plugin:

* Adds an on-site chat widget, configurable from Settings (theme, language, position, proactive message, and more).
* Publishes a WooCommerce product feed (with real-time stock/availability) so the assistant always recommends what's actually in stock.
* Exposes an authenticated order-tracking endpoint so the assistant can answer "where is my order?" questions with real carrier/AWB data.
* Syncs selected pages into the assistant's knowledge base for accurate support answers.
* Connects your store to your Ovebot.ai account via OAuth — no API keys to copy/paste by hand.

WooCommerce is required for the product feed and order tracking features; the chat widget and knowledge base sync work without it.

== Installation ==

1. Upload the plugin to `/wp-content/plugins/` or install it through the WordPress plugins screen.
2. Activate the plugin.
3. Go to Settings → Ovebot.ai and follow the setup wizard: connect an existing Ovebot.ai account, or start a free trial if you're new.

== Frequently Asked Questions ==

= Does this work without WooCommerce? =

Yes — the chat widget and knowledge base sync work on any WordPress site. The product feed and order tracking endpoint require WooCommerce.

= Where do I manage the widget's appearance? =

Settings → Ovebot.ai → Settings → Appearance.

= Which shipping/courier plugins does order tracking support? =

The order-tracking endpoint reads the AWB/tracking number directly from whichever of these shipping plugins you already use to generate it - no extra setup needed, it just works if one of them is active and has generated a label for the order:

* [FedEx Rates & Labels](https://myshipi.com/)
* [Colissimo shipping methods for WooCommerce](https://www.colissimo.entreprise.laposte.fr/fr)
* [GLS Shipping for WooCommerce](https://inchoo.hr)
* [Packeta](https://www.zasilkovna.cz/)
* [SamedayCourier Shipping](https://www.sameday.ro/contact)
* [SEUR Oficial](http://www.seur.com/)
* [WCMultiShipping - Mondial Relay, Inpost & Chronopost for WooCommerce](https://www.wcmultishipping.com/fr/mondial-relay-woocommerce/) (UPS, Chronopost and Mondial Relay)
* [DPD Baltic Shipping](https://dpd.com)
* [HgE: Shipping Zones for FAN Courier Romania](https://www.linkedin.com/in/hurubarugeorgesemanuel/)

Don't see your courier listed? Let us know at https://ovebot.ai/contact and we'll look into adding support.

== Changelog ==

= 1.0.0 =
* Initial release.
