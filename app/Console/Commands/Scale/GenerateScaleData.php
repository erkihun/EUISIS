<?php

declare(strict_types=1);

namespace App\Console\Commands\Scale;

use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Role;

/**
 * Synthetic, PII-free data for scale and load testing (docs/load-testing.md).
 *
 * Bulk-inserts organizations, units, positions, employees with a current
 * assignment and an active ID card, login accounts for load-test roles, and
 * accumulated history (daily activity, cafeteria transactions, audit rows,
 * notifications) so queries are measured on Day-N volume, not an empty DB.
 *
 * Every row is tagged (employee numbers SC-…, is_demo where the column exists)
 * and the command refuses to run in production. Not part of any seeder.
 */
class GenerateScaleData extends Command
{
    protected $signature = 'scale:generate
        {--employees=10000 : Employees to create}
        {--organizations=50 : Organizations to spread them across}
        {--units-per-org=10 : Organization units per organization}
        {--history-days=20 : Working days of accumulated history}
        {--daily-activity-rate=0.7 : Share of employees with a daily activity log per day}
        {--cafeteria-rate=0.6 : Share of employees with a cafeteria transaction per day}
        {--audit-per-day=1 : Audit rows per active employee per day}
        {--login-users=200 : Employee accounts load tests can sign in as}
        {--chunk=5000 : Rows per insert batch}
        {--password=Scale-Load-Test-2026! : Password for the generated load-test accounts}';

    protected $description = 'Generate synthetic, PII-free scale-test data (never in production).';

    private string $now;

    public function handle(): int
    {
        if (app()->environment('production')) {
            $this->error('scale:generate never runs in production.');

            return self::FAILURE;
        }
        if (DB::getDriverName() !== 'pgsql') {
            $this->error('scale:generate targets PostgreSQL (the production engine).');

            return self::FAILURE;
        }

        $this->now = now()->toDateTimeString();
        $employees = max(1, (int) $this->option('employees'));
        $chunk = max(100, (int) $this->option('chunk'));
        $started = microtime(true);

        $this->info("Generating {$employees} employees…");
        $orgs = $this->organizations((int) $this->option('organizations'), (int) $this->option('units-per-org'));
        [$cafeteria, $provider, $serviceType, $serviceProvider] = $this->cafeteria($orgs[0]['id']);
        $employeeIds = $this->employees($employees, $orgs, $chunk);
        $this->loginUsers((int) $this->option('login-users'), $orgs);
        $this->history($employeeIds, $chunk, $cafeteria, $provider, $serviceType, $serviceProvider);

        $this->info(sprintf('Done in %.1fs. Run ANALYZE before measuring.', microtime(true) - $started));
        DB::statement('ANALYZE');

        return self::SUCCESS;
    }

    /** @return list<array{id: string, units: list<string>}> */
    private function organizations(int $count, int $unitsPerOrg): array
    {
        $typeId = DB::table('organization_types')->where('code', 'SCALE')->value('id');
        if ($typeId === null) {
            $typeId = (string) Str::uuid7();
            DB::table('organization_types')->insert(['id' => $typeId, 'code' => 'SCALE', 'name_en' => 'Scale test', 'created_at' => $this->now, 'updated_at' => $this->now]);
        }

        $orgs = [];
        $run = Str::upper(Str::random(4));
        for ($o = 0; $o < $count; $o++) {
            $id = (string) Str::uuid7();
            DB::table('organizations')->insert([
                'id' => $id, 'organization_type_id' => $typeId, 'code' => "SC-{$run}-{$o}",
                'name_en' => "Scale Organization {$run}-{$o}", 'status' => 'active', 'is_demo' => true,
                'created_at' => $this->now, 'updated_at' => $this->now,
            ]);
            $units = [];
            $rows = [];
            for ($u = 0; $u < $unitsPerOrg; $u++) {
                $unitId = (string) Str::uuid7();
                $units[] = $unitId;
                $rows[] = ['id' => $unitId, 'organization_id' => $id, 'unit_type' => 'team', 'code' => "SC-{$run}-{$o}-U{$u}",
                    'name_en' => "Scale Unit {$o}-{$u}", 'status' => 'active', 'created_at' => $this->now, 'updated_at' => $this->now];
            }
            DB::table('organization_units')->insert($rows);
            $orgs[] = ['id' => $id, 'units' => $units];
        }

        return $orgs;
    }

