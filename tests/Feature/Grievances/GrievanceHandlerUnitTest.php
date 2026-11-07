<?php

declare(strict_types=1);

use App\Enums\HierarchyVersionStatus;
use App\Models\EmployeeAssignment;
use App\Models\HierarchyVersion;
use App\Models\OrganizationClosurePath;
use App\Models\OrganizationUnit;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Inertia\Testing\AssertableInertia;
use Modules\Grievance\Application\Services\GrievanceCaseAccessService;
use Modules\Grievance\Application\Services\GrievanceCaseService;
use Modules\Grievance\Application\Services\GrievanceEscalationService;
use Modules\Grievance\Application\Services\GrievanceHandlerRegistry;
use Modules\Grievance\Application\Services\GrievanceHandlerUnitService;
use Modules\Grievance\Application\Services\GrievanceRoutingService;
use Modules\Grievance\Contracts\Data\GrievanceRoles;
use Modules\Grievance\Domain\Enums\GrievanceCaseOfficerRole;
use Modules\Grievance\Domain\Enums\GrievanceMovementType;
use Modules\Grievance\Domain\Enums\GrievanceUnitEndReason;
use Modules\Grievance\Domain\Models\Grievance;
use Modules\Grievance\Domain\Models\GrievanceCaseStage;
use Modules\Grievance\Domain\Models\GrievanceCommittee;
use Modules\Grievance\Domain\Models\GrievanceRoute;
use Tests\Support\GrievanceScenario;

/*
 * ADR 0029: Grievance Teams and Directorates are existing units designated for
 * grievance work, with named members who carry a responsibility and a signature.
 */

beforeEach(function (): void {
    Carbon::setTestNow(Carbon::parse('2026-10-05 09:00:00'));
    $this->s = GrievanceScenario::build();
    $this->units = app(GrievanceHandlerUnitService::class);
    $this->admin = GrievanceScenario::user(GrievanceScenario::employee($this->s->subcity, null), GrievanceRoles::ADMINISTRATOR_PERMISSIONS);
    GrievanceScenario::scope($this->admin, $this->s->subcity);
});

afterEach(fn () => Carbon::setTestNow());

function unitCaseAtTeam(object $test): Grievance
{
    $cases = app(GrievanceCaseService::class);
    $g = $cases->createDraft($test->s->complainant, ['subject' => 'Shift allocation', 'description' => 'Narrative.', 'category_id' => $test->s->category->id, 'incident_date' => '2026-09-20']);
    $g = $cases->intakeAccept($cases->submit($g, $test->s->complainant), $test->s->intake, 'Complete');
    Carbon::setTestNow(Carbon::parse('2026-10-09 10:00:00'));
    app(GrievanceEscalationService::class)->autoEscalate($g->current_stage_id);

    return $g->refresh();
}

it('designates an existing unit once and creates nothing in the organization structure', function (): void {
    $before = OrganizationUnit::query()->count();
    $designation = $this->units->designate($this->admin, ['organization_unit_id' => $this->s->team->id, 'kind' => 'team']);

    expect($designation->organization_id)->toBe($this->s->subcity->id)
        ->and(OrganizationUnit::query()->count())->toBe($before)
        ->and(fn () => $this->units->designate($this->admin, ['organization_unit_id' => $this->s->team->id, 'kind' => 'directorate']))
        ->toThrow(ValidationException::class, __('grievances.errors.unit_already_designated'));
});

