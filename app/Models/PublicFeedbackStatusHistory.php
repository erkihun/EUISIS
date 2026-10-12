<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\HasUuidPrimaryKey;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

/**
 * Append-only status transition of a public submission (brief §52). Written
 * once; a row can be neither changed nor deleted through the model, so the
 * history is the record even when the current status disagrees with memory.
 */
class PublicFeedbackStatusHistory extends Model
{
    use HasUuidPrimaryKey;

    protected $table = 'public_feedback_status_history';

    public const CREATED_AT = null;

    public const UPDATED_AT = null;

    protected $fillable = [
        'submission_id',
        'from_status',
        'to_status',
        'actor_id',
        'reason',
        'transitioned_at',
    ];

    protected function casts(): array
    {
        return [
            'transitioned_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::updating(function (): never {
            throw new LogicException('Public feedback status history is immutable.');
        });
        static::deleting(function (): never {
            throw new LogicException('Public feedback status history is immutable.');
        });
    }

    public function submission(): BelongsTo
    {
        return $this->belongsTo(EmployeeServiceFeedback::class, 'submission_id');
    }

    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_id');
    }
}
