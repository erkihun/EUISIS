<?php

declare(strict_types=1);

namespace App\Backbone\Contracts;

/**
 * Appends one entry to the application audit log on behalf of a module that
 * does not use the audit models. The log is append-only for the application;
 * it does not claim protection against a database administrator.
 */
interface AuditTrail
{
    /**
     * @param  string  $event  a value of App\Enums\AuditEventType
     * @param  string  $subjectType  a stable name for the kind of record, e.g. "reporting.obligation"
     * @param  array<string, mixed>|null  $before
     * @param  array<string, mixed>|null  $after
     */
    public function record(
        string $event,
        ?int $actorUserId,
        string $subjectType,
        string $subjectId,
        ?string $organizationUuid = null,
        ?array $before = null,
        ?array $after = null,
        ?string $reason = null,
    ): void;
}
