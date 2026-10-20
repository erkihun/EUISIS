<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\HasUuidPrimaryKey;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** One assistant thread of one user (docs/assistant/architecture.md). */
class AssistantConversation extends Model
{
    use HasUuidPrimaryKey;

    public const STATUSES = ['active', 'archived'];

    protected $fillable = ['user_id', 'title', 'module', 'route_name', 'status', 'message_count', 'last_activity_at'];

    protected function casts(): array
    {
        return ['message_count' => 'integer', 'last_activity_at' => 'datetime'];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function messages(): HasMany
    {
        return $this->hasMany(AssistantMessage::class, 'conversation_id')->orderBy('created_at');
    }
}