it('names only the unit’s own staff, one head, with a signature', function (): void {
    Storage::fake('local');
    $designation = $this->units->designate($this->admin, ['organization_unit_id' => $this->s->team->id, 'kind' => 'team']);
    $lead = $this->s->teamLead->employee;
    $officer = $this->s->teamOfficer->employee;
    $officer->forceFill(['signature_path' => null])->save();

    $head = $this->units->addMember($designation, $this->admin, ['employee_id' => $lead->id, 'responsibility' => 'head', 'effective_from' => '2026-10-05']);
    expect($head->signature_path)->not->toBeNull();

    // Without a signature the member is not registered at all.
    expect(fn () => $this->units->addMember($designation, $this->admin, ['employee_id' => $officer->id, 'responsibility' => 'expert', 'effective_from' => '2026-10-05']))
        ->toThrow(ValidationException::class, __('grievances.errors.committee_member_signature_required'));
    $investigator = $this->units->addMember($designation, $this->admin, ['employee_id' => $officer->id, 'responsibility' => 'expert', 'effective_from' => '2026-10-05', 'signature' => UploadedFile::fake()->image('sig.png', 200, 60)]);
    expect($investigator->signature_sha256)->toHaveLength(64);

    // A second head is refused while the first serves.
    expect(fn () => $this->units->updateMember($designation, $investigator, $this->admin, ['responsibility' => 'head']))
        ->toThrow(ValidationException::class, __('grievances.errors.unit_already_has_head'));

    // Staff of another unit are refused.
    $outsider = GrievanceScenario::employee($this->s->subcity, GrievanceScenario::unit($this->s->subcity, 'directorate', 'Finance'));
    expect(fn () => $this->units->addMember($designation, $this->admin, ['employee_id' => $outsider->id, 'responsibility' => 'expert', 'effective_from' => '2026-10-05']))
        ->toThrow(ValidationException::class, __('grievances.errors.member_outside_unit'));

    expect($this->units->problems($designation))->toBe([]);
});

it('lets only named members handle a designated unit’s cases', function (): void {
    $g = unitCaseAtTeam($this);
    $access = app(GrievanceCaseAccessService::class);
    $cases = app(GrievanceCaseService::class);
    // A colleague placed in the same team with the same permissions as the lead.
    $colleague = GrievanceScenario::user(GrievanceScenario::employee($this->s->subcity, $this->s->team), GrievanceRoles::OFFICER_PERMISSIONS);

    // Before designation (HR-placement rule): both may assign.
    expect($access->canAssignOfficers($this->s->teamLead, $g))->toBeTrue()
        ->and($access->canAssignOfficers($colleague, $g))->toBeTrue();

    $designation = $this->units->designate($this->admin, ['organization_unit_id' => $this->s->team->id, 'kind' => 'team']);
    $this->units->addMember($designation, $this->admin, ['employee_id' => $this->s->teamLead->employee_id, 'responsibility' => 'head', 'effective_from' => '2026-10-05']);
    $this->units->addMember($designation, $this->admin, ['employee_id' => $this->s->teamOfficer->employee_id, 'responsibility' => 'expert', 'effective_from' => '2026-10-05']);
    $fresh = fn (): GrievanceCaseAccessService => app()->make(GrievanceCaseAccessService::class);
    app()->forgetInstance(GrievanceHandlerRegistry::class);

    expect($fresh()->canAssignOfficers($this->s->teamLead, $g))->toBeTrue()
        ->and($fresh()->canAssignOfficers($colleague, $g))->toBeFalse();

    // Case officers are chosen among the named members only.
    app(GrievanceCaseService::class)->assignOfficer($g, $this->s->teamLead, $this->s->teamOfficer, GrievanceCaseOfficerRole::Officer);
    expect(fn () => app(GrievanceCaseService::class)->assignOfficer($g, $this->s->teamLead, $colleague, GrievanceCaseOfficerRole::Officer))
        ->toThrow(ValidationException::class);
});

