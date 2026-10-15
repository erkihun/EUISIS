<?php

declare(strict_types=1);

use App\Actions\Organizations\PublishHierarchyVersionAction;
use App\Enums\AssignmentStatus;
use App\Enums\AuditEventType;
use App\Enums\EmployeeStatus;
use App\Enums\HierarchyVersionStatus;
use App\Enums\OrganizationRelationshipType;
use App\Enums\OrganizationScopeType;
use App\Enums\ServiceFeedbackStatus;
use App\Enums\ServiceFeedbackType;
use App\Jobs\ExportSituationRoomReport;
use App\Models\AuditLog;
use App\Models\Employee;
use App\Models\EmployeeAssignment;
use App\Models\EmployeeServiceFeedback;
use App\Models\HierarchyVersion;
use App\Models\Organization;
use App\Models\OrganizationEdge;
use App\Models\OrganizationType;
use App\Models\Permission;
use App\Models\PublicFeedbackCategory;
use App\Models\PublicFeedbackReportExport;
use App\Models\Role;
use App\Models\SituationRoomAction;
use App\Models\SystemSetting;
use App\Models\User;
use App\Models\UserOrganizationScope;
use App\Services\OrganizationScope\OrganizationScopeService;
use App\Services\ServiceFeedback\PublicFeedbackSettings;
use App\Services\ServiceFeedback\PublicFeedbackVisibility;
use App\Services\SituationRoom\CommunityVoiceQuery;
use App\Services\SituationRoom\CommunityVoiceRoomService;
use App\Services\SituationRoom\SituationRoomFilters;
use App\Services\SituationRoom\SituationRoomScope;
use App\Services\SystemSettings\SystemSettingsService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;

/*
 * Executive Situation Room (docs/executive-situation-room.md): roll-up over
 * the published hierarchy, scope enforcement, counts that add up, privacy of
 * the employee drill-down and the Wall, configured-only rules, the Action
 * Register's separation from the complaint workflow, and safe exports.
 *
 * Fixture: city → bureau → sub-city → woreda, plus a sibling bureau under the city.
 */

const SR_PERMISSIONS = [
    'situation_room.view', 'situation_room.view_employee_targeted', 'situation_room.view_detail',
    'situation_room.export', 'situation_room.wall_mode', 'situation_room.manage_actions', 'situation_room.public_voice.view',
    'service_feedback.view_employee_target', 'service_feedback.view_organization_target',
];

const SR_AGGREGATE = ['situation_room.view', 'situation_room.public_voice.view', 'service_feedback.view_employee_target', 'service_feedback.view_organization_target'];

function srOrg(string $code): Organization
{
    $type = OrganizationType::query()->firstOrCreate(['code' => 'SR-TYPE'], ['name_en' => 'Situation Room Type', 'is_demo' => false]);

    return Organization::query()->create([
        'organization_type_id' => $type->id,
        'code' => 'SR-'.$code,
        'name_en' => 'SR '.$code,
        'name_am' => 'ተቋም '.$code,
        'status' => 'active',
        'effective_from' => now()->subYear()->toDateString(),
        'is_demo' => false,
    ]);
}

/** @param  list<array{0: Organization, 1: Organization}>  $edges */
function srPublish(array $edges): HierarchyVersion
{
    $version = HierarchyVersion::query()->create([
        'version_name' => 'sr-v-'.uniqid(),
        'status' => HierarchyVersionStatus::Draft,
        'effective_from' => now()->subYear()->toDateString(),
        'is_demo' => false,
    ]);
    foreach ($edges as [$parent, $child]) {
        OrganizationEdge::query()->create([
            'hierarchy_version_id' => $version->id,
            'parent_organization_id' => $parent->id,
            'child_organization_id' => $child->id,
            'relationship_type' => OrganizationRelationshipType::ReportsTo,
            'effective_from' => now()->subYear()->toDateString(),
        ]);
    }
    $publisher = User::factory()->create();
    $publisher->assignRole('Super Admin');
    app(PublishHierarchyVersionAction::class)->execute($version, $publisher);

    return $version->fresh();
}

