<?php

declare(strict_types=1);

namespace App\Actions\Audit;

use App\Enums\AuditEventType;
use App\Models\AuditLog;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class WriteAuditLogAction
{
    /**
     * Keys whose values must never appear in the audit log, regardless of caller.
     * Values are replaced with the string '[REDACTED]'.
     */
    private const REDACTED_KEYS = [
        'password',
        'password_confirmation',
        'default_password_hash',
        'default_password_hash_confirmation',
        'current_password',
        'national_id',
        'two_factor_secret',
        'two_factor_recovery_codes',
        'phone_number',
        'remember_token',
        'qr_token',
        'qr_hash',
        'token_hash',
        'secret',
        'api_key',
        // One-time codes and bearer credentials (defense in depth: callers
        // should never pass these, but the log must not keep them if they do).
        'otp',
        'otp_code',
        'otp_hash',
        'code_hash',
        'recovery_code',
        'recovery_codes',
        'access_token',
        'refresh_token',
        'plain_text_token',
        'client_secret',
        'authorization',
        'two_factor_recovery_codes',
        // Password lifecycle (docs/password-security-policy.md).
        'new_password',
        'temporary_password',
        'user_password',
        'password_hash',
        'reset_token',
        'token',
    ];

    public function execute(
        AuditEventType $eventType,
        ?User $actor,
        ?Model $auditable = null,
        ?string $organizationId = null,
        ?array $oldValues = null,
        ?array $newValues = null,
        ?string $reason = null,
        ?Request $request = null,
    ): AuditLog {
        $organizationId = $this->validOrganizationId($organizationId);

        return AuditLog::query()->create([
            'actor_user_id' => $actor?->getKey(),
            'actor_type' => $actor ? $actor::class : null,
            'event_type' => $eventType,
            'auditable_type' => $auditable ? $auditable::class : null,
            'auditable_id' => $auditable?->getKey(),
            'organization_id' => $organizationId,
            'old_values' => $this->redact($oldValues),
            'new_values' => $this->redact($newValues),
            'reason' => $reason,
            'request_id' => (string) Str::uuid7(),
            'request_ip' => $request?->ip(),
            'user_agent' => $request?->userAgent(),
            'created_at' => now(),
        ]);
    }

    /** Strip sensitive fields from value arrays before persisting to the audit log. */
    private function redact(?array $values): ?array
    {
        if ($values === null) {
            return null;
        }

        $redacted = [];

        foreach ($values as $key => $value) {
            $redacted[$key] = match (true) {
                in_array(strtolower((string) $key), self::REDACTED_KEYS, true) => '[REDACTED]',
                // Nested payloads (item lists, settings groups) are redacted too.
                is_array($value) => $this->redact($value),
                default => $value,
            };
        }

        return $redacted;
    }

    private function validOrganizationId(?string $organizationId): ?string
    {
        if ($organizationId === null) {
            return null;
        }

        return Organization::query()->whereKey($organizationId)->exists()
            ? $organizationId
            : null;
    }
}
