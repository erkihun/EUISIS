<?php

declare(strict_types=1);

/*
 * Concurrency race harness (docs/production-readiness/failure-testing.md).
 *
 * Starts N PHP processes that each boot Laravel, prepare, wait for a common
 * start instant and then call the real application action at the same moment
 * against a PostgreSQL benchmark database. Afterwards it checks the database
 * invariant the scenario is about and prints one JSON summary line.
 *
 *   DB_DATABASE=aaemployeedb_scale3m php loadtest/concurrency/race.php run <scenario> [workers] [rounds]
 *
 * Scenarios:
 *   assignment         N inserts of a current assignment for one employee whose current one was just closed
 *   position           N RegisterEmployeeAction calls placing new people in one single-incumbent position
 *   transfer-same      N CompleteTransferAction calls on one approved application
 *   transfer-two       2 approved applications of one employee to two positions, implemented at once
 *   transfer-position  2 employees' approved applications to one single-slot position, implemented at once
 *   revoke             1 RevokeCardAction against N-1 RecordServiceTransactionAction calls on the same card
 *   idempotency        N identical POST /api/v1/services/cafeteria/transactions with one Idempotency-Key
 *
 * Refuses production and databases holding non-synthetic employees. It only
 * writes synthetic rows (tagged SC-RACE-…) and changes synthetic employees.
 */

use App\Actions\Employees\RegisterEmployeeAction;
use App\Actions\IdCards\RevokeCardAction;
use App\Actions\ServiceTransactions\RecordServiceTransactionAction;
use App\Actions\Transfers\CompleteTransferAction;
use App\Models\Employee;
use App\Models\EmployeeAssignment;
use App\Models\ExternalApplication;
use App\Models\IdCard;
use App\Models\ServiceProvider;
use App\Models\ServiceType;
use App\Models\TransferApplication;
use App\Models\User;
use Illuminate\Contracts\Console\Kernel as ConsoleKernel;
use Illuminate\Contracts\Http\Kernel as HttpKernel;
use Illuminate\Database\Events\TransactionCommitted;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Str;

$root = dirname(__DIR__, 2);
require $root.'/vendor/autoload.php';
$app = require $root.'/bootstrap/app.php';
$app->make(ConsoleKernel::class)->bootstrap();

if ($app->environment('production') || DB::getDriverName() !== 'pgsql') {
    fwrite(STDERR, "race.php runs only against a non-production PostgreSQL benchmark database.\n");
    exit(1);
}
if (DB::table('employees')->where('employee_number', 'not like', 'SC-%')->limit(1)->exists()) {
    fwrite(STDERR, 'Database '.DB::getDatabaseName()." holds non-synthetic employees; refusing.\n");
    exit(1);
}

$mode = $argv[1] ?? 'run';

if ($mode === 'worker') {
    [$scenario, $index, $startAt, $payload] = [$argv[2], (int) $argv[3], (float) $argv[4], json_decode(base64_decode($argv[5]), true)];
    $prepared = prepareWorker($scenario, $index, $payload);
    // Microsecond time of this process's first outermost COMMIT: the moment
    // its write became visible (timestamps in the tables have 1 s precision).
    $firstCommit = null;
    Event::listen(TransactionCommitted::class, function ($event) use (&$firstCommit): void {
        if ($firstCommit === null && $event->connection->transactionLevel() === 0) {
            $firstCommit = microtime(true);
        }
    });
    while (microtime(true) < $startAt) {
        usleep(200);
    }
    $began = microtime(true);
    try {
        $outcome = $prepared();
        $result = ['ok' => true, 'detail' => $outcome];
    } catch (Throwable $e) {
        $result = ['ok' => false, 'error' => class_basename($e).': '.Str::limit($e->getMessage(), 160)];
    }
    $result['ms'] = round((microtime(true) - $began) * 1000, 1);
    $result['committed_at'] = $firstCommit;
    echo json_encode($result), PHP_EOL;
    exit(0);
}

$scenario = $argv[2] ?? 'assignment';
$workers = (int) ($argv[3] ?? 8);
$rounds = (int) ($argv[4] ?? 1);
$deadlocksBefore = (int) DB::selectOne('select deadlocks from pg_stat_database where datname = current_database()')->deadlocks;
$summary = ['scenario' => $scenario, 'workers' => $workers, 'rounds' => $rounds, 'successes' => [], 'violations' => 0, 'errors' => []];

