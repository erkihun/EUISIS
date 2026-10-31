<?php

declare(strict_types=1);

namespace Modules\Court\Domain\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** A grievance EUISIS referred to the tribunal, and the case it opened. */
class EuisisReferral extends Model
{
    protected $table = 'court_euisis_referrals';

    protected $guarded = [];

    protected $casts = [
        'payload' => 'array',
        'last_reported_state' => 'array',
    ];

    public function courtCase(): BelongsTo
    {
        return $this->belongsTo(CourtCase::class, 'court_case_id');
    }

    public function updates(): HasMany
    {
        return $this->hasMany(EuisisCaseUpdate::class, 'euisis_referral_id');
    }
}
