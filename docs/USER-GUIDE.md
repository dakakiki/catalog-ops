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
   or a formula), then click **Preview**. You see how many items matched, how many
   will actually change, and why the rest will not. Nothing has been written yet.
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

## What will change, and what won't

Preview always answers three questions: how many items **matched** the filter, how
many **will change**, and how many **will not** — each with a reason. Only the
items that will change are put into the operation, so its progress bar, its
history entry, and its undo all show the same number you were shown.

Items are left out when:

* **A field the change reads is empty or non-numeric.** A formula or percentage
  needs a value to work from; without one the item is left alone, never set to 0.
  Variable products are the common case — their prices live on the variations, so
  switch **Target** to *Variations*.
* **The sale price is not below the regular price.** WooCommerce only stores a
  sale price lower than the regular price. Setting one that isn't does not fail
  loudly — it *deletes* any sale price the product already had. CatalogOps leaves
  those products out instead.
* **Stock is managed.** With *Manage stock* on, WooCommerce works the stock status
  out from the quantity and backorder setting on every save, so setting the status
  by hand has no effect. Change the quantity instead. Variations that inherit
  stock management from their parent count here too.

A finished run shows the same breakdown, and the **Why** column in an operation's
detail view gives the reason for each individual item.

### One warning worth reading

Lowering a **regular price** below an existing **sale price** makes WooCommerce
delete that sale price — it re-checks both prices whenever either one is written.
Preview counts how many products this would hit and warns you before you apply.
That deletion is not recorded as a change of its own, so **Undo cannot bring those
sale prices back**. Check the number before applying.

## Undo, drift, and retention

Every change is recorded as an old → new delta. In **Operation history**, click
**Undo** on a run to reverse it — undo is itself an operation, with its own
preview. If an item was changed *after* the original run (drift), undo **skips**
it by default so it never overwrites a newer edit; you can choose to force it.

Recorded changes are kept for the **retention window** — the period in which an
operation can still be undone. It defaults to **30 days** and is configurable
from 7 to 180 days in the **Retention** panel.

## Scheduling

In **Bulk edit**, expand **Scheduling** to run the same filter + change later, or
on a recurring basis (hourly … monthly). Set a **Start** time (leave it empty to
start at the next run), optionally a name and an email to notify, then **Create
schedule**. Manage schedules in the **Schedules** panel; each run emails a
completion report.

**Why repeat a change?** The filter is re-evaluated on *every* run — it is not
frozen to the products that matched when you created the schedule. So a recurring
schedule keeps enforcing a rule as the catalog changes: products added or edited
later that match the filter get the change too, with no manual re-run. For
example, a nightly `sale_price = regular_price * 0.9` on a *Clearance* category
automatically discounts anything added to that category afterwards; a daily
`roundto( cost * 1.35, 0.99 )` keeps prices in step with imported costs. For a
genuine one-time change, use **Once** (optionally with a Start time).

### Set the server up first

**A schedule only fires if something drives WordPress's background queue**, and by
default that something is a visitor loading a page. Which defeats the purpose: you
schedule 30,000 changes for 2am *because* the shop is quiet then, and a quiet shop
generates no page loads — so nothing runs until the first visitor next morning,
during opening hour.

Set up one repeating task on the server and every schedule fires on time. It takes
a few minutes once. **CatalogOps → Schedules → Make schedules run on time** prints
the exact commands with your own paths already filled in, for both Windows Task
Scheduler and a Linux/cPanel cron job.

The full walkthrough, including how to verify it and what to check when a schedule
does not fire, is in [`docs/scheduling.md`](scheduling.md).

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
