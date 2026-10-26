<?php

declare(strict_types=1);

namespace App\Http\Controllers\Web;

use App\Actions\ManageBreakGlass;
use App\Http\Controllers\Controller;
use App\Jobs\VerifyAuditTrailJob;
use App\Models\ApiClient;
use App\Models\BreakGlassGrant;
use App\Models\CoreAuditLog;
use App\Models\Organization;
use App\Models\User;
use App\Services\AuditChain;
use App\Services\AuditWriter;
use App\Services\CoreSettings;
use App\Services\MfaPolicy;
use App\Support\CorePermissions;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Core/SecurityCenter (ADR 0010): emergency (break-glass) access — the
 * reader's own, requests waiting for a decision, grants in force and recent
 * history — and the audit trail's integrity: how far it is sealed and what
 * the last verification found.
 */
class SecurityCenterController extends Controller
{
    public function show(Request $request, AuditChain $chain, CoreSettings $settings): Response
    {
        /** @var User $user */
        $user = $request->user();
        $approve = $user->can('break_glass.approve');
        $revoke = $user->can('break_glass.revoke');
        $grants = BreakGlassGrant::query()->with(['user:id,name', 'organization:id,name_en,name_am'])
            ->when(! $approve && ! $revoke, fn ($q) => $q->where('user_id', $user->getKey()))
            ->orderByDesc('requested_at')->limit(50)->get();
        $deciders = User::query()->whereIn('id', $grants->flatMap(fn ($g) => [$g->decided_by, $g->ended_by])->filter()->unique())->pluck('name', 'id');
        $status = $user->can('audit.integrity.view') ? $chain->status() : null;

        return Inertia::render('Core/SecurityCenter', [
            'grants' => $grants->map(fn (BreakGlassGrant $grant): array => [
                'id' => $grant->id, 'status' => $this->status($grant), 'mine' => (int) $grant->user_id === (int) $user->getKey(),
                'user' => $grant->user?->name, 'permissions' => $grant->permissions, 'scope_type' => $grant->scope_type,
                'organization' => $grant->organization?->only(['name_en', 'name_am']), 'reason' => $grant->reason, 'duration_minutes' => $grant->duration_minutes,
                'requested_at' => $grant->requested_at?->toIso8601String(), 'decided_by' => $deciders->get($grant->decided_by), 'decision_reason' => $grant->decision_reason,
                'starts_at' => $grant->starts_at?->toIso8601String(), 'expires_at' => $grant->expires_at?->toIso8601String(),
                'ended_by' => $deciders->get($grant->ended_by), 'end_reason' => $grant->end_reason,
                'decide_url' => $approve && $grant->status === BreakGlassGrant::REQUESTED && (int) $grant->user_id !== (int) $user->getKey() ? route('core.security.break-glass.decide', $grant) : null,
                'end_url' => in_array($this->status($grant), [BreakGlassGrant::ACTIVE, BreakGlassGrant::REQUESTED], true) && ($revoke || (int) $grant->user_id === (int) $user->getKey())
                    ? route('core.security.break-glass.end', $grant) : null,
            ])->values(),
            'request' => $user->can('break_glass.request') ? [
                'url' => route('core.security.break-glass.store'),
                'permissions' => ManageBreakGlass::grantable(),
                'max_minutes' => $settings->int('security.break_glass_max_minutes'),
                'organizations' => Organization::query()->orderBy('name_en')->get(['id', 'name_en', 'name_am']),
            ] : null,
            'integrity' => $status === null ? null : [
                'sealed_through' => $status['sealed_through'],
                'last_sealed_at' => $status['last_sealed_at'],
                'unsealed' => $status['unsealed'],
                'last_verification' => $status['last_verification'] === null ? null : [
                    'ok' => (bool) $status['last_verification']->ok, 'finished_at' => $status['last_verification']->finished_at,
                    'checked_rows' => (int) $status['last_verification']->checked_rows, 'message' => $status['last_verification']->message,
                    'first_bad_sequence' => $status['last_verification']->first_bad_sequence,
                ],
                'verify_url' => $user->can('audit.integrity.verify') ? route('core.security.audit.verify') : null,
            ],
            'dashboard' => $user->isCityWide() && $user->can('security_dashboard.view') ? $this->dashboard() : null,
        ]);
    }

    public function store(Request $request, ManageBreakGlass $breakGlass): RedirectResponse
    {
        $breakGlass->request($request->only(['permissions', 'scope_type', 'organization_id', 'duration_minutes', 'reason']), $request->user());

        return back()->with('success', __('security.break_glass_requested'));
    }

