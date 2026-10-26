<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** An uploaded business import (ADR 0009) and where it is in its life. */
class ImportBatch extends Model
{
    use HasUuids;

    public const TYPES = ['organizations', 'positions', 'employees', 'assignments'];

    public const UPLOADED = 'uploaded';

    public const VALIDATING = 'validating';

    public const READY = 'ready';

    public const REQUIRES_ATTENTION = 'requires_attention';

    public const APPROVED = 'approved';

    public const PROCESSING = 'processing';

    public const COMPLETED = 'completed';

    public const FAILED = 'failed';

    public const CANCELLED = 'cancelled';

    /** Statuses a batch may be approved from. */
    public const APPROVABLE = [self::READY, self::REQUIRES_ATTENTION];

    protected $guarded = ['id'];

    protected $hidden = ['storage_disk', 'storage_path'];

    protected function casts(): array
    {
        return [
            'error_summary' => 'array', 'approved_at' => 'datetime', 'cancelled_at' => 'datetime', 'validated_at' => 'datetime',
            'started_at' => 'datetime', 'completed_at' => 'datetime',
        ];
    }

    public function rows(): HasMany
    {
        return $this->hasMany(ImportRow::class);
    }
}
