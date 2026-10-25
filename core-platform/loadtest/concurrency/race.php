<?php

declare(strict_types=1);

/*
 * Concurrency integrity on a staging or rehearsal database (docs/go-live/core/core-go-live-evidence.md §9).
 * NEVER production: it writes synthetic records.
 *
 *   cd <release>/backend && php race.php prepare <state.json> <scenario>     # makes the shared target
 *   php race.php run <state.json> <scenario> <worker>                         # one racing process
 *   php race.php check <state.json> <scenario>                                # the invariant afterwards
 *
 * Scenarios (each worker acts as its own synthetic officer, as different people would):
 *   seats     every worker registers an employee into the SAME 1-seat position: all may succeed,
 *             because a draft holds no seat (only an ACTIVE current placement does)
 *   approve   16 verified drafts for the SAME 1-seat position, approved at once by 16 different
 *             administrators: at most one may become active (the binding seat check)
 *   numbers   every worker registers into its own position: all succeed, employee numbers unique
 *   cardreq   every worker requests a card for the SAME eligible employee: one live request at most
 * The runner starts the workers at the same instant (a shared start time) and reports outcomes.
 */

use App\Actions\CardRequests;
use App\Actions\EmployeeVerification;
use App\Actions\RegisterEmployee;
use App\Models\Employee;
use App\Models\EmployeeDocument;
use App\Models\Organization;
use App\Models\Position;
use App\Models\User;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

require 'vendor/autoload.php';
$app = require 'bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
[$mode, $stateFile, $scenario] = [$argv[1] ?? '', $argv[2] ?? '', $argv[3] ?? ''];
$database = DB::connection()->getDatabaseName();
if (! preg_match('/staging|rehearsal|test/', $database)) {
    exit("refusing: {$database} is not a staging or rehearsal database\n");
}

$officer = function (int $i): User {
    return User::query()->firstOrCreate(['email' => "race-officer-{$i}@staging.invalid"], ['name' => "Race officer {$i}",
        'password' => bin2hex(random_bytes(16)), 'role' => 'core_officer', 'scope_type' => User::SCOPE_CITYWIDE, 'is_active' => true, 'must_change_password' => false]);
};
$position = function (string $label, int $seats): Position {
    $org = Organization::query()->orderBy('created_at')->firstOrFail();
    $position = Position::query()->create(['organization_id' => $org->id, 'job_position_code' => 'RACE-'.$label.'-'.Str::upper(Str::random(6)), 'title_en' => "Race {$label}"]);
    $position->establishments()->create(['establishment_number' => 'RACE-'.Str::upper(Str::random(8)), 'organization_id' => $org->id, 'approved_slots' => $seats,
        'effective_from' => '2020-01-01', 'status' => 'approved']);

    return $position;
};

if ($mode === 'prepare') {
    $state = ['scenario' => $scenario, 'start_at' => microtime(true) + 6];
    if ($scenario === 'seats') {
        $state['position'] = $position('SEAT', 1)->id;
    } elseif ($scenario === 'numbers') {
        $state['positions'] = array_map(fn () => $position('NUM', 2)->id, range(1, 16));
    } elseif ($scenario === 'approve') {
        $position = $position('APPROVE', 1);
        $state['position'] = $position->id;
        $state['employees'] = [];
        for ($i = 1; $i <= 16; $i++) {
            $registrar = $officer($i);
            $employee = app(RegisterEmployee::class)->execute(['first_name' => 'Race', 'last_name' => 'Approve '.Str::upper(Str::random(5)).$i,
                'date_of_birth' => sprintf('1985-%02d-%02d', ($i % 12) + 1, ($i % 27) + 1), 'position_id' => $position->id, 'effective_from' => '2025-01-01'], $registrar);
            app(EmployeeVerification::class)->submit($employee, $registrar);
            app(EmployeeVerification::class)->verify($employee->fresh(), $officer($i + 16),
                array_fill_keys(array_keys(app(EmployeeVerification::class)->checklist($employee->fresh())), true));
            foreach (['photo', 'signature'] as $kind) { // synthetic verified evidence rows, as the test fixtures make them
                $id = (string) Str::uuid7();
                (new EmployeeDocument)->forceFill(['id' => $id, 'employee_id' => $employee->id, 'kind' => $kind, 'document_type' => $kind, 'lineage_id' => $id, 'version' => 1,
                    'is_current' => true, 'status' => EmployeeDocument::VERIFIED, 'storage_disk' => 'evidence', 'storage_path' => "{$employee->id}/{$kind}/{$id}.png",
                    'original_filename' => "{$kind}.png", 'mime_type' => 'image/png', 'file_size' => 1, 'checksum' => hash('sha256', $id), 'width' => 600, 'height' => 800,
                    'uploaded_by' => $registrar->id, 'verified_by' => $officer($i + 16)->id, 'verified_at' => now()])->save();
            }
            $state['employees'][$i] = $employee->id;
        }
        for ($i = 1; $i <= 16; $i++) {
            User::query()->firstOrCreate(['email' => "race-approver-{$i}@staging.invalid"], ['name' => "Race approver {$i}", 'password' => bin2hex(random_bytes(16)),
                'role' => 'core_admin', 'scope_type' => User::SCOPE_CITYWIDE, 'is_active' => true, 'must_change_password' => false]);
        }
        $state['start_at'] = microtime(true) + 6;
    } elseif ($scenario === 'cardreq') {
        $employee = Employee::query()->where('status', Employee::ACTIVE)->whereDoesntHave('cards')->whereHas('documents', fn ($q) => $q->where('kind', 'photo')->where('status', 'verified'))
            ->orderBy('created_at')->first() ?? exit("no eligible employee without a card\n");
        $state['employee'] = $employee->id;
    }
    for ($i = 1; $i <= 16; $i++) {
        $officer($i);
    }
    file_put_contents($stateFile, json_encode($state));
    echo "prepared {$scenario}\n";
    exit(0);
}

