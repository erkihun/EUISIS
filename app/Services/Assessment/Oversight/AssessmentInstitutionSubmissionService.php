<?php

declare(strict_types=1);

namespace App\Services\Assessment\Oversight;

use App\Actions\Audit\WriteAuditLogAction;
use App\Enums\AuditEventType;
use App\Models\AssessmentCycle;
use App\Models\AssessmentCycleOrganization;
use App\Models\AssessmentInstitutionSubmission;
use App\Models\User;
use App\Notifications\PerformanceNotification;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Validation\ValidationException;

/**
 * Institution sign-off of its assessment summary
 * (docs/assessment-institution-submission.md):
 *
 *   validate → SUBMIT → city review → RETURN / REJECT | VERIFY → FINALIZE
 *
 * Totals are calculated, never typed in. The submission stores a snapshot
 * and a fingerprint of its source data; if the data changes afterwards a
 * submitted or verified summary becomes OUTDATED and must be resubmitted.
 * A finalized summary never changes.
 */
class AssessmentInstitutionSubmissionService
{
    /** Statuses that hold the institution's current answer (no new submission while one is open). */
    public const OPEN = ['submitted', 'under_city_review', 'verified', 'finalized'];

    public function __construct(
        private readonly AssessmentCoverageService $coverage,
        private readonly AssessmentResultDistributionService $distribution,
        private readonly AssessmentDataQualityService $quality,
        private readonly OversightAccess $access,
        private readonly WriteAuditLogAction $audit,
    ) {}

    /**
     * @return array{ready: bool, checks: array<int, array{code: string, ok: bool, detail?: mixed}>}
     */
    public function validateReadiness(AssessmentCycle $cycle, string $organizationId): array
    {
        $participation = $this->participation($cycle, $organizationId);
        $scope = new OversightScope([$organizationId]);
        $totals = $this->coverage->totals($cycle, $scope);
        $distribution = $this->distribution->distribution($cycle, $scope);
        $summary = $this->quality->summary($cycle, $scope, $organizationId);
        $blocking = collect($summary['rules'])->filter(fn (array $rule): bool => $rule['severity'] === AssessmentDataQualityService::BLOCKING && $rule['count'] > 0)
            ->map(fn (array $rule): int => $rule['count'])->all();
        $reconciliation = AssessmentCoverageService::reconcile($totals, $distribution['policy'] ? $distribution['classified'] : null, $distribution['policy'] ? $distribution['assessed'] - $this->unclassifiable($summary) : null);
        $latest = $this->latest($cycle, $organizationId);

        $checks = [
            ['code' => 'participating', 'ok' => $participation?->status === 'included'],
            ['code' => 'eligibility_finalized', 'ok' => $cycle->isEligibilityFinalized()],
            ['code' => 'has_population', 'ok' => $totals['population'] > 0],
            ['code' => 'no_blocking_issues', 'ok' => $blocking === [], 'detail' => $blocking],
            ['code' => 'totals_reconcile', 'ok' => collect($reconciliation)->every('ok'), 'detail' => collect($reconciliation)->reject('ok')->values()->all()],
            ['code' => 'no_open_submission', 'ok' => $latest === null || ! in_array($latest->status, self::OPEN, true), 'detail' => $latest?->status],
        ];

        return ['ready' => collect($checks)->every('ok'), 'checks' => $checks];
    }

