<?php

declare(strict_types=1);

namespace App\Services\ServiceFeedback;

use App\Actions\Audit\WriteAuditLogAction;
use App\Actions\CodeRules\GenerateCodeAction;
use App\Enums\AuditEventType;
use App\Enums\CodeRuleEntityType;
use App\Enums\PublicFeedbackTargetType;
use App\Enums\PublicIntakeChannel;
use App\Enums\PublicTipType;
use App\Enums\ServiceFeedbackStatus;
use App\Enums\ServiceFeedbackType;
use App\Models\EmployeeServiceFeedback;
use App\Models\OrganizationUnit;
use App\Models\PositionService;
use App\Models\Service;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Creates a public submission for a resolved target (brief §106).
 *
 * The target comes only from PublicFeedbackTargetResolver — never from the
 * request — and is written with its snapshots so later transfers, renames or
 * QR rotations cannot move the row; text in the submission naming another
 * person or office never re-targets it. What is stored about the submitter
 * follows PublicSubmissionPolicy: Feedback and Complaint carry a named
 * submitter, a Tip-off carries only what the tipster chose to give. The
 * channel is recorded as WEB_QR, never asked. Tip-offs and Complaints get
 * their reference from the code rules and keep their evidence on the private
 * disk. An accidental double submit inside a short window returns the row
 * already stored rather than creating a twin (brief §39).
 */
class PublicFeedbackSubmissionService
{
    private const DUPLICATE_WINDOW_SECONDS = 120;

    public function __construct(
        private readonly WriteAuditLogAction $audit,
        private readonly GenerateCodeAction $codes,
        private readonly PublicFeedbackWorkflow $workflow,
        private readonly PublicFeedbackRoutingService $routing,
        private readonly PublicFeedbackSettings $settings,
        private readonly PublicFeedbackNotifier $notifier,
        private readonly PublicSubmissionPolicy $policy,
        private readonly PublicFeedbackQuestionnaire $questionnaire,
    ) {}

    /**
     * @param  array<string, mixed>  $data  validated form data; target fields in it are ignored
     */
    public function submit(TargetContext $target, ServiceFeedbackType $type, array $data, Request $request): EmployeeServiceFeedback
    {
        $fingerprint = $this->fingerprint($target, $type, $data, $request);

        return Cache::lock('public-feedback:submit:'.$fingerprint, self::DUPLICATE_WINDOW_SECONDS)
            ->block(10, function () use ($target, $type, $data, $request, $fingerprint): EmployeeServiceFeedback {
                $existing = $this->duplicateOf($fingerprint);

                if ($existing !== null) {
                    return $existing;
                }

                $entry = $this->submitNew($target, $type, $data, $request);

                try {
                    Cache::put('public-feedback:dup:'.$fingerprint, (string) $entry->getKey(), now()->addSeconds(self::DUPLICATE_WINDOW_SECONDS));
                } catch (\Throwable $exception) {
                    // The database commit is already complete. Cache trouble must not turn a saved
                    // submission into a false failure or prompt the visitor to send it again.
                    Log::warning('Public feedback duplicate marker could not be stored', ['exception' => $exception::class]);
                }

                return $entry;
            });
    }