function srEmployee(Organization $org, string $name): Employee
{
    $employee = Employee::query()->create([
        'employee_number' => 'SR-'.uniqid(),
        'first_name' => $name,
        'last_name' => 'Tester',
        'full_name' => $name.' Tester',
        'status' => EmployeeStatus::Active,
        'is_demo' => false,
    ]);
    $assignment = EmployeeAssignment::query()->create([
        'employee_id' => $employee->id,
        'organization_id' => $org->id,
        'assignment_status' => AssignmentStatus::Active,
        'effective_from' => now()->subYear()->toDateString(),
        'is_current' => true,
    ]);
    $employee->forceFill(['current_assignment_id' => $assignment->id])->save();

    return $employee;
}

function srRow(Organization $org, ServiceFeedbackType $type, ServiceFeedbackStatus $status, array $overrides = []): EmployeeServiceFeedback
{
    return EmployeeServiceFeedback::query()->create(array_merge([
        'employee_id' => null,
        'organization_target_id' => $org->id,
        'target_type' => 'organization',
        'source_type' => 'organization_feedback_qr',
        'organization_id' => $org->id,
        'type' => $type,
        'rating' => $type === ServiceFeedbackType::Feedback ? 4 : null,
        'comment' => 'Situation room fixture '.$type->value,
        'status' => $status,
        'submitted_at' => now()->subDays(2),
    ], $overrides));
}

function srEmployeeRow(Employee $employee, Organization $org, ServiceFeedbackType $type, ServiceFeedbackStatus $status, array $overrides = []): EmployeeServiceFeedback
{
    return srRow($org, $type, $status, array_merge([
        'employee_id' => $employee->id,
        'organization_target_id' => null,
        'target_type' => 'employee',
        'source_type' => 'employee_card_qr',
    ], $overrides));
}

function srUser(array $permissions, ?Organization $org = null, OrganizationScopeType $scope = OrganizationScopeType::Subtree): User
{
    $role = Role::findOrCreate('SR '.md5(implode(',', $permissions)), 'web');
    $role->syncPermissions($permissions);
    $user = User::factory()->create();
    $user->assignRole($role);
    if ($org !== null) {
        UserOrganizationScope::query()->create(['user_id' => $user->id, 'organization_id' => $org->id, 'scope_type' => $scope]);
    }

    return $user;
}

function srScope(User $user, ?Organization $node = null): SituationRoomScope
{
    return SituationRoomScope::for($user, $node?->id, app(OrganizationScopeService::class), app(PublicFeedbackVisibility::class));
}

beforeEach(function (): void {
    app()->setLocale('en');
    Storage::fake('local');
    foreach (SR_PERMISSIONS as $permission) {
        Permission::findOrCreate($permission, 'web');
    }
    Role::findOrCreate('Super Admin', 'web')->syncPermissions(Permission::all());

    $this->city = srOrg('CITY');
    $this->bureau = srOrg('BUREAU');
    $this->subcity = srOrg('SUBCITY');
    $this->woreda = srOrg('WOREDA');
    $this->sibling = srOrg('SIBLING');
    srPublish([[$this->city, $this->bureau], [$this->bureau, $this->subcity], [$this->subcity, $this->woreda], [$this->city, $this->sibling]]);

    $this->category = PublicFeedbackCategory::query()->create([
        'code' => 'SR-WAIT', 'name_en' => 'Waiting time', 'name_am' => 'የመጠበቂያ ጊዜ', 'submission_types' => ['feedback', 'suggestion', 'complaint'],
        'target_types' => ['employee', 'organization'], 'organization_id' => null, 'is_active' => true, 'sort_order' => 1,
    ]);

    $this->bureauHead = srUser(SR_PERMISSIONS, $this->bureau);
    $this->woredaHead = srUser(SR_PERMISSIONS, $this->woreda);
    $this->filters = SituationRoomFilters::fromInput([]);
});

it('rolls a woreda submission up to every level above it, once each', function (): void {
    srRow($this->woreda, ServiceFeedbackType::Complaint, ServiceFeedbackStatus::Pending);
    srRow($this->subcity, ServiceFeedbackType::Suggestion, ServiceFeedbackStatus::Accepted);
    srRow($this->bureau, ServiceFeedbackType::Feedback, ServiceFeedbackStatus::Pending);
    srRow($this->sibling, ServiceFeedbackType::Complaint, ServiceFeedbackStatus::Pending);
    srRow($this->city, ServiceFeedbackType::Complaint, ServiceFeedbackStatus::Pending);

    $voice = app(CommunityVoiceQuery::class);
    $total = fn (User $user, ?Organization $node = null): int => $voice->kpis(srScope($user, $node), $this->filters)['current']['total'];

    expect($total($this->bureauHead))->toBe(3)
        ->and($total($this->bureauHead, $this->subcity))->toBe(2)
        ->and($total($this->bureauHead, $this->woreda))->toBe(1)
        ->and($total($this->woredaHead))->toBe(1);

    $cityExec = srUser(SR_PERMISSIONS, $this->city);
    expect($total($cityExec))->toBe(5)
        ->and($total($cityExec, $this->bureau))->toBe(3);
});

