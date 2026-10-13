<?php

declare(strict_types=1);

namespace App\Console\Commands\Scale;

use App\Models\Employee;
use App\Models\Organization;
use App\Models\Permission;
use App\Models\PublicFeedbackCategory;
use App\Models\Role;
use App\Services\ServiceFeedback\EmployeeFeedbackTokenService;
use App\Services\ServiceFeedback\OrganizationFeedbackQrService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * Synthetic, PII-free public feedback data for a scale database built by
 * scale:generate (QA brief §70). Adds institution QR codes, city-wide
 * categories, one handler account and routing rule per institution, employee
 * feedback tokens (with one position service each so a rating can be
 * submitted) and a configurable number of submissions spread over the six
 * target × type combinations. Everything except the submissions is
 * idempotent; submissions are appended on every run.
 */
class SeedPublicFeedbackScale extends Command
{
    protected $signature = 'feedback:scale-seed
        {--employee-tokens=2000 : Employees given an active feedback QR token (each gets one position service)}
        {--categories=8 : City-wide categories to ensure}
        {--submissions=20000 : Submissions to add, spread over the six target × type combinations}
        {--chunk=1000 : Insert chunk size}
        {--password=Scale-Load-Test-2026! : Password for the fb-handler-<n>@scale.invalid accounts}';

    protected $description = 'Add synthetic, PII-free public feedback data to a scale database (never in production).';

    public const HANDLER_PERMISSIONS = [
        'service_feedback.view', 'service_feedback.complaints.view', 'service_feedback.complaints.review',
        'service_feedback.assign', 'service_feedback.reassign', 'service_feedback.resolve', 'service_feedback.close',
        'service_feedback.view_employee_target', 'service_feedback.view_organization_target',
    ];

    private string $now;

    public function handle(EmployeeFeedbackTokenService $tokens, OrganizationFeedbackQrService $qr): int
    {
        if (app()->environment('production')) {
            $this->error('feedback:scale-seed never runs in production.');

            return self::FAILURE;
        }
        if (DB::getDriverName() !== 'pgsql') {
            $this->error('feedback:scale-seed targets PostgreSQL (the production engine).');

            return self::FAILURE;
        }

        $this->now = now()->toDateTimeString();

        $organizations = $this->organizations($qr);
        $categories = $this->categories((int) $this->option('categories'));
        $handlers = $this->handlers($organizations);
        $employees = $this->employeeTokens($tokens, (int) $this->option('employee-tokens'));
        $added = $this->submissions((int) $this->option('submissions'), (int) $this->option('chunk'), $organizations, $categories, $handlers, $employees);

        $this->info(sprintf(
            'Institutions with QR: %d; categories: %d; handlers/rules: %d; employee tokens: %d; submissions added: %d.',
            count($organizations), count($categories), count($handlers), count($employees), $added,
        ));
        $this->info('Handler accounts: fb-handler-<n>@scale.invalid (scoped to one institution). Password: --password option.');

        return self::SUCCESS;
    }

    /** @return array<string, array{id: string, code: string, name: string, qr_id: string, token: string}> */
    private function organizations(OrganizationFeedbackQrService $qr): array
    {
        $result = [];
        foreach (Organization::query()->where('status', 'active')->orderBy('code')->get() as $organization) {
            $code = $qr->ensureActive($organization);
            $result[$organization->id] = [
                'id' => $organization->id, 'code' => (string) $organization->code, 'name' => (string) $organization->name_en,
                'qr_id' => $code->id, 'token' => $code->public_token,
            ];
        }
        $this->line('Institution QR codes ensured: '.count($result));

        return $result;
    }

    /** @return list<string> category ids */
    private function categories(int $count): array
    {
        $ids = [];
        for ($n = 1; $n <= $count; $n++) {
            $ids[] = PublicFeedbackCategory::query()->firstOrCreate(
                ['organization_id' => null, 'code' => 'SC-CAT-'.$n],
                [
                    'name_en' => 'Scale category '.$n, 'name_am' => 'የመለኪያ ምድብ '.$n,
                    'submission_types' => ['suggestion', 'complaint'], 'target_types' => ['employee', 'organization'],
                    'is_active' => true, 'sort_order' => $n,
                ],
            )->id;
        }

        return $ids;
    }

