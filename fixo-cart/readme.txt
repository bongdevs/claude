=== Fixo Cart — Abandoned Cart Recovery, Telegram & WhatsApp Alerts for WooCommerce ===
Contributors: bongdevs
Donate link: https://bongdevs.com
Tags: woocommerce, abandoned cart, cart recovery, telegram, whatsapp
Requires at least: 5.8
Tested up to: 6.6
Requires PHP: 7.4
WC requires at least: 5.0
WC tested up to: 9.5
Stable tag: 3.0.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Recover abandoned WooCommerce carts and get Telegram and WhatsApp alerts for new checkout leads, orders and high-value carts — all from one modern dashboard.

== Description ==

**Fixo Cart** by [BongDevs](https://bongdevs.com) captures every checkout the moment a shopper starts typing — name, phone, email, full address, cart contents and the traffic source — so you can win the sale back by phone, WhatsApp, email or a one-click manual order. It also sends instant **Telegram** and **WhatsApp** notifications, so you hear about new leads and orders wherever you are.

Fixo Cart combines and replaces two plugins: *BongDevs Cart Recovery for WooCommerce* and *Woo Telegram Orders*. Existing carts, settings and your Telegram bot are carried over automatically.

= Cart recovery =

* Live checkout capture: name, email, phone, full billing & shipping address and cart contents are saved as the customer types — even if they never click "Place order". Works with the classic and the block checkout.
* Traffic source on every cart: landing page, referrer, all UTM parameters and ad click IDs (fbclid, gclid, ttclid).
* One-click recovery: send a recovery email, open a pre-filled WhatsApp message (with an optional coupon), call, or turn the cart into a real WooCommerce order — attributed to the original ad.
* Automatic 3-step recovery email sequence with smart, single-use coupons.
* Smart rules: skip bots, duplicates, low-value carts and excluded sources.
* Live customers monitor, full customer journey timeline and internal notes.
* **Recovered orders** screen listing every order won back — by your team or via a recovery link — with who recovered it and the revenue.

= Telegram & WhatsApp notifications =

* **New checkout lead alerts** — get a message as soon as a shopper enters a phone number and/or email on checkout. Choose the rule: phone, email, either one, or both. Optional delay that skips the alert if the customer completes the order.
* **Order alerts** — new orders (classic, block checkout or created in wp-admin) plus processing, completed, cancelled/failed and refunded.
* **Cart alerts** — abandoned carts, high-value abandoned carts and carts recovered by your team.
* For each channel choose what it receives: **Cart**, **Order** or **Both** — then fine-tune individual events.
* Telegram via your own bot (several chats or groups supported).
* WhatsApp via CallMeBot (free, for your own numbers), the official WhatsApp Cloud API (with template support) or any HTTP SMS/WhatsApp gateway.
* Messages are sent in the background so checkout is never slowed down; a delivery log shows what was sent and why anything failed. Test buttons for every channel.

= Orders dashboard =

* Sales, paid orders, average order value and today's orders, with comparison to the previous period and a daily sales chart.
* Searchable order list with status filters and an order detail panel.

= Analytics & tracking =

* Overview, product, UTM campaign, revenue and customer segment reports.
* Server-side Purchase events for recovered orders: Meta Conversions API, GA4 Measurement Protocol and a signed webhook (Zapier, Make, n8n, Google Sheets…).
* CSV export, dashboard alerts, light and dark mode.

= Privacy =

* Optional consent checkbox before any data is captured, automatic data retention cleanup and opt-in data removal on uninstall.
* Data is stored in two custom tables, not post meta, so large stores stay fast. HPOS compatible.

== Installation ==

1. Upload the `fixo-cart` folder to `/wp-content/plugins/` (or upload the zip in Plugins → Add New → Upload Plugin).
2. Activate **Fixo Cart**. WooCommerce must be active.
3. If "BongDevs Cart Recovery for WooCommerce" is installed it is deactivated automatically — all its data is reused. You can then delete it.
4. If "Woo Telegram Orders" is installed, its bot token and chat ID are imported; deactivate it from the notice.
5. Open **Fixo Cart → Notifications** to connect Telegram and/or WhatsApp and press "Save & send test".

== Frequently Asked Questions ==

= How do I get a Telegram bot token and chat ID? =
Open @BotFather in Telegram, send /newbot and copy the token. Send any message to your new bot (or add it to a group), then get the chat ID from @userinfobot — group IDs start with -100.

= Which WhatsApp option should I use? =
CallMeBot is free and the quickest way to get alerts on your own phone. The WhatsApp Cloud API is the official Meta option; outside the 24-hour customer-service window it only delivers approved templates, so create a Utility template with one body variable. Any other provider with an HTTP API works through the Custom gateway option.

= When exactly is a checkout lead sent? =
Once per cart, when the cart matches your rule (phone, email, either or both) and the phone has at least the configured number of digits. With a delay, the alert is skipped if the shopper completes the order meanwhile.

= Does it work with the block checkout? =
Yes — capture, order alerts and conversion marking support both the classic and the block (Store API) checkout.

== Changelog ==

= 3.0.0 =
* New name: Fixo Cart by BongDevs (bongdevs.com), author Jahid Hasan. Replaces "BongDevs Cart Recovery for WooCommerce" and "Woo Telegram Orders" — data and settings are kept.
* Added: Telegram and WhatsApp notifications (CallMeBot, WhatsApp Cloud API or custom gateway) for checkout leads, new orders, order status changes, abandoned, high-value and recovered carts.
* Added: per-channel "Send notifications for" setting — Cart, Order or Both — plus a per-event matrix.
* Added: checkout lead rule — notify when a phone, an email, either or both are entered, with an optional delay.
* Added: background delivery, delivery log and "Save & send test" buttons.
* Added: Orders dashboard (sales KPIs, daily chart, order list and detail) merged from Woo Telegram Orders, now HPOS-safe and without loading every order into memory.
* Added: Recovered orders screen and wp-admin submenu shortcuts.
* New: completely redesigned dashboard — grouped side navigation, KPI cards, status filters with search, cart detail side panel with tabs, light and dark mode, responsive layout and no emoji icons.


= 2.5.2 =
* Improved: addresses show the state/district name (e.g. "Chattogram") and country name ("Bangladesh") instead of codes like "BD-15" / "BD" — in the carts table, the cart detail view and the CSV export. Codes are still stored, so orders created from carts keep the correct WooCommerce state.

= 2.5.1 =
* Added: "Entry / Exit" column in the carts table — when the visitor entered the site, when they left (last activity), and total time on site. Shows "On site now" for live shoppers. Also shown in the cart detail view, and can be hidden under Settings → Table columns.

= 2.5.0 =
* Added: converting a cart into a Woo order now copies the FULL traffic source to the order — landing page, referrer, all 5 UTMs, fbclid/gclid/ttclid, device, shopper IP and user agent — and fills WooCommerce's native Order Attribution (the Orders list "Origin" column now shows the real source instead of "Unknown").
* Added: server-side Purchase events for admin-recovered orders — Meta Conversions API (hashed email/phone/name + fbp/fbc for ad matching), GA4 Measurement Protocol and a generic signed JSON webhook. Choose "on create" or "only when Processing/Completed". Test buttons in Settings.
* Added: developer hooks wccr_cart_converted_to_order and wccr_recovered_order_tracking.
* Added: fbp, fbc, _ga client id and ad click ids are captured from the shopper's browser; a newer ad click updates the stored click id.
* Added: toast notifications for every action, error and new real-time alert; styled confirm dialog replaces the browser alert/confirm popups; Escape closes dialogs.
* Improved: full mobile layout — carts table becomes cards with tap-to-call, Call button, swipeable tabs, full-screen detail sheet, bottom-sheet confirms, touch-sized buttons, responsive settings and analytics tables.
* Fixed: settings checkboxes did not appear checked after clicking until the page was reloaded.
* Improved: order screen "Cart Recovery" box shows the full source and which events were sent.

= 2.4.3 =
* Menu badge now counts only abandoned carts that have a phone number.
* Real-time alerts moved from a full-width panel into a bell dropdown in the header.

= 2.4.2 =
* Fixed: cart list was EMPTY — a missing max_total filter from the REST API became "cart_total <= 0".
* Added: cron self-heal + dashboard fallback when WP-Cron does not run (carts never became abandoned).
* Added: no recovery email for carts whose first email is more than 2 days overdue.
* Fixed: activity timeline, customer journey and coupon re-use were empty because of a typo in the events query.
* Fixed: site-timezone mismatch — carts were marked abandoned hours late (or too early), the Live indicator was wrong and email timing was shifted.
* Fixed: the first recovery email could never be sent because every cron run refreshed the cart's "last activity" time.
* Fixed: cron kept re-processing the same 200 old carts, so new carts stopped receiving emails.
* Fixed: two carts of the same customer marked each other as duplicate and neither received an email.
* Fixed: returning / logged-in customers were only ever tracked once (later carts were written into the old converted row).
* Fixed: recovery link created a duplicate cart row; the final order is now attributed to the original cart.
* Fixed: Block (Store API) checkout orders are now marked converted; block checkout fields are captured too.
* Fixed: customers who already ordered (same email/phone) no longer receive recovery emails.
* Fixed: "Risk detection" column shifted the whole table; CSV export ignored filters and stopped at 100 rows.
* Fixed: HPOS order edit links, variation attributes on admin-created orders, prices shown as raw HTML in emails.
* Improved: heartbeat only runs for visitors who have a cart; CSV formula-injection protection; country code captured.

= 2.3.0 =
* Added WhatsApp recovery workflow with pre-filled recovery link messages and optional smart coupon.
* Added automated 3-step email sequence controls and recovery-safe sequencing.
* Added smart coupon rules: expiry, minimum spend, maximum discount, prefix, single-use and email restriction.



= 2.2.0 =
* Added Advanced Dashboard with expanded KPI, traffic and performance reporting.
* Added dedicated UTM Analytics grouped by source, medium and campaign with recovery revenue attribution.
* Added Revenue Analytics separating admin-recovered revenue from normal customer checkout revenue.
* Added customer segmentation based on captured cart behavior and cart value.
* Moved the Cart Recovery admin menu directly after WooCommerce in wp-admin.
* Added a live-style abandoned cart count badge to the Cart Recovery admin menu.


= 1.0.1 =
* Fixed admin REST API URL handling for WordPress REST routing and non-pretty permalinks.
* Added dashboard API error handling to prevent uncaught promise errors.
* Updated plugin branding to BongDevs Cart Recovery for WooCommerce.

= 1.0.0 =
* Initial release.

= 2.1.1 =
* Added Customer Journey timeline with cart creation, page views, add-to-cart, recovery email, admin recovery and customer checkout events.
* Added dedicated Live Customers screen with current page, device, cart value, products and live presence.
* Added advanced cart filters for value range, source/UTM, device, presence, conversion type and product ID.
* Added dedicated Product Analytics with cart count, quantity, abandoned carts, admin recoveries, customer checkouts, recovery rate and recovered revenue.
* Added lightweight page-view and add-to-cart event capture for journey reporting.
* Hardened the 2.1 database migration so an existing conversion_type index is not duplicated.

= 2.1.0 =
* Added explicit conversion attribution for admin-created recovery orders versus normal customer checkout orders.
* Fixed Recovered Revenue so it counts only orders created from an abandoned cart by an authorized WooCommerce manager/admin.
* Normal customer checkout conversions are tracked separately and no longer inflate Recovered Revenue.
* Added admin recovery and customer checkout conversion counts to analytics.
* Added migration/backfill for existing converted cart records.
* Product column now uses compact product thumbnails with hover cards showing title, variation, quantity, unit price, line total and SKU.

= 2.0.1 =
* Product column now uses compact product thumbnails with hover cards showing title, variation, quantity, unit price, line total and SKU.
