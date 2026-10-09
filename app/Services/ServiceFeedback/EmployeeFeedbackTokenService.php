<?php

declare(strict_types=1);

namespace App\Services\ServiceFeedback;

use App\Actions\Audit\WriteAuditLogAction;
use App\Enums\AuditEventType;
use App\Enums\EmployeeStatus;
use App\Enums\FeedbackTokenStatus;
use App\Models\Employee;
use App\Models\EmployeeFeedbackToken;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Issues and retires the public feedback QR token for an employee.
 *
 * The token is the ONLY thing standing between the public internet and an
 * employee's feedback page, so it is generated from a CSPRNG at a length that
 * makes guessing pointless, and it is never derived from anything about the
 * employee. A URL therefore leaks no employee number, UUID or name even before
 * the page renders.
 *
 * Regeneration is deliberately destructive to the old token: a QR that has been
 * photographed or leaked must stop working the moment a new one is printed.
 */
class EmployeeFeedbackTokenService
{
    /**
     * 32 bytes of randomness rendered as 64 hex characters. Long enough that
     * enumerating the space is infeasible, short enough to encode in a QR at a
     * modest error-correction level.
     */
    private const TOKEN_BYTES = 32;

    public function __construct(private readonly WriteAuditLogAction $writeAuditLogAction) {}

    /**
     * Return the employee's active token, creating one on first use.
     *
     * Safe to call repeatedly — it never rotates an existing active token, so
     * a printed QR keeps working.
     */
    public function ensureActiveToken(Employee $employee, ?User $actor = null, ?Request $request = null): EmployeeFeedbackToken
    {
        $existing = $this->activeToken($employee);

        if ($existing !== null) {
            return $existing;
        }

        return $this->issue($employee, $actor, $request, AuditEventType::ServiceFeedbackQrGenerated);
    }

    /**
     * Revoke the current token and issue a replacement in one transaction.
     *
     * Existing feedback keeps pointing at the old token row (nullOnDelete is
     * never triggered because rows are retained), so history survives a rotation.
     */
    public function regenerate(Employee $employee, ?User $actor = null, ?Request $request = null): EmployeeFeedbackToken
    {
        return DB::transaction(function () use ($employee, $actor, $request): EmployeeFeedbackToken {
            $current = $this->activeToken($employee);

            if ($current !== null) {
                $this->markRevoked($current, $actor);
            }

            return $this->issue($employee, $actor, $request, AuditEventType::ServiceFeedbackQrRegenerated);
        });
    }

    /** Retire a token permanently. The QR stops resolving immediately. */
    public function revoke(EmployeeFeedbackToken $token, ?User $actor = null, ?Request $request = null): EmployeeFeedbackToken
    {
        $this->markRevoked($token, $actor);

        $this->writeAuditLogAction->execute(
            eventType: AuditEventType::ServiceFeedbackQrRevoked,
            actor: $actor,
            auditable: $token,
            reason: 'Employee feedback QR revoked',
            request: $request,
        );

        return $token->refresh();
    }

    /** Pause a token without retiring it; `activate()` brings it back. */
    public function suspend(EmployeeFeedbackToken $token, ?User $actor = null, ?Request $request = null): EmployeeFeedbackToken
    {
        $token->forceFill(['status' => FeedbackTokenStatus::Suspended])->save();

        $this->writeAuditLogAction->execute(
            eventType: AuditEventType::ServiceFeedbackQrSuspended,
            actor: $actor,
            auditable: $token,
            reason: 'Employee feedback QR suspended',
            request: $request,
        );

        return $token->refresh();
    }

    /**
     * Reinstate a suspended token.
     *
     * A revoked token is terminal and is never reinstated — callers must
     * regenerate instead, which is why this returns false rather than throwing.
     */
    public function activate(EmployeeFeedbackToken $token): bool
    {
        if ($token->status === FeedbackTokenStatus::Revoked) {
            return false;
        }

        $token->forceFill(['status' => FeedbackTokenStatus::Active])->save();

        return true;
    }

