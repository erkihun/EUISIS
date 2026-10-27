<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** A request for an ID card (EUISIS CardRequestStatus / CardRequestType). */
class CardRequest extends Model
{
    use HasUuids;

    public const SUBMITTED = 'submitted';

    public const APPROVED = 'approved';

    public const REJECTED = 'rejected';

    public const CANCELLED = 'cancelled';

    /** Waiting for a decision; an employee has at most one. */
    public const PENDING = ['draft', self::SUBMITTED, 'verified'];

    public const TYPES = ['new', 'renewal', 'replacement', 'lost', 'damaged', 'correction'];

    protected $guarded = ['id', 'pending_employee_id'];

    protected function casts(): array
    {
        return [
            'submitted_at' => 'datetime', 'verified_at' => 'datetime', 'approved_at' => 'datetime', 'rejected_at' => 'datetime', 'cancelled_at' => 'datetime',
            'requested_by' => 'integer', 'approved_by' => 'integer',
        ];
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    public function previousCard(): BelongsTo
    {
        return $this->belongsTo(IdCard::class, 'previous_card_id');
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

    /** The cards this request produced. */
    public function cards(): HasMany
    {
        return $this->hasMany(IdCard::class, 'card_request_id');
    }
}
