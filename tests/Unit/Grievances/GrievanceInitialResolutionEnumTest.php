<?php

declare(strict_types=1);

use Modules\Grievance\Domain\Enums\GrievanceInitialResolutionOutcome as Outcome;
use Modules\Grievance\Domain\Enums\GrievanceInitialResolutionStatus as Status;

it('marks resolved and failed as terminal statuses', function () {
    expect(Status::Resolved->isTerminal())->toBeTrue();
    expect(Status::Failed->isTerminal())->toBeTrue();
    expect(Status::Pending->isTerminal())->toBeFalse();
});

it('starts the filing clock only on unresolved or no-response outcomes', function () {
    expect(Outcome::Unresolved->startsFilingClock())->toBeTrue();
    expect(Outcome::NoResponse->startsFilingClock())->toBeTrue();
    expect(Outcome::Resolved->startsFilingClock())->toBeFalse();
});