    /**
     * One handler account per institution, in a "Service Quality Team" role
     * with the complaint-handling permissions, scoped to that institution,
     * and one routing rule sending the institution's suggestions and
     * complaints to it.
     *
     * @param  array<string, array{id: string, code: string, name: string, qr_id: string, token: string}>  $organizations
     * @return array<string, array{user_id: int, name: string, rule_id: string}> keyed by organization id
     */
    private function handlers(array $organizations): array
    {
        $role = Role::query()->firstOrCreate(['name' => 'Service Quality Team', 'guard_name' => 'web']);
        $role->syncPermissions(Permission::query()->whereIn('name', self::HANDLER_PERMISSIONS)->where('guard_name', 'web')->get());
        $password = Hash::make((string) $this->option('password'));

        $handlers = [];
        $n = 0;
        foreach ($organizations as $organization) {
            $n++;
            $email = 'fb-handler-'.$n.'@scale.invalid';
            $name = 'Scale Handler '.$n;
            $userId = DB::table('users')->where('email', $email)->value('id');
            if ($userId === null) {
                $userId = DB::table('users')->insertGetId([
                    'name' => $name, 'email' => $email, 'password' => $password, 'status' => 'active', 'is_demo' => true,
                    'must_change_password' => false, 'email_verified_at' => $this->now, 'created_at' => $this->now, 'updated_at' => $this->now,
                ]);
            }
            $hasRole = DB::table('model_has_roles')->where('role_id', $role->id)->where('model_type', 'App\\Models\\User')->where('model_id', $userId)->exists();
            if (! $hasRole) {
                DB::table('model_has_roles')->insert(['role_id' => $role->id, 'model_type' => 'App\\Models\\User', 'model_id' => $userId]);
            }
            $scoped = DB::table('user_organization_scopes')->where('user_id', $userId)->where('organization_id', $organization['id'])->exists();
            if (! $scoped) {
                DB::table('user_organization_scopes')->insert([
                    'id' => (string) Str::uuid7(), 'user_id' => $userId, 'organization_id' => $organization['id'],
                    'scope_type' => 'self', 'is_active' => true, 'effective_from' => '2024-01-01',
                    'created_at' => $this->now, 'updated_at' => $this->now,
                ]);
            }
            $ruleId = DB::table('public_feedback_routing_rules')
                ->where('organization_id', $organization['id'])->where('handler_type', 'user')->where('handler_id', (string) $userId)->value('id');
            if ($ruleId === null) {
                $ruleId = (string) Str::uuid7();
                DB::table('public_feedback_routing_rules')->insert([
                    'id' => $ruleId, 'organization_id' => $organization['id'], 'handler_type' => 'user', 'handler_id' => (string) $userId,
                    'priority' => 100, 'is_active' => true, 'created_at' => $this->now, 'updated_at' => $this->now,
                ]);
            }
            $handlers[$organization['id']] = ['user_id' => (int) $userId, 'name' => $name, 'rule_id' => (string) $ruleId];
        }
        $this->line('Handler accounts and routing rules ensured: '.count($handlers));

        return $handlers;
    }

