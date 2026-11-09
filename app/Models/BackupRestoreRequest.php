<?php

namespace App\Models;

use App\Models\Concerns\HasUuidPrimaryKey;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class BackupRestoreRequest extends Model
{
    use HasUuidPrimaryKey;

    public const TYPES = ['POINT_IN_TIME', 'BACKUP_SET', 'LATEST'];

    public const STATUSES = ['REQUESTED', 'UNDER_REVIEW', 'APPROVED', 'REJECTED', 'TEST_RESTORE_RUNNING', 'TEST_RESTORE_VERIFIED',
        'PRODUCTION_RESTORE_AUTHORIZED', 'RESTORING', 'COMPLETED', 'FAILED', 'CANCELLED'];

    protected $guarded = [];

    protected function casts(): array
    {
        return ['target_time' => 'immutable_datetime', 'started_at' => 'immutable_datetime', 'completed_at' => 'immutable_datetime',
            'requested_by' => 'integer', 'reviewed_by' => 'integer', 'approved_by' => 'integer', 'production_authorized_by' => 'integer'];
    }

    public function requester(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by');
    }

    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }

    public function approver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    public function productionAuthorizer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'production_authorized_by');
    }
}
