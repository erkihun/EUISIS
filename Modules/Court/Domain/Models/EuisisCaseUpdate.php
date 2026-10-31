<?php

declare(strict_types=1);

namespace Modules\Court\Domain\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** One change of a referred case, as EUISIS reads it; the id is the cursor. */
class EuisisCaseUpdate extends Model
{
    protected $table = 'court_euisis_case_updates';

    public const UPDATED_AT = null;

    protected $guarded = [];

    protected $casts = [
        'changed' => 'array',
        'next_hearing_at' => 'datetime',
        'decision_date' => 'date',
    ];

    public function referral(): BelongsTo
    {
        return $this->belongsTo(EuisisReferral::class, 'euisis_referral_id');
    }
}
