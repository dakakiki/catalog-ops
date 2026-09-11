=== CatalogOps — Bulk Operations for WooCommerce ===
Contributors: dakakiki
Tags: woocommerce, bulk edit, products, variations, undo
Requires at least: 6.0
Tested up to: 7.1
Requires PHP: 8.1
WC requires at least: 9.0
WC tested up to: 11.0
Stable tag: 0.8.0
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

= I run an agency. How do I keep track of which client sites are using my licence? =

Tick **"Associate with the license owner's account"** when you activate your key on a client's site. It appears whenever the key you are entering belongs to a different account than the one that site connected with — which is usually the case, because the site connects with its own administrator's email.

The licence is activated either way, and so are updates; what the tick decides is whose account that installation is filed under. Filed under yours, every client site appears in one list, and you can free a seat when you stop working for that client — without needing access to their site again. Left unticked, the site is filed under the client, and you are paying for a seat you can neither see nor release.

== Screenshots ==

1. The filter, product table, and bulk-edit panel.
2. A preview of old → new values before applying.
3. Operation history with one-click undo.

== Changelog ==

= 0.8.0 =
* Added: on a WPML site, CatalogOps now works in whatever language you are already in — filter, results, bulk edit, schedules and history, all of it. It never asks which language you mean, because standing in one and being asked is a question the screen can already answer. On WPML's "All languages" nothing is confined and the whole catalogue is in reach, which is the same behaviour a shop without WPML has always had.
* Added: the header says which language the page is working in. An unstated frame is invisible until it is wrong, and somebody who switched language two screens ago, filtered, previewed twelve thousand products and pressed Apply has no other way to know which twelve thousand those were. A shop with one language is told nothing, because it has nothing to be told.
* Added: the category, tag, brand and attribute pickers list the current language's own terms. A translated term is a different term with a different id — on a bilingual catalogue the same category can be 18 in one language and 73 in another — so a picker filled in one language used to hand the filter ids that no product in the other carried, and the run came back empty with nothing about it looking wrong. A term nobody has translated is left out rather than substituted: each language shows its own catalogue.
* Added: past runs and schedules are listed under the language they were made in, and all of them under "All languages". Work that belongs to no language — everything made before this release included — stays visible in every language, so upgrading never looks like the history was wiped.
* Added: the labels and the offered values of ACF fields follow the language too. The stored value behind a choice never does: it is what the filter freezes, and a translated one would match nothing, silently.
* Fixed: a schedule could not be created from a filter that used a field from ACF, while the identical filter previewed, ran and applied without complaint. The schedule route was checking the filter against the built-in field list, which knows nothing about modules.
* Changed: what a finished run could not do is now stated in a notice, like every other message on the screen, instead of as loose text under the Apply button.
* Changed: the Pricing and Contact items in CatalogOps' own menu are now its own — the plans as they appear on catalog-ops.app, and a link out to the site's contact form. Account is untouched: it is where a licence is activated and synced, and that is not a matter of appearance.