    public function createSubmission(User $actor, AssessmentCycle $cycle, string $organizationId, ?string $note = null): AssessmentInstitutionSubmission
    {
        $this->authorize($actor, 'assessment_submissions.submit', $organizationId);

        return DB::transaction(function () use ($actor, $cycle, $organizationId, $note): AssessmentInstitutionSubmission {
            // Serialize submissions of one institution.
            $participation = AssessmentCycleOrganization::query()->where('assessment_cycle_id', $cycle->id)->where('organization_id', $organizationId)->lockForUpdate()->first();
            $readiness = $this->validateReadiness($cycle, $organizationId);
            if (! $readiness['ready']) {
                throw ValidationException::withMessages(['submission' => 'The institution is not ready to submit: '.collect($readiness['checks'])->reject('ok')->pluck('code')->implode(', ').'.']);
            }
            $snapshot = $this->snapshotTotals($cycle, $organizationId);
            $submission = AssessmentInstitutionSubmission::query()->create([
                'assessment_cycle_id' => $cycle->id, 'organization_id' => $organizationId,
                'revision_no' => (int) AssessmentInstitutionSubmission::query()->where('assessment_cycle_id', $cycle->id)->where('organization_id', $organizationId)->max('revision_no') + 1,
                'status' => 'submitted', ...$snapshot['columns'], 'summary_snapshot' => $snapshot['summary'], 'source_fingerprint' => $snapshot['fingerprint'],
                'submitted_by' => $actor->id, 'submitted_at' => now(), 'sign_off_note' => $note,
            ]);
            $participation?->update(['submission_status' => 'submitted']);
            $this->event($submission, 'submitted', $actor, $note);
            $this->audit->execute(AuditEventType::AssessmentSubmissionSubmitted, $actor, $submission, $organizationId, newValues: $snapshot['columns'] + ['revision_no' => $submission->revision_no], request: request());
            $this->notify('assessment_submission_received', User::permission('assessment_submissions.review')->where('status', 'active')->limit(50)->get(), $cycle);

            return $submission;
        });
    }

    public function returnForCorrection(User $actor, AssessmentInstitutionSubmission $submission, string $reason): void
    {
        $this->move($actor, $submission, 'assessment_submissions.return', ['submitted', 'under_city_review', 'verified', 'outdated'], 'returned', $reason, true);
    }

    /** Claim a submitted summary for city review without allowing score edits. */
    public function startReview(User $actor, AssessmentInstitutionSubmission $submission, ?string $comment = null): void
    {
        $this->move($actor, $submission, 'assessment_submissions.review', ['submitted'], 'under_city_review', $comment, false);
    }

    public function reject(User $actor, AssessmentInstitutionSubmission $submission, string $reason): void
    {
        $this->move($actor, $submission, 'assessment_submissions.return', ['submitted'], 'rejected', $reason, true);
    }

    public function verify(User $actor, AssessmentInstitutionSubmission $submission, ?string $comment = null): void
    {
        $this->move($actor, $submission, 'assessment_submissions.verify', ['submitted', 'under_city_review'], 'verified', $comment, false);
    }

    public function finalize(User $actor, AssessmentInstitutionSubmission $submission, ?string $comment = null): void
    {
        $this->move($actor, $submission, 'assessment_submissions.finalize', ['verified'], 'finalized', $comment, false);
    }

    /**
     * Compare the stored fingerprint with the live data. An open (submitted
     * or verified) summary whose source changed becomes OUTDATED; a finalized
     * one is left untouched and only reported.
     *
     * @return array{changed: bool, status: string}
     */
    public function detectOutdatedSubmission(AssessmentInstitutionSubmission $submission, ?User $actor = null): array
    {
        if (! in_array($submission->status, ['submitted', 'under_city_review', 'verified', 'finalized'], true)) {
            return ['changed' => false, 'status' => $submission->status];
        }
        $changed = $this->fingerprint($submission->cycle, $submission->organization_id) !== $submission->source_fingerprint;
        if ($changed && $submission->status !== 'finalized') {
            DB::transaction(function () use ($submission, $actor): void {
                $submission->update(['status' => 'outdated', 'outdated_at' => now()]);
                AssessmentCycleOrganization::query()->where('assessment_cycle_id', $submission->assessment_cycle_id)
                    ->where('organization_id', $submission->organization_id)->update(['submission_status' => 'outdated']);
                $this->event($submission, 'outdated', $actor, null);
                $this->audit->execute(AuditEventType::AssessmentSubmissionOutdated, $actor, $submission, $submission->organization_id, newValues: ['revision_no' => $submission->revision_no], request: request());
            });
        }

        return ['changed' => $changed, 'status' => $submission->status];
    }

