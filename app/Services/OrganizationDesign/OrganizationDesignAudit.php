<?php

declare(strict_types=1);

namespace App\Services\OrganizationDesign;

use App\Actions\Audit\WriteAuditLogAction;
use App\Enums\AuditEventType;
use App\Models\OrganizationDesignStatusHistory;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;

/**
 * Every organization-design state change is audited the same way, and every
 * lifecycle transition is also written to the immutable status history.
 */
final class OrganizationDesignAudit
{
    public function __construct(private readonly WriteAuditLogAction $write) {}

    /**
     * @param  array<string, mixed>  $new
     * @param  array<string, mixed>|null  $old
     */
    public function record(AuditEventType $event, ?User $actor, Model $subject, array $new = [], ?array $old = null, ?string $reason = null): void
    {
        $organizationId = $subject->getAttribute('organization_id');

        $this->write->execute($event, $actor, $subject, is_string($organizationId) ? $organizationId : null, $old, $new, $reason, request());
    }

    public function transition(?User $actor, Model $subject, ?string $from, string $to, ?string $reason = null): void
    {
        OrganizationDesignStatusHistory::query()->create([
            'entity_type' => $subject->getMorphClass(),
            'entity_id' => $subject->getKey(),
            'from_status' => $from,
            'to_status' => $to,
            'actor_id' => $actor?->getKey(),
            'reason' => $reason,
            'created_at' => now(),
        ]);
    }
}
