# Testing setup — WordPress + WooCommerce + CatalogOps

A repeatable runbook to stand up a clean environment for testing CatalogOps,
licensing gating in particular. Follow it whenever you need a fresh install.

---

## 0. Golden rule: install the built zip, never symlink the dev repo

**Do not** symlink `C:\dev\catalogops` into `wp-content/plugins` and then use WP
Admin's **Delete**. WordPress' delete recurses *through* the symlink into your
source tree and — on Windows, with a 609 MB `node_modules` and the 30 s PHP
time limit — can destroy the repo mid-delete. (This has happened.)

Always install the **built zip** as a real plugin folder. Then Deactivate and
Delete are both safe: Delete runs `uninstall.php`, which drops only CatalogOps'
own tables — the WooCommerce catalog (products/prices/cost/tags) is untouched.

---

## 1. Prerequisites

- A PHP stack (WAMP, Local, Docker, or a server): **PHP 8.1+** (8.1 / 8.2 / 8.3
  are all CI-tested), **MySQL 5.7+ or 8.0**, Apache/nginx.
- **WP-CLI** (WAMP ships it at `C:\wamp64\wp-cli`).
- **WooCommerce** — latest is fine (dev was on WC 11.x).
- The plugin zip — build it from the repo:
  ```bash
  php bin/build.php
  ```
  → `dist/catalogops-<version>.zip` (premium: Freemius SDK bundled, licensing live).
  Use `--variant=free` for an SDK-absent build (everything unlimited; not for
  gating tests).

---

## 2. Raise PHP limits (prevents the 30 s timeout footgun)

The 30 s `max_execution_time` on a default WAMP breaks plugin/WooCommerce
unzips and long operations. Raise it in **both** the Apache PHP ini and the CLI
php.ini:

```ini
max_execution_time = 300
memory_limit = 512M
```

WAMP Apache ini lives under `C:\wamp64\bin\apache\apache2.4.x\bin\php.ini`.
Restart Apache after editing.

### WP-CLI on WAMP (PowerShell)

WP-CLI, PHP, and MySQL aren't on the global PATH. Prefix each PowerShell session:

```powershell
$env:Path += ";C:\wamp64\wp-cli;C:\wamp64\bin\php\php8.1.29;C:\wamp64\bin\mysql\mysql5.7.36\bin"
Set-Location "C:\wamp64\www\<your-site-folder>"
```

(Adjust the php / mysql version folders to what your WAMP has installed.)

---

## 3. Fresh WordPress

### Option A — WP-CLI (fast, reproducible)

```bash
# from the site's web root (e.g. C:\wamp64\www\catalogops-test)
wp db create
wp core download
wp config create --dbname=catalogops_test --dbuser=root --dbpass=
wp core install \
  --url="http://localhost/catalogops-test" \
  --title="CatalogOps Test" \
  --admin_user=admin \
  --admin_password=admin \
  --admin_email=you@example.com
```

### Option B — browser installer

Point Apache at a fresh web root, create an empty DB, open the site, and run the
5-minute WordPress installer.

---

## 4. WooCommerce

```bash
wp plugin install woocommerce --activate
```

Skip the WooCommerce onboarding wizard (Skip setup store details) — not needed
for testing.

---

## 5. CatalogOps plugin

Install the built zip as a **real** plugin folder:

```bash
wp plugin install "C:\dev\catalogops\dist\catalogops-0.6.0.zip" --activate
```

Or in WP Admin → **Plugins → Add New → Upload Plugin** → choose the zip → Install
→ Activate.

> Requires WordPress 6.0+, PHP 8.1+, and WooCommerce active.

---

## 6. Freemius activation — pick the plan you want to test

On activation, Freemius shows an opt-in / activation screen:

- **Free plan (gating ON — the upsell test):** choose **"Activate Free Version"**
  (or simply skip the license). The site stays on the Free plan, so paid-only
  controls are gated.
- **Studio plan (everything unlocked):** enter a **sandbox Studio** license key.

Switch later from CatalogOps → **Account**:
- Free → Studio: enter the sandbox Studio license.
- Studio → Free: **Deactivate license**.

> The `--variant=free` zip has no Freemius at all, so it always runs unlimited —
> it cannot show Free-plan gating. Use the premium zip + "Activate Free Version".

---

## 7. Seed a test catalog

The plugin ships a seeder. Run it **via WP-CLI** (no web timeout), so even a low
`max_execution_time` is fine:

```bash
wp catalogops seed --products=5000 --variable=2000
```

- `--products=<n>` parent products, `--variable=<n>` of which are variable (each
  gets Size variations).
- `--reset` removes everything the seeder created (`_catalogops_seeded` marker).

The seeder recreates categories (Apparel, Footwear, …), the Color/Size global
attributes, brand values (the `_catalogops_brand` meta field), prices, cost, and
stock — the full shape the query/gating features exercise. **5 000 products is
plenty** for gating tests: you only need > 200 matching a filter to hit the free
cap; the 68 k live scale is not required.

> This is why re-seeding beats a CSV export/import for moving the catalog to a new
> site: brands are a meta field (a product CSV export drops them without a custom
> column) and 118 k rows import slowly and fragilely. One seed command is exact
> and fast.

---

## 8. Free-plan test checklist

With the site on the **Free** plan, open CatalogOps and confirm:

- **Bulk edit:** "Set to" works; **Percent** and **Formula** are disabled with a
  "Paid plan" upsell underneath.
- **Scheduling:** the toggle is locked with an upsell (the form never opens).
- **History:** no **Undo** button appears on any operation.
- **Free object cap:** an operation whose filter matches **> 200** objects returns
  a clean upgrade message (HTTP 402), not a 500 / critical error; a "Set to" run
  of ≤ 200 completes normally.

Then (optional) switch to **Studio** and confirm everything above unlocks.

---

## 9. Cleanup / re-test

Because it's a real plugin folder, teardown is safe:

```bash
wp plugin deactivate catalogops
wp plugin uninstall catalogops   # runs uninstall.php: drops CatalogOps tables only
```

Or in WP Admin: Deactivate → Delete. The WooCommerce catalog remains; only
CatalogOps' operations/changes/schedules/saved-filters tables are dropped. To
re-test, reinstall the zip and re-seed.

---

## Gotchas recap

- **Never** symlink the dev repo as the plugin and then Delete it (§0).
- Raise `max_execution_time` to 300 on WAMP (§2) — the 30 s default breaks
  unzips and long ops.
- Prefix the PATH for WP-CLI/PHP/MySQL on WAMP (§2).
- Seed via WP-CLI, not the browser (§7).
- Free-plan gating needs the **premium** zip + "Activate Free Version" (§6).
