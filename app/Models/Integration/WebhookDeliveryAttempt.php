<?php

declare(strict_types=1);

namespace App\Models\Integration;

use Illuminate\Database\Eloquent\Model;

/** One HTTP attempt: outcome, status, safe error category, latency. Never a body. */
class WebhookDeliveryAttempt extends Model
{
    public $timestamps = false;

    protected $fillable = ['webhook_delivery_id', 'attempt', 'outcome', 'http_status', 'error_category', 'latency_ms', 'started_at', 'completed_at'];

    protected function casts(): array
    {
        return ['started_at' => 'datetime', 'completed_at' => 'datetime', 'http_status' => 'integer', 'latency_ms' => 'integer', 'attempt' => 'integer'];
    }
}
