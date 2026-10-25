<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** One row of an import (ADR 0009): its data (encrypted), status, errors and the record it produced. */
class ImportRow extends Model
{
    public const VALID = 'valid';

    public const INVALID = 'invalid';

    public const PROCESSED = 'processed';

    public const FAILED = 'failed';

    protected $guarded = ['id'];

    protected $hidden = ['payload'];

    protected function casts(): array
    {
        return ['payload' => 'encrypted:array', 'errors' => 'array', 'processed_at' => 'datetime'];
    }

    public function batch(): BelongsTo
    {
        return $this->belongsTo(ImportBatch::class, 'import_batch_id');
    }
}