for ($round = 0; $round < $rounds; $round++) {
    $payload = setupScenario($scenario, $workers);
    $count = $payload['workers'] ?? $workers;
    $startAt = microtime(true) + 4.0; // time for every worker to boot Laravel
    $procs = [];
    $outputs = [];
    for ($i = 0; $i < $count; $i++) {
        $cmd = [PHP_BINARY, '-d', 'memory_limit=512M', __FILE__, 'worker', $scenario, (string) $i, (string) $startAt, base64_encode(json_encode($payload))];
        $procs[] = proc_open($cmd, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, $root, null);
        $outputs[$i] = $pipes;
    }
    $results = [];
    foreach ($procs as $i => $proc) {
        $out = stream_get_contents($outputs[$i][1]);
        $err = stream_get_contents($outputs[$i][2]);
        proc_close($proc);
        $line = trim((string) collect(explode("\n", trim($out)))->last());
        $results[$i] = json_decode($line, true) ?? ['ok' => false, 'error' => 'no result: '.Str::limit(trim($err.' '.$out), 200)];
    }
    $check = verifyScenario($scenario, $payload, $results);
    $summary['successes'][] = count(array_filter($results, fn ($r) => $r['ok'] && ($r['detail'] ?? null) !== 'replay'));
    $summary['violations'] += $check['violations'];
    $summary['checks'][] = $check['note'];
    foreach ($results as $r) {
        if (! $r['ok']) {
            $key = preg_replace('/[0-9a-f]{8}-[0-9a-f-]{27}/', '<uuid>', $r['error']);
            $summary['errors'][$key] = ($summary['errors'][$key] ?? 0) + 1;
        }
    }
    $summary['latency_ms'][] = max(array_column($results, 'ms') ?: [0]);
    if (getenv('RACE_VERBOSE')) {
        $summary['results'][] = $results;
    }
}

$summary['deadlocks'] = (int) DB::selectOne('select deadlocks from pg_stat_database where datname = current_database()')->deadlocks - $deadlocksBefore;
echo json_encode($summary, JSON_UNESCAPED_SLASHES), PHP_EOL;
exit($summary['violations'] === 0 ? 0 : 2);

// ---------------------------------------------------------------------------

function actor(): User
{
    // A synthetic, inactive-password harness account; actions record it as the actor.
    return User::query()->firstOrCreate(['email' => 'sc-race@scale.invalid'], [
        'name' => 'Race harness', 'password' => bcrypt(Str::random(40)), 'status' => 'active', 'is_demo' => true,
    ]);
}

/** A synthetic employee with a current active assignment and an active card, not used before by this harness. */
function freshEmployee(): Employee
{
    $max = (int) DB::table('employees')->where('employee_number', 'like', 'SC-%')->count();
    for ($attempt = 0; $attempt < 50; $attempt++) {
        $id = DB::table('employees as e')
            ->join('employee_assignments as a', 'a.id', '=', 'e.current_assignment_id')
            ->where('e.employee_number', 'SC-'.random_int(0, max(0, $max - 1)))
            ->where('e.status', 'active')->where('a.is_current', true)->where('a.assignment_status', 'active')
            ->whereExists(fn ($q) => $q->from('id_cards as c')->whereColumn('c.employee_id', 'e.id')->where('c.is_current', true)->where('c.status', 'active'))
            ->whereNotExists(fn ($q) => $q->from('transfer_applications as t')->whereColumn('t.employee_id', 'e.id'))
            ->value('e.id');
        if ($id !== null) {
            return Employee::query()->findOrFail($id);
        }
    }

    throw new RuntimeException('No unused synthetic employee found.');
}

