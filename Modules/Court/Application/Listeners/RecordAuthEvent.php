<?php

declare(strict_types=1);

namespace Modules\Court\Application\Listeners;

use Illuminate\Auth\Events\Failed;
use Illuminate\Auth\Events\Lockout;
use Illuminate\Auth\Events\Login;
use Illuminate\Auth\Events\Logout;
use Illuminate\Auth\Events\PasswordReset;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Writes authentication events (login, logout, failed attempt, lockout,
 * password reset) to the system_audits table. SystemAuditMiddleware only
 * covers authenticated state-changing requests, so auth events need their
 * own trail for security review.
 */
class RecordAuthEvent
{
    public function handleLogin(Login $event): void
    {
        $this->record('auth.login', $event->guard, $event->user->getAuthIdentifier());
    }

    public function handleLogout(Logout $event): void
    {
        $this->record('auth.logout', $event->guard, $event->user?->getAuthIdentifier());
    }

    public function handleFailed(Failed $event): void
    {
        $this->record('auth.failed', $event->guard, $event->user?->getAuthIdentifier(), [
            'email' => (string) ($event->credentials['email'] ?? ''),
        ]);
    }

    public function handleLockout(Lockout $event): void
    {
        $this->record('auth.lockout', null, null, [
            'email' => (string) $event->request->input('email', ''),
        ]);
    }

    public function handlePasswordReset(PasswordReset $event): void
    {
        $this->record('auth.password_reset', null, $event->user->getAuthIdentifier());
    }

    private function record(string $action, ?string $guard, int|string|null $userId, array $context = []): void
    {
        $request = request();

        try {
            DB::table('court_system_audits')->insert([
                'request_id' => $request->attributes->get('request_id'),
                'user_id' => is_numeric($userId) ? (int) $userId : null,
                'actor_type' => $guard ?: 'guest',
                'action' => $action,
                'outcome' => in_array($action, ['auth.failed', 'auth.lockout'], true) ? 'failure' : 'success',
                'module' => 'auth',
                'route' => optional($request->route())->getName(),
                'method' => $request->method(),
                'response_status' => null,
                'ip' => $request->ip(),
                'user_agent' => substr((string) $request->userAgent(), 0, 2000),
                'context' => json_encode(array_merge(['path' => $request->path()], $context)),
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        } catch (\Throwable $auditFailure) {
            Log::channel('court.audit')->critical('authentication_audit_database_write_failed', [
                'action' => $action,
                'guard' => $guard,
                'user_id' => $userId,
                'path' => $request->path(),
                'failure' => $auditFailure::class,
            ]);
        }
    }
}
