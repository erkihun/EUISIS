<?php

declare(strict_types=1);

namespace App\Actions;

use App\Models\DataQualityIssue;
use App\Models\User;
use App\Services\AuditWriter;
use App\Services\OrganizationScope;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * The data-quality issue workflow (ADR 0011), inside the actor's scope:
 *
 *   open → investigating (data_quality.manage, optionally assigned to someone);
 *   open or investigating → resolved or false positive (data_quality.manage,
 *   with what was done); → accepted risk (data_quality.accept_risk, with the
 *   reason the risk is accepted).
 *
 * An issue with no organization (city-level checks) is handled city-wide.
 * Every step is audited; closing an issue needs a written note.
 */
final readonly class ManageDataQualityIssues
{
    public const STEPS = ['investigate', 'resolve', 'false_positive', 'accept_risk'];

    public function __construct(private AuditWriter $audit, private OrganizationScope $scope) {}

    public function step(DataQualityIssue $issue, string $step, ?string $note, ?int $assignTo, User $actor): DataQualityIssue
    {
        abort_unless(in_array($step, self::STEPS, true), 404);
        $permission = $step === 'accept_risk' ? 'data_quality.accept_risk' : 'data_quality.manage';
        abort_unless($actor->is_active && $actor->can($permission), 403);
        if ($step !== 'investigate' && mb_strlen(trim((string) $note)) < 5) {
            throw ValidationException::withMessages(['note' => 'Say what was done or why (at least 5 characters).']);
        }

        return DB::transaction(function () use ($issue, $step, $note, $assignTo, $actor): DataQualityIssue {
            $issue = DataQualityIssue::query()->lockForUpdate()->findOrFail($issue->id);
            if (! $this->visible($issue, $actor)) {
                throw ValidationException::withMessages(['issue' => 'This issue is outside your organizations.']);
            }
            if (! in_array($issue->status, DataQualityIssue::ACTIVE, true)) {
                throw ValidationException::withMessages(['issue' => 'This issue is already closed.']);
            }
            if ($assignTo !== null) {
                $assignee = User::query()->whereKey($assignTo)->where('is_active', true)->first();
                if ($assignee === null || ! $assignee->can('data_quality.manage') || ! $this->visible($issue, $assignee)) {
                    throw ValidationException::withMessages(['assigned_to' => 'Choose someone who works on data quality for this organization.']);
                }
            }
            $before = $issue->status;
            $changes = match ($step) {
                'investigate' => ['status' => DataQualityIssue::INVESTIGATING, 'assigned_to' => $assignTo ?? $issue->assigned_to ?? $actor->getKey()],
                'resolve' => ['status' => DataQualityIssue::RESOLVED, 'resolution' => $note, 'resolved_by' => $actor->getKey(), 'resolved_at' => now(), 'auto_resolved' => false],
                'false_positive' => ['status' => DataQualityIssue::FALSE_POSITIVE, 'resolution' => $note, 'resolved_by' => $actor->getKey(), 'resolved_at' => now()],
                'accept_risk' => ['status' => DataQualityIssue::ACCEPTED_RISK, 'resolution' => $note, 'accepted_risk_by' => $actor->getKey(), 'accepted_risk_at' => now()],
            };
            $issue->forceFill($changes)->save();
            $this->audit->write('data_quality.'.$step, $actor, $issue, $issue->organization_id, ['status' => $before],
                ['status' => $issue->status, 'assigned_to' => $issue->assigned_to], $note);

            return $issue;
        });
    }

    /** Inside the reader's organizations; an issue with no organization only city-wide. */
    public function visible(DataQualityIssue $issue, User $user): bool
    {
        $ids = $this->scope->organizationIds($user);

        return $ids === null || ($issue->organization_id !== null && in_array((string) $issue->organization_id, $ids, true));
    }
}
