<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\HasUuidPrimaryKey;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** A queued export of an organization-design report, stored on the private disk when ready. */
class OrganizationDesignReportExport extends Model
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
