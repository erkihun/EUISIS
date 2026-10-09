<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\CardRequestStatus;
use App\Enums\CardRequestType;
use App\Models\Concerns\HasUuidPrimaryKey;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class CardRequest extends Model
{
    use HasUuidPrimaryKey;

    protected $fillable = [
        'employee_id',
        'requested_by',
        'reviewed_by',
        'approved_by',
        'rejected_by',
        'cancelled_by',
        'request_type',
        'status',
        'request_reason',
        'verification_notes',
        'rejection_reason',
        'cancellation_reason',
        'notes',
        'previous_card_id',
        'submitted_at',
        'verified_at',
        'approved_at',
        'rejected_at',
        'cancelled_at',
    ];

    protected function casts(): array
    {
        return [
            'status' => CardRequestStatus::class,
            'request_type' => CardRequestType::class,
            'submitted_at' => 'datetime',
            'verified_at' => 'datetime',
            'approved_at' => 'datetime',
            'rejected_at' => 'datetime',
            'cancelled_at' => 'datetime',
        ];
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    public function requester(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by');
    }

    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }

    public function approver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    public function rejecter(): BelongsTo
    {
        return $this->belongsTo(User::class, 'rejected_by');
    }

    public function canceller(): BelongsTo
    {
        return $this->belongsTo(User::class, 'cancelled_by');
    }

    public function previousCard(): BelongsTo
    {
        return $this->belongsTo(IdCard::class, 'previous_card_id');
    }

    public function cards(): HasMany
    {
        return $this->hasMany(IdCard::class);
    }
}
