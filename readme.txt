=== Steadysync – Inventory Sync for Square ===
Contributors: famosmedia
Tags: square, woocommerce, inventory, sync, pos
Requires at least: 6.4
Tested up to: 7.0
Requires PHP: 8.1
Stable tag: 1.0.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Sync your Square inventory and catalog into WooCommerce. An anti-zeroing guard means a sync never overwrites your stock with 0.

== Description ==

Steadysync keeps your WooCommerce store in sync with your Square catalog and inventory. It is built around one idea: a sync should never destroy data. An empty, partial or non-numeric stock update is rejected instead of being written, so your WooCommerce stock is never silently set to 0.

The plugin connects to your Square account, imports your catalog and keeps stock levels up to date in real time using Square webhooks. Before anything is written you can run a dry-run preview that shows exactly which catalog and stock changes a sync would make.

= What it does =

* **Square → WooCommerce inventory sync** for the selected Square location, with an anti-zeroing guard: empty, partial or non-numeric quantities are rejected and never applied as 0.
* **Catalog import** — product name, description, price, SKU and the item image are imported from Square. Simple and variable products are supported. Images are only re-downloaded when the Square image actually changes, so repeated syncs do not bloat your media library.
* **Real-time updates via Square webhooks** — incoming events are HMAC signature-verified and de-duplicated so the same event is never applied twice.
* **Secure Square connection** — connect a sandbox access token, or connect a production account via OAuth. Tokens are stored encrypted (AES-256-GCM) and refreshed automatically before they expire.
* **Dry-run preview** — compute the catalog and stock changes a sync would make, without writing anything.
* **Timeout-safe batch import** — large catalogs are imported one page per background run, so imports do not time out.
* **Health monitor** — an hourly connection check with an admin panel and an optional email alert on persistent errors.
* **WP-CLI** — `wp steadysync status`, `preview`, `catalog_sync`, `sync_start`, `sync_status`, `sync_cancel`.

= Extended features (separate add-on) =

Two-way sync and migration are available through **Steadysync Pro**, a separate add-on distributed at [steadysync.net](https://steadysync.net) and not hosted here: WooCommerce → Square push (inventory, catalog and images), order/refund sync as Square ledger adjustments, multi-location stock aggregation and a one-click import of an existing Square product mapping. The add-on is optional; this plugin is fully functional on its own.

= Trademarks =

Square is a trademark of Block, Inc. WooCommerce is a trademark of Automattic Inc. Steadysync is an independent, unofficial plugin and is not affiliated with, endorsed by or sponsored by Block, Inc. or Automattic Inc. These names are used only to describe compatibility.

== External services ==

This plugin connects to the **Square API** (provided by Block, Inc.). Square is required for the plugin to work: it is the source of the catalog and inventory data that the plugin imports into WooCommerce, and the connection that lets your store stay in sync with your Square account.

What the plugin sends to Square and when:

* When you connect your account (OAuth authorization and token exchange, or when you save a sandbox access token) and when your stored token is automatically refreshed: your Square application credentials and OAuth/refresh tokens are exchanged with Square.
* When you test the connection or load your locations: an authenticated request is sent to Square to list your business locations.
* On every catalog import, dry-run preview and inventory read (manually, on a schedule, or when a Square webhook is received): authenticated requests are sent to Square to read catalog objects and inventory counts. These requests contain your access token and the catalog/location identifiers being queried; no WooCommerce customer data is sent.
* Product images shown in Square are downloaded from Square-hosted image URLs during catalog import.

The plugin also **receives** webhook notifications from Square at a REST endpoint it registers (`/wp-json/steadysync/v1/square-webhook`); those requests are verified with the HMAC signature key from your Square webhook subscription.

Square's terms and privacy policy:

* Square Terms of Service: https://squareup.com/us/en/legal/general/ua
* Square Privacy Notice: https://squareup.com/us/en/legal/general/privacy

== Installation ==

1. Install and activate WooCommerce.
2. Install and activate Steadysync.
3. Go to **WooCommerce → Steadysync**. Connect your Square account — a sandbox access token for testing, or a production account via OAuth (enter your Square application credentials and add the shown redirect URL to your Square app).
4. Run **Test connection**, then pick the Square location to sync.
5. In the Square Developer dashboard, create a webhook subscription pointing at the **Webhook URL** shown on the settings page, and paste its signature key into **Webhook Signature Key**.
6. Optionally run **Compute preview** to see what a sync would change before you enable it.

== Frequently Asked Questions ==

= Will a sync ever set my stock to zero? =

No. An empty, partial or non-numeric stock update is rejected by the anti-zeroing guard, so your existing WooCommerce stock is kept instead of being overwritten with 0.

= Is real-time sync included? =

Yes. Updates are delivered by Square webhooks; incoming events are signature-verified and de-duplicated. Webhook handling is part of the free plugin.

= Do I need a Square account? =

Yes. The plugin reads your catalog and inventory from Square via the Square API, so a Square account (sandbox for testing, or a production account) is required. See the "External services" section above.

= Does it sync from WooCommerce back to Square? =

This plugin syncs Square → WooCommerce. Two-way sync (WooCommerce → Square push), order sync, multi-location aggregation and one-click mapping import are available through the separate Steadysync Pro add-on at steadysync.net.

= Which product types are supported? =

Simple and variable products. Variable Square items are imported as a WooCommerce variable product with its variations.

== Screenshots ==

1. Connection and health — connect Square (sandbox token or production OAuth), select the location, set the webhook signature key, and see the connection health status.
2. Dry-run preview — the catalog and stock changes a sync would make, computed without writing anything.
3. Health panel — connection status, last successful sync and error count.

== Changelog ==

= 1.0.0 =
* First public release. Square → WooCommerce inventory sync with an anti-zeroing guard, catalog and image import for simple and variable products, real-time signature-verified webhooks, encrypted tokens with automatic refresh, a timeout-safe batch import, a dry-run preview, a health monitor and WP-CLI commands.

== Upgrade Notice ==

= 1.0.0 =
First public release.
