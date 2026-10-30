<?php

declare(strict_types=1);

namespace Modules\Communication\Domain\Models;

use App\Models\Concerns\HasUuidPrimaryKey;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Modules\Communication\Domain\Enums\ConversationType;

/**
 * A conversation stream: a direct conversation between two people, a private
 * group, or the stream behind a channel. Access is membership only (an active
 * conversation_members row); no permission reads a conversation by itself.
 */
class Conversation extends Model
{
    use HasUuidPrimaryKey;

    protected $guarded = ['*'];

    protected function casts(): array
    {
        return [
            'type' => ConversationType::class,
            'last_message_at' => 'datetime',
            'archived_at' => 'datetime',
        ];
    }

    /** The pair key that makes a direct conversation unique, whatever the order of the two people. */
    public static function directKey(int $a, int $b): string
    {
        return min($a, $b).':'.max($a, $b);
    }

    public function members(): HasMany
    {
        return $this->hasMany(ConversationMember::class);
    }

    public function activeMembers(): HasMany
    {
        return $this->hasMany(ConversationMember::class)->whereNull('left_at');
    }

    public function messages(): HasMany
    {
        return $this->hasMany(Message::class);
    }

    public function pins(): HasMany
    {
        return $this->hasMany(ConversationPin::class);
    }

    public function channel(): HasOne
    {
        return $this->hasOne(Channel::class);
    }

    public function lastMessage(): BelongsTo
    {
        return $this->belongsTo(Message::class, 'last_message_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    public function membershipOf(User $user): ?ConversationMember
    {
        return $this->members()->where('user_id', $user->getKey())->whereNull('left_at')->first();
    }

    public function isArchived(): bool
    {
        return $this->archived_at !== null;
    }

    /** Conversations the user is an active member of — the only way a list is ever built. */
    public function scopeVisibleTo(Builder $query, User $user): Builder
    {
        return $query->whereIn('conversations.id', ConversationMember::query()
            ->select('conversation_id')->where('user_id', $user->getKey())->whereNull('left_at'));
    }
}
