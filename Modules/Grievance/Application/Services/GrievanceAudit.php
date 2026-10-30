<?php

declare(strict_types=1);

namespace App\Services\Grievances;

use App\Actions\Audit\WriteAuditLogAction;
use App\Enums\AuditEventType;
use App\Models\Grievance;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;

/**
 * Every grievance state change is audited through here. Values are ids,
 * statuses, codes and dates only: the grievance narrative, decision text,
 * notes and file contents are never written to the audit log.
 */
final class GrievanceAudit
{
    public function __construct(private readonly WriteAuditLogAction $write) {}

    /**
     * @param  array<string, mixed>  $new
     * @param  array<string, mixed>|null  $old
     */
    public function record(AuditEventType $event, ?User $actor, Model $subject, array $new = [], ?array $old = null, ?string $reason = null): void
    {
        $organizationId = $subject instanceof Grievance
            ? $subject->organization_id
            : ($subject->getAttribute('organization_id') ?? $this->grievanceOrganization($subject));

        $this->write->execute(
            $event,
            $actor,
            $subject,
            is_string($organizationId) ? $organizationId : null,
            $old,
            $new,
            $reason,
            app()->runningInConsole() ? null : request(),
        );
    }

    private function grievanceOrganization(Model $subject): ?string
    {
        $grievanceId = $subject->getAttribute('grievance_id');
        if (! is_string($grievanceId)) {
            return null;
        }

        $organizationId = Grievance::query()->whereKey($grievanceId)->value('organization_id');

        return is_string($organizationId) ? $organizationId : null;
    }
}
