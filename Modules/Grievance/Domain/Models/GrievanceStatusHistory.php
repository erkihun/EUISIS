<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Append-only grievance status transition (spec §66). Written once at
 * each transition; never updated. No updated_at column exists.
 */
class GrievanceStatusHistory extends Model
{
    use HasUuids;

    public const UPDATED_AT = null;

    public const CREATED_AT = null;

    protected $guarded = [];

    protected $casts = [
        'transitioned_at' => 'datetime',
    ];

    public function grievance(): BelongsTo
    {
        return $this->belongsTo(Grievance::class);
    }
}
