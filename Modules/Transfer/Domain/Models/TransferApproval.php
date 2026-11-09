<?php

declare(strict_types=1);

namespace Modules\Transfer\Domain\Models;

use App\Models\Concerns\HasUuidPrimaryKey;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Modules\Transfer\Domain\Enums\TransferApprovalStatus;
use Modules\Transfer\Domain\Enums\TransferApprovalType;

class TransferApproval extends Model
{
    use HasUuidPrimaryKey;

    protected $fillable = [
        'transfer_application_id',
        'approval_type',
        'status',
        'approver_id',
        'rejection_reason',
        'decided_at',
    ];

    protected function casts(): array
    {
        return [
            'approval_type' => TransferApprovalType::class,
            'status' => TransferApprovalStatus::class,
            'decided_at' => 'datetime',
        ];
    }

    public function transferApplication(): BelongsTo
    {
        return $this->belongsTo(TransferApplication::class);
    }

    public function approver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approver_id');
    }

    public function isPending(): bool
    {
        return $this->status === TransferApprovalStatus::Pending;
    }

    public function isApproved(): bool
    {
        return $this->status === TransferApprovalStatus::Approved;
    }
}
