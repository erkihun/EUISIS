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

readonly class ApproveGrievanceResponseAction
{
    public function __construct(
        private WriteAuditLogAction $audit,
        private GenerateGrievanceDecisionLetterAction $generateLetter,
    ) {}

    public function execute(Grievance $grievance, User $actor): GrievanceResponse
    {
        return DB::transaction(function () use ($grievance, $actor): GrievanceResponse {
            $response = $grievance->responses()
                ->where('status', GrievanceResponseStatus::Compiled->value)
                ->latest()
                ->first();

            if ($response === null) {
                throw ValidationException::withMessages(['response' => __('grievances.noCompiledResponse')]);
            }

            $response->update([
                'status' => GrievanceResponseStatus::ApprovedByManager,
                'approved_by_user_id' => $actor->id,
                'approved_at' => now(),
            ]);

            $grievance->update([
                'status' => GrievanceStatus::Approved,
                'closed_at' => now(),
                'closed_by' => $actor->id,
            ]);

            $this->generateLetter->execute($grievance, $response, $actor);

            $this->audit->execute(
                eventType: AuditEventType::GrievanceResponseApproved,
                actor: $actor,
                auditable: $grievance,
                organizationId: $grievance->organization_id,
                newValues: ['status' => GrievanceStatus::Approved->value],
            );

            return $response->refresh();
        });
    }
}