    /**
     * @param  list<array{id: string, units: list<string>}>  $orgs
     * @return list<string>
     */
    private function employees(int $count, array $orgs, int $chunk): array
    {
        $first = ['Abebe', 'Almaz', 'Bekele', 'Chaltu', 'Dawit', 'Eden', 'Fikru', 'Genet', 'Hana', 'Kebede', 'Lemlem', 'Meron', 'Nahom', 'Rahel', 'Selam', 'Tesfaye'];
        $base = (int) (DB::table('employees')->where('employee_number', 'like', 'SC-%')->count());
        $employeeIds = [];
        $bar = $this->output->createProgressBar($count);

        for ($offset = 0; $offset < $count; $offset += $chunk) {
            $employees = $positions = $assignments = $cards = [];
            for ($i = $offset; $i < min($count, $offset + $chunk); $i++) {
                $n = $base + $i;
                $org = $orgs[$i % count($orgs)];
                $unit = $org['units'][$i % count($org['units'])];
                $employeeId = (string) Str::uuid7();
                $positionId = (string) Str::uuid7();
                $assignmentId = (string) Str::uuid7();
                $name = $first[$i % 16].' '.$first[($i * 7) % 16].' '.$first[($i * 13) % 16];
                $employeeIds[] = $employeeId;

                $positions[] = ['id' => $positionId, 'organization_id' => $org['id'], 'organization_unit_id' => $unit,
                    'job_position_code' => "SC-P-{$n}", 'title_en' => 'Scale Officer', 'is_active' => true,
                    'created_at' => $this->now, 'updated_at' => $this->now];
                $employees[] = ['id' => $employeeId, 'employee_number' => "SC-{$n}", 'first_name' => $first[$i % 16],
                    'last_name' => $first[($i * 13) % 16], 'full_name' => $name, 'name_en' => $name, 'status' => 'active',
                    'email' => "sc{$n}@scale.invalid", 'current_assignment_id' => $assignmentId, 'is_demo' => true,
                    'created_at' => $this->now, 'updated_at' => $this->now];
                $assignments[] = ['id' => $assignmentId, 'employee_id' => $employeeId, 'organization_id' => $org['id'],
                    'organization_unit_id' => $unit, 'position_id' => $positionId, 'assignment_status' => 'active',
                    'effective_from' => '2024-01-01', 'is_current' => true, 'created_at' => $this->now, 'updated_at' => $this->now];
                $cards[] = ['id' => (string) Str::uuid7(), 'employee_id' => $employeeId, 'card_number' => "SC-C-{$n}",
                    'status' => 'active', 'is_current' => true, 'public_card_uuid' => (string) Str::uuid(),
                    'token_hash' => hash('sha256', "scale-{$n}"), 'expires_at' => '2028-12-31 00:00:00',
                    'activated_at' => $this->now, 'created_at' => $this->now, 'updated_at' => $this->now];
            }
            DB::transaction(function () use ($positions, $employees, $assignments, $cards): void {
                DB::table('positions')->insert($positions);
                DB::table('employees')->insert($employees);
                DB::table('employee_assignments')->insert($assignments);
                DB::table('id_cards')->insert($cards);
            });
            $bar->advance(count($employees));
        }
        $bar->finish();
        $this->newLine();

        return $employeeIds;
    }

