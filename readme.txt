=== WC Live Shipping Rates ===
Contributors:      yourname
Tags:              woocommerce, shipping, canada post, ups, purolator, live rates
Requires at least: 6.0
Tested up to:      6.7
Requires PHP:      7.4
Stable tag:        1.1.0
License:           GPL-2.0+
License URI:       https://www.gnu.org/licenses/gpl-2.0.html

Fetch live shipping rates from Canada Post, UPS, and Purolator at WooCommerce checkout.

== Description ==

**WC Live Shipping Rates** is a WooCommerce shipping plugin that retrieves real-time rates
from three major Canadian carriers and presents them as selectable options at checkout:

* **Canada Post** — via the REST Rating API (v4)
* **UPS** — via the UPS Rating API (OAuth 2.0)
* **Purolator** — via the E-Ship Web Services SOAP API (v2)

Each carrier is a separate WooCommerce Shipping Method that can be added to any shipping zone.
All three support:

* Sandbox / test mode for development
* Per-method rate markup (percentage)
* Automatic unit conversion (weight & dimensions from your WooCommerce store settings)

== Installation ==

1. Upload the `wc-live-shipping-rates` folder to `/wp-content/plugins/`.
2. Activate the plugin in **Plugins → Installed Plugins**.
3. Go to **WooCommerce → Settings → Shipping** and click on a shipping zone (or create one).
4. Click **Add shipping method** and add one or more of:
   - *Canada Post (Live Rates)*
   - *UPS (Live Rates)*
   - *Purolator (Live Rates)*
5. Click the method name to expand its settings and enter your API credentials.

== Carrier API Credentials ==

=== Canada Post ===
1. Register at https://www.canadapost-postescanada.ca/information/app/drc/registered
2. Create an application to get an **API Username**, **API Password**, and note your **Customer Number**.
3. Start in Sandbox mode; switch to Production when ready.

=== UPS ===
1. Register at https://developer.ups.com/
2. Create an app to obtain a **Client ID** and **Client Secret** (OAuth 2.0).
3. Your **Shipper Account Number** is the 6-character alphanumeric on your UPS invoice.
4. Start in CIE (test) mode; switch to Production when ready.

=== Purolator ===
1. Register at https://eship.purolator.com/ → Developer Tools → Register
2. Create an application to get an **API Key** and **API Password**.
3. Use your Purolator **Account Number** as the billing account.
4. Start in Sandbox mode; switch to Production when ready.

== Product Data Requirements ==

For accurate rates, ensure each product has:
* **Weight** — set in WooCommerce product data → Shipping tab
* **Dimensions** (Length × Width × Height) — same tab

If weight is missing, 0.5 kg is assumed. If dimensions are missing, a default box size is used.

== Frequently Asked Questions ==

= Rates are not showing at checkout =
* Enable **WP_DEBUG** in `wp-config.php` and check your PHP error log for `[WCLSR]` messages.
* Confirm you are in the correct shipping zone (destination matches the zone).
* Verify the destination postal code is filled in at checkout — rates require a postal code.

= Can I add a handling fee? =
Yes — each method has a **Rate Markup (%)** field. For example, enter `10` to add 10% on top of every carrier rate.

= Does this support cross-border (US/Canada) shipments? =
Canada Post and Purolator methods currently only query domestic Canadian rates.
UPS uses a "Shop" (all-services) request and works for both domestic and international routes.

= Does this support free shipping or flat-rate fallbacks? =
This plugin only adds live carrier rates. Use WooCommerce's built-in Free Shipping and Flat Rate methods alongside it.

== Changelog ==

= 1.0.0 =
* Initial release — Canada Post REST, UPS OAuth 2.0, Purolator SOAP v2.
