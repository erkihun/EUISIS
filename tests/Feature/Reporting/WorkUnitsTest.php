<?php

declare(strict_types=1);

use App\Models\OrganizationUnit;
use Modules\Reporting\Application\Queries\ComplianceQuery;
use Modules\Reporting\Application\Support\ReportingGuard;
use Modules\Reporting\Application\UseCases\ConsolidateReports;
use Modules\Reporting\Application\UseCases\ManageSchedules;
use Modules\Reporting\Contracts\Data\ReportingRoles;
use Modules\Reporting\Domain\Workflow\WorkflowViolation;
use Modules\Reporting\Infrastructure\Persistence\Models\Obligation;
use Tests\Support\ReportingScenario;

/*
 * A work unit inside an institution owes a report of its own, and it reports
 * inside that institution: the institution receives, reviews and consolidates
 * it, and nobody outside the institution sees it.
 */

beforeEach(function (): void {
    ReportingScenario::clock();
    $this->s = ReportingScenario::build()->configure();
    $this->unit = fn (string $org, string $code, string $en, string $am, string $status = 'active'): OrganizationUnit => OrganizationUnit::query()->create([
        'organization_id' => $this->s->org[$org]->id, 'unit_type' => 'department', 'code' => $code, 'name_en' => $en, 'name_am' => $am, 'status' => $status,
    ]);
    $this->planning = ($this->unit)('HQ', 'HQ-PLN', 'Planning Directorate', 'የዕቅድ ዳይሬክቶሬት');
    $this->finance = ($this->unit)('HQ', 'HQ-FIN', 'Finance Directorate', 'የፋይናንስ ዳይሬክቶሬት');
    $this->head = $this->s->user([ReportingRoles::VIEW, ReportingRoles::PREPARE, ReportingRoles::SUBMIT]);
    $this->bind = fn (array $targets) => app(ManageSchedules::class)->save($this->s->actor($this->s->admin), $this->s->schedule->id, [
        'report_type_id' => $this->s->type->id, 'receiving_organization_id' => $this->s->org['HQ']->id,
    ], $targets);
    $this->units = fn (): array => [
        ['organization_id' => $this->s->org['HQ']->id, 'organization_unit_id' => $this->planning->id, 'accountable_user_id' => $this->head->id],
        ['organization_id' => $this->s->org['HQ']->id, 'organization_unit_id' => $this->finance->id],
    ];
});

it('creates one obligation per work unit, received by the unit\'s own institution', function (): void {
    ($this->bind)(($this->units)());
    $cycle = $this->s->publish();
    $this->s->publish('2026-11-05');

    $obligations = Obligation::query()->where('cycle_id', $cycle->id)->orderBy('unit_code')->get();
    expect($obligations)->toHaveCount(2)
        ->and($obligations->pluck('subject_key')->all())->toBe(["unit:{$this->finance->id}", "unit:{$this->planning->id}"])
        ->and($obligations->pluck('organization_id')->unique()->all())->toBe([$this->s->org['HQ']->id])
        ->and($obligations->pluck('receiving_organization_id')->unique()->all())->toBe([$this->s->org['HQ']->id])
        ->and($obligations[1]->only(['unit_code', 'unit_name_en', 'unit_name_am', 'accountable_user_id']))
        ->toBe(['unit_code' => 'HQ-PLN', 'unit_name_en' => 'Planning Directorate', 'unit_name_am' => 'የዕቅድ ዳይሬክቶሬት', 'accountable_user_id' => $this->head->id]);

    // The name is a snapshot: renaming the unit later does not rewrite the published obligation.
    $this->planning->update(['name_en' => 'Strategy Directorate']);
    expect($obligations[1]->fresh()->subjectName('en'))->toBe('Planning Directorate');
});

it('refuses a unit of another institution, an unknown unit and a closed unit', function (): void {
    $elsewhere = ($this->unit)('A', 'A-PLN', 'Trade Planning', 'የንግድ ዕቅድ');
    $closed = ($this->unit)('HQ', 'HQ-OLD', 'Closed team', 'የተዘጋ ቡድን', 'inactive');

    // Unit A-PLN belongs to the Trade Bureau, but the reports of this schedule go to the bureau HQ.
    expect(fn () => ($this->bind)([['organization_id' => $this->s->org['A']->id, 'organization_unit_id' => $elsewhere->id]]))->toThrow(WorkflowViolation::class, 'unit_outside_institution')
        // A unit named under an institution it does not belong to.
        ->and(fn () => ($this->bind)([['organization_id' => $this->s->org['HQ']->id, 'organization_unit_id' => $elsewhere->id]]))->toThrow(WorkflowViolation::class, 'unknown_unit')
        ->and(fn () => ($this->bind)([['organization_id' => $this->s->org['HQ']->id, 'organization_unit_id' => $closed->id]]))->toThrow(WorkflowViolation::class, 'unknown_unit');
});

