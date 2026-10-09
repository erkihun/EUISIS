<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\FieldWorkLocationEventType;
use App\Enums\FieldWorkLocationValidation;
use App\Models\Concerns\HasUuidPrimaryKey;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

/**
 * One immutable GPS capture (check-in or check-out).
 *
 * Inserted once by FieldWorkService and never updated or deleted through the
 * model: a check-out is a new row, so it can never overwrite the check-in
 * position. Exact coordinates are private (field_work.location.view_precise);
 * ordinary viewers get validation_status only.
 */
class FieldWorkLocationEvent extends Model
{
    use HasUuidPrimaryKey;

    public const UPDATED_AT = null;

    protected $fillable = [];

    protected $hidden = ['latitude', 'longitude', 'accuracy_m', 'distance_m'];

    protected static function booted(): void
    {
        static::updating(static function (): never {
            throw new LogicException('Field work location events are immutable.');
        });
        static::deleting(static function (): never {
            throw new LogicException('Field work location events are immutable.');
        });
    }

    protected function casts(): array
    {
        return [
            'event_type' => FieldWorkLocationEventType::class,
            'validation_status' => FieldWorkLocationValidation::class,
            'latitude' => 'float',
            'longitude' => 'float',
            'accuracy_m' => 'float',
            'distance_m' => 'float',
            'captured_at' => 'datetime',
            'received_at' => 'datetime',
            'created_at' => 'datetime',
        ];
    }

    public function request(): BelongsTo
    {
        return $this->belongsTo(FieldWorkRequest::class, 'field_work_request_id');
    }

    public function participant(): BelongsTo
    {
        return $this->belongsTo(FieldWorkParticipant::class, 'field_work_participant_id');
    }
}