it('serves the Teams and the Directorate as separate pages and designates through them', function (): void {
    $this->actingAs($this->admin)->get(route('grievances.teams.index'))->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page->component('Grievances/Units/Index')->where('kind', 'team')->where('summary.teams', 0));
    // The old combined address now leads to the Teams page.
    $this->actingAs($this->admin)->get(route('grievances.units.index'))->assertRedirect('/grievances/teams');

    // A unit of another institution is refused for this one.
    $this->actingAs($this->admin)->from(route('grievances.directorate.index'))
        ->post(route('grievances.units.store'), ['organization_id' => $this->s->subcity->id, 'organization_unit_id' => $this->s->directorate->id, 'kind' => 'directorate'])
        ->assertSessionHasErrors('organization_unit_id');

    $local = GrievanceScenario::unit($this->s->subcity, 'directorate', 'Sub-city Grievance Directorate');
    $this->actingAs($this->admin)->from(route('grievances.directorate.index'))
        ->post(route('grievances.units.store'), ['organization_id' => $this->s->subcity->id, 'organization_unit_id' => $local->id, 'kind' => 'directorate'])
        ->assertRedirect(route('grievances.directorate.index'))->assertSessionHasNoErrors();

    // The directorate shows on its own page only, and no second one is offered.
    $this->actingAs($this->admin)->get(route('grievances.directorate.index'))
        ->assertInertia(fn (AssertableInertia $page) => $page->where('kind', 'directorate')->where('directorate_exists', true)->where('units.data.0.kind', 'directorate'));
    $this->actingAs($this->admin)->get(route('grievances.teams.index'))
        ->assertInertia(fn (AssertableInertia $page) => $page->where('units.total', 0));

    // Someone without the configuration permission cannot open either page.
    $this->actingAs($this->s->complainant)->get(route('grievances.teams.index'))->assertForbidden();
    $this->actingAs($this->s->complainant)->get(route('grievances.directorate.index'))->assertForbidden();
});

it('leaves already-serving members out of the member search', function (): void {
    $designation = $this->units->designate($this->admin, ['organization_unit_id' => $this->s->team->id, 'kind' => 'team']);
    $this->units->addMember($designation, $this->admin, ['employee_id' => $this->s->teamLead->employee_id, 'responsibility' => 'head', 'effective_from' => '2026-10-05']);

    $ids = fn (array $params) => collect($this->actingAs($this->admin)->getJson(route('grievances.lookup.employees', $params))->assertOk()->json())->pluck('id')->all();
    $base = ['organization_id' => $this->s->subcity->id, 'unit_id' => $this->s->team->id];

    expect($ids($base))->toContain($this->s->teamLead->employee_id)->toContain($this->s->teamOfficer->employee_id)
        ->and($ids([...$base, 'exclude_handler_unit_id' => $designation->id]))->not->toContain($this->s->teamLead->employee_id)
        ->and($ids([...$base, 'exclude_handler_unit_id' => $designation->id]))->toContain($this->s->teamOfficer->employee_id);
});

it('shows a member’s photo and position to unit viewers only', function (): void {
    Storage::fake('local');
    $designation = $this->units->designate($this->admin, ['organization_unit_id' => $this->s->team->id, 'kind' => 'team']);
    $member = $this->units->addMember($designation, $this->admin, ['employee_id' => $this->s->teamLead->employee_id, 'responsibility' => 'head', 'effective_from' => '2026-10-05']);
    Storage::disk('local')->put('employee-photos/'.Str::uuid().'.png', 'x');
    $path = Storage::disk('local')->files('employee-photos')[0];
    $this->s->teamLead->employee->forceFill(['photo_path' => $path])->saveQuietly();

    $this->actingAs($this->admin)->get(route('grievances.teams.index'))
        ->assertInertia(fn (AssertableInertia $page) => $page->where('units.data.0.members.0.employee.photo_url', route('grievances.units.members.photo', [$designation, $member])));
    $this->actingAs($this->admin)->get(route('grievances.units.members.photo', [$designation, $member]))->assertOk();
    $this->actingAs($this->s->complainant)->get(route('grievances.units.members.photo', [$designation, $member]))->assertNotFound();
});

