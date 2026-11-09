<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\HasUuidPrimaryKey;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

/** Append-only history of a Situation Room action: written once, never changed. */
class SituationRoomActionEvent extends Model
{
    use HasUuidPrimaryKey;

    public const CREATED_AT = null;

    public const UPDATED_AT = null;

    protected $fillable = [
        'action_id',
        'event',
        'from_value',
        'to_value',
        'note',
        'actor_id',
        'occurred_at',
    ];

    protected function casts(): array
    {
        return [
            'occurred_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::updating(function (): never {
            throw new LogicException('Situation Room action history is immutable.');
        });
        static::deleting(function (): never {
            throw new LogicException('Situation Room action history is immutable.');
        });
    }

    public function action(): BelongsTo
    {
        return $this->belongsTo(SituationRoomAction::class, 'action_id');
    }

    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_id');
    }
}
