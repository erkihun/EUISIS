<?php

declare(strict_types=1);

namespace Modules\Reception\Domain\Models;

use App\Models\Concerns\HasUuidPrimaryKey;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

final class ReceptionAppointment extends Model
{
    use HasUuidPrimaryKey;

    protected $fillable = ['reference', 'visitor_id', 'organization_id', 'organization_unit_id', 'service_id', 'reception_desk_id', 'scheduled_at', 'status', 'checked_in_at', 'notes', 'created_by'];

    protected function casts(): array
    {
        return ['scheduled_at' => 'datetime', 'checked_in_at' => 'datetime'];
    }

    public function visitor(): BelongsTo
    {
        return $this->belongsTo(ReceptionVisitor::class, 'visitor_id');
    }

    public function desk(): BelongsTo
    {
        return $this->belongsTo(ReceptionDesk::class, 'reception_desk_id');
    }
}