    /**
     * The calculated totals frozen into a submission.
     *
     * @return array{columns: array<string, mixed>, summary: array<string, mixed>, fingerprint: string}
     */
    public function snapshotTotals(AssessmentCycle $cycle, string $organizationId): array
    {
        $scope = new OversightScope([$organizationId]);
        $totals = $this->coverage->totals($cycle, $scope);
        $distribution = $this->distribution->distribution($cycle, $scope);
        $g = $totals['gender'];

        return [
            'columns' => [
                'eligible_count' => $totals['eligible'], 'excluded_count' => $totals['excluded'],
                'assessed_count' => $totals['assessed'], 'unassessed_count' => $totals['unassessed'],
                'male_eligible' => $g['male']['eligible'], 'female_eligible' => $g['female']['eligible'], 'unknown_eligible' => $g['unknown']['eligible'] + $g['other']['eligible'],
                'male_assessed' => $g['male']['assessed'], 'female_assessed' => $g['female']['assessed'], 'unknown_assessed' => $g['unknown']['assessed'] + $g['other']['assessed'],
                'coverage_percent' => $totals['coverage_percent'],
            ],
            'summary' => ['totals' => $totals, 'bands' => $distribution['bands'], 'band_policy' => $distribution['policy'], 'unclassified' => $distribution['unclassified']],
            'fingerprint' => $this->fingerprint($cycle, $organizationId, $totals, $distribution),
        ];
    }

    public function latest(AssessmentCycle $cycle, string $organizationId): ?AssessmentInstitutionSubmission
    {
        return AssessmentInstitutionSubmission::query()->where('assessment_cycle_id', $cycle->id)->where('organization_id', $organizationId)->orderByDesc('revision_no')->first();
    }

    /**
     * Hash of the totals plus raw change markers read straight from the
     * tables (uncached), so any edit to the source shows up.
     */
    private function fingerprint(AssessmentCycle $cycle, string $organizationId, ?array $totals = null, ?array $distribution = null): string
    {
        $scope = new OversightScope([$organizationId]);
        $totals ??= $this->coverage->totals($cycle, $scope);
        $distribution ??= $this->distribution->distribution($cycle, $scope);
        $records = DB::table('assessment_records')->where('assessment_cycle_id', $cycle->id)->where('organization_id', $organizationId)
            ->selectRaw('COUNT(*) as n, MAX(updated_at) as changed')->first();
        $eligibility = DB::table('assessment_cycle_employee_eligibility')->where('assessment_cycle_id', $cycle->id)->where('organization_id', $organizationId)
            ->selectRaw('COUNT(*) as n, MAX(updated_at) as changed')->first();
        $responses = DB::table('assessment_responses as s')->join('assessment_records as r', 'r.id', '=', 's.assessment_record_id')
            ->where('r.assessment_cycle_id', $cycle->id)->where('r.organization_id', $organizationId)->selectRaw('COUNT(s.submitted_at) as n, MAX(s.updated_at) as changed')->first();
        $marks = [
            'totals' => array_diff_key($totals, ['group_key' => 1]),
            'bands' => array_map(fn (array $b): array => [$b['code'], $b['count']], $distribution['bands']),
            'records' => (array) $records, 'eligibility' => (array) $eligibility, 'responses' => (array) $responses,
        ];

        return hash('sha256', json_encode($marks));
    }

