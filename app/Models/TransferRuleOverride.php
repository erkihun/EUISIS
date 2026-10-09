<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\TransferOverrideStatus;
use App\Models\Concerns\HasUuidPrimaryKey;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TransferRuleOverride extends Model
{
    use HasUuidPrimaryKey;

    protected $fillable = [
        'transfer_application_id',
        'requested_by',
        'approved_by',
        'rule_key',
        'reason',
        'status',
        'decided_at',
    ];

    protected function casts(): array
    {
        return [
            'status' => TransferOverrideStatus::class,
            'decided_at' => 'datetime',
        ];
    }

    public function transferApplication(): BelongsTo
    {
        return $this->belongsTo(TransferApplication::class);
    }

    public function requestedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by');
    }

    public function approvedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    public function isApproved(): bool
    {
        return $this->status === TransferOverrideStatus::Approved;
    }
}
