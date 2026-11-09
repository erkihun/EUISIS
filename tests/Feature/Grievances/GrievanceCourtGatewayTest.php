<?php

declare(strict_types=1);

use App\Models\AdministrativeTribunalCase;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;
use Modules\Grievance\Application\Services\GrievanceAppealService;
use Modules\Grievance\Application\Services\GrievanceCaseService;
use Modules\Grievance\Application\Services\GrievanceCorrespondenceService;
use Modules\Grievance\Application\Services\GrievanceEscalationService;
use Modules\Grievance\Application\Services\GrievanceHandlerUnitService;
use Modules\Grievance\Contracts\Data\GrievanceRoles;
use Modules\Grievance\Domain\Enums\GrievanceHandlerType;
use Modules\Grievance\Domain\Enums\GrievanceLetterLanguage;
use Modules\Grievance\Domain\Models\Grievance;
use Modules\Grievance\Domain\Models\GrievanceAppeal;
use Modules\Grievance\Domain\Models\GrievanceDecision;
use Modules\Grievance\Domain\Models\GrievanceExternalAuthority;
use Tests\Support\GrievanceScenario;

/*
 * ADR 0030 (owner decision): there is one Grievance Directorate, and a case
 * reaches the Administrative Court only from it — after its decision, or when
 * it does not decide in time — never straight from a committee or a team.
 */

beforeEach(function (): void {
    Carbon::setTestNow(Carbon::parse('2026-10-05 09:00:00'));
    $this->s = GrievanceScenario::build();
    $this->admin = GrievanceScenario::user(GrievanceScenario::employee($this->s->bureau, null), GrievanceRoles::ADMINISTRATOR_PERMISSIONS);
    GrievanceScenario::scope($this->admin, $this->s->bureau);
    $this->tribunal = GrievanceExternalAuthority::query()->create([
        'code' => 'TRB-'.str()->upper(str()->random(6)), 'name_en' => 'Administrative Court',
        'organization_id' => $this->s->bureau->id, 'is_administrative_tribunal' => true, 'is_active' => true,
    ]);
});

afterEach(fn () => Carbon::setTestNow());

function gatewayCase(object $test): Grievance
{
    $cases = app(GrievanceCaseService::class);
    $g = $cases->createDraft($test->s->complainant, ['subject' => 'Grading', 'description' => 'Narrative.', 'category_id' => $test->s->category->id, 'incident_date' => '2026-09-20']);

    return $cases->intakeAccept($cases->submit($g, $test->s->complainant), $test->s->intake, 'Complete');
}

it('keeps one Grievance Directorate in the system', function (): void {
    $units = app(GrievanceHandlerUnitService::class);
    $units->designate($this->admin, ['organization_unit_id' => $this->s->directorate->id, 'kind' => 'directorate']);
    $second = GrievanceScenario::unit($this->s->bureau, 'directorate', 'Another Directorate');

    expect(fn () => $units->designate($this->admin, ['organization_unit_id' => $second->id, 'kind' => 'directorate']))
        ->toThrow(ValidationException::class, __('grievances.errors.only_one_directorate'));
    // A team is fine.
    expect($units->designate($this->admin, ['organization_unit_id' => $second->id, 'kind' => 'team'])->kind)->toBe('team');
});

it('allows a route to the court only from the Grievance Directorate', function (): void {
    $payload = fn (string $type, string $id) => [
        'source_handler_type' => $type, 'source_handler_id' => $id,
        'target_handler_type' => 'external_authority', 'target_handler_id' => $this->tribunal->id,
        'movement_type' => 'employee_appeal', 'priority' => 10, 'effective_from' => '2026-10-01',
    ];
    $this->actingAs($this->admin)->post(route('grievances.routes.store'), $payload('committee', $this->s->committee->id))
        ->assertSessionHasErrors('source_handler_id');

    app(GrievanceHandlerUnitService::class)->designate($this->admin, ['organization_unit_id' => $this->s->directorate->id, 'kind' => 'directorate']);
    $this->actingAs($this->admin)->post(route('grievances.routes.store'), $payload('organization_unit', $this->s->directorate->id))
        ->assertSessionHasNoErrors();
});

it('never moves a case to the court from a committee, even along an old route', function (): void {
    $g = gatewayCase($this);
    // A route written before this rule, straight from the committee to the court.
    $route = GrievanceScenario::route('committee', $this->s->committee->id, 'external_authority', $this->tribunal->id, 'referred');

    expect(fn () => app(GrievanceEscalationService::class)->refer($g, $this->s->chair, 'To court.', $route->id))
        ->toThrow(ValidationException::class);
    expect($g->refresh()->current_handler_type)->toBe(GrievanceHandlerType::Committee);
});

it('lets the employee go to the court when the Grievance Directorate does not decide in time', function (): void {
    $g = $this->s->moveToDirectorate(gatewayCase($this));
    GrievanceScenario::route('organization_unit', $this->s->directorate->id, 'external_authority', $this->tribunal->id, 'employee_appeal');
    $appeals = app(GrievanceAppealService::class);

    // Still within the directorate's time: no appeal yet.
    $g->currentStage->forceFill(['due_at' => now()->addDays(3)])->save();
    expect($appeals->eligibility($g->refresh())['allowed'])->toBeFalse();

    // Past due with no decision: the court is open to the employee.
    $g->currentStage->forceFill(['due_at' => now()->subDay()])->save();
    $eligibility = $appeals->eligibility($g->refresh());
    expect($eligibility['allowed'])->toBeTrue()->and($eligibility['overdue'])->toBeTrue();

    $appeal = $appeals->file($g, $this->s->complainant, 'The directorate did not decide in time.');
    expect($appeal->appealed_decision_id)->toBeNull()
        ->and($g->refresh()->current_handler_type)->toBe(GrievanceHandlerType::ExternalAuthority)
        ->and(AdministrativeTribunalCase::query()->where('grievance_id', $g->id)->exists())->toBeTrue()
        ->and(GrievanceAppeal::query()->count())->toBe(1);
});

it('tells the employee about the court only on a Grievance Directorate decision', function (): void {
    $g = gatewayCase($this);
    $letters = app(GrievanceCorrespondenceService::class);
    $atCommittee = GrievanceDecision::query()->forceCreate(['grievance_id' => $g->id, 'case_stage_id' => $g->current_stage_id, 'version_no' => 1, 'status' => 'draft', 'decision_text' => 'x']);
    expect($letters->tokenValues($g, GrievanceLetterLanguage::En, ['decision' => $atCommittee])['appeal_notice'])->toBe(__('grievances.letters.appeal_notice_internal', [], 'en'));

    $g = $this->s->moveToDirectorate($g);
    $atDirectorate = GrievanceDecision::query()->forceCreate(['grievance_id' => $g->id, 'case_stage_id' => $g->current_stage_id, 'version_no' => 1, 'status' => 'draft', 'decision_text' => 'x']);
    expect($letters->tokenValues($g, GrievanceLetterLanguage::En, ['decision' => $atDirectorate])['appeal_notice'])->toBe(__('grievances.letters.appeal_notice', [], 'en'));
});