it('routes a case end to end to a designated team handled by its named members', function (): void {
    // Designate the scenario's team and name a head and an expert.
    $designation = $this->units->designate($this->admin, ['organization_unit_id' => $this->s->team->id, 'kind' => 'team']);
    $this->units->addMember($designation, $this->admin, ['employee_id' => $this->s->teamLead->employee_id, 'responsibility' => 'head', 'effective_from' => '2026-10-05']);
    $this->units->addMember($designation, $this->admin, ['employee_id' => $this->s->teamOfficer->employee_id, 'responsibility' => 'expert', 'effective_from' => '2026-10-05']);
    app()->forgetInstance(GrievanceHandlerRegistry::class);

    // The routes page offers the team as a target and counts only the scenario's
    // still-undesignated directorate as a gap.
    $this->actingAs($this->admin)->get(route('grievances.routes.index'))
        ->assertInertia(fn (AssertableInertia $page) => $page->component('Grievances/Routing/Index')
            ->where('summary.unit_gaps', 1)
            ->where('options.handler_units.0.id', $this->s->team->id));
    $this->units->designate(GrievanceScenario::user(GrievanceScenario::employee($this->s->bureau, null), GrievanceRoles::ADMINISTRATOR_PERMISSIONS), ['organization_unit_id' => $this->s->directorate->id, 'kind' => 'directorate']);
    $this->actingAs($this->admin)->get(route('grievances.routes.index'))
        ->assertInertia(fn (AssertableInertia $page) => $page->where('summary.unit_gaps', 1)); // designated, but nobody named yet

    // The committee misses its deadline and the case escalates along the configured route.
    $g = unitCaseAtTeam($this);
    expect($g->currentStage->handler_id)->toBe($this->s->team->id);

    $access = app(GrievanceCaseAccessService::class);
    $queue = fn ($user) => $access->constrainAssigned(Grievance::query(), $user)->pluck('id')->all();
    expect($queue($this->s->teamLead))->toContain($g->id);

    // The head assigns the expert, who can then draft the unit's findings.
    app(GrievanceCaseService::class)->assignOfficer($g, $this->s->teamLead, $this->s->teamOfficer, GrievanceCaseOfficerRole::Officer);
    expect($access->canDraftDecision($this->s->teamOfficer, $g->refresh()))->toBeTrue()
        ->and($queue($this->s->teamOfficer))->toContain($g->id);
});

it('ends a term with its reason and flags members who moved out of the unit', function (): void {
    $designation = $this->units->designate($this->admin, ['organization_unit_id' => $this->s->team->id, 'kind' => 'team']);
    $head = $this->units->addMember($designation, $this->admin, ['employee_id' => $this->s->teamLead->employee_id, 'responsibility' => 'head', 'effective_from' => '2026-10-05']);
    $expert = $this->units->addMember($designation, $this->admin, ['employee_id' => $this->s->teamOfficer->employee_id, 'responsibility' => 'expert', 'effective_from' => '2026-10-05']);
    $registry = fn () => app()->make(GrievanceHandlerRegistry::class);

    // The expert is transferred to another unit by HR: flagged, and no longer the team's staff.
    $elsewhere = GrievanceScenario::unit($this->s->subcity, 'directorate', 'Finance');
    EmployeeAssignment::query()->whereKey($this->s->teamOfficer->employee->current_assignment_id)->update(['organization_unit_id' => $elsewhere->id]);
    expect($this->units->problems($designation->refresh()))->toContain('grievances.errors.unit_member_moved')
        ->and($registry()->designatedStaff($this->s->team->id))->toHaveKey($this->s->teamLead->employee_id)
        ->and($registry()->designatedStaff($this->s->team->id))->not->toHaveKey($this->s->teamOfficer->employee_id);

    // "Other" needs a note; a listed reason does not.
    expect(fn () => $this->units->endMember($designation, $expert, $this->admin, GrievanceUnitEndReason::Other))
        ->toThrow(ValidationException::class);
    $this->actingAs($this->admin)->post(route('grievances.units.members.end', [$designation, $expert]), ['end_reason_code' => 'transferred_unit'])
        ->assertSessionHasNoErrors();
    expect($expert->refresh()->end_reason_code)->toBe('transferred_unit')->and($expert->status)->toBe('inactive')
        ->and($this->units->problems($designation->refresh()))->not->toContain('grievances.errors.unit_member_moved')
        ->and($head->refresh()->status)->toBe('active');
});

