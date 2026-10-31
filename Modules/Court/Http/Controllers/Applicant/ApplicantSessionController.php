<?php

declare(strict_types=1);

namespace Modules\Court\Http\Controllers\Applicant;

use Modules\Court\Http\Controllers\Controller;
use Modules\Court\Application\Support\SessionRows;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

final class ApplicantSessionController extends Controller
{
    public function index(Request $request): View|\Inertia\Response
    {
        if ($request->routeIs('court.respondent.profile.*')) {
            $request->session()->put('acting_as_respondent', true);
        }

        $user = $request->user('applicant');
        abort_if($user === null, 401);

        $currentId = $request->session()->getId();
        $records = DB::table('sessions')
            ->where('user_id', $user->getAuthIdentifier())
            ->orderByDesc('last_activity')
            ->get(['id', 'ip_address', 'user_agent', 'last_activity', 'payload'])
            ->filter(fn (object $session): bool => (string) $session->id === (string) $currentId || $this->belongsToProfileSession($session));

        $sessions = $records->map(fn (object $session): object => $this->present($session, $currentId))->values();

        if (! $sessions->contains(fn (object $session): bool => $session->is_current)) {
            $sessions->prepend($this->presentCurrent($request));
        }

        if ($request->routeIs('court.respondent.profile.*')) {
            // The respondent portal's profile is its own React page (Court/Respondent/Profile), on its devices tab.
            return \Modules\Court\Http\Controllers\Respondent\RespondentProfilePage::render($request, $sessions);
        }

        // The applicant's signed-in sessions (React, Court/Applicant/Sessions), beside the profile and security tabs.
        return \Inertia\Inertia::render('Court/Applicant/Sessions', [
            'sessions' => $sessions->map(fn (object $session): array => [
                'id' => (string) $session->id,
                'ip_address' => $session->ip_address ?: null,
                'device_name' => (string) $session->device_name,
                'browser_name' => (string) $session->browser_name,
                'is_current' => (bool) $session->is_current,
                'last_active_at' => $session->last_active_at->toIso8601String(),
                'last_active_relative' => $session->last_active_at->diffForHumans(),
            ])->values()->all(),
            'supported' => config('session.driver') === 'database',
            // Flashed by destroy/destroyOthers under a key the shared flash does not carry.
            'status' => $request->session()->get('status'),
        ]);
    }

    public function destroy(Request $request, string $session): RedirectResponse
    {
        $user = $request->user('applicant');
        abort_if($user === null, 401);
        // `{session}` is the row's keyed hash (SessionRows::publicKey), as the page names it.
        abort_if(hash_equals(SessionRows::publicKey($request->session()->getId()), $session), 422, __('court::auth.session_current_cannot_revoke'));

        $record = DB::table('sessions')
            ->where('user_id', $user->getAuthIdentifier())
            ->get(['id', 'payload'])
            ->first(fn (object $row): bool => $this->belongsToProfileSession($row) && hash_equals(SessionRows::publicKey((string) $row->id), $session));

        if ($record !== null) {
            DB::table('sessions')->where('id', $record->id)->delete();
        }

        return back()->with('status', __('court::auth.session_revoked'));
    }

    public function destroyOthers(Request $request): RedirectResponse
    {
        $user = $request->user('applicant');
        abort_if($user === null, 401);

        $currentId = $request->session()->getId();
        $sessionIds = DB::table('sessions')
            ->where('user_id', $user->getAuthIdentifier())
            ->get(['id', 'payload'])
            ->filter(fn (object $session): bool => (string) $session->id !== (string) $currentId && $this->belongsToProfileSession($session))
            ->pluck('id');

        if ($sessionIds->isNotEmpty()) {
            DB::table('sessions')->whereIn('id', $sessionIds->all())->delete();
        }

        return back()->with('status', __('court::auth.other_sessions_revoked'));
    }

    private function present(object $session, string $currentId): object
    {
        $hints = $this->payloadHints((string) $session->payload);
        $userAgent = (string) $session->user_agent;

        return (object) [
            // Session ids never reach the browser; a row is addressed by its keyed hash.
            'id' => SessionRows::publicKey((string) $session->id),
            'ip_address' => $session->ip_address,
            'user_agent' => $userAgent,
            'device_name' => ($hints ? $this->deviceFromHints($hints) : null) ?? $this->deviceName($userAgent),
            'browser_name' => ($hints ? $this->browserFromHints($hints) : null) ?? $this->browserName($userAgent),
            'location' => __('court::auth.unknown_location'),
            'is_current' => hash_equals((string) $currentId, (string) $session->id),
            'last_active_at' => now()->setTimestamp((int) $session->last_activity),
        ];
    }

