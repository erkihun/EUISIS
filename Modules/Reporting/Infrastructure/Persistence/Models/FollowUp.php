<?php

declare(strict_types=1);

namespace Modules\Reporting\Infrastructure\Persistence\Models;

use Illuminate\Database\Eloquent\Relations\HasMany;

/** An action arising from a report; its own lifecycle, apart from submission compliance. */
final class FollowUp extends ReportingModel
{
    protected $table = 'reporting_follow_ups';

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'due_at' => 'immutable_datetime',
            'closed_at' => 'immutable_datetime',
            'progress' => 'integer',
        ];
    }

    /** @return HasMany<covariant ReportingModel, $this> */
    public function updates(): HasMany
    {
        return $this->hasMany(FollowUpUpdate::class, 'follow_up_id')->orderBy('created_at');
    }
}