= 0.7.3 =
* Added: every operation that finishes now emails a report, whatever started it and whether or not anything was skipped. Until now a run that changed everything it promised said nothing, on the reasoning that an hourly schedule sending two dozen cheerful reports a day teaches its reader to delete them unopened. What outweighed that is that silence cannot be read: a clean run, a schedule that never fired, cron not reaching the site and a host quietly dropping outgoing mail all produce exactly the same no mail at all, and telling them apart meant opening a screen the report exists to spare you. A report that always arrives is also the only one whose absence means something. Sites that want the old quiet can silence any one source through the `catalogops_send_notifications` filter.
* Changed: reports are now formatted, with the changed, skipped and failed figures in the same green, amber and red the admin screens use, and a plain-text copy sent alongside for text-only clients and spam filters. A figure of zero is left uncoloured, so colour in a report always means something.
* Changed: reports print times on the shop's own clock, like the history does, instead of GMT.
* Added: a run whose worker disappears — a restarted host, a killed PHP process — is picked up and carried on automatically, without anyone at the screen. A machine failure never pauses a schedule; only a person stopping or undoing a run does that.
* Added: the history says when a run has stopped answering, counting up from a minute of silence, and offers to take it over. A run's controls are held back while the page itself cannot reach the site, so nothing is decided on a stale screen.
* Fixed: an interrupted run's counter now settles on the truth. The count was written once per chunk, so a process killed mid-chunk lost everything it had already saved — a live run finished 581 changes and reported 523. The count now rides the heartbeat, and at the end it is reconciled from the change rows themselves, which are the record.
* Fixed: the progress panel no longer freezes for the rest of the session after a single request that never answered. One dropped poll used to end polling entirely, so a run that recovered and finished showed a panel stuck at its old number beside a history that had moved on.
* Changed: the progress panel goes away once a run is over, instead of leaving a full green bar on the screen.
* Fixed: filtering by brand returned the wrong set. A positive brand membership was tested in the WHERE clause rather than joined, which on a large catalogue is both slow and wrong; it is now the same shape the category filter uses.
* Added: the tag filter can ask for products that have no tag at all.
* Fixed: a repeating schedule with a relative action no longer compounds. A percentage or formula schedule re-resolved its filter every run and applied itself again to everything matching, so a nightly "-5%" cut five percent off the already-cut price, night after night. A schedule now changes each product at most once: everything on its first run, and only what has newly entered the filter after that.
* Fixed: a schedule pauses itself, with the reason recorded and emailed, when one of its runs is stopped or undone.
* Added: HPOS (High-Performance Order Storage) compatibility is declared, so CatalogOps no longer counts as an undeclared plugin holding the feature back, and the supported WooCommerce range is stated in the plugin header.