it('refuses an institution outside the leader scope, in the service and over HTTP', function (): void {
    expect(fn () => srScope($this->woredaHead, $this->bureau))->toThrow(AuthorizationException::class)
        ->and(fn () => srScope($this->bureauHead, $this->sibling))->toThrow(AuthorizationException::class);

    $this->actingAs($this->woredaHead)->get(route('situation-room.organization', $this->subcity))->assertForbidden();
    $this->actingAs($this->bureauHead)->get(route('situation-room.organization', $this->sibling))->assertForbidden();
    $this->actingAs($this->bureauHead)->get(route('situation-room.organization', $this->woreda))->assertOk();
});

it('needs the Situation Room permission', function (): void {
    $other = srUser(['service_feedback.view_employee_target', 'service_feedback.view_organization_target'], $this->bureau);

    $this->actingAs($other)->get(route('situation-room.index'))->assertForbidden();
    $this->actingAs($other)->get(route('situation-room.community-voice'))->assertForbidden();
    $this->actingAs($other)->get(route('situation-room.wall'))->assertForbidden();
    $this->actingAs($other)->get(route('situation-room.actions.index'))->assertForbidden();
});

it('makes institution bars plus the own bucket add up to the total', function (): void {
    srRow($this->woreda, ServiceFeedbackType::Complaint, ServiceFeedbackStatus::Pending);
    srRow($this->subcity, ServiceFeedbackType::Complaint, ServiceFeedbackStatus::Resolved);
    srRow($this->bureau, ServiceFeedbackType::Feedback, ServiceFeedbackStatus::Pending);
    srRow($this->bureau, ServiceFeedbackType::Suggestion, ServiceFeedbackStatus::Pending);

    $scope = srScope($this->bureauHead, $this->bureau);
    $voice = app(CommunityVoiceQuery::class);
    $institutions = $voice->byInstitution($scope, $this->filters);

    expect(array_column($institutions['rows'], 'id'))->toBe([$this->subcity->id])
        ->and($institutions['rows'][0]['total'])->toBe(2)
        ->and($institutions['own']['total'])->toBe(2)
        ->and($institutions['rows'][0]['total'] + $institutions['own']['total'])->toBe($voice->kpis($scope, $this->filters)['current']['total']);
});

it('counts categories with uncategorized, and keeps hidden out of every bucket', function (): void {
    srRow($this->woreda, ServiceFeedbackType::Complaint, ServiceFeedbackStatus::Pending, ['category_id' => $this->category->id]);
    srRow($this->woreda, ServiceFeedbackType::Complaint, ServiceFeedbackStatus::Closed, ['category_id' => $this->category->id]);
    srRow($this->woreda, ServiceFeedbackType::Suggestion, ServiceFeedbackStatus::NotAdopted);
    srRow($this->woreda, ServiceFeedbackType::Feedback, ServiceFeedbackStatus::Hidden);

    $scope = srScope($this->bureauHead);
    $voice = app(CommunityVoiceQuery::class);
    $kpis = $voice->kpis($scope, $this->filters)['current'];
    $categories = collect($voice->byCategory($scope, $this->filters));

    expect($kpis['total'])->toBe(3)
        ->and($kpis['resolved'])->toBe(2)
        ->and($kpis['unresolved'])->toBe(1)
        ->and($categories->sum('total'))->toBe(3)
        ->and($categories->firstWhere('uncategorized', true)['total'])->toBe(1)
        ->and($categories->firstWhere('id', $this->category->id)['complaint'])->toBe(2);
});

