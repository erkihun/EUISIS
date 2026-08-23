<?php

declare(strict_types=1);

namespace CafeteriaSystem\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class CafeteriaApiLog extends Model
{
    use HasUuids;

    protected $table = 'cafeteria_api_logs';

    protected $fillable = [
        'endpoint',
        'method',
        'status_code',
        'success',
        'error_code',
        'duration_ms',
        'requested_at',
    ];

    protected function casts(): array
    {
        return [
            'success' => 'boolean',
            'status_code' => 'integer',
            'duration_ms' => 'integer',
            'requested_at' => 'datetime',
        ];
    }
}
