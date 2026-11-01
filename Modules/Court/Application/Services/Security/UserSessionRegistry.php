<?php

declare(strict_types=1);

namespace Modules\Court\Application\Services\Security;

use Modules\Court\Domain\Models\CourtStaffMember as User;
use Modules\Court\Application\Support\ClientHints;
use Modules\Court\Application\Support\SessionRows;
use Modules\Court\Application\Support\UserAgent;
use Carbon\CarbonImmutable;
use Illuminate\Database\Query\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * An admin's live sessions for the profile's Devices tab, in the EUISIS
 * shape (EUISIS Modules\Court\Application\Services\Security\UserSessionRegistry) with court-ms's
 * detail: the exact OS and browser version from the client hints each
 * session stored, the User-Agent string as the fallback. Only the database
 * session driver keeps a per-user index; with any other the tab says so.
 */
final class UserSessionRegistry
{
    public function supported(): bool
    {
        return config('session.driver') === 'database';
    }

    /** @return list<array{id: string, ip_address: ?string, browser: ?string, os: ?string, device: string, last_active_at: string, current: bool}> */
    public function forUser(User $user, Request $request): array
    {
        if (! $this->supported()) {
            return [];
        }

        $currentId = $request->session()->getId();
        $sessions = $this->staffRows($this->liveRows($user)->orderByDesc('last_activity'))
            ->map(fn (object $row): array => [
                // Session ids never reach the browser; a row is addressed by its keyed hash.
                'id' => SessionRows::publicKey((string) $row->id),
                'ip_address' => $row->ip_address !== null ? (string) $row->ip_address : null,
                ...$this->describe((string) $row->user_agent, ClientHints::fromSessionPayload((string) $row->payload)),
                'last_active_at' => CarbonImmutable::createFromTimestamp((int) $row->last_activity)->toIso8601String(),
                'current' => hash_equals($currentId, (string) $row->id),
            ]);

        // The current session is described by this very request, whose hints are the freshest;
        // on its first request the row may not be written yet, so it is listed from the request.
        $liveHints = ClientHints::fromRequest($request) ?? $request->session()->get('client_hints');
        $live = $this->describe((string) $request->userAgent(), is_array($liveHints) ? $liveHints : null);
        $sessions = $sessions->map(fn (array $session): array => $session['current'] ? [...$session, ...$live] : $session);
        if (! $sessions->contains(fn (array $session): bool => $session['current'])) {
            $sessions->prepend([
                'id' => SessionRows::publicKey($currentId),
                'ip_address' => $request->ip(),
                ...$live,
                'last_active_at' => now()->toIso8601String(),
                'current' => true,
            ]);
        }

        return $sessions->values()->all();
    }

    /** Ends one of the account's other staff sessions, named by its keyed hash. */
    public function revoke(User $user, string $key, string $currentSessionId): bool
    {
        if (hash_equals(SessionRows::publicKey($currentSessionId), $key)) {
            return false;
        }

        $row = $this->staffRows($this->table()->where('user_id', (string) $user->getKey()))
            ->first(fn (object $row): bool => hash_equals(SessionRows::publicKey((string) $row->id), $key));

        return $row !== null && $this->table()->where('id', $row->id)->delete() > 0;
    }

    public function revokeOthers(User $user, string $currentSessionId): int
    {
        $ids = $this->staffRows($this->table()->where('user_id', (string) $user->getKey()))
            ->reject(fn (object $row): bool => hash_equals($currentSessionId, (string) $row->id))
            ->pluck('id');

        return $ids->isEmpty() ? 0 : $this->table()->whereIn('id', $ids->all())->delete();
    }

    /**
     * The staff sessions among the rows: the table is shared with the applicant portal and
     * user_id is only unique per guard, so rows of the applicant and respondent guards are left out.
     *
     * @return \Illuminate\Support\Collection<int, object>
     */
    private function staffRows(Builder $query): \Illuminate\Support\Collection
    {
        return $query->get(['id', 'ip_address', 'user_agent', 'last_activity', 'payload'])
            ->reject(fn (object $row): bool => SessionRows::isPortal((string) $row->payload))
            ->values();
    }

    /**
     * @param  array{platform?: string, platform_version?: string, brands?: string}|null  $hints
     * @return array{browser: ?string, os: ?string, device: string}
     */
    private function describe(string $userAgent, ?array $hints): array
    {
        return [
            'browser' => ($hints ? ClientHints::browser($hints) : null) ?? ClientHints::browserFromUserAgent($userAgent),
            'os' => ($hints ? ClientHints::os($hints) : null) ?? ClientHints::osFromUserAgent($userAgent),
            'device' => UserAgent::summarize($userAgent)['device'],
        ];
    }

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
}
