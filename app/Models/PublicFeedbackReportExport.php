<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\HasUuidPrimaryKey;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One requested export of the public submissions report (brief §87): built
 * on the queue as the requester, stored on the private disk, downloadable
 * only through the policy. The row records where the file landed or why
 * the build failed.
 */
class PublicFeedbackReportExport extends Model
{
    use HasUuidPrimaryKey;

    public const STATUSES = ['queued', 'ready', 'failed'];

    protected $fillable = ['organization_id', 'report', 'format', 'filters', 'status', 'disk', 'path', 'filename', 'row_count', 'error', 'requested_by', 'completed_at'];

    protected function casts(): array
    {
        return ['filters' => 'array', 'row_count' => 'integer', 'completed_at' => 'datetime'];
    }

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    public function requester(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by');
    }

    public function isReady(): bool
    {
        return $this->status === 'ready' && $this->path !== null;
    }
}
