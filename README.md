# Steadysync – Inventory Sync for Square

Free-core WordPress plugin (WordPress.org, slug `steadysync-for-square`). Syncs Square
inventory and catalog into WooCommerce with an anti-zeroing guard, webhook-driven
real-time updates, encrypted tokens with auto-refresh, a dry-run preview, a health
monitor and WP-CLI commands.

This is **Model B**: a clean, fully-functional free core with **no Freemius SDK and no
license gating**. The paid features live in a separate, off-org add-on
(`steadysync-for-square-pro`, private) that hooks into the extension points below.

## Extension API (consumed by the Pro add-on)

- `do_action( 'steadysync_loaded', \Steadysync\Plugin $plugin )` — register add-on modules.
- `apply_filters( 'steadysync_apply_inventory_counts', null, array $counts, Inventory_Sync $inv )` — override single-location apply (e.g. multi-location aggregate).
- `apply_filters( 'steadysync_is_aggregate', false, Settings $settings )`
- `apply_filters( 'steadysync_preview_locations', array $locations, Settings $settings )`
- `do_action( 'steadysync_admin_sections', Settings $settings )` — render add-on settings UI.
- `do_action( 'steadysync_admin_save', array $post )` — persist add-on fields (core nonce already verified).

## Deploy

Tag a GitHub Release equal to the `readme.txt` "Stable tag"; `.github/workflows/deploy.yml`
publishes the repo tree to the WordPress.org SVN trunk + tag via the 10up action, and the
`.wordpress-org/` images to SVN `/assets`. Requires repo secrets `SVN_USERNAME` + `SVN_PASSWORD`.

## Verification

`wp plugin check steadysync-for-square` → 0 errors. Activates cleanly on WP + WooCommerce.

Square is a trademark of Block, Inc. WooCommerce is a trademark of Automattic Inc.
Steadysync is an independent, unofficial plugin, not affiliated with either.
