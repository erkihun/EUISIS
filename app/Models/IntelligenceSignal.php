<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\HasUuidPrimaryKey;
use Illuminate\Database\Eloquent\Model;

/**
 * One time-bucketed telemetry sample: a metric of a component (optionally
 * one dimension such as a route group or a queue name) for a bucket start.
 * Written only by the collector; never a log line, never a person.
 */
class IntelligenceSignal extends Model
{
    use HasUuidPrimaryKey;

    public $timestamps = false;

    protected $fillable = ['metric', 'component', 'dimension', 'value', 'unit', 'sample_count', 'bucket_start', 'created_at'];

    protected function casts(): array
    {
        return [
            'value' => 'decimal:4',
            'sample_count' => 'integer',
            'bucket_start' => 'datetime',
            'created_at' => 'datetime',
        ];
    }
}
