<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\HasUuidPrimaryKey;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AdministrativeTribunalCase extends Model
{
    use HasUuidPrimaryKey;

    protected $fillable = [
        'grievance_id',
        'case_number',
        'status',
        'decision_summary',
        'hearing_date',
        'decision_date',
        'assigned_to_user_id',
        'created_by_user_id',
    ];

    /** court-ms referral states (ADR 0013): null = never sent. */
    public const REFERRAL_QUEUED = 'queued';

    public const REFERRAL_SENT = 'sent';

    public const REFERRAL_FAILED = 'failed';

    protected function casts(): array
    {
        return [
            // Plain dates on the page: the form's date inputs take YYYY-MM-DD, and a
            // UTC timestamp would show the day before east of Greenwich.
            'hearing_date' => 'date:Y-m-d',
            'decision_date' => 'date:Y-m-d',
            'referred_at' => 'datetime',
            'court_synced_at' => 'datetime',
            'referral_payload' => 'array',
            'referral_attempts' => 'integer',
        ];
    }

    public function referredByUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'referred_by_user_id');
    }

    /** Sent to court-ms, or on its way: a second referral is never allowed. */
    public function isReferred(): bool
    {
        return in_array($this->referral_state, [self::REFERRAL_QUEUED, self::REFERRAL_SENT], true);
    }

    public function grievance(): BelongsTo
    {
        return $this->belongsTo(Grievance::class);
    }

    public function assignedToUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_to_user_id');
    }

    public function createdByUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }

    public function isOpen(): bool
    {
        return in_array($this->status, ['open', 'hearing'], true);
    }
}
