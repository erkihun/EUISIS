<?php

declare(strict_types=1);

namespace App\Services\Performance;

use App\Actions\Audit\WriteAuditLogAction;
use App\Enums\AuditEventType;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;

/** Thin wrapper so every EPMS state change is audited the same way. */
final class EpmsAudit
{
    public function __construct(private readonly WriteAuditLogAction $write) {}

    /**
     * @param  array<string, mixed>  $new
     * @param  array<string, mixed>|null  $old
     */
    public function record(AuditEventType $event, ?User $actor, Model $subject, array $new = [], ?array $old = null, ?string $reason = null): void
    {
        $organizationId = $subject->getAttribute('organization_id');

        $this->write->execute(
            $event,
            $actor,
            $subject,
            is_string($organizationId) ? $organizationId : null,
            $old,
            $new,
            $reason,
            request(),
        );
    }
}
