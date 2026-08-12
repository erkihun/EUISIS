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

readonly class CompileGrievanceResponseAction
{
    public function __construct(private WriteAuditLogAction $audit) {}

    /**
     * @param  array{response_body_en: string, response_body_am?: string|null}  $data
     */
    public function execute(Grievance $grievance, User $actor, array $data): GrievanceResponse
    {
        return DB::transaction(function () use ($grievance, $actor, $data): GrievanceResponse {
            $assignment = $grievance->currentAssignment;

            if ($assignment === null) {
                throw ValidationException::withMessages(['grievance' => __('grievances.notAssigned')]);
            }

            $lastResponse = $grievance->responses()->latest()->first();
            $round = $lastResponse ? $lastResponse->revision_round + 1 : 1;

            $response = GrievanceResponse::query()->create([
                'grievance_id' => $grievance->id,
                'committee_id' => $assignment->committee_id,
                'compiled_by_employee_id' => $actor->employee_id,
                'response_body_en' => $data['response_body_en'],
                'response_body_am' => $data['response_body_am'] ?? null,
                'status' => GrievanceResponseStatus::Compiled,
                'revision_round' => $round,
                'compiled_at' => now(),
            ]);

            $grievance->update(['status' => GrievanceStatus::AwaitingApproval]);

            $this->audit->execute(
                eventType: AuditEventType::GrievanceResponseCompiled,
                actor: $actor,
                auditable: $grievance,
                organizationId: $grievance->organization_id,
                newValues: ['status' => GrievanceStatus::AwaitingApproval->value, 'response_id' => $response->id],
            );

            return $response;
        });
    }
}
