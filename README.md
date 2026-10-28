# Product Glide (WordPress plugin)

All-in-one WooCommerce order hub: Steadfast courier, fraud check, invoices, package builder,
WhatsApp, profit & stock, screenshot order creator, team performance.

## Releasing an update to every site

```bash
tools/release.sh 1.5.1 "Short note about what changed"
```

This bumps the version, commits and pushes to `main`. The **Release** GitHub Action sees the
new version, creates tag `v1.5.1`, builds `product-glide.zip` and attaches it to a GitHub Release. Each site then shows **Update available** on the Plugins page.

## Sites need no token

The repository is public, so every site with Product Glide installed checks the latest
Release on its own (cached for 6 hours; Settings → Updates → *Check now* forces a check).
