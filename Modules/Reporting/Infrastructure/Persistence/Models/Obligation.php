<?php

declare(strict_types=1);

namespace Modules\Reporting\Infrastructure\Persistence\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Modules\Reporting\Domain\Compliance\ObligationFacts;
use Modules\Reporting\Domain\Enums\Applicability;
use Modules\Reporting\Domain\Enums\WorkflowStatus;

/**
 * What one institution (or one assigned person) owes for one cycle. Created
 * when the cycle is published, whether or not anyone ever opens the module:
 * a missing report is an obligation with no submission.
 *
 * @property WorkflowStatus $workflow_status
 * @property Applicability $applicability
 * @property CarbonImmutable $opens_at
 * @property CarbonImmutable $original_due_at
 * @property CarbonImmutable $effective_due_at
 * @property ?CarbonImmutable $first_submitted_at
 * @property ?CarbonImmutable $review_due_at
 * @property ?CarbonImmutable $revision_due_at
 */
final class Obligation extends ReportingModel
{
    protected $table = 'reporting_obligations';

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'workflow_status' => WorkflowStatus::class,
            'applicability' => Applicability::class,
            'hierarchy_snapshot' => 'array',
            'opens_at' => 'immutable_datetime',
            'original_due_at' => 'immutable_datetime',
            'effective_due_at' => 'immutable_datetime',
            'first_submitted_at' => 'immutable_datetime',
            'latest_submitted_at' => 'immutable_datetime',
            'revision_due_at' => 'immutable_datetime',
            'review_due_at' => 'immutable_datetime',
            'decided_at' => 'immutable_datetime',
            'deadline_missed_at' => 'immutable_datetime',
            'current_version' => 'integer',
            'lock_version' => 'integer',
        ];
    }

    /** @return BelongsTo<Cycle, $this> */
    public function cycle(): BelongsTo
    {
        return $this->belongsTo(Cycle::class, 'cycle_id');
    }

    /** @return HasMany<Submission, $this> */
    public function submissions(): HasMany
    {
        return $this->hasMany(Submission::class, 'obligation_id')->orderBy('version_no');
    }

    /** @return HasMany<Draft, $this> */
    public function drafts(): HasMany
    {
        return $this->hasMany(Draft::class, 'obligation_id');
    }

    /** The facts the compliance rules read, as they stand now. */
    public function facts(): ObligationFacts
    {
        return new ObligationFacts(
            applicability: $this->applicability,
            workflow: $this->workflow_status,
            originalDueAt: $this->original_due_at->toDateTimeImmutable(),
            effectiveDueAt: $this->effective_due_at->toDateTimeImmutable(),
            firstSubmittedAt: $this->first_submitted_at?->toDateTimeImmutable(),
            reviewDueAt: $this->review_due_at?->toDateTimeImmutable(),
        );
    }

    /**
     * Who owes the report, by name: the work unit when the report is a unit's
     * own, otherwise the institution. Falls back to the other language.
     */
    public function subjectName(string $language): ?string
    {
        [$en, $am] = $this->organization_unit_id === null
            ? [$this->organization_name_en, $this->organization_name_am]
            : [$this->unit_name_en, $this->unit_name_am];

        return $language === 'am' ? ($am ?: $en) : ($en ?: $am);
    }
}
