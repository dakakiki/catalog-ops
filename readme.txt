=== CatalogOps — Bulk Operations for WooCommerce ===
Contributors: dakakiki
Tags: woocommerce, bulk edit, products, variations, undo
Requires at least: 6.0
Tested up to: 7.0
Requires PHP: 8.1
Stable tag: 0.6.0
License: GPL-2.0-or-later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Change thousands of WooCommerce products at once. See what will change before it changes. Undo the whole thing with one click.

== Description ==

CatalogOps makes bulk changes to large WooCommerce catalogs safe. Filter the products (or variations) you want, **preview** every old → new value, apply the change as a background operation, and **undo** it later if you need to.

It is built for catalogs where a mistake is expensive — agencies running many stores, and distributors with tens of thousands of SKUs.

**Every operation follows one pipeline:** Filter → Preview → Snapshot → Apply → Verify. The target list is frozen before the run, every change is recorded as a reversible delta, and work runs in idempotent background chunks so a timeout never leaves a half-finished operation.

= What it does =

* **Filter** by category, brand, price, stock, stock status, attribute, SKU, or any meta field — over 50,000 products without loading a single product object.
* **Preview** the exact old → new value for every match before anything is written.
* **Variations are first class** — filter and edit variations directly, not as an afterthought.
* **Formulas** — e.g. `roundto( cost * 1.35, 0.99 )`, with a safe parser (no `eval`). Empty fields are skipped and logged, never set to 0.
* **Amount and percentage changes** — raise or drop a price by a fixed amount or by a percentage across the filter.
* **Scheduled & recurring operations** with an emailed completion report.
* **Undo** — an operation reverts through the same pipeline, with drift detection: items changed since the run are skipped (or forced) rather than clobbered.
* **Audit log & retention** — every change is recorded for a configurable window (7–180 days, default 30).
* **Multisite** — per-site catalogs, installed on network activation and on each new site.

= What it is not =

* Not an import/export plugin — it works with data already in your store.
* Not an order tool — it touches products and variations only.
* Not a replacement for backups — it reminds you to take one before your first change.

= Free vs Pro =

The free version is a funnel, not a crippled product: up to 200 objects per operation — setting a value or moving a price by a fixed amount — without undo, percentages, formulas, or scheduling. Pro plans (Solo, Studio, Agency, Unlimited) lift the cap and add undo, formulas, scheduling, and — from Studio up — the ACF and WPML field modules. Pro is sold through Freemius.

== Installation ==

1. Upload the plugin to `wp-content/plugins/catalogops`, or install it from the Plugins screen.
2. Activate it. WooCommerce must be active.
3. Open **CatalogOps** in the admin menu. A short first-run walkthrough shows you the Filter → Preview → Apply flow.

On a multisite network, activating network-wide installs the per-site tables on every site; new sites are set up automatically.

== Frequently Asked Questions ==

= Will a bulk change time out on a big catalog? =

No. Work is frozen to a target list and run in idempotent background chunks through Action Scheduler, with an adaptive batch size and a watchdog. A timeout resumes rather than leaving a half-finished operation.

= Can I undo a change? =

Yes. Every change is recorded as an old → new delta. Undo runs as its own operation (with a preview), and skips any item that changed after the original run so it never overwrites newer edits.

= Does it work with variations? =

Yes — variations are a first-class target. You can filter and edit them directly, including by their own attribute values.

= Which databases are supported? =

MySQL 5.7+ / MariaDB 10.4+. Queries are verified against MySQL 8.0 before each release.

= Is it translatable? =

Yes. All strings (PHP and the React admin app) are translatable; a `.pot` template ships in `/languages`, with a Serbian starter translation.

== Screenshots ==

1. The filter, product table, and bulk-edit panel.
2. A preview of old → new values before applying.
3. Operation history with one-click undo.

== Changelog ==

= 0.6.0 =
* Go-to-market groundwork: multisite (network activation, per-site schema, uninstall), a first-run onboarding walkthrough with a mandatory backup reminder, full translation-readiness with a Serbian starter, and a MySQL 8.0 query-verification harness.

= 0.5.0 =
* Formula parser (shunting-yard, no eval), scheduled and recurring operations, completion-report notifications.

= 0.4.0 =
* Variations as a first-class object — filter and edit 50,000 variations without loading product objects.

= 0.3.0 =
* Undo, drift detection, conflict policy, audit log, and configurable retention.

= 0.2.0 =
* Write engine: snapshot, Action Scheduler chunks, progress UI, watchdog.

= 0.1.0 =
* Read-only query engine, filter structure, saved filters, product table.

== Upgrade Notice ==

= 0.6.0 =
Adds multisite support, onboarding, translations, and MySQL 8.0 verification. No action needed — the schema upgrades itself on the next admin visit.
