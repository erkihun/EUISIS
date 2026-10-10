<?php

declare(strict_types=1);

use App\Enums\Grievance\GrievanceCommitteeRole;
use App\Enums\Grievance\GrievanceCommitteeSelectionSource as Source;
use App\Services\Legal\StatutoryCommitteeRules;

it('matches the statutory selection-source constants', function () {
    expect(Source::AppointedByHead->value)->toBe(StatutoryCommitteeRules::APPOINTED);
    expect(Source::ElectedByEmployees->value)->toBe(StatutoryCommitteeRules::ELECTED);
    expect(Source::HrRepresentative->value)->toBe(StatutoryCommitteeRules::HR_REPRESENTATIVE);
});

it('exposes all three selection sources as values', function () {
    expect(Source::values())->toEqualCanonicalizing([
        StatutoryCommitteeRules::APPOINTED,
        StatutoryCommitteeRules::ELECTED,
        StatutoryCommitteeRules::HR_REPRESENTATIVE,
    ]);
});

it('treats the writer role as the HR secretary (no separate secretary case)', function () {
    // The grievance committee's HR professional serves as secretary; in this
    // system that slot is the Writer role, and legacy 'secretary' maps to it.
    expect(GrievanceCommitteeRole::tryFrom('secretary'))->toBeNull();
    expect(GrievanceCommitteeRole::Writer->value)->toBe('writer');
});