    /** @param list<array{id: string, units: list<string>}> $orgs */
    private function loginUsers(int $count, array $orgs): void
    {
        $password = Hash::make((string) $this->option('password'));
        $employeeRole = Role::query()->where('name', 'Employee')->where('guard_name', 'web')->value('id');
        $hrRole = Role::query()->where('name', 'HR Officer')->where('guard_name', 'web')->value('id');
        $employees = DB::table('employees')->where('employee_number', 'like', 'SC-%')
            ->whereNotIn('id', DB::table('users')->whereNotNull('employee_id')->select('employee_id'))
            ->orderBy('employee_number')->limit($count)->get(['id', 'employee_number', 'current_assignment_id']);

        foreach ($employees as $index => $employee) {
            $isHr = $index % 10 === 0; // every tenth account is a scoped HR officer
            $userId = DB::table('users')->insertGetId([
                'name' => 'Scale '.$employee->employee_number, 'email' => strtolower($employee->employee_number).'@scale.invalid',
                'password' => $password, 'employee_id' => $employee->id, 'status' => 'active', 'is_demo' => true,
                'must_change_password' => false, 'email_verified_at' => $this->now,
                'created_at' => $this->now, 'updated_at' => $this->now,
            ]);
            $roleId = $isHr ? $hrRole : $employeeRole;
            if ($roleId !== null) {
                DB::table('model_has_roles')->insert(['role_id' => $roleId, 'model_type' => 'App\\Models\\User', 'model_id' => $userId]);
            }
            if ($isHr) {
                $organizationId = DB::table('employee_assignments')->where('id', $employee->current_assignment_id)->value('organization_id');
                DB::table('user_organization_scopes')->insert([
                    'id' => (string) Str::uuid7(), 'user_id' => $userId, 'organization_id' => $organizationId,
                    'scope_type' => 'self', 'is_active' => true, 'effective_from' => '2024-01-01',
                    'created_at' => $this->now, 'updated_at' => $this->now,
                ]);
            }
        }
        $this->info('Load-test accounts: sc-<n>@scale.invalid (every tenth is a scoped HR Officer). Password: --password option.');
    }

    /** @return array{0: string, 1: string, 2: string, 3: string} cafeteria, provider, service type, service provider */
    private function cafeteria(string $organizationId): array
    {
        $serviceType = DB::table('service_types')->where('code', 'cafeteria')->value('id');
        if ($serviceType === null) {
            $serviceType = (string) Str::uuid7();
            DB::table('service_types')->insert(['id' => $serviceType, 'code' => 'cafeteria', 'name_en' => 'Cafeteria', 'is_active' => true, 'created_at' => $this->now, 'updated_at' => $this->now]);
        }
        $serviceProvider = (string) Str::uuid7();
        DB::table('service_providers')->insert(['id' => $serviceProvider, 'organization_id' => $organizationId, 'service_type_id' => $serviceType,
            'name' => 'Scale Cafeteria Service', 'code' => 'SC-SP-'.Str::upper(Str::random(6)), 'status' => 'active', 'is_demo' => true,
            'created_at' => $this->now, 'updated_at' => $this->now]);
        $cafeteria = (string) Str::uuid7();
        DB::table('cafeteria_providers')->insert(['id' => $cafeteria, 'code' => 'SC-CAF-'.Str::upper(Str::random(6)), 'name_en' => 'Scale Cafeteria',
            'organization_id' => $organizationId, 'is_active' => true, 'created_at' => $this->now, 'updated_at' => $this->now]);

        return [$cafeteria, '', $serviceType, $serviceProvider];
    }

