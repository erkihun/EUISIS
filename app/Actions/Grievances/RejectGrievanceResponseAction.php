<?php

declare(strict_types=1);

namespace App\Actions\Grievances;

use App\Actions\Audit\WriteAuditLogAction;
use App\Enums\AuditEventType;
use App\Enums\GrievanceResponseStatus;
use App\Enums\GrievanceStatus;
use App\Models\Grievance;
use App\Models\GrievanceResponse;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

readonly class RejectGrievanceResponseAction
{
    public function __construct(private WriteAuditLogAction $audit) {}

    public function execute(Grievance $grievance, User $actor, string $reason): GrievanceResponse
    {
        return DB::transaction(function () use ($grievance, $actor, $reason): GrievanceResponse {
            $response = $grievance->responses()
                ->where('status', GrievanceResponseStatus::Compiled->value)
                ->latest()
                ->first();

            if ($response === null) {
                throw ValidationException::withMessages(['response' => __('grievances.noCompiledResponse')]);
            }

            $response->update([
                'status' => GrievanceResponseStatus::RejectedByManager,
                'rejected_by_user_id' => $actor->id,
                'rejected_at' => now(),
                'rejection_reason' => $reason,
            ]);

            $grievance->update(['status' => GrievanceStatus::InProgress]);

            $this->audit->execute(
                eventType: AuditEventType::GrievanceResponseRejected,
                actor: $actor,
                auditable: $grievance,
                organizationId: $grievance->organization_id,
                newValues: ['status' => GrievanceStatus::InProgress->value, 'rejection_reason' => $reason],
            );

            return $response->refresh();
        });
    }
}
