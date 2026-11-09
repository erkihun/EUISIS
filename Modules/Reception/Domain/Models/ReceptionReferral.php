<?php

declare(strict_types=1);

namespace Modules\Reception\Domain\Models;

use App\Models\Concerns\HasUuidPrimaryKey;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

final class ReceptionReferral extends Model
{
    use HasUuidPrimaryKey;

    protected $fillable = ['visit_id', 'from_organization_id', 'to_organization_id', 'to_organization_unit_id', 'service_id', 'reason', 'status', 'referred_by', 'received_by', 'referred_at', 'acknowledged_at', 'outcome'];

    protected function casts(): array
    {
        return ['referred_at' => 'datetime', 'acknowledged_at' => 'datetime'];
    }

    public function visit(): BelongsTo
    {
        return $this->belongsTo(ReceptionVisit::class, 'visit_id');
    }
}
