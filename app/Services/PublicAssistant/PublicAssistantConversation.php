<?php

declare(strict_types=1);

namespace App\Services\PublicAssistant;

use App\Services\Intelligence\AI\Redactor;
use Illuminate\Contracts\Session\Session;
use Illuminate\Support\Str;

/**
 * Ephemeral public conversation (P-4): the last N turns live in the guest
 * session under one key, redacted, bounded, gone with the session. There is
 * no table, no visitor id and no way to address another session.
 */
final class PublicAssistantConversation
{
    private const KEY = 'public_assistant.turns';

    private const TEXT_MAX = 4000;

    public function __construct(private readonly PublicAssistantSettings $settings) {}

    /** @return list<array<string, mixed>> */
    public function turns(Session $session): array
    {
        $turns = $session->get(self::KEY, []);

        return is_array($turns) ? array_values($turns) : [];
    }

    /** @param  list<array<string, mixed>>  $blocks  @return array<string, mixed> */
    public function append(Session $session, string $role, string $text, array $blocks = [], array $meta = []): array
    {
        $message = [
            'id' => (string) Str::uuid(),
            'role' => $role,
            'text' => Redactor::text(mb_substr($text, 0, self::TEXT_MAX)),
            'blocks' => $blocks,
            'created_at' => now()->toIso8601String(),
            'meta' => ['mode' => $meta['mode'] ?? null, 'ai' => (bool) ($meta['ai'] ?? false)],
        ];
        $turns = $this->turns($session);
        $turns[] = $message;
        $keep = max(2, $this->settings->historyTurns() * 2);
        $session->put(self::KEY, array_slice($turns, -$keep));

        return $message;
    }

    /** The turns the AI narrative may see: text only, short. @return list<array{role: string, text: string}> */
    public function window(Session $session): array
    {
        $limit = $this->settings->historyTurns();
        if ($limit === 0) {
            return [];
        }

        return array_map(fn (array $t): array => ['role' => (string) $t['role'], 'text' => Str::limit((string) $t['text'], 300, '…')], array_slice($this->turns($session), -$limit));
    }

    public function reset(Session $session): void
    {
        $session->forget(self::KEY);
    }
}