    private function move(User $actor, AssessmentInstitutionSubmission $submission, string $permission, array $from, string $to, ?string $comment, bool $commentRequired): void
    {
        $this->authorize($actor, $permission, $submission->organization_id);
        if ($commentRequired && trim((string) $comment) === '') {
            throw ValidationException::withMessages(['comment' => 'A reason is required.']);
        }

        // Checked (and recorded) outside the move, so the OUTDATED mark survives the refusal.
        if (in_array($to, ['under_city_review', 'verified', 'finalized'], true) && $submission->status !== 'finalized'
            && $this->detectOutdatedSubmission($submission, $actor)['changed']) {
            throw ValidationException::withMessages(['submission' => 'The institution data changed after submission. It must be resubmitted.']);
        }

        DB::transaction(function () use ($actor, $submission, $from, $to, $comment): void {
            $submission = AssessmentInstitutionSubmission::query()->lockForUpdate()->findOrFail($submission->id);
            if ($submission->status === 'finalized') {
                throw ValidationException::withMessages(['submission' => 'A finalized submission cannot change.']);
            }
            if (in_array($to, ['under_city_review', 'verified', 'finalized'], true)) {
                abort_if($submission->submitted_by === $actor->id, 403, 'The person who submitted cannot verify or finalize the same submission.');
            }
            if ($to === 'finalized') {
                abort_if($submission->verified_by === $actor->id, 403, 'The person who verified cannot finalize the same submission.');
            }
            if (! in_array($submission->status, $from, true)) {
                throw ValidationException::withMessages(['submission' => "A {$submission->status} submission cannot be {$to}."]);
            }

            $columns = match ($to) {
                'returned', 'rejected' => ['returned_by' => $actor->id, 'returned_at' => now(), 'return_reason' => $comment],
                'under_city_review' => ['city_reviewer_id' => $actor->id, 'review_started_at' => now()],
                'verified' => ['verified_by' => $actor->id, 'verified_at' => now()],
                'finalized' => ['finalized_by' => $actor->id, 'finalized_at' => now()],
            };
            $submission->update(['status' => $to, ...$columns]);
            AssessmentCycleOrganization::query()->where('assessment_cycle_id', $submission->assessment_cycle_id)
                ->where('organization_id', $submission->organization_id)->update(['submission_status' => $to]);
            $this->event($submission, $to, $actor, $comment);
            $event = match ($to) {
                'returned' => AuditEventType::AssessmentSubmissionReturned,
                'rejected' => AuditEventType::AssessmentSubmissionRejected,
                'under_city_review' => AuditEventType::AssessmentSubmissionReviewStarted,
                'verified' => AuditEventType::AssessmentSubmissionVerified,
                'finalized' => AuditEventType::AssessmentSubmissionFinalized,
            };
            $this->audit->execute($event, $actor, $submission, $submission->organization_id, newValues: ['revision_no' => $submission->revision_no], reason: $comment, request: request());
            if (in_array($to, ['returned', 'rejected'], true)) {
                $this->notify('assessment_submission_returned', User::query()->whereKey($submission->submitted_by)->where('status', 'active')->get(), $submission->cycle);
            }
        });
    }

    private function authorize(User $actor, string $permission, string $organizationId): void
    {
        abort_unless($actor->can($permission) && $this->access->canSeeOrganization($actor, $organizationId), 403);
    }

    private function participation(AssessmentCycle $cycle, string $organizationId): ?AssessmentCycleOrganization
    {
        return AssessmentCycleOrganization::query()->where('assessment_cycle_id', $cycle->id)->where('organization_id', $organizationId)->first();
    }

    /** Assessed results the band check expects to classify: all of them, less those already flagged unclassified. */
    private function unclassifiable(array $summary): int
    {
        return (int) ($summary['rules']['UNCLASSIFIED_RESULT']['count'] ?? 0);
    }

    private function event(AssessmentInstitutionSubmission $submission, string $action, ?User $actor, ?string $comment): void
    {
        $submission->events()->create(['action' => $action, 'actor_id' => $actor?->id, 'comment' => $comment, 'created_at' => now()]);
    }

    /** @param iterable<int, User> $users */
    private function notify(string $kind, iterable $users, AssessmentCycle $cycle): void
    {
        $users = collect($users);
        if ($users->isNotEmpty()) {
            Notification::send($users, new PerformanceNotification($kind, route('assessment-oversight.submissions', ['cycle' => $cycle->id], false), ['database']));
        }
    }
}
