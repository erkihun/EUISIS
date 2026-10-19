<?php

declare(strict_types=1);

use App\Models\EmployeeAssignment;
use App\Services\Intelligence\IntelligenceArea;
use App\Services\Intelligence\IntelligencePermissions;
use Tests\Support\IntelligenceBusinessFixture as F;
use Tests\Support\SituationRoomScenario;

/*
 * Data Quality Intelligence: every check is a scope-confined count with a
 * severity, an impact and a remediation; FAIL checks with records raise an
 * insight; installation-wide public-feedback checks appear only for an
 * unrestricted viewer.
 */

beforeEach(function (): void {
    F::createPermissions();
    $this->s = SituationRoomScenario::build();
    $this->grants = [...F::viewerGrants(), IntelligencePermissions::DATA_QUALITY];
});

function dqRow(array $panel, string $code): ?array
{
    foreach ($panel['data']['rows'] as $row) {
        if ($row['check']['key'] === "intelligence.quality.{$code}.label") {
            return $row;
        }
    }

    return null;
}

it('counts contradictory assignments inside the scope and raises a FAIL insight', function (): void {
    // The database forbids a second current assignment (partial unique index), so the contradiction planted
    // here is an assignment that ends before it starts.
    $employee = $this->s->people['woreda_a1'][0];
    EmployeeAssignment::query()->whereKey($employee->current_assignment_id)->update(['effective_to' => now()->subYears(2)->toDateString()]);

    $a = F::area(IntelligenceArea::DataQuality, $this->s->viewer($this->grants, 'subcity_a'));
    expect($a['has_errors'])->toBeFalse();
    $table = F::panel($a['panels'], 'quality_checks');
    $row = dqRow($table, 'assignments_effective_to_before_from');
    expect($row['count'])->toBe(1)
        ->and($row['severity']['key'])->toBe('intelligence.values.severity_code.FAIL')
        ->and($row['impact']['key'])->toBe('intelligence.quality.assignments_effective_to_before_from.impact')
        ->and($row['remediation']['key'])->toBe('intelligence.quality.assignments_effective_to_before_from.remediation')
        ->and(dqRow($table, 'duplicate_current_assignments')['count'])->toBe(0)
        ->and(dqRow($table, 'positions_without_jd')['count'])->toBe(3)
        ->and(dqRow($table, 'positions_without_occupation')['count'])->toBe(3)
        ->and(dqRow($table, 'assignments_outside_hierarchy')['count'])->toBe(0);

    $insights = array_filter($a['insights'], fn (array $i): bool => $i['key'] === 'data_quality_fail');
    $metrics = array_map(fn (array $i): string => $i['metric'], $insights);
    expect($metrics)->toContain('assignments_effective_to_before_from')->toContain('positions_over_capacity');

    $b = F::panel(F::area(IntelligenceArea::DataQuality, $this->s->viewer($this->grants, 'subcity_b'))['panels'], 'quality_checks');
    expect(dqRow($b, 'assignments_effective_to_before_from')['count'])->toBe(0)
        ->and(dqRow($b, 'positions_without_jd')['count'])->toBe(2);
});

it('shows the public-feedback integrity checks to an unrestricted viewer only', function (): void {
    $scoped = F::area(IntelligenceArea::DataQuality, $this->s->viewer($this->grants, 'subcity_a'));
    expect(F::panel($scoped['panels'], 'quality_public_feedback'))->toBeNull();

    $unrestricted = F::area(IntelligenceArea::DataQuality, $this->s->viewer($this->grants));
    $panel = F::panel($unrestricted['panels'], 'quality_public_feedback');
    expect($panel)->not->toBeNull()->and($panel['type'])->toBe('table');
    expect(json_encode($unrestricted, JSON_THROW_ON_ERROR))->not->toContain('Private Submitter')->not->toContain('Person 0');
});