= 0.7.2 =
* Fixed: a filter condition naming a field CatalogOps does not recognize is now refused, naming the field, instead of being ignored. An ignored condition removed a constraint, so "category X and brand Y" quietly became "category X" — a larger set of products, previewed and applied identically, so nothing downstream noticed. The same now applies to a comparison a field cannot make (a price "contains", a SKU "greater than") and to a "between" filter missing one end.
* Fixed: a variation attribute filter whose terms have been deleted now matches nothing, as the same filter over products already did. It used to match every variation.
* Fixed: a schedule that cannot be built no longer stops every other schedule on the site. It pauses itself, records why, and the rest of the tick continues. Previously the failure repeated on every tick and every later schedule was skipped indefinitely — reachable without any bad filter, for example when a licence lapses on a schedule that uses a formula.
* Fixed: "Run now" on a schedule whose template is no longer valid answers cleanly instead of a critical error.
* Fixed: an unrecognized filter operator answers with an error naming it, instead of a critical error.
* Added: the results table shows categories, alongside the brand and tags added in 0.7.1. Category is the filter's first control and was the one thing you could filter by but not see.
* Fixed: a category or tag whose name contains an ampersand reads as written in the results table. It showed as "Home &amp;amp; Kitchen" while the filter's own dropdown, for the same term, said "Home &amp; Kitchen".
* Added: a run that stopped part-way can be resumed. If the host restarts or the background queue breaks, an operation is marked failed after ten minutes so it does not hold the catalog — and until now the only ways out were undoing the part that landed or running the whole filter again. Resume carries on down the list the run froze when it started, so it changes exactly what was approved then rather than re-resolving the filter against a catalog that has since moved on.
* Changed: the backup reminder is per person and says who agreed and when. It used to be a single site-wide tick: one administrator's click stood it down for every colleague who came after, so a newcomer's first change over the whole catalogue could arrive with no warning — and it recorded only that somebody had clicked, at no particular time. Everyone now confirms once, and from then on the confirmation reads "Backup confirmed by <name> on <date>, in version <x>", with a nudge to check it is still recent.
* Added: undo asks for the same backup confirmation Apply does. It writes at the same scale, it cannot itself be undone, and under Force it discards work done after the operation — the one thing this plugin can destroy that it never recorded and so can never give back.
* Added: the undo preview pages and can be searched by SKU, like the results table and the audit log. It showed the first twenty rows and nothing else, so on a catalogue of any size the question an undo is actually decided on — will the one I care about be reverted, or skipped because it changed since? — had no answer. Rows now lead with the SKU, and searching narrows what is shown without narrowing what the undo covers.
* Changed: confirming an undo happens on the page, alongside the numbers and the conflict choice being agreed to, instead of in a browser dialog that could state neither.
* Changed: undo is one-way. An operation that has been undone no longer offers Undo, and an undo cannot itself be undone — previously it could, which put the original change back and made undo a toggle to ride in both directions, leaving the first operation reading "reverted" while its change was in force again. Once a run has been given back, what remains is to look at what it changed or delete it from the history.
* Added: resuming a schedule whose time has passed now says so first. Pausing hides a schedule from the supervisor but does not move its next run, so resuming one that was paused past its time makes it due again immediately — it would start within a few minutes with nothing having said so. The confirmation names the time it was due, how soon it will run, and that it runs once rather than once for every run it missed. A schedule paused before its time resumes with no interruption, as before.
* Fixed: a run that starts while the page is already open now appears by itself. The history list used to schedule its next refresh only if the answer it had just received already contained a running operation, so a page left open with everything finished went quiet for good and a schedule firing overnight was invisible until someone reloaded. It now keeps checking every thirty seconds and switches to every two while something is running, pauses entirely on a hidden tab, and refreshes the moment that tab is looked at again.
* Added: a running operation can be stopped from the history. Until now the only thing offering to stop one was a disabled Delete button whose tooltip told you to stop the run first, and there was nowhere to do it — so a run writing the wrong thing had to be waited out, undo being refused while it is active. Stopping leaves what was already written in place; undo becomes available once it has stopped.
* Faster: an operation no longer waits five seconds between batches. On an 18,583-product catalogue that pause, plus a WordPress load, was about 43% of the time a run took. It is shortened only while CatalogOps is writing, so other plugins' background work is unaffected; a host that wants the full pause back can filter `catalogops_queue_sleep_seconds`.
* Added: `catalogops_schedule_paused` action, fired with the schedule id and the error when the supervisor pauses a schedule itself.
* The schedule form's summary reads as a notice like the preview above it, and turns amber when a schedule would match products but change none of them — worth knowing before it is saved rather than after it fires.

= 0.7.1 =
* The results table leads with the SKU and shows brand and tags — both filterable, and until now invisible in the results.
* An operation refused before it ran — over the free plan's object cap, or while another was already writing — no longer leaves a "draft" row in the history.
* The Schedules panel says scheduling is a paid feature instead of showing an empty table and instructions for a control the plan cannot reach. Schedules already created stay listed and manageable.

= 0.7.0 =
* **Preview shows the work, not just the count** — a table of old → new values for the products the change would touch, with a SKU search to check any one of them.
* **Amount** — raise or lower a price by a fixed sum, alongside the percentage. Available on the free plan.
* **When** — applying now and scheduling are one choice with one button, instead of two controls that never mentioned each other.
* Filters can exclude: every set filter reads "is" or "is not".
* Percentage changes land on the cent, and a price is never written negative — the preview counts the products a subtraction would take below zero instead of discovering them mid-run.
* Operation history and Schedules are paged; the history no longer stops silently at its newest twenty.

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

= 0.7.1 =
The Schedules panel now explains itself on a plan without scheduling. No action needed.

= 0.7.0 =
Preview now shows old → new values per product, prices can be moved by a fixed amount, and applying versus scheduling is a single choice. No action needed.

= 0.6.0 =
Adds multisite support, onboarding, translations, and MySQL 8.0 verification. No action needed — the schema upgrades itself on the next admin visit.
