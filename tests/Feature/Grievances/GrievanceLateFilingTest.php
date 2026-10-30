<?php

declare(strict_types=1);

use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Carbon;
use Modules\Grievance\Application\Services\GrievanceCaseService;
use Modules\Grievance\Contracts\Data\GrievanceStatus;
use Modules\Grievance\Domain\Enums\GrievanceInitialResolutionOutcome as Outcome;
use Modules\Grievance\Domain\Models\Grievance;
use Modules\Grievance\Domain\Models\GrievanceStatusHistory;
use Tests\Support\GrievanceScenario;

afterEach(fn () => Carbon::setTestNow());

function lateGrievance(GrievanceScenario $scenario): Grievance
{
    $service = app(GrievanceCaseService::class);
    Carbon::setTestNow(Carbon::parse('2026-03-02 09:00:00'));
    $issue = $service->raiseInitialIssue($scenario->complainant, ['issue_subject' => 'A', 'issue_description' => 'B']);
    $service->recordInitialResolution($issue->refresh(), $scenario->complainant, Outcome::Unresolved, []);
    Carbon::setTestNow(Carbon::parse('2026-03-18 09:00:00')); // past the 10-wd deadline

    return $service->submitFormalGrievance($issue->refresh(), $scenario->complainant, [
        'cause_of_grievance' => 'x', 'requested_remedy' => 'y', 'incident_date' => '2026-02-20',
        'category_id' => $scenario->category->id,
    ]);
}

it('records an immutable status-history row on formal submission', function () {
    $scenario = GrievanceScenario::build();
    $grievance = lateGrievance($scenario);

    $row = GrievanceStatusHistory::query()
        ->where('grievance_id', $grievance->id)
        ->where('to_status', GrievanceStatus::Submitted->value)
        ->first();

    expect($row)->not->toBeNull();
    expect($row->from_status)->toBe(GrievanceStatus::Draft->value);
    expect($row->actor_user_id)->toBe($scenario->complainant->id);
    expect($row->transitioned_at)->not->toBeNull();
});

it('captures a late filing without auto-accepting it', function () {
    $scenario = GrievanceScenario::build();
    $grievance = lateGrievance($scenario);

    expect($grievance->is_late_submission)->toBeTrue();
    expect($grievance->late_review_status)->toBeNull();
});

it('lets an intake officer accept a late filing and records the reviewer', function () {
    $scenario = GrievanceScenario::build();
    $grievance = lateGrievance($scenario);
    $service = app(GrievanceCaseService::class);

    $reviewed = $service->reviewLateSubmission($grievance->refresh(), $scenario->intake, true, 'Illness documented.');

    expect($reviewed->late_review_status)->toBe('accepted');
    expect($reviewed->late_reviewed_by)->toBe($scenario->intake->id);
    expect($reviewed->late_reviewed_at)->not->toBeNull();
    expect($reviewed->late_decision_reason)->toBe('Illness documented.');
});

it('forbids late-filing review without the intake permission', function () {
    $scenario = GrievanceScenario::build();
    $grievance = lateGrievance($scenario);
    $service = app(GrievanceCaseService::class);

    $service->reviewLateSubmission($grievance->refresh(), $scenario->complainant, true, 'nope');
})->throws(AuthorizationException::class);