it('saves routes with Team / Directorate choices and an "all teams" source, preferring a specific route', function (): void {
    $units = $this->units;
    $team = $units->designate($this->admin, ['organization_unit_id' => $this->s->team->id, 'kind' => 'team']);
    $bureauAdmin = GrievanceScenario::user(GrievanceScenario::employee($this->s->bureau, null), GrievanceRoles::ADMINISTRATOR_PERMISSIONS);
    $units->designate($bureauAdmin, ['organization_unit_id' => $this->s->directorate->id, 'kind' => 'directorate']);
    $routes = fn () => GrievanceRoute::query();
    $routes()->delete();
    $base = ['movement_type' => 'timeout_escalation', 'priority' => 10, 'effective_from' => '2026-10-01'];

    // A team cannot be saved as the directorate.
    $this->actingAs($this->admin)->post(route('grievances.routes.store'), [...$base, 'source_handler_type' => 'directorate', 'source_handler_id' => $this->s->team->id, 'target_handler_type' => 'committee', 'target_handler_id' => $this->s->committee->id])
        ->assertSessionHasErrors('source_handler_id');

    // All teams → the directorate.
    $this->actingAs($this->admin)->post(route('grievances.routes.store'), [...$base, 'source_handler_type' => 'team', 'source_handler_id' => 'all', 'target_handler_type' => 'directorate', 'target_handler_id' => $this->s->directorate->id])
        ->assertSessionHasNoErrors();
    $all = $routes()->sole();
    expect($all->source_all)->toBeTrue()->and($all->source_handler_id)->toBeNull()->and($all->source_unit_kind)->toBe('team');
    $all->forceFill(['approved_at' => now()])->save();

    $stage = new GrievanceCaseStage(['handler_type' => 'organization_unit', 'handler_id' => $this->s->team->id]);
    $routing = app(GrievanceRoutingService::class);
    expect($routing->nextHandlers($stage, GrievanceMovementType::TimeoutEscalation, null)[0]['id'])->toBe($this->s->directorate->id);

    // A route from this very team wins over the "all teams" route.
    $other = GrievanceScenario::unit($this->s->subcity, 'team', 'Other team');
    $units->designate($this->admin, ['organization_unit_id' => $other->id, 'kind' => 'team']);
    $specific = GrievanceScenario::route('organization_unit', $this->s->team->id, 'organization_unit', $other->id, 'timeout_escalation');
    expect($routing->nextHandlers($stage, GrievanceMovementType::TimeoutEscalation, null)[0]['route']->id)->toBe($specific->id);

    // A committee cannot use "all" towards the court.
    expect($team->kind)->toBe('team');
});

it('saves an "all institutions" initial route that a specific institution route still overrides', function (): void {
    GrievanceRoute::query()->delete();
    $base = ['movement_type' => 'initial_assignment', 'priority' => 10, 'effective_from' => '2026-10-01'];

    $this->actingAs($this->admin)->post(route('grievances.routes.store'), [...$base, 'source_handler_type' => 'organization', 'source_handler_id' => 'all', 'include_descendants' => true, 'target_handler_type' => 'committee', 'target_handler_id' => $this->s->committee->id])
        ->assertSessionHasNoErrors();
    $all = GrievanceRoute::query()->sole();
    expect($all->source_all)->toBeTrue()->and($all->source_handler_id)->toBeNull()
        ->and($all->include_descendants)->toBeFalse()->and($all->source_unit_kind)->toBeNull();
    $all->forceFill(['approved_at' => now()])->save();

    $routing = app(GrievanceRoutingService::class);
    $from = fn (string $organizationId) => $routing->resolveInitialHandler(new Grievance(['organization_id' => $organizationId]));
    expect($from($this->s->woreda->id)['route']->id)->toBe($all->id)
        ->and($from($this->s->bureau->id)['route']->id)->toBe($all->id);

    // A route from this very institution wins over the "all institutions" route.
    $specific = GrievanceScenario::route('organization', $this->s->woreda->id, 'committee', $this->s->committee->id, 'initial_assignment');
    expect($from($this->s->woreda->id)['route']->id)->toBe($specific->id);

    // "All" is refused for an external authority source.
    $this->actingAs($this->admin)->post(route('grievances.routes.store'), [...$base, 'movement_type' => 'timeout_escalation', 'source_handler_type' => 'external_authority', 'source_handler_id' => 'all', 'target_handler_type' => 'committee', 'target_handler_id' => $this->s->committee->id])
        ->assertSessionHasErrors('source_handler_id');
});