/** A new single-incumbent position with an approved one-slot establishment in the employee's organization. */
function vacantPosition(string $organizationId, string $unitId): string
{
    $positionId = (string) Str::uuid7();
    $tag = 'SC-RACE-'.Str::upper(Str::random(8));
    DB::table('positions')->insert(['id' => $positionId, 'organization_id' => $organizationId, 'organization_unit_id' => $unitId,
        'job_position_code' => $tag, 'title_en' => 'Race Post', 'is_active' => true, 'created_at' => now(), 'updated_at' => now()]);
    DB::table('position_establishments')->insert(['id' => (string) Str::uuid7(), 'establishment_number' => $tag, 'organization_id' => $organizationId,
        'organization_unit_id' => $unitId, 'position_id' => $positionId, 'approved_slots' => 1, 'effective_from' => '2020-01-01',
        'status' => 'approved', 'created_at' => now(), 'updated_at' => now()]);

    return $positionId;
}

function approvedApplication(Employee $employee, string $positionId, string $organizationId): string
{
    $announcement = (string) Str::uuid7();
    DB::table('transfer_announcements')->insert(['id' => $announcement, 'organization_id' => $organizationId, 'position_id' => $positionId,
        'opening_date' => now()->subDays(30)->toDateString(), 'closing_date' => now()->subDays(10)->toDateString(),
        'created_by' => actor()->id, 'created_at' => now(), 'updated_at' => now()]);
    $application = (string) Str::uuid7();
    $assignment = EmployeeAssignment::query()->findOrFail($employee->current_assignment_id);
    DB::table('transfer_applications')->insert(['id' => $application, 'announcement_id' => $announcement, 'employee_id' => $employee->id,
        'current_assignment_id' => $assignment->id, 'releasing_organization_id' => $assignment->organization_id,
        'receiving_organization_id' => $organizationId, 'status' => 'approved', 'effective_date' => now()->toDateString(),
        'created_at' => now(), 'updated_at' => now()]);

    return $application;
}

function setupScenario(string $scenario, int $workers): array
{
    $employee = freshEmployee();
    $assignment = EmployeeAssignment::query()->findOrFail($employee->current_assignment_id);

    return match ($scenario) {
        'assignment' => (function () use ($employee, $assignment) {
            DB::table('employee_assignments')->where('id', $assignment->id)->update(['is_current' => false, 'assignment_status' => 'closed', 'effective_to' => now()->subDay()->toDateString()]);

            return ['employee' => $employee->id, 'organization' => $assignment->organization_id, 'unit' => $assignment->organization_unit_id, 'position' => $assignment->position_id];
        })(),
        'position' => ['position' => vacantPosition($assignment->organization_id, $assignment->organization_unit_id),
            'organization' => $assignment->organization_id, 'unit' => $assignment->organization_unit_id],
        'transfer-same' => ['applications' => [approvedApplication($employee, vacantPosition($assignment->organization_id, $assignment->organization_unit_id), $assignment->organization_id)], 'employees' => [$employee->id]],
        'transfer-two' => ['workers' => 2, 'employees' => [$employee->id], 'applications' => [
            approvedApplication($employee, vacantPosition($assignment->organization_id, $assignment->organization_unit_id), $assignment->organization_id),
            approvedApplication($employee, vacantPosition($assignment->organization_id, $assignment->organization_unit_id), $assignment->organization_id),
        ]],
        'transfer-position' => (function () use ($employee, $assignment) {
            $other = freshEmployee();
            $position = vacantPosition($assignment->organization_id, $assignment->organization_unit_id);

            return ['workers' => 2, 'position' => $position, 'employees' => [$employee->id, $other->id], 'applications' => [
                approvedApplication($employee, $position, $assignment->organization_id),
                approvedApplication($other, $position, $assignment->organization_id),
            ]];
        })(),
        'revoke', 'idempotency' => (function () use ($employee, $scenario) {
            $card = IdCard::query()->where('employee_id', $employee->id)->where('is_current', true)->where('status', 'active')->firstOrFail();
            $provider = ServiceProvider::query()->where('code', 'like', 'SC-SP-%')->firstOrFail();
            $payload = ['card' => $card->id, 'employee' => $employee->id, 'provider' => $provider->id, 'tag' => 'SC-RACE-'.Str::upper(Str::random(10))];
            if ($scenario === 'idempotency') {
                DB::table('entitlements')->insertOrIgnore(['id' => (string) Str::uuid7(), 'employee_id' => $employee->id,
                    'service_type_id' => ServiceType::query()->where('code', 'cafeteria')->value('id'), 'status' => 'active',
                    'quota_used' => 0, 'effective_from' => '2024-01-01', 'created_at' => now(), 'updated_at' => now()]);
                $raw = Str::random(40);
                DB::table('id_cards')->where('id', $card->id)->update(['token_hash' => hash('sha256', $raw)]);
                $payload['token'] = $card->id.'|'.$raw;
                $payload['bearer'] = raceApiToken($provider);
            }

            return $payload;
        })(),
        default => throw new InvalidArgumentException("Unknown scenario {$scenario}"),
    };
}

