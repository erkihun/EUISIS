<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** One report export (ADR 0011): what was asked, by whom, with which filters and columns, its file and its downloads. */
class ExportRun extends Model
{
    use HasUuids;

    public const FORMATS = ['csv', 'xlsx', 'html'];

    protected $guarded = ['id'];

    protected $hidden = ['file_path'];

    protected function casts(): array
    {
        return [
            'filters' => 'array', 'columns' => 'array', 'include_sensitive' => 'boolean', 'started_at' => 'datetime', 'completed_at' => 'datetime',
            'expires_at' => 'datetime', 'last_downloaded_at' => 'datetime', 'row_count' => 'integer', 'download_count' => 'integer',
        ];
    }

    public function requester(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by');
    }
}
