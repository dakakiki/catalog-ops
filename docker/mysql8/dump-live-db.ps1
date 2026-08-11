# Dump the tables the CatalogOps query engine touches from the live dev 5.7 DB,
# ready to import into the MySQL 8.0 verification container.
#
# Only the six tables the query engine reads are exported (not the whole DB):
# posts, postmeta, wc_product_meta_lookup, term_relationships, term_taxonomy,
# terms. Over the 68k-product / 50k-variation catalog this is still large
# (postmeta dominates) — the .sql is gitignored.
#
# Run from anywhere:  .\docker\mysql8\dump-live-db.ps1

$ErrorActionPreference = 'Stop'

$wpRoot  = 'C:\wamp64\www\catalog-ops.app_08.2026'
$outFile = Join-Path $PSScriptRoot 'catalogops-live.sql'

# wp-cli / php / mysql are not on PATH by default in this env (see memory).
$env:Path += ';C:\wamp64\wp-cli;C:\wamp64\bin\php\php8.1.29;C:\wamp64\bin\mysql\mysql5.7.36\bin'

$tables = @(
  'co_posts',
  'co_postmeta',
  'co_wc_product_meta_lookup',
  'co_term_relationships',
  'co_term_taxonomy',
  'co_terms'
) -join ','

Write-Host "Exporting $tables from the live DB..."
Push-Location $wpRoot
try {
  # wp db export uses the site's own DB credentials, so no host/user/pass here.
  wp db export $outFile --tables=$tables --single-transaction --default-character-set=utf8mb4
}
finally {
  Pop-Location
}

$sizeMB = [math]::Round((Get-Item $outFile).Length / 1MB, 1)
Write-Host "Wrote $outFile ($sizeMB MB)."
Write-Host "Next: docker compose up -d ; then .\docker\mysql8\run-explain.ps1"
