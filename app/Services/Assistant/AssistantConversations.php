<?php

declare(strict_types=1);

namespace App\Services\Assistant;

use App\Models\AssistantConversation;
use App\Models\AssistantMessage;
use App\Models\User;
use App\Services\Intelligence\AI\Redactor;
use Carbon\CarbonImmutable;
use Illuminate\Support\Str;

/**
 * Own conversations only (docs/assistant/privacy.md): the active thread of
 * a user, the window the AI narrative may see, safe titles, archiving and
 * retention pruning. Every query is keyed by the user; there is no path to
 * another person's thread.
 */
final class AssistantConversations
{
    private const TITLE_CHARS = 60;

    public function __construct(private readonly AssistantSettings $settings) {}

    public function active(User $user): ?AssistantConversation
    {
        return AssistantConversation::query()->where('user_id', $user->id)->where('status', 'active')->orderByDesc('last_activity_at')->first();
    }

    public function activeOrStart(User $user, string $module, ?string $routeName): AssistantConversation
    {
        return $this->active($user) ?? $this->start($user, $module, $routeName);
    }

    public function start(User $user, string $module, ?string $routeName): AssistantConversation
    {
        AssistantConversation::query()->where('user_id', $user->id)->where('status', 'active')->update(['status' => 'archived']);

        return AssistantConversation::query()->create([
            'user_id' => $user->id, 'module' => $module, 'route_name' => $routeName !== null ? mb_substr($routeName, 0, 190) : null,
            'status' => 'active', 'message_count' => 0, 'last_activity_at' => now(),
        ]);
    }

    /** Only the owner finds it. */
    public function find(User $user, string $id): ?AssistantConversation
    {
        return AssistantConversation::query()->where('user_id', $user->id)->whereKey($id)->first();
    }

    /** @param  list<array<string, mixed>>  $blocks */
    public function append(AssistantConversation $conversation, string $role, string $text, array $blocks = [], array $meta = []): AssistantMessage
    {
        $text = Redactor::text(mb_substr($text, 0, 8000));
        $message = AssistantMessage::query()->create([
            'conversation_id' => $conversation->id, 'role' => $role, 'text' => $text, 'blocks' => $blocks,
            'mode' => $meta['mode'] ?? null, 'tools' => $meta['tools'] ?? [], 'status' => $meta['status'] ?? null,
            'ai_used' => (bool) ($meta['ai'] ?? false), 'ai_run_id' => $meta['run_id'] ?? null, 'duration_ms' => $meta['duration_ms'] ?? null,
            'created_at' => now(),
        ]);
        $conversation->forceFill([
            'message_count' => (int) $conversation->message_count + 1,
            'last_activity_at' => now(),
            'title' => $conversation->title ?? ($role === 'user' ? self::title($text) : null),
        ])->save();

        return $message;
    }

    /**
     * The turns the AI narrative may see: the last N, text only, redacted and
     * short. Never blocks, never other conversations.
     *
     * @return list<array{role: string, text: string}>
     */
    public function window(AssistantConversation $conversation): array
    {
        $limit = $this->settings->historyWindow();
        if ($limit === 0) {
            return [];
        }
        $turns = AssistantMessage::query()->where('conversation_id', $conversation->id)->whereIn('role', ['user', 'assistant'])
            ->orderByDesc('created_at')->limit($limit)->get(['role', 'text'])->reverse()->values();

        return $turns->map(fn (AssistantMessage $m): array => ['role' => $m->role, 'text' => Str::limit(Redactor::text($m->text), 400, '…')])->all();
    }

    /** @return array{deleted_conversations: int, deleted_messages: int} */
    public function prune(?CarbonImmutable $now = null): array
    {
        $before = ($now ?? CarbonImmutable::now())->subDays($this->settings->retentionDays());
        $ids = AssistantConversation::query()->where('last_activity_at', '<', $before->toDateTimeString())->pluck('id');
        if ($ids->isEmpty()) {
            return ['deleted_conversations' => 0, 'deleted_messages' => 0];
        }
        $messages = AssistantMessage::query()->whereIn('conversation_id', $ids)->delete();
        $conversations = AssistantConversation::query()->whereKey($ids)->delete();

        return ['deleted_conversations' => $conversations, 'deleted_messages' => $messages];
    }

    /** A short title from the first question, after redaction, with no digits runs that could be an identifier. */
    public static function title(string $text): string
    {
        $clean = preg_replace('/\d{4,}/u', '…', Redactor::text($text)) ?? '';
        $clean = trim(preg_replace('/\s+/u', ' ', $clean) ?? '');

        return $clean === '' ? '' : Str::limit($clean, self::TITLE_CHARS, '…');
    }
}
