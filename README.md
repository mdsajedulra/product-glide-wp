# Product Glide (WordPress plugin)

All-in-one WooCommerce order hub: Steadfast courier, fraud check, invoices, package builder,
WhatsApp, profit & stock, screenshot order creator, team performance.

## Releasing an update to every site

```bash
tools/release.sh 1.5.1 "Short note about what changed"
```

This bumps the version, commits and pushes to `main`. The **Release** GitHub Action sees the
new version, creates tag `v1.5.1`, builds `product-glide.zip` and attaches it to a GitHub Release. Each site (Settings → Updates:
repository + read-only token) then shows **Update available** on the Plugins page.

## Token for the sites

GitHub → Settings → Developer settings → Fine-grained tokens → *Generate new token*:
- Repository access: **Only select repositories** → this repo
- Permissions → Repository → **Contents: Read-only**

Paste it in each site: Product Glide → Settings → Updates → GitHub token.