it('separates target types by the viewer grants', function (): void {
    $employee = srEmployee($this->woreda, 'Abebe');
    srEmployeeRow($employee, $this->woreda, ServiceFeedbackType::Complaint, ServiceFeedbackStatus::Pending);
    srRow($this->woreda, ServiceFeedbackType::Complaint, ServiceFeedbackStatus::Pending);

    $orgOnly = srUser(['situation_room.view', 'service_feedback.view_organization_target'], $this->bureau);
    $targets = app(CommunityVoiceQuery::class)->byTarget(srScope($orgOnly), $this->filters);

    expect($targets['employee']['complaint'])->toBe(0)
        ->and($targets['organization']['complaint'])->toBe(1);
});

it('never shares a cached section between leaders with different reach', function (): void {
    srRow($this->woreda, ServiceFeedbackType::Complaint, ServiceFeedbackStatus::Pending);
    srRow($this->bureau, ServiceFeedbackType::Complaint, ServiceFeedbackStatus::Pending);
    $room = app(CommunityVoiceRoomService::class);

    $bureauHeadline = $room->headline(srScope($this->bureauHead), $this->filters);
    $woredaHeadline = $room->headline(srScope($this->woredaHead), $this->filters);

    expect($bureauHeadline['kpis']['current']['total'])->toBe(2)
        ->and($woredaHeadline['kpis']['current']['total'])->toBe(1)
        ->and($room->key(srScope($this->bureauHead), $this->filters, 'headline'))->not->toBe($room->key(srScope($this->woredaHead), $this->filters, 'headline'))
        ->and($bureauHeadline)->toHaveKey('generated_at');
});

it('says nothing is overdue or rising until the rules are configured', function (): void {
    srRow($this->woreda, ServiceFeedbackType::Complaint, ServiceFeedbackStatus::Pending, ['submitted_at' => now()->subDays(20)]);
    $room = app(CommunityVoiceRoomService::class);
    $codes = fn (): array => array_column($room->comparison(srScope($this->bureauHead), $this->filters)['attention'], 'code');

    expect($codes())->toContain('unresolved_complaints')->not->toContain('overdue');

    SystemSetting::query()->updateOrCreate(['group' => 'service_feedback', 'key' => 'sla_days_complaint'], ['value' => '10', 'type' => 'integer']);
    app(SystemSettingsService::class)->clearCache();
    cache()->flush();

    expect($codes())->toContain('overdue');
});

it('gives the employee drill-down only with both grants, and text only with the detail grant', function (): void {
    $employee = srEmployee($this->woreda, 'Almaz');
    srEmployeeRow($employee, $this->woreda, ServiceFeedbackType::Complaint, ServiceFeedbackStatus::Pending, ['comment' => 'Private narrative text']);

    $aggregateOnly = srUser(SR_AGGREGATE, $this->bureau);
    $this->actingAs($aggregateOnly)->get(route('situation-room.employees'))->assertForbidden();
    $this->actingAs($aggregateOnly)->get(route('situation-room.employee', $employee))->assertForbidden();

    $countsOnly = srUser([...SR_AGGREGATE, 'situation_room.view_employee_targeted'], $this->bureau);
    $this->actingAs($countsOnly)->get(route('situation-room.employees'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->component('SituationRoom/Employees')->where('employees.rows.0.total', 1));
    $this->actingAs($countsOnly)->get(route('situation-room.employee', $employee))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->where('submissions.rows.0.message', null));
    expect(AuditLog::query()->where('event_type', AuditEventType::SituationRoomDetailViewed->value)->exists())->toBeFalse();

    $this->actingAs($this->bureauHead)->get(route('situation-room.employee', $employee))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->where('submissions.rows.0.message', 'Private narrative text'));
    expect(AuditLog::query()->where('event_type', AuditEventType::SituationRoomDetailViewed->value)->exists())->toBeTrue();

    // A leader with no submissions about this employee in scope cannot even learn the name.
    $siblingHead = srUser(SR_PERMISSIONS, $this->sibling);
    $this->actingAs($siblingHead)->get(route('situation-room.employee', $employee))->assertNotFound();
});

it('keeps the Wall to aggregates: no narrative, no person', function (): void {
    $employee = srEmployee($this->woreda, 'Kebede');
    srEmployeeRow($employee, $this->woreda, ServiceFeedbackType::Complaint, ServiceFeedbackStatus::Pending, ['comment' => 'Secret wall text', 'client_name' => 'Visitor Name']);

    // The room has one wall; its figures are a deferred prop, so load them before looking.
    $json = '';
    $this->actingAs($this->bureauHead)->get(route('situation-room.wall'))->assertOk()
        ->assertInertia(function (Assert $page) use (&$json): void {
            $page->component('SituationRoom/Wall')->loadDeferredProps(function (Assert $reload) use (&$json): void {
                $json = json_encode($reload->toArray(), JSON_THROW_ON_ERROR);
            });
        });
    expect($json)->toContain('complaints');

    expect($json)->not->toContain('Secret wall text')
        ->not->toContain('Visitor Name')
        ->not->toContain('Kebede');
});