    /** @param array<string, mixed> $data */
    private function submitNew(TargetContext $target, ServiceFeedbackType $type, array $data, Request $request): EmployeeServiceFeedback
    {
        $service = $target->targetType === PublicFeedbackTargetType::Employee && ! empty($data['position_service_id'])
            ? PositionService::query()->find($data['position_service_id'])
            : null;
        $requirements = $this->policy->for($type, $this->settings->allowAttachments());
        $tipType = $requirements->tipTypeRequired ? PublicTipType::from((string) $data['tip_type']) : null;
        $organizationForm = $this->organizationForm($target, $type, $data);
        $storedPaths = [];

        try {
            return DB::transaction(function () use ($target, $type, $data, $request, $service, $requirements, $tipType, $organizationForm, &$storedPaths): EmployeeServiceFeedback {
                $entry = EmployeeServiceFeedback::query()->create([
                    // Target and source: only ever from the resolved context.
                    'target_type' => $target->targetType,
                    'source_type' => $target->sourceType,
                    'employee_id' => $target->employeeId,
                    'organization_target_id' => $target->organizationTargetId,
                    'employee_feedback_token_id' => $target->targetType === PublicFeedbackTargetType::Employee ? $target->sourceId : null,
                    'source_organization_qr_id' => $target->targetType === PublicFeedbackTargetType::Organization ? $target->sourceId : null,
                    'employee_assignment_id' => $target->employeeAssignmentId,
                    'organization_id' => $target->contextOrganizationId,
                    // Employee: the unit of the post. Organization: the office the visitor chose, checked against it.
                    'organization_unit_id' => $organizationForm['organization_unit_id'] ?? $target->contextUnitId,
                    'organization_unit_name_snapshot' => $organizationForm['organization_unit_name_snapshot'] ?? null,
                    'organization_unit_name_am_snapshot' => $organizationForm['organization_unit_name_am_snapshot'] ?? null,
                    'organization_unit_type_snapshot' => $organizationForm['organization_unit_type_snapshot'] ?? null,
                    'unit_selection_mode' => $organizationForm['unit_selection_mode'] ?? null,
                    'position_id' => $target->contextPositionId,
                    'target_name_snapshot' => $target->targetNameSnapshot,
                    'target_code_snapshot' => $target->targetCodeSnapshot,
                    'type' => $type,
                    'position_service_id' => $service?->getKey(),
                    'service_no_snapshot' => $service?->service_no ?? $organizationForm['service_no_snapshot'] ?? null,
                    'service_name_snapshot' => $service?->name_en ?? $organizationForm['service_name_snapshot'] ?? null,
                    'service_id' => $organizationForm['service_id'] ?? null,
                    'service_text' => $organizationForm['service_text'] ?? null,
                    'service_date' => $organizationForm['service_date'] ?? null,
                    'service_time_band' => $organizationForm['service_time_band'] ?? null,
                    'question_set_id' => $organizationForm['question_set_id'] ?? null,
                    'rating' => $type === ServiceFeedbackType::Feedback ? (int) $data['rating'] : null,
                    'comment' => $type === ServiceFeedbackType::Feedback
                        ? $this->trimmedOrNull($data['comment'] ?? null)
                        : trim((string) ($data['message'] ?? '')),
                    'subject' => $this->trimmedOrNull($data['subject'] ?? null),
                    // Applicability was checked by the controller against the resolved target.
                    'category_id' => $this->trimmedOrNull($data['category_id'] ?? null),
                    // The identity policy of this type, recorded with the row (PublicSubmissionPolicy).
                    'client_name' => $this->trimmedOrNull($data['client_name'] ?? null),
                    'client_contact' => $this->trimmedOrNull($data['client_contact'] ?? null),
                    'client_email' => $this->trimmedOrNull($data['client_email'] ?? null),
                    'submitter_organization' => $requirements->submitterOrganization ? $this->trimmedOrNull($data['submitter_organization'] ?? null) : null,
                    'submitter_address' => $requirements->submitterAddress ? $this->address($data['submitter_address'] ?? null) : null,
                    'submitter_identity_mode' => $requirements->identityMode(),
                    'tip_type' => $tipType,
                    'involved_parties' => $requirements->involvedParties ? $this->trimmedOrNull($data['involved_parties'] ?? null) : null,
                    // The system knows how this arrived; the citizen is never asked.
                    'intake_channel' => PublicIntakeChannel::WebQr,
                    'incident_date' => $requirements->incidentContext ? ($data['incident_date'] ?? null) : null,
                    'incident_location' => $requirements->incidentContext ? $this->trimmedOrNull($data['incident_location'] ?? null) : null,
                    'ip_address' => $request->ip(),
                    'user_agent' => Str::limit((string) $request->userAgent(), 500, ''),
                    'status' => ServiceFeedbackStatus::Pending,
                    'submitted_at' => now(),
                ]);

                if ($type !== ServiceFeedbackType::Feedback) {
                    $entry->forceFill([
                        'reference_number' => $this->codes->execute(
                            $type === ServiceFeedbackType::Complaint ? CodeRuleEntityType::PublicServiceComplaint : CodeRuleEntityType::PublicTipOff,
                            ['organization_id' => $target->contextOrganizationId],
                            null,
                            null,
                            'reference_number',
                            $entry->getKey(),
                        ),
                    ])->save();
                }

                // Structured answers, one typed row each, against the version the visitor answered.
                $this->questionnaire->persist($entry, $organizationForm['answers'] ?? []);

                if ($requirements->evidenceAllowed) {
                    foreach ($data['attachments'] ?? [] as $file) {
                        if (! $file instanceof UploadedFile) {
                            continue;
                        }

                        $storedPaths[] = $this->storeAttachment($entry, $file);
                    }
                }

                // Brief §52: the history starts at submission. §45–46: routed, or unrouted and flagged.
                $this->workflow->recordInitial($entry);
                $this->routing->route($entry, $request);
                // Brief §89: queued after commit; a failing mail provider never fails the submission.
                $this->notifier->acknowledge($entry, app()->getLocale());

                $this->audit->execute(
                    eventType: match ($type) {
                        ServiceFeedbackType::Feedback => AuditEventType::ServiceFeedbackSubmitted,
                        ServiceFeedbackType::Suggestion => AuditEventType::ServiceSuggestionSubmitted,
                        ServiceFeedbackType::Complaint => AuditEventType::ServiceComplaintSubmitted,
                    },
                    // Anonymous by design: there is no actor to attribute this to.
                    actor: null,
                    auditable: $entry,
                    organizationId: $target->contextOrganizationId,
                    // Codes only: never the narrative, never who sent it.
                    reason: 'Public '.$type->value.($tipType !== null ? ' ('.$tipType->value.')' : '').' submitted about '.$target->targetType->value,
                    request: $request,
                );

                return $entry;
            });
        } catch (\Throwable $exception) {
            foreach ($storedPaths as $path) {
                Storage::disk('local')->delete($path);
            }

            throw $exception;
        }
    }

