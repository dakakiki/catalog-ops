# CatalogOps — User Guide

CatalogOps makes large WooCommerce bulk changes safe: filter what to change, see
exactly what will change, apply it in the background, and undo it if you need to.

## Your first operation

Open **CatalogOps** in the WordPress admin. On the first visit a short
walkthrough explains the three steps; you can dismiss it any time.

1. **Filter** — in the *Filter* row, choose what to change: a category, brand,
   price range, stock status, attribute, or a SKU search. Switch **Target**
   between *Products* and *Variations*. Click **Show products** to see the
   matches and the count.
2. **Preview** — in **Bulk edit**, pick a field and a new value (or a percentage,
   or a formula), then click **Preview**. You see the old → new value for the
   matches. Nothing has been written yet.
3. **Apply** — click **Apply**. The first time, you confirm a one-time backup
   reminder. The operation then runs in the background; its progress appears in
   **Operation history**.

> **Take a backup before your first change.** CatalogOps is reversible, but it is
> not a backup. The first-run reminder is deliberate.

## Ways to change a value

* **Set to** — a fixed value (e.g. set stock status to *In stock*).
* **Percent** — raise or drop a numeric field by a percentage (e.g. `-10%`).
* **Formula** — an expression such as `roundto( cost * 1.35, 0.99 )`.
  Variables: `regular_price`, `sale_price`, `stock`, `weight`, `cost`.
  Functions: `round`, `ceil`, `floor`, `roundto`, `min`, `max`, `abs`.
  An empty or non-numeric field is **skipped and logged**, never set to 0.

## Undo, drift, and retention

Every change is recorded as an old → new delta. In **Operation history**, click
**Undo** on a run to reverse it — undo is itself an operation, with its own
preview. If an item was changed *after* the original run (drift), undo **skips**
it by default so it never overwrites a newer edit; you can choose to force it.

Recorded changes are kept for the **retention window** — the period in which an
operation can still be undone. It defaults to **30 days** and is configurable
from 7 to 180 days in the **Retention** panel.

## Scheduling

From **Bulk edit**, choose **Schedule instead…** to run the same filter + change
later, or on a recurring basis (hourly … monthly). Each run re-resolves the
filter at run time and emails a completion report. Manage schedules in the
**Schedules** panel.

## Plans

| Plan | Sites | Notes |
|---|---|---|
| Free | 1 | Up to 200 objects per operation; no undo, formulas, or scheduling. |
| Solo | 1 | Core field providers. |
| Studio | 5 | + ACF and WPML modules. |
| Agency | 25 | The primary plan. |
| Unlimited | ∞ | |

Pro is sold through Freemius (which handles EU VAT and US sales tax).

## Multisite

CatalogOps keeps a **separate catalog and history per site**. Activating the
plugin network-wide installs the tables on every existing site, and any site
created afterwards is set up automatically. Uninstalling cleans up every site.

## Translations

The interface is fully translatable — both the PHP strings and the React admin
app. A `.pot` template ships in `/languages`, alongside a Serbian starter
translation. To translate, see [`languages/README.md`](../languages/README.md).

## Requirements

* WordPress 6.0+, WooCommerce active.
* PHP 8.1+.
* MySQL 5.7+ / MariaDB 10.4+. Queries are verified against MySQL 8.0 before each
  release — see [`docker/mysql8/README.md`](../docker/mysql8/README.md).