    public function decide(Request $request, BreakGlassGrant $grant, ManageBreakGlass $breakGlass): RedirectResponse
    {
        $data = $request->validate(['decision' => ['required', 'in:approve,reject'], 'reason' => ['nullable', 'string', 'max:2000']]);
        $breakGlass->decide($grant, $data['decision'] === 'approve', $data['reason'] ?? null, $request->user());

        return back()->with('success', __($data['decision'] === 'approve' ? 'security.break_glass_approved' : 'security.break_glass_rejected'));
    }

    public function end(Request $request, BreakGlassGrant $grant, ManageBreakGlass $breakGlass): RedirectResponse
    {
        $breakGlass->end($grant, (string) $request->input('reason', ''), $request->user());

        return back()->with('success', __('security.break_glass_ended'));
    }

    /** Runs the full verification in the background; the result appears here when it finishes. */
    public function verifyAudit(Request $request, AuditWriter $audit): RedirectResponse
    {
        abort_unless($request->user()->can('audit.integrity.verify'), 403);
        VerifyAuditTrailJob::dispatch((int) $request->user()->getKey());
        $audit->writeFor('audit.verification_requested', $request->user(), 'AuditTrail', 'core_audit_logs', null);

        return back()->with('success', __('security.audit_verification_started'));
    }

    /**
     * The security dashboard (ADR 0011), from the system's own records only.
     *
     * @return array<string, mixed>
     */
    private function dashboard(): array
    {
        $privileged = User::query()->where('is_active', true)->whereIn('role', CorePermissions::rolesWith('core.write'));
        $without = (clone $privileged)->where(fn ($q) => $q->where('two_factor_enabled', false)->orWhereNull('two_factor_confirmed_at'));
        $since = now()->subDay();
        $recent = fn (string $pattern) => CoreAuditLog::query()->where('action', 'like', $pattern)->orderByDesc('created_at')->limit(10)->get(['action', 'actor_user_id', 'subject_id', 'created_at']);
        $changes = $recent('role.%')->concat($recent('user.%'))->sortByDesc('created_at')->take(15)->values();
        $actors = User::query()->whereIn('id', $changes->pluck('actor_user_id')->filter()->unique())->pluck('name', 'id');
        $clients = ApiClient::query();
        $sessions = config('session.driver') === 'database' && Schema::hasTable('sessions')
            ? DB::table('sessions')->whereNotNull('user_id')->where('last_activity', '>=', now()->subMinutes((int) config('session.lifetime'))->getTimestamp())->count() : null;

        return [
            'privileged' => (clone $privileged)->count(),
            'privileged_with_mfa' => (clone $privileged)->where('two_factor_enabled', true)->whereNotNull('two_factor_confirmed_at')->count(),
            'privileged_without_mfa' => (clone $without)->orderBy('name')->limit(20)->pluck('name')->all(),
            'privileged_without_mfa_count' => $without->count(),
            'mfa_enforced' => MfaPolicy::enforced(),
            'active_sessions' => $sessions,
            'failed_sign_ins_24h' => CoreAuditLog::query()->where('action', 'auth.sign_in_failed')->where('created_at', '>=', $since)->count(),
            'lockouts_24h' => CoreAuditLog::query()->where('action', 'auth.locked_out')->where('created_at', '>=', $since)->count(),
            'break_glass_active' => BreakGlassGrant::query()->inForce()->count(),
            'api_clients' => [
                'active' => (clone $clients)->where('is_active', true)->whereNull('revoked_at')->where(fn ($q) => $q->whereNull('expires_at')->orWhere('expires_at', '>', now()))->count(),
                'revoked' => (clone $clients)->whereNotNull('revoked_at')->count(),
                'expired' => (clone $clients)->whereNull('revoked_at')->whereNotNull('expires_at')->where('expires_at', '<=', now())->count(),
            ],
            'webhooks' => [
                'failed_deliveries' => DB::table('webhook_deliveries')->where('status', 'failed')->count(),
                'suspended' => DB::table('webhook_endpoints')->whereNotNull('suspended_at')->count(),
            ],
            'recent_access_changes' => $changes->map(fn ($log): array => ['action' => $log->action, 'actor' => $actors->get($log->actor_user_id),
                'subject' => $log->subject_id, 'at' => $log->created_at?->toIso8601String()])->all(),
        ];
    }

    /** An active grant past its end is shown as expired before the scheduler records it. */
    private function status(BreakGlassGrant $grant): string
    {
        return $grant->status === BreakGlassGrant::ACTIVE && $grant->expires_at?->isPast() ? BreakGlassGrant::EXPIRED : $grant->status;
    }
}