    /**
     * The service information and questionnaire answers of an organization
     * submission. The controller has already checked that the service and the
     * office belong to the resolved institution; an employee submission has none.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function organizationForm(TargetContext $target, ServiceFeedbackType $type, array $data): array
    {
        if ($target->targetType !== PublicFeedbackTargetType::Organization) {
            return [];
        }

        $catalogService = ! empty($data['service_id']) ? Service::query()->find((string) $data['service_id']) : null;
        $unit = ! empty($data['organization_unit_id']) ? OrganizationUnit::query()->find((string) $data['organization_unit_id']) : null;
        $set = $this->questionnaire->published();
        // Validation has checked the stated version is the published one; no version means the page showed no questions.
        $asked = $set !== null
            && filled($data['questionnaire_version'] ?? null)
            && $this->questionnaire->questionsFor($set, $type)->isNotEmpty();

        return [
            'service_id' => $catalogService?->getKey(),
            'service_no_snapshot' => $catalogService?->code,
            'service_name_snapshot' => $catalogService === null ? null : ($catalogService->name_en ?: $catalogService->name_am),
            // The visitor's own words only when no catalog service was chosen: an unstructured service.
            'service_text' => $catalogService === null ? $this->trimmedOrNull(is_string($data['service_text'] ?? null) ? $data['service_text'] : null) : null,
            'organization_unit_id' => $this->trimmedOrNull(is_string($data['organization_unit_id'] ?? null) ? $data['organization_unit_id'] : null),
            // The unit as the visitor saw it; a later rename or archive never rewrites this submission.
            'organization_unit_name_snapshot' => $unit === null ? null : ($unit->name_en ?: $unit->name_am),
            'organization_unit_name_am_snapshot' => $unit?->name_am,
            'organization_unit_type_snapshot' => $unit?->unit_type,
            'unit_selection_mode' => $unit === null ? 'GENERAL' : 'SELECTED',
            'service_date' => $data['service_date'] ?? null,
            'service_time_band' => $data['service_time_band'] ?? null,
            // The version is recorded whenever the visitor was shown its questions, answered or not.
            'question_set_id' => $asked ? $set->getKey() : null,
            'answers' => $asked ? $this->questionnaire->rowsFor($set, $type, is_array($data['answers'] ?? null) ? $data['answers'] : []) : [],
        ];
    }

    /** @param array<string, mixed> $data */
    private function fingerprint(TargetContext $target, ServiceFeedbackType $type, array $data, Request $request): string
    {
        $attachments = array_map(static fn (UploadedFile $file): array => [
            $file->getClientOriginalName(),
            $file->getSize(),
            hash_file('sha256', $file->getPathname()),
        ], $data['attachments'] ?? []);

        return hash('sha256', serialize([
            $request->session()->getId(),
            $target->targetType->value,
            $target->sourceId,
            $type->value,
            (string) ($data['tip_type'] ?? ''),
            trim((string) ($data['message'] ?? $data['comment'] ?? '')),
            (string) ($data['rating'] ?? ''),
            (string) ($data['position_service_id'] ?? ''),
            (string) ($data['category_id'] ?? ''),
            trim((string) ($data['client_name'] ?? '')),
            trim((string) ($data['client_contact'] ?? '')),
            trim((string) ($data['client_email'] ?? '')),
            trim((string) ($data['involved_parties'] ?? '')),
            (string) ($data['incident_date'] ?? ''),
            trim((string) ($data['incident_location'] ?? '')),
            (string) ($data['service_id'] ?? ''),
            trim((string) ($data['service_text'] ?? '')),
            (string) ($data['organization_unit_id'] ?? ''),
            (string) ($data['service_date'] ?? ''),
            (string) ($data['service_time_band'] ?? ''),
            $data['answers'] ?? [],
            $attachments,
        ]));
    }