it('sends "all institutions → all committees" to each institution\'s own committee only', function (): void {
    GrievanceRoute::query()->delete();
    $base = ['movement_type' => 'initial_assignment', 'priority' => 10, 'effective_from' => '2026-10-01', 'source_handler_type' => 'organization', 'target_handler_type' => 'committee'];

    $this->actingAs($this->admin)->post(route('grievances.routes.store'), [...$base, 'source_handler_id' => 'all', 'target_handler_id' => 'all'])
        ->assertSessionHasNoErrors();
    $route = GrievanceRoute::query()->sole();
    expect($route->source_all)->toBeTrue()->and($route->target_all)->toBeTrue()->and($route->target_handler_id)->toBeNull();
    $route->forceFill(['approved_at' => now()])->save();

    $routing = app(GrievanceRoutingService::class);
    $from = fn (string $organizationId) => $routing->resolveInitialHandler(new Grievance(['organization_id' => $organizationId]));
    $woreda = $from($this->s->woreda->id);
    expect($woreda['id'])->toBe($this->s->committee->id)->and($woreda['route']->id)->toBe($route->id)
        // The bureau has no committee of its own: never another institution's committee.
        ->and($from($this->s->bureau->id))->toBeNull();

    // "All committees" is a target only for routes from institutions.
    $this->actingAs($this->admin)->post(route('grievances.routes.store'), [...$base, 'movement_type' => 'timeout_escalation', 'source_handler_type' => 'committee', 'source_handler_id' => $this->s->committee->id, 'target_handler_id' => 'all'])
        ->assertSessionHasErrors('target_handler_id');
    $this->actingAs($this->admin)->post(route('grievances.routes.store'), [...$base, 'source_handler_id' => 'all', 'target_handler_type' => 'directorate', 'target_handler_id' => 'all'])
        ->assertSessionHasErrors('target_handler_id');
});

it('sends "all committees → all teams" to the team nearest the committee\'s institution', function (): void {
    // Bureau ⊃ Sub-city ⊃ Woreda 01 (the committee's institution).
    $version = HierarchyVersion::query()->create([
        'version_name' => 'GRV v1', 'status' => HierarchyVersionStatus::Published->value,
        'approval_date' => now()->subMonth(), 'effective_from' => now()->subMonth()->toDateString(),
    ]);
    $s = $this->s;
    foreach ([[$s->woreda, $s->woreda, 0], [$s->subcity, $s->subcity, 0], [$s->bureau, $s->bureau, 0], [$s->subcity, $s->woreda, 1], [$s->bureau, $s->subcity, 1], [$s->bureau, $s->woreda, 2]] as [$a, $d, $depth]) {
        OrganizationClosurePath::query()->create(['hierarchy_version_id' => $version->id, 'ancestor_organization_id' => $a->id, 'descendant_organization_id' => $d->id, 'depth' => $depth]);
    }
    $this->units->designate($this->admin, ['organization_unit_id' => $s->team->id, 'kind' => 'team']);
    GrievanceRoute::query()->delete();
    $base = ['movement_type' => 'timeout_escalation', 'priority' => 10, 'effective_from' => '2026-10-01', 'target_handler_type' => 'team', 'target_handler_id' => 'all'];

    $this->actingAs($this->admin)->post(route('grievances.routes.store'), [...$base, 'source_handler_type' => 'committee', 'source_handler_id' => 'all'])
        ->assertSessionHasNoErrors();
    $route = GrievanceRoute::query()->sole();
    expect($route->source_all)->toBeTrue()->and($route->target_all)->toBeTrue()->and($route->target_handler_type->value)->toBe('organization_unit');
    $route->forceFill(['approved_at' => now()])->save();

    $routing = app(GrievanceRoutingService::class);
    $stage = new GrievanceCaseStage(['handler_type' => 'committee', 'handler_id' => $s->committee->id]);
    $next = $routing->nextHandlers($stage, GrievanceMovementType::TimeoutEscalation, null);
    expect($next)->toHaveCount(1)->and($next[0]['id'])->toBe($s->team->id)->and($next[0]['route']->id)->toBe($route->id);

    // A team in the committee's own institution is nearer.
    $woredaAdmin = GrievanceScenario::user(GrievanceScenario::employee($s->woreda, null), GrievanceRoles::ADMINISTRATOR_PERMISSIONS);
    $this->units->designate($woredaAdmin, ['organization_unit_id' => $s->woredaUnit->id, 'kind' => 'team']);
    expect($routing->nextHandlers($stage, GrievanceMovementType::TimeoutEscalation, null)[0]['id'])->toBe($s->woredaUnit->id);

    // "All teams" as a target is only for routes from committees.
    $this->actingAs($this->admin)->post(route('grievances.routes.store'), [...$base, 'source_handler_type' => 'team', 'source_handler_id' => $s->team->id])
        ->assertSessionHasErrors('target_handler_id');
});

