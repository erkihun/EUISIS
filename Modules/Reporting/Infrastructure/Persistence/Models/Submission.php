<?php

declare(strict_types=1);

namespace Modules\Reporting\Infrastructure\Persistence\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use LogicException;

/**
 * An immutable submitted version: the snapshot taken at the moment of
 * submission. Only its review status and decision time may change afterwards;
 * any other change, and any delete, is refused here.
 *
 * @property CarbonImmutable $submitted_at
 */
final class Submission extends ReportingModel
{
    private const MUTABLE = ['status', 'decided_at', 'updated_at'];

    protected $table = 'reporting_submissions';

    protected static function booted(): void
    {
        self::updating(function (self $submission): void {
            $changed = array_diff(array_keys($submission->getDirty()), self::MUTABLE);
            if ($changed !== []) {
                throw new LogicException('A submitted version is immutable; refused to change: '.implode(', ', $changed));
            }
        });
        self::deleting(fn () => throw new LogicException('A submitted version is never deleted.'));
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'content' => 'array',
            'metadata' => 'array',
            'sections_snapshot' => 'array',
            'is_nil' => 'boolean',
            'is_late' => 'boolean',
            'completeness_declared' => 'boolean',
            'submitted_at' => 'immutable_datetime',
            'decided_at' => 'immutable_datetime',
            'version_no' => 'integer',
        ];
    }

    /** @return BelongsTo<Obligation, $this> */
    public function obligation(): BelongsTo
    {
        return $this->belongsTo(Obligation::class, 'obligation_id');
    }

    /** @return HasMany<ReviewStep, $this> */
    public function reviewSteps(): HasMany
    {
        return $this->hasMany(ReviewStep::class, 'submission_id')->orderBy('step_no');
    }
}
