<?php

declare(strict_types=1);

namespace Modules\Reporting\Application\UseCases;

use Carbon\CarbonImmutable;
use DateTimeImmutable;
use Illuminate\Support\Facades\DB;
use Modules\Reporting\Application\Data\ActorContext;
use Modules\Reporting\Application\Ports\AuditRecorder;
use Modules\Reporting\Application\Ports\ReportingAccessResolver;
use Modules\Reporting\Application\Support\ReportingAccessDenied;
use Modules\Reporting\Application\Support\ReportingGuard;
use Modules\Reporting\Application\Support\ReportingSettings;
use Modules\Reporting\Contracts\Data\ReportingRoles;
use Modules\Reporting\Domain\Enums\Applicability;
use Modules\Reporting\Domain\Workflow\WorkflowViolation;
use Modules\Reporting\Infrastructure\Persistence\Models\ApplicabilityDecision;
use Modules\Reporting\Infrastructure\Persistence\Models\Extension;
use Modules\Reporting\Infrastructure\Persistence\Models\Obligation;

/**
 * Deadline extensions, exemptions and cancellations, and who is responsible.
 *
 * Fairness rules: the original deadline is never overwritten; an extension
 * request alone changes nothing; an extension granted after the deadline it
 * replaces is labelled retrospective; evidence of earlier lateness (the
 * "deadline missed" stamp, compliance against the original deadline) stays.
 * Every decision is a dated record, so compliance can be reported as of any
 * past moment.
 */
