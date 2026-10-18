<?php

declare(strict_types=1);

namespace App\Models\Communication;

use App\Models\Concerns\HasUuidPrimaryKey;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** A pinned message in a conversation. */
class ConversationPin extends Model
{
    use HasUuidPrimaryKey;

    public $timestamps = false;

    protected $guarded = ['*'];

    protected function casts(): array
    {
        return ['pinned_at' => 'datetime'];
    }

    public function conversation(): BelongsTo
    {
        return $this->belongsTo(Conversation::class);
    }

    public function message(): BelongsTo
    {
        return $this->belongsTo(Message::class)->withTrashed();
    }

    public function pinner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'pinned_by');
    }
}