    /**
     * Auto-provision a token for an employee who should have one.
     *
     * This is the entry point for implicit generation — employee registration
     * and opening the QR screen — as opposed to `ensureActiveToken()`, which an
     * administrator drives explicitly by pressing a button.
     *
     * Two rules keep implicit generation from doing something unwanted:
     *
     *  1. Only ACTIVE employees get a token. Minting a live public feedback URL
     *     for a terminated, retired or deceased employee would invite ratings
     *     for someone who no longer serves the public.
     *  2. A deliberate revocation is never undone. If an administrator revoked
     *     or suspended the token, the absence of an active one is the intended
     *     state, and silently issuing a replacement would defeat them.
     *
     * Returns null when no token should exist, so callers can render an
     * explanatory empty state rather than a broken QR.
     */
    public function ensureActiveTokenFor(Employee $employee, ?User $actor = null, ?Request $request = null): ?EmployeeFeedbackToken
    {
        $existing = $this->activeToken($employee);

        if ($existing !== null) {
            return $existing;
        }

        if ($employee->status !== EmployeeStatus::Active) {
            return null;
        }

        // A revoked or suspended row means an administrator acted; respect it.
        if ($this->hasDeliberatelyDisabledToken($employee)) {
            return null;
        }

        return $this->issue($employee, $actor, $request, AuditEventType::ServiceFeedbackQrGenerated);
    }

    /** True when a token exists but was revoked or suspended by an administrator. */
    public function hasDeliberatelyDisabledToken(Employee $employee): bool
    {
        return EmployeeFeedbackToken::query()
            ->where('employee_id', $employee->getKey())
            ->whereIn('status', [
                FeedbackTokenStatus::Revoked->value,
                FeedbackTokenStatus::Suspended->value,
            ])
            ->exists();
    }

    public function activeToken(Employee $employee): ?EmployeeFeedbackToken
    {
        return EmployeeFeedbackToken::query()
            ->where('employee_id', $employee->getKey())
            ->where('status', FeedbackTokenStatus::Active->value)
            ->latest('created_at')
            ->first();
    }

    /**
     * Resolve a scanned token string to a token that may accept feedback.
     *
     * Returns null for unknown, suspended and revoked tokens alike; the caller
     * renders one generic "link not available" page for all three so the
     * endpoint cannot be probed to learn which tokens exist.
     */
    public function resolveForPublic(string $token): ?EmployeeFeedbackToken
    {
        $record = EmployeeFeedbackToken::query()
            ->with(['employee'])
            ->where('token', $token)
            ->first();

        if ($record === null || ! $record->acceptsFeedback() || $record->employee === null) {
            return null;
        }

        return $record;
    }

    /** Cheap scan telemetry for the admin QR card; not a security control. */
    public function recordScan(EmployeeFeedbackToken $token): void
    {
        EmployeeFeedbackToken::query()
            ->whereKey($token->getKey())
            ->update([
                'last_scanned_at' => now(),
                'scan_count' => DB::raw('scan_count + 1'),
            ]);
    }

    private function issue(Employee $employee, ?User $actor, ?Request $request, AuditEventType $event): EmployeeFeedbackToken
    {
        $token = EmployeeFeedbackToken::query()->create([
            'employee_id' => $employee->getKey(),
            'token' => $this->generateToken(),
            'status' => FeedbackTokenStatus::Active,
            'created_by' => $actor?->getKey(),
        ]);

        $this->writeAuditLogAction->execute(
            eventType: $event,
            actor: $actor,
            auditable: $token,
            reason: 'Employee feedback QR issued',
            request: $request,
        );

        return $token;
    }

    private function markRevoked(EmployeeFeedbackToken $token, ?User $actor): void
    {
        $token->forceFill([
            'status' => FeedbackTokenStatus::Revoked,
            'revoked_by' => $actor?->getKey(),
            'revoked_at' => now(),
        ])->save();
    }

    private function generateToken(): string
    {
        do {
            $candidate = bin2hex(random_bytes(self::TOKEN_BYTES));
        } while (EmployeeFeedbackToken::query()->where('token', $candidate)->exists());

        return $candidate;
    }
}
