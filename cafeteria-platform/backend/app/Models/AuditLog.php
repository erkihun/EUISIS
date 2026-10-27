<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\AuditEventType;
use App\Models\Concerns\HasUuidPrimaryKey;
use Illuminate\Database\Eloquent\Model;

class AuditLog extends Model
{
    use HasUuidPrimaryKey;

    public $timestamps = false;

    protected $fillable = [
        'actor_user_id',
        'actor_type',
        'event_type',
        'auditable_type',
        'auditable_id',
        'organization_id',
        'reason',
        'old_values',
        'new_values',
        'request_id',
        'request_ip',
        'user_agent',
        'created_at',
    ];

    protected function casts(): array
    {
        return [
            'event_type' => AuditEventType::class,
            'old_values' => 'array',
            'new_values' => 'array',
            'created_at' => 'datetime',
        ];
    }
}