it('records management actions without touching the complaint workflow', function (): void {
    $complaint = srRow($this->woreda, ServiceFeedbackType::Complaint, ServiceFeedbackStatus::Assigned);
    $owner = User::factory()->create(['default_organization_id' => $this->woreda->id]);
    UserOrganizationScope::query()->create(['user_id' => $owner->id, 'organization_id' => $this->woreda->id, 'scope_type' => OrganizationScopeType::Self]);

    $this->actingAs($this->bureauHead)->post(route('situation-room.actions.store'), [
        'organization_id' => $this->woreda->id,
        'source_type' => 'submission',
        'source_reference' => $complaint->id,
        'title' => 'Follow up waiting time',
        'owner_user_id' => $owner->id,
        'due_date' => now()->addWeek()->toDateString(),
    ])->assertRedirect()->assertSessionHasNoErrors();

    $action = SituationRoomAction::query()->firstOrFail();
    $this->actingAs($this->bureauHead)->patch(route('situation-room.actions.update', $action), ['status' => 'done', 'note' => 'Done'])->assertRedirect();

    expect($action->fresh()->status)->toBe('done')
        ->and($action->fresh()->closed_at)->not->toBeNull()
        ->and($action->events()->pluck('event')->all())->toBe(['created', 'assigned', 'status_changed'])
        ->and($complaint->fresh()->status)->toBe(ServiceFeedbackStatus::Assigned)
        ->and(AuditLog::query()->where('event_type', AuditEventType::SituationRoomActionCreated->value)->exists())->toBeTrue();

    // Out of scope: a sibling institution, or an owner who cannot see the institution.
    $this->actingAs($this->bureauHead)->post(route('situation-room.actions.store'), ['organization_id' => $this->sibling->id, 'title' => 'x'])->assertForbidden();
    $stranger = User::factory()->create();
    UserOrganizationScope::query()->create(['user_id' => $stranger->id, 'organization_id' => $this->sibling->id, 'scope_type' => OrganizationScopeType::Self]);
    $this->actingAs($this->bureauHead)->post(route('situation-room.actions.store'), ['organization_id' => $this->woreda->id, 'title' => 'x', 'owner_user_id' => $stranger->id])
        ->assertSessionHasErrors('owner_user_id');
    $this->actingAs($this->woredaHead)->patch(route('situation-room.actions.update', SituationRoomAction::query()->create([
        'organization_id' => $this->sibling->id, 'title' => 'sibling', 'status' => 'open', 'priority' => 'normal',
    ])), ['status' => 'done'])->assertForbidden();
});

it('exports institution figures as formula-safe CSV for the requester only', function (): void {
    $this->subcity->forceFill(['name_en' => '=HYPERLINK("http://x")'])->save();
    srRow($this->woreda, ServiceFeedbackType::Complaint, ServiceFeedbackStatus::Pending);

    $this->actingAs($this->bureauHead)->post(route('situation-room.exports.store'), ['format' => 'csv', 'organization' => $this->bureau->id])->assertRedirect();

    $export = PublicFeedbackReportExport::query()->where('report', ExportSituationRoomReport::REPORT)->firstOrFail();
    expect($export->status)->toBe('ready');
    $csv = Storage::disk('local')->get($export->path);
    expect($csv)->toContain("'=HYPERLINK")
        ->and(AuditLog::query()->where('event_type', AuditEventType::SituationRoomExportRequested->value)->exists())->toBeTrue();

    $this->actingAs($this->bureauHead)->get(route('situation-room.exports.download', $export))->assertOk();
    $this->actingAs($this->woredaHead)->get(route('situation-room.exports.download', $export))->assertForbidden();
});

