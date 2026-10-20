<?php

declare(strict_types=1);

namespace App\Services\Security;

use App\Actions\Audit\WriteAuditLogAction;
use App\Enums\AuditEventType;
use App\Models\AuditLog;
use App\Models\User;
use App\Support\UserAgent;
use Carbon\CarbonImmutable;
use Illuminate\Database\Query\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * The devices signed in to one account, read from the `sessions` table.
 *
 * Laravel's database session handler stores the signed-in user's id, the
 * client address and User-Agent beside every session, so with
 * `SESSION_DRIVER=database` (what production runs — docs/session-management.md)
 * the account's live sessions can be listed and any one of them ended by
 * deleting its row: that browser is signed out on its next request.
 *
 * Other drivers (file, redis, array) keep no per-user index, so the list is
 * reported as unsupported rather than guessed at. Session ids never leave the
 * server except as the opaque handle the owner uses to end a session, and
 * never reach the audit log.
 */
class UserSessionRegistry
{
    public const REASON_REVOKED_BY_USER = 'revoked_by_user';

    public const REASON_REVOKED_OTHER_DEVICES = 'revoked_other_devices';

    public function __construct(private readonly WriteAuditLogAction $writeAuditLog) {}

    public function supported(): bool
    {
        return config('session.driver') === 'database';
    }

    /**
     * Live sessions of the user, most recently active first.
     *
     * @return list<array{id: string, ip_address: ?string, browser: ?string, os: ?string, device: string, last_active_at: string, current: bool}>
     */
    public function forUser(User $user, string $currentSessionId): array
    {
        if (! $this->supported()) {
            return [];
        }

        return $this->liveRows($user)
            ->orderByDesc('last_activity')
            ->get(['id', 'ip_address', 'user_agent', 'last_activity'])
            ->map(fn (object $row): array => [
                'id' => (string) $row->id,
                'ip_address' => $row->ip_address !== null ? (string) $row->ip_address : null,
                ...UserAgent::summarize($row->user_agent !== null ? (string) $row->user_agent : null),
                'last_active_at' => CarbonImmutable::createFromTimestamp((int) $row->last_activity)->toIso8601String(),
                'current' => hash_equals($currentSessionId, (string) $row->id),
            ])
            ->values()
            ->all();
    }

    /** True when the session belongs to the user and was deleted. The current session is never ended here. */
    public function revoke(User $user, string $sessionId, string $currentSessionId, Request $request): bool
    {
        if (! $this->supported() || hash_equals($currentSessionId, $sessionId)) {
            return false;
        }

        $row = $this->liveRows($user)->where('id', $sessionId)->first(['id', 'ip_address', 'user_agent']);

        if ($row === null) {
            return false;
        }

        $this->table()->where('id', $sessionId)->delete();

        $this->audit($user, $request, self::REASON_REVOKED_BY_USER, [
            'ip_address' => $row->ip_address,
            'device' => UserAgent::label($row->user_agent !== null ? (string) $row->user_agent : null),
        ]);

        return true;
    }

    /** Ends every live session of the user except the current one; returns how many. */
    public function revokeOthers(User $user, string $currentSessionId, Request $request): int
    {
        if (! $this->supported()) {
            return 0;
        }

        $count = $this->liveRows($user)->where('id', '!=', $currentSessionId)->delete();

        if ($count > 0) {
            $this->audit($user, $request, self::REASON_REVOKED_OTHER_DEVICES, ['sessions' => $count]);
        }

        return $count;
    }

    /**
     * The user's most recent sign-ins, from the audit log.
     *
     * @return list<array{at: string, ip_address: ?string, browser: ?string, os: ?string, device: string}>
     */
    public function recentSignIns(User $user, int $limit = 8): array
    {
        return AuditLog::query()
            ->where('actor_user_id', $user->getKey())
            ->where('event_type', AuditEventType::UserLoggedIn->value)
            ->orderByDesc('created_at')
            ->limit($limit)
            ->get(['created_at', 'request_ip', 'user_agent'])
            ->map(fn (AuditLog $log): array => [
                'at' => $log->created_at?->toIso8601String() ?? '',
                'ip_address' => $log->request_ip,
                ...UserAgent::summarize($log->user_agent),
            ])
            ->values()
            ->all();
    }

    /** Rows of this user that the storage lifetime has not yet expired (garbage collection runs lazily). */
    private function liveRows(User $user): Builder
    {
        $lifetimeMinutes = max(1, (int) config('session.lifetime', 120));

        return $this->table()
            ->where('user_id', (string) $user->getKey())
            ->where('last_activity', '>=', now()->subMinutes($lifetimeMinutes)->getTimestamp());
    }

    private function table(): Builder
    {
        return DB::connection(config('session.connection'))->table((string) config('session.table', 'sessions'));
    }

    /** @param  array<string, mixed>  $details */
    private function audit(User $user, Request $request, string $reason, array $details): void
    {
        $this->writeAuditLog->execute(
            AuditEventType::SessionRevoked,
            $user,
            $user,
            newValues: $details,
            reason: $reason,
            request: $request,
        );
    }
}
