<?php

declare(strict_types=1);

namespace Modules\ServiceMonitoring\Domain\Models;

use App\Models\Concerns\HasUuidPrimaryKey;
use Database\Factories\ServiceMonitoring\ReminderLogFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Modules\ServiceMonitoring\Domain\Enums\ReminderType;

/** One reminder sent (D23); the unique key makes the daily job idempotent. */
class ReminderLog extends Model
{
    /** @use HasFactory<ReminderLogFactory> */
    use HasFactory;

    use HasUuidPrimaryKey;

    protected $table = 'service_monitoring_reminder_logs';

    protected $fillable = [
        'participant_id',
        'period_id',
        'type',
        'sent_on',
        'subject_key',
        'recipients',
    ];

    protected function casts(): array
    {
        return [
            'type' => ReminderType::class,
            'sent_on' => 'date:Y-m-d',
            'recipients' => 'integer',
        ];
    }

    protected static function newFactory(): ReminderLogFactory
    {
        return ReminderLogFactory::new();
    }

    public function participant(): BelongsTo
    {
        return $this->belongsTo(Participant::class);
    }
}
