<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\HasUuidPrimaryKey;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Append-only workflow history. Rows are written once and never updated, so
 * the trail of who asked for what, and what the reviewer said, survives every
 * later correction.
 */
class OrganizationalChangeRequestHistory extends Model
{
    use HasUuidPrimaryKey;

    protected $table = 'organizational_change_request_history';

    public const UPDATED_AT = null;

    protected $fillable = [
        'request_id',
        'actor_id',
        'action',
        'from_status',
        'to_status',
        'comment',
        'changed_fields',
        'context',
        'revision',
    ];

    protected function casts(): array
    {
        return [
            'changed_fields' => 'array',
            'context' => 'array',
            'revision' => 'integer',
            'created_at' => 'datetime',
        ];
    }

    public function request(): BelongsTo
    {
        return $this->belongsTo(OrganizationalChangeRequest::class, 'request_id');
    }

    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_id');
    }
}