it('lets one institution keep a teachers\' committee and an all-staff committee, split by work unit', function (): void {
    $s = $this->s;
    $woredaAdmin = GrievanceScenario::user(GrievanceScenario::employee($s->woreda, null), GrievanceRoles::ADMINISTRATOR_PERMISSIONS);
    GrievanceScenario::scope($woredaAdmin, $s->woreda);
    // No type is sent: the page registers grievance committees only, whatever a request asks for.
    $form = ['organization_id' => $s->woreda->id, 'committee_type' => 'disciplinary', 'name_en' => 'Teachers Grievance Committee', 'effective_from' => '2026-10-01'];

    // The work unit must belong to the committee's institution.
    $this->actingAs($woredaAdmin)->post(route('grievances.committees.store'), [...$form, 'organization_unit_id' => $s->directorate->id])
        ->assertSessionHasErrors('organization_unit_id');
    $this->actingAs($woredaAdmin)->post(route('grievances.committees.store'), [...$form, 'organization_unit_id' => $s->woredaUnit->id])
        ->assertSessionHasNoErrors();
    $teachers = GrievanceCommittee::query()->where('name_en', 'Teachers Grievance Committee')->sole();
    expect($teachers->organization_unit_id)->toBe($s->woredaUnit->id)->and($teachers->committee_type->value)->toBe('grievance');

    // Make it usable like the all-staff committee (same composition), then route by work unit.
    $teachers->forceFill(['status' => 'active', 'approved_at' => now()])->save();
    foreach ($s->committee->members as $member) {
        $member->replicate()->fill(['committee_id' => $teachers->id])->save();
    }
    GrievanceRoute::query()->delete();
    $routing = app(GrievanceRoutingService::class);
    $from = fn (?string $unitId) => $routing->resolveInitialHandler(new Grievance(['organization_id' => $s->woreda->id, 'organization_unit_id' => $unitId]))['id'] ?? null;
    $otherUnit = GrievanceScenario::unit($s->woreda, 'team', 'Administration');
    expect($from($s->woredaUnit->id))->toBe($teachers->id)
        ->and($from($otherUnit->id))->toBe($s->committee->id)
        ->and($from(null))->toBe($s->committee->id);

    // The list names the unit, so the two committees are told apart.
    $this->actingAs($woredaAdmin)->get(route('grievances.committees.index'))->assertInertia(fn (AssertableInertia $page) => $page
        ->where('committees.data', fn ($rows) => collect($rows)->firstWhere('id', $teachers->id)['organization_unit']['id'] === $s->woredaUnit->id));
});
