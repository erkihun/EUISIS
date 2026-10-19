<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\HasUuidPrimaryKey;
use Illuminate\Database\Eloquent\Model;

/**
 * One idempotency claim: (principal, route, key) is unique in the database,
 * so of two simultaneous requests exactly one inserts the row and executes;
 * the other sees the claim and waits for, or replays, its result (F6).
 */
class ApiIdempotencyKey extends Model
{
    use HasUuidPrimaryKey;

    public $timestamps = false;

    public const STATUS_IN_PROGRESS = 'in_progress';

    public const STATUS_COMPLETED = 'completed';

    protected $fillable = ['principal_type', 'principal_id', 'route', 'idempotency_key', 'request_hash', 'status', 'response_status', 'response_body', 'created_at', 'completed_at', 'expires_at'];

    protected function casts(): array
    {
        return ['response_body' => 'array', 'response_status' => 'integer', 'created_at' => 'datetime', 'completed_at' => 'datetime', 'expires_at' => 'datetime'];
    }
}
