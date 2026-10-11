<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\HasUuidPrimaryKey;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** One classification import run, dry or real, with its counts and error report. */
class ClassificationImportBatch extends Model
{
    use HasUuidPrimaryKey;

    protected $fillable = ['dataset', 'revision', 'file_name', 'fingerprint', 'dry_run', 'total_rows', 'created_rows', 'updated_rows', 'unchanged_rows', 'failed_rows', 'status', 'error_report', 'uploaded_by'];

    protected function casts(): array
    {
        return [
            'dry_run' => 'boolean', 'total_rows' => 'integer', 'created_rows' => 'integer', 'updated_rows' => 'integer',
            'unchanged_rows' => 'integer', 'failed_rows' => 'integer', 'error_report' => 'array',
        ];
    }

    public function uploader(): BelongsTo
    {
        return $this->belongsTo(User::class, 'uploaded_by');
    }
}
