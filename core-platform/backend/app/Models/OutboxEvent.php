<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** A published domain change (written with the change; see App\Services\DomainEvents). */
class OutboxEvent extends Model
{
    public $timestamps = false;

    public $incrementing = false;

    protected $keyType = 'string';

    protected $guarded = [];

    protected function casts(): array
    {
        return ['data' => 'array', 'occurred_at' => 'datetime', 'payload_version' => 'integer'];
    }

    /** The body every receiver gets (payload version 1). @return array<string, mixed> */
    public function payload(): array
    {
        return [
            'event_id' => $this->id,
            'event_type' => $this->event_type,
            'occurred_at' => $this->occurred_at?->toIso8601String(),
            'entity_type' => $this->entity_type,
            'entity_uuid' => $this->entity_id,
            'payload_version' => $this->payload_version,
            'data' => $this->data,
        ];
    }
}
