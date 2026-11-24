<?php

declare(strict_types=1);

namespace Modules\Reporting\Infrastructure\Persistence\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** A parent-level report compiled from approved submissions of one cycle. */
final class Consolidation extends ReportingModel
{
    protected $table = 'reporting_consolidations';

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'missing' => 'array',
            'indicators' => 'array',
            'compiled_at' => 'immutable_datetime',
            'lock_version' => 'integer',
        ];
    }

    /** @return BelongsTo<covariant ReportingModel, $this> */
    public function cycle(): BelongsTo
    {
        return $this->belongsTo(Cycle::class, 'cycle_id');
    }

    /** @return HasMany<covariant ReportingModel, $this> */
    public function sources(): HasMany
    {
        return $this->hasMany(ConsolidationSource::class, 'consolidation_id');
    }
}
