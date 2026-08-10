# MySQL 8.0 verification harness

The dev box runs MySQL **5.7.36**; production runs whatever the customer has —
often **8.0**. The two optimizers differ enough that a query that is fast on 5.7
can be slow on 8.0 (CONTEXT §3 "⚠ MySQL 5.7 ograničenja", §9 risk row *"Upit brz
na 5.7, spor na 8.0"*). This harness runs the query engine's **real** SQL against
an 8.0 instance loaded with the **live catalog**, so the plans can be compared
before release.

Nothing here ships in the plugin — it is a developer tool. The large dump and the
plan outputs are gitignored.

## Prerequisites

- **Docker Desktop** installed and running (`docker compose version` works).
- The live dev catalog seeded (68k products + 50k variations, per the M5 state).

## One-time / per-run flow

All commands are run from the plugin root (`C:\dev\catalogops`), PowerShell.

```powershell
# 1. Start MySQL 8.0 (port 3307, buffer pool 1G to match the tuned 5.7).
docker compose -f docker/mysql8/docker-compose.yml up -d

# 2. Generate the EXPLAIN suite from the live query engine (needs the plugin).
#    Writes docker/mysql8/explain-queries.generated.sql.
#    (run-explain.ps1 -Generate does this too.)
#    From WP root: wp eval-file C:\dev\catalogops\docker\mysql8\generate-explain-sql.php

# 3. Export the six tables the engine reads from the live 5.7 DB.
.\docker\mysql8\dump-live-db.ps1

# 4. Import into 8.0 (auto on first run), run the suite, compare to 5.7.
.\docker\mysql8\run-explain.ps1 -Generate -Compare
```

`run-explain.ps1` imports the dump automatically the first time (when `co_posts`
is absent in the container), runs `ANALYZE TABLE` so the optimizer has fresh
statistics, then executes the suite. Switches:

| Switch | Effect |
|---|---|
| `-Generate` | Regenerate the SQL from the live engine before running. |
| `-Import` | Force a re-import of the dump (after reseeding the catalog). |
| `-Compare` | Also run the suite on the local 5.7 and write `explain-5.7.txt`. |

Output: `explain-8.0.txt` (and `explain-5.7.txt` with `-Compare`).

## What the suite covers

`generate-explain-sql.php` reflects into `Query_Engine::select()` so the EXPLAINed
statements are byte-for-byte what the engine emits — one per clause path, plus the
realistic combined filter:

- price `BETWEEN`, `stock_status`, `sku CONTAINS` — `wc_product_meta_lookup` columns
- `category IN` and `attribute:pa_color IN` — correlated taxonomy `EXISTS`
- `meta` string (`meta_key` index) and numeric (`CAST … DECIMAL`)
- variation scope: price, category inherited via `p.post_parent`, `attribute:pa_size` by slug
- combined product filter (price + category + stock_status), resolve **and** count
- `EXPLAIN FORMAT=JSON` (with cost) for the four heaviest paths

## What to look for when comparing 5.7 → 8.0

Read `explain-8.0.txt` (and diff against `explain-5.7.txt`). Flags for concern:

- **`type: ALL`** (full table scan) on `l`/`p` where 5.7 used a range/ref.
- **`key: NULL`** where 5.7 picked an index — a lost index means an 8.0 stats or
  cost-model change; may need an index hint or a rewritten clause.
- **`rows` estimate exploding** vs 5.7 (optimizer mis-estimating selectivity —
  8.0 uses histograms; consider `ANALYZE TABLE … UPDATE HISTOGRAM`).
- **`Extra: Using filesort` / `Using temporary`** newly appearing.
- In the JSON output, a large **`query_cost`** jump for the same shape.

If a plan regresses, that is a real finding: adjust the clause in `Query_Engine`
(or add an index) and regenerate. A clean run — same indexes chosen, comparable
row estimates, no new filesort/scan — is the milestone's MySQL 8.0 sign-off.

## Teardown

```powershell
docker compose -f docker/mysql8/docker-compose.yml down        # keep the data volume
docker compose -f docker/mysql8/docker-compose.yml down -v     # also drop the data
```
