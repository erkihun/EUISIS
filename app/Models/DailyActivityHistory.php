<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\DailyActivityHistoryAction;
use App\Models\Concerns\HasUuidPrimaryKey;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Append-only workflow history. Never updated after insert. */
class DailyActivityHistory extends Model
{
    use HasUuidPrimaryKey;

    public const UPDATED_AT = null;

    protected $fillable = [
        'action',
        'from_status',
        'to_status',
        'actor_user_id',
        'comment',
        'snapshot',
    ];

    protected function casts(): array
    {
        return [
            'action' => DailyActivityHistoryAction::class,
            'snapshot' => 'array',
            'created_at' => 'datetime',
        ];
    }

    public function log(): BelongsTo
    {
        return $this->belongsTo(DailyActivityLog::class, 'daily_activity_log_id');
    }

    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_user_id');
    }
}
