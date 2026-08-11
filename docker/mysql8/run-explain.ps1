# Run the CatalogOps query-engine EXPLAIN suite against the MySQL 8.0 container
# (and optionally the live 5.7) so the plans can be compared before release.
#
#   .\docker\mysql8\run-explain.ps1                # run the suite on 8.0
#   .\docker\mysql8\run-explain.ps1 -Generate      # regenerate the SQL first
#   .\docker\mysql8\run-explain.ps1 -Import         # (re)import the live dump
#   .\docker\mysql8\run-explain.ps1 -Compare        # also run it on local 5.7
#
# Prereq: docker compose up -d  (see README.md). The container name and DB come
# from docker-compose.yml.

param(
  [switch]$Generate,
  [switch]$Import,
  [switch]$Compare
)

$ErrorActionPreference = 'Stop'

$container = 'catalogops-mysql8'
$db        = 'catalogops_verify'
$wpRoot    = 'C:\wamp64\www\catalog-ops.app_08.2026'
$sqlFile   = Join-Path $PSScriptRoot 'explain-queries.generated.sql'
$dumpFile  = Join-Path $PSScriptRoot 'catalogops-live.sql'
$out80     = Join-Path $PSScriptRoot 'explain-8.0.txt'
$out57     = Join-Path $PSScriptRoot 'explain-5.7.txt'

$env:Path += ';C:\wamp64\wp-cli;C:\wamp64\bin\php\php8.1.29;C:\wamp64\bin\mysql\mysql5.7.36\bin'

function Invoke-Mysql8([string]$sqlPathInContainer) {
  # -t gives table-formatted output (readable traditional EXPLAIN).
  docker exec -e MYSQL_PWD=root -i $container sh -c "mysql -t -uroot $db < $sqlPathInContainer"
}

# --- 1. Regenerate the EXPLAIN SQL from the live query engine, if asked ---------
if ($Generate) {
  Write-Host 'Regenerating EXPLAIN SQL from the live query engine...'
  Push-Location $wpRoot
  try { wp eval-file 'C:\dev\catalogops\docker\mysql8\generate-explain-sql.php' }
  finally { Pop-Location }
}

if (-not (Test-Path $sqlFile)) {
  throw "Missing $sqlFile — run with -Generate first (needs the live site)."
}

# --- 2. Wait for the container to be healthy -----------------------------------
Write-Host 'Waiting for MySQL 8.0 to be ready...'
$ready = $false
for ($i = 0; $i -lt 30; $i++) {
  $ok = docker exec -e MYSQL_PWD=root $container mysqladmin ping -uroot 2>$null
  if ($LASTEXITCODE -eq 0) { $ready = $true; break }
  Start-Sleep -Seconds 2
}
if (-not $ready) { throw "MySQL 8.0 container '$container' did not become ready. Is 'docker compose up -d' running?" }

# --- 3. Import the live dump if empty (or forced) ------------------------------
$countSql = "SELECT COUNT(*) FROM information_schema.tables WHERE table_schema='$db' AND table_name='co_posts'"
$rows = docker exec -e MYSQL_PWD=root $container mysql -N -uroot -e $countSql 2>$null
if ($Import -or "$rows".Trim() -eq '0') {
  if (-not (Test-Path $dumpFile)) { throw "Missing $dumpFile — run .\docker\mysql8\dump-live-db.ps1 first." }
  Write-Host 'Importing the live dump into the 8.0 container (this can take several minutes)...'
  docker cp $dumpFile "${container}:/tmp/catalogops-live.sql"
  docker exec -e MYSQL_PWD=root $container sh -c "mysql -uroot $db < /tmp/catalogops-live.sql"
  Write-Host 'Refreshing optimizer statistics (ANALYZE TABLE)...'
  docker exec -e MYSQL_PWD=root $container sh -c "mysql -uroot $db -e 'ANALYZE TABLE co_posts, co_postmeta, co_wc_product_meta_lookup, co_term_relationships, co_term_taxonomy, co_terms;'" | Out-Null
}

# --- 4. Run the suite on 8.0 ---------------------------------------------------
Write-Host 'Running EXPLAIN suite on MySQL 8.0...'
docker cp $sqlFile "${container}:/tmp/explain.sql"
Invoke-Mysql8 '/tmp/explain.sql' | Tee-Object -FilePath $out80
Write-Host "8.0 plans written to $out80"

# --- 5. Optionally run the same on the live 5.7 for side-by-side ---------------
if ($Compare) {
  $dbName = (wp --path=$wpRoot config get DB_NAME).Trim()
  Write-Host "Running the same suite on local 5.7 (db: $dbName)..."
  # mysql client reads the SQL file; -t for the same table format.
  Get-Content $sqlFile -Raw | mysql -t -uroot --database=$dbName | Tee-Object -FilePath $out57
  Write-Host "5.7 plans written to $out57"
  Write-Host "Compare: code --diff `"$out57`" `"$out80`"   (or your diff tool)"
}