it('skips a unit that was closed after the schedule was saved', function (): void {
    ($this->bind)(($this->units)());
    $this->finance->update(['status' => 'inactive']);

    expect(Obligation::query()->where('cycle_id', $this->s->publish()->id)->pluck('unit_code')->all())->toBe(['HQ-PLN']);
});

it('keeps a unit\'s report inside its institution', function (): void {
    ($this->bind)(($this->units)());
    $cycle = $this->s->publish();
    $planning = Obligation::query()->where('cycle_id', $cycle->id)->where('organization_unit_id', $this->planning->id)->firstOrFail();
    $finance = Obligation::query()->where('cycle_id', $cycle->id)->where('organization_unit_id', $this->finance->id)->firstOrFail();
    $register = fn ($user): int => app(ComplianceQuery::class)->register($this->s->actor($user), [])->total();

    $inside = $this->s->user([ReportingRoles::VIEW], ['HQ']);
    $outside = $this->s->user([ReportingRoles::VIEW], ['A']);

    expect($register($inside))->toBe(2)
        ->and($register($outside))->toBe(0)
        ->and(ReportingGuard::onReportingSide($this->s->actor($outside), $planning))->toBeFalse()
        // The unit head holds no scope at all: named on one unit's report, that is the one they reach.
        ->and($register($this->head))->toBe(1)
        ->and(ReportingGuard::onReportingSide($this->s->actor($this->head), $planning))->toBeTrue()
        ->and(ReportingGuard::onReportingSide($this->s->actor($this->head), $finance))->toBeFalse();

    // Submitted by the unit head, decided by the institution's own reviewers.
    $submission = $this->s->writeAndSubmit($planning, $this->head);
    $this->s->approve($submission);
    expect($planning->fresh()->workflow_status->value)->toBe('approved');
});

it('shows each unit on its own row of the matrix and of the register', function (): void {
    ($this->bind)([...($this->units)(), ['organization_id' => $this->s->org['A']->id]]);
    $this->s->publish();
    $actor = $this->s->actor($this->s->admin);

    $matrix = app(ComplianceQuery::class)->matrix($actor, ['report_type_id' => $this->s->type->id]);
    $rows = collect($matrix['institutions']->items());
    expect($rows)->toHaveCount(3)
        ->and($rows->pluck('unit.code')->all())->toBe([null, 'HQ-FIN', 'HQ-PLN'])
        ->and($rows->every(fn (array $row): bool => count($row['cells']) === 1))->toBeTrue();

    $found = app(ComplianceQuery::class)->register($actor, ['search' => 'planning']);
    expect($found->total())->toBe(1)
        ->and($found->items()[0]['unit'])->toMatchArray(['code' => 'HQ-PLN', 'name_am' => 'የዕቅድ ዳይሬክቶሬት']);
});

it('consolidates the units\' approved reports into the institution\'s report and names the missing unit', function (): void {
    ($this->bind)(($this->units)());
    $cycle = $this->s->publish();
    $planning = Obligation::query()->where('cycle_id', $cycle->id)->where('organization_unit_id', $this->planning->id)->firstOrFail();
    $this->s->approve($this->s->writeAndSubmit($planning, $this->head));
    ReportingScenario::clock('2026-11-20 09:00');

    $consolidate = app(ConsolidateReports::class);
    $actor = $this->s->actor($this->s->admin);
    $draft = $consolidate->start($actor, $cycle->id, $this->s->org['HQ']->id, 'Bureau monthly report');
    $compiled = $consolidate->compile($actor, $draft->id);

    expect($compiled->indicators['expected'])->toBe(2)
        ->and($compiled->indicators['included'])->toBe(1)
        ->and(collect($compiled->missing)->pluck('status', 'code')->all())->toBe(['HQ-FIN' => 'not_submitted']);
});

it('lists an institution\'s units only to someone who may bind it', function (): void {
    $publisher = $this->s->user([ReportingRoles::VIEW, ReportingRoles::PUBLISH_SCHEDULES], ['HQ']);

    $this->actingAs($publisher)->getJson(route('reporting.lookups.units', ['organization_id' => $this->s->org['HQ']->id]))
        ->assertOk()->assertJsonCount(2, 'items')->assertJsonPath('items.0.organization_id', $this->s->org['HQ']->id);
    $this->actingAs($publisher)->getJson(route('reporting.lookups.units', ['organization_id' => $this->s->org['A']->id]))->assertForbidden();
    $this->actingAs($this->head)->getJson(route('reporting.lookups.units', ['organization_id' => $this->s->org['HQ']->id]))->assertForbidden();
});
