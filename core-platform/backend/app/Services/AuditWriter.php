<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\CoreAuditLog;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;

/**
 * Writes one append-only audit row per master-data change, in the caller's
 * transaction, with the request id, IP and user agent of the HTTP request.
 * Secrets and personal identifiers never go in (callers pass chosen fields).
 */
final class AuditWriter
{
    /**
     * @param  array<string, mixed>|null  $old
     * @param  array<string, mixed>|null  $new
     */
    public function write(string $action, ?User $actor, Model $subject, ?string $organizationId, ?array $old = null, ?array $new = null, ?string $reason = null): CoreAuditLog
    {
        $request = app()->bound('request') ? request() : null;

        return CoreAuditLog::query()->create([
            'actor_user_id' => $actor?->getKey(),
            'action' => $action,
            'subject_type' => class_basename($subject),
            'subject_id' => (string) $subject->getKey(),
            'organization_id' => $organizationId,
            'old_values' => $old,
            'new_values' => $new,
            'reason' => $reason,
            'request_id' => $request?->attributes->get('request_id'),
            'request_ip' => $request?->ip(),
            'user_agent' => $request === null ? null : mb_substr((string) $request->userAgent(), 0, 512),
            'created_at' => now(),
        ]);
    }
}