    private function duplicateOf(string $fingerprint): ?EmployeeServiceFeedback
    {
        $id = Cache::get('public-feedback:dup:'.$fingerprint);

        return $id === null ? null : EmployeeServiceFeedback::query()->find((string) $id);
    }

    private function storeAttachment(EmployeeServiceFeedback $entry, UploadedFile $file): string
    {
        $extension = strtolower($file->getClientOriginalExtension());
        $path = $file->storeAs('service-feedback/'.$entry->getKey(), Str::uuid7().'.'.$extension, 'local');

        if ($path === false) {
            throw new \RuntimeException('Public submission evidence could not be stored.');
        }

        $entry->attachments()->create([
            'disk' => 'local',
            'path' => $path,
            'original_name' => $this->safeAttachmentName($file->getClientOriginalName()),
            'mime_type' => (string) $file->getMimeType(),
            'size_bytes' => (int) $file->getSize(),
            'sha256' => hash_file('sha256', $file->getRealPath()),
            // No malware scanner is integrated (MALWARE_SCANNER_REQUIRED); never claim otherwise.
            'scan_status' => 'not_scanned',
        ]);

        return $path;
    }

    /**
     * The tipster's optional address, keeping only the parts given.
     *
     * @return array<string, string>|null
     */
    private function address(mixed $value): ?array
    {
        if (! is_array($value)) {
            return null;
        }

        $parts = [];
        foreach (['city', 'sub_city', 'woreda', 'detail'] as $key) {
            $part = $this->trimmedOrNull(is_string($value[$key] ?? null) ? $value[$key] : null);
            if ($part !== null) {
                $parts[$key] = $part;
            }
        }

        return $parts === [] ? null : $parts;
    }

    private function safeAttachmentName(string $name): string
    {
        $base = basename(str_replace('\\', '/', $name));
        $safe = trim((string) preg_replace('/[^\pL\pN._ -]/u', '_', $base), ' .');

        return Str::limit($safe !== '' ? $safe : 'attachment', 255, '');
    }

    private function trimmedOrNull(?string $value): ?string
    {
        $trimmed = trim((string) $value);

        return $trimmed === '' ? null : $trimmed;
    }
}