it('compares a calendar period with the one before it', function (): void {
    $filters = SituationRoomFilters::fromInput(['period' => 'month'], now()->setDate(2026, 3, 15));

    expect($filters->start->toDateString())->toBe('2026-03-01')
        ->and($filters->previousStart->toDateString())->toBe('2026-02-01')
        ->and($filters->previousEnd->toDateString())->toBe('2026-03-01');

    $custom = SituationRoomFilters::fromInput(['period' => 'custom', 'from' => '2026-01-10', 'to' => '2026-01-19']);
    expect($custom->previousStart->toDateString())->toBe('2025-12-31')
        ->and($custom->previousEnd->toDateString())->toBe('2026-01-10');
});

it('saves the Situation Room rules, empty meaning off', function (): void {
    $admin = User::factory()->create();
    $admin->assignRole('Super Admin');
    Permission::findOrCreate('service_feedback.settings.manage', 'web');
    Role::findOrCreate('Super Admin', 'web')->syncPermissions(Permission::all());

    $this->actingAs($admin)->put(route('service-feedback.settings.update'), ['sla_days_complaint' => 15, 'spike_threshold_percent' => null])->assertRedirect()->assertSessionHasNoErrors();
    app(SystemSettingsService::class)->clearCache();
    expect(app(PublicFeedbackSettings::class)->situationRoomRules())
        ->toMatchArray(['sla_days_complaint' => 15, 'spike_threshold_percent' => null, 'sla_days_feedback' => null]);

    $this->actingAs($admin)->put(route('service-feedback.settings.update'), ['sla_days_complaint' => 0])->assertSessionHasErrors('sla_days_complaint');
});

it('flags an increase only past the configured threshold and never from zero', function (): void {
    foreach (range(1, 2) as $i) {
        srRow($this->woreda, ServiceFeedbackType::Complaint, ServiceFeedbackStatus::Pending, ['submitted_at' => now()->subDays(40)]);
    }
    foreach (range(1, 5) as $i) {
        srRow($this->woreda, ServiceFeedbackType::Complaint, ServiceFeedbackStatus::Pending, ['submitted_at' => now()->subDays(3)]);
        srRow($this->woreda, ServiceFeedbackType::Suggestion, ServiceFeedbackStatus::Pending, ['submitted_at' => now()->subDays(3)]);
    }
    foreach (['spike_threshold_percent' => '50', 'spike_minimum_count' => '2'] as $key => $value) {
        SystemSetting::query()->updateOrCreate(['group' => 'service_feedback', 'key' => $key], ['value' => $value, 'type' => 'integer']);
    }
    app(SystemSettingsService::class)->clearCache();

    $items = collect(app(CommunityVoiceRoomService::class)->comparison(srScope($this->bureauHead), $this->filters)['attention'])->where('code', 'increase');

    // Complaints 2 → 5 is +150%; suggestions 0 → 5 has no baseline and is not called an increase.
    expect($items)->toHaveCount(1)
        ->and($items->first()['params'])->toMatchArray(['type' => 'complaint', 'previous' => 2, 'percent' => 150]);
});

it('shows only the ancestors a leader may open in the breadcrumb', function (): void {
    $crumbs = array_column(srScope($this->bureauHead, $this->woreda)->breadcrumb(), 'id');

    expect($crumbs)->toBe([$this->bureau->id, $this->subcity->id, $this->woreda->id])
        ->not->toContain($this->city->id);
});

it('leaves a leader own submissions out of what they see', function (): void {
    $employee = srEmployee($this->woreda, 'Self');
    srEmployeeRow($employee, $this->woreda, ServiceFeedbackType::Complaint, ServiceFeedbackStatus::Pending);
    srRow($this->woreda, ServiceFeedbackType::Complaint, ServiceFeedbackStatus::Pending);
    $this->woredaHead->forceFill(['employee_id' => $employee->id])->save();

    expect(app(CommunityVoiceQuery::class)->kpis(srScope($this->woredaHead->fresh()), $this->filters)['current']['total'])->toBe(1);
});

it('needs the wall permission for wall mode, and defers the slower sections', function (): void {
    $viewer = srUser(SR_AGGREGATE, $this->bureau);
    $this->actingAs($viewer)->get(route('situation-room.wall'))->assertForbidden();

    $this->actingAs($viewer)->get(route('situation-room.community-voice'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('SituationRoom/CommunityVoice')
            ->has('headline.kpis.current')
            ->missing('comparison')
            ->missing('breakdown')
            ->where('can.export', false)
            ->where('can.employeeTargeted', false));
});
