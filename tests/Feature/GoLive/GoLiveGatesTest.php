<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Artisan;

/*
 * ADR 0004: the go-live gates are part of production:readiness. Evidence that
 * people produce (drills, penetration test, UAT, named roles) is recorded as
 * references; a gate stays closed while its reference is missing or open
 * P0/P1 defects are not explicitly zero.
 */

function gateLine(string $output, string $label): string
{
    preg_match('/'.preg_quote($label, '/').'[ .]+(PASS|FAIL|WARN)/', $output, $match);

    return $match[1] ?? 'MISSING';
}

it('keeps every mandatory gate closed until its evidence is recorded', function (): void {
    Artisan::call('production:readiness', ['--strict' => true]);
    $output = Artisan::output();

    foreach (['G2 Restore drill met the recovery targets', 'G1 Database failover drill recorded', 'G4 Independent penetration test with no open P0/P1', 'G4 UAT signed off with no open P0/P1', 'G5 Governance roles named'] as $gate) {
        expect(gateLine($output, $gate))->toBe('FAIL');
    }
    // Retention is a policy decision still open, but records are kept meanwhile: a warning, not a block.
    expect(gateLine($output, 'G6 Retention schedule approved for every record class'))->toBe('WARN')
        ->and($output)->toContain('NEEDS_POLICY_DECISION_RETENTION');
});

it('opens a gate only with the reference and zero open P0/P1 defects', function (): void {
    config([
        'backup.objectives_validated_by' => 'DRILL-RESTORE-1',
        'golive.failover_drill' => 'DRILL-FAILOVER-1',
        'golive.penetration_test' => ['report' => 'PT-2026-01', 'open_p0_p1' => null],
        'golive.uat' => ['signoff' => 'UAT-2026-01', 'open_p0_p1' => '0'],
        'golive.governance_roles' => array_fill_keys(array_keys(config('golive.governance_roles')), 'Named holder'),
    ]);
    Artisan::call('production:readiness', ['--strict' => true]);
    $output = Artisan::output();

    expect(gateLine($output, 'G2 Restore drill met the recovery targets'))->toBe('PASS')
        ->and(gateLine($output, 'G1 Database failover drill recorded'))->toBe('PASS')
        // Unknown open-defect count keeps the gate closed.
        ->and(gateLine($output, 'G4 Independent penetration test with no open P0/P1'))->toBe('FAIL')
        ->and(gateLine($output, 'G4 UAT signed off with no open P0/P1'))->toBe('PASS')
        ->and(gateLine($output, 'G5 Governance roles named'))->toBe('PASS');

    config(['golive.penetration_test.open_p0_p1' => '0']);
    Artisan::call('production:readiness', ['--strict' => true]);
    expect(gateLine(Artisan::output(), 'G4 Independent penetration test with no open P0/P1'))->toBe('PASS');
});
