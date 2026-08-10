# CatalogOps

Bulk operations for large WooCommerce catalogs — **filter, preview, snapshot,
execute, undo.** Change 20,000 products at once, see what will change before it
changes, and revert it with one click.

> Working name: **CatalogOps**. A commercial WooCommerce plugin for safe,
> large-scale catalog operations.

## Why

Existing bulk editors fail on big, real-world catalogs in the same places:
timeouts leave half-finished operations, there is no undo, no preview, and
variations are second-class. CatalogOps is built around one pipeline —
`Filter → Preview → Snapshot → Execute → Verify` — so none of those happen.

## For users

See the [User Guide](docs/USER-GUIDE.md) and the wp.org
[`readme.txt`](readme.txt).

## For developers

Requirements: PHP 8.1+, WordPress 6.0+, WooCommerce, MySQL 5.7+ / MariaDB 10.4+.

```bash
composer install     # PHP dependencies
npm install          # JS dependencies
npm run build        # build the admin app into assets/dist
```

Quality gates:

```bash
composer phpcs                 # coding standards (WordPress-Extra)
composer test:unit             # PHPUnit unit suite
composer test:integration      # PHPUnit integration suite (needs the WP test lib)
npm run lint:js                # ESLint (@wordpress/scripts)
```

CI runs coding standards, the unit suite on PHP 8.1–8.3, and the integration
suite on MySQL 5.7 — both single-site and multisite.

### Layout

| Path | What |
|---|---|
| `src/` | PSR-4 plugin code (`Query`, `Operations`, `Rest`, `Admin`, `Database`, …). |
| `assets/src/` | The React admin app (`@wordpress/scripts`). Built to `assets/dist/` (gitignored). |
| `tests/` | PHPUnit unit + integration suites. |
| `languages/` | `.pot` template and translations — see [`languages/README.md`](languages/README.md). |
| `docker/mysql8/` | MySQL 8.0 query-verification harness — see [`docker/mysql8/README.md`](docker/mysql8/README.md). |
| `docs/` | Project context and the user guide. |

### Architecture

Six rules hold for every change (see [`docs/CatalogOps-CONTEXT_1.md`](docs/CatalogOps-CONTEXT_1.md) §2):
one pipeline for everything; the target list is frozen before execution; nothing
writes synchronously (idempotent Action Scheduler chunks); every change is a
reversible delta; undo is itself an operation; formulas run through a
shunting-yard parser, never `eval()`.

## License

GPL-2.0-or-later.
