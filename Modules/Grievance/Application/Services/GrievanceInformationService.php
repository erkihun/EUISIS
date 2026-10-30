<?php

declare(strict_types=1);

namespace App\Services\Grievances;

use App\Enums\AuditEventType;
use App\Enums\Grievance\GrievanceInformationRequestStatus;
use App\Enums\Grievance\GrievanceInformationTarget;
use App\Enums\Grievance\GrievanceSlaPauseReason;
use App\Enums\Grievance\GrievanceSlaPauseStatus;
use App\Enums\Grievance\GrievanceStageStatus;
use App\Models\Grievance;
use App\Models\GrievanceInformationRequest;
use App\Models\GrievanceInformationResponse;
use App\Models\GrievanceSlaPause;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Requests for further information (docs/grievance-management.md §8.7).
 * A request may pause the stage deadline — immediately when the requester
 * holds grievances.sla_pause, otherwise as a pause request needing approval.
 * Responses (and their files) attach to the request they answer only.
 */
final class GrievanceInformationService
{
    public function __construct(
        private readonly GrievanceCaseAccessService $access,
        private readonly GrievanceCaseService $cases,
        private readonly GrievanceSlaService $sla,
        private readonly GrievanceEvidenceService $evidence,
        private readonly GrievanceAudit $audit,
        private readonly GrievanceTimeline $timeline,
        private readonly GrievanceNotifier $notifier,
    ) {}

    /** @param  array{requested_from_type: string, requested_from_id?: string|null, requested_from_name?: string|null, request_text: string, due_at?: string|null, pauses_sla?: bool}  $data */
    public function request(Grievance $grievance, User $actor, array $data): GrievanceInformationRequest
    {
        $this->access->authorize($this->access->canHandle($actor, $grievance, 'grievances.request_information'));
        $target = GrievanceInformationTarget::from($data['requested_from_type']);

        return DB::transaction(function () use ($grievance, $actor, $data, $target): GrievanceInformationRequest {
            $grievance = $this->cases->lock($grievance);
            $stage = $this->cases->lockedCurrentStage($grievance);

            $request = GrievanceInformationRequest::query()->create([
                'grievance_id' => $grievance->getKey(),
                'case_stage_id' => $stage->getKey(),
                'requested_from_type' => $target,
                // For the complainant the id is the case's own employee: never user input.
                'requested_from_id' => $target === GrievanceInformationTarget::Employee ? $grievance->employee_id : ($data['requested_from_id'] ?? null),
                'requested_from_name' => $data['requested_from_name'] ?? null,
                'request_text' => $data['request_text'],
                'due_at' => $data['due_at'] ?? null,
                'status' => GrievanceInformationRequestStatus::Open,
                'pauses_sla' => (bool) ($data['pauses_sla'] ?? false),
                'requested_by' => $actor->getKey(),
                'requested_at' => now(),
            ]);

            if ($request->pauses_sla && $stage->due_at !== null) {
                $reason = $target === GrievanceInformationTarget::Employee
                    ? GrievanceSlaPauseReason::AwaitingEmployeeInformation
                    : GrievanceSlaPauseReason::AwaitingExternalInformation;
                $pause = $this->sla->requestPause($stage, $reason, 'information_request:'.$request->getKey(), $actor);
                $request->forceFill(['sla_pause_id' => $pause->getKey()])->save();
            }

            $this->cases->setWorkingStatus($grievance->setRelation('currentStage', $stage), GrievanceStageStatus::AwaitingInformation);
            $this->audit->record(AuditEventType::GrievanceInformationRequested, $actor, $request, ['target' => $target->value, 'pauses_sla' => $request->pauses_sla]);
            // The complainant sees that information was requested only when it is requested from them.
            $this->timeline->record($grievance, $target === GrievanceInformationTarget::Employee ? 'information_requested' : 'information_requested_internal', $actor, ['request_id' => $request->getKey()], $stage->getKey());
            if ($target === GrievanceInformationTarget::Employee) {
                $this->notifier->toComplainant($grievance, 'information_requested');
            }

            return $request;
        });
    }

