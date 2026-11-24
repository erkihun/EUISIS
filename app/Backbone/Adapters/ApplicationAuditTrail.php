<?php

declare(strict_types=1);

namespace App\Backbone\Adapters;

use App\Actions\Audit\WriteAuditLogAction;
use App\Backbone\Contracts\AuditTrail;
use App\Enums\AuditEventType;
use App\Models\User;

/**
 * Writes through the one audit action, so redaction, the request's
 * correlation id and the organization check apply. The subject is named in
 * the entry's values because the module's records are not audit models.
 */
final readonly class ApplicationAuditTrail implements AuditTrail
{
    public function __construct(private WriteAuditLogAction $write) {}

    public function record(
        string $event,
        ?int $actorUserId,
        string $subjectType,
        string $subjectId,
        ?string $organizationUuid = null,
        ?array $before = null,
        ?array $after = null,
        ?string $reason = null,
    ): void {
        $this->write->execute(
            AuditEventType::from($event),
            $actorUserId === null ? null : User::query()->find($actorUserId),
            null,
            $organizationUuid,
            $before,
            ['subject_type' => $subjectType, 'subject_id' => $subjectId, ...($after ?? [])],
            $reason,
        );
    }
}
