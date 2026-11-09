<?php

declare(strict_types=1);

namespace Modules\Reception\Domain\Models;

use App\Models\Concerns\HasUuidPrimaryKey;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

final class ReceptionQueueTicket extends Model
{
    use HasUuidPrimaryKey;

    protected $fillable = ['visit_id', 'reception_desk_id', 'reception_counter_id', 'queue_date', 'queue_number', 'display_number', 'priority', 'status', 'issued_at', 'called_at', 'service_started_at', 'completed_at', 'recall_count'];

    protected function casts(): array
    {
        return ['queue_date' => 'date:Y-m-d', 'issued_at' => 'datetime', 'called_at' => 'datetime', 'service_started_at' => 'datetime', 'completed_at' => 'datetime', 'queue_number' => 'integer', 'recall_count' => 'integer'];
    }

    public function visit(): BelongsTo
    {
        return $this->belongsTo(ReceptionVisit::class, 'visit_id');
    }

    public function desk(): BelongsTo
    {
        return $this->belongsTo(ReceptionDesk::class, 'reception_desk_id');
    }

    public function counter(): BelongsTo
    {
        return $this->belongsTo(ReceptionCounter::class, 'reception_counter_id');
    }
}
