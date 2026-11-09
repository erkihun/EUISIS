<?php

declare(strict_types=1);

/*
 * Per-request PHP memory, time and query count for the heavy pages and API
 * calls (docs/production-readiness/capacity-model.md, PHP-FPM sizing).
 *
 * Each request runs in its own fresh PHP process (like one FPM request after
 * OPcache warm-up: the first, cold run is discarded), signed in as a synthetic
 * Super Admin (city scope, the most expensive view) created on first use.
 *
 *   DB_DATABASE=aaemployeedb_scale3m [PROFILE_VERBOSE=1] php loadtest/profile/requests.php [runs] [label-substring]
 */

use App\Models\ExternalApplication;
use App\Models\User;
use Illuminate\Contracts\Console\Kernel as ConsoleKernel;
use Illuminate\Contracts\Http\Kernel as HttpKernel;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

$root = dirname(__DIR__, 2);

if (($argv[1] ?? null) === 'one') {
    require $root.'/vendor/autoload.php';
    $app = require $root.'/bootstrap/app.php';
    $app->make(ConsoleKernel::class)->bootstrap();
    guard($app);
    [$method, $uri, $as] = [$argv[2], $argv[3], $argv[4]];
    $user = User::query()->where('email', $as)->firstOrFail();
    $headers = ['HTTP_ACCEPT' => str_starts_with($uri, '/api/') ? 'application/json' : 'text/html', 'REMOTE_ADDR' => '127.0.0.1'];
    if (str_starts_with($uri, '/api/')) {
        $headers['HTTP_AUTHORIZATION'] = 'Bearer '.$argv[5];
    }
    $queries = 0;
    $dbMs = 0.0;
    $slowest = [];
    DB::listen(function ($q) use (&$queries, &$dbMs, &$slowest): void {
        $queries++;
        $dbMs += $q->time;
        $slowest[] = [$q->time, $q->sql];
    });
    $request = Request::create($uri, $method, [], [], [], $headers);
    if (! str_starts_with($uri, '/api/')) {
        app('auth')->guard('web')->setUser($user);
        $request->setUserResolver(fn () => $user);
    }
    memory_reset_peak_usage();
    $base = memory_get_usage();
    $t = microtime(true);
    $response = app(HttpKernel::class)->handle($request);
    $ms = (microtime(true) - $t) * 1000;
    echo json_encode(['status' => $response->getStatusCode(), 'ms' => round($ms, 1), 'db_ms' => round($dbMs, 1), 'queries' => $queries,
        'peak_mb' => round(memory_get_peak_usage() / 1048576, 1), 'request_mb' => round((memory_get_peak_usage() - $base) / 1048576, 1),
        'bytes' => strlen((string) $response->getContent()),
        'slowest' => collect($slowest)->sortByDesc(0)->take((int) (getenv('PROFILE_TOP') ?: 3))->map(fn ($x) => round($x[0]).'ms '.substr(preg_replace('/\s+/', ' ', $x[1]), 0, 220))->values()->all()]), PHP_EOL;
    exit(0);
}

require $root.'/vendor/autoload.php';
$app = require $root.'/bootstrap/app.php';
$app->make(ConsoleKernel::class)->bootstrap();
guard($app);

$runs = (int) ($argv[1] ?? 3);
$admin = User::query()->firstOrCreate(['email' => 'sc-admin@scale.invalid'], [
    'name' => 'Scale Super Admin', 'password' => bcrypt(Str::random(40)), 'status' => 'active', 'is_demo' => true,
    'must_change_password' => false, 'email_verified_at' => now(),
]);
if (! $admin->hasRole('Super Admin')) {
    $admin->assignRole('Super Admin');
}
$token = ExternalApplication::query()->where('code', 'SC-K6-00')->first()?->createToken('profile', ['*'])->plainTextToken;
$employee = DB::selectOne("SELECT e.id, e.name_en, a.organization_id FROM employees e JOIN employee_assignments a ON a.id = e.current_assignment_id WHERE e.employee_number = 'SC-1500000'");
$term = urlencode(implode(' ', array_slice(explode(' ', (string) $employee?->name_en), 0, 2)));

$targets = [
    ['GET', '/employees', 'employee list, page 1 (city)'],
    ['GET', '/employees?page=20000', 'employee list, page 20,000 (deep OFFSET)'],
    ['GET', "/employees?search={$term}", 'employee search, given + father name'],
    ['GET', '/employees?search=SC-1500000', 'employee search, employee number'],
    ['GET', "/employees/{$employee?->id}", 'employee profile'],
    ['GET', '/organizations', 'organization list'],
    ['GET', '/dashboard', 'dashboard (aggregates, cached 120 s)'],
    ['GET', '/situation-room/workforce', 'Situation Room workforce (aggregates)'],
    ['GET', '/situation-room', 'Situation Room overview'],
    ['GET', '/cafeteria/transactions/export/csv', 'cafeteria transaction export (CSV, capped)'],
];
if ($token !== null) {
    $targets[] = ['GET', '/api/v1/changes?limit=500', 'change feed, 500 events'];
    $targets[] = ['GET', '/api/v1/employees?per_page=100', 'API employee list, 100 per page (city)'];
}

// Each child gets a private, empty cache: every aggregate is computed (the cache-miss cost).
putenv('CACHE_STORE=array');
putenv('INTEGRATION_CHANGE_FEED_ENABLED=true');
$verbose = getenv('PROFILE_VERBOSE') !== false;

printf("%-46s %6s %8s %8s %7s %8s %9s %9s\n", 'request', 'status', 'ms', 'db_ms', 'queries', 'peak_mb', 'req_mb', 'KB');
$only = $argv[2] ?? null;
foreach ($targets as [$method, $uri, $label]) {
    if ($only !== null && ! str_contains($label, $only)) {
        continue;
    }
    $results = [];
    for ($i = 0; $i <= $runs; $i++) {
        $out = shell_exec(escapeshellarg(PHP_BINARY).' -d memory_limit=1G '.escapeshellarg(__FILE__).' one '.$method.' '.escapeshellarg($uri).' '.escapeshellarg($admin->email).' '.escapeshellarg((string) $token).' 2>&1');
        $line = json_decode(trim((string) collect(explode("\n", trim((string) $out)))->last()), true);
        if ($i > 0 && is_array($line)) { // run 0 warms caches and is discarded
            $results[] = $line;
        } elseif (! is_array($line)) {
            echo "  {$label}: ".substr((string) $out, 0, 300).PHP_EOL;
            break;
        }
    }
    if ($results === []) {
        continue;
    }
    $median = fn (string $k) => collect($results)->pluck($k)->sort()->values()[intdiv(count($results), 2)];
    printf("%-46s %6s %8.0f %8.0f %7d %8.1f %9.1f %9.0f\n", $label, $results[0]['status'], $median('ms'), $median('db_ms'), $median('queries'),
        $median('peak_mb'), $median('request_mb'), $median('bytes') / 1024);
    if ($verbose) {
        foreach ($results[0]['slowest'] as $q) {
            echo '      '.$q.PHP_EOL;
        }
    }
}

function guard($app): void
{
    if ($app->environment('production') || DB::table('employees')->where('employee_number', 'not like', 'SC-%')->limit(1)->exists()) {
        fwrite(STDERR, "Benchmark databases only.\n");
        exit(1);
    }
}