function raceApiToken(ServiceProvider $provider): string
{
    $application = ExternalApplication::query()->firstOrCreate(['code' => 'SC-RACE-CLIENT'], [
        'name' => 'Race harness (synthetic)', 'status' => 'active', 'organization_access' => 'city',
        'allowed_scopes' => ['id_cards.verify', 'service_eligibility.check', 'service_transactions.create'],
        'rate_limit_per_minute' => 100000, 'allowed_ips' => [], 'service_provider_id' => $provider->id,
    ]);
    $application->forceFill(['unrestricted_endpoints' => true, 'service_provider_id' => $provider->id, 'rate_limit_per_minute' => 100000])->save();

    return $application->createToken('race', $application->grantableScopes())->plainTextToken;
}

function prepareWorker(string $scenario, int $index, array $p): Closure
{
    $actor = actor();

    return match ($scenario) {
        'assignment' => fn () => DB::transaction(function () use ($p) {
            $assignment = EmployeeAssignment::query()->create(['employee_id' => $p['employee'], 'organization_id' => $p['organization'],
                'organization_unit_id' => $p['unit'], 'position_id' => $p['position'], 'assignment_status' => 'active',
                'effective_from' => now()->toDateString(), 'is_current' => true]);
            Employee::query()->whereKey($p['employee'])->update(['current_assignment_id' => $assignment->id]);

            return $assignment->id;
        }),
        'position' => fn () => app(RegisterEmployeeAction::class)->execute(
            ['first_name' => 'Race', 'last_name' => "Worker{$index}",
                'full_name' => "Race Worker {$index}", 'name_en' => "Race Worker {$index}", 'is_demo' => true],
            ['organization_id' => $p['organization'], 'organization_unit_id' => $p['unit'], 'position_id' => $p['position'], 'effective_from' => now()->toDateString()],
            $actor,
        )->id,
        'transfer-same', 'transfer-two', 'transfer-position' => (function () use ($p, $index, $actor) {
            $application = TransferApplication::query()->findOrFail($p['applications'][$index % count($p['applications'])]);

            return function () use ($application, $actor) {
                $before = $application->status;
                $done = app(CompleteTransferAction::class)->execute($application, $actor);

                return $done->status->value === 'transferred' && $before->value === 'transferred' ? 'replay' : $done->status->value;
            };
        })(),
        'revoke' => $index === 0
            ? (function () use ($p, $actor) {
                $card = IdCard::query()->findOrFail($p['card']);

                return function () use ($card, $actor) {
                    usleep(2000); // let some transactions get past their eligibility check first

                    return app(RevokeCardAction::class)->execute($card, $actor, 'race test')->status->value;
                };
            })()
            : (function () use ($p, $index) {
                $card = IdCard::query()->with('employee')->findOrFail($p['card']);
                $type = ServiceType::query()->where('code', 'cafeteria')->firstOrFail();
                $provider = ServiceProvider::query()->findOrFail($p['provider']);

                return fn () => app(RecordServiceTransactionAction::class)->execute($card->employee, $card, $type, $provider, null, 'authorized', null,
                    ['reference' => "{$p['tag']}-{$index}"])->id;
            })(),
        'idempotency' => function () use ($p) {
            $request = Request::create('/api/v1/services/cafeteria/transactions', 'POST', [], [], [], [
                'HTTP_AUTHORIZATION' => 'Bearer '.$p['bearer'], 'HTTP_ACCEPT' => 'application/json', 'HTTP_IDEMPOTENCY_KEY' => $p['tag'],
                'CONTENT_TYPE' => 'application/json', 'REMOTE_ADDR' => '127.0.0.1',
            ], json_encode(['token' => $p['token'], 'provider_code' => ServiceProvider::query()->findOrFail($p['provider'])->code]));
            $response = app(HttpKernel::class)->handle($request);
            $replay = $response->headers->get('Idempotent-Replay') === 'true';
            if ($response->getStatusCode() >= 300) {
                throw new RuntimeException('HTTP '.$response->getStatusCode().' '.Str::limit((string) $response->getContent(), 120));
            }

            return $replay ? 'replay' : 'HTTP '.$response->getStatusCode().' '.Str::limit((string) $response->getContent(), 300);
        },
    };
}

