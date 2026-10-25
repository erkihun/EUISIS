<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/** A receiver of Core events; its signing secret is encrypted at rest and never returned after creation or rotation. */
class WebhookEndpoint extends Model
{
    use HasUuids;

    protected $fillable = ['name', 'url', 'event_types', 'scope_organization_id', 'is_active'];

    protected $hidden = ['secret', 'previous_secret'];

    protected function casts(): array
    {
        return [
            'secret' => 'encrypted', 'previous_secret' => 'encrypted', 'event_types' => 'array', 'is_active' => 'boolean',
            'consecutive_failures' => 'integer', 'suspended_at' => 'datetime', 'previous_secret_expires_at' => 'datetime',
            'secret_rotated_at' => 'datetime', 'last_success_at' => 'datetime', 'last_failure_at' => 'datetime',
        ];
    }
}
