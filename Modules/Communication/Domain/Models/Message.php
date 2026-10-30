<?php

declare(strict_types=1);

namespace Modules\Communication\Domain\Models;

use App\Models\Concerns\HasUuidPrimaryKey;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Modules\Communication\Domain\Enums\MessageType;

/**
 * A message. Soft-deleted for retention: a deleted message keeps its row, and
 * MessageResource never returns its body or attachments to members.
 */
class Message extends Model
{
    use HasUuidPrimaryKey;
    use SoftDeletes;

    protected $guarded = ['*'];

    protected function casts(): array
    {
        return [
            'type' => MessageType::class,
            'metadata' => 'array',
            'edited_at' => 'datetime',
        ];
    }

    public function conversation(): BelongsTo
    {
        return $this->belongsTo(Conversation::class);
    }

    public function sender(): BelongsTo
    {
        return $this->belongsTo(User::class, 'sender_id');
    }

    /** The one referenced message a reply quotes (no nested trees). */
    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_message_id')->withTrashed();
    }

    public function forwardedFrom(): BelongsTo
    {
        return $this->belongsTo(self::class, 'forwarded_from_message_id')->withTrashed();
    }

    public function attachments(): HasMany
    {
        return $this->hasMany(MessageAttachment::class);
    }

    public function reactions(): HasMany
    {
        return $this->hasMany(MessageReaction::class);
    }

    public function mentions(): HasMany
    {
        return $this->hasMany(MessageMention::class);
    }
}
