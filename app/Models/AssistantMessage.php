<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\HasUuidPrimaryKey;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** One turn: the text shown and the allow-listed blocks; nothing hidden (docs/assistant/privacy.md). */
class AssistantMessage extends Model
{
    use HasUuidPrimaryKey;

    public const UPDATED_AT = null;

    public const ROLES = ['user', 'assistant', 'system'];

    public const FEEDBACK = ['helpful', 'not_helpful'];

    public const STATUSES = ['ok', 'fallback', 'denied', 'error'];

    protected $fillable = ['conversation_id', 'role', 'text', 'blocks', 'mode', 'tools', 'status', 'ai_used', 'ai_run_id', 'duration_ms', 'feedback', 'created_at'];

    protected function casts(): array
    {
        return ['blocks' => 'array', 'tools' => 'array', 'ai_used' => 'boolean', 'duration_ms' => 'integer', 'created_at' => 'datetime'];
    }

    public function conversation(): BelongsTo
    {
        return $this->belongsTo(AssistantConversation::class, 'conversation_id');
    }

    /** @return array<string, mixed> */
    public function toClientArray(): array
    {
        return [
            'id' => $this->id,
            'role' => $this->role,
            'text' => $this->text,
            'blocks' => $this->blocks ?? [],
            'created_at' => $this->created_at->toIso8601String(),
            'feedback' => $this->feedback,
            'meta' => ['mode' => $this->mode, 'ai' => $this->ai_used, 'tools' => $this->tools ?? [], 'status' => $this->status],
        ];
    }
}
