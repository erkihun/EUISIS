<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\CoreAuditLog;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;

/**
 * Writes one append-only audit row per master-data change, in the caller's
 * transaction, with the request id, IP and user agent of the HTTP request and
 * where the change came from (frontend, API client or console). Secrets and
 * personal identifiers never go in: callers pass chosen fields, and known
 * secret keys are masked whatever is passed.
 */
final class AuditWriter
{
    public function __construct(private readonly DomainEvents $events) {}

    /**
     * @param  array<string, mixed>|null  $old
     * @param  array<string, mixed>|null  $new
     */
    public function write(string $action, ?User $actor, Model $subject, ?string $organizationId, ?array $old = null, ?array $new = null, ?string $reason = null): CoreAuditLog
    {
        return $this->writeFor($action, $actor, class_basename($subject), (string) $subject->getKey(), $organizationId, $old, $new, $reason);
    }

    /**
     * The same row for a subject that is not one model (a settings group).
     *
     * @param  array<string, mixed>|null  $old
     * @param  array<string, mixed>|null  $new
     */
    public function writeFor(string $action, ?User $actor, string $subjectType, string $subjectId, ?string $organizationId, ?array $old = null, ?array $new = null, ?string $reason = null): CoreAuditLog
    {
        $request = app()->runningInConsole() && ! app()->runningUnitTests() ? null : (app()->bound('request') ? request() : null);
        $client = $request?->attributes->get('api_client');

        $log = CoreAuditLog::query()->create([
            'actor_user_id' => $actor?->getKey(),
            'action' => $action,
            'subject_type' => $subjectType,
            'subject_id' => mb_substr($subjectId, 0, 64),
            'organization_id' => $organizationId,
            'old_values' => $old === null ? null : self::masked($old),
            'new_values' => $new === null ? null : self::masked($new),
            'reason' => $reason === null ? null : mb_substr($reason, 0, 1000),
            'request_id' => $request?->attributes->get('request_id'),
            'request_ip' => $request?->ip(),
            'user_agent' => $request === null ? null : mb_substr((string) $request->userAgent(), 0, 512),
            'source' => match (true) {
                $client !== null => 'api_client',
                $request === null => 'console',
                default => 'frontend',
            },
            'api_client_id' => $client?->uuid,
            'created_at' => now(),
        ]);
        // The integration event of the same change, in the same transaction (outbox).
        $this->events->publish($log);

        return $log;
    }

    /** Keys whose values never go into an audit row, whatever a caller passes. */
    private const SECRET_KEYS = ['password', 'token', 'secret', 'token_hash', 'qr_payload', 'national_id', 'national_id_hash', 'signing_secret'];

    /**
     * @param  array<string, mixed>  $values
     * @return array<string, mixed>
     */
    private static function masked(array $values): array
    {
        foreach ($values as $key => $value) {
            if (in_array(strtolower((string) $key), self::SECRET_KEYS, true)) {
                $values[$key] = '[hidden]';
            } elseif (is_array($value)) {
                $values[$key] = self::masked($value);
            }
        }

        return $values;
    }
}