final readonly class ManageDeadlines
{
    public function __construct(
        private ReportingAccessResolver $access,
        private AuditRecorder $audit,
        private ReportingSettings $settings,
    ) {}

    public function requestExtension(ActorContext $actor, string $obligationId, DateTimeImmutable $requestedDueAt, string $reason): Extension
    {
        $this->settings->assertWritable();
        if (trim($reason) === '') {
            throw new WorkflowViolation('reason_required');
        }

        return DB::transaction(function () use ($actor, $obligationId, $requestedDueAt, $reason): Extension {
            $obligation = Obligation::query()->lockForUpdate()->findOrFail($obligationId);
            if (! ReportingGuard::onReportingSide($actor, $obligation) || ! ($actor->can(ReportingRoles::PREPARE) || $actor->can(ReportingRoles::SUBMIT))) {
                throw new ReportingAccessDenied(ReportingRoles::SUBMIT);
            }
            // Allowed after a late submission too: such an extension is retrospective and labelled so.
            if ($obligation->applicability !== Applicability::Required) {
                throw new WorkflowViolation('extension_not_applicable');
            }
            if ($requestedDueAt <= $obligation->effective_due_at->toDateTimeImmutable()) {
                throw new WorkflowViolation('extension_must_be_later');
            }
            if (Extension::query()->where('obligation_id', $obligation->id)->where('status', 'pending')->exists()) {
                throw new WorkflowViolation('extension_already_pending');
            }

            $extension = Extension::query()->create([
                'obligation_id' => $obligation->id, 'requested_due_at' => CarbonImmutable::instance($requestedDueAt)->utc(), 'reason' => trim($reason),
                'requested_by' => $actor->userId, 'requested_by_name' => $actor->name, 'requested_at' => CarbonImmutable::now('UTC'), 'status' => 'pending',
            ]);
            $this->audit->record('extension_requested', $actor, 'reporting.extension', $extension->id, $obligation->organization_id,
                ['requested_due_at' => $extension->requested_due_at->toIso8601String()], $reason, $obligation->id);

            return $extension;
        });
    }

    /** Approves (optionally with a different date) or rejects a request. Only an approval moves the effective deadline. */
    public function decideExtension(ActorContext $actor, string $extensionId, bool $approve, string $reason, ?DateTimeImmutable $approvedDueAt = null): Extension
    {
        $this->settings->assertWritable();
        if (trim($reason) === '') {
            throw new WorkflowViolation('reason_required');
        }

        return DB::transaction(function () use ($actor, $extensionId, $approve, $reason, $approvedDueAt): Extension {
            $extension = Extension::query()->lockForUpdate()->findOrFail($extensionId);
            $obligation = Obligation::query()->lockForUpdate()->findOrFail($extension->obligation_id);
            ReportingGuard::requireReceivingSide($actor, $obligation, ReportingRoles::GRANT_EXTENSIONS);
            if ($extension->status !== 'pending') {
                throw new WorkflowViolation('extension_already_decided');
            }
            // The person who asked cannot grant their own request.
            if ((int) $extension->requested_by === $actor->userId) {
                throw new WorkflowViolation('self_review_forbidden');
            }

            $now = CarbonImmutable::now('UTC');
            $decision = ['status' => $approve ? 'approved' : 'rejected', 'decided_by' => $actor->userId, 'decided_by_name' => $actor->name, 'decided_at' => $now, 'decision_reason' => trim($reason)];
            if ($approve) {
                $due = CarbonImmutable::instance($approvedDueAt ?? $extension->requested_due_at->toDateTimeImmutable())->utc();
                if ($due <= $obligation->effective_due_at) {
                    throw new WorkflowViolation('extension_must_be_later');
                }
                $decision += [
                    'previous_due_at' => $obligation->effective_due_at, 'approved_due_at' => $due,
                    'is_retrospective' => $now > $obligation->effective_due_at,
                ];
                // original_due_at and deadline_missed_at are deliberately left alone.
                $obligation->forceFill(['effective_due_at' => $due, 'lock_version' => $obligation->lock_version + 1])->save();
            }
            $extension->forceFill($decision)->save();
            $this->audit->record('extension_decided', $actor, 'reporting.extension', $extension->id, $obligation->organization_id, [
                'approved' => $approve, 'previous_due_at' => $extension->previous_due_at?->toIso8601String(),
                'approved_due_at' => $extension->approved_due_at?->toIso8601String(), 'is_retrospective' => (bool) $extension->is_retrospective,
            ], $reason, $obligation->id);

            return $extension;
        });
    }

    /** Exempts, cancels or reinstates an obligation, effective now, with the authority's reason. */
    public function setApplicability(ActorContext $actor, string $obligationId, Applicability $applicability, string $reason): Obligation
    {
        $this->settings->assertWritable();
        if (trim($reason) === '') {
            throw new WorkflowViolation('reason_required');
        }

        return DB::transaction(function () use ($actor, $obligationId, $applicability, $reason): Obligation {
            $obligation = Obligation::query()->lockForUpdate()->findOrFail($obligationId);
            ReportingGuard::requireReceivingSide($actor, $obligation, ReportingRoles::GRANT_EXTENSIONS);
            if ($obligation->applicability === $applicability) {
                throw new WorkflowViolation('applicability_unchanged');
            }
            $now = CarbonImmutable::now('UTC');
            $before = $obligation->applicability->value;
            ApplicabilityDecision::query()->create([
                'obligation_id' => $obligation->id, 'applicability' => $applicability->value, 'reason' => trim($reason),
                'decided_by' => $actor->userId, 'decided_by_name' => $actor->name, 'effective_at' => $now, 'created_at' => $now,
            ]);
            $obligation->forceFill(['applicability' => $applicability, 'lock_version' => $obligation->lock_version + 1])->save();
            $this->audit->record('applicability_changed', $actor, 'reporting.obligation', $obligation->id, $obligation->organization_id,
                ['from' => $before, 'to' => $applicability->value], $reason, $obligation->id);

            return $obligation;
        });
    }

    /**
     * Names a new preparer or accountable officer. The obligation carries the
     * change from now on; the history entry keeps who was responsible before.
     */
    public function reassignResponsible(ActorContext $actor, string $obligationId, ?int $preparerUserId, ?int $accountableUserId, string $reason): Obligation
    {
        $this->settings->assertWritable();
        if (trim($reason) === '') {
            throw new WorkflowViolation('reason_required');
        }

        return DB::transaction(function () use ($actor, $obligationId, $preparerUserId, $accountableUserId, $reason): Obligation {
            $obligation = Obligation::query()->lockForUpdate()->findOrFail($obligationId);
            // The institution's own officer, or the receiving side's schedule authority.
            $own = $actor->can(ReportingRoles::SUBMIT) && ReportingGuard::onReportingSide($actor, $obligation);
            $authority = $actor->can(ReportingRoles::PUBLISH_SCHEDULES) && ReportingGuard::onReceivingSide($actor, $obligation);
            if (! $own && ! $authority) {
                throw new ReportingAccessDenied(ReportingRoles::SUBMIT);
            }
            $users = $this->access->users(array_values(array_filter([$preparerUserId, $accountableUserId])));
            foreach (array_filter([$preparerUserId, $accountableUserId]) as $id) {
                if (! isset($users[$id])) {
                    throw new WorkflowViolation('unknown_user');
                }
            }
            $before = $obligation->only(['preparer_user_id', 'preparer_name', 'accountable_user_id', 'accountable_name']);
            $obligation->forceFill([
                'preparer_user_id' => $preparerUserId, 'preparer_name' => $preparerUserId === null ? null : $users[$preparerUserId]->name,
                'accountable_user_id' => $accountableUserId, 'accountable_name' => $accountableUserId === null ? null : $users[$accountableUserId]->name,
            ])->save();
            $this->audit->record('obligation_reassigned', $actor, 'reporting.obligation', $obligation->id, $obligation->organization_id,
                ['before' => $before, 'after' => $obligation->only(['preparer_user_id', 'preparer_name', 'accountable_user_id', 'accountable_name'])], $reason, $obligation->id);

            return $obligation;
        });
    }
}
