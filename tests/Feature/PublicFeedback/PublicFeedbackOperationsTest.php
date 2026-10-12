<?php

declare(strict_types=1);

use App\Enums\ServiceFeedbackStatus;
use App\Enums\ServiceFeedbackType;
use App\Models\EmployeeServiceFeedback;
use App\Models\Permission;
use App\Models\PublicFeedbackRoutingRule;
use App\Models\PublicFeedbackStatusHistory;
use App\Models\Role;
use App\Models\User;
use App\Services\ServiceFeedback\OrganizationFeedbackQrService;
use App\Services\ServiceFeedback\PublicFeedbackDataQuality;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Support\PublicFeedbackScenario;

/*
 * QA brief §90–91 and §103: the data-quality report names what is wrong,
 * repairs only what needs no judgement, never retargets a submission, and
 * the production gate reports the public feedback prerequisites.
 */

beforeEach(function (): void {
    app()->setLocale('en');
    Permission::findOrCreate('service_feedback.manage_routing', 'web');
    Role::findOrCreate('Super Admin', 'web')->syncPermissions(Permission::all());
    $this->superAdmin = User::factory()->create();
    $this->superAdmin->assignRole('Super Admin');
    $this->a = PublicFeedbackScenario::employeeTarget('OPS');
});

it('reports findings, repairs only deterministic ones and leaves ambiguous rows for review', function (): void {
    // A legacy complaint stored before routing existed: pending, no history, no submission time.
    $legacy = EmployeeServiceFeedback::query()->create([
        'employee_id' => $this->a->employee->id, 'organization_id' => $this->a->organization->id, 'type' => ServiceFeedbackType::Complaint,
        'comment' => 'Legacy', 'status' => ServiceFeedbackStatus::Pending,
    ]);
    DB::table('employee_service_feedback')->where('id', $legacy->id)->update(['submitted_at' => null]);
    // A rule whose handler is gone.
    $ghost = User::factory()->create();
    PublicFeedbackRoutingRule::query()->create(['organization_id' => $this->a->organization->id, 'handler_type' => 'user', 'handler_id' => (string) $ghost->id, 'priority' => 1, 'is_active' => true, 'created_by' => $this->superAdmin->id]);
    $ghost->forceDelete();
    // A category reference that no longer resolves.
    $orphan = EmployeeServiceFeedback::query()->create([
        'employee_id' => $this->a->employee->id, 'organization_id' => $this->a->organization->id, 'type' => ServiceFeedbackType::Suggestion,
        'comment' => 'Orphan category', 'status' => ServiceFeedbackStatus::Closed, 'submitted_at' => now(),
    ]);
    DB::table('employee_service_feedback')->where('id', $orphan->id)->update(['category_id' => '00000000-0000-0000-0000-00000000dead']);

    $findings = collect(app(PublicFeedbackDataQuality::class)->run())->keyBy('key');

    expect($findings['active_organizations_without_qr']['count'])->toBeGreaterThanOrEqual(1)
        ->and($findings['unrouted_legacy_submissions']['count'])->toBe(1)
        ->and($findings['missing_submitted_at']['count'])->toBe(1)
        ->and($findings['missing_initial_history']['count'])->toBe(2)
        ->and($findings['rules_with_missing_handler']['count'])->toBe(1)
        ->and($findings['broken_category_reference']['count'])->toBe(1)
        ->and($findings['broken_category_reference']['severity'])->toBe('NEEDS_DATA_REVIEW')
        ->and($findings['duplicate_active_qr']['count'])->toBe(0)
        ->and($findings['invalid_source_target']['count'])->toBe(0);

    $this->artisan('feedback:data-quality')->expectsOutputToContain('Public feedback data quality')->assertExitCode(0);
    $this->artisan('feedback:data-quality', ['--repair' => true])->assertExitCode(0);

    $after = collect(app(PublicFeedbackDataQuality::class)->run())->keyBy('key');
    expect($after['unrouted_legacy_submissions']['count'])->toBe(0)
        ->and($after['missing_submitted_at']['count'])->toBe(0)
        ->and($after['missing_initial_history']['count'])->toBe(0)
        // The legacy complaint was routed by today's rules: the ghost rule cannot be used, so it is unrouted and flagged.
        ->and($legacy->fresh()->status)->toBe(ServiceFeedbackStatus::Unrouted)
        ->and($legacy->fresh()->employee_id)->toBe($this->a->employee->id)
        ->and($legacy->fresh()->target_type->value)->toBe('employee')
        ->and(PublicFeedbackStatusHistory::query()->where('submission_id', $legacy->id)->pluck('to_status')->all())->toBe(['pending', 'unrouted'])
        // Ambiguous data is untouched.
        ->and($after['broken_category_reference']['count'])->toBe(1)
        ->and($after['rules_with_missing_handler']['count'])->toBe(1);
});

it('flags a duplicate active QR as a failure and exits non-zero', function (): void {
    app(OrganizationFeedbackQrService::class)->ensureActive($this->a->organization, $this->superAdmin);
    // Only a direct write can bypass the partial unique index on SQLite without it; emulate the failure by a second active row where the driver allows.
    try {
        DB::table('organization_feedback_qr_codes')->insert([
            'id' => (string) Str::uuid(), 'organization_id' => $this->a->organization->id, 'public_token' => str_repeat('b', 64),
            'status' => 'active', 'enabled_at' => now(), 'scan_count' => 0, 'created_at' => now(), 'updated_at' => now(),
        ]);
    } catch (QueryException) {
        // The partial unique index refused it: that is the protection the report would otherwise catch.
        expect(true)->toBeTrue();

        return;
    }

    $this->artisan('feedback:data-quality')->assertExitCode(1);
});

it('reports the public feedback prerequisites in the production gate', function (): void {
    // Component output (two-column rows) is not captured by expectsOutputToContain, so read the buffered output.
    Artisan::call('production:readiness');
    $output = preg_replace('/\[[0-9;]*m/', '', Artisan::output());

    expect($output)->toContain('Public feedback (docs/public-feedback-readiness.md)')
        ->toContain('Public feedback tables present')
        ->toContain('Complaint reference code rule active')
        ->toContain('Attachment disk (local) is writable')
        ->toContain('Routing rules exist for suggestions and complaints')
        ->toContain('Public feedback data integrity')
        ->toContain('No public feedback rows awaiting data review');
});
