<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** One integration call: client, route, status and duration; never a payload. */
class ApiRequestLog extends Model
{
    public const UPDATED_AT = null;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['created_at' => 'datetime', 'status' => 'integer', 'duration_ms' => 'integer'];
    }
}
