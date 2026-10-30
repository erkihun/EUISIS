<?php

declare(strict_types=1);

use App\Enums\EmployeeStatus;
use App\Enums\ServiceFeedbackStatus;
use App\Enums\ServiceFeedbackType;
use App\Models\EmployeeServiceFeedback;
use App\Models\Permission;
use App\Models\PublicFeedbackReferral;
use App\Models\Role;
use App\Models\User;
use App\Services\Dashboard\DashboardMetricService;
use App\Services\ServiceFeedback\PublicFeedbackReferralService;
use App\Services\ServiceFeedback\PublicFeedbackWorkflow;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Modules\Performance\Application\Services\SystemKpiSourceRegistry;
use Tests\Support\PublicFeedbackScenario;

/*
 * QA brief §2, §38–39, §53–54: public submissions touch no other domain.
 * The only performance link is the opt-in KPI source that reads ratings;
 * suggestions and complaints never enter it, and no workflow step changes
 * the employee. The platform dashboard counts ratings only.
 */

beforeEach(function (): void {
    app()->setLocale('en');
    Permission::findOrCreate('service_feedback.refer_discipline', 'web');
    Permission::findOrCreate('service_feedback.complaints.view', 'web');
    Role::findOrCreate('Super Admin', 'web')->syncPermissions(Permission::all());
    $this->superAdmin = User::factory()->create();
    $this->superAdmin->assignRole('Super Admin');
    $this->a = PublicFeedbackScenario::employeeTarget('BND');
});

function bndRow(ServiceFeedbackType $type, array $overrides = []): EmployeeServiceFeedback
{
    return EmployeeServiceFeedback::query()->create(array_merge([
        'employee_id' => test()->a->employee->id, 'organization_id' => test()->a->organization->id, 'organization_unit_id' => test()->a->unit->id,
        'position_id' => test()->a->position->id, 'position_service_id' => test()->a->positionService->id, 'type' => $type,
        'comment' => 'Boundary '.$type->value, 'status' => ServiceFeedbackStatus::Pending, 'submitted_at' => now(),
    ], $overrides));
}

it('feeds only ratings into the opt-in performance KPI source, never suggestions or complaints', function (): void {
    bndRow(ServiceFeedbackType::Feedback, ['rating' => 4]);
    bndRow(ServiceFeedbackType::Feedback, ['rating' => 2]);
    bndRow(ServiceFeedbackType::Suggestion);
    bndRow(ServiceFeedbackType::Complaint, ['rating' => 1]); // a rating smuggled onto a complaint must still not count

    $measured = app(SystemKpiSourceRegistry::class)->measure(
        SystemKpiSourceRegistry::SERVICE_FEEDBACK, $this->a->organization->id, $this->a->employee->id,
        Carbon::now()->subDay(), Carbon::now()->addDay(),
    );

    expect($measured['denominator'])->toBe('2')
        ->and($measured['numerator'])->toBe('6')
        ->and(DB::table('kpi_actuals')->count())->toBe(0);
});

it('changes nothing about the employee when a complaint is resolved and referred', function (): void {
    $complaint = bndRow(ServiceFeedbackType::Complaint, ['status' => ServiceFeedbackStatus::UnderReview]);
    $before = $this->a->employee->fresh()->toArray();

    app(PublicFeedbackWorkflow::class)->transition($complaint, ServiceFeedbackStatus::Resolved, $this->superAdmin, 'Resolved');
    $referral = app(PublicFeedbackReferralService::class)->request($complaint, 'Facts may justify discipline.', $this->superAdmin);
    app(PublicFeedbackReferralService::class)->decide($referral, 'accepted', null, $this->superAdmin);

    expect($this->a->employee->fresh()->toArray())->toBe($before)
        ->and($this->a->employee->fresh()->status)->toBe(EmployeeStatus::Active)
        ->and(DB::table('grievances')->count())->toBe(0)
        ->and(DB::table('administrative_tribunal_cases')->count())->toBe(0)
        ->and(DB::table('kpi_actuals')->count())->toBe(0)
        ->and(PublicFeedbackReferral::query()->sole()->disciplinary_case_id)->toBeNull()
        ->and($complaint->fresh()->type)->toBe(ServiceFeedbackType::Complaint);
});

it('never reclassifies a suggestion that is not adopted as a complaint', function (): void {
    $suggestion = bndRow(ServiceFeedbackType::Suggestion, ['status' => ServiceFeedbackStatus::UnderReview]);
    app(PublicFeedbackWorkflow::class)->transition($suggestion, ServiceFeedbackStatus::NotAdopted, $this->superAdmin, 'Already covered.');

    expect($suggestion->fresh()->type)->toBe(ServiceFeedbackType::Suggestion)
        ->and($suggestion->fresh()->status)->toBe(ServiceFeedbackStatus::NotAdopted)
        ->and($suggestion->fresh()->reference_number)->toBeNull();
});

it('counts only ratings on the platform dashboard, never suggestions, complaints or grievances', function (): void {
    bndRow(ServiceFeedbackType::Feedback, ['rating' => 1]);
    bndRow(ServiceFeedbackType::Complaint, ['status' => ServiceFeedbackStatus::Unrouted]);
    bndRow(ServiceFeedbackType::Suggestion);

    $count = app(DashboardMetricService::class)
        ->feedbackQuery(['global_access' => true, 'provider_only' => false, 'organization_ids' => []])
        ->count();

    expect($count)->toBe(1);
});