    /**
     * @return list<array{employee_id: string, number: string, name: string, token_id: string, token: string, assignment_id: string, organization_id: string, unit_id: ?string, position_id: string, service_id: string}>
     */
    private function employeeTokens(EmployeeFeedbackTokenService $tokens, int $count): array
    {
        $result = [];
        $employees = Employee::query()
            ->where('employee_number', 'like', 'SC-%')->where('status', 'active')->whereNotNull('current_assignment_id')
            ->orderBy('employee_number')->limit($count)->get();
        $bar = $this->output->createProgressBar($employees->count());

        foreach ($employees as $employee) {
            $assignment = DB::table('employee_assignments')->where('id', $employee->current_assignment_id)->first(['id', 'organization_id', 'organization_unit_id', 'position_id']);
            if ($assignment === null || $assignment->position_id === null) {
                $bar->advance();

                continue;
            }
            $serviceId = DB::table('position_services')->where('position_id', $assignment->position_id)->whereNull('deleted_at')->value('id');
            if ($serviceId === null) {
                $serviceId = (string) Str::uuid7();
                DB::table('position_services')->insert([
                    'id' => $serviceId, 'organization_id' => $assignment->organization_id, 'position_id' => $assignment->position_id,
                    'service_no' => 'SC-SVC-'.substr((string) $employee->employee_number, 3), 'name_en' => 'Service desk', 'name_am' => 'የአገልግሎት ጠረጴዛ',
                    'created_at' => $this->now, 'updated_at' => $this->now,
                ]);
            }
            $token = $tokens->ensureActiveToken($employee);
            $result[] = [
                'employee_id' => $employee->id, 'number' => (string) $employee->employee_number, 'name' => (string) $employee->full_name,
                'token_id' => $token->id, 'token' => $token->token, 'assignment_id' => $assignment->id,
                'organization_id' => $assignment->organization_id, 'unit_id' => $assignment->organization_unit_id,
                'position_id' => $assignment->position_id, 'service_id' => (string) $serviceId,
            ];
            $bar->advance();
        }
        $bar->finish();
        $this->newLine();
        $this->line('Employee feedback tokens ensured: '.count($result));

        return $result;
    }

