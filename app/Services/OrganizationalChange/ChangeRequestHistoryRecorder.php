<?php

declare(strict_types=1);

namespace App\Services\OrganizationalChange;

use App\Actions\Audit\WriteAuditLogAction;
use App\Enums\AuditEventType;
use App\Enums\OrganizationalChangeRequestStatus;
use App\Models\OrganizationalChangeRequest;
use App\Models\OrganizationalChangeRequestHistory;
use App\Models\User;
use Illuminate\Http\Request;

/**
 * Writes the append-only workflow trail and the matching audit-log entry.
 *
 * Request-level history is kept separate from master-data history: this class
 * records what happened to the *request*, while the implementation service
 * writes position/unit history for what happened to the *records*.
 */
final readonly class ChangeRequestHistoryRecorder
{
    public function __construct(private WriteAuditLogAction $audit) {}

    /**
     * @param  array<string, mixed>|null  $changedFields
     * @param  array<string, mixed>|null  $context
     */
    public function record(
        OrganizationalChangeRequest $request,
        ?User $actor,
        string $action,
        ?OrganizationalChangeRequestStatus $from,
        ?OrganizationalChangeRequestStatus $to,
        ?string $comment = null,
        ?array $changedFields = null,
        ?array $context = null,
        ?AuditEventType $auditEvent = null,
        ?Request $httpRequest = null,
    ): OrganizationalChangeRequestHistory {
        $history = OrganizationalChangeRequestHistory::query()->create([
            'request_id' => $request->getKey(),
            'actor_id' => $actor?->getKey(),
            'action' => $action,
            'from_status' => $from?->value,
            'to_status' => $to?->value,
            'comment' => $comment,
            'changed_fields' => $changedFields,
            'context' => $context,
            'revision' => $request->revision,
            'created_at' => now(),
        ]);

        if ($auditEvent !== null) {
            $this->audit->execute(
                eventType: $auditEvent,
                actor: $actor,
                auditable: $request,
                organizationId: $request->organization_id,
                oldValues: $from !== null ? ['status' => $from->value] : null,
                newValues: array_filter([
                    'request_no' => $request->request_no,
                    'status' => $to?->value,
                    'request_type' => $request->request_type->value,
                ], static fn ($value): bool => $value !== null),
                reason: $comment,
                request: $httpRequest,
            );
        }

        return $history;
    }
}
