<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\HasUuidPrimaryKey;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One row per integration-API request.
 *
 * Stores routing and outcome only. Request/response bodies are never written
 * here, so the log cannot become a secondary source of employee data.
 */
class ApiRequestLog extends Model
{
    use HasUuidPrimaryKey;

    protected $fillable = [
        'external_application_id',
        'endpoint',
        'method',
        'ip_address',
        'status_code',
        'success',
        'failure_reason',
        'requested_at',
        'metadata',
    ];

    protected function casts(): array
    {
        return [
            'success' => 'boolean',
            'status_code' => 'integer',
            'requested_at' => 'datetime',
            'metadata' => 'array',
        ];
    }

    /** @return BelongsTo<ExternalApplication, $this> */
    public function externalApplication(): BelongsTo
    {
        return $this->belongsTo(ExternalApplication::class);
    }
}