/** @return array{violations: int, note: string} */
function verifyScenario(string $scenario, array $p, array $results): array
{
    $currentFor = fn (string $employee): int => DB::table('employee_assignments')->where('employee_id', $employee)->where('is_current', true)->count();

    switch ($scenario) {
        case 'assignment':
            $n = $currentFor($p['employee']);
            $pointer = DB::table('employees as e')->join('employee_assignments as a', 'a.id', '=', 'e.current_assignment_id')
                ->where('e.id', $p['employee'])->value('a.is_current');

            return ['violations' => ($n === 1 ? 0 : 1) + ($pointer ? 0 : 1), 'note' => "current assignments={$n}, pointer current=".var_export((bool) $pointer, true)];
        case 'position':
            $n = DB::table('employee_assignments')->where('position_id', $p['position'])->where('is_current', true)->count();
            // The code rule numbered the new person like a real employee; re-tag it as synthetic.
            DB::table('employees')->whereIn('id', DB::table('employee_assignments')->where('position_id', $p['position'])->select('employee_id'))
                ->update(['employee_number' => DB::raw("'SC-RACE-' || substr(md5(random()::text), 1, 10)")]);

            return ['violations' => $n <= 1 ? 0 : 1, 'note' => "incumbents in single-slot position={$n}"];
        case 'transfer-same':
        case 'transfer-two':
        case 'transfer-position':
            $violations = 0;
            $notes = [];
            foreach ($p['employees'] as $employee) {
                $n = $currentFor($employee);
                $violations += $n === 1 ? 0 : 1;
                $notes[] = "current={$n}";
            }
            $transferred = DB::table('transfer_applications')->whereIn('id', $p['applications'])->where('status', 'transferred')->count();
            $transfers = DB::table('employee_transfers')->whereIn('transfer_application_id', $p['applications'])->count();
            if ($scenario === 'transfer-same') {
                $violations += ($transferred === 1 && $transfers === 1) ? 0 : 1;
            } else {
                $violations += $transferred <= 1 ? 0 : 1;
            }
            if (isset($p['position'])) {
                $occupants = DB::table('employee_assignments')->where('position_id', $p['position'])->where('is_current', true)->count();
                $violations += $occupants <= 1 ? 0 : 1;
                $notes[] = "occupants={$occupants}";
            }

            return ['violations' => $violations, 'note' => implode(' ', $notes)." transferred_apps={$transferred} transfer_rows={$transfers}"];
        case 'revoke':
            // Worker 0 revoked. An accepted transaction must have committed
            // before the revocation did; one committing after it is a revoked
            // card being accepted.
            $revokedCommit = $results[0]['ok'] ? $results[0]['committed_at'] : null;
            $accepted = array_filter(array_slice($results, 1, null, true), fn ($r) => $r['ok']);
            $after = count(array_filter($accepted, fn ($r) => $revokedCommit !== null && $r['committed_at'] > $revokedCommit));
            $recorded = DB::table('service_transactions')->where('id_card_id', $p['card'])->where('reference', 'like', $p['tag'].'%')->count();
            $before = count($accepted) - $after;

            return ['violations' => ($revokedCommit === null ? 1 : 0) + $after + ($recorded === count($accepted) ? 0 : 1),
                'note' => "accepted before revoke commit={$before}, accepted after revoke commit={$after}, rows={$recorded}"];
        case 'idempotency':
            $n = DB::table('service_transactions')->where('id_card_id', $p['card'])->where('created_at', '>=', now()->subMinutes(5))->count();
            $replays = count(array_filter($results, fn ($r) => ($r['detail'] ?? null) === 'replay'));

            return ['violations' => $n === 1 ? 0 : 1, 'note' => "service transactions recorded={$n}, replays={$replays}"];
    }

    return ['violations' => 0, 'note' => ''];
}
