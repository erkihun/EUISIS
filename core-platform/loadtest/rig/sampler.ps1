# Resource sampler for the Core load runs (docs/go-live/core/core-go-live-evidence.md §9). TEST RIG ONLY.
# Every INTERVAL seconds for DURATION seconds: CPU (% of the whole machine) and memory of the php,
# node and postgres processes, the PostgreSQL connections to the database, and the queue depth.
#   powershell -File sampler.ps1 -Out samples.csv -Duration 70 -Database core_migration_rehearsal_scale -PgPass <pgpass file>
param([string]$Out, [int]$Duration = 70, [int]$Interval = 2, [string]$Database, [string]$PgPass)
$cores = [Environment]::ProcessorCount
$psql = 'C:\Program Files\PostgreSQL\18\bin\psql.exe'
$env:PGPASSFILE = $PgPass
"time,php_cpu_pct,php_mem_mb,php_procs,node_cpu_pct,postgres_cpu_pct,postgres_mem_mb,db_connections,db_active,jobs_waiting" | Out-File -Encoding ascii $Out
$prev = @{}
function CpuSeconds($name) { (Get-Process -Name $name -ErrorAction SilentlyContinue | Measure-Object -Property CPU -Sum).Sum }
foreach ($n in 'php', 'node', 'postgres') { $prev[$n] = CpuSeconds $n }
$end = (Get-Date).AddSeconds($Duration)
while ((Get-Date) -lt $end) {
    Start-Sleep -Seconds $Interval
    $row = @{}
    foreach ($n in 'php', 'node', 'postgres') {
        $now = CpuSeconds $n
        $row[$n] = [math]::Round((($now - $prev[$n]) / $Interval) / $cores * 100, 1)
        $prev[$n] = $now
    }
    $php = Get-Process -Name php -ErrorAction SilentlyContinue
    $pg = Get-Process -Name postgres -ErrorAction SilentlyContinue
    $q = "select count(*), count(*) filter (where state = 'active'), (select count(*) from jobs) from pg_stat_activity where datname = '$Database'"
    $db = (& $psql -h 127.0.0.1 -U Anani -d $Database -At -F ',' -c $q 2>$null)
    if (-not $db) { $db = ',,' }
    "{0},{1},{2},{3},{4},{5},{6},{7}" -f (Get-Date -Format 'HH:mm:ss'), $row['php'], [math]::Round(($php | Measure-Object WorkingSet64 -Sum).Sum / 1MB), $php.Count,
        $row['node'], $row['postgres'], [math]::Round(($pg | Measure-Object WorkingSet64 -Sum).Sum / 1MB), $db | Out-File -Append -Encoding ascii $Out
}
