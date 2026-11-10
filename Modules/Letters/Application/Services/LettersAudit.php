<?php

declare(strict_types=1);

namespace Modules\Letters\Application\Services;

use App\Actions\Audit\WriteAuditLogAction;
use App\Enums\AuditEventType;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;

/**
 * Every registration, number assignment, approval, dispatch, receipt, routing
 * step, download and template publication goes to the platform audit log
 * through here, tagged with the institution it concerns so scoped auditors
 * see it.
 */
class LettersAudit
{
    public function __construct(private readonly WriteAuditLogAction $audit) {}

    /** @param array<string, mixed> $values */
    public function record(AuditEventType $event, ?User $actor, ?Model $subject, ?string $organizationId, array $values = [], ?string $reason = null, ?array $old = null): void
    {
        $this->audit->execute(
            eventType: $event,
            actor: $actor,
            auditable: $subject,
            organizationId: $organizationId,
            oldValues: $old,
            newValues: ['module' => 'letters', ...$values],
            reason: $reason,
        );
    }
}
