<?php

declare(strict_types=1);

namespace Modules\Communication\Domain\Models;

use App\Models\Concerns\HasUuidPrimaryKey;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Modules\Communication\Domain\Enums\MentionType;

/** A parsed mention, stored so "mentions of me" is one indexed query. */
class MessageMention extends Model
{
    use HasUuidPrimaryKey;

    public const UPDATED_AT = null;

    protected $guarded = ['*'];

    protected function casts(): array
    {
        return ['mention_type' => MentionType::class];
    }

    public function message(): BelongsTo
    {
        return $this->belongsTo(Message::class);
    }

    public function mentionedUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'mentioned_user_id');
    }
}
