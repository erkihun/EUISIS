<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\FieldWorkHistoryAction;
use App\Models\Concerns\HasUuidPrimaryKey;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Append-only workflow history. Never updated after insert. */
class FieldWorkHistory extends Model
{
    use HasUuidPrimaryKey;

    public const UPDATED_AT = null;

    protected $fillable = [
        'action',
        'from_status',
        'to_status',
        'actor_user_id',
        'comment',
    ];

    protected function casts(): array
    {
        return [
            'action' => FieldWorkHistoryAction::class,
            'created_at' => 'datetime',
        ];
    }

    public function request(): BelongsTo
    {
        return $this->belongsTo(FieldWorkRequest::class, 'field_work_request_id');
    }

    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_user_id');
    }
}
