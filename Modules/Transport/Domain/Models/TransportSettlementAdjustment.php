<?php

declare(strict_types=1);

namespace Modules\Transport\Domain\Models;

use App\Models\Concerns\HasUuidPrimaryKey;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Modules\Transport\Domain\Enums\TransportAdjustmentOrigin;
use Modules\Transport\Domain\Enums\TransportAdjustmentStatus;

/** A dispute or adjustment on a statement; it never edits the boarding evidence. */
class TransportSettlementAdjustment extends Model
{
    use HasUuidPrimaryKey;

    protected $fillable = [
        'transport_settlement_id', 'origin', 'kind', 'dispute_type', 'amount', 'quantity', 'reason', 'evidence_path', 'status',
        'requested_by_provider_user_id', 'requested_by_user_id', 'decided_by', 'decided_at', 'decision_notes',
    ];

    protected function casts(): array
    {
        return [
            'origin' => TransportAdjustmentOrigin::class,
            'status' => TransportAdjustmentStatus::class,
            'amount' => 'decimal:2',
            'quantity' => 'integer',
            'decided_at' => 'datetime',
        ];
    }

    public function settlement(): BelongsTo
    {
        return $this->belongsTo(TransportSettlement::class, 'transport_settlement_id');
    }

    public function decidedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'decided_by');
    }
}