    /**
     * @param  array<string, array{id: string, code: string, name: string, qr_id: string, token: string}>  $organizations
     * @param  list<string>  $categories
     * @param  array<string, array{user_id: int, name: string, rule_id: string}>  $handlers
     * @param  list<array<string, mixed>>  $employees
     */
    private function submissions(int $count, int $chunk, array $organizations, array $categories, array $handlers, array $employees): int
    {
        if ($count <= 0 || $employees === [] || $organizations === []) {
            return 0;
        }
        $organizationList = array_values($organizations);
        $base = (int) DB::table('employee_service_feedback')->where('reference_number', 'like', 'PSC-SCALE-%')->count();
        $types = ['feedback', 'suggestion', 'complaint'];
        $bar = $this->output->createProgressBar($count);
        $added = 0;

        for ($offset = 0; $offset < $count; $offset += $chunk) {
            $rows = $history = $assignments = [];
            $limit = min($chunk, $count - $offset);
            for ($i = 0; $i < $limit; $i++) {
                $n = $base + $offset + $i + 1;
                $combo = $n % 6;
                $type = $types[$combo % 3];
                $isEmployee = $combo < 3;
                $id = (string) Str::uuid7();
                $submittedAt = now()->subMinutes(random_int(0, 90 * 24 * 60));
                $status = $this->status($type);
                $employee = $employees[array_rand($employees)];
                $organization = $isEmployee ? $organizations[$employee['organization_id']] ?? $organizationList[array_rand($organizationList)] : $organizationList[array_rand($organizationList)];
                $contextOrganizationId = $isEmployee ? $employee['organization_id'] : $organization['id'];
                $handler = $handlers[$contextOrganizationId] ?? null;

                $rows[] = [
                    'id' => $id,
                    'employee_id' => $isEmployee ? $employee['employee_id'] : null,
                    'organization_target_id' => $isEmployee ? null : $organization['id'],
                    'target_type' => $isEmployee ? 'employee' : 'organization',
                    'source_type' => $isEmployee ? 'employee_card_qr' : 'organization_feedback_qr',
                    'source_organization_qr_id' => $isEmployee ? null : $organization['qr_id'],
                    'employee_feedback_token_id' => $isEmployee ? $employee['token_id'] : null,
                    'employee_assignment_id' => $isEmployee ? $employee['assignment_id'] : null,
                    'target_name_snapshot' => $isEmployee ? $employee['name'] : $organization['name'],
                    'target_code_snapshot' => $isEmployee ? $employee['number'] : $organization['code'],
                    'category_id' => $type === 'feedback' ? null : $categories[array_rand($categories)],
                    'submitter_identity_mode' => 'anonymous',
                    'type' => $type,
                    'reference_number' => $type === 'complaint' ? 'PSC-SCALE-'.str_pad((string) $n, 7, '0', STR_PAD_LEFT) : null,
                    'organization_id' => $contextOrganizationId,
                    'organization_unit_id' => $isEmployee ? $employee['unit_id'] : null,
                    'position_id' => $isEmployee ? $employee['position_id'] : null,
                    'position_service_id' => $isEmployee && $type === 'feedback' ? $employee['service_id'] : null,
                    'service_no_snapshot' => $isEmployee && $type === 'feedback' ? 'SC-SVC' : null,
                    'service_name_snapshot' => $isEmployee && $type === 'feedback' ? 'Service desk' : null,
                    'rating' => $type === 'feedback' ? random_int(1, 5) : null,
                    'comment' => 'Synthetic scale submission '.$n.': the counter service took longer than expected.',
                    'status' => $status,
                    'submitted_at' => $submittedAt,
                    'assigned_at' => in_array($status, ['pending', 'reviewed'], true) ? null : $submittedAt->copy()->addMinute(),
                    'resolved_at' => in_array($status, ['resolved', 'accepted', 'closed'], true) ? $submittedAt->copy()->addDay() : null,
                    'closed_at' => $status === 'closed' ? $submittedAt->copy()->addDays(2) : null,
                    'created_at' => $submittedAt,
                    'updated_at' => $submittedAt,
                ];

                $from = null;
                $step = 0;
                foreach ($this->chain($type, $status) as $to) {
                    $history[] = [
                        'id' => (string) Str::uuid7(), 'submission_id' => $id, 'from_status' => $from, 'to_status' => $to,
                        'actor_id' => $to === 'pending' || $to === 'assigned' ? null : $handler['user_id'] ?? null,
                        'transitioned_at' => $submittedAt->copy()->addMinutes($step * 30),
                    ];
                    $from = $to;
                    $step++;
                }

                if ($type !== 'feedback' && $status !== 'pending' && $handler !== null) {
                    $assignments[] = [
                        'id' => (string) Str::uuid7(), 'submission_id' => $id, 'handler_type' => 'user', 'handler_id' => (string) $handler['user_id'],
                        'handler_name_snapshot' => $handler['name'], 'routing_rule_id' => $handler['rule_id'],
                        'assigned_at' => $submittedAt->copy()->addMinute(),
                    ];
                }
            }

            DB::transaction(function () use ($rows, $history, $assignments): void {
                DB::table('employee_service_feedback')->insert($rows);
                foreach (array_chunk($history, 1000) as $part) {
                    DB::table('public_feedback_status_history')->insert($part);
                }
                if ($assignments !== []) {
                    DB::table('public_feedback_assignments')->insert($assignments);
                }
            });
            $added += count($rows);
            $bar->advance(count($rows));
        }
        $bar->finish();
        $this->newLine();

        return $added;
    }

    private function status(string $type): string
    {
        $r = random_int(1, 100);
        if ($type === 'feedback') {
            return $r <= 70 ? 'pending' : 'reviewed';
        }
        if ($r <= 55) {
            return 'assigned';
        }
        if ($r <= 75) {
            return 'under_review';
        }
        if ($r <= 90) {
            return $type === 'complaint' ? 'resolved' : 'accepted';
        }

        return 'closed';
    }

    /** @return list<string> */
    private function chain(string $type, string $status): array
    {
        $final = $type === 'complaint' ? 'resolved' : 'accepted';

        return match ($status) {
            'pending' => ['pending'],
            'reviewed' => ['pending', 'reviewed'],
            'assigned' => ['pending', 'assigned'],
            'under_review' => ['pending', 'assigned', 'under_review'],
            'resolved', 'accepted' => ['pending', 'assigned', 'under_review', $final],
            default => ['pending', 'assigned', 'under_review', $final, 'closed'],
        };
    }
}
