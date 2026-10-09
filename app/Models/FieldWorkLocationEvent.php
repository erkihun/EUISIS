<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\FieldWorkLocationEventType;
use App\Enums\FieldWorkLocationValidationStatus;
use App\Models\Concerns\HasUuidPrimaryKey;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Immutable, user-initiated GPS observation; never a continuous tracker. */
class FieldWorkLocationEvent extends Model
{
    use HasUuidPrimaryKey;

    public const UPDATED_AT = null;

    protected $guarded = ['id', 'field_work_request_id', 'field_work_participant_id', 'employee_id', 'employee_assignment_id', 'event_type', 'latitude', 'longitude', 'accuracy_meters', 'altitude_meters', 'heading_degrees', 'speed_mps', 'captured_at', 'received_at', 'location_source', 'permission_state', 'is_within_expected_area', 'distance_from_destination_meters', 'validation_status', 'validation_reason', 'policy_snapshot', 'validation_flags', 'review_state', 'idempotency_key', 'created_at'];

    protected function casts(): array
    {
        return ['event_type' => FieldWorkLocationEventType::class, 'validation_status' => FieldWorkLocationValidationStatus::class, 'latitude' => 'decimal:8', 'longitude' => 'decimal:8', 'accuracy_meters' => 'decimal:2', 'altitude_meters' => 'decimal:2', 'heading_degrees' => 'decimal:2', 'speed_mps' => 'decimal:2', 'is_within_expected_area' => 'bool', 'distance_from_destination_meters' => 'decimal:2', 'policy_snapshot' => 'array', 'validation_flags' => 'array', 'captured_at' => 'datetime', 'received_at' => 'datetime'];
    }

    protected static function booted(): void
    {
        static::updating(static function (): never {
            throw new \LogicException('Field Work location events are immutable.');
        });
        static::deleting(static function (): never {
            throw new \LogicException('Field Work location events cannot be deleted through the application.');
        });
    }

    public function request(): BelongsTo
    {
        return $this->belongsTo(FieldWorkRequest::class, 'field_work_request_id');
    }

    public function participant(): BelongsTo
    {
        return $this->belongsTo(FieldWorkParticipant::class, 'field_work_participant_id');
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }
}
