<?php

declare(strict_types=1);

namespace Modules\Letters\Domain\Models;

use App\Models\Concerns\HasUuidPrimaryKey;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Modules\Letters\Domain\Enums\LetterEventType;

/** One entry of a letter's timeline. Append-only. */
class LetterEvent extends Model
{
    use HasUuidPrimaryKey;

    public const UPDATED_AT = null;

    protected $table = 'letter_events';

    protected $fillable = ['letter_id', 'registration_id', 'type', 'actor_id', 'payload', 'created_at'];

    protected function casts(): array
    {
        return ['type' => LetterEventType::class, 'payload' => 'array', 'created_at' => 'datetime'];
    }

    public function letter(): BelongsTo
    {
        return $this->belongsTo(Letter::class);
    }

    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_id');
    }
}