    /** @param list<string> $employeeIds */
    private function history(array $employeeIds, int $chunk, string $cafeteria, string $provider, string $serviceType, string $serviceProvider): void
    {
        $days = (int) $this->option('history-days');
        $dailyRate = (float) $this->option('daily-activity-rate');
        $cafeteriaRate = (float) $this->option('cafeteria-rate');
        $auditPerDay = (int) $this->option('audit-per-day');

        $date = Carbon::today();
        $workingDays = [];
        while (count($workingDays) < $days) {
            $date = $date->subDay();
            if (! $date->isWeekend()) {
                $workingDays[] = $date->toDateString();
            }
        }

        $total = count($workingDays);
        $this->info("History: {$total} working days.");
        $bar = $this->output->createProgressBar($total);

        foreach ($workingDays as $dayIndex => $day) {
            foreach (array_chunk($employeeIds, $chunk) as $batch) {
                $context = DB::table('employee_assignments')->whereIn('employee_id', $batch)->where('is_current', true)
                    ->get(['employee_id', 'id', 'organization_id', 'organization_unit_id', 'position_id'])->keyBy('employee_id');
                $cards = DB::table('id_cards')->whereIn('employee_id', $batch)->where('is_current', true)->pluck('id', 'employee_id');
                $logs = $services = $transactions = $audits = [];

                foreach ($batch as $k => $employeeId) {
                    $c = $context[$employeeId] ?? null;
                    if ($c === null) {
                        continue;
                    }
                    $h = crc32($employeeId.$day);
                    if (($h % 1000) / 1000 < $dailyRate) {
                        $logs[] = ['id' => (string) Str::uuid7(), 'employee_id' => $employeeId, 'employee_assignment_id' => $c->id,
                            'organization_id' => $c->organization_id, 'organization_unit_id' => $c->organization_unit_id,
                            'position_id' => $c->position_id, 'activity_date' => $day, 'status' => 'approved',
                            'submitted_at' => "{$day} 17:00:00", 'first_submitted_at' => "{$day} 17:00:00", 'submission_count' => 1,
                            'created_at' => "{$day} 17:00:00", 'updated_at' => "{$day} 17:00:00"];
                    }
                    if ((($h >> 10) % 1000) / 1000 < $cafeteriaRate) {
                        $serviceId = (string) Str::uuid7();
                        $services[] = ['id' => $serviceId, 'employee_id' => $employeeId, 'id_card_id' => $cards[$employeeId] ?? null,
                            'service_type_id' => $serviceType, 'service_provider_id' => $serviceProvider, 'status' => 'authorized',
                            'occurred_at' => "{$day} 12:30:00", 'created_at' => "{$day} 12:30:00", 'updated_at' => "{$day} 12:30:00"];
                        $transactions[] = ['id' => (string) Str::uuid7(), 'service_transaction_id' => $serviceId,
                            'transaction_number' => 'SC-T-'.Str::upper(Str::random(12)), 'employee_id' => $employeeId,
                            'id_card_id' => $cards[$employeeId] ?? null, 'cafeteria_provider_id' => $cafeteria,
                            'transaction_date' => $day, 'scanned_at' => "{$day} 12:30:00", 'status' => 'accepted',
                            'meal_amount' => 120, 'subsidy_amount_applied' => 100, 'employee_payable_amount' => 20,
                            'employee_organization_id' => $c->organization_id, 'currency_code' => 'ETB',
                            'created_at' => "{$day} 12:30:00", 'updated_at' => "{$day} 12:30:00"];
                    }
                    for ($a = 0; $a < $auditPerDay; $a++) {
                        $audits[] = ['id' => (string) Str::uuid7(), 'event_type' => 'scale_synthetic_event', 'organization_id' => $c->organization_id,
                            'auditable_type' => 'App\\Models\\Employee', 'auditable_id' => $employeeId,
                            'request_id' => (string) Str::uuid7(), 'created_at' => "{$day} 09:00:00"];
                    }
                }

                DB::transaction(function () use ($logs, $services, $transactions, $audits): void {
                    foreach (array_chunk($logs, 2000) as $part) {
                        DB::table('daily_activity_logs')->insertOrIgnore($part);
                    }
                    foreach (array_chunk($services, 2000) as $part) {
                        DB::table('service_transactions')->insert($part);
                    }
                    foreach (array_chunk($transactions, 2000) as $part) {
                        DB::table('cafeteria_transactions')->insert($part);
                    }
                    foreach (array_chunk($audits, 2000) as $part) {
                        DB::table('audit_logs')->insert($part);
                    }
                });
            }
            $bar->advance();
        }
        $bar->finish();
        $this->newLine();
    }
}
