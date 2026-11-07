<?php

declare(strict_types=1);

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
use Modules\Grievance\Contracts\Data\GrievanceRoles;
use Modules\Grievance\Domain\Enums\GrievanceCaseOfficerRole;
use Modules\Grievance\Domain\Models\Grievance;
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
    expect(fn () => $this->units->addMember($designation, $this->admin, ['employee_id' => $officer->id, 'responsibility' => 'investigator', 'effective_from' => '2026-10-05']))
        ->toThrow(ValidationException::class, __('grievances.errors.committee_member_signature_required'));
    $investigator = $this->units->addMember($designation, $this->admin, ['employee_id' => $officer->id, 'responsibility' => 'investigator', 'effective_from' => '2026-10-05', 'signature' => UploadedFile::fake()->image('sig.png', 200, 60)]);
    expect($investigator->signature_sha256)->toHaveLength(64);

    // A second head is refused while the first serves.
    expect(fn () => $this->units->updateMember($designation, $investigator, $this->admin, ['responsibility' => 'head']))
        ->toThrow(ValidationException::class, __('grievances.errors.unit_already_has_head'));

    // Staff of another unit are refused.
    $outsider = GrievanceScenario::employee($this->s->subcity, GrievanceScenario::unit($this->s->subcity, 'directorate', 'Finance'));
    expect(fn () => $this->units->addMember($designation, $this->admin, ['employee_id' => $outsider->id, 'responsibility' => 'investigator', 'effective_from' => '2026-10-05']))
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
    $this->units->addMember($designation, $this->admin, ['employee_id' => $this->s->teamOfficer->employee_id, 'responsibility' => 'investigator', 'effective_from' => '2026-10-05']);
    $fresh = fn (): GrievanceCaseAccessService => app()->make(GrievanceCaseAccessService::class);
    app()->forgetInstance(GrievanceHandlerRegistry::class);

    expect($fresh()->canAssignOfficers($this->s->teamLead, $g))->toBeTrue()
        ->and($fresh()->canAssignOfficers($colleague, $g))->toBeFalse();

    // Case officers are chosen among the named members only.
    app(GrievanceCaseService::class)->assignOfficer($g, $this->s->teamLead, $this->s->teamOfficer, GrievanceCaseOfficerRole::Officer);
    expect(fn () => app(GrievanceCaseService::class)->assignOfficer($g, $this->s->teamLead, $colleague, GrievanceCaseOfficerRole::Officer))
        ->toThrow(ValidationException::class);
});

it('serves the Teams & Directorates page and designates through it', function (): void {
    $this->actingAs($this->admin)->get(route('grievances.units.index'))->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page->component('Grievances/Units/Index')->where('summary.teams', 0));

    // A unit of another institution is refused for this one.
    $this->actingAs($this->admin)->from(route('grievances.units.index'))
        ->post(route('grievances.units.store'), ['organization_id' => $this->s->subcity->id, 'organization_unit_id' => $this->s->directorate->id, 'kind' => 'directorate'])
        ->assertSessionHasErrors('organization_unit_id');

    $local = GrievanceScenario::unit($this->s->subcity, 'directorate', 'Sub-city Grievance Directorate');
    $this->actingAs($this->admin)->from(route('grievances.units.index'))
        ->post(route('grievances.units.store'), ['organization_id' => $this->s->subcity->id, 'organization_unit_id' => $local->id, 'kind' => 'directorate'])
        ->assertRedirect(route('grievances.units.index'))->assertSessionHasNoErrors();

    $this->actingAs($this->admin)->get(route('grievances.units.index'))
        ->assertInertia(fn (AssertableInertia $page) => $page->where('summary.directorates', 1)->where('units.data.0.kind', 'directorate'));

    // Someone without the configuration permission cannot open it.
    $this->actingAs($this->s->complainant)->get(route('grievances.units.index'))->assertForbidden();
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

    $this->actingAs($this->admin)->get(route('grievances.units.index'))
        ->assertInertia(fn (AssertableInertia $page) => $page->where('units.data.0.members.0.employee.photo_url', route('grievances.units.members.photo', [$designation, $member])));
    $this->actingAs($this->admin)->get(route('grievances.units.members.photo', [$designation, $member]))->assertOk();
    $this->actingAs($this->s->complainant)->get(route('grievances.units.members.photo', [$designation, $member]))->assertNotFound();
});

it('routes a case end to end to a designated team handled by its named members', function (): void {
    // Designate the scenario's team and name a head and an investigator.
    $designation = $this->units->designate($this->admin, ['organization_unit_id' => $this->s->team->id, 'kind' => 'team']);
    $this->units->addMember($designation, $this->admin, ['employee_id' => $this->s->teamLead->employee_id, 'responsibility' => 'head', 'effective_from' => '2026-10-05']);
    $this->units->addMember($designation, $this->admin, ['employee_id' => $this->s->teamOfficer->employee_id, 'responsibility' => 'investigator', 'effective_from' => '2026-10-05']);
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

    // The head assigns the investigator, who can then draft the unit's findings.
    app(GrievanceCaseService::class)->assignOfficer($g, $this->s->teamLead, $this->s->teamOfficer, GrievanceCaseOfficerRole::Officer);
    expect($access->canDraftDecision($this->s->teamOfficer, $g->refresh()))->toBeTrue()
        ->and($queue($this->s->teamOfficer))->toContain($g->id);
});
