<?php

declare(strict_types=1);

use App\Enums\AuditEventType;
use App\Enums\OrganizationScopeType;
use App\Enums\PublicFeedbackHandlerType;
use App\Enums\ServiceFeedbackStatus;
use App\Enums\ServiceFeedbackType;
use App\Models\AuditLog;
use App\Models\EmployeeServiceFeedback;
use App\Models\OrganizationUnit;
use App\Models\Permission;
use App\Models\PublicFeedbackReportExport;
use App\Models\Role;
use App\Models\User;
use App\Models\UserOrganizationScope;
use App\Services\ServiceFeedback\OrganizationFeedbackQrService;
use App\Services\ServiceFeedback\PublicFeedbackAssignmentService;
use App\Services\ServiceFeedback\PublicFeedbackMetrics;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Support\PublicFeedbackScenario;

/*
 * Public Feedback Management (brief §79–87): one navigation group, a
 * dashboard with real scoped counts, a submissions report with filters and
 * breakdowns, and queued, private, formula-safe exports. No employee is
 * ranked by complaints (§82); employee-level rows take the employee-target
 * grant (§84); institution-targeted rows stand apart (§85).
 */

const PFR_VIEW = ['service_feedback.view', 'service_feedback.complaints.view', 'service_feedback.export', 'service_feedback.organization_qr.view'];
const PFR_TARGETS = ['service_feedback.view_employee_target', 'service_feedback.view_organization_target'];

beforeEach(function (): void {
    app()->setLocale('en');
    Storage::fake('local');

    foreach ([...PFR_VIEW, ...PFR_TARGETS, 'service_feedback.view_submitter_identity', 'service_feedback.assign'] as $permission) {
        Permission::findOrCreate($permission, 'web');
    }
    Role::findOrCreate('Super Admin', 'web')->syncPermissions(Permission::all());
    Role::findOrCreate('Organizational Admin', 'web')->syncPermissions([...PFR_VIEW, ...PFR_TARGETS]);
    Role::findOrCreate('Organization-only Reviewer', 'web')->syncPermissions([...PFR_VIEW, 'service_feedback.view_organization_target']);

    $this->a = PublicFeedbackScenario::employeeTarget('RPA');
    $this->b = PublicFeedbackScenario::employeeTarget('RPB');

    $this->superAdmin = User::factory()->create();
    $this->superAdmin->assignRole('Super Admin');

    $this->adminB = User::factory()->create();
    $this->adminB->assignRole('Organizational Admin');
    UserOrganizationScope::query()->create(['user_id' => $this->adminB->id, 'organization_id' => $this->b->organization->id, 'scope_type' => OrganizationScopeType::Self]);

    $this->orgOnly = User::factory()->create();
    $this->orgOnly->assignRole('Organization-only Reviewer');
    UserOrganizationScope::query()->create(['user_id' => $this->orgOnly->id, 'organization_id' => $this->a->organization->id, 'scope_type' => OrganizationScopeType::Self]);

});

function rptRow(PublicFeedbackScenario $s, ServiceFeedbackType $type, ServiceFeedbackStatus $status, array $overrides = []): EmployeeServiceFeedback
{
    return EmployeeServiceFeedback::query()->create(array_merge([
        'employee_id' => $s->employee->id,
        'organization_id' => $s->organization->id,
        'type' => $type,
        'rating' => $type === ServiceFeedbackType::Feedback ? 3 : null,
        'comment' => 'Report fixture '.$type->value,
        'status' => $status,
        'submitted_at' => now()->subDays(2),
    ], $overrides));
}

function rptOrganizationRow(PublicFeedbackScenario $s, ServiceFeedbackType $type, ServiceFeedbackStatus $status, array $overrides = []): EmployeeServiceFeedback
{
    return rptRow($s, $type, $status, array_merge([
        'employee_id' => null, 'organization_target_id' => $s->organization->id, 'target_type' => 'organization', 'source_type' => 'organization_feedback_qr',
    ], $overrides));
}