    /**
     * Record a response. The complainant answers requests addressed to them;
     * a handler records answers received from HR, units or institutions.
     *
     * @param  list<UploadedFile>  $files
     */
    public function respond(GrievanceInformationRequest $request, User $actor, string $text, array $files = []): GrievanceInformationResponse
    {
        $grievance = $request->grievance;
        $byComplainant = $grievance !== null && $this->access->isComplainant($actor, $grievance);
        $this->access->authorize($grievance !== null && (
            $byComplainant
                ? $request->requested_from_type === GrievanceInformationTarget::Employee && $actor->can('grievances.submit')
                : $this->access->canReview($actor, $grievance)
        ));

        $response = DB::transaction(function () use ($request, $actor, $text): GrievanceInformationResponse {
            $request = GrievanceInformationRequest::query()->whereKey($request->getKey())->lockForUpdate()->firstOrFail();
            if ($request->status !== GrievanceInformationRequestStatus::Open) {
                throw ValidationException::withMessages(['request' => __('grievances.errors.request_not_open')]);
            }

            $response = GrievanceInformationResponse::query()->create([
                'information_request_id' => $request->getKey(),
                'grievance_id' => $request->grievance_id,
                'response_text' => $text,
                'responded_by' => $actor->getKey(),
                'responded_by_employee_id' => $actor->employee_id,
                'responded_at' => now(),
            ]);
            $request->forceFill(['status' => GrievanceInformationRequestStatus::Responded, 'responded_at' => now()])->save();

            return $response;
        });

        foreach ($files as $file) {
            $this->evidence->upload($grievance, $file, [
                'evidence_type' => 'document',
                'title' => __('grievances.labels.information_response_file'),
                'information_request_id' => $request->getKey(),
                'information_response_id' => $response->getKey(),
            ], $actor, $byComplainant);
        }

        $this->endPause($request, $actor);
        $this->audit->record(AuditEventType::GrievanceInformationResponded, $actor, $request, ['files' => count($files)]);
        $this->timeline->record($grievance, 'information_responded', $actor, ['request_id' => $request->getKey()], $request->case_stage_id);
        if ($stage = $grievance->currentStage) {
            $this->notifier->toStageHandlers($stage->setRelation('grievance', $grievance), 'information_received', $actor);
        }

        return $response;
    }

    public function close(GrievanceInformationRequest $request, User $actor, bool $cancel = false): GrievanceInformationRequest
    {
        $grievance = $request->grievance;
        $this->access->authorize($grievance !== null && $this->access->canReview($actor, $grievance));

        $request->forceFill([
            'status' => $cancel ? GrievanceInformationRequestStatus::Cancelled : GrievanceInformationRequestStatus::Closed,
            'closed_at' => now(),
        ])->save();
        $this->endPause($request, $actor);
        $this->audit->record(AuditEventType::GrievanceInformationRequestClosed, $actor, $request, ['cancelled' => $cancel]);

        return $request;
    }

    private function endPause(GrievanceInformationRequest $request, User $actor): void
    {
        if ($request->sla_pause_id === null) {
            return;
        }
        $pause = GrievanceSlaPause::query()->find($request->sla_pause_id);
        if ($pause === null) {
            return;
        }
        if ($pause->status === GrievanceSlaPauseStatus::Active) {
            $this->sla->resume($pause, $actor);
        } elseif ($pause->status === GrievanceSlaPauseStatus::Requested) {
            $pause->forceFill(['status' => GrievanceSlaPauseStatus::Ended, 'ended_at' => now(), 'ended_by' => $actor->getKey(), 'paused_days' => 0])->save();
        }

        // Back to review once nothing is outstanding.
        $grievance = $request->grievance;
        if ($grievance !== null && ! $grievance->informationRequests()->where('status', GrievanceInformationRequestStatus::Open->value)->exists()) {
            $grievance->load('currentStage');
            if ($grievance->currentStage?->status === GrievanceStageStatus::AwaitingInformation) {
                $this->cases->setWorkingStatus($grievance, GrievanceStageStatus::UnderReview);
            }
        }
    }
}