    private function presentCurrent(Request $request): object
    {
        $userAgent = (string) $request->userAgent();
        $hints = $request->session()->get('client_hints');
        $hints = is_array($hints) ? $hints : null;

        return (object) [
            'id' => SessionRows::publicKey($request->session()->getId()),
            'ip_address' => $request->ip(),
            'user_agent' => $userAgent,
            'device_name' => ($hints ? $this->deviceFromHints($hints) : null) ?? $this->deviceName($userAgent),
            'browser_name' => ($hints ? $this->browserFromHints($hints) : null) ?? $this->browserName($userAgent),
            'location' => __('court::auth.unknown_location'),
            'is_current' => true,
            'last_active_at' => now(),
        ];
    }

    private function belongsToProfileSession(object $session): bool
    {
        $payload = base64_decode((string) $session->payload, true);

        if ($payload === false) {
            return false;
        }

        $data = @unserialize($payload, ['allowed_classes' => false]);

        return is_array($data) && in_array($data['_auth_guard'] ?? null, ['applicant', 'respondent'], true);
    }

    /**
     * @return array{platform?: string, platform_version?: string, brands?: string}|null
     */
    private function payloadHints(string $payload): ?array
    {
        $decoded = base64_decode($payload, true);
        $data = $decoded === false ? null : @unserialize($decoded, ['allowed_classes' => false]);

        return is_array($data) && is_array($data['client_hints'] ?? null) ? $data['client_hints'] : null;
    }

    /**
     * @param array{platform?: string, platform_version?: string, brands?: string} $hints
     */
    private function deviceFromHints(array $hints): ?string
    {
        $platform = (string) ($hints['platform'] ?? '');

        if ($platform === '') {
            return null;
        }

        if ($platform === 'Windows') {
            $major = (int) strtok((string) ($hints['platform_version'] ?? ''), '.');

            return match (true) {
                $major >= 13 => 'Windows 11',
                $major >= 1 => 'Windows 10',
                default => 'Windows',
            };
        }

        return $platform;
    }

    /**
     * @param array{platform?: string, platform_version?: string, brands?: string} $hints
     */
    private function browserFromHints(array $hints): ?string
    {
        if (preg_match_all('/"([^"]+)";v="([^"]+)"/', (string) ($hints['brands'] ?? ''), $matches, PREG_SET_ORDER) === false) {
            return null;
        }

        $brands = [];

        foreach ($matches as $match) {
            if (stripos($match[1], 'brand') === false) {
                $brands[$match[1]] = $match[2];
            }
        }

        foreach (['Microsoft Edge', 'Opera', 'Brave', 'Vivaldi', 'Google Chrome', 'Chromium'] as $name) {
            if (isset($brands[$name])) {
                return ($name === 'Google Chrome' ? 'Chrome' : $name).' '.$brands[$name];
            }
        }

        $name = array_key_first($brands);

        return $name === null ? null : $name.' '.$brands[$name];
    }

    private function deviceName(string $userAgent): string
    {
        return match (true) {
            str_contains($userAgent, 'Windows') => 'Windows',
            str_contains($userAgent, 'iPhone') => 'iPhone',
            str_contains($userAgent, 'iPad') => 'iPad',
            str_contains($userAgent, 'Android') => 'Android',
            str_contains($userAgent, 'Mac') => 'macOS',
            str_contains($userAgent, 'CrOS') => 'ChromeOS',
            str_contains($userAgent, 'Linux') => 'Linux',
            default => 'Unknown device',
        };
    }

    private function browserName(string $userAgent): string
    {
        $patterns = [
            '/Edg(?:A|iOS)?\/([0-9]+)/i' => 'Microsoft Edge',
            '/Firefox\/([0-9]+)/i' => 'Firefox',
            '/OPR\/([0-9]+)/i' => 'Opera',
            '/Chrome\/([0-9]+)/i' => 'Chrome',
            '/Version\/([0-9]+).*Safari/i' => 'Safari',
        ];

        foreach ($patterns as $pattern => $name) {
            if (preg_match($pattern, $userAgent, $match) === 1) {
                return $name.' '.($match[1] ?? '');
            }
        }

        return 'Browser';
    }
}
