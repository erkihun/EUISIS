<?php

declare(strict_types=1);

use Modules\Court\Domain\Models\CourtStaffMember as User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

function auditViewerAdmin(): User
{
    $user = User::factory()->create([
        'name' => 'ICT Support',
        'email_verified_at' => now(),
    ]);
    $roleId = DB::table('court_roles')->insertGetId([
        'name' => 'admin',
        'description' => 'Test administrator',
        'created_at' => now(),
        'updated_at' => now(),
    ]);
    DB::table('court_role_user')->insert([
        'user_id' => $user->id,
        'role_id' => $roleId,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    return $user;
}

function seedAllAuditSources(User $actor): void
{
    DB::table('court_system_audits')->insert([
        'request_id' => 'req-system-001',
        'user_id' => $actor->id,
        'actor_type' => 'user',
        'action' => 'audit.system.example',
        'outcome' => 'failure',
        'module' => 'court.audit',
        'route' => 'audit.system.example',
        'method' => 'POST',
        'response_status' => 422,
        'ip' => '127.0.0.1',
        'user_agent' => 'Pest',
        'context' => json_encode(['input' => ['password' => '[redacted]']]),
        'created_at' => now()->subMinutes(3),
        'updated_at' => now()->subMinutes(3),
    ]);

    $caseTypeId = DB::table('court_case_types')->insertGetId([
        'name' => 'Audit test type',
        'description' => null,
        'created_at' => now(),
        'updated_at' => now(),
    ]);
    $caseId = DB::table('court_cases')->insertGetId([
        'case_number' => 'AUDIT-CASE-001',
        'title' => 'Audit viewer test',
        'case_type_id' => $caseTypeId,
        'filing_date' => now()->toDateString(),
        'status' => 'pending',
        'created_at' => now(),
        'updated_at' => now(),
    ]);
    DB::table('court_case_audits')->insert([
        'case_id' => $caseId,
        'action' => 'audit_case_example',
        'actor_type' => 'user',
        'actor_id' => $actor->id,
        'meta' => json_encode(['status' => 'reviewed']),
        'created_at' => now()->subMinutes(2),
    ]);

    DB::table('court_audits')->insert([
        'user_type' => User::class,
        'user_id' => $actor->id,
        'event' => 'updated',
        'auditable_type' => User::class,
        'auditable_id' => $actor->id,
        'old_values' => json_encode(['name' => 'Before']),
        'new_values' => json_encode(['name' => 'After']),
        'url' => '/court/admin/users/'.$actor->id,
        'ip_address' => '127.0.0.1',
        'user_agent' => 'Pest',
        'tags' => 'identity',
        'created_at' => now()->subMinute(),
        'updated_at' => now()->subMinute(),
    ]);
}

test('authorized administrator sees every audit source and evidence field', function (): void {
    $admin = auditViewerAdmin();
    seedAllAuditSources($admin);

    $this->actingAs($admin)
        ->get(route('court.admin.audit'))
        ->assertOk()
        // The page (Court/Audit/Index) draws every source and evidence field from these props.
        ->assertInertia(fn (\Inertia\Testing\AssertableInertia $page) => $page
            ->component('Court/Audit/Index')
            ->where('events.data', function ($rows): bool {
                $rows = collect($rows);

                return $rows->contains(fn ($row) => $row['action'] === 'audit.system.example' && $row['request_id'] === 'req-system-001' && $row['response_status'] === 422)
                    && $rows->contains(fn ($row) => $row['action'] === 'audit_case_example' && $row['target_label'] === 'AUDIT-CASE-001')
                    && $rows->contains(fn ($row) => $row['source_label'] === 'Model history');
            }));
});

test('audit filters apply across the unified timeline', function (): void {
    $admin = auditViewerAdmin();
    seedAllAuditSources($admin);

    $this->actingAs($admin)
        ->get(route('court.admin.audit', ['source' => 'case']))
        ->assertOk()
        ->assertInertia(fn (\Inertia\Testing\AssertableInertia $page) => $page
            ->where('filters.source', 'case')
            ->where('events.data', fn ($rows) => collect($rows)->pluck('action')->contains('audit_case_example')
                && ! collect($rows)->pluck('action')->contains('audit.system.example')));
});

test('destructive activity states who deleted which record', function (): void {
    $admin = auditViewerAdmin();
    $requestId = 'req-letter-delete-001';
    $now = now();

    DB::table('court_system_audits')->insert([
        [
            'request_id' => $requestId,
            'user_id' => $admin->id,
            'actor_type' => 'user',
            'action' => 'model.deleted',
            'outcome' => 'success',
            'module' => 'letter_template',
            'route' => 'court.letter-templates.destroy',
            'method' => 'DELETE',
            'response_status' => null,
            'ip' => '127.0.0.1',
            'user_agent' => 'Pest',
            'context' => json_encode([
                'model' => 'Modules\\Court\\Domain\\Models\\LetterTemplate',
                'model_id' => 7,
                'old_values' => ['id' => 7, 'title' => 'Notice Template'],
                'new_values' => [],
            ]),
            'created_at' => $now,
            'updated_at' => $now,
        ],
        [
            'request_id' => $requestId,
            'user_id' => $admin->id,
            'actor_type' => 'user',
            'action' => 'court.letter-templates.destroy',
            'outcome' => 'success',
            'module' => 'letter-templates',
            'route' => 'court.letter-templates.destroy',
            'method' => 'DELETE',
            'response_status' => 302,
            'ip' => '127.0.0.1',
            'user_agent' => 'Pest',
            'context' => json_encode([
                'path' => 'admin/letter-templates/7',
                'route_parameters' => ['letter_template' => 7],
            ]),
            'created_at' => $now,
            'updated_at' => $now,
        ],
    ]);

    $this->actingAs($admin)
        ->get(route('court.admin.audit', ['search' => $requestId]))
        ->assertOk()
        ->assertInertia(fn (\Inertia\Testing\AssertableInertia $page) => $page
            ->where('events.data', fn ($rows) => collect($rows)->contains(fn ($row) => $row['action_label'] === 'Deleted Letter Template'
                && $row['description'] === 'ICT Support: Deleted Letter Template - Letter Template "Notice Template" #7.')));
});

test('staff without audit permission cannot open the audit trail', function (): void {
    $user = User::factory()->create(['email_verified_at' => now()]);

    $this->actingAs($user)
        ->get(route('court.admin.audit'))
        ->assertRedirect(route('court.dashboard'));
});