$state = json_decode((string) file_get_contents($stateFile), true);
if ($mode === 'run') {
    $worker = (int) ($argv[4] ?? 1);
    $actor = $officer($worker);
    while (microtime(true) < $state['start_at']) {
        usleep(1000);
    }
    try {
        $result = match ($scenario) {
            'seats' => app(RegisterEmployee::class)->execute(['first_name' => 'Race', 'last_name' => "Seat {$worker}", 'date_of_birth' => '1990-01-0'.(($worker % 9) + 1),
                'position_id' => $state['position'], 'effective_from' => '2025-01-01'], $actor)->employee_number,
            'numbers' => app(RegisterEmployee::class)->execute(['first_name' => 'Race', 'last_name' => "Number {$worker}", 'date_of_birth' => '1991-02-0'.(($worker % 9) + 1),
                'position_id' => $state['positions'][$worker - 1], 'effective_from' => '2025-01-01'], $actor)->employee_number,
            'cardreq' => app(CardRequests::class)->submit(Employee::query()->findOrFail($state['employee']), 'new', 'race', $actor)->id,
            'approve' => app(EmployeeVerification::class)->approve(Employee::query()->findOrFail($state['employees'][$worker]),
                User::query()->where('email', "race-approver-{$worker}@staging.invalid")->firstOrFail())->status,
        };
        echo "worker {$worker}: ok {$result}\n";
    } catch (Throwable $e) {
        echo "worker {$worker}: refused ".class_basename($e).': '.Str::limit($e->getMessage(), 90)."\n";
    }
    exit(0);
}

if ($mode === 'check') {
    $report = match ($scenario) {
        'seats' => ['drafts registered' => DB::table('employee_assignments')->where('position_id', $state['position'])->where('is_current', true)->count(),
            'seats held (active placements)' => DB::table('employee_assignments')->where('position_id', $state['position'])->where('is_current', true)->where('assignment_status', 'active')->count(), 'allowed active' => 1],
        'approve' => ['seats held (active placements)' => DB::table('employee_assignments')->where('position_id', $state['position'])->where('is_current', true)->where('assignment_status', 'active')->count(),
            'active employees' => DB::table('employees')->whereIn('id', array_values($state['employees']))->where('status', 'active')->count(), 'allowed' => 1],
        'numbers' => ['employees made' => DB::table('employees')->where('last_name', 'like', 'Number %')->where('first_name', 'Race')->count(),
            'duplicate employee numbers (whole table)' => DB::table('employees')->select('employee_number')->groupBy('employee_number')->havingRaw('count(*) > 1')->get()->count(), 'allowed' => 0],
        'cardreq' => ['open card requests for the employee' => DB::table('card_requests')->where('employee_id', $state['employee'])->whereNotIn('status', ['rejected', 'cancelled', 'completed'])->count(), 'allowed' => 1],
    };
    echo json_encode($report), "\n";
}