it('puts real scoped submission counts on the dashboard', function (): void {
    rptRow($this->a, ServiceFeedbackType::Feedback, ServiceFeedbackStatus::Pending);
    rptRow($this->a, ServiceFeedbackType::Suggestion, ServiceFeedbackStatus::UnderReview);
    rptRow($this->a, ServiceFeedbackType::Complaint, ServiceFeedbackStatus::Unrouted);
    rptOrganizationRow($this->a, ServiceFeedbackType::Complaint, ServiceFeedbackStatus::Assigned);
    rptRow($this->b, ServiceFeedbackType::Complaint, ServiceFeedbackStatus::Closed);
    rptRow($this->b, ServiceFeedbackType::Suggestion, ServiceFeedbackStatus::Accepted);

    $this->actingAs($this->superAdmin)
        ->get(route('service-feedback.admin.dashboard'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('submissions.total', 6)
            ->where('submissions.by_type.feedback', 1)
            ->where('submissions.by_type.suggestion', 2)
            ->where('submissions.by_type.complaint', 3)
            ->where('submissions.by_target.employee', 5)
            ->where('submissions.by_target.organization', 1)
            ->where('submissions.by_status.unrouted', 1)
            ->where('submissions.by_status.assigned', 1)
            ->where('submissions.by_status.under_review', 1)
            ->where('submissions.by_status.resolved', 1)
            ->where('submissions.by_status.closed', 1)
            ->where('submissions.by_status.pending', 1));

    // Scope narrows the counts; so does the target-type grant.
    $this->actingAs($this->adminB)
        ->get(route('service-feedback.admin.dashboard'))
        ->assertInertia(fn (Assert $page) => $page->where('submissions.total', 2)->where('submissions.by_target.organization', 0));
    $this->actingAs($this->orgOnly)
        ->get(route('service-feedback.admin.dashboard'))
        ->assertInertia(fn (Assert $page) => $page->where('submissions.total', 1)->where('submissions.by_target.employee', 0)->where('byEmployee', []));
});

it('breaks organization submissions down by the relevant work unit named, with General / Not sure as its own bucket, and exports the recorded name', function (): void {
    $permits = OrganizationUnit::query()->create(['organization_id' => $this->a->organization->id, 'unit_type' => 'office', 'code' => 'RP-PERMITS', 'name_en' => 'Permits office', 'name_am' => 'የፈቃድ ጽሕፈት ቤት', 'status' => 'active']);
    $named = ['organization_unit_id' => $permits->id, 'organization_unit_name_snapshot' => 'Permits office', 'organization_unit_name_am_snapshot' => 'የፈቃድ ጽሕፈት ቤት', 'unit_selection_mode' => 'SELECTED'];
    rptOrganizationRow($this->a, ServiceFeedbackType::Complaint, ServiceFeedbackStatus::Pending, $named);
    rptOrganizationRow($this->a, ServiceFeedbackType::Complaint, ServiceFeedbackStatus::Closed, $named);
    rptOrganizationRow($this->a, ServiceFeedbackType::Suggestion, ServiceFeedbackStatus::Pending, ['unit_selection_mode' => 'GENERAL']);
    // An employee QR: the unit is the employee's post, not the visitor's choice, so it is not counted here.
    rptRow($this->a, ServiceFeedbackType::Complaint, ServiceFeedbackStatus::Pending, ['organization_unit_id' => $this->a->unit->id]);
    $permits->update(['name_en' => 'Renamed office']);

    $this->actingAs($this->superAdmin)
        ->get(route('service-feedback.submission-reports.index'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('byUnit', fn ($rows) => collect($rows->toArray())->map(fn (array $row): array => [$row['name_en'], $row['general'], $row['total']])->all() === [['Permits office', false, 2], [null, true, 1]]));

    $metrics = app(PublicFeedbackMetrics::class);
    $units = collect($metrics->exportRows($metrics->query($this->superAdmin), $this->superAdmin, 'en'))->pluck('organization_unit')->countBy()->all();
    expect($units)->toBe(['Permits office' => 2, 'General / Not sure' => 1, 'Licensing Unit' => 1]);
    $am = collect($metrics->exportRows($metrics->query($this->superAdmin), $this->superAdmin, 'am'))->pluck('organization_unit')->all();
    expect($am)->toContain('የፈቃድ ጽሕፈት ቤት')->toContain('አጠቃላይ / አላውቅም');
});

it('breaks submissions down by organization, status and handler with filters', function (): void {
    $handled = rptRow($this->a, ServiceFeedbackType::Complaint, ServiceFeedbackStatus::Unrouted);
    // The handler must work in the submission's institution: orgOnly is scoped to A.
    app(PublicFeedbackAssignmentService::class)->assign($handled, PublicFeedbackHandlerType::User, (string) $this->orgOnly->id, $this->superAdmin);
    rptRow($this->a, ServiceFeedbackType::Complaint, ServiceFeedbackStatus::Closed, ['submitted_at' => now()->subDays(40)]);
    rptOrganizationRow($this->a, ServiceFeedbackType::Suggestion, ServiceFeedbackStatus::NotAdopted);
    rptRow($this->b, ServiceFeedbackType::Complaint, ServiceFeedbackStatus::Pending);

    $this->actingAs($this->superAdmin)
        ->get(route('service-feedback.submission-reports.index'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('ServiceFeedback/SubmissionReports')
            ->where('summary.total', 4)
            ->where('byOrganization', fn ($rows) => collect($rows->toArray())->pluck('total', 'name_en')->all() === [$this->a->organization->name_en => 3, $this->b->organization->name_en => 1])
            ->where('byHandler', fn ($rows) => collect($rows->toArray())->pluck('total', 'name_en')->all() === [$this->orgOnly->name => 1])
            ->where('can.export', true));

    $this->actingAs($this->superAdmin)
        ->get(route('service-feedback.submission-reports.index', ['submission_type' => 'complaint', 'status' => 'closed']))
        ->assertInertia(fn (Assert $page) => $page->where('summary.total', 1));
    $this->actingAs($this->superAdmin)
        ->get(route('service-feedback.submission-reports.index', ['target_type' => 'organization']))
        ->assertInertia(fn (Assert $page) => $page->where('summary.total', 1)->where('summary.by_type.suggestion', 1));
    $this->actingAs($this->superAdmin)
        ->get(route('service-feedback.submission-reports.index', ['date_from' => now()->subDays(7)->toDateString()]))
        ->assertInertia(fn (Assert $page) => $page->where('summary.total', 3));
    $this->actingAs($this->superAdmin)
        ->get(route('service-feedback.submission-reports.index', ['handler_type' => 'user', 'handler_id' => (string) $this->orgOnly->id]))
        ->assertInertia(fn (Assert $page) => $page->where('summary.total', 1));

    // Scope applies to the report exactly as to the inbox.
    $this->actingAs($this->adminB)
        ->get(route('service-feedback.submission-reports.index'))
        ->assertInertia(fn (Assert $page) => $page->where('summary.total', 1)->has('byOrganization', 1));
});

it('exports the filtered submissions on the queue to a private, formula-safe file and audits it', function (): void {
    rptRow($this->a, ServiceFeedbackType::Complaint, ServiceFeedbackStatus::Closed, ['comment' => '=SUM(A1:A9) looks like a formula', 'client_name' => 'Abebe', 'client_contact' => '0911223344', 'reference_number' => 'PSC-2026-000777']);
    rptRow($this->b, ServiceFeedbackType::Suggestion, ServiceFeedbackStatus::Pending);

    $this->actingAs($this->adminB)
        ->post(route('service-feedback.submission-reports.export'), ['format' => 'csv'])
        ->assertRedirect();

    $export = PublicFeedbackReportExport::query()->sole();
    expect($export->status)->toBe('ready')
        ->and($export->row_count)->toBe(1)
        ->and((int) $export->requested_by)->toBe((int) $this->adminB->id)
        ->and(Storage::disk('local')->exists($export->path))->toBeTrue();

    $this->actingAs($this->adminB)
        ->get(route('service-feedback.submission-reports.download', $export))
        ->assertOk();
    // Another scoped administrator cannot read someone else's export of another institution.
    $this->actingAs($this->orgOnly)
        ->get(route('service-feedback.submission-reports.download', $export))
        ->assertForbidden();

    // City-wide export by someone who may see identity: the formula is neutralised, the contact is present.
    $this->actingAs($this->superAdmin)
        ->post(route('service-feedback.submission-reports.export'), ['format' => 'csv', 'submission_type' => 'complaint'])
        ->assertRedirect();
    $cityWide = PublicFeedbackReportExport::query()->where('requested_by', $this->superAdmin->id)->sole();
    $csv = Storage::disk('local')->get($cityWide->path);
    expect($csv)->toContain('PSC-2026-000777')
        ->toContain('0911223344')
        ->not->toContain("\n=SUM")
        ->not->toContain(',=SUM')
        ->and(AuditLog::query()->where('event_type', AuditEventType::ServiceFeedbackExported->value)->count())->toBe(2);

    // Without the identity grant the contact never reaches the file.
    $this->actingAs($this->orgOnly)
        ->post(route('service-feedback.submission-reports.export'), ['format' => 'xlsx'])
        ->assertRedirect();
    $masked = PublicFeedbackReportExport::query()->where('requested_by', $this->orgOnly->id)->sole();
    expect($masked->status)->toBe('ready')->and($masked->format)->toBe('xlsx');

    $this->actingAs($this->adminB)
        ->post(route('service-feedback.submission-reports.export'), ['format' => 'pdf'])
        ->assertSessionHasErrors('format');
});

it('lists institutions with their feedback QR state within the user scope', function (): void {
    app(OrganizationFeedbackQrService::class)->ensureActive($this->a->organization, $this->superAdmin);
    rptOrganizationRow($this->a, ServiceFeedbackType::Complaint, ServiceFeedbackStatus::Pending);

    $this->actingAs($this->superAdmin)
        ->get(route('service-feedback.organization-qr.index'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('ServiceFeedback/OrganizationQrIndex')
            ->where('organizations', fn ($rows) => collect($rows->toArray())->firstWhere('id', $this->a->organization->id)['qr']['status'] === 'active'
                && collect($rows->toArray())->firstWhere('id', $this->a->organization->id)['submissions'] === 1
                && collect($rows->toArray())->firstWhere('id', $this->b->organization->id)['qr'] === null));

    $this->actingAs($this->adminB)
        ->get(route('service-feedback.organization-qr.index'))
        ->assertInertia(fn (Assert $page) => $page->has('organizations', 1)->where('organizations.0.id', $this->b->organization->id));

    $plain = User::factory()->create();
    $this->actingAs($plain)->get(route('service-feedback.organization-qr.index'))->assertForbidden();
});

it('keeps Public Feedback Management as one sidebar group with every management page', function (): void {
    $sidebar = file_get_contents(base_path('resources/js/Components/AppSidebar.tsx'));
    $start = strpos($sidebar, "key: 'serviceFeedback'");
    $end = strpos($sidebar, "key: '", $start + 10);
    $group = substr($sidebar, $start, $end - $start);

    expect($group)->toContain("labelKey: 'nav.groupPublicFeedback'");
    foreach ([
        'service-feedback.admin.dashboard', 'service-feedback.admin.index', 'service-submissions.suggestions', 'service-submissions.complaints',
        'service-feedback.routing-rules.index', 'service-feedback.organization-qr.index',
        'service-feedback.admin.reports', 'service-feedback.submission-reports.index', 'service-feedback.settings.index',
    ] as $routeName) {
        expect($group)->toContain("routeName: '{$routeName}'");
    }
    // Public complaints never sit under grievances or discipline (brief §80).
    foreach (["key: 'grievances'", "key: 'discipline'"] as $otherKey) {
        $otherStart = strpos($sidebar, $otherKey);
        if ($otherStart === false) {
            continue;
        }
        $otherEnd = strpos($sidebar, "key: '", $otherStart + 10) ?: strlen($sidebar);
        expect(substr($sidebar, $otherStart, $otherEnd - $otherStart))->not->toContain('service-submissions');
    }
});
